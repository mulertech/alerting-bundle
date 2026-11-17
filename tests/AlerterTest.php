<?php

declare(strict_types=1);

namespace MulerTech\AlertingBundle\Tests;

use MulerTech\AlertingBundle\Tests\Double\Channels;
use MulerTech\AlertingBundle\Alert\Alert;
use MulerTech\AlertingBundle\Alert\Severity;
use MulerTech\AlertingBundle\Alerter;
use MulerTech\AlertingBundle\Channel\ChannelChain;
use MulerTech\AlertingBundle\Channel\ChannelInterface;
use MulerTech\AlertingBundle\Tests\Double\RecordingChannel;
use MulerTech\AlertingBundle\Tests\Double\StandardErrorFile;
use MulerTech\AlertingBundle\Throttle\Throttle;
use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\MockClock;

final class AlerterTest extends TestCase
{
    private const int WINDOW = 900;

    private StandardErrorFile $standardError;

    private MockClock $clock;

    protected function setUp(): void
    {
        $this->standardError = new StandardErrorFile();
        $this->clock = new MockClock('2026-11-16 10:00:00');
    }

    public function testAnAlertReachesTheChannelWithItsApplicationAndTime(): void
    {
        $channel = new RecordingChannel();
        $alert = $this->alert('a');

        $this->alerter($channel)->alert($alert);

        self::assertCount(1, $channel->deliveries);
        self::assertSame('shop-prod', $channel->deliveries[0]->application);
        self::assertSame($alert, $channel->deliveries[0]->alert);
        self::assertEquals($this->clock->now(), $channel->deliveries[0]->raisedAt);
        self::assertSame([], $this->standardError->lines());
    }

    public function testARepeatedFaultIsSentOnceThenReportedWithItsCount(): void
    {
        $channel = new RecordingChannel();
        $alerter = $this->alerter($channel);

        $alerter->alert($this->alert('a'));
        $alerter->alert($this->alert('a'));
        $alerter->alert($this->alert('a'));
        $this->clock->sleep(self::WINDOW);
        $alerter->alert($this->alert('a'));

        self::assertCount(2, $channel->deliveries);
        self::assertSame(2, $channel->deliveries[1]->suppressed);
    }

    public function testReachingTheCapSendsANoticeAndKeepsTheAlertOnStandardError(): void
    {
        $channel = new RecordingChannel();
        $alerter = $this->alerter($channel, cap: 1);

        $alerter->alert($this->alert('a'));
        $alerter->alert($this->alert('b'));
        $alerter->alert($this->alert('c'));

        self::assertCount(2, $channel->deliveries);
        self::assertSame('alerting', $channel->deliveries[1]->alert->source);
        self::assertSame(Severity::Critical, $channel->deliveries[1]->alert->severity);
        self::assertSame(
            '1 alerts were sent within 900 seconds: until this window ends, further alerts are written to standard error only.',
            $channel->deliveries[1]->alert->summary,
        );

        $lines = $this->standardError->lines();
        self::assertSame(['Failure b', 'Failure c'], array_column($lines, 'summary'));
        self::assertSame([['Over the alert cap: written here only.'], ['Over the alert cap: written here only.']], array_column($lines, 'incidents'));
    }

    public function testWithAFailingPoolTheCapNoticeAndTheAlertsHeldBackNameTheFailure(): void
    {
        $pool = $this->createStub(CacheItemPoolInterface::class);
        $pool->method('getItem')->willThrowException(new \RuntimeException('Disk full'));
        $channel = new RecordingChannel();
        $alerter = new Alerter(
            'shop-prod',
            new Throttle($pool, $this->clock, self::WINDOW, 0),
            new ChannelChain(Channels::built(['messaging' => $channel]), $this->standardError->channel()),
            $this->standardError->channel(),
            $this->clock,
            self::WINDOW,
            0,
        );

        $alerter->alert($this->alert('a'));
        $alerter->alert($this->alert('b'));

        self::assertCount(1, $channel->deliveries);
        self::assertSame('alerting', $channel->deliveries[0]->alert->source);
        self::assertStringContainsString('Disk full', $channel->deliveries[0]->incidents[0]);
        foreach ($this->standardError->lines() as $line) {
            self::assertIsArray($line['incidents']);
            self::assertCount(2, $line['incidents']);
            self::assertIsString($line['incidents'][0]);
            self::assertStringContainsString('Disk full', $line['incidents'][0]);
        }
        self::assertCount(2, $this->standardError->lines());
    }

    public function testAnAlertRaisedWhileSendingGoesToStandardErrorOnly(): void
    {
        $alerter = null;
        $channel = new RecordingChannel(function () use (&$alerter): void {
            \assert($alerter instanceof Alerter);
            $alerter->alert($this->alert('raised by the channel'));
        });
        $alerter = $this->alerter($channel);

        $alerter->alert($this->alert('a'));

        self::assertCount(1, $channel->deliveries);
        $lines = $this->standardError->lines();
        self::assertCount(1, $lines);
        self::assertSame('Failure raised by the channel', $lines[0]['summary']);
        self::assertSame(
            ['Raised while another alert was being sent: written here only, so that a failing channel cannot feed itself.'],
            $lines[0]['incidents'],
        );
    }

    public function testAFailureOfTheAlertingEndsOnStandardErrorAndIsNotThrown(): void
    {
        $channel = new RecordingChannel();
        $clock = new class('2026-11-16 10:00:00') implements ClockInterface {
            private int $calls = 0;

            public function __construct(private readonly string $now)
            {
            }

            public function now(): \DateTimeImmutable
            {
                // The throttle reads the clock first, the delivery second: the second call fails.
                if (2 === ++$this->calls) {
                    throw new \LogicException('Clock unavailable');
                }

                return new \DateTimeImmutable($this->now);
            }
        };
        $alerter = new Alerter(
            'shop-prod',
            new Throttle(new ArrayAdapter(), $clock, self::WINDOW, 20),
            new ChannelChain(Channels::built(['messaging' => $channel]), $this->standardError->channel()),
            $this->standardError->channel(),
            $clock,
            self::WINDOW,
            20,
        );

        $alerter->alert($this->alert('a'));
        $alerter->alert($this->alert('b'));

        $lines = $this->standardError->lines();
        self::assertCount(1, $lines);
        self::assertSame(['The alerting failed: LogicException: Clock unavailable'], $lines[0]['incidents']);
        self::assertCount(1, $channel->deliveries, 'The next alert goes out normally.');
    }

    private function alerter(ChannelInterface $channel, int $cap = 20): Alerter
    {
        return new Alerter(
            'shop-prod',
            new Throttle(new ArrayAdapter(), $this->clock, self::WINDOW, $cap),
            new ChannelChain(Channels::built(['messaging' => $channel]), $this->standardError->channel()),
            $this->standardError->channel(),
            $this->clock,
            self::WINDOW,
            $cap,
        );
    }

    private function alert(string $fault): Alert
    {
        return new Alert(Severity::Error, 'http', 'Failure '.$fault, [$fault]);
    }
}
