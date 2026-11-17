<?php

declare(strict_types=1);

namespace MulerTech\AlertingBundle\Tests;

use MulerTech\AlertingBundle\Alerter;
use MulerTech\AlertingBundle\MulerTechAlertingBundle;
use MulerTech\AlertingBundle\Source\AlertingHandler;
use MulerTech\AlertingBundle\Source\HttpExceptionSubscriber;
use MulerTech\AlertingBundle\Tests\Double\ReadReceiptChannel;
use MulerTech\AlertingBundle\Tests\Double\RecordingChannel;
use MulerTech\AlertingBundle\Tests\Kernel\AlertingKernel;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpKernel\KernelEvents;

final class MulerTechAlertingBundleTest extends TestCase
{
    private const array MAIL = ['type' => 'mail', 'dsn' => 'null://null', 'from' => 'alerts@shop.test', 'to' => ['ops@shop.test']];

    /**
     * @var list<AlertingKernel>
     */
    private array $kernels = [];

    protected function tearDown(): void
    {
        foreach ($this->kernels as $kernel) {
            $kernel->shutdown();
            $kernel->removeDirectory();
        }
    }

    public function testItsConfigurationLivesUnderTheMulertechAlertingKey(): void
    {
        self::assertSame('mulertech_alerting', new MulerTechAlertingBundle()->getContainerExtension()?->getAlias());
    }

    public function testTheAlerterIsReadyWithItsSources(): void
    {
        $container = $this->boot($this->config());

        self::assertInstanceOf(Alerter::class, $container->get(Alerter::class));

        $dispatcher = $container->get('event_dispatcher');
        \assert($dispatcher instanceof EventDispatcherInterface);
        $listeners = array_map(
            static fn (mixed $listener): string => \is_array($listener) && \is_object($listener[0]) ? $listener[0]::class : '',
            $dispatcher->getListeners(KernelEvents::EXCEPTION),
        );
        self::assertContains(HttpExceptionSubscriber::class, $listeners);
    }

    public function testTheLogHandlerIsRegisteredWithoutTouchingTheMonologConfiguration(): void
    {
        $logger = $this->boot($this->config())->get('monolog.logger');
        \assert($logger instanceof Logger);

        self::assertContains(AlertingHandler::class, array_map(static fn (object $handler): string => $handler::class, $logger->getHandlers()));
    }

    public function testTheTestCommandSendsThroughEveryDeclaredChannel(): void
    {
        $kernel = $this->kernel($this->config(['channels' => [
            'mail' => self::MAIL,
            'chat' => ['type' => 'notifier', 'dsn' => 'null://null', 'message' => 'chat'],
            'push' => ['type' => 'notifier', 'dsn' => 'null://null', 'message' => 'push'],
            'sms' => ['type' => 'notifier', 'dsn' => 'null://null', 'message' => 'sms', 'phone' => '+33600000000'],
        ]]));

        $tester = $this->command($kernel, 'mulertech:alerting:test');

        self::assertSame(Command::SUCCESS, $tester->getStatusCode(), $tester->getDisplay());
        foreach (['mail', 'chat', 'push', 'sms'] as $channel) {
            self::assertStringContainsString('✔ '.$channel, $tester->getDisplay());
        }
    }

    public function testAChannelOfTheApplicationIsUsedAsDeclared(): void
    {
        $kernel = $this->kernel($this->config(['channels' => ['messaging' => ['type' => 'service', 'service' => 'app.messaging']]]), services: [
            static fn (ContainerBuilder $c) => $c->register('app.messaging', RecordingChannel::class)->setPublic(true),
        ]);

        $tester = $this->command($kernel, 'mulertech:alerting:test');

        $channel = $kernel->getContainer()->get('app.messaging');
        \assert($channel instanceof RecordingChannel);
        self::assertCount(1, $channel->deliveries, $tester->getDisplay());
    }

    /**
     * A build boots the kernel without the secrets of the runtime: a channel reading one must not
     * be built before an alert needs it, and then its failure is a refusal like any other.
     */
    public function testAChannelReadingAnAbsentSecretLetsTheKernelBootAndSaysSoWhenItIsNeeded(): void
    {
        $kernel = $this->kernel($this->config(['channels' => ['mail' => ['dsn' => '%env(file:ALERTING_ABSENT_DSN_FILE)%'] + self::MAIL]]));
        $kernel->boot();

        // What a build runs: a console command, its events reaching the console source.
        self::assertSame(Command::SUCCESS, $this->command($kernel, 'about')->getStatusCode());

        $logger = $kernel->getContainer()->get('test.service_container')->get('monolog.logger');
        \assert($logger instanceof Logger);
        $logger->error('Import of {file} failed', ['file' => 'parkings.geojsonseq']);

        $alerts = $kernel->standardError();
        self::assertCount(1, $alerts);
        self::assertSame('Import of parkings.geojsonseq failed', $alerts[0]['summary']);
        self::assertIsArray($alerts[0]['incidents']);
        self::assertStringContainsString('Channel "mail" refused the alert', (string) $alerts[0]['incidents'][0]);
        self::assertStringContainsString('ALERTING_ABSENT_DSN_FILE', (string) $alerts[0]['incidents'][0]);
    }

    public function testTheStatusCodesOfTheFrameworkAreThoseTheAlertingJudgesBy(): void
    {
        $kernel = $this->kernel($this->config(), framework: ['exceptions' => [\DomainException::class => ['status_code' => 422]]]);
        $kernel->boot();

        $subscriber = $kernel->getContainer()->get('test.service_container')->get('mulertech_alerting.source.http');
        $statusCodes = new \ReflectionProperty(HttpExceptionSubscriber::class, 'statusCodes')->getValue($subscriber);

        self::assertSame([\DomainException::class => 422], $statusCodes);
    }

    public function testEveryAbsenceSignalIsWatched(): void
    {
        $kernel = $this->kernel($this->config([
            'channels' => ['messaging' => ['type' => 'service', 'service' => 'app.messaging'], 'mail' => self::MAIL],
            'absence' => [
                'worker' => ['transport' => 'async', 'silence' => 300],
                'schedule' => ['app:quote-reminders' => 90000],
                'failure_queue' => ['transport' => 'failed', 'threshold' => 10],
                'unread' => ['delay' => 3600],
            ],
        ]), services: [
            static fn (ContainerBuilder $c) => $c->register('app.messaging', ReadReceiptChannel::class),
        ]);

        $tester = $this->command($kernel, 'mulertech:alerting:check');

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertSame(
            ['worker heartbeat of "async"', 'scheduled task "app:quote-reminders"', 'failure transport "failed"', 'unread alerts of "messaging"'],
            $this->checked($tester->getDisplay()),
        );
    }

    public function testWithoutAbsenceSignalThereIsNoCheck(): void
    {
        $kernel = $this->kernel($this->config());
        $kernel->boot();

        self::assertFalse(new Application($kernel)->has('mulertech:alerting:check'));
    }

    public function testMonologIsRequired(): void
    {
        $this->expectRefusal('mulertech_alerting needs symfony/monolog-bundle');

        $this->kernel($this->config(), monolog: false)->boot();
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function refusedChannels(): iterable
    {
        yield 'unknown type' => [['type' => 'pigeon'], 'The channel "alert" of mulertech_alerting has the type "pigeon". Accepted types: mail, notifier, service.'];
        yield 'mail without recipient' => [['to' => []] + self::MAIL, 'The mail channel "alert" of mulertech_alerting declares no recipient: list them under "to".'];
        yield 'mail without sender' => [['type' => 'mail', 'dsn' => 'null://null', 'to' => ['ops@shop.test']], 'The mail channel "alert" of mulertech_alerting needs "from".'];
        yield 'mail without dsn' => [['type' => 'mail', 'from' => 'alerts@shop.test', 'to' => ['ops@shop.test']], 'The mail channel "alert" of mulertech_alerting needs "dsn".'];
        yield 'unknown message' => [['type' => 'notifier', 'dsn' => 'null://null', 'message' => 'fax'], 'The Notifier channel "alert" of mulertech_alerting sends "fax" messages. Accepted: chat, sms, push.'];
        yield 'text without phone' => [['type' => 'notifier', 'dsn' => 'null://null', 'message' => 'sms'], 'The notifier channel "alert" of mulertech_alerting needs "phone".'];
        yield 'service without id' => [['type' => 'service'], 'The service channel "alert" of mulertech_alerting needs "service".'];
        yield 'service that does not exist' => [['type' => 'service', 'service' => 'app.missing'], 'The channel "alert" of mulertech_alerting names the service "app.missing", which does not exist.'];
    }

    /**
     * @param array<string, mixed> $channel
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('refusedChannels')]
    public function testAnIncompleteChannelIsRefusedWithWhatItLacks(array $channel, string $message): void
    {
        $this->expectRefusal($message);

        $this->kernel($this->config(['channels' => ['alert' => $channel]]))->boot();
    }

    public function testAServiceThatIsNoChannelIsRefused(): void
    {
        $this->expectRefusal('The service "app.mailer" of the channel "alert" of mulertech_alerting is a stdClass, which does not implement MulerTech\AlertingBundle\Channel\ChannelInterface.');

        $this->kernel($this->config(['channels' => ['alert' => ['type' => 'service', 'service' => 'app.mailer']]]), services: [
            static fn (ContainerBuilder $c) => $c->register('app.mailer', \stdClass::class),
        ])->boot();
    }

    public function testUnreadAlertsNeedAChannelThatTells(): void
    {
        $this->expectRefusal('mulertech_alerting.absence.unread needs a channel that knows whether its alerts were read');

        $this->kernel($this->config(['absence' => ['unread' => ['delay' => 3600]]]))->boot();
    }

    public function testAnInMemoryPoolIsRefusedWithThePoolsAccepted(): void
    {
        $this->expectRefusal('The pool "cache.alerting" of mulertech_alerting is a Symfony\Component\Cache\Adapter\ArrayAdapter: it lives in the memory of one request, so it deduplicates nothing. Accepted: a pool on Redis or Memcached');

        $this->kernel($this->config(), framework: ['cache' => ['pools' => ['cache.alerting' => ['adapter' => 'cache.adapter.array']]]])->boot();
    }

    public function testAnApcuPoolIsRefusedOnceTheWorkerIsWatched(): void
    {
        $this->expectRefusal('its memory is not shared between the web server and the command line, where the worker runs');

        $this->kernel(
            $this->config(['absence' => ['worker' => ['transport' => 'async', 'silence' => 300]]]),
            framework: ['cache' => ['pools' => ['cache.alerting' => ['adapter' => 'cache.adapter.apcu']]]],
        )->boot();
    }

    public function testDashesInNamesAreKept(): void
    {
        $kernel = $this->kernel($this->config([
            'channels' => ['ops-mail' => self::MAIL],
            'absence' => ['schedule' => ['app:quote-reminders' => 90000]],
        ]));

        self::assertStringContainsString('✔ ops-mail', $this->command($kernel, 'mulertech:alerting:test')->getDisplay());
        self::assertSame(['scheduled task "app:quote-reminders"'], $this->checked($this->command($kernel, 'mulertech:alerting:check')->getDisplay()));
    }

    public function testAPoolThatIsNoServiceIsRefused(): void
    {
        $this->expectRefusal('The pool "cache.missing" of mulertech_alerting is no service.');

        $this->kernel($this->config(['pool' => 'cache.missing']))->boot();
    }

    public function testAChannelNeedsTheTransportsOfTheFramework(): void
    {
        $this->expectRefusal('no service is tagged "chatter.transport_factory": enable framework.notifier in the framework configuration.');

        $this->kernel(
            $this->config(['channels' => ['chat' => ['type' => 'notifier', 'dsn' => 'null://null', 'message' => 'chat']]]),
            framework: ['notifier' => ['enabled' => false]],
        )->boot();
    }

    /**
     * @param array<string, mixed> $override
     *
     * @return array<string, mixed>
     */
    private function config(array $override = []): array
    {
        return array_replace(['application' => 'shop-prod', 'pool' => 'cache.alerting', 'channels' => ['mail' => self::MAIL]], $override);
    }

    /**
     * @param array<string, mixed>                   $config
     * @param array<string, mixed>                   $framework
     * @param list<\Closure(ContainerBuilder): void> $services
     */
    private function kernel(array $config, array $framework = [], array $services = [], bool $monolog = true): AlertingKernel
    {
        return $this->kernels[] = new AlertingKernel($config, $framework, $services, $monolog);
    }

    /**
     * @param array<string, mixed> $config
     * @param array<string, mixed> $framework
     */
    private function boot(array $config, array $framework = []): ContainerInterface
    {
        $kernel = $this->kernel($config, $framework);
        $kernel->boot();
        $container = $kernel->getContainer()->get('test.service_container');
        \assert($container instanceof ContainerInterface);

        return $container;
    }

    private function command(AlertingKernel $kernel, string $name): CommandTester
    {
        $kernel->boot();
        $tester = new CommandTester(new Application($kernel)->find($name));
        $tester->execute([]);

        return $tester;
    }

    /**
     * @return list<string>
     */
    private function checked(string $display): array
    {
        preg_match_all('/^Checked the (.+?)\r?$/m', $display, $matches);

        return $matches[1];
    }

    private function expectRefusal(string $message): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage($message);
    }
}
