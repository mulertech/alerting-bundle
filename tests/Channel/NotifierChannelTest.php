<?php

declare(strict_types=1);

namespace MulerTech\AlertingBundle\Tests\Channel;

use MulerTech\AlertingBundle\Alert\Alert;
use MulerTech\AlertingBundle\Alert\Delivery;
use MulerTech\AlertingBundle\Alert\Severity;
use MulerTech\AlertingBundle\Channel\NotifierChannel;
use MulerTech\AlertingBundle\Channel\NotifierMessage;
use MulerTech\AlertingBundle\Format\TextFormatter;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Notifier\Message\ChatMessage;
use Symfony\Component\Notifier\Message\MessageInterface;
use Symfony\Component\Notifier\Message\PushMessage;
use Symfony\Component\Notifier\Message\SentMessage;
use Symfony\Component\Notifier\Message\SmsMessage;
use Symfony\Component\Notifier\Transport\TransportInterface;

final class NotifierChannelTest extends TestCase
{
    public function testAChatMessageCarriesTheSubjectAndTheWholeBody(): void
    {
        $transport = $this->transport();

        new NotifierChannel($transport, NotifierMessage::Chat, new TextFormatter(), 7)->send($this->delivery());

        $message = $transport->sent[0] ?? null;
        self::assertInstanceOf(ChatMessage::class, $message);
        self::assertSame($this->subject()."\n\n".$this->body(), $message->getSubject());
    }

    public function testAPushMessageCarriesTheSubjectAsTitleAndTheBodyAsContent(): void
    {
        $transport = $this->transport();

        new NotifierChannel($transport, NotifierMessage::Push, new TextFormatter(), 7)->send($this->delivery());

        $message = $transport->sent[0] ?? null;
        self::assertInstanceOf(PushMessage::class, $message);
        self::assertSame($this->subject(), $message->getSubject());
        self::assertSame($this->body(), $message->getContent());
    }

    public function testATextMessageCarriesTheSubjectAloneToTheDeclaredPhone(): void
    {
        $transport = $this->transport();

        new NotifierChannel($transport, NotifierMessage::Sms, new TextFormatter(), 7, '+33600000000')->send($this->delivery());

        $message = $transport->sent[0] ?? null;
        self::assertInstanceOf(SmsMessage::class, $message);
        self::assertSame('+33600000000', $message->getPhone());
        self::assertSame($this->subject(), $message->getSubject());
    }

    public function testTextMessagesNeedAPhone(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('A Notifier channel sending text messages needs the phone number to send them to.');

        new NotifierChannel($this->transport(), NotifierMessage::Sms, new TextFormatter(), 7);
    }

    public function testTheTransportWaitsNoLongerThanTheDeclaredTimeout(): void
    {
        $transport = $this->transport();

        new NotifierChannel($transport, NotifierMessage::Chat, new TextFormatter(), 7)->send($this->delivery());

        self::assertSame('7', $transport->timeoutDuringSend);
    }

    public function testATransportThatDoesNotTakeTheKindOfMessageSaysWhichKindsExist(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('The Notifier transport "recording://" does not take chat messages. Accepted kinds: chat, sms, push.');

        new NotifierChannel($this->transport(supports: false), NotifierMessage::Chat, new TextFormatter(), 7)->send($this->delivery());
    }

    public function testATransportThatSendsNothingIsARefusal(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('The Notifier transport "recording://" did not send the alert.');

        new NotifierChannel($this->transport(sends: false), NotifierMessage::Chat, new TextFormatter(), 7)->send($this->delivery());
    }

    /**
     * @return TransportInterface&object{sent: list<MessageInterface>, timeoutDuringSend: string|false|null}
     */
    private function transport(bool $supports = true, bool $sends = true): TransportInterface
    {
        return new class($supports, $sends) implements TransportInterface {
            /**
             * @var list<MessageInterface>
             */
            public array $sent = [];

            public string|false|null $timeoutDuringSend = null;

            public function __construct(
                private readonly bool $supports,
                private readonly bool $sends,
            ) {
            }

            public function send(MessageInterface $message): ?SentMessage
            {
                $this->timeoutDuringSend = \ini_get('default_socket_timeout');
                $this->sent[] = $message;

                return $this->sends ? new SentMessage($message, 'recording') : null;
            }

            public function supports(MessageInterface $message): bool
            {
                return $this->supports;
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
            new Alert(Severity::Error, 'messenger', 'Message App\Import abandoned', []),
            new \DateTimeImmutable('2026-11-16 10:00:00', new \DateTimeZone('UTC')),
        );
    }

    private function subject(): string
    {
        return new TextFormatter()->subject($this->delivery());
    }

    private function body(): string
    {
        return new TextFormatter()->body($this->delivery());
    }
}
