<?php

declare(strict_types=1);

namespace MulerTech\AlertingBundle\Channel;

/**
 * The kind of message a Notifier transport takes: a chat service (Slack, Telegram, Mattermost…),
 * a text message, or a push service (ntfy, Gotify…).
 */
enum NotifierMessage: string
{
    case Chat = 'chat';
    case Sms = 'sms';
    case Push = 'push';
}
