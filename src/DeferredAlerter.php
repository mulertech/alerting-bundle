<?php

declare(strict_types=1);

namespace MulerTech\AlertingBundle;

use MulerTech\AlertingBundle\Alert\Alert;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * The alerter as every source and watch holds it: built at the first alert, never with them.
 *
 * A source is built whenever its event fires — the end of every command, every request — and the
 * alerter it would build reads the name of the application and the pool's address, often from
 * variables a runtime alone provides. An image build runs commands without them: building the
 * alerter there would fail every one of those commands for an alert nobody raised.
 *
 * Once built, the alerter never throws. Building it may, when a variable is missing or the pool
 * cannot be reached: the caller must not fail for it, and the alert must not vanish either.
 *
 * An alert raised while a request is handled marks that request: the code that reported its
 * failure (a logged error) and then answered 5xx itself has said everything, and the response
 * must not raise a second alert for the same fault.
 */
final readonly class DeferredAlerter
{
    public const string REQUEST_REPORTED = '_mulertech_alerting_reported';

    /**
     * @param \Closure(): Alerter $alerter
     */
    public function __construct(
        private \Closure $alerter,
        private ?RequestStack $requests = null,
    ) {
    }

    public function alert(Alert $alert, ?string $after = null): void
    {
        $this->requests?->getMainRequest()?->attributes->set(self::REQUEST_REPORTED, true);

        try {
            $alerter = ($this->alerter)();
        } catch (\Throwable $failure) {
            error_log(\sprintf('mulertech_alerting could not be built, so this alert reaches the error log only: %s. Cause: %s: %s', $alert->summary, $failure::class, $failure->getMessage()));

            return;
        }

        $alerter->alert($alert, $after);
    }
}
