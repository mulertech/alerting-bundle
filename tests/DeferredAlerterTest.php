<?php

declare(strict_types=1);

namespace MulerTech\AlertingBundle\Tests;

use MulerTech\AlertingBundle\Alert\Alert;
use MulerTech\AlertingBundle\Alert\Severity;
use MulerTech\AlertingBundle\Alerter;
use MulerTech\AlertingBundle\DeferredAlerter;
use MulerTech\AlertingBundle\Tests\Double\AlertingKit;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

final class DeferredAlerterTest extends TestCase
{
    public function testNothingIsBuiltBeforeTheFirstAlert(): void
    {
        $built = 0;
        $kit = new AlertingKit();

        $deferred = new DeferredAlerter(static function () use (&$built, $kit): Alerter {
            ++$built;

            return $kit->alerter;
        });

        self::assertSame(0, $built);

        $deferred->alert(new Alert(Severity::Error, 'log', 'Import of parkings.geojsonseq failed', ['app']));

        self::assertSame(1, $built);
        self::assertSame('Import of parkings.geojsonseq failed', $kit->only()->alert->summary);
    }

    /**
     * A variable the runtime alone provides, or a pool out of reach: the caller goes on, and the
     * alert reaches the error log with its cause.
     */
    public function testAnAlerterThatCannotBeBuiltLeavesTheAlertInTheErrorLog(): void
    {
        $deferred = new DeferredAlerter(static fn (): Alerter => throw new \RuntimeException('Environment variable not found: "ALERTING_APPLICATION".'));
        $target = (string) tempnam(sys_get_temp_dir(), 'alerting_error_log_');
        $previous = ini_set('error_log', $target);

        try {
            $deferred->alert(new Alert(Severity::Error, 'log', 'Import of parkings.geojsonseq failed', ['app']));
            $logged = (string) file_get_contents($target);
        } finally {
            ini_set('error_log', (string) $previous);
            @unlink($target);
        }

        self::assertStringContainsString('mulertech_alerting could not be built, so this alert reaches the error log only: Import of parkings.geojsonseq failed. Cause: RuntimeException: Environment variable not found: "ALERTING_APPLICATION".', $logged);
    }

    public function testAnAlertMarksTheRequestBeingHandled(): void
    {
        $kit = new AlertingKit();
        $request = Request::create('/api/messaging/send', 'POST');
        $requests = new RequestStack([$request]);

        new DeferredAlerter(static fn (): Alerter => $kit->alerter, $requests)
            ->alert(new Alert(Severity::Error, 'log', 'Message not stored', ['app']));

        self::assertTrue($request->attributes->get(DeferredAlerter::REQUEST_REPORTED));
    }

    public function testAnAlertOutsideAnyRequestMarksNothing(): void
    {
        $kit = new AlertingKit();

        new DeferredAlerter(static fn (): Alerter => $kit->alerter, new RequestStack())
            ->alert(new Alert(Severity::Error, 'log', 'Export failed', ['app']));

        self::assertCount(1, $kit->deliveries());
    }
}
