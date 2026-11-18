<?php

declare(strict_types=1);

namespace MulerTech\AlertingBundle\Source;

use MulerTech\AlertingBundle\Alert\Alert;
use MulerTech\AlertingBundle\Alert\Severity;
use MulerTech\AlertingBundle\DeferredAlerter;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\Console\Event\ConsoleErrorEvent;
use Symfony\Component\Console\Event\ConsoleTerminateEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Reports a command that exits with a non-zero code, with or without an exception.
 *
 * The exception is claimed above Symfony's console error listener (priority -128), which logs it
 * at `critical`, and kept until the command terminates. The errors are kept per command because a
 * worker runs scheduled commands within its own command. An error raised before any command ran
 * (an unknown command name) is reported at once, since no command terminates after it.
 *
 * The commands declared as status commands exit with a code that reports a state, not a failure
 * (`doctrine:migrations:up-to-date` answers 1 until the migrations ran): only a thrown error is
 * reported for them. Only the name of a command is reported, never its arguments, which may carry
 * personal data.
 */
final class ConsoleFailureSubscriber implements EventSubscriberInterface, ResetInterface
{
    public const string OWNER = 'console';

    /**
     * @var \WeakMap<Command, \Throwable>
     */
    private \WeakMap $errors;

    /**
     * @param list<string> $statusCommands
     */
    public function __construct(
        private readonly DeferredAlerter $alerter,
        private readonly ThrowableClaims $claims,
        private readonly ThrowableAlertFactory $factory,
        private readonly array $statusCommands = [],
    ) {
        $this->errors = new \WeakMap();
    }

    public static function getSubscribedEvents(): array
    {
        return [
            ConsoleEvents::ERROR => ['onError', -127],
            ConsoleEvents::TERMINATE => ['onTerminate', -127],
        ];
    }

    public function onError(ConsoleErrorEvent $event): void
    {
        $error = $event->getError();

        if (!$this->claims->claim($error, self::OWNER)) {
            return;
        }

        $command = $event->getCommand();

        if (null !== $command) {
            $this->errors[$command] = $error;

            return;
        }

        $name = $event->getInput()->getFirstArgument() ?? 'console';
        $this->alerter->alert($this->factory->create(
            'console',
            Severity::Error,
            \sprintf('Console "%s" failed before any command ran', $name),
            $error,
            ['command' => $name],
            [$name],
        ));
    }

    public function onTerminate(ConsoleTerminateEvent $event): void
    {
        $command = $event->getCommand();

        // ConsoleEvent types the command as nullable; the console dispatches this event with one only.
        if (null === $command) {
            return;
        }

        $error = $this->errors[$command] ?? null;
        unset($this->errors[$command]);
        $exitCode = $event->getExitCode();

        if (0 === $exitCode) {
            return;
        }

        $name = $command->getName() ?? $command::class;

        if (null === $error && \in_array($name, $this->statusCommands, true)) {
            return;
        }

        $context = ['command' => $name, 'exit_code' => $exitCode];
        $identity = [$name, (string) $exitCode];

        $this->alerter->alert(null === $error
            ? new Alert(Severity::Error, 'console', \sprintf('Command "%s" exited with code %d', $name, $exitCode), $identity, $context)
            : $this->factory->create('console', Severity::Error, \sprintf('Command "%s" failed with code %d', $name, $exitCode), $error, $context, $identity));
    }

    public function reset(): void
    {
        $this->errors = new \WeakMap();
    }
}
