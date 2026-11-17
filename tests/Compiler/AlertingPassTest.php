<?php

declare(strict_types=1);

namespace MulerTech\AlertingBundle\Tests\Compiler;

use MulerTech\AlertingBundle\Compiler\AlertingPass;
use MulerTech\AlertingBundle\Source\HttpExceptionSubscriber;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;
use Symfony\Component\DependencyInjection\ChildDefinition;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\Notifier\Transport;

final class AlertingPassTest extends TestCase
{
    public function testWithoutTheBundleConfiguredThePassDoesNothing(): void
    {
        $container = new ContainerBuilder();

        new AlertingPass()->process($container);

        self::assertSame(['service_container'], array_keys($container->getDefinitions()));
    }

    public function testTheTransportFactoriesOfTheFrameworkAreCopiedWithTheClientOfTheBundle(): void
    {
        $container = $this->container(withClient: true);

        new AlertingPass()->process($container);

        $copy = $container->getDefinition('mulertech_alerting.chatter_transport_factory.0');
        self::assertInstanceOf(ChildDefinition::class, $copy);
        self::assertSame('notifier.transport_factory.slack', $copy->getParent());
        self::assertEquals(new Reference('mulertech_alerting.http_client'), $copy->getArgument(1));
        self::assertEquals(
            [new Reference('mulertech_alerting.chatter_transport_factory.0')],
            $container->getDefinition('mulertech_alerting.chatter_transport_factory')->getArgument(0),
        );
    }

    public function testWithoutHttpClientTheFactoriesKeepTheirOwn(): void
    {
        $container = $this->container(withClient: false);

        new AlertingPass()->process($container);

        self::assertSame([], $container->getDefinition('mulertech_alerting.chatter_transport_factory.0')->getArguments());
    }

    public function testWithoutTheErrorListenerOfTheFrameworkNoStatusCodeIsMapped(): void
    {
        $container = $this->container(withClient: false);

        new AlertingPass()->process($container);

        self::assertSame([], $container->getDefinition('mulertech_alerting.source.http')->getArgument(4));
    }

    private function container(bool $withClient): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setParameter('mulertech_alerting.config', [
            'pool' => 'cache.alerting',
            'channels' => [],
            'apcu_refused' => false,
            'unread' => null,
            'timeout' => 10,
        ]);
        $container->register('cache.alerting', FilesystemAdapter::class);
        $container->register('notifier.transport_factory.slack', \stdClass::class)->addTag('chatter.transport_factory');
        $container->register('mulertech_alerting.chatter_transport_factory', Transport::class)->setArguments([[]]);
        $container->register('mulertech_alerting.source.http', HttpExceptionSubscriber::class)->setArguments([null, null, null, [], []]);

        if ($withClient) {
            $container->register('mulertech_alerting.http_client', \stdClass::class);
        }

        return $container;
    }
}
