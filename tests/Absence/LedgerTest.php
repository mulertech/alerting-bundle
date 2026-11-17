<?php

declare(strict_types=1);

namespace MulerTech\AlertingBundle\Tests\Absence;

use MulerTech\AlertingBundle\Absence\Ledger;
use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\MockClock;

final class LedgerTest extends TestCase
{
    public function testWhatIsRecordedIsReadBack(): void
    {
        $clock = new MockClock('2026-11-16 10:00:00');
        $ledger = new Ledger(new ArrayAdapter(), $clock);

        $ledger->record('worker.async');
        $clock->sleep(30);

        self::assertSame(1_794_823_200, $ledger->seenAt('worker.async'));
        self::assertSame(1_794_823_200, $ledger->lastSeen('worker.async'));
    }

    public function testSomethingNeverRecordedHasNoTime(): void
    {
        self::assertNull(new Ledger(new ArrayAdapter(), new MockClock())->seenAt('worker.async'));
    }

    public function testTheFirstReadingOfSomethingNeverRecordedStartsTheCountNow(): void
    {
        $clock = new MockClock('2026-11-16 10:00:00');
        $ledger = new Ledger(new ArrayAdapter(), $clock);

        self::assertSame(1_794_823_200, $ledger->lastSeen('worker.async'));
        $clock->sleep(30);
        self::assertSame(1_794_823_200, $ledger->lastSeen('worker.async'));
    }

    public function testAValueThatIsNoTimeIsNoTime(): void
    {
        $ledger = new Ledger(new ArrayAdapter(), new MockClock());
        $ledger->write('worker.async', 'garbage');

        self::assertNull($ledger->seenAt('worker.async'));
    }

    public function testAnyTextIsKept(): void
    {
        $ledger = new Ledger(new ArrayAdapter(), new MockClock());

        $ledger->write('probe.token', 'abc');

        self::assertSame('abc', $ledger->read('probe.token'));
        self::assertNull($ledger->read('probe.reply'));
    }

    public function testSubjectsWithCharactersACacheKeyRefusesAreKept(): void
    {
        $ledger = new Ledger(new ArrayAdapter(), new MockClock());

        $ledger->write('schedule.app:import {all}', 'kept');

        self::assertSame('kept', $ledger->read('schedule.app:import {all}'));
    }

    public function testAPoolThatRefusesToSaveIsAFailure(): void
    {
        $pool = $this->createStub(CacheItemPoolInterface::class);
        $pool->method('getItem')->willReturnCallback(static fn (string $key) => new ArrayAdapter()->getItem($key));
        $pool->method('save')->willReturn(false);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/^The pool refused to save "mulertech_alerting\.ledger\.[0-9a-f]+"\.$/');

        new Ledger($pool, new MockClock())->record('worker.async');
    }
}
