<?php

declare(strict_types=1);

namespace MulerTech\AlertingBundle;

use MulerTech\AlertingBundle\Alert\Alert;

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
 */
final readonly class DeferredAlerter
{
    /**
     * @param \Closure(): Alerter $alerter
     */
    public function __construct(
        private \Closure $alerter,
    ) {
    }

    public function alert(Alert $alert, ?string $after = null): void
    {
        try {
            $alerter = ($this->alerter)();
        } catch (\Throwable $failure) {
            error_log(\sprintf('mulertech_alerting could not be built, so this alert reaches the error log only: %s. Cause: %s: %s', $alert->summary, $failure::class, $failure->getMessage()));

            return;
        }

        $alerter->alert($alert, $after);
    }
}
