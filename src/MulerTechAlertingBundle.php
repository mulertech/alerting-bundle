<?php

declare(strict_types=1);

namespace MulerTech\AlertingBundle;

use Monolog\Handler\AbstractProcessingHandler;
use MulerTech\AlertingBundle\Absence\AbsenceCheck;
use MulerTech\AlertingBundle\Absence\CheckOnTerminate;
use MulerTech\AlertingBundle\Absence\FailureQueueWatch;
use MulerTech\AlertingBundle\Absence\Ledger;
use MulerTech\AlertingBundle\Absence\ScheduledTaskRecorder;
use MulerTech\AlertingBundle\Absence\ScheduledTaskWatch;
use MulerTech\AlertingBundle\Absence\WorkerHeartbeat;
use MulerTech\AlertingBundle\Absence\WorkerHeartbeatWatch;
use MulerTech\AlertingBundle\Channel\ChannelChain;
use MulerTech\AlertingBundle\Channel\MailChannel;
use MulerTech\AlertingBundle\Channel\NotifierChannel;
use MulerTech\AlertingBundle\Channel\NotifierMessage;
use MulerTech\AlertingBundle\Channel\StandardErrorChannel;
use MulerTech\AlertingBundle\Command\CheckCommand;
use MulerTech\AlertingBundle\Command\TestCommand;
use MulerTech\AlertingBundle\Compiler\AlertingPass;
use MulerTech\AlertingBundle\Format\TextFormatter;
use MulerTech\AlertingBundle\Probe\PoolProbe;
use MulerTech\AlertingBundle\Probe\PoolProbeHandler;
use MulerTech\AlertingBundle\Probe\PoolProbeSender;
use MulerTech\AlertingBundle\Source\AlertingHandler;
use MulerTech\AlertingBundle\Source\ConsoleFailureSubscriber;
use MulerTech\AlertingBundle\Source\DependencyClassifier;
use MulerTech\AlertingBundle\Source\HttpExceptionSubscriber;
use MulerTech\AlertingBundle\Source\MessengerFailureSubscriber;
use MulerTech\AlertingBundle\Source\ThrowableAlertFactory;
use MulerTech\AlertingBundle\Source\ThrowableClaims;
use MulerTech\AlertingBundle\Throttle\Throttle;
use Symfony\Bundle\MonologBundle\MonologBundle;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\Compiler\PassConfig;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\DependencyInjection\Loader\Configurator\ReferenceConfigurator;
use Symfony\Component\DependencyInjection\Loader\Configurator\ServicesConfigurator;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;
use Symfony\Component\Mailer\Transport as MailerTransport;
use Symfony\Component\Mailer\Transport\TransportInterface as MailerTransportInterface;
use Symfony\Component\Messenger\Worker;
use Symfony\Component\Notifier\Transport as NotifierTransport;
use Symfony\Component\Notifier\Transport\TransportInterface as NotifierTransportInterface;
use Symfony\Component\Scheduler\Messenger\ScheduledStamp;
use Symfony\Contracts\HttpClient\HttpClientInterface;

use function Symfony\Component\DependencyInjection\Loader\Configurator\param;
use function Symfony\Component\DependencyInjection\Loader\Configurator\service;
use function Symfony\Component\DependencyInjection\Loader\Configurator\tagged_iterator;

/**
 * @phpstan-type ChannelConfig array{type: string, dsn?: string, from?: string, to: list<string>, message?: string, phone?: string, service?: string}
 * @phpstan-type Config array{
 *     application: string,
 *     pool: string,
 *     window: int,
 *     cap: int,
 *     timeout: int,
 *     channels: array<string, ChannelConfig>,
 *     http: array{client_errors: list<int>},
 *     console: array{status_commands: list<string>},
 *     dependencies: array<string, string>,
 *     absence: array{
 *         interval: int,
 *         worker?: array{transport: string, silence: int, probe_wait: int},
 *         schedule: array<string, int>,
 *         failure_queue?: array{transport: string, threshold: int},
 *         unread?: array{delay: int},
 *     },
 * }
 */
final class MulerTechAlertingBundle extends AbstractBundle
{
    protected string $extensionAlias = 'mulertech_alerting';

    public const string PREFIX = 'mulertech_alerting.';

    public const string WATCH_TAG = 'mulertech_alerting.watch';

    private const array CHANNEL_TYPES = ['mail', 'notifier', 'service'];

    public function configure(DefinitionConfigurator $definition): void
    {
        $definition->rootNode()
            ->children()
                ->scalarNode('application')
                    ->info('Names the application in every alert, so that production and staging stay apart in a shared mailbox.')
                    ->isRequired()->cannotBeEmpty()
                ->end()
                ->scalarNode('pool')
                    ->info('Service id of the PSR-6 cache pool holding the state of the alerting. Shared by every process of the application: Redis, Memcached, or the filesystem in a directory every container mounts.')
                    ->isRequired()->cannotBeEmpty()
                ->end()
                ->integerNode('window')->info('Seconds during which a fault is sent once.')->min(1)->defaultValue(900)->end()
                ->integerNode('cap')->info('Alerts sent per window, every fault included.')->min(1)->defaultValue(20)->end()
                ->integerNode('timeout')->info('Seconds a channel may wait for its server.')->min(1)->defaultValue(10)->end()
                ->arrayNode('channels')
                    ->info('Tried in this order; the first one that accepts the alert ends the chain, and standard error comes last.')
                    ->isRequired()
                    ->requiresAtLeastOneElement()
                    ->useAttributeAsKey('name')
                    ->normalizeKeys(false)
                    ->arrayPrototype()
                        ->children()
                            ->scalarNode('type')->isRequired()->end()
                            ->scalarNode('dsn')->end()
                            ->scalarNode('from')->end()
                            ->arrayNode('to')->scalarPrototype()->end()->end()
                            ->scalarNode('message')->end()
                            ->scalarNode('phone')->end()
                            ->scalarNode('service')->end()
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('http')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->arrayNode('client_errors')
                            ->info('The 4xx to report; every 5xx is.')
                            ->integerPrototype()->min(400)->max(499)->end()
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('console')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->arrayNode('status_commands')
                            ->info('Commands whose exit code reports a state, not a failure: reported only when they throw.')
                            ->scalarPrototype()->cannotBeEmpty()->end()
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('dependencies')
                    ->info('Exception class => name of the dependency it means is failing.')
                    ->useAttributeAsKey('exception')
                    ->normalizeKeys(false)
                    ->scalarPrototype()->cannotBeEmpty()->end()
                ->end()
                ->arrayNode('absence')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->integerNode('interval')->info('Seconds between two checks run by web requests.')->min(1)->defaultValue(60)->end()
                        ->arrayNode('worker')
                            ->children()
                                ->scalarNode('transport')->isRequired()->cannotBeEmpty()->end()
                                ->integerNode('silence')->info('Seconds without a worker on the transport; longer than the longest message.')->isRequired()->min(1)->end()
                                ->integerNode('probe_wait')->info('Seconds mulertech:alerting:test waits for the worker to answer its probe.')->min(1)->defaultValue(30)->end()
                            ->end()
                        ->end()
                        ->arrayNode('schedule')
                            ->info('Scheduled task (command name or message class) => seconds it may go without completing.')
                            ->useAttributeAsKey('task')
                            ->normalizeKeys(false)
                            ->integerPrototype()->min(1)->end()
                        ->end()
                        ->arrayNode('failure_queue')
                            ->children()
                                ->scalarNode('transport')->isRequired()->cannotBeEmpty()->end()
                                ->integerNode('threshold')->isRequired()->min(1)->end()
                            ->end()
                        ->end()
                        ->arrayNode('unread')
                            ->children()
                                ->integerNode('delay')->info('Seconds an alert may wait unread in a channel that tells.')->isRequired()->min(1)->end()
                            ->end()
                        ->end()
                    ->end()
                ->end()
            ->end();
    }

    public function prependExtension(ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        if ($builder->hasExtension('monolog')) {
            $builder->prependExtensionConfig('monolog', [
                'handlers' => ['mulertech_alerting' => ['type' => 'service', 'id' => self::PREFIX.'monolog_handler']],
            ]);
        }
    }

    /**
     * @param array<mixed> $config
     */
    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        /** @var Config $config */
        $bundles = $builder->hasParameter('kernel.bundles') ? $builder->getParameter('kernel.bundles') : [];

        // The builder given here knows this extension alone, so Monolog is looked for among the bundles.
        if (!\is_array($bundles) || !\in_array(MonologBundle::class, $bundles, true) || !class_exists(AbstractProcessingHandler::class)) {
            throw new InvalidConfigurationException('mulertech_alerting needs symfony/monolog-bundle: the errors the application logs, PHP fatal errors and warnings reach the alerting through Monolog alone. Run "composer require symfony/monolog-bundle".');
        }

        $services = $container->services();
        $absence = $config['absence'];
        $hasWatches = isset($absence['worker']) || [] !== $absence['schedule'] || isset($absence['failure_queue']) || isset($absence['unread']);

        $builder->setParameter(self::PREFIX.'config', [
            'pool' => $config['pool'],
            'channels' => $config['channels'],
            'apcu_refused' => $hasWatches,
            'unread' => $absence['unread'] ?? null,
            'timeout' => $config['timeout'],
        ]);

        $this->loadCore($services, $config);
        $channels = $this->loadChannels($services, $config['channels'], $config['timeout']);
        $this->loadSources($services, $config);
        $this->loadAbsence($services, $config, $hasWatches);

        $services->set(self::PREFIX.'channel_chain', ChannelChain::class)
            ->args([$channels, service(self::PREFIX.'standard_error')]);

        $test = $services->set(self::PREFIX.'command.test', TestCommand::class)
            ->args([$config['application'], $channels, service(self::PREFIX.'standard_error'), service('clock')])
            ->tag('console.command', ['command' => 'mulertech:alerting:test']);

        if (isset($absence['worker'])) {
            $test->arg(4, service(self::PREFIX.'probe_sender'));
        }
    }

    public function build(ContainerBuilder $container): void
    {
        $container->addCompilerPass(new AlertingPass(), PassConfig::TYPE_BEFORE_OPTIMIZATION);
    }

    /**
     * @param Config $config
     */
    private function loadCore(ServicesConfigurator $services, array $config): void
    {
        $services->set(self::PREFIX.'standard_error', StandardErrorChannel::class);
        $services->set(self::PREFIX.'formatter', TextFormatter::class);
        $services->alias(TextFormatter::class, self::PREFIX.'formatter');

        $services->set(self::PREFIX.'throttle', Throttle::class)
            ->args([service($config['pool']), service('clock'), $config['window'], $config['cap']]);

        $services->set(self::PREFIX.'alerter', Alerter::class)
            ->args([
                $config['application'],
                service(self::PREFIX.'throttle'),
                service(self::PREFIX.'channel_chain'),
                service(self::PREFIX.'standard_error'),
                service('clock'),
                $config['window'],
                $config['cap'],
            ]);
        $services->alias(Alerter::class, self::PREFIX.'alerter');

        $services->set(self::PREFIX.'ledger', Ledger::class)
            ->args([service($config['pool']), service('clock')]);

        if (class_exists(HttpClient::class)) {
            $services->set(self::PREFIX.'http_client')
                ->class(HttpClientInterface::class)
                ->factory([HttpClient::class, 'create'])
                ->args([['timeout' => $config['timeout'], 'max_duration' => $config['timeout']]]);
        }
    }

    /**
     * @param array<string, ChannelConfig> $channels
     *
     * @return array<string, ReferenceConfigurator>
     */
    private function loadChannels(ServicesConfigurator $services, array $channels, int $timeout): array
    {
        $references = [];

        foreach ($channels as $name => $channel) {
            $id = self::PREFIX.'channel.'.$name;

            match ($channel['type']) {
                'mail' => $this->loadMailChannel($services, $id, $name, $channel, $timeout),
                'notifier' => $this->loadNotifierChannel($services, $id, $name, $channel, $timeout),
                'service' => $services->alias($id, self::required($name, $channel, 'service')),
                default => throw new InvalidConfigurationException(\sprintf('The channel "%s" of mulertech_alerting has the type "%s". Accepted types: %s.', $name, $channel['type'], implode(', ', self::CHANNEL_TYPES))),
            };

            $references[$name] = service($id);
        }

        return $references;
    }

    /**
     * @param ChannelConfig $channel
     */
    private function loadMailChannel(ServicesConfigurator $services, string $id, string $name, array $channel, int $timeout): void
    {
        if (!class_exists(MailerTransport::class)) {
            // Out of reach of the suite, which installs every optional component.
            throw new InvalidConfigurationException(\sprintf('The mail channel "%s" of mulertech_alerting needs symfony/mailer. Run "composer require symfony/mailer".', $name));
        }

        if ([] === $channel['to']) {
            throw new InvalidConfigurationException(\sprintf('The mail channel "%s" of mulertech_alerting declares no recipient: list them under "to".', $name));
        }

        $services->set(self::PREFIX.'mailer_transport_factory', MailerTransport::class)->args([[]]);

        $services->set($id.'.transport', MailerTransportInterface::class)
            ->factory([service(self::PREFIX.'mailer_transport_factory'), 'fromString'])
            ->args([self::required($name, $channel, 'dsn')]);

        $services->set($id, MailChannel::class)
            ->args([
                service($id.'.transport'),
                self::required($name, $channel, 'from'),
                $channel['to'],
                service(self::PREFIX.'formatter'),
                $timeout,
            ]);
    }

    /**
     * @param ChannelConfig $channel
     */
    private function loadNotifierChannel(ServicesConfigurator $services, string $id, string $name, array $channel, int $timeout): void
    {
        if (!class_exists(NotifierTransport::class)) {
            // Out of reach of the suite, which installs every optional component.
            throw new InvalidConfigurationException(\sprintf('The Notifier channel "%s" of mulertech_alerting needs symfony/notifier. Run "composer require symfony/notifier".', $name));
        }

        $kind = self::required($name, $channel, 'message');
        $message = NotifierMessage::tryFrom($kind) ?? throw new InvalidConfigurationException(\sprintf('The Notifier channel "%s" of mulertech_alerting sends "%s" messages. Accepted: %s.', $name, $kind, implode(', ', array_column(NotifierMessage::cases(), 'value'))));

        $phone = NotifierMessage::Sms === $message ? self::required($name, $channel, 'phone') : null;
        $factory = NotifierMessage::Chat === $message ? 'chatter_transport_factory' : 'texter_transport_factory';

        $services->set(self::PREFIX.$factory, NotifierTransport::class)->args([[]]);

        $services->set($id.'.transport', NotifierTransportInterface::class)
            ->factory([service(self::PREFIX.$factory), 'fromString'])
            ->args([self::required($name, $channel, 'dsn')]);

        $services->set($id, NotifierChannel::class)
            ->args([service($id.'.transport'), $message, service(self::PREFIX.'formatter'), $timeout, $phone]);
    }

    /**
     * @param Config $config
     */
    private function loadSources(ServicesConfigurator $services, array $config): void
    {
        $sourceArgs = [service(self::PREFIX.'alerter'), service(self::PREFIX.'claims'), service(self::PREFIX.'alert_factory')];

        $services->set(self::PREFIX.'claims', ThrowableClaims::class)->tag('kernel.reset', ['method' => 'reset']);
        $services->set(self::PREFIX.'dependency_classifier', DependencyClassifier::class)->args([$config['dependencies']]);
        $services->set(self::PREFIX.'alert_factory', ThrowableAlertFactory::class)
            ->args([service(self::PREFIX.'dependency_classifier'), param('kernel.project_dir')]);

        $services->set(self::PREFIX.'source.http', HttpExceptionSubscriber::class)
            ->args([...$sourceArgs, $config['http']['client_errors'], []])
            ->tag('kernel.event_subscriber');

        $services->set(self::PREFIX.'source.console', ConsoleFailureSubscriber::class)
            ->args([...$sourceArgs, $config['console']['status_commands']])
            ->tag('kernel.event_subscriber')
            ->tag('kernel.reset', ['method' => 'reset']);

        $services->set(self::PREFIX.'monolog_handler', AlertingHandler::class)->args($sourceArgs);

        if (class_exists(Worker::class)) {
            $services->set(self::PREFIX.'source.messenger', MessengerFailureSubscriber::class)
                ->args($sourceArgs)
                ->tag('kernel.event_subscriber');
        }
    }

    /**
     * @param Config $config
     */
    private function loadAbsence(ServicesConfigurator $services, array $config, bool $hasWatches): void
    {
        $absence = $config['absence'];
        $reporting = [service(self::PREFIX.'ledger'), service(self::PREFIX.'alerter'), service(self::PREFIX.'alert_factory')];

        if (isset($absence['worker'])) {
            self::requireMessenger('absence.worker');
            $worker = $absence['worker'];

            $services->set(self::PREFIX.'worker_heartbeat', WorkerHeartbeat::class)
                ->args([...$reporting, service('clock')])
                ->tag('kernel.event_subscriber');

            $services->set(self::PREFIX.'watch.worker', WorkerHeartbeatWatch::class)
                ->args([service(self::PREFIX.'ledger'), service(self::PREFIX.'alerter'), service('clock'), $worker['transport'], $worker['silence'], $config['pool']])
                ->tag(self::WATCH_TAG);

            $services->set(self::PREFIX.'probe_handler', PoolProbeHandler::class)
                ->args([service(self::PREFIX.'ledger'), service(self::PREFIX.'alerter'), $config['pool']])
                ->tag('messenger.message_handler', ['handles' => PoolProbe::class]);

            $services->set(self::PREFIX.'probe_sender', PoolProbeSender::class)
                ->args([service(self::PREFIX.'ledger'), service('messenger.default_bus'), service('clock'), $worker['transport'], $worker['probe_wait']]);
        }

        if ([] !== $absence['schedule']) {
            self::requireMessenger('absence.schedule');

            if (!class_exists(ScheduledStamp::class)) {
                // Out of reach of the suite, which installs every optional component.
                throw new InvalidConfigurationException('mulertech_alerting.absence.schedule needs symfony/scheduler. Run "composer require symfony/scheduler".');
            }

            $services->set(self::PREFIX.'schedule_recorder', ScheduledTaskRecorder::class)
                ->args($reporting)
                ->tag('kernel.event_subscriber');

            foreach ($absence['schedule'] as $task => $delay) {
                $services->set(self::PREFIX.'watch.schedule.'.$task, ScheduledTaskWatch::class)
                    ->args([service(self::PREFIX.'ledger'), service(self::PREFIX.'alerter'), service('clock'), (string) $task, $delay])
                    ->tag(self::WATCH_TAG);
            }
        }

        if (isset($absence['failure_queue'])) {
            self::requireMessenger('absence.failure_queue');

            $services->set(self::PREFIX.'watch.failure_queue', FailureQueueWatch::class)
                ->args([service('messenger.receiver_locator'), service(self::PREFIX.'alerter'), $absence['failure_queue']['transport'], $absence['failure_queue']['threshold']])
                ->tag(self::WATCH_TAG);
        }

        if (!$hasWatches) {
            return;
        }

        $services->set(self::PREFIX.'absence_check', AbsenceCheck::class)
            ->args([tagged_iterator(self::WATCH_TAG), service(self::PREFIX.'alerter'), service(self::PREFIX.'alert_factory')]);

        $services->set(self::PREFIX.'check_on_terminate', CheckOnTerminate::class)
            ->args([service(self::PREFIX.'absence_check'), ...$reporting, service('clock'), $absence['interval']])
            ->tag('kernel.event_subscriber');

        $services->set(self::PREFIX.'command.check', CheckCommand::class)
            ->args([service(self::PREFIX.'absence_check')])
            ->tag('console.command', ['command' => 'mulertech:alerting:check']);
    }

    private static function requireMessenger(string $key): void
    {
        if (!class_exists(Worker::class)) {
            // Out of reach of the suite, which installs every optional component.
            throw new InvalidConfigurationException(\sprintf('mulertech_alerting.%s needs symfony/messenger. Run "composer require symfony/messenger".', $key));
        }
    }

    /**
     * @param ChannelConfig $channel
     */
    private static function required(string $name, array $channel, string $key): string
    {
        $value = $channel[$key] ?? null;

        if (!\is_string($value) || '' === $value) {
            throw new InvalidConfigurationException(\sprintf('The %s channel "%s" of mulertech_alerting needs "%s".', $channel['type'], $name, $key));
        }

        return $value;
    }
}
