<?php

declare(strict_types=1);

namespace App\Tests\Unit\Read\Cursor;

use App\Read\Cursor\Cursor;
use App\Read\Cursor\InvalidCursorException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Cursor::class)]
#[CoversClass(InvalidCursorException::class)]
final class CursorTest extends TestCase
{
    private const string SECRET = 'unit-test-secret-do-not-leak';

    public function testMintReturnsBase64UrlString(): void
    {
        $opaque = Cursor::mint(
            criteria: ['service' => 'checkout', 'since' => 1_778_421_600_000_000_000, 'until' => 1_778_425_200_000_000_000, 'limit' => 100],
            position: ['lastTimeUnixNano' => 1_778_424_000_000_000_000, 'lastRowId' => 42],
            tenantSlug: 'acme',
            secret: self::SECRET,
        );

        // Single dot separator, two base64url chunks (no padding, URL-safe alphabet).
        self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+$/', $opaque);
        self::assertStringNotContainsString('=', $opaque);
    }

    public function testRoundTrip(): void
    {
        $criteria = ['service' => 'checkout', 'since' => 1_778_421_600_000_000_000, 'until' => 1_778_425_200_000_000_000, 'limit' => 100];
        $position = ['lastTimeUnixNano' => 1_778_424_000_000_000_000, 'lastRowId' => 42];

        $opaque = Cursor::mint($criteria, $position, 'acme', self::SECRET);
        $decoded = Cursor::decode($opaque, self::SECRET, 'acme', maxWindowDays: 30);

        self::assertSame($criteria, $decoded->criteria);
        self::assertSame($position, $decoded->position);
        self::assertSame('acme', $decoded->tenantSlug);
    }

    public function testTamperedPayloadRejected(): void
    {
        $opaque = Cursor::mint(
            criteria: ['service' => 'checkout'],
            position: ['lastTimeUnixNano' => 1, 'lastRowId' => 0],
            tenantSlug: 'acme',
            secret: self::SECRET,
        );

        // Flip a character in the payload portion.
        [$payload, $sig] = explode('.', $opaque, 2);
        $tampered = substr($payload, 0, -1).('A' === substr($payload, -1) ? 'B' : 'A').'.'.$sig;

        $this->expectException(InvalidCursorException::class);
        $this->expectExceptionMessageMatches('/signature mismatch/i');

        Cursor::decode($tampered, self::SECRET, 'acme', maxWindowDays: 30);
    }

    public function testWrongSecretRejected(): void
    {
        $opaque = Cursor::mint(
            criteria: [],
            position: ['lastTimeUnixNano' => 1, 'lastRowId' => 0],
            tenantSlug: 'acme',
            secret: 'original-secret',
        );

        $this->expectException(InvalidCursorException::class);
        $this->expectExceptionMessageMatches('/different secret|signature mismatch/i');

        Cursor::decode($opaque, 'rotated-secret', 'acme', maxWindowDays: 30);
    }

    public function testCrossTenantRejected(): void
    {
        $opaque = Cursor::mint(
            criteria: [],
            position: ['lastTimeUnixNano' => 1, 'lastRowId' => 0],
            tenantSlug: 'acme',
            secret: self::SECRET,
        );

        $this->expectException(InvalidCursorException::class);
        $this->expectExceptionMessageMatches('/different tenant/i');

        Cursor::decode($opaque, self::SECRET, expectedTenantSlug: 'widgets', maxWindowDays: 30);
    }

    public function testWindowOverCapRejectedDefensiveCheck(): void
    {
        // Mint a cursor when the cap was 30 days, embedding a 28-day window
        // (within cap at mint time).
        $since = 1_778_000_000_000_000_000;
        $until = $since + 28 * 24 * 60 * 60 * 1_000_000_000;
        $opaque = Cursor::mint(
            criteria: ['since' => $since, 'until' => $until],
            position: ['lastTimeUnixNano' => $since + 1, 'lastRowId' => 0],
            tenantSlug: 'acme',
            secret: self::SECRET,
        );

        // Operator lowers the cap to 7 days. The cursor's embedded 28-day
        // window now exceeds the live cap.
        $this->expectException(InvalidCursorException::class);
        $this->expectExceptionMessageMatches('/cap/i');

        Cursor::decode($opaque, self::SECRET, 'acme', maxWindowDays: 7);
    }

    public function testMalformedEnvelopeRejected(): void
    {
        $this->expectException(InvalidCursorException::class);
        Cursor::decode('not-a-cursor', self::SECRET, 'acme', maxWindowDays: 30);
    }

    public function testEmptyStringRejected(): void
    {
        $this->expectException(InvalidCursorException::class);
        Cursor::decode('', self::SECRET, 'acme', maxWindowDays: 30);
    }

    public function testNonsenseBase64Rejected(): void
    {
        // Two parts separated by a dot but neither is valid base64.
        $this->expectException(InvalidCursorException::class);
        Cursor::decode('!!!!.!!!!', self::SECRET, 'acme', maxWindowDays: 30);
    }

    public function testCriteriaDigestRoundTrip(): void
    {
        $digest = str_repeat('a', 64); // 64 lowercase hex chars

        $opaque = Cursor::mint(
            criteria: ['since' => 1, 'until' => 2],
            position: ['lastTimeUnixNano' => 1, 'lastRowId' => 0],
            tenantSlug: 'acme',
            secret: self::SECRET,
            criteriaDigest: $digest,
        );
        $decoded = Cursor::decode($opaque, self::SECRET, 'acme', maxWindowDays: 30);

        self::assertSame($digest, $decoded->criteriaDigest);
    }

    public function testGetCursorHasNullDigest(): void
    {
        $opaque = Cursor::mint(
            criteria: [],
            position: ['lastTimeUnixNano' => 1, 'lastRowId' => 0],
            tenantSlug: 'acme',
            secret: self::SECRET,
        );
        $decoded = Cursor::decode($opaque, self::SECRET, 'acme', maxWindowDays: 30);

        self::assertNull($decoded->criteriaDigest);
    }

    public function testMalformedDigestRejected(): void
    {
        // The digest field shape check inside decode() catches non-hex / wrong-length values.
        // Mint with a malformed digest (uppercase) and assert decode rejects.
        $this->expectException(InvalidCursorException::class);
        $this->expectExceptionMessageMatches('/digest is malformed/');

        $opaque = Cursor::mint(
            criteria: [],
            position: ['lastTimeUnixNano' => 1, 'lastRowId' => 0],
            tenantSlug: 'acme',
            secret: self::SECRET,
            criteriaDigest: 'NOT-A-VALID-HEX',
        );
        Cursor::decode($opaque, self::SECRET, 'acme', maxWindowDays: 30);
    }
}
