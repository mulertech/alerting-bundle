<?php

declare(strict_types=1);

namespace MulerTech\AlertingBundle\Absence;

use MulerTech\AlertingBundle\Alert\Alert;
use MulerTech\AlertingBundle\Alert\Severity;
use MulerTech\AlertingBundle\Channel\ReadReceiptInterface;
use MulerTech\AlertingBundle\DeferredAlerter;
use Psr\Clock\ClockInterface;

/**
 * Alerts accepted by a channel that knows whether they were read, and left unread past the declared
 * delay: the reminder goes through the channels after that one, again every window while they stay
 * unread.
 */
final readonly class UnreadWatch implements WatchInterface
{
    public function __construct(
        private DeferredAlerter $alerter,
        private ClockInterface $clock,
        private string $channelName,
        private ReadReceiptInterface $channel,
        private int $delay,
    ) {
    }

    public function name(): string
    {
        return \sprintf('unread alerts of "%s"', $this->channelName);
    }

    public function check(): void
    {
        $since = $this->channel->oldestUnreadSince();

        if (null === $since || $this->clock->now()->getTimestamp() - $since->getTimestamp() < $this->delay) {
            return;
        }

        $this->alerter->alert(new Alert(
            Severity::Error,
            'unread',
            \sprintf('Alerts sent through the channel "%s" have been waiting unread since %s.', $this->channelName, $since->format(\DATE_ATOM)),
            [$this->channelName],
            ['channel' => $this->channelName, 'unread_since' => $since->format(\DATE_ATOM)],
        ), after: $this->channelName);
    }
}
