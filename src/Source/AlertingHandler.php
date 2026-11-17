<?php

declare(strict_types=1);

namespace MulerTech\AlertingBundle\Source;

use Monolog\Handler\AbstractProcessingHandler;
use Monolog\Level;
use Monolog\LogRecord;
use MulerTech\AlertingBundle\Alert\Alert;
use MulerTech\AlertingBundle\Alert\Severity;
use MulerTech\AlertingBundle\Alerter;
use Symfony\Component\ErrorHandler\Error\OutOfMemoryError;

/**
 * Reports what reaches the logs at `error` and above and no other source owns: the errors the
 * application logs itself, PHP fatal errors, warnings and notices.
 *
 * The fingerprint of a record without an exception is its channel and its message template, before
 * the placeholders are filled in: "Import of {file} failed" is one fault, whatever the file.
 *
 * The logger is built with the kernel, a build included: the alerter, its pool and its channels are
 * built at the first record to report, never with the handler.
 */
final class AlertingHandler extends AbstractProcessingHandler
{
    public const string OWNER = 'log';

    public const int PRIORITY = 1024;

    /**
     * What an out-of-memory error is given above what the process already holds: enough to build
     * the alerter and its channels, and to send through them. Symfony frees 32 KiB for the whole
     * error, which a single class loaded exhausts.
     */
    private const int OUT_OF_MEMORY_MARGIN = 32 * 1024 * 1024;

    /**
     * @param \Closure(): Alerter $alerter
     */
    public function __construct(
        private readonly \Closure $alerter,
        private readonly ThrowableClaims $claims,
        private readonly ThrowableAlertFactory $factory,
    ) {
        parent::__construct(Level::Error);
    }

    protected function write(LogRecord $record): void
    {
        $exception = $record->context['exception'] ?? null;

        if ($exception instanceof OutOfMemoryError) {
            self::raiseMemoryLimit();
        }
        $severity = $record->level->isHigherThan(Level::Error) ? Severity::Critical : Severity::Error;
        $context = ['channel' => $record->channel, ...$record->context];
        $summary = self::interpolate($record->message, $record->context);

        if (!$exception instanceof \Throwable) {
            $this->report(new Alert($severity, 'log', $summary, [$record->channel, $record->message], $context));

            return;
        }

        unset($context['exception']);

        if (!$this->claims->claim($exception, self::OWNER)) {
            return;
        }

        $this->report($this->factory->create('log', $severity, $summary, $exception, $context, [$record->channel]));
    }

    /**
     * Only ever raised, and left alone when unlimited: the process is ending on this error.
     */
    private static function raiseMemoryLimit(): void
    {
        if ('-1' === \ini_get('memory_limit')) {
            return;
        }

        @ini_set('memory_limit', (string) (memory_get_usage(true) + self::OUT_OF_MEMORY_MARGIN));
    }

    /**
     * The alerter never throws once built; building it may, when its pool cannot be reached. A log
     * call of the application must not fail for it, and the alert must not vanish either.
     */
    private function report(Alert $alert): void
    {
        try {
            $alerter = ($this->alerter)();
        } catch (\Throwable $failure) {
            error_log(\sprintf('mulertech_alerting could not be built, so this alert reaches the error log only: %s. Cause: %s: %s', $alert->summary, $failure::class, $failure->getMessage()));

            return;
        }

        $alerter->alert($alert);
    }

    /**
     * @param array<mixed> $context
     */
    private static function interpolate(string $message, array $context): string
    {
        $replacements = [];

        foreach ($context as $key => $value) {
            if (\is_scalar($value) || $value instanceof \Stringable) {
                $replacements['{'.$key.'}'] = (string) $value;
            }
        }

        return strtr($message, $replacements);
    }
}
