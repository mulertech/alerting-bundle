<?php

declare(strict_types=1);

namespace MulerTech\AlertingBundle\Source;

use Monolog\Handler\AbstractProcessingHandler;
use Monolog\Level;
use Monolog\LogRecord;
use MulerTech\AlertingBundle\Alert\Alert;
use MulerTech\AlertingBundle\Alert\Severity;
use MulerTech\AlertingBundle\DeferredAlerter;
use Symfony\Component\ErrorHandler\Error\OutOfMemoryError;

/**
 * Reports what reaches the logs at `error` and above and no other source owns: the errors the
 * application logs itself, PHP fatal errors, warnings and notices.
 *
 * The fingerprint of a record without an exception is its channel and its message template, before
 * the placeholders are filled in: "Import of {file} failed" is one fault, whatever the file.
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

    public function __construct(
        private readonly DeferredAlerter $alerter,
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
            $this->alerter->alert(new Alert($severity, 'log', $summary, [$record->channel, $record->message], $context));

            return;
        }

        unset($context['exception']);

        if (!$this->claims->claim($exception, self::OWNER)) {
            return;
        }

        $this->alerter->alert($this->factory->create('log', $severity, $summary, $exception, $context, [$record->channel]));
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
