<?php

declare(strict_types=1);

namespace MulerTech\AlertingBundle\Source;

use MulerTech\AlertingBundle\Alert\Severity;
use MulerTech\AlertingBundle\Alerter;
use Symfony\Component\Console\Exception\RunCommandFailedException;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\Stamp\RedeliveryStamp;

/**
 * Reports a message once it is abandoned, never while it will be retried.
 *
 * The exception is claimed above Symfony's retry listener (priority 100), which logs the abandon at
 * `critical`, and reported below it, once that listener has decided whether the message is retried.
 *
 * A scheduled command that failed is left to the console source, which saw the command fail.
 */
final readonly class MessengerFailureSubscriber implements EventSubscriberInterface
{
    public const string OWNER = 'messenger';

    public function __construct(
        private Alerter $alerter,
        private ThrowableClaims $claims,
        private ThrowableAlertFactory $factory,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [WorkerMessageFailedEvent::class => [['claim', 101], ['report', 99]]];
    }

    public function claim(WorkerMessageFailedEvent $event): void
    {
        $this->claims->claim($event->getThrowable(), self::OWNER);
    }

    public function report(WorkerMessageFailedEvent $event): void
    {
        $throwable = $event->getThrowable();

        if ($event->willRetry() || self::OWNER !== $this->claims->ownerOf($throwable)) {
            return;
        }

        $cause = $throwable instanceof HandlerFailedException
            ? ($throwable->getWrappedExceptions()[0] ?? $throwable)
            : $throwable;

        if ($cause instanceof RunCommandFailedException) {
            return;
        }

        $envelope = $event->getEnvelope();
        $message = $envelope->getMessage()::class;
        $retries = RedeliveryStamp::getRetryCountFromEnvelope($envelope);

        $this->alerter->alert($this->factory->create(
            'messenger',
            Severity::Error,
            \sprintf('Message %s abandoned after %d retries', $message, $retries),
            $cause,
            ['message' => $message, 'transport' => $event->getReceiverName(), 'retries' => $retries],
            [$message],
        ));
    }
}
