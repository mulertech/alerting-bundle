<?php

declare(strict_types=1);

namespace MulerTech\AlertingBundle\Tests\Channel;

use MulerTech\AlertingBundle\Alert\Alert;
use MulerTech\AlertingBundle\Alert\Delivery;
use MulerTech\AlertingBundle\Alert\Severity;
use MulerTech\AlertingBundle\Channel\StandardErrorChannel;
use MulerTech\AlertingBundle\Tests\Double\StandardErrorFile;
use PHPUnit\Framework\Attributes\RequiresOperatingSystem;
use PHPUnit\Framework\TestCase;

final class StandardErrorChannelTest extends TestCase
{
    public function testItWritesTheWholeDeliveryOnOneJsonLine(): void
    {
        $file = new StandardErrorFile();

        $file->channel()->send($this->delivery());

        self::assertCount(1, (array) file($file->path));
        self::assertSame([[
            'alerting' => 'shop-prod',
            'severity' => 'critical',
            'source' => 'http',
            'summary' => 'Database "main" unreachable',
            'context' => ['route' => 'app_home'],
            'suppressed' => 3,
            'previous_alert_at' => '2026-11-16T08:30:00+00:00',
            'incidents' => ['Channel "mail" refused the alert: RuntimeException: Connection refused'],
            'raised_at' => '2026-11-16T10:00:00+00:00',
        ]], $file->lines());
    }

    public function testInvalidUtf8DoesNotLoseTheAlert(): void
    {
        $file = new StandardErrorFile();
        $alert = new Alert(Severity::Error, 'log', "Bad byte \xB1 here", []);

        $file->channel()->send(new Delivery('shop-prod', $alert, new \DateTimeImmutable()));

        self::assertSame("Bad byte \u{FFFD} here", $file->lines()[0]['summary']);
    }

    public function testAStreamThatCannotBeOpenedFallsBackToThePhpErrorLog(): void
    {
        $logged = $this->errorLogOf(fn () => new StandardErrorChannel('/nonexistent-directory/stderr')->send($this->delivery()));

        self::assertStringContainsString('"summary":"Database \"main\" unreachable"', $logged);
    }

    #[RequiresOperatingSystem('Linux')]
    public function testAStreamThatRefusesTheWriteFallsBackToThePhpErrorLog(): void
    {
        $logged = $this->errorLogOf(fn () => new StandardErrorChannel('/dev/full')->send($this->delivery()));

        self::assertStringContainsString('"summary":"Database \"main\" unreachable"', $logged);
    }

    /**
     * PHPUnit points error_log at its own capture once the test has started, so the redirection
     * happens here rather than in setUp().
     */
    private function errorLogOf(\Closure $send): string
    {
        $target = (string) tempnam(sys_get_temp_dir(), 'alerting_error_log_');
        $previous = ini_set('error_log', $target);

        try {
            $send();

            return (string) file_get_contents($target);
        } finally {
            ini_set('error_log', (string) $previous);
            @unlink($target);
        }
    }

    private function delivery(): Delivery
    {
        return new Delivery(
            'shop-prod',
            new Alert(Severity::Critical, 'http', 'Database "main" unreachable', ['database', 'main'], ['route' => 'app_home']),
            new \DateTimeImmutable('2026-11-16 10:00:00', new \DateTimeZone('UTC')),
            3,
            ['Channel "mail" refused the alert: RuntimeException: Connection refused'],
            new \DateTimeImmutable('2026-11-16 08:30:00', new \DateTimeZone('UTC')),
        );
    }
}
