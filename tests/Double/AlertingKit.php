<?php

declare(strict_types=1);

namespace MulerTech\AlertingBundle\Tests\Double;

use MulerTech\AlertingBundle\DeferredAlerter;
use MulerTech\AlertingBundle\Absence\Ledger;
use MulerTech\AlertingBundle\Alert\Delivery;
use MulerTech\AlertingBundle\Alerter;
use MulerTech\AlertingBundle\Channel\ChannelChain;
use MulerTech\AlertingBundle\Channel\ChannelInterface;
use MulerTech\AlertingBundle\Source\DependencyClassifier;
use MulerTech\AlertingBundle\Source\ThrowableAlertFactory;
use MulerTech\AlertingBundle\Source\ThrowableClaims;
use MulerTech\AlertingBundle\Throttle\Throttle;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\MockClock;

/**
 * A real alerting, with a recording channel and a file for standard error, for the tests of the
 * sources and the watches.
 */
final class AlertingKit
{
    public const string PROJECT_DIR = '/srv/app';

    public readonly MockClock $clock;

    public readonly ArrayAdapter $pool;

    public readonly RecordingChannel $channel;

    public readonly StandardErrorFile $standardError;

    public readonly Alerter $alerter;

    public readonly ThrowableClaims $claims;

    public readonly ThrowableAlertFactory $factory;

    public readonly Ledger $ledger;

    /**
     * The alerter as sources and watches hold it.
     */
    public readonly DeferredAlerter $deferred;

    /**
     * @param array<string, ChannelInterface> $channels replace the recording channel when given
     * @param array<string, string>           $dependencies
     */
    public function __construct(array $channels = [], array $dependencies = [])
    {
        $this->clock = new MockClock('2026-11-16 10:00:00');
        $this->pool = new ArrayAdapter();
        $this->channel = new RecordingChannel();
        $this->standardError = new StandardErrorFile();
        $this->alerter = new Alerter(
            'shop-prod',
            new Throttle($this->pool, $this->clock, 900, 20),
            new ChannelChain(Channels::built([] === $channels ? ['recording' => $this->channel] : $channels), $this->standardError->channel()),
            $this->standardError->channel(),
            $this->clock,
            900,
            20,
        );
        $this->claims = new ThrowableClaims();
        $this->factory = new ThrowableAlertFactory(new DependencyClassifier($dependencies), self::PROJECT_DIR);
        $this->ledger = new Ledger($this->pool, $this->clock);
        $this->deferred = new DeferredAlerter(fn (): Alerter => $this->alerter);
    }

    /**
     * @return list<Delivery>
     */
    public function deliveries(): array
    {
        return $this->channel->deliveries;
    }

    public function only(): Delivery
    {
        \assert(1 === \count($this->channel->deliveries), \sprintf('%d deliveries instead of one.', \count($this->channel->deliveries)));

        return $this->channel->deliveries[0];
    }
}
