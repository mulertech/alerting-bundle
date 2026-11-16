<?php

declare(strict_types=1);

namespace MulerTech\AlertingBundle\Channel;

use MulerTech\AlertingBundle\Alert\Delivery;

/**
 * A way out for alerts. Implement it to plug a channel of the application into the chain.
 *
 * A channel throws when it could not send: the chain then tries the next one and carries the
 * failure in the delivery it hands on. Returning without sending makes the alert disappear.
 */
interface ChannelInterface
{
    public function send(Delivery $delivery): void;
}
