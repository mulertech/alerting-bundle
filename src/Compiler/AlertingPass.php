<?php

declare(strict_types=1);

namespace MulerTech\AlertingBundle\Compiler;

use MulerTech\AlertingBundle\Absence\UnreadWatch;
use MulerTech\AlertingBundle\Channel\ChannelInterface;
use MulerTech\AlertingBundle\Channel\ReadReceiptInterface;
use MulerTech\AlertingBundle\MulerTechAlertingBundle;
use Symfony\Component\Cache\Adapter\ApcuAdapter;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Adapter\DoctrineDbalAdapter;
use Symfony\Component\Cache\Adapter\NullAdapter;
use Symfony\Component\Cache\Adapter\PdoAdapter;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\ChildDefinition;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;

/**
 * What the configuration alone cannot settle, read from the container once every bundle declared
 * its services: the pool behind its id, the status codes of `framework.exceptions`, the transport
 * factories of the framework, and the channels the application provides.
 */
final class AlertingPass implements CompilerPassInterface
{
    private const string PREFIX = MulerTechAlertingBundle::PREFIX;

    /**
     * Pools that cannot hold the state of the alerting, and why.
     */
    private const array REFUSED_POOLS = [
        ArrayAdapter::class => 'it lives in the memory of one request, so it deduplicates nothing',
        NullAdapter::class => 'it keeps nothing, so it deduplicates nothing',
        DoctrineDbalAdapter::class => 'it lives in the database, and would fall with what the alerting watches',
        PdoAdapter::class => 'it lives in the database, and would fall with what the alerting watches',
    ];

    /**
     * The transport factories of the framework, by the tag that lists them, and the factory of the
     * bundle that takes them over.
     */
    private const array TRANSPORT_FACTORIES = [
        'mailer.transport_factory' => 'mailer_transport_factory',
        'chatter.transport_factory' => 'chatter_transport_factory',
        'texter.transport_factory' => 'texter_transport_factory',
    ];

    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasParameter(self::PREFIX.'config')) {
            return;
        }

        /** @var array{pool: string, channels: array<string, array{type: string, service?: string}>, apcu_refused: bool, unread: array{delay: int}|null, timeout: int} $config */
        $config = $container->getParameter(self::PREFIX.'config');
        $container->getParameterBag()->remove(self::PREFIX.'config');

        $this->checkPool($container, $config['pool'], $config['apcu_refused']);
        $this->mapStatusCodes($container);
        $this->takeOverTransportFactories($container);
        $this->checkApplicationChannels($container, $config['channels'], $config['unread']);
    }

    private function checkPool(ContainerBuilder $container, string $pool, bool $apcuRefused): void
    {
        if (!$container->has($pool)) {
            throw new InvalidConfigurationException(\sprintf('The pool "%s" of mulertech_alerting is no service. Declare it under framework.cache.pools, on Redis, Memcached or the filesystem.', $pool));
        }

        $class = $this->classOf($container, $container->findDefinition($pool));
        $refusals = self::REFUSED_POOLS;

        if ($apcuRefused) {
            $refusals[ApcuAdapter::class] = 'its memory is not shared between the web server and the command line, where the worker runs';
        }

        foreach ($refusals as $refused => $reason) {
            if (null !== $class && is_a($class, $refused, true)) {
                throw new InvalidConfigurationException(\sprintf('The pool "%s" of mulertech_alerting is a %s: %s. Accepted: a pool on Redis or Memcached (cache.adapter.redis, cache.adapter.memcached), or on the filesystem (cache.adapter.filesystem) in a directory every process of the application shares.', $pool, $class, $reason));
            }
        }
    }

    /**
     * The status code each exception class is given by `framework.exceptions`, so that the alerting
     * judges a request by the status Symfony answers with.
     */
    private function mapStatusCodes(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition('exception_listener')) {
            return;
        }

        $mapping = $container->getDefinition('exception_listener')->getArguments()[3] ?? null;
        $statusCodes = [];

        foreach (\is_array($mapping) ? $mapping : [] as $class => $exception) {
            if (\is_array($exception) && \is_int($exception['status_code'] ?? null)) {
                $statusCodes[$class] = $exception['status_code'];
            }
        }

        $container->getDefinition(self::PREFIX.'source.http')->replaceArgument(4, $statusCodes);
    }

    /**
     * Each transport factory of the framework, copied with the HTTP client of the bundle in place
     * of the application's: a `timeout` in `framework.http_client.default_options` would otherwise
     * override the bound of the channels, and a chat service that does not answer would hold the
     * request that long.
     */
    private function takeOverTransportFactories(ContainerBuilder $container): void
    {
        $client = $container->has(self::PREFIX.'http_client') ? new Reference(self::PREFIX.'http_client') : null;

        foreach (self::TRANSPORT_FACTORIES as $tag => $factory) {
            if (!$container->hasDefinition(self::PREFIX.$factory)) {
                continue;
            }

            $copies = [];

            foreach (array_keys($container->findTaggedServiceIds($tag)) as $index => $id) {
                $copy = new ChildDefinition($id);

                if (null !== $client) {
                    $copy->replaceArgument(1, $client);
                }

                $container->setDefinition(self::PREFIX.$factory.'.'.$index, $copy);
                $copies[] = new Reference(self::PREFIX.$factory.'.'.$index);
            }

            if ([] === $copies) {
                throw new InvalidConfigurationException(\sprintf('A channel of mulertech_alerting needs the transports of the framework, and no service is tagged "%s": enable %s in the framework configuration.', $tag, 'mailer.transport_factory' === $tag ? 'framework.mailer' : 'framework.notifier'));
            }

            $container->getDefinition(self::PREFIX.$factory)->replaceArgument(0, $copies);
        }
    }

    /**
     * @param array<string, array{type: string, service?: string}> $channels
     * @param array{delay: int}|null                               $unread
     */
    private function checkApplicationChannels(ContainerBuilder $container, array $channels, ?array $unread): void
    {
        $readReceipts = [];

        foreach ($channels as $name => $channel) {
            if ('service' !== $channel['type']) {
                continue;
            }

            $id = (string) ($channel['service'] ?? '');

            if (!$container->has($id)) {
                throw new InvalidConfigurationException(\sprintf('The channel "%s" of mulertech_alerting names the service "%s", which does not exist.', $name, $id));
            }

            $class = $this->classOf($container, $container->findDefinition($id));

            if (null === $class || !is_a($class, ChannelInterface::class, true)) {
                throw new InvalidConfigurationException(\sprintf('The service "%s" of the channel "%s" of mulertech_alerting is a %s, which does not implement %s.', $id, $name, $class ?? 'service of unknown class', ChannelInterface::class));
            }

            if (is_a($class, ReadReceiptInterface::class, true)) {
                $readReceipts[$name] = $id;
            }
        }

        if (null === $unread) {
            return;
        }

        if ([] === $readReceipts) {
            throw new InvalidConfigurationException(\sprintf('mulertech_alerting.absence.unread needs a channel that knows whether its alerts were read: a service channel implementing %s.', ReadReceiptInterface::class));
        }

        foreach ($readReceipts as $name => $id) {
            $container->register(self::PREFIX.'watch.unread.'.$name, UnreadWatch::class)
                ->setArguments([new Reference(self::PREFIX.'alerter'), new Reference('clock'), $name, new Reference($id), $unread['delay']])
                ->addTag(MulerTechAlertingBundle::WATCH_TAG);
        }
    }

    /**
     * The class of a service, looked for up its parents: a cache pool is a child of its adapter.
     */
    private function classOf(ContainerBuilder $container, Definition $definition): ?string
    {
        while (null === $definition->getClass() && $definition instanceof ChildDefinition) {
            $definition = $container->findDefinition($definition->getParent());
        }

        $class = $container->getParameterBag()->resolveValue($definition->getClass());

        return \is_string($class) ? $class : null;
    }
}
