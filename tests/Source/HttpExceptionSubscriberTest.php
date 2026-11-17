<?php

declare(strict_types=1);

namespace MulerTech\AlertingBundle\Tests\Source;

use MulerTech\AlertingBundle\Alert\Severity;
use MulerTech\AlertingBundle\Source\HttpExceptionSubscriber;
use MulerTech\AlertingBundle\Tests\Double\AlertingKit;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\WithHttpStatus;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;

#[WithHttpStatus(409)]
class ConflictingOrder extends \RuntimeException
{
}

final class ConflictingOrderOnce extends ConflictingOrder
{
}

final class HttpExceptionSubscriberTest extends TestCase
{
    public function testItClaimsTheExceptionBeforeSymfonyLogsIt(): void
    {
        self::assertSame(
            [KernelEvents::EXCEPTION => ['onException', 1], KernelEvents::RESPONSE => ['onResponse', -1024]],
            HttpExceptionSubscriber::getSubscribedEvents(),
        );
    }

    public function testAServerErrorIsReportedWithTheRequestButNotItsQueryString(): void
    {
        $kit = new AlertingKit();
        $failure = new \RuntimeException('Payment refused');

        $this->subscriber($kit)->onException($this->event($failure, '/cart/checkout?token=secret'));

        $alert = $kit->only()->alert;
        self::assertSame(Severity::Critical, $alert->severity);
        self::assertSame('Request POST /cart/checkout answered 500: RuntimeException: Payment refused', $alert->summary);
        self::assertSame('500', $alert->context['status']);
        self::assertSame('POST', $alert->context['method']);
        self::assertSame('/cart/checkout', $alert->context['path']);
        self::assertSame('app_checkout', $alert->context['route']);
        self::assertStringNotContainsString('secret', implode(' ', $alert->context).$alert->summary);
        self::assertSame(HttpExceptionSubscriber::OWNER, $kit->claims->ownerOf($failure));
    }

    public function testAClientErrorNotDeclaredIsClaimedButNotReported(): void
    {
        $kit = new AlertingKit();
        $failure = new NotFoundHttpException('No route');

        $this->subscriber($kit)->onException($this->event($failure));

        self::assertSame([], $kit->deliveries());
        self::assertSame(HttpExceptionSubscriber::OWNER, $kit->claims->ownerOf($failure));
    }

    public function testADeclaredClientErrorIsReported(): void
    {
        $kit = new AlertingKit();

        $this->subscriber($kit, clientErrors: [403])->onException($this->event(new AccessDeniedHttpException('Denied')));

        self::assertSame(Severity::Error, $kit->only()->alert->severity);
        self::assertSame('403', $kit->only()->alert->context['status']);
    }

    public function testTheStatusDeclaredInTheFrameworkConfigurationComesFirst(): void
    {
        $kit = new AlertingKit();

        $this->subscriber($kit, statusCodes: [\DomainException::class => 422])->onException($this->event(new \DomainException('Invalid')));

        self::assertSame([], $kit->deliveries());
    }

    public function testTheStatusOfAnAttributeIsFoundOnAParentClass(): void
    {
        $kit = new AlertingKit();

        $this->subscriber($kit, clientErrors: [409])->onException($this->event(new ConflictingOrderOnce('Already paid')));

        self::assertSame('409', $kit->only()->alert->context['status']);
    }

    public function testAnExceptionAnotherPathOwnsIsLeftToIt(): void
    {
        $kit = new AlertingKit();
        $failure = new \Error('Allowed memory size exhausted');
        $kit->claims->claim($failure, 'log');

        $this->subscriber($kit)->onException($this->event($failure));

        self::assertSame([], $kit->deliveries());
    }

    public function testARequestWithoutRouteSaysSo(): void
    {
        $kit = new AlertingKit();
        $event = $this->event(new \RuntimeException('Failure'));
        $event->getRequest()->attributes->remove('_route');

        $this->subscriber($kit)->onException($event);

        self::assertSame('null', $kit->only()->alert->context['route']);
    }

    public function testAServerErrorBuiltWithoutExceptionIsReported(): void
    {
        $kit = new AlertingKit();

        $this->subscriber($kit)->onResponse($this->response(Request::create('/api/ai/chat', 'POST'), 503));

        $alert = $kit->only()->alert;
        self::assertSame(Severity::Critical, $alert->severity);
        self::assertSame('Request POST /api/ai/chat answered 503 with no exception: the code that built this response caught the failure without reporting it.', $alert->summary);
        self::assertSame('null', $alert->context['route']);
    }

    public function testTheErrorResponseOfAnExceptionAlreadySeenIsNotReportedAgain(): void
    {
        $kit = new AlertingKit();
        $subscriber = $this->subscriber($kit);
        $event = $this->event(new \RuntimeException('Payment refused'));

        $subscriber->onException($event);
        $subscriber->onResponse($this->response($event->getRequest(), 500));

        self::assertCount(1, $kit->deliveries());
    }

    public function testTheErrorResponseOfAnExceptionAnotherPathOwnsIsNotReported(): void
    {
        $kit = new AlertingKit();
        $subscriber = $this->subscriber($kit);
        $failure = new \Error('Allowed memory size exhausted');
        $kit->claims->claim($failure, 'log');
        $event = $this->event($failure);

        $subscriber->onException($event);
        $subscriber->onResponse($this->response($event->getRequest(), 500));

        self::assertSame([], $kit->deliveries());
    }

    public function testASuccessfulResponseOrASubRequestIsNotReported(): void
    {
        $kit = new AlertingKit();
        $subscriber = $this->subscriber($kit);

        $subscriber->onResponse($this->response(Request::create('/'), 200));
        $subscriber->onResponse($this->response(Request::create('/'), 500, HttpKernelInterface::SUB_REQUEST));

        self::assertSame([], $kit->deliveries());
    }

    public function testTheRouteOfAServerErrorResponseIsNamed(): void
    {
        $kit = new AlertingKit();
        $request = Request::create('/cart');
        $request->attributes->set('_route', 'app_cart');

        $this->subscriber($kit)->onResponse($this->response($request, 502));

        self::assertSame('app_cart', $kit->only()->alert->context['route']);
    }

    private function response(Request $request, int $status, int $type = HttpKernelInterface::MAIN_REQUEST): ResponseEvent
    {
        return new ResponseEvent($this->createStub(HttpKernelInterface::class), $request, $type, new Response('', $status));
    }

    /**
     * @param list<int>          $clientErrors
     * @param array<string, int> $statusCodes
     */
    private function subscriber(AlertingKit $kit, array $clientErrors = [], array $statusCodes = []): HttpExceptionSubscriber
    {
        return new HttpExceptionSubscriber($kit->alerter, $kit->claims, $kit->factory, $clientErrors, $statusCodes);
    }

    private function event(\Throwable $throwable, string $uri = '/cart/checkout'): ExceptionEvent
    {
        $request = Request::create($uri, 'POST');
        $request->attributes->set('_route', 'app_checkout');

        return new ExceptionEvent($this->createStub(HttpKernelInterface::class), $request, HttpKernelInterface::MAIN_REQUEST, $throwable);
    }
}
