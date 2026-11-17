<?php

declare(strict_types=1);

namespace MulerTech\AlertingBundle\Tests\Double;

use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;

final class FailingPool implements CacheItemPoolInterface
{
    public function getItem(string $key): CacheItemInterface
    {
        throw new \RuntimeException('Disk full');
    }

    public function getItems(array $keys = []): iterable
    {
        throw new \RuntimeException('Disk full');
    }

    public function hasItem(string $key): bool
    {
        throw new \RuntimeException('Disk full');
    }

    public function clear(): bool
    {
        throw new \RuntimeException('Disk full');
    }

    public function deleteItem(string $key): bool
    {
        throw new \RuntimeException('Disk full');
    }

    public function deleteItems(array $keys): bool
    {
        throw new \RuntimeException('Disk full');
    }

    public function save(CacheItemInterface $item): bool
    {
        throw new \RuntimeException('Disk full');
    }

    public function saveDeferred(CacheItemInterface $item): bool
    {
        throw new \RuntimeException('Disk full');
    }

    public function commit(): bool
    {
        throw new \RuntimeException('Disk full');
    }
}
