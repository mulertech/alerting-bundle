<?php

declare(strict_types=1);

namespace MulerTech\AlertingBundle\Absence;

use MulerTech\AlertingBundle\Alert\Severity;
use MulerTech\AlertingBundle\DeferredAlerter;
use MulerTech\AlertingBundle\Source\ThrowableAlertFactory;
use Psr\Clock\ClockInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Messenger\Event\WorkerRunningEvent;
use Symfony\Component\Messenger\Event\WorkerStartedEvent;

/**
 * Records, for each transport a worker consumes, that a worker is consuming it.
 *
 * A worker loops about once a second when idle, and not at all while it handles a message: the
 * declared silence of a heartbeat must exceed the longest message.
 */
final class WorkerHeartbeat implements EventSubscriberInterface
{
    /**
     * Seconds between two records of the same process: a write every loop would cost a cache write
     * a second for nothing a silence measured in minutes can tell.
     */
    private const int INTERVAL = 10;

    /**
     * When this process last recorded its heartbeat: the one state it keeps from one message to the
     * next, on purpose, since it paces the process.
     */
    private int $recordedAt = 0;

    public function __construct(
        private readonly Ledger $ledger,
        private readonly DeferredAlerter $alerter,
        private readonly ThrowableAlertFactory $factory,
        private readonly ClockInterface $clock,
    ) {
    }

    public static function subject(string $transport): string
    {
        return 'worker.'.$transport;
    }

    public static function getSubscribedEvents(): array
    {
        return [
            WorkerStartedEvent::class => 'onStarted',
            WorkerRunningEvent::class => 'onRunning',
        ];
    }

    public function onStarted(WorkerStartedEvent $event): void
    {
        $this->beat($event->getWorker()->getMetadata()->getTransportNames());
    }

    public function onRunning(WorkerRunningEvent $event): void
    {
        if ($this->clock->now()->getTimestamp() - $this->recordedAt < self::INTERVAL) {
            return;
        }

        $this->beat($event->getWorker()->getMetadata()->getTransportNames());
    }

    /**
     * @param array<string> $transports
     */
    private function beat(array $transports): void
    {
        $this->recordedAt = $this->clock->now()->getTimestamp();

        try {
            foreach ($transports as $transport) {
                $this->ledger->record(self::subject($transport));
            }
        } catch (\Throwable $failure) {
            $this->alerter->alert($this->factory->create(
                'worker',
                Severity::Error,
                'The worker could not record its heartbeat',
                $failure,
            ));
        }
    }
}
