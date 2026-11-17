<?php

declare(strict_types=1);

namespace MulerTech\AlertingBundle\Tests\Double;

use MulerTech\AlertingBundle\Alert\Delivery;
use MulerTech\AlertingBundle\Channel\ReadReceiptInterface;

final class ReadReceiptChannel implements ReadReceiptInterface
{
    /**
     * @var list<Delivery>
     */
    public array $deliveries = [];

    public function __construct(
        public ?\DateTimeImmutable $oldestUnread = null,
    ) {
    }

    public function send(Delivery $delivery): void
    {
        $this->deliveries[] = $delivery;
    }

    public function oldestUnreadSince(): ?\DateTimeImmutable
    {
        return $this->oldestUnread;
    }
}
