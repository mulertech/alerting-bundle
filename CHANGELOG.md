# Release notes for alerting-bundle

## v1.0.1 - 2026-11-18

### Fixed

- A console command or a request no longer builds the alerter when it meets no fault. Every source and watch now holds the alerter deferred, built at the first alert: the name of the application, the channels and the pool are read only then. A command run without the runtime's variables — an image build with `cache:warmup`, `assets:install`, `importmap:install` — no longer fails at its end on "Environment variable not found".
- An alerter that cannot be built at the first alert leaves that alert in the PHP error log, with the cause, and the caller goes on.

## v1.0.0 - 2026-11-18

First release: a Symfony bundle that reports whatever malfunctions in an application, over channels tried in order.

### What is reported

- **Requests** ending on an exception answered 5xx, a 4xx you declare, or a 5xx answered with no exception at all
- **Messenger messages** abandoned, never while they will be retried
- **Commands** exiting with a non-zero code, with or without an exception
- **Log records** at `error` and above that no other source owns: what the application logs itself, PHP fatal errors (out-of-memory included), warnings and notices
- **Dependencies** — the database, the mail transport, Redis, or any you declare: one alert for the dependency, wherever it is met
- **Absences**: no worker consuming a transport, a scheduled task not completing, a failure transport filling up, an alert left unread in a channel that can tell

### How it behaves

- **One fault, one alert.** Each fault enters by a single path, and Symfony's own critical log of it is left alone.
- **Deduplicated** per window (15 minutes by default); the occurrences held back are counted, and the next alert of the same fault carries the count and when the previous one left.
- **Capped**: beyond 20 alerts per window, one notice says so and the rest goes to standard error only, so the application's mail account is never blocked for mass sending.
- **Channels tried in order**, the first that accepts ending the chain; a channel that refuses hands the alert to the next one, and the refusal travels with it. Standard error always comes last.
- **Own transports**: mail and Notifier channels are built from their own DSN, never through the `mailer`, `chatter` or `texter` services, which may route to a Messenger queue living in the very database that failed. Each channel waits no longer than its timeout.
- **Built when first needed**: a kernel booting without the runtime secrets — an image build — is never stopped by the alerting.
- **No personal data**: never the connected user, the IP address nor the query string.
- **Configuration checked at compile time**: a missing recipient, an unknown channel type, a pool that cannot hold the state (in memory, in the database, APCu once an absence is watched) are refused, naming what is accepted.

### Channels

- `mail` — any Symfony Mailer DSN
- `notifier` — any Notifier DSN, as a chat, text message or push
- `service` — a channel of the application implementing `ChannelInterface`, optionally `ReadReceiptInterface` to remind unread alerts

### Commands

- `mulertech:alerting:test` — a test alert through each channel, one by one; `--channel` to try some; `--probe-only` for deployments: builds every channel without sending, and proves the worker shares the alerting's cache pool
- `mulertech:alerting:check` — checks the absences now, for a timer covering the hours without traffic

### Requirements

- PHP 8.4 or above
- Symfony 7 or 8, with MonologBundle
- `symfony/mailer` and `symfony/notifier` for their channels, `symfony/messenger` and `symfony/scheduler` for the worker and scheduled-task watches

### Installation

```bash
composer require mulertech/alerting-bundle


```
Register the bundle for production only, then declare a cache pool shared by every process and at least one channel — see the [README](https://github.com/mulertech/alerting-bundle#readme).
