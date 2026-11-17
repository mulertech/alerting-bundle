<?php

declare(strict_types=1);

namespace MulerTech\AlertingBundle\Command;

use MulerTech\AlertingBundle\Alert\Alert;
use MulerTech\AlertingBundle\Alert\Delivery;
use MulerTech\AlertingBundle\Alert\Severity;
use MulerTech\AlertingBundle\Channel\ChannelInterface;
use MulerTech\AlertingBundle\Channel\StandardErrorChannel;
use MulerTech\AlertingBundle\Probe\PoolProbeSender;
use Psr\Clock\ClockInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Sends a test alert through each declared channel, one by one rather than through the chain: in
 * a chain, the second channel only serves the day the first one falls, so without this its
 * configuration is first tried that day.
 *
 * When the worker heartbeat is watched, it also proves that the worker shares the pool of the web.
 * That proof alone, with `--probe-only`, suits each deployment: every test alert reaches a person,
 * and a text message is paid for.
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
        private readonly ?PoolProbeSender $probe = null,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('channel', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Tries only this channel; repeat the option for several')
            ->addOption('probe-only', null, InputOption::VALUE_NONE, 'Sends no test alert, and only proves that the worker shares the cache pool of the alerting');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        /** @var list<string> $chosen */
        $chosen = $input->getOption('channel');
        $probeOnly = (bool) $input->getOption('probe-only');

        if ($probeOnly && [] !== $chosen) {
            $io->error('--probe-only sends no test alert, and --channel chooses which channels receive one: give one option or the other.');

            return Command::INVALID;
        }

        if ($probeOnly && null === $this->probe) {
            $io->error('--probe-only needs the worker to be watched: declare mulertech_alerting.absence.worker.');

            return Command::INVALID;
        }

        $unknown = array_diff($chosen, array_keys($this->channels));

        if ([] !== $unknown) {
            $io->error(\sprintf('Unknown channel: %s. Declared: %s.', implode(', ', $unknown), implode(', ', array_keys($this->channels))));

            return Command::INVALID;
        }

        $refused = $probeOnly ? [] : $this->sendTestAlerts($io, $chosen);

        if (null !== $this->probe && [] === $chosen) {
            if ($this->probe->probe()) {
                $io->writeln(\sprintf('<info>✔</info> the worker of "%s" shares the cache pool of the alerting', $this->probe->transport));
            } else {
                $refused[] = 'pool probe';
                $io->writeln(\sprintf(
                    '<error>✘</error> pool probe: no answer from the worker of "%s" within %d seconds. Either the worker is stopped, or it does not share the cache pool of the alerting, and then it sends an alert saying so once it handles the probe.',
                    $this->probe->transport,
                    $this->probe->wait,
                ));
            }
        }

        if ([] !== $refused) {
            $io->error(\sprintf('Refused the test alert: %s.', implode(', ', $refused)));

            return Command::FAILURE;
        }

        $io->success($probeOnly ? 'The worker shares the cache pool of the alerting.' : 'Every channel tried accepted the test alert. Check that each one arrived.');

        return Command::SUCCESS;
    }

    /**
     * Standard error is tried with every channel, never when some are chosen.
     *
     * @param list<string> $chosen
     *
     * @return list<string> the channels that refused
     */
    private function sendTestAlerts(SymfonyStyle $io, array $chosen): array
    {
        $refused = [];

        foreach ($this->channels as $name => $channel) {
            if ([] !== $chosen && !\in_array($name, $chosen, true)) {
                continue;
            }

            try {
                $channel->send($this->delivery($name));
                $io->writeln(\sprintf('<info>✔</info> %s', $name));
            } catch (\Throwable $failure) {
                $refused[] = $name;
                $io->writeln(\sprintf('<error>✘</error> %s: %s: %s', $name, $failure::class, $failure->getMessage()));
            }
        }

        if ([] === $chosen) {
            $this->standardError->send($this->delivery('standard error'));
            $io->writeln('<info>✔</info> standard error, written to the error output of this process');
        }

        return $refused;
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
