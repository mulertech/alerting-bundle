<?php

declare(strict_types=1);

namespace MulerTech\AlertingBundle\Format;

use MulerTech\AlertingBundle\Alert\Delivery;

/**
 * Plain text of an alert, for the channels that carry text: mail, chat, SMS, push. A channel of
 * the application may use it too, so that every channel reads the same.
 */
final readonly class TextFormatter
{
    public const int MAX_SUBJECT_LENGTH = 150;

    public function subject(Delivery $delivery): string
    {
        $subject = \sprintf('[%s] %s: %s', $delivery->application, ucfirst($delivery->alert->severity->value), $delivery->alert->summary);

        return mb_strlen($subject) > self::MAX_SUBJECT_LENGTH
            ? mb_substr($subject, 0, self::MAX_SUBJECT_LENGTH).'…'
            : $subject;
    }

    public function body(Delivery $delivery): string
    {
        $alert = $delivery->alert;
        $sections = [
            $alert->summary,
            implode("\n", [
                'Application: '.$delivery->application,
                'Severity: '.$alert->severity->value,
                'Source: '.$alert->source,
                'Raised at: '.$delivery->raisedAt->format(\DATE_ATOM),
            ]),
        ];

        if ([] !== $alert->context) {
            $sections[] = "Context:\n".implode("\n", array_map(
                static fn (string $key, string $value): string => \sprintf('  %s: %s', $key, $value),
                array_keys($alert->context),
                $alert->context,
            ));
        }

        if ($delivery->suppressed > 0) {
            $since = null === $delivery->previousAlertAt ? 'the previous alert' : 'the previous alert, sent at '.$delivery->previousAlertAt->format(\DATE_ATOM);
            $sections[] = 1 === $delivery->suppressed
                ? \sprintf('One more occurrence of this fault was held back since %s.', $since)
                : \sprintf('%d more occurrences of this fault were held back since %s.', $delivery->suppressed, $since);
        }

        if ([] !== $delivery->incidents) {
            $sections[] = "Incidents of the alerting itself:\n".implode("\n", array_map(
                static fn (string $incident): string => '  - '.$incident,
                $delivery->incidents,
            ));
        }

        return implode("\n\n", $sections);
    }
}
