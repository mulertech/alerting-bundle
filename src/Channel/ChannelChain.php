<?php

declare(strict_types=1);

namespace MulerTech\AlertingBundle\Channel;

use MulerTech\AlertingBundle\Alert\Delivery;

/**
 * Tries the channels in their declared order and stops at the first one that accepts the alert.
 * Each refusal is added to the delivery handed to the next channel, and standard error receives
 * whatever no channel accepted.
 *
 * A reminder about alerts left unread in a channel starts after that channel, since that is the one
 * nobody reads.
 */
final readonly class ChannelChain
{
    /**
     * @param array<string, ChannelInterface> $channels keyed by their declared name
     */
    public function __construct(
        private array $channels,
        private StandardErrorChannel $standardError,
    ) {
    }

    public function send(Delivery $delivery, ?string $after = null): void
    {
        foreach ($this->channels as $name => $channel) {
            if (null !== $after) {
                if ($name === $after) {
                    $after = null;
                }

                continue;
            }

            try {
                $channel->send($delivery);

                return;
            } catch (\Throwable $failure) {
                $delivery = $delivery->withIncident(\sprintf('Channel "%s" refused the alert: %s: %s', $name, $failure::class, $failure->getMessage()));
            }
        }

        $this->standardError->send($delivery);
    }
}
