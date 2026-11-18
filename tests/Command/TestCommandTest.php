<?php

declare(strict_types=1);

namespace MulerTech\AlertingBundle\Tests\Command;

use MulerTech\AlertingBundle\Tests\Double\Channels;
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
use Symfony\Component\Console\Exception\InvalidOptionException;
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

        [$tester, $failure] = $this->failing(['messaging' => new RefusingChannel('Database unreachable'), 'mail' => $mail], new StandardErrorFile());

        self::assertCount(1, $mail->deliveries, 'A refusal does not stop the test of the next channel.');
        self::assertStringContainsString('✘ messaging: RuntimeException: Database unreachable', $tester->getDisplay());
        self::assertSame('The test alert was refused. [messaging] RuntimeException: Database unreachable', $failure->getMessage());
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
        $probe = new PoolProbeSender($kit->ledger, $this->bus(new PoolProbeHandler($kit->ledger, $kit->deferred, 'cache.alerting')), $kit->clock, 'async', 5);

        $tester = $this->tester(['mail' => new RecordingChannel()], new StandardErrorFile(), $probe);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('✔ the worker of "async" shares the cache pool of the alerting', $tester->getDisplay());
    }

    public function testAProbeWithoutAnswerFailsTheCommandAndSaysWhatItMeans(): void
    {
        $kit = new AlertingKit();
        $probe = new PoolProbeSender($kit->ledger, $this->bus(null), $kit->clock, 'async', 5);

        [$tester, $failure] = $this->failing(['mail' => new RecordingChannel()], new StandardErrorFile(), $probe);

        self::assertStringContainsString('✘ pool probe: no answer from the worker of "async" within 5 seconds.', $tester->getDisplay());
        self::assertStringStartsWith('The test alert was refused. [pool probe] no answer from the worker of "async" within 5 seconds. Either the worker is stopped', $failure->getMessage());
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

        [, $failure] = $this->failing(['messaging' => new RecordingChannel(), 'mail' => $mail], new StandardErrorFile(), input: ['--channel' => ['mail', 'sms']]);

        self::assertInstanceOf(InvalidOptionException::class, $failure);
        self::assertSame('Unknown channel: sms. Declared: messaging, mail.', $failure->getMessage());
        self::assertCount(0, $mail->deliveries);
    }

    public function testTheProbeAloneSendsNoTestAlert(): void
    {
        $mail = new RecordingChannel();
        $standardError = new StandardErrorFile();
        $kit = new AlertingKit();
        $probe = new PoolProbeSender($kit->ledger, $this->bus(new PoolProbeHandler($kit->ledger, $kit->deferred, 'cache.alerting')), $kit->clock, 'async', 5);

        $tester = $this->tester(['mail' => $mail], $standardError, $probe, ['--probe-only' => true]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode(), $tester->getDisplay());
        self::assertCount(0, $mail->deliveries);
        self::assertSame([], $standardError->lines());
        self::assertStringContainsString('✔ mail is built', $tester->getDisplay());
        self::assertStringContainsString('[OK] Every channel is built, and the worker shares the cache pool', $tester->getDisplay());
    }

    /**
     * The probe runs at each deployment: a channel reading a secret the deployment did not provide
     * fails it there, rather than at the first alert.
     */
    public function testTheProbeAloneNamesAChannelThatCannotBeBuilt(): void
    {
        $kit = new AlertingKit();
        $probe = new PoolProbeSender($kit->ledger, $this->bus(new PoolProbeHandler($kit->ledger, $kit->deferred, 'cache.alerting')), $kit->clock, 'async', 5);
        $channels = ['mail' => static fn (): ChannelInterface => throw new \RuntimeException('Environment variable not found: "MAILER_DSN_FILE".')];

        $tester = new CommandTester(new TestCommand('shop-prod', $channels, new StandardErrorFile()->channel(), new MockClock(), $probe));

        try {
            $tester->execute(['--probe-only' => true]);
            self::fail('A channel that cannot be built passed the deployment check.');
        } catch (\RuntimeException $failure) {
            self::assertSame('The deployment check failed. [mail] cannot be built: RuntimeException: Environment variable not found: "MAILER_DSN_FILE".', $failure->getMessage());
        }

        self::assertStringContainsString('✘ mail cannot be built: RuntimeException: Environment variable not found: "MAILER_DSN_FILE".', $tester->getDisplay());
    }

    public function testTheProbeAloneNeedsTheWorkerWatched(): void
    {
        [, $failure] = $this->failing(['mail' => new RecordingChannel()], new StandardErrorFile(), input: ['--probe-only' => true]);

        self::assertInstanceOf(InvalidOptionException::class, $failure);
        self::assertSame('--probe-only needs the worker to be watched: declare mulertech_alerting.absence.worker.', $failure->getMessage());
    }

    public function testTheProbeAloneAndChosenChannelsExcludeEachOther(): void
    {
        $mail = new RecordingChannel();

        [, $failure] = $this->failing(['mail' => $mail], new StandardErrorFile(), input: ['--probe-only' => true, '--channel' => ['mail']]);

        self::assertInstanceOf(InvalidOptionException::class, $failure);
        self::assertCount(0, $mail->deliveries);
    }

    /**
     * Runs the command expected to fail, and returns what it printed with what it threw.
     *
     * @param array<string, ChannelInterface> $channels
     * @param array<string, mixed>            $input
     *
     * @return array{CommandTester, \Throwable}
     */
    private function failing(array $channels, StandardErrorFile $standardError, ?PoolProbeSender $probe = null, array $input = []): array
    {
        $tester = new CommandTester(new TestCommand('shop-prod', Channels::built($channels), $standardError->channel(), new MockClock(), $probe));

        try {
            $tester->execute($input);
        } catch (\Throwable $failure) {
            return [$tester, $failure];
        }

        self::fail('The command succeeded: '.$tester->getDisplay());
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
        $tester = new CommandTester(new TestCommand('shop-prod', Channels::built($channels), $standardError->channel(), new MockClock(), $probe));
        $tester->execute($input);

        return $tester;
    }
}
