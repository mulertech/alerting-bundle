<?php

declare(strict_types=1);

namespace MulerTech\AlertingBundle\Channel;

/**
 * Implemented by a channel of the application that knows whether its alerts were read, such as an
 * internal messaging. An alert accepted by a channel nobody reads is as lost as a refused one:
 * past the declared delay, the alerting sends a reminder through the channels after this one.
 */
interface ReadReceiptInterface extends ChannelInterface
{
    /**
     * When the oldest alert this channel delivered and nobody has read yet was delivered, or null
     * when every alert was read.
     */
    public function oldestUnreadSince(): ?\DateTimeImmutable;
}
