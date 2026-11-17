<?php

declare(strict_types=1);

namespace MulerTech\AlertingBundle\Absence;

use MulerTech\AlertingBundle\Alert\Alert;
use MulerTech\AlertingBundle\Alert\Severity;
use MulerTech\AlertingBundle\Alerter;
use Psr\Clock\ClockInterface;

/**
 * A scheduled task that has not completed within its declared delay. A run that failed is reported
 * as it happens; this one reports the runs that never came.
 */
final readonly class ScheduledTaskWatch implements WatchInterface
{
    public function __construct(
        private Ledger $ledger,
        private Alerter $alerter,
        private ClockInterface $clock,
        private string $task,
        private int $delay,
    ) {
    }

    public function name(): string
    {
        return \sprintf('scheduled task "%s"', $this->task);
    }

    public function check(): void
    {
        $late = $this->clock->now()->getTimestamp() - $this->ledger->lastSeen(ScheduledTaskRecorder::subject($this->task));

        if ($late <= $this->delay) {
            return;
        }

        $this->alerter->alert(new Alert(
            Severity::Error,
            'schedule',
            \sprintf(
                'The scheduled task "%s" has not completed for %d seconds (allowed: %d): it fails, it was removed from the schedule, or no worker consumes the schedule transport.',
                $this->task,
                $late,
                $this->delay,
            ),
            [$this->task],
            ['task' => $this->task, 'late_by' => $late - $this->delay],
        ));
    }
}
