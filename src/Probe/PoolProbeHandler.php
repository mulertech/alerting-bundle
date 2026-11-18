<?php

declare(strict_types=1);

namespace MulerTech\AlertingBundle\Probe;

use MulerTech\AlertingBundle\Absence\Ledger;
use MulerTech\AlertingBundle\Alert\Alert;
use MulerTech\AlertingBundle\Alert\Severity;
use MulerTech\AlertingBundle\DeferredAlerter;

/**
 * Runs in the worker. A token missing from its pool proves that the pool is not the one the
 * command wrote to: the worker says so itself, since the command cannot read the worker's pool.
 * A token found is answered through the pool, where the command waits for it.
 *
 * Each token keeps an entry of its own: a probe the worker handles late, after a later run of the
 * command, still finds its token, and is no proof of a pool apart.
 */
final readonly class PoolProbeHandler
{
    public const string SENT = 'sent';

    public const string ANSWERED = 'answered';

    public function __construct(
        private Ledger $ledger,
        private DeferredAlerter $alerter,
        private string $pool,
    ) {
    }

    public function __invoke(PoolProbe $probe): void
    {
        if (null !== $this->ledger->read(self::subject($probe->token))) {
            $this->ledger->write(self::subject($probe->token), self::ANSWERED);

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

    public static function subject(string $token): string
    {
        return 'probe.'.$token;
    }
}
