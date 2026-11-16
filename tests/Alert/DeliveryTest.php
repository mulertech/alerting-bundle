<?php

declare(strict_types=1);

namespace MulerTech\AlertingBundle\Tests\Alert;

use MulerTech\AlertingBundle\Alert\Alert;
use MulerTech\AlertingBundle\Alert\Delivery;
use MulerTech\AlertingBundle\Alert\Severity;
use PHPUnit\Framework\TestCase;

final class DeliveryTest extends TestCase
{
    public function testAnIncidentIsAddedToACopy(): void
    {
        $alert = new Alert(Severity::Error, 'http', 'Failure', []);
        $raisedAt = new \DateTimeImmutable('2026-11-16 10:00:00');
        $delivery = new Delivery('shop-prod', $alert, $raisedAt, 4, ['first']);

        $next = $delivery->withIncident('second');

        self::assertSame(['first'], $delivery->incidents);
        self::assertSame(['first', 'second'], $next->incidents);
        self::assertSame('shop-prod', $next->application);
        self::assertSame($alert, $next->alert);
        self::assertSame($raisedAt, $next->raisedAt);
        self::assertSame(4, $next->suppressed);
    }
}
