<?php

declare(strict_types=1);

namespace MulerTech\AlertingBundle\Tests\Absence;

use MulerTech\AlertingBundle\Absence\Ledger;
use MulerTech\AlertingBundle\Absence\ScheduledTaskRecorder;
use MulerTech\AlertingBundle\Absence\ScheduledTaskWatch;
use MulerTech\AlertingBundle\Tests\Double\AlertingKit;
use MulerTech\AlertingBundle\Tests\Double\FailingPool;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Messenger\RunCommandMessage;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Event\WorkerMessageHandledEvent;
use Symfony\Component\Scheduler\Generator\MessageContext;
use Symfony\Component\Scheduler\Messenger\ScheduledStamp;
use Symfony\Component\Scheduler\Trigger\TriggerInterface;

final class ScheduledTaskTest extends TestCase
{
    public function testItListensToHandledMessages(): void
    {
        self::assertSame([WorkerMessageHandledEvent::class => 'onHandled'], ScheduledTaskRecorder::getSubscribedEvents());
    }

    public function testAScheduledMessageIsRecordedUnderItsClass(): void
    {
        $kit = new AlertingKit();

        $this->recorder($kit)->onHandled($this->handled(new \ArrayObject(), scheduled: true));

        self::assertSame(1_794_823_200, $kit->ledger->seenAt('schedule.ArrayObject'));
    }

    public function testAScheduledCommandIsRecordedUnderItsName(): void
    {
        $kit = new AlertingKit();

        $this->recorder($kit)->onHandled($this->handled(new RunCommandMessage('app:quote-reminders --dry-run'), scheduled: true));

        self::assertSame(1_794_823_200, $kit->ledger->seenAt('schedule.app:quote-reminders'));
    }

    public function testAMessageThatWasNotScheduledIsNotRecorded(): void
    {
        $kit = new AlertingKit();

        $this->recorder($kit)->onHandled($this->handled(new \ArrayObject(), scheduled: false));

        self::assertNull($kit->ledger->seenAt('schedule.ArrayObject'));
    }

    public function testARunThatCannotBeRecordedIsReported(): void
    {
        $kit = new AlertingKit();
        $recorder = new ScheduledTaskRecorder(new Ledger(new FailingPool(), $kit->clock), $kit->alerter, $kit->factory);

        $recorder->onHandled($this->handled(new \ArrayObject(), scheduled: true));

        self::assertSame('The run of the scheduled task "ArrayObject" could not be recorded: RuntimeException: Disk full', $kit->only()->alert->summary);
    }

    public function testATaskWithinItsDelayIsNoAlert(): void
    {
        $kit = new AlertingKit();
        $kit->ledger->record('schedule.app:quote-reminders');
        $kit->clock->sleep(90_000);

        $this->watch($kit)->check();

        self::assertSame([], $kit->deliveries());
    }

    public function testATaskPastItsDelayIsReported(): void
    {
        $kit = new AlertingKit();
        $kit->ledger->record('schedule.app:quote-reminders');
        $kit->clock->sleep(90_001);

        $this->watch($kit)->check();

        $alert = $kit->only()->alert;
        self::assertSame(
            'The scheduled task "app:quote-reminders" has not completed for 90001 seconds (allowed: 90000): it fails, it was removed from the schedule, or no worker consumes the schedule transport.',
            $alert->summary,
        );
        self::assertSame('1', $alert->context['late_by']);
        self::assertSame('scheduled task "app:quote-reminders"', $this->watch($kit)->name());
    }

    private function recorder(AlertingKit $kit): ScheduledTaskRecorder
    {
        return new ScheduledTaskRecorder($kit->ledger, $kit->alerter, $kit->factory);
    }

    private function watch(AlertingKit $kit): ScheduledTaskWatch
    {
        return new ScheduledTaskWatch($kit->ledger, $kit->alerter, $kit->clock, 'app:quote-reminders', 90_000);
    }

    private function handled(object $message, bool $scheduled): WorkerMessageHandledEvent
    {
        $stamps = $scheduled
            ? [new ScheduledStamp(new MessageContext('default', 'id', $this->createStub(TriggerInterface::class), new \DateTimeImmutable()))]
            : [];

        return new WorkerMessageHandledEvent(new Envelope($message, $stamps), 'scheduler_default');
    }
}
