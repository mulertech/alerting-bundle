<?php

declare(strict_types=1);

namespace MulerTech\AlertingBundle\Tests\Double;

use MulerTech\AlertingBundle\Absence\WatchInterface;

final class ScriptedWatch implements WatchInterface
{
    public int $checks = 0;

    public function __construct(
        private readonly string $name,
        private readonly ?\Throwable $failure = null,
    ) {
    }

    public function name(): string
    {
        return $this->name;
    }

    public function check(): void
    {
        ++$this->checks;

        if (null !== $this->failure) {
            throw $this->failure;
        }
    }
}
