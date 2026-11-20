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
 * delay: the reminder goes through the channels after that one, and again every delay while they
 * stay unread. Every window would be too often: a reminder reaches a person, often by mail, and one
 * every fifteen minutes through a night buries the mailbox it is meant to wake.
 *
 * The time of the last reminder is kept in the ledger, shared by every process.
 */
final readonly class UnreadWatch implements WatchInterface
{
    public function __construct(
        private DeferredAlerter $alerter,
        private Ledger $ledger,
        private ClockInterface $clock,
        private string $channelName,
        private ReadReceiptInterface $channel,
        private int $delay,
        private \DateTimeZone $timezone,
    ) {
    }

    public function name(): string
    {
        return \sprintf('unread alerts of "%s"', $this->channelName);
    }

    public function check(): void
    {
        $since = $this->channel->oldestUnreadSince();
        $now = $this->clock->now()->getTimestamp();

        if (null === $since || $now - $since->getTimestamp() < $this->delay) {
            return;
        }

        $subject = 'unread reminder '.$this->channelName;
        $remindedAt = $this->ledger->seenAt($subject);

        if (null !== $remindedAt && $now - $remindedAt < $this->delay) {
            return;
        }

        $delay = self::duration($this->delay);

        // The summary is also the subject of a mail: what is unread, where, since when, and the
        // action that stops the reminders come first, before a subject cut at 150 characters.
        $this->alerter->alert(new Alert(
            Severity::Error,
            'unread',
            \sprintf('Messages unread in "%s" since %s, over %s: read them to stop this reminder, repeated every %s.', $this->channelName, $since->setTimezone($this->timezone)->format('Y-m-d H:i'), $delay, $delay),
            [$this->channelName],
            [
                'channel' => $this->channelName,
                'unread_since' => $since->format(\DATE_ATOM),
                'next_reminder_not_before' => $this->clock->now()->modify(\sprintf('+%d seconds', $this->delay))->format(\DATE_ATOM),
            ],
        ), after: $this->channelName);

        // After the reminder: a pool that refuses the record must not cost the reminder itself.
        $this->ledger->record($subject);
    }

    private static function duration(int $seconds): string
    {
        $parts = array_filter([
            'h' => intdiv($seconds, 3600),
            'min' => intdiv($seconds % 3600, 60),
            's' => $seconds % 60,
        ]);

        return implode(' ', array_map(static fn (string $unit, int $value): string => $value.' '.$unit, array_keys($parts), $parts));
    }
}
