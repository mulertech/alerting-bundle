<?php

declare(strict_types=1);

namespace MulerTech\AlertingBundle\Tests\Throttle;

use MulerTech\AlertingBundle\Alert\Alert;
use MulerTech\AlertingBundle\Alert\Severity;
use MulerTech\AlertingBundle\Throttle\Throttle;
use MulerTech\AlertingBundle\Throttle\Verdict;
use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\MockClock;

final class ThrottleTest extends TestCase
{
    private const int WINDOW = 900;

    private ArrayAdapter $pool;

    private MockClock $clock;

    protected function setUp(): void
    {
        $this->pool = new ArrayAdapter();
        $this->clock = new MockClock('2026-11-16 10:00:00');
    }

    public function testTheFirstOccurrenceIsSent(): void
    {
        $admission = $this->throttle()->admit($this->alert('a'));

        self::assertSame(Verdict::Send, $admission->verdict);
        self::assertSame(0, $admission->suppressed);
        self::assertSame([], $admission->incidents);
    }

    public function testTheSameFaultWithinTheWindowIsSuppressed(): void
    {
        $throttle = $this->throttle();
        $throttle->admit($this->alert('a'));
        $this->clock->sleep(self::WINDOW - 1);

        self::assertSame(Verdict::Suppress, $throttle->admit($this->alert('a'))->verdict);
    }

    public function testAnotherFaultWithinTheWindowIsSent(): void
    {
        $throttle = $this->throttle();
        $throttle->admit($this->alert('a'));

        self::assertSame(Verdict::Send, $throttle->admit($this->alert('b'))->verdict);
    }

    public function testTheNextOccurrenceAfterTheWindowCarriesTheCountHeldBack(): void
    {
        $throttle = $this->throttle();
        $throttle->admit($this->alert('a'));
        $throttle->admit($this->alert('a'));
        $throttle->admit($this->alert('a'));
        $this->clock->sleep(self::WINDOW);

        $admission = $throttle->admit($this->alert('a'));

        self::assertSame(Verdict::Send, $admission->verdict);
        self::assertSame(2, $admission->suppressed);
    }

    /**
     * The count alone does not say whether what it held back spans a minute or a day.
     */
    public function testTheCountComesWithWhenThePreviousAlertLeft(): void
    {
        $throttle = $this->throttle();
        $sentAt = $this->clock->now()->getTimestamp();
        $throttle->admit($this->alert('a'));
        $this->clock->sleep(60);
        $throttle->admit($this->alert('a'));
        $this->clock->sleep(5 * 3600);

        self::assertSame($sentAt, $throttle->admit($this->alert('a'))->previousAlertAt);
    }

    public function testNothingHeldBackNamesNoPreviousAlert(): void
    {
        $throttle = $this->throttle();
        $throttle->admit($this->alert('a'));
        $this->clock->sleep(self::WINDOW);

        self::assertNull($throttle->admit($this->alert('a'))->previousAlertAt);
    }

    public function testTheCountStartsAgainAfterItWasReported(): void
    {
        $throttle = $this->throttle();
        $throttle->admit($this->alert('a'));
        $throttle->admit($this->alert('a'));
        $this->clock->sleep(self::WINDOW);
        $throttle->admit($this->alert('a'));
        $this->clock->sleep(self::WINDOW);

        self::assertSame(0, $throttle->admit($this->alert('a'))->suppressed);
    }

    public function testSuppressingDoesNotExtendTheWindow(): void
    {
        $throttle = $this->throttle();
        $throttle->admit($this->alert('a'));
        $this->clock->sleep(self::WINDOW - 1);
        $throttle->admit($this->alert('a'));
        $this->clock->sleep(1);

        self::assertSame(Verdict::Send, $throttle->admit($this->alert('a'))->verdict);
    }

    public function testPastTheCapOneNoticeThenStandardErrorOnly(): void
    {
        $throttle = $this->throttle(cap: 2);

        self::assertSame(Verdict::Send, $throttle->admit($this->alert('a'))->verdict);
        self::assertSame(Verdict::Send, $throttle->admit($this->alert('b'))->verdict);
        self::assertSame(Verdict::CapReached, $throttle->admit($this->alert('c'))->verdict);
        self::assertSame(Verdict::OverCap, $throttle->admit($this->alert('d'))->verdict);
        self::assertSame(Verdict::OverCap, $throttle->admit($this->alert('e'))->verdict);
    }

    public function testAFaultHeldBackByTheCapIsSentOnceTheCapWindowEnds(): void
    {
        $throttle = $this->throttle(cap: 1);
        $throttle->admit($this->alert('a'));
        $throttle->admit($this->alert('b'));
        $this->clock->sleep(self::WINDOW);

        self::assertSame(Verdict::Send, $throttle->admit($this->alert('b'))->verdict);
    }

    public function testSuppressedOccurrencesDoNotCountTowardsTheCap(): void
    {
        $throttle = $this->throttle(cap: 2);
        $throttle->admit($this->alert('a'));
        $throttle->admit($this->alert('a'));
        $throttle->admit($this->alert('a'));

        self::assertSame(Verdict::Send, $throttle->admit($this->alert('b'))->verdict);
    }

    public function testAnUnreadableEntryCountsAsNoEntry(): void
    {
        $alert = $this->alert('a');
        $item = $this->pool->getItem('mulertech_alerting.'.$alert->fingerprint);
        $item->set('garbage');
        $this->pool->save($item);

        self::assertSame(Verdict::Send, $this->throttle()->admit($alert)->verdict);
    }

    public function testAPoolThatFailsLetsTheAlertOutAndSaysWhy(): void
    {
        $admission = new Throttle($this->failingPool(), $this->clock, self::WINDOW, 20)->admit($this->alert('a'));

        self::assertSame(Verdict::Send, $admission->verdict);
        self::assertSame(
            ['The cache pool of the alerting failed (RuntimeException: Disk full): alerts go out with no deduplication, under a cap counted by each process alone.'],
            $admission->incidents,
        );
    }

    public function testAPoolThatFailsFallsBackToACapCountedByTheProcess(): void
    {
        $throttle = new Throttle($this->failingPool(), $this->clock, self::WINDOW, 2);

        $verdicts = array_map(fn (string $fault) => $throttle->admit($this->alert($fault))->verdict, ['a', 'a', 'b', 'c']);

        self::assertSame([Verdict::Send, Verdict::Send, Verdict::CapReached, Verdict::OverCap], $verdicts);
    }

    public function testTheFallbackCapStartsAgainWithTheNextWindow(): void
    {
        $throttle = new Throttle($this->failingPool(), $this->clock, self::WINDOW, 1);
        $throttle->admit($this->alert('a'));
        $throttle->admit($this->alert('b'));
        $throttle->admit($this->alert('c'));
        $this->clock->sleep(self::WINDOW);

        self::assertSame(Verdict::Send, $throttle->admit($this->alert('d'))->verdict);
    }

    public function testEveryVerdictOfTheFallbackNamesThePoolFailure(): void
    {
        $throttle = new Throttle($this->failingPool(), $this->clock, self::WINDOW, 0);

        self::assertCount(1, $throttle->admit($this->alert('a'))->incidents);
        self::assertCount(1, $throttle->admit($this->alert('b'))->incidents);
    }

    public function testAPoolThatRefusesToSaveLetsTheAlertOutAndSaysWhy(): void
    {
        $pool = $this->createStub(CacheItemPoolInterface::class);
        $pool->method('getItem')->willReturnCallback(fn (string $key) => new ArrayAdapter()->getItem($key));
        $pool->method('save')->willReturn(false);

        $admission = new Throttle($pool, $this->clock, self::WINDOW, 20)->admit($this->alert('a'));

        self::assertSame(Verdict::Send, $admission->verdict);
        self::assertCount(1, $admission->incidents);
        self::assertStringContainsString('The pool refused to save "mulertech_alerting.cap".', $admission->incidents[0]);
    }

    private function failingPool(): CacheItemPoolInterface
    {
        $pool = $this->createStub(CacheItemPoolInterface::class);
        $pool->method('getItem')->willThrowException(new \RuntimeException('Disk full'));

        return $pool;
    }

    private function throttle(int $cap = 20): Throttle
    {
        return new Throttle($this->pool, $this->clock, self::WINDOW, $cap);
    }

    private function alert(string $fault): Alert
    {
        return new Alert(Severity::Error, 'http', 'Failure '.$fault, [$fault]);
    }
}
