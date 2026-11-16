<?php

declare(strict_types=1);

namespace MulerTech\AlertingBundle\Alert;

/**
 * An alert as a channel sends it: which application raised it, how many occurrences of the same
 * fault were held back since the last one sent, and what went wrong in the alerting itself on the
 * way (a channel that refused, a cache pool that failed). Those incidents travel with the alert,
 * so that the channel that finally delivers it also says why the previous ones did not.
 */
final readonly class Delivery
{
    /**
     * @param list<string> $incidents
     */
    public function __construct(
        public string $application,
        public Alert $alert,
        public \DateTimeImmutable $raisedAt,
        public int $suppressed = 0,
        public array $incidents = [],
    ) {
    }

    public function withIncident(string $incident): self
    {
        return new self($this->application, $this->alert, $this->raisedAt, $this->suppressed, [...$this->incidents, $incident]);
    }
}
