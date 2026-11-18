<?php

declare(strict_types=1);

namespace MulerTech\AlertingBundle\Tests\Kernel;

use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Response;

/**
 * A controller that catches its failure, logs it with its cause, and answers 503 itself, as an API
 * does to give its callers an explicit code.
 */
final readonly class ReportingController
{
    public function __construct(
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(): Response
    {
        $this->logger->error('Message for the conversation "{conversation}" not stored', [
            'conversation' => 'Monitoring',
            'exception' => new \RuntimeException('SQLSTATE[08006] connection refused'),
        ]);

        return new Response('', Response::HTTP_SERVICE_UNAVAILABLE);
    }
}
