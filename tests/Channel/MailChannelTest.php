<?php

declare(strict_types=1);

namespace MulerTech\AlertingBundle\Tests\Channel;

use MulerTech\AlertingBundle\Alert\Alert;
use MulerTech\AlertingBundle\Alert\Delivery;
use MulerTech\AlertingBundle\Alert\Severity;
use MulerTech\AlertingBundle\Channel\MailChannel;
use MulerTech\AlertingBundle\Format\TextFormatter;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\RawMessage;

final class MailChannelTest extends TestCase
{
    public function testTheMailCarriesTheAlertFromTheSenderToEveryRecipient(): void
    {
        $transport = $this->transport();

        $this->channel($transport)->send($this->delivery());

        $email = $transport->sent[0] ?? null;
        self::assertInstanceOf(Email::class, $email);
        self::assertSame(['alerts@shop.test'], array_map(static fn (Address $a) => $a->getAddress(), $email->getFrom()));
        self::assertSame(['ops@shop.test', 'dev@shop.test'], array_map(static fn (Address $a) => $a->getAddress(), $email->getTo()));
        self::assertSame('[shop-prod] Error: Uncaught RuntimeException', $email->getSubject());
        self::assertSame(new TextFormatter()->body($this->delivery()), $email->getTextBody());
    }

    public function testTheTransportWaitsNoLongerThanTheDeclaredTimeout(): void
    {
        $transport = $this->transport();

        $this->channel($transport)->send($this->delivery());

        self::assertSame('7', $transport->timeoutDuringSend);
    }

    public function testAMailRejectedByAListenerIsARefusal(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('The mail transport "recording://" did not send the alert: a listener of the mailer rejected it.');

        $this->channel($this->transport(rejects: true))->send($this->delivery());
    }

    public function testAFailureOfTheTransportReachesTheChain(): void
    {
        $transport = $this->transport(failure: new \RuntimeException('Connection could not be established'));

        $this->expectExceptionMessage('Connection could not be established');

        $this->channel($transport)->send($this->delivery());
    }

    private function channel(TransportInterface $transport): MailChannel
    {
        return new MailChannel($transport, 'alerts@shop.test', ['ops@shop.test', 'dev@shop.test'], new TextFormatter(), 7);
    }

    /**
     * @return TransportInterface&object{sent: list<RawMessage>, timeoutDuringSend: string|false|null}
     */
    private function transport(bool $rejects = false, ?\Throwable $failure = null): TransportInterface
    {
        return new class($rejects, $failure) implements TransportInterface {
            /**
             * @var list<RawMessage>
             */
            public array $sent = [];

            public string|false|null $timeoutDuringSend = null;

            public function __construct(
                private readonly bool $rejects,
                private readonly ?\Throwable $failure,
            ) {
            }

            public function send(RawMessage $message, ?Envelope $envelope = null): ?SentMessage
            {
                $this->timeoutDuringSend = \ini_get('default_socket_timeout');

                if (null !== $this->failure) {
                    throw $this->failure;
                }

                $this->sent[] = $message;

                return $this->rejects ? null : new SentMessage($message, $envelope ?? Envelope::create($message));
            }

            public function __toString(): string
            {
                return 'recording://';
            }
        };
    }

    private function delivery(): Delivery
    {
        return new Delivery(
            'shop-prod',
            new Alert(Severity::Error, 'http', 'Uncaught RuntimeException', []),
            new \DateTimeImmutable('2026-11-16 10:00:00', new \DateTimeZone('UTC')),
        );
    }
}
