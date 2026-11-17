<?php

declare(strict_types=1);

namespace MulerTech\AlertingBundle\Tests\Double;

use MulerTech\AlertingBundle\Channel\ChannelInterface;

/**
 * Channels as the container hands them over: each one built when it is first needed.
 */
final class Channels
{
    /**
     * @param array<string, ChannelInterface> $channels
     *
     * @return array<string, \Closure(): ChannelInterface>
     */
    public static function built(array $channels): array
    {
        return array_map(static fn (ChannelInterface $channel): \Closure => static fn (): ChannelInterface => $channel, $channels);
    }
}
