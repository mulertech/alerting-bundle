<?php

declare(strict_types=1);

namespace MulerTech\AlertingBundle\Tests\Source;

use MulerTech\AlertingBundle\Source\ThrowableClaims;
use PHPUnit\Framework\TestCase;

final class ThrowableClaimsTest extends TestCase
{
    public function testTheFirstPathOwnsTheException(): void
    {
        $claims = new ThrowableClaims();
        $failure = new \RuntimeException('Failure');

        self::assertTrue($claims->claim($failure, 'http'));
        self::assertFalse($claims->claim($failure, 'log'));
        self::assertSame('http', $claims->ownerOf($failure));
    }

    public function testAWrapperOfAClaimedExceptionIsClaimed(): void
    {
        $claims = new ThrowableClaims();
        $failure = new \RuntimeException('Failure');
        $claims->claim($failure, 'http');

        $wrapper = new \LogicException('Wrapped', 0, $failure);

        self::assertSame('http', $claims->ownerOf($wrapper));
        self::assertFalse($claims->claim($wrapper, 'log'));
    }

    public function testAnExceptionNobodyClaimedHasNoOwner(): void
    {
        self::assertNull(new ThrowableClaims()->ownerOf(new \RuntimeException('Failure')));
    }

    public function testResetForgetsEveryClaim(): void
    {
        $claims = new ThrowableClaims();
        $failure = new \RuntimeException('Failure');
        $claims->claim($failure, 'http');

        $claims->reset();

        self::assertNull($claims->ownerOf($failure));
    }
}
