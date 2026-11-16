<?php

declare(strict_types=1);

namespace MulerTech\AlertingBundle\Channel;

use MulerTech\AlertingBundle\Alert\Delivery;

/**
 * Tries the channels in their declared order and stops at the first one that accepts the alert.
 * Each refusal is added to the delivery handed to the next channel, and standard error receives
 * whatever no channel accepted.
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

    public function send(Delivery $delivery): void
    {
        foreach ($this->channels as $name => $channel) {
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
