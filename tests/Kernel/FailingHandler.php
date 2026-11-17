<?php

declare(strict_types=1);

namespace MulerTech\AlertingBundle\Tests\Kernel;

/**
 * Handles the `\ArrayObject` messages of the test application, and fails each one.
 */
final class FailingHandler
{
    public function __invoke(\ArrayObject $message): never
    {
        throw new \RuntimeException('The payment provider refused the refund');
    }
}
