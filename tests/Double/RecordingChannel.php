<?php

declare(strict_types=1);

namespace MulerTech\AlertingBundle\Tests\Double;

use MulerTech\AlertingBundle\Alert\Delivery;
use MulerTech\AlertingBundle\Channel\ChannelInterface;

final class RecordingChannel implements ChannelInterface
{
    /**
     * @var list<Delivery>
     */
    public array $deliveries = [];

    /**
     * @var (\Closure(Delivery): void)|null
     */
    private ?\Closure $onSend;

    /**
     * @param (\Closure(Delivery): void)|null $onSend
     */
    public function __construct(?\Closure $onSend = null)
    {
        $this->onSend = $onSend;
    }

    public function send(Delivery $delivery): void
    {
        $this->deliveries[] = $delivery;

        if (null !== $this->onSend) {
            ($this->onSend)($delivery);
        }
    }
}
