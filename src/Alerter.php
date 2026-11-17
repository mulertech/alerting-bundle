<?php

declare(strict_types=1);

namespace MulerTech\AlertingBundle;

use MulerTech\AlertingBundle\Alert\Alert;
use MulerTech\AlertingBundle\Alert\Delivery;
use MulerTech\AlertingBundle\Alert\Severity;
use MulerTech\AlertingBundle\Channel\ChannelChain;
use MulerTech\AlertingBundle\Channel\StandardErrorChannel;
use MulerTech\AlertingBundle\Throttle\Throttle;
use MulerTech\AlertingBundle\Throttle\Verdict;
use Psr\Clock\ClockInterface;

/**
 * The single way in for every alert.
 *
 * It never throws: a failure of the alerting ends on standard error and leaves the response, the
 * message or the command of the application as it was.
 */
final class Alerter
{
    /**
     * Set while an alert is being sent. A channel that fails may log an error, which comes straight
     * back here through the log handler: that alert goes to standard error, otherwise a failing
     * channel would feed itself.
     */
    private bool $sending = false;

    public function __construct(
        private readonly string $application,
        private readonly Throttle $throttle,
        private readonly ChannelChain $channels,
        private readonly StandardErrorChannel $standardError,
        private readonly ClockInterface $clock,
        private readonly int $window,
        private readonly int $cap,
    ) {
    }

    /**
     * @param string|null $after the channel to skip, with every channel before it
     */
    public function alert(Alert $alert, ?string $after = null): void
    {
        if ($this->sending) {
            $this->standardError->send($this->deliver($alert)->withIncident(
                'Raised while another alert was being sent: written here only, so that a failing channel cannot feed itself.',
            ));

            return;
        }

        $this->sending = true;

        try {
            $this->dispatch($alert, $after);
        } catch (\Throwable $failure) {
            $this->standardError->send($this->deliver($alert)->withIncident(\sprintf(
                'The alerting failed: %s: %s',
                $failure::class,
                $failure->getMessage(),
            )));
        } finally {
            $this->sending = false;
        }
    }

    private function dispatch(Alert $alert, ?string $after): void
    {
        $admission = $this->throttle->admit($alert);

        match ($admission->verdict) {
            Verdict::Suppress => null,
            Verdict::Send => $this->channels->send(new Delivery(
                $this->application,
                $alert,
                $this->clock->now(),
                $admission->suppressed,
                $admission->incidents,
                null === $admission->previousAlertAt ? null : $this->clock->now()->setTimestamp($admission->previousAlertAt),
            ), $after),
            Verdict::CapReached => $this->reachCap($alert, $admission->incidents),
            Verdict::OverCap => $this->standardError->send($this->overCap($alert, $admission->incidents)),
        };
    }

    /**
     * @param list<string> $incidents
     */
    private function reachCap(Alert $alert, array $incidents): void
    {
        $this->channels->send(new Delivery($this->application, new Alert(
            Severity::Critical,
            'alerting',
            \sprintf(
                '%d alerts were sent within %d seconds: until this window ends, further alerts are written to standard error only.',
                $this->cap,
                $this->window,
            ),
            ['cap'],
        ), $this->clock->now(), incidents: $incidents));

        $this->standardError->send($this->overCap($alert, $incidents));
    }

    /**
     * @param list<string> $incidents
     */
    private function overCap(Alert $alert, array $incidents): Delivery
    {
        return new Delivery($this->application, $alert, $this->clock->now(), incidents: [...$incidents, 'Over the alert cap: written here only.']);
    }

    private function deliver(Alert $alert): Delivery
    {
        return new Delivery($this->application, $alert, $this->clock->now());
    }
}
