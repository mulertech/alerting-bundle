<?php

declare(strict_types=1);

namespace MulerTech\AlertingBundle\Tests\Command;

use MulerTech\AlertingBundle\Channel\ChannelInterface;
use MulerTech\AlertingBundle\Command\TestCommand;
use MulerTech\AlertingBundle\Tests\Double\RecordingChannel;
use MulerTech\AlertingBundle\Tests\Double\RefusingChannel;
use MulerTech\AlertingBundle\Tests\Double\StandardErrorFile;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class TestCommandTest extends TestCase
{
    public function testEveryChannelReceivesItsOwnTestAlert(): void
    {
        $messaging = new RecordingChannel();
        $mail = new RecordingChannel();

        $tester = $this->tester(['messaging' => $messaging, 'mail' => $mail], new StandardErrorFile());

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertCount(1, $messaging->deliveries);
        self::assertCount(1, $mail->deliveries);
        self::assertSame('Test alert sent by mulertech:alerting:test through the channel "mail".', $mail->deliveries[0]->alert->summary);
        self::assertSame('shop-prod', $mail->deliveries[0]->application);
        self::assertStringContainsString('✔ messaging', $tester->getDisplay());
        self::assertStringContainsString('✔ mail', $tester->getDisplay());
    }

    public function testARefusingChannelFailsTheCommandAndIsNamed(): void
    {
        $mail = new RecordingChannel();

        $tester = $this->tester(['messaging' => new RefusingChannel('Database unreachable'), 'mail' => $mail], new StandardErrorFile());

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertCount(1, $mail->deliveries, 'A refusal does not stop the test of the next channel.');
        self::assertStringContainsString('✘ messaging: RuntimeException: Database unreachable', $tester->getDisplay());
        self::assertStringContainsString('Refused the test alert: messaging.', $tester->getDisplay());
    }

    public function testStandardErrorIsTriedToo(): void
    {
        $standardError = new StandardErrorFile();

        $tester = $this->tester(['mail' => new RecordingChannel()], $standardError);

        self::assertSame(
            ['Test alert sent by mulertech:alerting:test through the channel "standard error".'],
            array_column($standardError->lines(), 'summary'),
        );
        self::assertStringContainsString('✔ standard error', $tester->getDisplay());
    }

    /**
     * @param array<string, ChannelInterface> $channels
     */
    private function tester(array $channels, StandardErrorFile $standardError): CommandTester
    {
        $tester = new CommandTester(new TestCommand('shop-prod', $channels, $standardError->channel(), new MockClock()));
        $tester->execute([]);

        return $tester;
    }
}
