<?php

declare(strict_types=1);

namespace App\Read\Cursor;

/**
 * Opaque, HMAC-signed pagination cursor.
 *
 * The cursor's payload encodes the resolved criteria (filters + absolute
 * `since`/`until` instants + ordering + limit), the position marker
 * `(last_time_unix_nano, last_row_id)`, the tenant slug, and an optional
 * criteria digest (only POST-search cursors). The whole payload is
 * HMAC-SHA256 signed with `crashler.read.cursor_secret` so:
 *
 *   1. Following a `next` link doesn't require the client to re-forward
 *      filters — the server reconstructs them from the cursor.
 *   2. A client can't forge a cursor that escapes the tenant prefix or
 *      bypasses the time-window cap (the signature would not verify).
 *   3. POST-search cursors carry a digest of the canonicalised criteria
 *      tree; GET cursors leave the digest null. This binds a cursor to
 *      both the HTTP method that minted it AND the body that produced it.
 *   4. Rotating the secret invalidates outstanding cursors (acceptable —
 *      cursors are ephemeral; clients restart from the beginning).
 *
 * Wire format:
 *
 *   base64url( payload_json ) . '.' . base64url( hmac )
 *
 * No padding, URL-safe alphabet — fits unmodified into a query parameter.
 */
final readonly class Cursor
{
    /**
     * @param array<string, mixed>                         $criteria
     * @param array{lastTimeUnixNano: int, lastRowId: int} $position
     * @param ?string                                      $criteriaDigest SHA-256 hex of the canonicalised POST-search criteria; null for GET cursors
     */
    public function __construct(
        public array $criteria,
        public array $position,
        public string $tenantSlug,
        public ?string $criteriaDigest = null,
    ) {
    }

    /**
     * @param array<string, mixed>                         $criteria
     * @param array{lastTimeUnixNano: int, lastRowId: int} $position
     */
    public static function mint(
        array $criteria,
        array $position,
        string $tenantSlug,
        string $secret,
        ?string $criteriaDigest = null,
    ): string {
        $payload = json_encode([
            'c' => $criteria,
            'p' => $position,
            't' => $tenantSlug,
            'cd' => $criteriaDigest,
        ], \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES);

        $payloadEncoded = self::base64UrlEncode($payload);
        $signature = self::base64UrlEncode(hash_hmac('sha256', $payloadEncoded, $secret, binary: true));

        return $payloadEncoded.'.'.$signature;
    }

    public static function decode(string $opaque, string $secret, string $expectedTenantSlug, int $maxWindowDays): self
    {
        $parts = explode('.', $opaque, 2);
        if (2 !== \count($parts)) {
            throw new InvalidCursorException('Invalid cursor: malformed envelope.');
        }
        [$payloadEncoded, $providedSig] = $parts;

        $expectedSig = self::base64UrlEncode(hash_hmac('sha256', $payloadEncoded, $secret, binary: true));
        if (!hash_equals($expectedSig, $providedSig)) {
            throw new InvalidCursorException('Invalid cursor: signature mismatch (tampered, expired, or minted with a different secret).');
        }

        try {
            $decoded = json_decode(self::base64UrlDecode($payloadEncoded), true, flags: \JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new InvalidCursorException('Invalid cursor: payload is not valid JSON.', previous: $e);
        }

        if (!\is_array($decoded) || !isset($decoded['c'], $decoded['p'], $decoded['t'])) {
            throw new InvalidCursorException('Invalid cursor: payload is missing required fields.');
        }
        if (!\is_array($decoded['c']) || !\is_array($decoded['p']) || !\is_string($decoded['t'])) {
            throw new InvalidCursorException('Invalid cursor: payload field types are wrong.');
        }

        $criteria = $decoded['c'];
        $position = $decoded['p'];
        $tenantSlug = $decoded['t'];
        $criteriaDigest = null;
        if (\array_key_exists('cd', $decoded)) {
            $rawDigest = $decoded['cd'];
            if (null !== $rawDigest && (!\is_string($rawDigest) || 1 !== preg_match('/^[0-9a-f]{64}$/', $rawDigest))) {
                throw new InvalidCursorException('Invalid cursor: criteria digest is malformed.');
            }
            $criteriaDigest = $rawDigest;
        }

        if ($tenantSlug !== $expectedTenantSlug) {
            throw new InvalidCursorException('Invalid cursor: bound to a different tenant.');
        }

        if (!isset($position['lastTimeUnixNano'], $position['lastRowId'])
            || !\is_int($position['lastTimeUnixNano'])
            || !\is_int($position['lastRowId'])) {
            throw new InvalidCursorException('Invalid cursor: position field is malformed.');
        }

        // Defense-in-depth: even if the signature is valid, refuse cursors
        // whose embedded time window is wider than the current cap. The cap
        // could have been lowered after the cursor was minted.
        $sinceNanos = self::extractInstant($criteria['since'] ?? null);
        $untilNanos = self::extractInstant($criteria['until'] ?? null);
        if (null !== $sinceNanos && null !== $untilNanos) {
            $maxNanos = $maxWindowDays * 24 * 60 * 60 * 1_000_000_000;
            if (($untilNanos - $sinceNanos) > $maxNanos) {
                throw new InvalidCursorException(\sprintf(
                    'Invalid cursor: embedded window exceeds the current %d-day cap.',
                    $maxWindowDays,
                ));
            }
        }

        return new self(
            criteria: $criteria,
            position: ['lastTimeUnixNano' => $position['lastTimeUnixNano'], 'lastRowId' => $position['lastRowId']],
            tenantSlug: $tenantSlug,
            criteriaDigest: $criteriaDigest,
        );
    }

    private static function base64UrlEncode(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    private static function base64UrlDecode(string $encoded): string
    {
        $padded = strtr($encoded, '-_', '+/');
        $padding = \strlen($padded) % 4;
        if ($padding > 0) {
            $padded .= str_repeat('=', 4 - $padding);
        }
        $decoded = base64_decode($padded, strict: true);
        if (false === $decoded) {
            throw new InvalidCursorException('Invalid cursor: not valid base64url.');
        }

        return $decoded;
    }

    private static function extractInstant(mixed $value): ?int
    {
        if (\is_int($value)) {
            return $value;
        }
        if (\is_string($value) && 1 === preg_match('/^-?\d+$/', $value)) {
            return (int) $value;
        }

        return null;
    }
}
