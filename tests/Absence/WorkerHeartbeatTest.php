<?php

declare(strict_types=1);

namespace MulerTech\AlertingBundle\Tests\Absence;

use MulerTech\AlertingBundle\Absence\Ledger;
use MulerTech\AlertingBundle\Absence\WorkerHeartbeat;
use MulerTech\AlertingBundle\Absence\WorkerHeartbeatWatch;
use MulerTech\AlertingBundle\Alert\Severity;
use MulerTech\AlertingBundle\Tests\Double\AlertingKit;
use MulerTech\AlertingBundle\Tests\Double\FailingPool;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Event\WorkerRunningEvent;
use Symfony\Component\Messenger\Event\WorkerStartedEvent;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Transport\Receiver\ReceiverInterface;
use Symfony\Component\Messenger\Worker;

final class WorkerHeartbeatTest extends TestCase
{
    public function testItListensToTheWorkerStartingAndLooping(): void
    {
        self::assertSame(
            [WorkerStartedEvent::class => 'onStarted', WorkerRunningEvent::class => 'onRunning'],
            WorkerHeartbeat::getSubscribedEvents(),
        );
    }

    public function testAStartingWorkerBeatsForEveryTransportItConsumes(): void
    {
        $kit = new AlertingKit();

        $this->heartbeat($kit)->onStarted(new WorkerStartedEvent($this->worker()));

        self::assertSame(1_794_823_200, $kit->ledger->seenAt('worker.async'));
        self::assertSame(1_794_823_200, $kit->ledger->seenAt('worker.scheduler_default'));
    }

    public function testALoopingWorkerBeatsNoMoreThanEveryTenSeconds(): void
    {
        $kit = new AlertingKit();
        $heartbeat = $this->heartbeat($kit);
        $worker = $this->worker();

        $heartbeat->onRunning(new WorkerRunningEvent($worker, true));
        $kit->clock->sleep(9);
        $heartbeat->onRunning(new WorkerRunningEvent($worker, true));
        self::assertSame(1_794_823_200, $kit->ledger->seenAt('worker.async'));

        $kit->clock->sleep(1);
        $heartbeat->onRunning(new WorkerRunningEvent($worker, true));
        self::assertSame(1_794_823_210, $kit->ledger->seenAt('worker.async'));
    }

    public function testAHeartbeatThatCannotBeRecordedIsReported(): void
    {
        $kit = new AlertingKit();
        $heartbeat = new WorkerHeartbeat(new Ledger(new FailingPool(), $kit->clock), $kit->deferred, $kit->factory, $kit->clock);

        $heartbeat->onStarted(new WorkerStartedEvent($this->worker()));

        self::assertSame('The worker could not record its heartbeat: RuntimeException: Disk full', $kit->only()->alert->summary);
    }

    public function testASilenceWithinTheAllowanceIsNoAlert(): void
    {
        $kit = new AlertingKit();
        $kit->ledger->record('worker.async');
        $kit->clock->sleep(300);

        $this->watch($kit)->check();

        self::assertSame([], $kit->deliveries());
    }

    public function testASilenceBeyondTheAllowanceNamesBothCauses(): void
    {
        $kit = new AlertingKit();
        $kit->ledger->record('worker.async');
        $kit->clock->sleep(301);

        $this->watch($kit)->check();

        $alert = $kit->only()->alert;
        self::assertSame(Severity::Critical, $alert->severity);
        self::assertSame(
            'No worker has consumed the transport "async" for 301 seconds (allowed: 300): the worker is stopped, or the cache pool "cache.alerting" is not shared between the web processes and the worker. Run mulertech:alerting:test to tell them apart.',
            $alert->summary,
        );
    }

    public function testAPoolWithNoHistoryStartsTheCountRatherThanAlerting(): void
    {
        $kit = new AlertingKit();
        $watch = $this->watch($kit);

        $watch->check();
        $kit->clock->sleep(301);
        $watch->check();

        self::assertCount(1, $kit->deliveries());
    }

    public function testTheWatchIsNamedAfterItsTransport(): void
    {
        self::assertSame('worker heartbeat of "async"', $this->watch(new AlertingKit())->name());
    }

    private function heartbeat(AlertingKit $kit): WorkerHeartbeat
    {
        return new WorkerHeartbeat($kit->ledger, $kit->deferred, $kit->factory, $kit->clock);
    }

    private function watch(AlertingKit $kit): WorkerHeartbeatWatch
    {
        return new WorkerHeartbeatWatch($kit->ledger, $kit->deferred, $kit->clock, 'async', 300, 'cache.alerting');
    }

    private function worker(): Worker
    {
        return new Worker(
            ['async' => $this->createStub(ReceiverInterface::class), 'scheduler_default' => $this->createStub(ReceiverInterface::class)],
            $this->createStub(MessageBusInterface::class),
        );
    }
}
