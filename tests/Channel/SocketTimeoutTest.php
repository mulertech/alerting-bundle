<?php

declare(strict_types=1);

namespace MulerTech\AlertingBundle\Tests\Channel;

use MulerTech\AlertingBundle\Channel\SocketTimeout;
use PHPUnit\Framework\TestCase;

final class SocketTimeoutTest extends TestCase
{
    private string $initial;

    protected function setUp(): void
    {
        $this->initial = (string) \ini_get('default_socket_timeout');
        ini_set('default_socket_timeout', '60');
    }

    protected function tearDown(): void
    {
        ini_set('default_socket_timeout', $this->initial);
    }

    public function testTheSendRunsUnderTheBoundAndItsResultIsReturned(): void
    {
        $result = SocketTimeout::within(5, static fn () => \ini_get('default_socket_timeout'));

        self::assertSame('5', $result);
        self::assertSame('60', \ini_get('default_socket_timeout'));
    }

    public function testThePreviousTimeoutComesBackWhenTheSendFails(): void
    {
        try {
            SocketTimeout::within(5, static fn () => throw new \RuntimeException('Connection refused'));
            self::fail('The failure of the send must reach the caller.');
        } catch (\RuntimeException $failure) {
            self::assertSame('Connection refused', $failure->getMessage());
        }

        self::assertSame('60', \ini_get('default_socket_timeout'));
    }
}
