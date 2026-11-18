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
 *
 * An e-mail address in that text keeps its domain alone. A mail server that refuses a recipient
 * names it in its answer, and an application logs the address it failed to write to: the address
 * of a user then leaves with the alert, while its domain is all the diagnosis needs.
 *
 * A database that refuses a row quotes the values it refused, which are often a user's: the
 * values are masked, and the constraint, the table and the columns stay, since they name the cause.
 */
final readonly class Alert
{
    public const int MAX_TEXT_LENGTH = 500;

    private const string MASK = '***';

    /**
     * Pattern => replacement. A value runs to the last delimiter of its line, so that a value
     * holding the delimiter itself is masked whole.
     */
    private const array MASKS = [
        // PostgreSQL, exclusion constraint: "Key (during)=(…) conflicts with existing key (during)=(…)."
        '/(Key \([^\n]*?\)=\().*(\) conflicts with existing key \([^\n]*?\)=\().*(\)\.)/u' => '$1'.self::MASK.'$2'.self::MASK.'$3',
        // PostgreSQL, unique and foreign keys: "Key (email)=(…) already exists."
        '/(Key \([^\n]*?\)=\().*(\) (?:already exists|is not present in table|is still referenced from table))/u' => '$1'.self::MASK.'$2',
        // PostgreSQL, not-null and check constraints: "Failing row contains (…)."
        '/(Failing row contains \().*(\))/u' => '$1'.self::MASK.'$2',
        // PostgreSQL, a value the column type cannot read: 'invalid input syntax for type integer: "…"'
        '/(invalid input (?:syntax for type|value for enum) [^:\n]+: ").*(")/u' => '$1'.self::MASK.'$2',
        // MySQL and MariaDB, likewise: "Incorrect integer value: '…' for column 'age'"
        "/(Incorrect [\\w ]+ value: ').*(' for column)/u" => '$1'.self::MASK.'$2',
        // MySQL and MariaDB, unique keys: "Duplicate entry '…' for key 'user.uniq_email'"
        "/(Duplicate entry ').*(' for key)/u" => '$1'.self::MASK.'$2',
        '/[^\s<>"\'(),;:@\[\]]+@((?:[\p{L}\p{N}-]+\.)+\p{L}{2,})/u' => self::MASK.'@$1',
    ];

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

    /**
     * Masked before it is cut, so that a cut never leaves part of an address unmasked.
     */
    private static function bound(string $text): string
    {
        $text = preg_replace(array_keys(self::MASKS), array_values(self::MASKS), $text) ?? $text;

        return mb_strlen($text) > self::MAX_TEXT_LENGTH
            ? mb_substr($text, 0, self::MAX_TEXT_LENGTH).'…'
            : $text;
    }
}
