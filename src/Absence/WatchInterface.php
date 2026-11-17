<?php

declare(strict_types=1);

namespace MulerTech\AlertingBundle\Absence;

/**
 * Something that malfunctions by not happening, which no event reports.
 *
 * @internal
 */
interface WatchInterface
{
    public function name(): string;

    /**
     * Raises an alert when what is watched is missing.
     */
    public function check(): void;
}
