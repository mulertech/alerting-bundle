<?php

declare(strict_types=1);

namespace MulerTech\AlertingBundle\Alert;

/**
 * What malfunctions, as it leaves the application.
 *
 * The fingerprint is built from the source and the identity alone, never from the summary or the
 * context: those carry variable text ("User 123 not found"), and a fingerprint that varies with
 * them makes one alert per occurrence instead of one per fault.
 *
 * Text is bounded and the context reduced to strings, because an alert leaves for a third party
 * (a mail provider, a chat service) and a message or a context is free text the bundle does not
 * control: an SQL query with its parameters, a serialised object, a trace.
 */
final readonly class Alert
{
    public const int MAX_TEXT_LENGTH = 500;

    public string $summary;

    public string $fingerprint;

    /**
     * @var array<string, string>
     */
    public array $context;

    /**
     * @param list<string>         $identity what makes two occurrences the same fault: a class, a
     *                                       file and a line, a command name and its exit code
     * @param array<string, mixed> $context
     */
    public function __construct(
        public Severity $severity,
        public string $source,
        string $summary,
        array $identity,
        array $context = [],
    ) {
        $this->summary = self::bound($summary);
        $this->fingerprint = hash('xxh128', implode("\x1F", [$source, ...$identity]));
        $this->context = array_map(self::describe(...), $context);
    }

    private static function describe(mixed $value): string
    {
        return self::bound(match (true) {
            null === $value => 'null',
            is_bool($value) => $value ? 'true' : 'false',
            is_scalar($value) => (string) $value,
            default => get_debug_type($value),
        });
    }

    private static function bound(string $text): string
    {
        return mb_strlen($text) > self::MAX_TEXT_LENGTH
            ? mb_substr($text, 0, self::MAX_TEXT_LENGTH).'…'
            : $text;
    }
}
