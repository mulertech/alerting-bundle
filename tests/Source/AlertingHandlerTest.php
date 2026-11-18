<?php

declare(strict_types=1);

namespace MulerTech\AlertingBundle\Tests\Source;

use Symfony\Component\ErrorHandler\Error\OutOfMemoryError;
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

    /**
     * Symfony frees 32 KiB to log an out-of-memory error: the limit is raised before the alerter,
     * its pool and its channels are built and sent through.
     */
    public function testAnOutOfMemoryErrorRaisesTheLimitBeforeItIsReported(): void
    {
        $kit = new AlertingKit();
        $previous = (string) \ini_get('memory_limit');
        ini_set('memory_limit', (string) (memory_get_usage(true) + 4 * 1024 * 1024));

        try {
            $error = new OutOfMemoryError('Error: Allowed memory size of 50331648 bytes exhausted', 0, ['type' => \E_ERROR, 'message' => 'Allowed memory size of 50331648 bytes exhausted', 'file' => __FILE__, 'line' => __LINE__], 0);
            $this->handler($kit)->handle($this->record(Level::Critical, 'Fatal Error: Allowed memory size of 50331648 bytes exhausted', ['exception' => $error], 'php'));
            $raised = (int) \ini_get('memory_limit');
        } finally {
            ini_set('memory_limit', $previous);
        }

        self::assertGreaterThanOrEqual(memory_get_usage(true) + 30 * 1024 * 1024, $raised);
        self::assertSame(Severity::Critical, $kit->only()->alert->severity);
    }

    private function handler(AlertingKit $kit): AlertingHandler
    {
        return new AlertingHandler($kit->deferred, $kit->claims, $kit->factory);
    }

    /**
     * @param array<string, mixed> $context
     */
    private function record(Level $level, string $message, array $context = [], string $channel = 'app'): LogRecord
    {
        return new LogRecord(new \DateTimeImmutable(), $channel, $level, $message, $context);
    }
}
