<?php

declare(strict_types=1);

namespace MulerTech\AlertingBundle\Throttle;

use MulerTech\AlertingBundle\Alert\Alert;
use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Clock\ClockInterface;

/**
 * Decides whether an alert goes out: once per fingerprint and per window, and no more than a cap
 * per window across every fingerprint.
 *
 * The state lives in the declared pool, so that the web processes and the worker share it when the
 * pool is shared.
 *
 * Reading then writing an item is not atomic: alerts raised at the same instant by concurrent
 * processes may each go out. The cap bounds that case.
 *
 * When the pool fails, alerts go out without deduplication, under a fallback cap counted in the
 * memory of the process. Without it, a fault repeated on every request would send a mail per
 * request and get the application's mail account blocked, and the mails to its users with it.
 * That count is the one state kept from one request to the next, on purpose: it bounds the
 * process, not a request.
 */
final class Throttle
{
    private const string KEY_PREFIX = 'mulertech_alerting.';

    private const string CAP_KEY = self::KEY_PREFIX.'cap';

    /**
     * How long the count of held-back occurrences waits, past its window, for the next occurrence
     * of the same fault to report it.
     */
    private const int TALLY_RETENTION = 7 * 86400;

    private int $fallbackUntil = 0;

    private int $fallbackCount = 0;

    public function __construct(
        private readonly CacheItemPoolInterface $pool,
        private readonly ClockInterface $clock,
        private readonly int $window,
        private readonly int $cap,
    ) {
    }

    public function admit(Alert $alert): Admission
    {
        try {
            return $this->decide($alert);
        } catch (\Throwable $failure) {
            return $this->admitWithoutPool($failure);
        }
    }

    private function admitWithoutPool(\Throwable $failure): Admission
    {
        $now = $this->clock->now()->getTimestamp();

        if ($now >= $this->fallbackUntil) {
            $this->fallbackUntil = $now + $this->window;
            $this->fallbackCount = 0;
        }

        $incidents = [\sprintf(
            'The cache pool of the alerting failed (%s: %s): alerts go out with no deduplication, under a cap counted by each process alone.',
            $failure::class,
            $failure->getMessage(),
        )];

        if ($this->fallbackCount > $this->cap) {
            return new Admission(Verdict::OverCap, incidents: $incidents);
        }

        return new Admission($this->fallbackCount++ === $this->cap ? Verdict::CapReached : Verdict::Send, incidents: $incidents);
    }

    private function decide(Alert $alert): Admission
    {
        $now = $this->clock->now()->getTimestamp();
        $tally = $this->pool->getItem(self::KEY_PREFIX.$alert->fingerprint);
        $previous = self::read($tally);

        if (null !== $previous && $now < $previous['until']) {
            $this->write($tally, $previous['until'], $previous['count'] + 1, $previous['until'] - $now + self::TALLY_RETENTION);

            return new Admission(Verdict::Suppress);
        }

        $capItem = $this->pool->getItem(self::CAP_KEY);
        $sent = self::read($capItem);

        if (null === $sent || $now >= $sent['until']) {
            $sent = ['until' => $now + $this->window, 'count' => 0];
        }

        if ($sent['count'] > $this->cap) {
            return new Admission(Verdict::OverCap);
        }

        $this->write($capItem, $sent['until'], $sent['count'] + 1, $sent['until'] - $now);

        if ($sent['count'] === $this->cap) {
            return new Admission(Verdict::CapReached);
        }

        $this->write($tally, $now + $this->window, 0, $this->window + self::TALLY_RETENTION);

        return new Admission(Verdict::Send, $previous['count'] ?? 0);
    }

    /**
     * @return array{until: int, count: int}|null
     */
    private static function read(CacheItemInterface $item): ?array
    {
        if (!$item->isHit()) {
            return null;
        }

        $value = $item->get();

        if (!\is_array($value) || !\is_int($value['until'] ?? null) || !\is_int($value['count'] ?? null)) {
            return null;
        }

        return ['until' => $value['until'], 'count' => $value['count']];
    }

    private function write(CacheItemInterface $item, int $until, int $count, int $lifetime): void
    {
        $item->set(['until' => $until, 'count' => $count]);
        $item->expiresAfter($lifetime);

        if (!$this->pool->save($item)) {
            throw new \RuntimeException(\sprintf('The pool refused to save "%s".', $item->getKey()));
        }
    }
}
