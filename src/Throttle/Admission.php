<?php

declare(strict_types=1);

namespace MulerTech\AlertingBundle\Throttle;

final readonly class Admission
{
    /**
     * @param int          $suppressed occurrences of the same fault held back since the last one sent
     * @param list<string> $incidents  what failed in the throttle itself
     */
    public function __construct(
        public Verdict $verdict,
        public int $suppressed = 0,
        public array $incidents = [],
    ) {
    }
}
