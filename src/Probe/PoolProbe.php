<?php

declare(strict_types=1);

namespace MulerTech\AlertingBundle\Probe;

/**
 * Sent to the worker by `mulertech:alerting:test`, carrying the token the command wrote in its pool.
 */
final readonly class PoolProbe
{
    public function __construct(
        public string $token,
    ) {
    }
}
