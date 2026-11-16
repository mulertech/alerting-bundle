<?php

declare(strict_types=1);

namespace MulerTech\AlertingBundle\Channel;

use MulerTech\AlertingBundle\Alert\Delivery;
use MulerTech\AlertingBundle\Format\TextFormatter;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\Email;

/**
 * Sends the alert through a mail transport of its own, never through the Mailer service: an
 * application routing its mails to a Messenger queue would put the alert in that queue, which
 * often lives in the very database whose failure the alert reports.
 */
final readonly class MailChannel implements ChannelInterface
{
    /**
     * @param non-empty-list<string> $recipients
     */
    public function __construct(
        private TransportInterface $transport,
        private string $sender,
        private array $recipients,
        private TextFormatter $formatter,
        private int $timeout,
    ) {
    }

    public function send(Delivery $delivery): void
    {
        $email = new Email()
            ->from($this->sender)
            ->to(...$this->recipients)
            ->subject($this->formatter->subject($delivery))
            ->text($this->formatter->body($delivery));

        $sent = SocketTimeout::within($this->timeout, fn () => $this->transport->send($email));

        if (null === $sent) {
            throw new \RuntimeException(\sprintf('The mail transport "%s" did not send the alert: a listener of the mailer rejected it.', $this->transport));
        }
    }
}
