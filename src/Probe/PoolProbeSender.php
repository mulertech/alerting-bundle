<?php

declare(strict_types=1);

namespace MulerTech\AlertingBundle\Probe;

use MulerTech\AlertingBundle\Absence\Ledger;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\TransportNamesStamp;

/**
 * Proves that the worker reads the pool the web writes, by writing a token and asking the worker,
 * through the watched transport, whether it finds it.
 */
final readonly class PoolProbeSender
{
    public function __construct(
        private Ledger $ledger,
        private MessageBusInterface $bus,
        private ClockInterface $clock,
        public string $transport,
        public int $wait,
    ) {
    }

    /**
     * Whether the worker answered within the wait.
     */
    public function probe(): bool
    {
        $token = bin2hex(random_bytes(16));
        $this->ledger->write(PoolProbeHandler::TOKEN, $token);
        $this->bus->dispatch(new PoolProbe($token), [new TransportNamesStamp([$this->transport])]);

        for ($waited = 0; $waited < $this->wait; ++$waited) {
            if ($token === $this->ledger->read(PoolProbeHandler::REPLY)) {
                return true;
            }

            $this->clock->sleep(1);
        }

        return $token === $this->ledger->read(PoolProbeHandler::REPLY);
    }
}
