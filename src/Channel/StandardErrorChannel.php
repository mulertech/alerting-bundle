<?php

declare(strict_types=1);

namespace MulerTech\AlertingBundle\Channel;

use MulerTech\AlertingBundle\Alert\Delivery;

/**
 * The end of every chain, which nothing can remove: it depends on no network, no database and no
 * queue, so it still speaks when everything an application relies on has fallen.
 *
 * One JSON line per alert, so that a container log collector keeps it as one entry.
 */
final readonly class StandardErrorChannel
{
    public function __construct(
        private string $stream = 'php://stderr',
    ) {
    }

    public function send(Delivery $delivery): void
    {
        $line = json_encode([
            'alerting' => $delivery->application,
            'severity' => $delivery->alert->severity->value,
            'source' => $delivery->alert->source,
            'summary' => $delivery->alert->summary,
            'context' => $delivery->alert->context,
            'suppressed' => $delivery->suppressed,
            'previous_alert_at' => $delivery->previousAlertAt?->format(\DATE_ATOM),
            'incidents' => $delivery->incidents,
            'raised_at' => $delivery->raisedAt->format(\DATE_ATOM),
        ], \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_INVALID_UTF8_SUBSTITUTE | \JSON_THROW_ON_ERROR);

        $handle = @fopen($this->stream, 'a');

        if (false === $handle) {
            error_log($line);

            return;
        }

        if (false === @fwrite($handle, $line."\n")) {
            error_log($line);
        }

        fclose($handle);
    }
}
