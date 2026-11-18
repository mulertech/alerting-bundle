<?php

declare(strict_types=1);

namespace MulerTech\AlertingBundle\Tests\Absence;

use MulerTech\AlertingBundle\Absence\FailureQueueWatch;
use MulerTech\AlertingBundle\Tests\Double\AlertingKit;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\Receiver\MessageCountAwareInterface;
use Symfony\Component\Messenger\Transport\Receiver\ReceiverInterface;

final class FailureQueueWatchTest extends TestCase
{
    public function testBelowTheThresholdNothingIsReported(): void
    {
        $kit = new AlertingKit();

        $this->watch($kit, $this->queue(2))->check();

        self::assertSame([], $kit->deliveries());
    }

    public function testFromTheThresholdTheQueueIsReportedWithWhatToDo(): void
    {
        $kit = new AlertingKit();

        $this->watch($kit, $this->queue(3))->check();

        $alert = $kit->only()->alert;
        self::assertSame('3 messages wait in the failure transport "failed" (threshold: 3): see messenger:failed:show, then retry or remove them.', $alert->summary);
        self::assertSame('3', $alert->context['messages']);
    }

    public function testAGrowingQueueIsOneFault(): void
    {
        $kit = new AlertingKit();

        $this->watch($kit, $this->queue(3))->check();
        $this->watch($kit, $this->queue(4))->check();

        self::assertCount(1, $kit->deliveries());
    }

    public function testATransportThatCannotCountSaysWhichOnesCan(): void
    {
        $kit = new AlertingKit();

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('The transport "failed" cannot count its messages: declare a failure transport whose receiver implements Symfony\Component\Messenger\Transport\Receiver\MessageCountAwareInterface, such as Doctrine or Redis.');

        $this->watch($kit, $this->createStub(ReceiverInterface::class))->check();
    }

    public function testTheWatchIsNamedAfterItsTransport(): void
    {
        self::assertSame('failure transport "failed"', $this->watch(new AlertingKit(), $this->queue(0))->name());
    }

    private function watch(AlertingKit $kit, ReceiverInterface $receiver): FailureQueueWatch
    {
        return new FailureQueueWatch(new ServiceLocator(['failed' => static fn () => $receiver]), $kit->deferred, 'failed', 3);
    }

    private function queue(int $messages): ReceiverInterface
    {
        return new class($messages) implements ReceiverInterface, MessageCountAwareInterface {
            public function __construct(private readonly int $messages)
            {
            }

            public function get(): iterable
            {
                return [];
            }

            public function ack(Envelope $envelope): void
            {
            }

            public function reject(Envelope $envelope): void
            {
            }

            public function getMessageCount(): int
            {
                return $this->messages;
            }
        };
    }
}
