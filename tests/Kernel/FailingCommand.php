<?php

declare(strict_types=1);

namespace MulerTech\AlertingBundle\Tests\Kernel;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:fail')]
final class FailingCommand extends Command
{
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        throw new \RuntimeException('The export could not be written');
    }
}
