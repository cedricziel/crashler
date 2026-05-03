<?php

declare(strict_types=1);

namespace App\Tests\Unit\Otlp;

use App\Otlp\ErrorResponse;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Response;

#[CoversClass(ErrorResponse::class)]
final class ErrorResponseTest extends TestCase
{
    public function testReturnsJsonResponseWithMessageField(): void
    {
        $response = ErrorResponse::create(400, 'bad json');

        self::assertSame(400, $response->getStatusCode());
        self::assertSame('application/json', $response->headers->get('Content-Type'));

        $body = json_decode((string) $response->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertSame(['message' => 'bad json'], $body);
    }

    /**
     * @return iterable<string, array{0: int}>
     */
    public static function statusProvider(): iterable
    {
        yield '400' => [Response::HTTP_BAD_REQUEST];
        yield '401' => [Response::HTTP_UNAUTHORIZED];
        yield '413' => [Response::HTTP_REQUEST_ENTITY_TOO_LARGE];
        yield '415' => [Response::HTTP_UNSUPPORTED_MEDIA_TYPE];
        yield '500' => [Response::HTTP_INTERNAL_SERVER_ERROR];
    }

    #[DataProvider('statusProvider')]
    public function testEachDocumentedStatusCodeIsAccepted(int $status): void
    {
        $response = ErrorResponse::create($status, 'x');

        self::assertSame($status, $response->getStatusCode());
    }
}
