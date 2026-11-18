<?php

declare(strict_types=1);

namespace MulerTech\AlertingBundle\Absence;

use MulerTech\AlertingBundle\Alert\Severity;
use MulerTech\AlertingBundle\DeferredAlerter;
use MulerTech\AlertingBundle\Source\ThrowableAlertFactory;

/**
 * Runs every declared watch. A watch that fails is reported and does not keep the others from
 * running: a database down makes the failure queue unreadable, which must not hide a stopped worker.
 */
final readonly class AbsenceCheck
{
    /**
     * @param iterable<WatchInterface> $watches
     */
    public function __construct(
        private iterable $watches,
        private DeferredAlerter $alerter,
        private ThrowableAlertFactory $factory,
    ) {
    }

    /**
     * @return list<string> the name of each watch run
     */
    public function run(): array
    {
        $names = [];

        foreach ($this->watches as $watch) {
            $names[] = $watch->name();

            try {
                $watch->check();
            } catch (\Throwable $failure) {
                $this->alerter->alert($this->factory->create(
                    'check',
                    Severity::Error,
                    \sprintf('The check of the %s failed', $watch->name()),
                    $failure,
                    identity: [$watch->name()],
                ));
            }
        }

        return $names;
    }
}
