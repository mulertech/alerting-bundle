<?php

declare(strict_types=1);

namespace MulerTech\AlertingBundle\Tests\Double;

use MulerTech\AlertingBundle\Alert\Delivery;
use MulerTech\AlertingBundle\Channel\ChannelInterface;

final class RefusingChannel implements ChannelInterface
{
    public int $attempts = 0;

    public function __construct(
        private readonly string $reason = 'Connection refused',
    ) {
    }

    public function send(Delivery $delivery): void
    {
        ++$this->attempts;

        throw new \RuntimeException($this->reason);
    }
}
