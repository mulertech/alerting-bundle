<?php

declare(strict_types=1);

namespace MulerTech\AlertingBundle\Probe;

use MulerTech\AlertingBundle\Absence\Ledger;
use MulerTech\AlertingBundle\Alert\Alert;
use MulerTech\AlertingBundle\Alert\Severity;
use MulerTech\AlertingBundle\Alerter;

/**
 * Runs in the worker. A token missing from its pool proves that the pool is not the one the
 * command wrote to: the worker says so itself, since the command cannot read the worker's pool.
 * A token found is answered through the pool, where the command waits for it.
 */
final readonly class PoolProbeHandler
{
    public const string TOKEN = 'probe.token';

    public const string REPLY = 'probe.reply';

    public function __construct(
        private Ledger $ledger,
        private Alerter $alerter,
        private string $pool,
    ) {
    }

    public function __invoke(PoolProbe $probe): void
    {
        if ($probe->token === $this->ledger->read(self::TOKEN)) {
            $this->ledger->write(self::REPLY, $probe->token);

            return;
        }

        $this->alerter->alert(new Alert(
            Severity::Critical,
            'worker',
            \sprintf(
                'The cache pool "%s" is not shared between the web processes and the worker: the worker does not find the token mulertech:alerting:test wrote to it. The worker heartbeat cannot be checked, and each process deduplicates alone. Accepted pools: Redis, Memcached, or a filesystem pool in a directory mounted in every container.',
                $this->pool,
            ),
            ['probe'],
            ['pool' => $this->pool],
        ));
    }
}
