<?php

declare(strict_types=1);

namespace MulerTech\AlertingBundle\Tests\Source;

use MulerTech\AlertingBundle\Source\DependencyClassifier;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Mailer\Exception\TransportException;

final class DependencyClassifierTest extends TestCase
{
    public function testAKnownDependencyIsRecognised(): void
    {
        self::assertSame('mail transport', new DependencyClassifier()->classify(new TransportException('Connection refused')));
    }

    public function testADependencyIsFoundAnywhereInTheChain(): void
    {
        $failure = new \RuntimeException('Order not saved', 0, new \LogicException('Wrapped', 0, new TransportException('Connection refused')));

        self::assertSame('mail transport', new DependencyClassifier()->classify($failure));
    }

    public function testTheApplicationNamesItsOwnDependencies(): void
    {
        $classifier = new DependencyClassifier([\DomainException::class => 'routing engine']);

        self::assertSame('routing engine', $classifier->classify(new \DomainException('Timeout')));
    }

    public function testADeclaredDependencyComesBeforeAKnownOne(): void
    {
        $classifier = new DependencyClassifier([TransportException::class => 'newsletter relay']);

        self::assertSame('newsletter relay', $classifier->classify(new TransportException('Connection refused')));
    }

    public function testAnOrdinaryExceptionIsNoDependency(): void
    {
        self::assertNull(new DependencyClassifier()->classify(new \RuntimeException('Failure')));
    }
}
