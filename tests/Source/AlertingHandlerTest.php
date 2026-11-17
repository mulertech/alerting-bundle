<?php

declare(strict_types=1);

namespace MulerTech\AlertingBundle\Tests\Source;

use MulerTech\AlertingBundle\Alert\Severity;
use MulerTech\AlertingBundle\Source\AlertingHandler;
use MulerTech\AlertingBundle\Tests\Double\AlertingKit;
use Monolog\Level;
use Monolog\LogRecord;
use PHPUnit\Framework\TestCase;

final class AlertingHandlerTest extends TestCase
{
    public function testAnErrorLoggedByTheApplicationIsReportedWithItsPlaceholdersFilledIn(): void
    {
        $kit = new AlertingKit();

        $this->handler($kit)->handle($this->record(Level::Error, 'Import of {file} failed', ['file' => 'poi.csv', 'rows' => 3]));

        $alert = $kit->only()->alert;
        self::assertSame(Severity::Error, $alert->severity);
        self::assertSame('log', $alert->source);
        self::assertSame('Import of poi.csv failed', $alert->summary);
        self::assertSame(['channel' => 'app', 'file' => 'poi.csv', 'rows' => '3'], $alert->context);
    }

    public function testTheSameTemplateIsOneFaultWhateverItsValues(): void
    {
        $kit = new AlertingKit();
        $handler = $this->handler($kit);

        $handler->handle($this->record(Level::Error, 'Import of {file} failed', ['file' => 'poi.csv']));
        $handler->handle($this->record(Level::Error, 'Import of {file} failed', ['file' => 'parkings.csv']));

        self::assertCount(1, $kit->deliveries());
    }

    public function testCriticalAndAboveAreCritical(): void
    {
        $kit = new AlertingKit();

        $this->handler($kit)->handle($this->record(Level::Emergency, 'Disk full'));

        self::assertSame(Severity::Critical, $kit->only()->alert->severity);
    }

    public function testBelowErrorNothingIsReported(): void
    {
        $kit = new AlertingKit();

        $this->handler($kit)->handle($this->record(Level::Warning, 'Slow query'));

        self::assertSame([], $kit->deliveries());
    }

    public function testAnExceptionNoSourceOwnsIsClaimedAndReported(): void
    {
        $kit = new AlertingKit();
        $fatal = new \Error('Allowed memory size exhausted');

        $this->handler($kit)->handle($this->record(Level::Critical, 'Fatal Error: {message}', ['exception' => $fatal, 'message' => 'Allowed memory size exhausted'], 'php'));

        $alert = $kit->only()->alert;
        self::assertSame('Fatal Error: Allowed memory size exhausted: Error: Allowed memory size exhausted', $alert->summary);
        self::assertSame('php', $alert->context['channel']);
        self::assertSame(AlertingHandler::OWNER, $kit->claims->ownerOf($fatal));
    }

    public function testAnExceptionASourceOwnsIsLeftToIt(): void
    {
        $kit = new AlertingKit();
        $failure = new \RuntimeException('Failure');
        $kit->claims->claim($failure, 'http');

        $this->handler($kit)->handle($this->record(Level::Critical, 'Uncaught PHP Exception', ['exception' => $failure], 'request'));

        self::assertSame([], $kit->deliveries());
    }

    public function testAnExceptionGivenAsTextIsAnOrdinaryValue(): void
    {
        $kit = new AlertingKit();

        $this->handler($kit)->handle($this->record(Level::Error, 'Webhook failed', ['exception' => 'Timeout']));

        self::assertSame('Timeout', $kit->only()->alert->context['exception']);
    }

    private function handler(AlertingKit $kit): AlertingHandler
    {
        return new AlertingHandler($kit->alerter, $kit->claims, $kit->factory);
    }

    /**
     * @param array<string, mixed> $context
     */
    private function record(Level $level, string $message, array $context = [], string $channel = 'app'): LogRecord
    {
        return new LogRecord(new \DateTimeImmutable(), $channel, $level, $message, $context);
    }
}
