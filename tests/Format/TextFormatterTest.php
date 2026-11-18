<?php

declare(strict_types=1);

namespace MulerTech\AlertingBundle\Tests\Format;

use MulerTech\AlertingBundle\Alert\Alert;
use MulerTech\AlertingBundle\Alert\Delivery;
use MulerTech\AlertingBundle\Alert\Severity;
use MulerTech\AlertingBundle\Format\TextFormatter;
use PHPUnit\Framework\TestCase;

final class TextFormatterTest extends TestCase
{
    public function testTheSubjectNamesTheApplicationTheSeverityAndTheFault(): void
    {
        self::assertSame(
            '[shop-prod] Critical: Database "main" unreachable',
            new TextFormatter()->subject($this->delivery(Severity::Critical, 'Database "main" unreachable')),
        );
    }

    public function testALongSubjectIsCutAndSaysSo(): void
    {
        $subject = new TextFormatter()->subject($this->delivery(Severity::Error, str_repeat('x', 300)));

        self::assertSame(TextFormatter::MAX_SUBJECT_LENGTH + 1, mb_strlen($subject));
        self::assertStringEndsWith('…', $subject);
    }

    public function testABareAlertHasItsSummaryAndItsOrigin(): void
    {
        self::assertSame(
            <<<'TEXT'
                Uncaught RuntimeException

                Application: shop-prod
                Severity: error
                Source: http
                Raised at: 2026-11-16T10:00:00+00:00
                TEXT,
            new TextFormatter()->body($this->delivery(Severity::Error, 'Uncaught RuntimeException')),
        );
    }

    public function testTheBodyCarriesTheContextTheCountAndTheIncidents(): void
    {
        $delivery = new Delivery(
            'shop-prod',
            new Alert(Severity::Error, 'http', 'Uncaught RuntimeException', [], ['route' => 'app_home', 'method' => 'GET']),
            new \DateTimeImmutable('2026-11-16 10:00:00', new \DateTimeZone('UTC')),
            12,
            ['Channel "messaging" refused the alert: RuntimeException: Database unreachable'],
        );

        self::assertSame(
            <<<'TEXT'
                Uncaught RuntimeException

                Application: shop-prod
                Severity: error
                Source: http
                Raised at: 2026-11-16T10:00:00+00:00

                Context:
                  route: app_home
                  method: GET

                12 more occurrences of this fault were held back since the previous alert.

                Incidents of the alerting itself:
                  - Channel "messaging" refused the alert: RuntimeException: Database unreachable
                TEXT,
            new TextFormatter()->body($delivery),
        );
    }

    public function testTheHeldBackOccurrencesSayWhenThePreviousAlertLeft(): void
    {
        $delivery = new Delivery(
            'shop-prod',
            new Alert(Severity::Error, 'schedule', 'The scheduled task "App\\Message\\RefreshPoiAccess" has not completed', []),
            new \DateTimeImmutable('2026-11-17 18:24:39', new \DateTimeZone('Europe/Paris')),
            1,
            previousAlertAt: new \DateTimeImmutable('2026-11-17 15:02:10', new \DateTimeZone('Europe/Paris')),
        );

        self::assertStringContainsString(
            'One more occurrence of this fault was held back since the previous alert, sent at 2026-11-17T15:02:10+01:00.',
            new TextFormatter()->body($delivery),
        );
    }

    public function testAContextValueOnSeveralLinesStaysUnderItsKey(): void
    {
        $delivery = new Delivery(
            'shop-prod',
            new Alert(Severity::Error, 'console', 'Console "app:import" failed', [], [
                'message' => "Command \"app:import\" is not defined.\n\nDid you mean one of these?\n    app:import-parkings",
                'command' => 'app:import',
            ]),
            new \DateTimeImmutable('2026-11-16 10:00:00', new \DateTimeZone('UTC')),
        );

        self::assertStringContainsString(
            "Context:\n  message: Command \"app:import\" is not defined.\n\n    Did you mean one of these?\n        app:import-parkings\n  command: app:import",
            new TextFormatter()->body($delivery),
        );
    }

    public function testASingleOccurrenceHeldBackIsSaidInTheSingular(): void
    {
        $delivery = new Delivery(
            'shop-prod',
            new Alert(Severity::Error, 'http', 'Uncaught RuntimeException', []),
            new \DateTimeImmutable('2026-11-16 10:00:00', new \DateTimeZone('UTC')),
            1,
        );

        self::assertStringContainsString(
            "\n\nOne more occurrence of this fault was held back since the previous alert.",
            new TextFormatter()->body($delivery),
        );
    }

    private function delivery(Severity $severity, string $summary): Delivery
    {
        return new Delivery(
            'shop-prod',
            new Alert($severity, 'http', $summary, []),
            new \DateTimeImmutable('2026-11-16 10:00:00', new \DateTimeZone('UTC')),
        );
    }
}
