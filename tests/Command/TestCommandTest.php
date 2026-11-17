<?php

declare(strict_types=1);

namespace MulerTech\AlertingBundle\Tests\Command;

use MulerTech\AlertingBundle\Channel\ChannelInterface;
use MulerTech\AlertingBundle\Command\TestCommand;
use MulerTech\AlertingBundle\Probe\PoolProbe;
use MulerTech\AlertingBundle\Probe\PoolProbeHandler;
use MulerTech\AlertingBundle\Probe\PoolProbeSender;
use MulerTech\AlertingBundle\Tests\Double\AlertingKit;
use MulerTech\AlertingBundle\Tests\Double\RecordingChannel;
use MulerTech\AlertingBundle\Tests\Double\RefusingChannel;
use MulerTech\AlertingBundle\Tests\Double\StandardErrorFile;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

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

    public function testAWorkerSharingThePoolPassesTheProbe(): void
    {
        $kit = new AlertingKit();
        $probe = new PoolProbeSender($kit->ledger, $this->bus(new PoolProbeHandler($kit->ledger, $kit->alerter, 'cache.alerting')), $kit->clock, 'async', 5);

        $tester = $this->tester(['mail' => new RecordingChannel()], new StandardErrorFile(), $probe);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('✔ the worker of "async" shares the cache pool of the alerting', $tester->getDisplay());
    }

    public function testAProbeWithoutAnswerFailsTheCommandAndSaysWhatItMeans(): void
    {
        $kit = new AlertingKit();
        $probe = new PoolProbeSender($kit->ledger, $this->bus(null), $kit->clock, 'async', 5);

        $tester = $this->tester(['mail' => new RecordingChannel()], new StandardErrorFile(), $probe);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('✘ pool probe: no answer from the worker of "async" within 5 seconds.', $tester->getDisplay());
        self::assertStringContainsString('Refused the test alert: pool probe.', $tester->getDisplay());
    }

    public function testChosenChannelsAloneReceiveATestAlert(): void
    {
        $messaging = new RecordingChannel();
        $mail = new RecordingChannel();
        $standardError = new StandardErrorFile();
        $kit = new AlertingKit();
        $probe = new PoolProbeSender($kit->ledger, $this->bus(null), $kit->clock, 'async', 5);

        $tester = $this->tester(['messaging' => $messaging, 'mail' => $mail], $standardError, $probe, ['--channel' => ['mail']]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode(), $tester->getDisplay());
        self::assertCount(0, $messaging->deliveries);
        self::assertCount(1, $mail->deliveries);
        self::assertSame([], $standardError->lines());
        self::assertStringNotContainsString('pool probe', $tester->getDisplay());
    }

    public function testAnUnknownChannelIsRefusedWithTheDeclaredOnes(): void
    {
        $mail = new RecordingChannel();

        $tester = $this->tester(['messaging' => new RecordingChannel(), 'mail' => $mail], new StandardErrorFile(), input: ['--channel' => ['mail', 'sms']]);

        self::assertSame(Command::INVALID, $tester->getStatusCode());
        self::assertCount(0, $mail->deliveries);
        self::assertStringContainsString('Unknown channel: sms. Declared: messaging, mail.', $tester->getDisplay());
    }

    public function testTheProbeAloneSendsNoTestAlert(): void
    {
        $mail = new RecordingChannel();
        $standardError = new StandardErrorFile();
        $kit = new AlertingKit();
        $probe = new PoolProbeSender($kit->ledger, $this->bus(new PoolProbeHandler($kit->ledger, $kit->alerter, 'cache.alerting')), $kit->clock, 'async', 5);

        $tester = $this->tester(['mail' => $mail], $standardError, $probe, ['--probe-only' => true]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode(), $tester->getDisplay());
        self::assertCount(0, $mail->deliveries);
        self::assertSame([], $standardError->lines());
        self::assertStringContainsString('The worker shares the cache pool of the alerting.', $tester->getDisplay());
    }

    public function testTheProbeAloneNeedsTheWorkerWatched(): void
    {
        $tester = $this->tester(['mail' => new RecordingChannel()], new StandardErrorFile(), input: ['--probe-only' => true]);

        self::assertSame(Command::INVALID, $tester->getStatusCode());
        self::assertStringContainsString('--probe-only needs the worker to be watched', $tester->getDisplay());
    }

    public function testTheProbeAloneAndChosenChannelsExcludeEachOther(): void
    {
        $mail = new RecordingChannel();

        $tester = $this->tester(['mail' => $mail], new StandardErrorFile(), input: ['--probe-only' => true, '--channel' => ['mail']]);

        self::assertSame(Command::INVALID, $tester->getStatusCode());
        self::assertCount(0, $mail->deliveries);
    }

    private function bus(?PoolProbeHandler $worker): MessageBusInterface
    {
        return new class($worker) implements MessageBusInterface {
            public function __construct(private readonly ?PoolProbeHandler $worker)
            {
            }

            public function dispatch(object $message, array $stamps = []): Envelope
            {
                if (null !== $this->worker && $message instanceof PoolProbe) {
                    ($this->worker)($message);
                }

                return new Envelope($message, $stamps);
            }
        };
    }

    /**
     * @param array<string, ChannelInterface> $channels
     * @param array<string, mixed>            $input
     */
    private function tester(array $channels, StandardErrorFile $standardError, ?PoolProbeSender $probe = null, array $input = []): CommandTester
    {
        $tester = new CommandTester(new TestCommand('shop-prod', $channels, $standardError->channel(), new MockClock(), $probe));
        $tester->execute($input);

        return $tester;
    }
}
