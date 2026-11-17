<?php

declare(strict_types=1);

namespace MulerTech\AlertingBundle\Tests\Functional;

use MulerTech\AlertingBundle\Absence\Ledger;
use MulerTech\AlertingBundle\Alert\Delivery;
use MulerTech\AlertingBundle\Alert\Severity;
use MulerTech\AlertingBundle\Probe\PoolProbe;
use MulerTech\AlertingBundle\Probe\PoolProbeHandler;
use MulerTech\AlertingBundle\Tests\Double\RecordingChannel;
use MulerTech\AlertingBundle\Tests\Kernel\AlertingKernel;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;
use Symfony\Component\Console\Messenger\RunCommandMessage;
use Symfony\Component\Console\Tester\ApplicationTester;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Mailer\Event\MessageEvent;
use Symfony\Component\Mailer\EventListener\MessageLoggerListener;
use Symfony\Component\Mailer\Messenger\SendEmailMessage;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;
use Symfony\Component\Messenger\Stamp\TransportNamesStamp;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Mime\Email;

/**
 * The bundle in a whole application: each fault met by the framework, Messenger, the console and
 * Monolog at once reaches the channels exactly once.
 */
final class OneFaultOneAlertTest extends TestCase
{
    private const array MESSENGER = [
        'messenger' => ['transports' => ['async' => ['dsn' => 'in-memory://', 'retry_strategy' => ['max_retries' => 0]]]],
    ];

    /**
     * @var list<AlertingKernel>
     */
    private array $kernels = [];

    /**
     * @var list<string>
     */
    private array $directories = [];

    protected function tearDown(): void
    {
        foreach ($this->kernels as $kernel) {
            $kernel->shutdown();
            $kernel->removeDirectory();
        }

        new Filesystem()->remove($this->directories);
    }

    public function testARequestEndingOnAnExceptionIsOneAlert(): void
    {
        $kernel = $this->kernel();

        $response = $kernel->handle(Request::create('/failure'));

        self::assertSame(500, $response->getStatusCode());
        $alert = $this->only($kernel)->alert;
        self::assertSame('http', $alert->source);
        self::assertStringContainsString('The basket could not be priced', $alert->summary);
    }

    public function testARequestAnswering5xxWithoutExceptionIsOneAlert(): void
    {
        $kernel = $this->kernel();

        $response = $kernel->handle(Request::create('/unavailable'));

        self::assertSame(503, $response->getStatusCode());
        self::assertSame('http', $this->only($kernel)->alert->source);
    }

    public function testAnAbandonedMessageIsOneAlert(): void
    {
        $kernel = $this->kernel();
        $this->dispatch($kernel, new \ArrayObject());

        $this->consume($kernel);

        $alert = $this->only($kernel)->alert;
        self::assertSame('messenger', $alert->source);
        self::assertStringContainsString('The payment provider refused the refund', $alert->summary);
    }

    public function testACommandThrowingIsOneAlert(): void
    {
        $kernel = $this->kernel();

        $status = $this->console($kernel, ['command' => 'app:fail']);

        self::assertSame(1, $status);
        $alert = $this->only($kernel)->alert;
        self::assertSame('console', $alert->source);
        self::assertStringContainsString('The export could not be written', $alert->summary);
    }

    public function testAScheduledCommandFailingInTheWorkerIsOneAlert(): void
    {
        $kernel = $this->kernel();
        $this->dispatch($kernel, new RunCommandMessage('app:fail'));

        $this->consume($kernel);

        $alert = $this->only($kernel)->alert;
        self::assertSame('console', $alert->source, 'The command reports it; the message that ran it does not report it again.');
        self::assertStringContainsString('The export could not be written', $alert->summary);
    }

    public function testTheAlertMailLeavesThroughItsTransportWhenTheApplicationQueuesItsMails(): void
    {
        $kernel = new AlertingKernel(
            ['application' => 'shop-prod', 'pool' => 'cache.alerting', 'channels' => ['mail' => [
                'type' => 'mail', 'dsn' => 'null://null', 'from' => 'alerts@shop.test', 'to' => ['ops@shop.test'],
            ]]],
            ['messenger' => ['routing' => [SendEmailMessage::class => 'async']]],
        );
        $this->kernels[] = $kernel;
        $kernel->boot();

        $kernel->handle(Request::create('/failure'));

        $logger = $this->container($kernel)->get('mailer.message_logger_listener');
        \assert($logger instanceof MessageLoggerListener);
        $events = $logger->getEvents()->getEvents();
        self::assertCount(1, $events);
        self::assertInstanceOf(MessageEvent::class, $events[0]);
        self::assertFalse($events[0]->isQueued(), 'The alert mail is sent, not handed to Messenger.');
        $mail = $events[0]->getMessage();
        \assert($mail instanceof Email);
        self::assertStringContainsString('shop-prod', (string) $mail->getSubject());
        self::assertSame([], $this->transport($kernel, 'async')->getSent());
    }

    public function testAWorkerSharingThePoolAnswersTheProbeWithoutAlert(): void
    {
        $pool = $this->directory();
        $web = $this->kernel(watchWorker: true, pool: $pool);
        $worker = $this->kernel(watchWorker: true, pool: $pool);

        $this->console($web, ['command' => 'mulertech:alerting:test', '--probe-only' => true]);
        $sent = $this->transport($web, 'async')->getSent();
        $this->handleIn($worker, $sent);

        self::assertSame([], $this->channel($worker)->deliveries);
        $probe = $sent[0]->getMessage();
        \assert($probe instanceof PoolProbe);
        $ledger = $this->container($web)->get('mulertech_alerting.ledger');
        \assert($ledger instanceof Ledger);
        self::assertSame(PoolProbeHandler::ANSWERED, $ledger->read(PoolProbeHandler::subject($probe->token)), 'The web reads the answer the worker wrote.');
    }

    /**
     * A deployment check that fails is one alert, and that alert carries what failed rather than
     * an exit code.
     */
    public function testAFailedDeploymentCheckIsOneAlertCarryingItsCause(): void
    {
        $web = $this->kernel(watchWorker: true, pool: $this->directory());

        self::assertSame(1, $this->console($web, ['command' => 'mulertech:alerting:test', '--probe-only' => true]));

        $alert = $this->only($web)->alert;
        self::assertSame('console', $alert->source);
        self::assertStringContainsString('The deployment check failed. [pool probe] no answer from the worker of "async" within 1 second.', $alert->summary);
    }

    public function testAWorkerWithAPoolOfItsOwnSaysSo(): void
    {
        $web = $this->kernel(watchWorker: true, pool: $this->directory());
        $worker = $this->kernel(watchWorker: true, pool: $this->directory());

        $this->console($web, ['command' => 'mulertech:alerting:test', '--probe-only' => true]);
        $this->handleIn($worker, $this->transport($web, 'async')->getSent());

        $alert = $this->only($worker)->alert;
        self::assertSame(Severity::Critical, $alert->severity);
        self::assertStringStartsWith('The cache pool "app.alerting_pool" is not shared between the web processes and the worker', $alert->summary);
    }

    /**
     * Booted, with one recording channel, so that every alert the application raises is counted;
     * with a pool of its own when one is given, in that directory.
     */
    private function kernel(bool $watchWorker = false, ?string $pool = null): AlertingKernel
    {
        $config = [
            'application' => 'shop-prod',
            'pool' => null === $pool ? 'cache.alerting' : 'app.alerting_pool',
            'channels' => ['messaging' => ['type' => 'service', 'service' => 'app.messaging']],
        ];

        if ($watchWorker) {
            $config['absence'] = ['worker' => ['transport' => 'async', 'silence' => 300, 'probe_wait' => 1]];
        }

        $services = [static fn (ContainerBuilder $c) => $c->register('app.messaging', RecordingChannel::class)->setPublic(true)];

        if (null !== $pool) {
            $services[] = static fn (ContainerBuilder $c) => $c->register('app.alerting_pool', FilesystemAdapter::class)->setArguments(['alerting', 0, $pool]);
        }

        $kernel = $this->kernels[] = new AlertingKernel($config, self::MESSENGER, $services);
        $kernel->boot();

        return $kernel;
    }

    private function directory(): string
    {
        return $this->directories[] = sys_get_temp_dir().'/mulertech_alerting/pool-'.bin2hex(random_bytes(8));
    }

    /**
     * The container as it stands: booting again after a request would reset the services, and
     * empty what they recorded.
     */
    private function container(AlertingKernel $kernel): ContainerInterface
    {
        $container = $kernel->getContainer()->get('test.service_container');
        \assert($container instanceof ContainerInterface);

        return $container;
    }

    private function channel(AlertingKernel $kernel): RecordingChannel
    {
        $channel = $this->container($kernel)->get('app.messaging');
        \assert($channel instanceof RecordingChannel);

        return $channel;
    }

    private function only(AlertingKernel $kernel): Delivery
    {
        $deliveries = $this->channel($kernel)->deliveries;
        self::assertCount(1, $deliveries, implode("\n", array_map(static fn (Delivery $d): string => $d->alert->summary, $deliveries)));

        return $deliveries[0];
    }

    private function transport(AlertingKernel $kernel, string $name): InMemoryTransport
    {
        $transport = $this->container($kernel)->get('messenger.transport.'.$name);
        \assert($transport instanceof InMemoryTransport);

        return $transport;
    }

    private function dispatch(AlertingKernel $kernel, object $message): void
    {
        $bus = $this->container($kernel)->get('messenger.default_bus');
        \assert($bus instanceof MessageBusInterface);
        $bus->dispatch($message, [new TransportNamesStamp(['async'])]);
    }

    /**
     * Handles, in the worker of another application, the messages sent by the first one.
     *
     * @param iterable<Envelope> $envelopes
     */
    private function handleIn(AlertingKernel $worker, iterable $envelopes): void
    {
        $bus = $this->container($worker)->get('messenger.default_bus');
        \assert($bus instanceof MessageBusInterface);

        foreach ($envelopes as $envelope) {
            $bus->dispatch($envelope->withoutAll(TransportNamesStamp::class)->with(new ReceivedStamp('async')));
        }
    }

    private function consume(AlertingKernel $kernel): void
    {
        self::assertSame(0, $this->console($kernel, ['command' => 'messenger:consume', 'receivers' => ['async'], '--limit' => 1, '--time-limit' => 5]));
    }

    /**
     * Through the console application, so that its events fire as in production.
     *
     * @param array<string, mixed> $input
     */
    private function console(AlertingKernel $kernel, array $input): int
    {
        $application = new Application($kernel);
        $application->setAutoExit(false);

        return new ApplicationTester($application)->run($input);
    }
}
