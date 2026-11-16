<?php

declare(strict_types=1);

namespace MulerTech\AlertingBundle\Tests\Double;

use MulerTech\AlertingBundle\Channel\StandardErrorChannel;

/**
 * A file standing in for standard error, read back as the decoded lines written to it.
 */
final class StandardErrorFile
{
    public readonly string $path;

    public function __construct()
    {
        $this->path = tempnam(sys_get_temp_dir(), 'alerting_');
    }

    public function __destruct()
    {
        @unlink($this->path);
    }

    public function channel(): StandardErrorChannel
    {
        return new StandardErrorChannel($this->path);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function lines(): array
    {
        $lines = [];

        foreach (file($this->path, \FILE_IGNORE_NEW_LINES | \FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $decoded = json_decode($line, true, flags: \JSON_THROW_ON_ERROR);
            \assert(\is_array($decoded));
            /* @var array<string, mixed> $decoded */
            $lines[] = $decoded;
        }

        return $lines;
    }
}
