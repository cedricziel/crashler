<?php

declare(strict_types=1);

namespace App\Tests\Unit\Read\Http;

use App\Read\Http\InvalidPostSearchBodyException;
use App\Read\Http\PostSearchRequestParser;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

#[CoversClass(PostSearchRequestParser::class)]
#[CoversClass(InvalidPostSearchBodyException::class)]
final class PostSearchRequestParserTest extends TestCase
{
    private const int CAP = 65536;

    public function testRejectsBodyOverCapWith413(): void
    {
        $parser = new PostSearchRequestParser(maxBodyBytes: self::CAP);

        // One byte over the cap. Padded by a valid-JSON outer object so the
        // size check fires before JSON parsing — and stays correct even if
        // the size check runs after parsing in a hypothetical reordering.
        $padding = str_repeat('x', self::CAP);
        $oversize = '{"criteria":{"column":"x","op":"eq","value":"'.$padding.'"}}';
        self::assertGreaterThan(self::CAP, \strlen($oversize), 'body must be over the cap');

        $request = Request::create(
            '/v1/logs/search',
            'POST',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: $oversize,
        );

        try {
            $parser->parse($request);
            self::fail('expected InvalidPostSearchBodyException');
        } catch (InvalidPostSearchBodyException $e) {
            self::assertSame(Response::HTTP_REQUEST_ENTITY_TOO_LARGE, $e->statusCode);
            self::assertStringContainsString((string) self::CAP, $e->getMessage());
        }
    }

    public function testAcceptsBodyAtExactlyCap(): void
    {
        $parser = new PostSearchRequestParser(maxBodyBytes: self::CAP);

        // Build a valid-JSON body that is exactly CAP bytes.
        $envelope = '{"criteria":{"column":"x","op":"eq","value":""}}';
        $padding = str_repeat('x', self::CAP - \strlen($envelope));
        $atCap = '{"criteria":{"column":"x","op":"eq","value":"'.$padding.'"}}';
        // Adjust for the +/- of the envelope shift; trim/pad to exactly CAP.
        $atCap = str_pad(substr($atCap, 0, self::CAP - 2), self::CAP - 2, 'x').'"}';
        self::assertSame(self::CAP, \strlen($atCap), 'fixture must be exactly at the cap');

        $request = Request::create(
            '/v1/logs/search',
            'POST',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: $atCap,
        );

        // We don't assert success of full parse (the trimmed JSON may be
        // malformed); we only assert the body-size check does NOT trip.
        try {
            $parser->parse($request);
        } catch (InvalidPostSearchBodyException $e) {
            self::assertNotSame(Response::HTTP_REQUEST_ENTITY_TOO_LARGE, $e->statusCode, 'body at the cap must not be rejected as oversize');
        }
    }

    public function testRejectsNonJsonContentTypeWith415(): void
    {
        $parser = new PostSearchRequestParser(maxBodyBytes: self::CAP);

        $request = Request::create(
            '/v1/logs/search',
            'POST',
            server: ['CONTENT_TYPE' => 'text/plain'],
            content: '{"criteria":{"column":"x","op":"eq","value":"y"}}',
        );

        try {
            $parser->parse($request);
            self::fail('expected InvalidPostSearchBodyException');
        } catch (InvalidPostSearchBodyException $e) {
            self::assertSame(Response::HTTP_UNSUPPORTED_MEDIA_TYPE, $e->statusCode);
        }
    }

    public function testRejectsMalformedJsonWith400(): void
    {
        $parser = new PostSearchRequestParser(maxBodyBytes: self::CAP);

        $request = Request::create(
            '/v1/logs/search',
            'POST',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: '{not json',
        );

        try {
            $parser->parse($request);
            self::fail('expected InvalidPostSearchBodyException');
        } catch (InvalidPostSearchBodyException $e) {
            self::assertSame(Response::HTTP_BAD_REQUEST, $e->statusCode);
            self::assertStringContainsString('Invalid JSON', $e->getMessage());
        }
    }
}
