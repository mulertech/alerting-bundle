<?php

declare(strict_types=1);

namespace MulerTech\AlertingBundle\Tests\Absence;

use MulerTech\AlertingBundle\Absence\AbsenceCheck;
use MulerTech\AlertingBundle\Absence\CheckOnTerminate;
use MulerTech\AlertingBundle\Absence\Ledger;
use MulerTech\AlertingBundle\Command\CheckCommand;
use MulerTech\AlertingBundle\Tests\Double\AlertingKit;
use MulerTech\AlertingBundle\Tests\Double\FailingPool;
use MulerTech\AlertingBundle\Tests\Double\ScriptedWatch;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\KernelEvent;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;

final class AbsenceCheckTest extends TestCase
{
    public function testEveryWatchRunsEvenWhenOneFails(): void
    {
        $kit = new AlertingKit();
        $broken = new ScriptedWatch('failure transport "failed"', new \RuntimeException('Connection refused'));
        $worker = new ScriptedWatch('worker heartbeat of "async"');

        $names = new AbsenceCheck([$broken, $worker], $kit->alerter, $kit->factory)->run();

        self::assertSame(['failure transport "failed"', 'worker heartbeat of "async"'], $names);
        self::assertSame(1, $worker->checks);
        self::assertSame('The check of the failure transport "failed" failed: RuntimeException: Connection refused', $kit->only()->alert->summary);
    }

    public function testTheCommandRunsTheCheckAndListsWhatItChecked(): void
    {
        $kit = new AlertingKit();
        $watch = new ScriptedWatch('worker heartbeat of "async"');
        $tester = new CommandTester(new CheckCommand(new AbsenceCheck([$watch], $kit->alerter, $kit->factory)));

        $tester->execute([]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertSame(1, $watch->checks);
        self::assertSame('Checked the worker heartbeat of "async"'.\PHP_EOL, $tester->getDisplay());
    }

    public function testItRunsAfterTheResponse(): void
    {
        self::assertSame([KernelEvents::TERMINATE => 'onTerminate'], CheckOnTerminate::getSubscribedEvents());
    }

    public function testRequestsRunTheCheckOncePerInterval(): void
    {
        $kit = new AlertingKit();
        $watch = new ScriptedWatch('worker heartbeat of "async"');
        $trigger = $this->trigger($kit, $watch, $kit->ledger);

        $trigger->onTerminate($this->terminate());
        $kit->clock->sleep(59);
        $trigger->onTerminate($this->terminate());
        self::assertSame(1, $watch->checks);

        $kit->clock->sleep(1);
        $trigger->onTerminate($this->terminate());
        self::assertSame(2, $watch->checks);
    }

    public function testASubRequestRunsNothing(): void
    {
        $kit = new AlertingKit();
        $watch = new ScriptedWatch('worker heartbeat of "async"');

        $this->trigger($kit, $watch, $kit->ledger)->onTerminate($this->subTerminate());

        self::assertSame(0, $watch->checks);
    }

    public function testAPacingThatCannotBeReadIsReportedAndRunsNothing(): void
    {
        $kit = new AlertingKit();
        $watch = new ScriptedWatch('worker heartbeat of "async"');

        $this->trigger($kit, $watch, new Ledger(new FailingPool(), $kit->clock))->onTerminate($this->terminate());

        self::assertSame(0, $watch->checks);
        self::assertSame('The absence check could not be paced: RuntimeException: Disk full', $kit->only()->alert->summary);
    }

    private function trigger(AlertingKit $kit, ScriptedWatch $watch, Ledger $ledger): CheckOnTerminate
    {
        return new CheckOnTerminate(new AbsenceCheck([$watch], $kit->alerter, $kit->factory), $ledger, $kit->alerter, $kit->factory, $kit->clock, 60);
    }

    private function terminate(): TerminateEvent
    {
        return new TerminateEvent($this->createStub(HttpKernelInterface::class), Request::create('/'), new Response());
    }

    /**
     * TerminateEvent is always built for the main request; a sub-request reaches the listener only
     * through an event that says otherwise.
     */
    private function subTerminate(): TerminateEvent
    {
        $event = $this->terminate();
        new \ReflectionProperty(KernelEvent::class, 'requestType')->setValue($event, HttpKernelInterface::SUB_REQUEST);

        return $event;
    }
}
