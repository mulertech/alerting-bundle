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
            'Alerts sent through the channel "messaging" have been waiting unread since 2026-11-16T09:00:00+00:00.',
            $mail->deliveries[0]->alert->summary,
        );
        self::assertSame('unread alerts of "messaging"', $this->watch($kit, $messaging)->name());
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
        return new UnreadWatch($kit->deferred, $kit->clock, 'messaging', $channel, 3600);
    }
}
