<?php

declare(strict_types=1);

namespace MulerTech\AlertingBundle\Tests\Probe;

use MulerTech\AlertingBundle\Absence\Ledger;
use MulerTech\AlertingBundle\Alert\Severity;
use MulerTech\AlertingBundle\Probe\PoolProbe;
use MulerTech\AlertingBundle\Probe\PoolProbeHandler;
use MulerTech\AlertingBundle\Probe\PoolProbeSender;
use MulerTech\AlertingBundle\Tests\Double\AlertingKit;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\TransportNamesStamp;

final class PoolProbeTest extends TestCase
{
    public function testAWorkerSharingThePoolAnswersAndRaisesNothing(): void
    {
        $kit = new AlertingKit();
        $bus = $this->bus(new PoolProbeHandler($kit->ledger, $kit->alerter, 'cache.alerting'));

        self::assertTrue(new PoolProbeSender($kit->ledger, $bus, $kit->clock, 'async', 30)->probe());
        self::assertSame([], $kit->deliveries());
        self::assertSame(['async'], $bus->stamp?->getTransportNames(), 'The probe goes to the watched transport, whatever the routing.');
    }

    public function testAWorkerWithAnotherPoolSaysSoItself(): void
    {
        $kit = new AlertingKit();
        $workerPool = new Ledger(new ArrayAdapter(), $kit->clock);
        $bus = $this->bus(new PoolProbeHandler($workerPool, $kit->alerter, 'cache.alerting'));

        self::assertFalse(new PoolProbeSender($kit->ledger, $bus, $kit->clock, 'async', 30)->probe());

        $alert = $kit->only()->alert;
        self::assertSame(Severity::Critical, $alert->severity);
        self::assertStringStartsWith('The cache pool "cache.alerting" is not shared between the web processes and the worker', $alert->summary);
        self::assertStringEndsWith('Accepted pools: Redis, Memcached, or a filesystem pool in a directory mounted in every container.', $alert->summary);
    }

    public function testAnAnswerArrivingWhileTheCommandWaitsIsSeen(): void
    {
        $kit = new AlertingKit();
        $handler = new PoolProbeHandler($kit->ledger, $kit->alerter, 'cache.alerting');
        $bus = $this->bus(null);
        $clock = new class($kit->clock, $bus, $handler) implements ClockInterface {
            public function __construct(private readonly MockClock $clock, private readonly object $bus, private readonly PoolProbeHandler $handler)
            {
            }

            public function now(): \DateTimeImmutable
            {
                return $this->clock->now();
            }

            public function sleep(float|int $seconds): void
            {
                $this->clock->sleep($seconds);
                \assert(property_exists($this->bus, 'probe') && $this->bus->probe instanceof PoolProbe);
                ($this->handler)($this->bus->probe);
            }

            public function withTimeZone(\DateTimeZone|string $timezone): static
            {
                return $this;
            }
        };

        self::assertTrue(new PoolProbeSender($kit->ledger, $bus, $clock, 'async', 30)->probe());
    }

    public function testAProbeHandledAfterALaterRunIsNoFalseAlert(): void
    {
        $kit = new AlertingKit();
        $stoppedWorker = $this->bus(null);

        self::assertFalse(new PoolProbeSender($kit->ledger, $stoppedWorker, $kit->clock, 'async', 30)->probe());
        $late = $stoppedWorker->probe;
        \assert($late instanceof PoolProbe);

        $handler = new PoolProbeHandler($kit->ledger, $kit->alerter, 'cache.alerting');
        self::assertTrue(new PoolProbeSender($kit->ledger, $this->bus($handler), $kit->clock, 'async', 30)->probe());
        $handler($late);

        self::assertSame([], $kit->deliveries());
    }

    public function testNoAnswerWithinTheWaitIsNoProof(): void
    {
        $kit = new AlertingKit();

        self::assertFalse(new PoolProbeSender($kit->ledger, $this->bus(null), $kit->clock, 'async', 30)->probe());
        self::assertSame('2026-11-16 10:00:30', $kit->clock->now()->format('Y-m-d H:i:s'));
    }

    /**
     * @return MessageBusInterface&object{stamp: ?TransportNamesStamp, probe: ?PoolProbe}
     */
    private function bus(?PoolProbeHandler $worker): MessageBusInterface
    {
        return new class($worker) implements MessageBusInterface {
            public ?TransportNamesStamp $stamp = null;

            public ?PoolProbe $probe = null;

            public function __construct(private readonly ?PoolProbeHandler $worker)
            {
            }

            public function dispatch(object $message, array $stamps = []): Envelope
            {
                \assert($message instanceof PoolProbe);
                $this->probe = $message;
                $envelope = new Envelope($message, $stamps);
                $this->stamp = $envelope->last(TransportNamesStamp::class);

                if (null !== $this->worker) {
                    ($this->worker)($message);
                }

                return $envelope;
            }
        };
    }
}
