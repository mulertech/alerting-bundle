<?php

declare(strict_types=1);

namespace MulerTech\AlertingBundle\Command;

use MulerTech\AlertingBundle\Absence\AbsenceCheck;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Runs the absence check now. Web requests run it too, but only when there are requests: schedule
 * this command outside the application (a systemd timer, a cron) so that the nights are checked.
 */
#[AsCommand(name: 'mulertech:alerting:check', description: 'Checks what malfunctions by not happening: worker, scheduled tasks, failure queue, unread alerts')]
final class CheckCommand extends Command
{
    public function __construct(
        private readonly AbsenceCheck $check,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        foreach ($this->check->run() as $name) {
            $output->writeln('Checked the '.$name);
        }

        return Command::SUCCESS;
    }
}
