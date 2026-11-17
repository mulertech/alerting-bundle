<?php

declare(strict_types=1);

namespace MulerTech\AlertingBundle\Absence;

use MulerTech\AlertingBundle\Alert\Severity;
use MulerTech\AlertingBundle\Alerter;
use MulerTech\AlertingBundle\Source\ThrowableAlertFactory;
use Symfony\Component\Console\Messenger\RunCommandMessage;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Messenger\Event\WorkerMessageHandledEvent;
use Symfony\Component\Scheduler\Messenger\ScheduledStamp;

/**
 * Records each scheduled task the worker handled successfully, under the name the configuration
 * declares it by: the command name for a scheduled command, the message class otherwise.
 */
final readonly class ScheduledTaskRecorder implements EventSubscriberInterface
{
    public function __construct(
        private Ledger $ledger,
        private Alerter $alerter,
        private ThrowableAlertFactory $factory,
    ) {
    }

    public static function subject(string $task): string
    {
        return 'schedule.'.$task;
    }

    public static function getSubscribedEvents(): array
    {
        return [WorkerMessageHandledEvent::class => 'onHandled'];
    }

    public function onHandled(WorkerMessageHandledEvent $event): void
    {
        $envelope = $event->getEnvelope();

        if (null === $envelope->last(ScheduledStamp::class)) {
            return;
        }

        $message = $envelope->getMessage();
        $task = $message instanceof RunCommandMessage
            ? strtok($message->input, ' ') ?: $message->input
            : $message::class;

        try {
            $this->ledger->record(self::subject($task));
        } catch (\Throwable $failure) {
            $this->alerter->alert($this->factory->create(
                'schedule',
                Severity::Error,
                \sprintf('The run of the scheduled task "%s" could not be recorded', $task),
                $failure,
            ));
        }
    }
}
