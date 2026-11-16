<?php

declare(strict_types=1);

namespace MulerTech\AlertingBundle\Command;

use MulerTech\AlertingBundle\Alert\Alert;
use MulerTech\AlertingBundle\Alert\Delivery;
use MulerTech\AlertingBundle\Alert\Severity;
use MulerTech\AlertingBundle\Channel\ChannelInterface;
use MulerTech\AlertingBundle\Channel\StandardErrorChannel;
use Psr\Clock\ClockInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Sends a test alert through each declared channel, one by one rather than through the chain: in
 * a chain, the second channel only serves the day the first one falls, so without this its
 * configuration is first tried that day.
 */
#[AsCommand(name: 'mulertech:alerting:test', description: 'Sends a test alert through each declared channel, one by one')]
final class TestCommand extends Command
{
    /**
     * @param array<string, ChannelInterface> $channels keyed by their declared name
     */
    public function __construct(
        private readonly string $application,
        private readonly array $channels,
        private readonly StandardErrorChannel $standardError,
        private readonly ClockInterface $clock,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $refused = [];

        foreach ($this->channels as $name => $channel) {
            try {
                $channel->send($this->delivery($name));
                $io->writeln(\sprintf('<info>✔</info> %s', $name));
            } catch (\Throwable $failure) {
                $refused[] = $name;
                $io->writeln(\sprintf('<error>✘</error> %s: %s: %s', $name, $failure::class, $failure->getMessage()));
            }
        }

        $this->standardError->send($this->delivery('standard error'));
        $io->writeln('<info>✔</info> standard error, written to the error output of this process');

        if ([] !== $refused) {
            $io->error(\sprintf('Refused the test alert: %s.', implode(', ', $refused)));

            return Command::FAILURE;
        }

        $io->success('Every channel accepted the test alert. Check that each one arrived.');

        return Command::SUCCESS;
    }

    private function delivery(string $channel): Delivery
    {
        return new Delivery($this->application, new Alert(
            Severity::Error,
            'test',
            \sprintf('Test alert sent by mulertech:alerting:test through the channel "%s".', $channel),
            [$channel],
        ), $this->clock->now());
    }
}
