# AlertingBundle

___
[![Latest Version on Packagist](https://img.shields.io/packagist/v/mulertech/alerting-bundle.svg?style=flat-square)](https://packagist.org/packages/mulertech/alerting-bundle)
[![GitHub Tests Action Status](https://img.shields.io/github/actions/workflow/status/mulertech/alerting-bundle/tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/mulertech/alerting-bundle/actions/workflows/tests.yml)
[![GitHub PHPStan Action Status](https://img.shields.io/github/actions/workflow/status/mulertech/alerting-bundle/phpstan.yml?branch=main&label=phpstan&style=flat-square)](https://github.com/mulertech/alerting-bundle/actions/workflows/phpstan.yml)
[![Total Downloads](https://img.shields.io/packagist/dt/mulertech/alerting-bundle.svg?style=flat-square)](https://packagist.org/packages/mulertech/alerting-bundle)
[![Test Coverage](https://raw.githubusercontent.com/mulertech/alerting-bundle/badge/badge-coverage.svg)](https://packagist.org/packages/mulertech/alerting-bundle)
___

Reports whatever malfunctions in a Symfony application, over channels tried in order.

## What is reported

| Signal | Reported when |
|---|---|
| A request | it ends on an exception answered 5xx, or a 4xx you declare; or it answers 5xx with no exception at all, unless it already raised an alert (an error it logged before answering) |
| A Messenger message | it is abandoned, never while it will be retried |
| A command | it exits with a non-zero code, with or without an exception |
| A log record | it is `error` or above and no source above owns its exception: what the application logs itself, PHP fatal errors, warnings and notices |
| A dependency | the database, the mail transport, Redis, or one you declare, fails: one alert for the dependency, wherever it is met |
| The worker | no worker has consumed a transport for longer than you allow |
| A scheduled task | it has not completed for longer than you allow |
| The failure transport | it holds more messages than you allow |
| An unread alert | a channel that knows what was read holds an alert unread for longer than you allow |

Each fault enters by one path only. Symfony logs at `critical` what a source already reports, and
the log handler leaves alone every exception a source owns: one fault, one alert.

A fault is sent once per window (15 minutes by default). The occurrences held back are counted,
and the count goes with the next alert of the same fault. Beyond a cap of alerts per window, every
fault included, one alert says the cap is reached and the rest goes to standard error only: the
mail account of the application is not blocked for mass sending, with the mails to its users.

An alert carries the application, the cause and where it happened. Never the connected user, the IP
address, nor the query string of a request, which carries tokens: the alert leaves for a third party.
An e-mail address met in a message or a log context keeps its domain alone: a mail server that
refuses a recipient names it, and so does an application logging the address it failed to write to.
The values a database quotes when it refuses a row or a value (`Key (phone)=(***) already exists`,
`invalid input syntax for type integer: "***"`) are masked
likewise, while the constraint, the table and the columns stay: they name the cause.

## Requirements

- PHP 8.4 or above
- Symfony 7 or 8, with `symfony/monolog-bundle`
- `symfony/mailer` for the mail channel, `symfony/notifier` for chat, text message and push channels
- `symfony/messenger` for the worker and the failure transport, `symfony/scheduler` for the scheduled tasks

## Installation

```bash
composer require mulertech/alerting-bundle
```

Register the bundle for production only, in `config/bundles.php`:

```php
return [
    // ...
    MulerTech\AlertingBundle\MulerTechAlertingBundle::class => ['prod' => true],
];
```

Its configuration goes under `when@prod` likewise: in any other environment, no extension reads the
`mulertech_alerting` key, and the kernel refuses to boot.

Nothing to add to `monolog.yaml`: the bundle registers its log handler itself.

## Usage

### The pool

The alerting keeps its state (what was sent, the worker heartbeat) in a cache pool of its own.
Declare one that every process of the application reads:

| Hosting | Pool |
|---|---|
| Web and worker on one machine | the filesystem, in a directory of its own |
| Several containers or machines | `cache.adapter.redis` or `cache.adapter.memcached` |
| Several containers without Redis | the filesystem, in a directory mounted in every container |

On the filesystem, give the pool a directory of its own rather than `framework.cache.pools`, whose
files live under `var/cache`: rebuilt at each deployment, and never shared between containers.

```yaml
# config/services.yaml
when@prod:
    services:
        cache.alerting:
            class: Symfony\Component\Cache\Adapter\FilesystemAdapter
            arguments: ['alerting', 0, '%kernel.project_dir%/var/alerting']
```

With Docker Compose, the web and the worker are two containers: mount one volume at that
directory in both.

```yaml
# compose.yaml
services:
    www:
        volumes: [alerting:/var/www/html/var/alerting]
    worker:
        volumes: [alerting:/var/www/html/var/alerting]
volumes:
    alerting:
```

Docker creates an empty named volume owned by `root`, unless the image already holds the directory,
whose owner it then takes. An application running as another user could write nothing there:
create the directory in the image, owned by that user.

```dockerfile
RUN mkdir -p var/alerting && chown www-data:www-data var/alerting
```

On Redis or Memcached, a pool of the framework does:

```yaml
# config/packages/cache.yaml
when@prod:
    framework:
        cache:
            pools:
                cache.alerting:
                    adapter: cache.adapter.redis
                    provider: '%env(REDIS_URL)%'
```

A pool in the database (Doctrine, PDO) is refused: it would fall with what the alerting watches.
A pool in memory (`array`) is refused: it deduplicates nothing from one request to the next. An
APCu pool is refused once the worker or another absence is watched: the web server and the command
line do not share its memory.

### The channels

```yaml
# config/packages/mulertech_alerting.yaml
mulertech_alerting:
    application: '%env(ALERTING_APPLICATION)%'   # "shop-prod", "shop-staging"
    pool: cache.alerting
    channels:
        # Tried in this order; the first one that accepts the alert ends the chain.
        mail:
            type: mail
            dsn: '%env(ALERTING_MAILER_DSN)%'
            from: alerts@example.com
            to: [ops@example.com]
        chat:
            type: notifier
            dsn: '%env(ALERTING_CHAT_DSN)%'   # any Notifier DSN: slack://, telegram://, ntfy://…
            message: chat                      # chat, sms (with a phone), or push
```

Standard error always comes last, and cannot be removed: it depends on no network, no database and
no queue. A channel that refuses an alert hands it to the next one, and the refusal travels with it.

The mail and Notifier channels send through transports of their own, built from the DSN you give,
never through the `mailer`, `chatter` or `texter` services: those hand their messages to Messenger
when the application routes them there, and the queue often lives in the database whose failure
the alert reports. Each channel waits no longer than `timeout` seconds (10 by default).

A channel is built when an alert first reaches it, never with the kernel: an image build boots
the kernel without the secrets the runtime mounts, such as the file a DSN is read from. A channel
that cannot be built refuses the alert like any other, naming the cause, and the next channel takes
it. The alerter itself, with the name of the application and the pool, is built at the first alert
too: a command or a request that meets no fault never reads them. `mulertech:alerting:test
--probe-only` builds every channel without sending anything: run at each deployment, it names a
variable the deployment did not provide before an alert needs it.

### A channel of the application

Implement `MulerTech\AlertingBundle\Channel\ChannelInterface`, throw when the alert could not be
sent, and declare the service:

```yaml
mulertech_alerting:
    channels:
        messaging:
            type: service
            service: App\Alerting\MessagingChannel
        mail: # …
```

`MulerTech\AlertingBundle\Format\TextFormatter` gives the subject and the body every channel of the
bundle sends.

A channel that knows whether its alerts were read implements `ReadReceiptInterface`: declare
`absence.unread`, and alerts left unread past the delay are reminded through the channels after it,
again every delay while they stay unread.

### Everything else

```yaml
mulertech_alerting:
    window: 900          # seconds during which a fault is sent once
    cap: 20              # alerts per window, every fault included
    timeout: 10          # seconds a channel waits for its server
    http:
        client_errors: [403]                    # the 4xx to report; every 5xx is
    console:
        status_commands: [doctrine:migrations:up-to-date]   # exit code reports a state
    dependencies:
        App\Routing\RoutingEngineUnreachable: routing engine
    absence:
        interval: 60     # seconds between two checks run by web requests
        worker:
            transport: async
            silence: 300         # longer than the longest message the worker handles
            probe_wait: 300      # seconds the probe waits for the worker; the silence by default
        schedule:
            app:quote-reminders: 90000         # command name or message class => seconds
        failure_queue:
            transport: failed
            threshold: 10
        unread:
            delay: 3600
```

Each absence is watched only once declared.

### Commands

```bash
php bin/console mulertech:alerting:test                   # a test alert through each channel, one by one
php bin/console mulertech:alerting:test --channel=chat    # through this channel only; repeat for several
php bin/console mulertech:alerting:test --probe-only      # no test alert: builds the channels, probes the pool
php bin/console mulertech:alerting:check                  # checks the absences now
```

Run `mulertech:alerting:test` whenever the channels change: in a chain, the second channel only
serves the day the first one falls, and this is the only way to try it before. When the worker is
watched, the command also proves that the worker reads the pool the web writes; a worker that does
not sends an alert saying so.

Run `mulertech:alerting:test --probe-only` at each deployment: a new container may lack a secret a
channel reads, mount another directory, or reach another Redis, and the command sees it. It builds
the channels whether the worker is watched or not, and probes the worker only when it is. Every test
alert reaches a person, and a text message is paid for: test alerts at each deployment end up
unread.

Run the probe last, once the worker of the new release consumes: a worker that has not started
yet answers nothing, the command fails, and a deployment that stops at the first error stops there
with nothing broken. With Docker Compose, it closes the deployment script, which stops on its
failure:

```bash
set -e
docker compose up --detach --build
docker compose exec -T www php bin/console mulertech:alerting:test --probe-only
```

A worker that has started may still be busy: it answers once its message in hand is done, a
scheduled task included. The probe therefore waits as long as `silence`, the longest message the
worker handles, unless `probe_wait` says otherwise; a probe that succeeds returns as soon as the
worker answers, and only a failure waits the whole time.

The absences are checked after web responses, at most once per `interval`. With no visit, nothing
is checked: schedule `mulertech:alerting:check` outside the application (a systemd timer, a cron)
to cover the nights.

## Limits

- **An application that is down does not report itself.** The bundle reacts to requests, messages
  and commands; with none of them, there is no alert. Watch the application from outside.
- **A kernel that does not boot** (invalid container, missing variable) dispatches no event: the
  deployment and an outside probe see it.
- **Deduplication is not atomic.** Processes raising the same fault at the same instant may each
  send it; the cap bounds that case.
- **A pool each container keeps for itself** deduplicates per container, and hides the worker
  heartbeat from the web.

## Testing

```bash
./vendor/bin/mtdocker test-ai
```
