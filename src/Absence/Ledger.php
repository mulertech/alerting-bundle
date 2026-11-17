<?php

declare(strict_types=1);

namespace MulerTech\AlertingBundle\Absence;

use Psr\Cache\CacheItemPoolInterface;
use Psr\Clock\ClockInterface;

/**
 * When each watched thing was last seen, kept in the pool of the alerting so that the process that
 * checks reads what another process wrote: the web reads the worker's heartbeat.
 */
final readonly class Ledger
{
    private const string KEY_PREFIX = 'mulertech_alerting.ledger.';

    /**
     * What is seen again within this time stays in the pool; what is not is a stale entry.
     */
    private const int RETENTION = 30 * 86400;

    public function __construct(
        private CacheItemPoolInterface $pool,
        private ClockInterface $clock,
    ) {
    }

    public function record(string $subject): void
    {
        $this->write($subject, (string) $this->clock->now()->getTimestamp());
    }

    /**
     * The timestamp it was last recorded at, or null if it never was.
     */
    public function seenAt(string $subject): ?int
    {
        $value = $this->read($subject);

        return null !== $value && ctype_digit($value) ? (int) $value : null;
    }

    /**
     * The timestamp it was last recorded at. The first reading of something never recorded starts
     * the count from now: a pool just emptied by a deployment holds no history, which is not an
     * absence.
     */
    public function lastSeen(string $subject): int
    {
        $seenAt = $this->seenAt($subject);

        if (null === $seenAt) {
            $this->record($subject);

            return $this->clock->now()->getTimestamp();
        }

        return $seenAt;
    }

    public function read(string $subject): ?string
    {
        $item = $this->pool->getItem(self::key($subject));
        $value = $item->isHit() ? $item->get() : null;

        return \is_string($value) ? $value : null;
    }

    public function write(string $subject, string $value): void
    {
        $item = $this->pool->getItem(self::key($subject));
        $item->set($value);
        $item->expiresAfter(self::RETENTION);

        if (!$this->pool->save($item)) {
            throw new \RuntimeException(\sprintf('The pool refused to save "%s".', $item->getKey()));
        }
    }

    /**
     * Hashed: a subject carries characters a cache key may not, such as the colon of a command name.
     */
    private static function key(string $subject): string
    {
        return self::KEY_PREFIX.hash('xxh128', $subject);
    }
}
