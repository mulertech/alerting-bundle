<?php

declare(strict_types=1);

namespace MulerTech\AlertingBundle\Channel;

use MulerTech\AlertingBundle\Alert\Delivery;
use MulerTech\AlertingBundle\Format\TextFormatter;
use Symfony\Component\Notifier\Message\ChatMessage;
use Symfony\Component\Notifier\Message\MessageInterface;
use Symfony\Component\Notifier\Message\PushMessage;
use Symfony\Component\Notifier\Message\SmsMessage;
use Symfony\Component\Notifier\Transport\TransportInterface;

/**
 * Sends the alert through a Notifier transport of its own, never through the Chatter or the
 * Texter services, which hand their messages to Messenger when the application routes them there.
 */
final readonly class NotifierChannel implements ChannelInterface
{
    public function __construct(
        private TransportInterface $transport,
        private NotifierMessage $message,
        private TextFormatter $formatter,
        private int $timeout,
        private ?string $phone = null,
    ) {
        if (NotifierMessage::Sms === $message && (null === $phone || '' === $phone)) {
            throw new \InvalidArgumentException('A Notifier channel sending text messages needs the phone number to send them to.');
        }
    }

    public function send(Delivery $delivery): void
    {
        $message = $this->message($delivery);

        if (!$this->transport->supports($message)) {
            throw new \RuntimeException(\sprintf('The Notifier transport "%s" does not take %s messages. Accepted kinds: %s.', $this->transport, $this->message->value, implode(', ', array_column(NotifierMessage::cases(), 'value'))));
        }

        $sent = SocketTimeout::within($this->timeout, fn () => $this->transport->send($message));

        if (null === $sent) {
            throw new \RuntimeException(\sprintf('The Notifier transport "%s" did not send the alert.', $this->transport));
        }
    }

    private function message(Delivery $delivery): MessageInterface
    {
        $subject = $this->formatter->subject($delivery);

        return match ($this->message) {
            NotifierMessage::Chat => new ChatMessage($subject."\n\n".$this->formatter->body($delivery)),
            NotifierMessage::Sms => new SmsMessage((string) $this->phone, $subject),
            NotifierMessage::Push => new PushMessage($subject, $this->formatter->body($delivery)),
        };
    }
}
