<?php

declare(strict_types=1);

namespace MulerTech\AlertingBundle\Source;

use MulerTech\AlertingBundle\Alert\Severity;
use MulerTech\AlertingBundle\Alerter;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Attribute\WithHttpStatus;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Reports the exceptions a request ends on: every 5xx, and the 4xx the application declares.
 *
 * It runs just above Symfony's own logging (priority 0), so that it sees what Symfony logs and
 * nothing a listener above turned into a response, and claims every exception, reported or not.
 *
 * The context carries the method, the path and the route. Never the query string, which carries
 * tokens (password reset, email verification), nor the user or the address: the alert leaves for
 * a third party.
 */
final readonly class HttpExceptionSubscriber implements EventSubscriberInterface
{
    public const string OWNER = 'http';

    /**
     * @param list<int>          $clientErrors the 4xx to report
     * @param array<string, int> $statusCodes  exception class => status code, as `framework.exceptions` declares them
     */
    public function __construct(
        private Alerter $alerter,
        private ThrowableClaims $claims,
        private ThrowableAlertFactory $factory,
        private array $clientErrors = [],
        private array $statusCodes = [],
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::EXCEPTION => ['onException', 1]];
    }

    public function onException(ExceptionEvent $event): void
    {
        $throwable = $event->getThrowable();

        if (!$this->claims->claim($throwable, self::OWNER)) {
            return;
        }

        $status = $this->status($throwable);

        if ($status < 500 && !\in_array($status, $this->clientErrors, true)) {
            return;
        }

        $request = $event->getRequest();
        $route = $request->attributes->get('_route');

        $this->alerter->alert($this->factory->create(
            'http',
            $status >= 500 ? Severity::Critical : Severity::Error,
            \sprintf('Request %s %s answered %d', $request->getMethod(), $request->getPathInfo(), $status),
            $throwable,
            [
                'status' => $status,
                'method' => $request->getMethod(),
                'path' => $request->getPathInfo(),
                'route' => \is_string($route) ? $route : null,
            ],
            [(string) $status],
        ));
    }

    private function status(\Throwable $throwable): int
    {
        foreach ($this->statusCodes as $class => $status) {
            if ($throwable instanceof $class) {
                return $status;
            }
        }

        if ($throwable instanceof HttpExceptionInterface) {
            return $throwable->getStatusCode();
        }

        for ($class = new \ReflectionClass($throwable); false !== $class; $class = $class->getParentClass()) {
            foreach ($class->getAttributes(WithHttpStatus::class) as $attribute) {
                return $attribute->newInstance()->statusCode;
            }
        }

        return 500;
    }
}
