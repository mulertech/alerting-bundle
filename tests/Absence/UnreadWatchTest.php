<?php

declare(strict_types=1);

namespace MulerTech\AlertingBundle\Tests\Absence;

use MulerTech\AlertingBundle\Absence\UnreadWatch;
use MulerTech\AlertingBundle\Tests\Double\AlertingKit;
use MulerTech\AlertingBundle\Tests\Double\ReadReceiptChannel;
use MulerTech\AlertingBundle\Tests\Double\RecordingChannel;
use PHPUnit\Framework\TestCase;

final class UnreadWatchTest extends TestCase
{
    public function testEverythingReadIsNoAlert(): void
    {
        [$kit, $messaging, $mail] = $this->kit(null);

        $this->watch($kit, $messaging)->check();

        self::assertSame([], $mail->deliveries);
    }

    public function testAnAlertUnreadWithinTheDelayIsNoAlert(): void
    {
        [$kit, $messaging, $mail] = $this->kit(new \DateTimeImmutable('2026-11-16 09:00:01'));

        $this->watch($kit, $messaging)->check();

        self::assertSame([], $mail->deliveries);
    }

    public function testAnAlertUnreadPastTheDelayIsRemindedThroughTheNextChannel(): void
    {
        [$kit, $messaging, $mail] = $this->kit(new \DateTimeImmutable('2026-11-16 09:00:00+00:00'));

        $this->watch($kit, $messaging)->check();

        self::assertSame([], $messaging->deliveries, 'The channel nobody reads does not get the reminder.');
        self::assertCount(1, $mail->deliveries);
        self::assertSame(
            'Messages unread in "messaging" since 2026-11-16 10:00, over 1 h: read them to stop this reminder, repeated every 1 h.',
            $mail->deliveries[0]->alert->summary,
        );
        self::assertSame(
            ['channel' => 'messaging', 'unread_since' => '2026-11-16T09:00:00+00:00', 'next_reminder_not_before' => $kit->clock->now()->modify('+3600 seconds')->format(\DATE_ATOM)],
            $mail->deliveries[0]->alert->context,
        );
        self::assertSame('unread alerts of "messaging"', $this->watch($kit, $messaging)->name());
    }

    public function testAReminderIsRepeatedEveryDelayRatherThanEveryWindow(): void
    {
        [$kit, $messaging, $mail] = $this->kit(new \DateTimeImmutable('2026-11-16 09:00:00+00:00'));
        $watch = $this->watch($kit, $messaging);

        $watch->check();
        $kit->clock->sleep(3599);
        $watch->check();

        self::assertCount(1, $mail->deliveries, 'Past a window, but not past the delay: no second reminder.');

        $kit->clock->sleep(1);
        $watch->check();

        self::assertCount(2, $mail->deliveries);
    }

    public function testTheUnreadTimeIsWrittenInTheDeclaredTimezone(): void
    {
        [$kit, $messaging, $mail] = $this->kit(new \DateTimeImmutable('2026-07-16 09:00:00+00:00'));

        $this->watch($kit, $messaging)->check();

        self::assertStringContainsString('since 2026-07-16 11:00,', $mail->deliveries[0]->alert->summary, 'Summer time in Paris is two hours ahead of UTC.');
        self::assertSame('2026-07-16T09:00:00+00:00', $mail->deliveries[0]->alert->context['unread_since'], 'The context keeps the instant with its offset.');
    }

    public function testTheDelayIsSpelledInHoursMinutesAndSeconds(): void
    {
        [$kit, $messaging, $mail] = $this->kit(new \DateTimeImmutable('2026-11-16 06:00:00+00:00'));

        (new UnreadWatch($kit->deferred, $kit->ledger, $kit->clock, 'messaging', $messaging, 5430, new \DateTimeZone('Europe/Paris')))->check();

        self::assertStringContainsString('over 1 h 30 min 30 s:', $mail->deliveries[0]->alert->summary);
    }

    /**
     * @return array{AlertingKit, ReadReceiptChannel, RecordingChannel}
     */
    private function kit(?\DateTimeImmutable $oldestUnread): array
    {
        $messaging = new ReadReceiptChannel($oldestUnread);
        $mail = new RecordingChannel();

        return [new AlertingKit(['messaging' => $messaging, 'mail' => $mail]), $messaging, $mail];
    }

    private function watch(AlertingKit $kit, ReadReceiptChannel $channel): UnreadWatch
    {
        return new UnreadWatch($kit->deferred, $kit->ledger, $kit->clock, 'messaging', $channel, 3600, new \DateTimeZone('Europe/Paris'));
    }
}
