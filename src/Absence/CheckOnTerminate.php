<?php

declare(strict_types=1);

namespace MulerTech\AlertingBundle\Absence;

use MulerTech\AlertingBundle\Alert\Severity;
use MulerTech\AlertingBundle\DeferredAlerter;
use MulerTech\AlertingBundle\Source\ThrowableAlertFactory;
use Psr\Clock\ClockInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Runs the check after a response was sent, at most once per declared interval across every
 * process sharing the pool. With no visit nothing is checked: `mulertech:alerting:check`, scheduled
 * outside the application, covers the nights.
 */
final readonly class CheckOnTerminate implements EventSubscriberInterface
{
    private const string SUBJECT = 'check';

    public function __construct(
        private AbsenceCheck $check,
        private Ledger $ledger,
        private DeferredAlerter $alerter,
        private ThrowableAlertFactory $factory,
        private ClockInterface $clock,
        private int $interval,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::TERMINATE => 'onTerminate'];
    }

    public function onTerminate(TerminateEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        try {
            $checkedAt = $this->ledger->seenAt(self::SUBJECT);

            if (null !== $checkedAt && $this->clock->now()->getTimestamp() - $checkedAt < $this->interval) {
                return;
            }

            $this->ledger->record(self::SUBJECT);
        } catch (\Throwable $failure) {
            $this->alerter->alert($this->factory->create('check', Severity::Error, 'The absence check could not be paced', $failure));

            return;
        }

        $this->check->run();
    }
}
