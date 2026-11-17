<?php

declare(strict_types=1);

namespace MulerTech\AlertingBundle\Absence;

use MulerTech\AlertingBundle\Alert\Alert;
use MulerTech\AlertingBundle\Alert\Severity;
use MulerTech\AlertingBundle\Alerter;
use Psr\Clock\ClockInterface;

/**
 * A transport no worker has consumed for longer than its declared silence.
 *
 * The process that checks cannot tell a stopped worker from a pool it does not share with the
 * worker, so the alert names both; `mulertech:alerting:test` tells them apart.
 */
final readonly class WorkerHeartbeatWatch implements WatchInterface
{
    public function __construct(
        private Ledger $ledger,
        private Alerter $alerter,
        private ClockInterface $clock,
        private string $transport,
        private int $silence,
        private string $pool,
    ) {
    }

    public function name(): string
    {
        return \sprintf('worker heartbeat of "%s"', $this->transport);
    }

    public function check(): void
    {
        $silent = $this->clock->now()->getTimestamp() - $this->ledger->lastSeen(WorkerHeartbeat::subject($this->transport));

        if ($silent <= $this->silence) {
            return;
        }

        $this->alerter->alert(new Alert(
            Severity::Critical,
            'worker',
            \sprintf(
                'No worker has consumed the transport "%s" for %d seconds (allowed: %d): the worker is stopped, or the cache pool "%s" is not shared between the web processes and the worker. Run mulertech:alerting:test to tell them apart.',
                $this->transport,
                $silent,
                $this->silence,
                $this->pool,
            ),
            [$this->transport],
            ['transport' => $this->transport, 'silent_for' => $silent],
        ));
    }
}
