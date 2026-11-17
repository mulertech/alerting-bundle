<?php

declare(strict_types=1);

namespace MulerTech\AlertingBundle\Tests\Channel;

use MulerTech\AlertingBundle\Alert\Alert;
use MulerTech\AlertingBundle\Alert\Delivery;
use MulerTech\AlertingBundle\Alert\Severity;
use MulerTech\AlertingBundle\Channel\ChannelChain;
use MulerTech\AlertingBundle\Tests\Double\RecordingChannel;
use MulerTech\AlertingBundle\Tests\Double\RefusingChannel;
use MulerTech\AlertingBundle\Tests\Double\StandardErrorFile;
use PHPUnit\Framework\TestCase;

final class ChannelChainTest extends TestCase
{
    public function testTheFirstChannelThatAcceptsEndsTheChain(): void
    {
        $standardError = new StandardErrorFile();
        $first = new RecordingChannel();
        $second = new RecordingChannel();

        new ChannelChain(['messaging' => $first, 'mail' => $second], $standardError->channel())->send($this->delivery());

        self::assertCount(1, $first->deliveries);
        self::assertSame([], $second->deliveries);
        self::assertSame([], $standardError->lines());
    }

    public function testARefusalTravelsWithTheAlertToTheNextChannel(): void
    {
        $standardError = new StandardErrorFile();
        $mail = new RecordingChannel();

        new ChannelChain(
            ['messaging' => new RefusingChannel('Database unreachable'), 'mail' => $mail],
            $standardError->channel(),
        )->send($this->delivery());

        self::assertCount(1, $mail->deliveries);
        self::assertSame(
            ['Channel "messaging" refused the alert: RuntimeException: Database unreachable'],
            $mail->deliveries[0]->incidents,
        );
        self::assertSame([], $standardError->lines());
    }

    public function testStandardErrorReceivesWhatNoChannelAccepted(): void
    {
        $standardError = new StandardErrorFile();

        new ChannelChain(
            ['messaging' => new RefusingChannel('Database unreachable'), 'mail' => new RefusingChannel('Authentication failed')],
            $standardError->channel(),
        )->send($this->delivery());

        $lines = $standardError->lines();
        self::assertCount(1, $lines);
        self::assertSame([
            'Channel "messaging" refused the alert: RuntimeException: Database unreachable',
            'Channel "mail" refused the alert: RuntimeException: Authentication failed',
        ], $lines[0]['incidents']);
    }

    public function testWithNoChannelTheAlertStillReachesStandardError(): void
    {
        $standardError = new StandardErrorFile();

        new ChannelChain([], $standardError->channel())->send($this->delivery());

        self::assertCount(1, $standardError->lines());
    }

    public function testAReminderSkipsTheChannelNamedAndThoseBeforeIt(): void
    {
        $standardError = new StandardErrorFile();
        $chat = new RecordingChannel();
        $messaging = new RecordingChannel();
        $mail = new RecordingChannel();

        new ChannelChain(['chat' => $chat, 'messaging' => $messaging, 'mail' => $mail], $standardError->channel())->send($this->delivery(), after: 'messaging');

        self::assertSame([], $chat->deliveries);
        self::assertSame([], $messaging->deliveries);
        self::assertCount(1, $mail->deliveries);
    }

    public function testAReminderAfterTheLastChannelReachesStandardError(): void
    {
        $standardError = new StandardErrorFile();
        $mail = new RecordingChannel();

        new ChannelChain(['mail' => $mail], $standardError->channel())->send($this->delivery(), after: 'mail');

        self::assertSame([], $mail->deliveries);
        self::assertCount(1, $standardError->lines());
    }

    private function delivery(): Delivery
    {
        return new Delivery('shop-prod', new Alert(Severity::Error, 'http', 'Failure', []), new \DateTimeImmutable());
    }
}
