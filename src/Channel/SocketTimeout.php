<?php

declare(strict_types=1);

namespace MulerTech\AlertingBundle\Channel;

/**
 * Bounds the network wait of a send. Mailer sockets and HTTP clients both fall back on
 * `default_socket_timeout` when nothing else sets their timeout, and it is 60 seconds by default:
 * a mail server that does not answer would hold the request, and the next channel, that long.
 *
 * The transports of the bundle are its own instances, opened within this bound, so the setting
 * reaches them without touching the timeouts of the application's own mails.
 */
final class SocketTimeout
{
    /**
     * @template T
     *
     * @param \Closure(): T $send
     *
     * @return T
     */
    public static function within(int $seconds, \Closure $send): mixed
    {
        $previous = (string) \ini_get('default_socket_timeout');
        ini_set('default_socket_timeout', (string) $seconds);

        try {
            return $send();
        } finally {
            ini_set('default_socket_timeout', $previous);
        }
    }
}
