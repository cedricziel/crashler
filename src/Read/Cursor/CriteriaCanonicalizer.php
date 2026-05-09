<?php

declare(strict_types=1);

namespace App\Read\Cursor;

/**
 * Produces a deterministic SHA-256 digest of a POST-search `criteria` tree.
 *
 * The digest is embedded in the issued cursor so that the next page-of-results
 * request must carry the *same* criteria. Mutating the criteria between pages
 * → digest mismatch → 400. Re-using a POST cursor on GET → digest present
 * where GET expects null → 400. Re-using a GET cursor on POST → digest absent
 * where POST expects one → 400.
 *
 * Canonicalisation rules:
 *   - object keys sorted ascending
 *   - lists preserved in source order (order is meaningful for `any`/`all`/`in`)
 *   - no whitespace, JSON_UNESCAPED_SLASHES + JSON_UNESCAPED_UNICODE
 *   - integers preserved as integers (PHP's default JSON encoder does this)
 */
final class CriteriaCanonicalizer
{
    /**
     * @param array<string, mixed>|list<mixed> $criteria
     */
    public static function digest(array $criteria): string
    {
        $canonical = self::canonicalize($criteria);
        $encoded = json_encode($canonical, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR);

        return hash('sha256', $encoded);
    }

    private static function canonicalize(mixed $value): mixed
    {
        if (!\is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map(self::canonicalize(...), $value);
        }

        ksort($value);
        $out = [];
        foreach ($value as $k => $v) {
            $out[$k] = self::canonicalize($v);
        }

        return $out;
    }
}
