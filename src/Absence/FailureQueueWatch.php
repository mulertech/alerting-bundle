<?php

declare(strict_types=1);

namespace MulerTech\AlertingBundle\Absence;

use MulerTech\AlertingBundle\Alert\Alert;
use MulerTech\AlertingBundle\Alert\Severity;
use MulerTech\AlertingBundle\DeferredAlerter;
use Psr\Container\ContainerInterface;
use Symfony\Component\Messenger\Transport\Receiver\MessageCountAwareInterface;

/**
 * A failure transport holding more messages than declared: each abandoned message was reported
 * when it was abandoned, this reports that nobody deals with them.
 */
final readonly class FailureQueueWatch implements WatchInterface
{
    public function __construct(
        private ContainerInterface $receivers,
        private DeferredAlerter $alerter,
        private string $transport,
        private int $threshold,
    ) {
    }

    public function name(): string
    {
        return \sprintf('failure transport "%s"', $this->transport);
    }

    public function check(): void
    {
        $receiver = $this->receivers->get($this->transport);

        if (!$receiver instanceof MessageCountAwareInterface) {
            throw new \LogicException(\sprintf('The transport "%s" cannot count its messages: declare a failure transport whose receiver implements %s, such as Doctrine or Redis.', $this->transport, MessageCountAwareInterface::class));
        }

        $count = $receiver->getMessageCount();

        if ($count < $this->threshold) {
            return;
        }

        $this->alerter->alert(new Alert(
            Severity::Error,
            'failure-queue',
            \sprintf(
                '%d messages wait in the failure transport "%s" (threshold: %d): see messenger:failed:show, then retry or remove them.',
                $count,
                $this->transport,
                $this->threshold,
            ),
            [$this->transport],
            ['transport' => $this->transport, 'messages' => $count],
        ));
    }
}
