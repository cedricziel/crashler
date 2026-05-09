<?php

declare(strict_types=1);

namespace App\Tests\Unit\Read\Http;

use App\Read\Http\ReadResponseConventionsListener;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * Unit tests for the read-side request guards.
 *
 * Functional-level coverage of these via zenstruck/browser is awkward
 * because Symfony's BrowserKit collapses duplicate query parameters
 * before they reach the kernel.request listener (a test-client
 * artifact — real HTTP requests preserve them). We exercise the listener
 * directly here.
 */
#[CoversClass(ReadResponseConventionsListener::class)]
final class ReadResponseConventionsListenerTest extends TestCase
{
    private ReadResponseConventionsListener $listener;
    private HttpKernelInterface $kernel;

    protected function setUp(): void
    {
        $this->listener = new ReadResponseConventionsListener(maxAttributeFilters: 5);
        $this->kernel = $this->createStub(HttpKernelInterface::class);
    }

    public function testRepeatedQueryParamSetsBadRequestResponse(): void
    {
        $request = Request::create('/v1/logs?since=1h&service=foo&service=bar', 'GET');
        $event = new RequestEvent($this->kernel, $request, HttpKernelInterface::MAIN_REQUEST);

        $this->listener->onKernelRequest($event);

        $response = $event->getResponse();
        self::assertInstanceOf(JsonResponse::class, $response);
        self::assertSame(400, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true);
        self::assertStringContainsString('service', $body['message']);
        self::assertStringContainsString('multiple times', $body['message']);
    }

    public function testSingleOccurrenceQueryParamPasses(): void
    {
        $request = Request::create('/v1/logs?since=1h&service=foo', 'GET');
        $event = new RequestEvent($this->kernel, $request, HttpKernelInterface::MAIN_REQUEST);

        $this->listener->onKernelRequest($event);

        self::assertNull($event->getResponse(), 'no short-circuit for unique params');
    }

    public function testRepeatedSinceParamSetsBadRequestResponse(): void
    {
        $request = Request::create('/v1/logs?since=1h&since=2h', 'GET');
        $event = new RequestEvent($this->kernel, $request, HttpKernelInterface::MAIN_REQUEST);

        $this->listener->onKernelRequest($event);

        $response = $event->getResponse();
        self::assertInstanceOf(JsonResponse::class, $response);
        self::assertSame(400, $response->getStatusCode());
    }

    public function testGetWithBodyReturns415(): void
    {
        $request = Request::create('/v1/logs?since=1h', 'GET');
        $request->headers->set('Content-Length', '5');
        $event = new RequestEvent($this->kernel, $request, HttpKernelInterface::MAIN_REQUEST);

        $this->listener->onKernelRequest($event);

        $response = $event->getResponse();
        self::assertInstanceOf(JsonResponse::class, $response);
        self::assertSame(415, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true);
        self::assertSame('Read endpoints take no request body.', $body['message']);
    }

    public function testNonReadPathPasses(): void
    {
        // POST to write endpoint should pass through untouched.
        $request = Request::create('/v1/logs?service=a&service=b', 'POST');
        $event = new RequestEvent($this->kernel, $request, HttpKernelInterface::MAIN_REQUEST);

        $this->listener->onKernelRequest($event);

        self::assertNull($event->getResponse(), 'POST is not a read path; listener must not short-circuit');
    }

    public function testMultipleDistinctAttributeFiltersAccepted(): void
    {
        // Up to the configured cap (5) of distinct attribute keys composes.
        $request = Request::create('/v1/logs?since=1h&attribute.exception.type=A&attribute.foo=B', 'GET');
        $event = new RequestEvent($this->kernel, $request, HttpKernelInterface::MAIN_REQUEST);

        $this->listener->onKernelRequest($event);

        self::assertNull($event->getResponse(), 'two distinct attribute keys must compose, not 400');
    }

    public function testAttributeFilterCapExceeded(): void
    {
        // Six distinct keys against a cap of 5 → 400.
        $url = '/v1/logs?since=1h'
            .'&attribute.a=1&attribute.b=2&attribute.c=3'
            .'&attribute.d=4&attribute.e=5&attribute.f=6';
        $request = Request::create($url, 'GET');
        $event = new RequestEvent($this->kernel, $request, HttpKernelInterface::MAIN_REQUEST);

        $this->listener->onKernelRequest($event);

        $response = $event->getResponse();
        self::assertInstanceOf(JsonResponse::class, $response);
        self::assertSame(400, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true);
        self::assertStringContainsString('At most 5', $body['message']);
    }

    public function testRepeatedSameAttributeKeyRejected(): void
    {
        $request = Request::create('/v1/logs?since=1h&attribute.exception.type=A&attribute.exception.type=B', 'GET');
        $event = new RequestEvent($this->kernel, $request, HttpKernelInterface::MAIN_REQUEST);

        $this->listener->onKernelRequest($event);

        $response = $event->getResponse();
        self::assertInstanceOf(JsonResponse::class, $response);
        self::assertSame(400, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true);
        self::assertStringContainsString('multiple times', $body['message']);
    }

    public function testSingleAttributeFilterPasses(): void
    {
        $request = Request::create('/v1/logs?since=1h&attribute.exception.type=Foo', 'GET');
        $event = new RequestEvent($this->kernel, $request, HttpKernelInterface::MAIN_REQUEST);

        $this->listener->onKernelRequest($event);

        self::assertNull($event->getResponse(), 'one attribute filter must pass');
    }

    public function testTraceByIdReadPathStillEnforcesNoBody(): void
    {
        $request = Request::create('/v1/spans/0123456789abcdef', 'GET');
        $request->headers->set('Content-Length', '5');
        $event = new RequestEvent($this->kernel, $request, HttpKernelInterface::MAIN_REQUEST);

        $this->listener->onKernelRequest($event);

        $response = $event->getResponse();
        self::assertInstanceOf(JsonResponse::class, $response);
        self::assertSame(415, $response->getStatusCode());
    }
}
