<?php

declare(strict_types=1);

namespace MulerTech\AlertingBundle\Tests\Source;

use MulerTech\AlertingBundle\Source\MessengerFailureSubscriber;
use MulerTech\AlertingBundle\Tests\Double\AlertingKit;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Exception\RunCommandFailedException;
use Symfony\Component\Console\Messenger\RunCommandContext;
use Symfony\Component\Console\Messenger\RunCommandMessage;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\Stamp\RedeliveryStamp;

final class MessengerFailureSubscriberTest extends TestCase
{
    public function testItClaimsAboveTheRetryListenerAndReportsBelowIt(): void
    {
        self::assertSame(
            [WorkerMessageFailedEvent::class => [['claim', 101], ['report', 99]]],
            MessengerFailureSubscriber::getSubscribedEvents(),
        );
    }

    public function testAnAbandonedMessageIsReportedWithTheCauseItsHandlerThrew(): void
    {
        $kit = new AlertingKit();
        $cause = new \RuntimeException('Valhalla timed out');
        $envelope = new Envelope(new \stdClass(), [new RedeliveryStamp(2)]);

        $this->handle($kit, new WorkerMessageFailedEvent($envelope, 'async', new HandlerFailedException($envelope, [$cause])));

        $alert = $kit->only()->alert;
        self::assertSame('messenger', $alert->source);
        self::assertSame('Message stdClass abandoned after 2 retries: RuntimeException: Valhalla timed out', $alert->summary);
        self::assertSame('stdClass', $alert->context['message']);
        self::assertSame('async', $alert->context['transport']);
        self::assertSame('2', $alert->context['retries']);
        self::assertSame('RuntimeException', $alert->context['exception']);
    }

    public function testAnExceptionThrownOutsideAHandlerIsReportedAsIs(): void
    {
        $kit = new AlertingKit();

        $this->handle($kit, new WorkerMessageFailedEvent(new Envelope(new \stdClass()), 'async', new \LogicException('Cannot decode')));

        self::assertSame('LogicException', $kit->only()->alert->context['exception']);
    }

    public function testAMessageThatWillBeRetriedIsNotReported(): void
    {
        $kit = new AlertingKit();
        $failure = new \RuntimeException('Valhalla timed out');
        $event = new WorkerMessageFailedEvent(new Envelope(new \stdClass()), 'async', $failure);
        $event->setForRetry();

        $this->handle($kit, $event);

        self::assertSame([], $kit->deliveries());
        self::assertSame(MessengerFailureSubscriber::OWNER, $kit->claims->ownerOf($failure), 'The retry warning is claimed all the same.');
    }

    public function testAFailedScheduledCommandIsLeftToTheConsoleSource(): void
    {
        $kit = new AlertingKit();
        $message = new RunCommandMessage('app:import');
        $envelope = new Envelope($message);
        $failure = new RunCommandFailedException('Command "app:import" exited with code "1".', new RunCommandContext($message, 1, ''));

        $this->handle($kit, new WorkerMessageFailedEvent($envelope, 'scheduler_default', new HandlerFailedException($envelope, [$failure])));

        self::assertSame([], $kit->deliveries());
    }

    public function testAnExceptionAnotherPathOwnsIsLeftToIt(): void
    {
        $kit = new AlertingKit();
        $failure = new \RuntimeException('Failure');
        $kit->claims->claim($failure, 'log');

        $this->handle($kit, new WorkerMessageFailedEvent(new Envelope(new \stdClass()), 'async', $failure));

        self::assertSame([], $kit->deliveries());
    }

    private function handle(AlertingKit $kit, WorkerMessageFailedEvent $event): void
    {
        $subscriber = new MessengerFailureSubscriber($kit->deferred, $kit->claims, $kit->factory);
        $subscriber->claim($event);
        $subscriber->report($event);
    }
}
