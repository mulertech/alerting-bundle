<?php

declare(strict_types=1);

namespace MulerTech\AlertingBundle\Tests\Source;

use MulerTech\AlertingBundle\Source\ConsoleFailureSubscriber;
use MulerTech\AlertingBundle\Tests\Double\AlertingKit;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\Console\Event\ConsoleErrorEvent;
use Symfony\Component\Console\Event\ConsoleTerminateEvent;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Output\NullOutput;

final class ConsoleFailureSubscriberTest extends TestCase
{
    public function testItClaimsAboveTheConsoleErrorListener(): void
    {
        self::assertSame(
            [ConsoleEvents::ERROR => ['onError', -127], ConsoleEvents::TERMINATE => ['onTerminate', -127]],
            ConsoleFailureSubscriber::getSubscribedEvents(),
        );
    }

    public function testACommandThatSucceedsIsNotReported(): void
    {
        $kit = new AlertingKit();

        $this->subscriber($kit)->onTerminate($this->terminate(new Command('app:import'), 0));

        self::assertSame([], $kit->deliveries());
    }

    public function testANonZeroExitWithoutExceptionIsReported(): void
    {
        $kit = new AlertingKit();

        $this->subscriber($kit)->onTerminate($this->terminate(new Command('app:import'), 2));

        $alert = $kit->only()->alert;
        self::assertSame('console', $alert->source);
        self::assertSame('Command "app:import" exited with code 2', $alert->summary);
        self::assertSame(['command' => 'app:import', 'exit_code' => '2'], $alert->context);
    }

    public function testAThrownErrorIsReportedWithTheCommand(): void
    {
        $kit = new AlertingKit();
        $subscriber = $this->subscriber($kit);
        $command = new Command('app:import');
        $failure = new \RuntimeException('File missing');

        $subscriber->onError($this->error($failure, $command));
        $subscriber->onTerminate($this->terminate($command, 1));

        self::assertSame('Command "app:import" failed with code 1: RuntimeException: File missing', $kit->only()->alert->summary);
        self::assertSame(ConsoleFailureSubscriber::OWNER, $kit->claims->ownerOf($failure));
    }

    public function testAStatusCommandIsReportedOnlyForAThrownError(): void
    {
        $kit = new AlertingKit();
        $subscriber = $this->subscriber($kit, ['doctrine:migrations:up-to-date']);
        $command = new Command('doctrine:migrations:up-to-date');

        $subscriber->onTerminate($this->terminate($command, 1));
        self::assertSame([], $kit->deliveries());

        $subscriber->onError($this->error(new \RuntimeException('Connection refused'), $command));
        $subscriber->onTerminate($this->terminate($command, 1));
        self::assertCount(1, $kit->deliveries());
    }

    public function testACommandRunWithinAnotherKeepsItsOwnError(): void
    {
        $kit = new AlertingKit();
        $subscriber = $this->subscriber($kit);
        $worker = new Command('messenger:consume');
        $scheduled = new Command('app:import');

        $subscriber->onError($this->error(new \RuntimeException('File missing'), $scheduled));
        $subscriber->onTerminate($this->terminate($scheduled, 1));
        $subscriber->onTerminate($this->terminate($worker, 0));

        self::assertCount(1, $kit->deliveries());
        self::assertStringStartsWith('Command "app:import"', $kit->only()->alert->summary);
    }

    public function testAnErrorAnotherPathOwnsIsNotKept(): void
    {
        $kit = new AlertingKit();
        $subscriber = $this->subscriber($kit);
        $command = new Command('app:import');
        $failure = new \RuntimeException('File missing');
        $kit->claims->claim($failure, 'log');

        $subscriber->onError($this->error($failure, $command));
        $subscriber->onTerminate($this->terminate($command, 1));

        self::assertSame('Command "app:import" exited with code 1', $kit->only()->alert->summary);
    }

    public function testAnErrorBeforeAnyCommandRanIsReportedAtOnce(): void
    {
        $kit = new AlertingKit();
        $definition = new InputDefinition([new InputArgument('command')]);

        $this->subscriber($kit)->onError(new ConsoleErrorEvent(new ArrayInput(['command' => 'app:imprt'], $definition), new NullOutput(), new \InvalidArgumentException('Command "app:imprt" is not defined.')));

        self::assertSame('Console "app:imprt" failed before any command ran: InvalidArgumentException: Command "app:imprt" is not defined.', $kit->only()->alert->summary);
    }

    public function testAnErrorBeforeAnyCommandWithNoNameIsNamedAfterTheConsole(): void
    {
        $kit = new AlertingKit();

        $this->subscriber($kit)->onError(new ConsoleErrorEvent(new ArrayInput([]), new NullOutput(), new \RuntimeException('Kernel failed')));

        self::assertSame('console', $kit->only()->alert->context['command']);
    }

    public function testResetForgetsTheKeptErrors(): void
    {
        $kit = new AlertingKit();
        $subscriber = $this->subscriber($kit);
        $command = new Command('app:import');
        $subscriber->onError($this->error(new \RuntimeException('File missing'), $command));

        $subscriber->reset();
        $subscriber->onTerminate($this->terminate($command, 1));

        self::assertSame('Command "app:import" exited with code 1', $kit->only()->alert->summary);
    }

    public function testACommandWithoutNameIsNamedAfterItsClass(): void
    {
        $kit = new AlertingKit();

        $this->subscriber($kit)->onTerminate($this->terminate(new Command(), 1));

        self::assertSame(Command::class, $kit->only()->alert->context['command']);
    }

    /**
     * @param list<string> $statusCommands
     */
    private function subscriber(AlertingKit $kit, array $statusCommands = []): ConsoleFailureSubscriber
    {
        return new ConsoleFailureSubscriber($kit->deferred, $kit->claims, $kit->factory, $statusCommands);
    }

    private function error(\Throwable $error, Command $command): ConsoleErrorEvent
    {
        return new ConsoleErrorEvent(new ArrayInput([]), new NullOutput(), $error, $command);
    }

    private function terminate(Command $command, int $exitCode): ConsoleTerminateEvent
    {
        return new ConsoleTerminateEvent($command, new ArrayInput([]), new NullOutput(), $exitCode);
    }
}
