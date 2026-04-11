<?php

declare(strict_types=1);

namespace Compliance\Gobd;

use RuntimeException;

/**
 * SHA-256 tamper-evident hash chain for GoBD-compliant audit trails.
 *
 * Every chained entry hashes together the previous entry's hash and a
 * canonical JSON encoding of the current payload. Reordering, removing, or
 * mutating any entry breaks the chain and is detected by `verify()`.
 *
 * The canonical encoding sorts object keys recursively so the hash is
 * order-independent for the caller — hashing `{"a":1,"b":2}` and
 * `{"b":2,"a":1}` produces the same digest.
 */
class HashChain
{
    /**
     * @var string
     */
    public const GENESIS = '0000000000000000000000000000000000000000000000000000000000000000';

    /**
     * Compute the hash for a new entry.
     *
     * @param string|null $previousHash Previous chain entry hash, or null for genesis
     * @param array<string, mixed> $payload
     */
    public static function hash(?string $previousHash, array $payload): string
    {
        $prev = $previousHash ?? static::GENESIS;
        $canonical = static::canonicalize($payload);

        return hash('sha256', $prev . '|' . $canonical);
    }

    /**
     * Verify that every entry in the chain matches its recomputed hash and
     * that every `prev_hash` correctly chains to the prior entry.
     *
     * @param array<int, array{payload: array<string, mixed>, prev_hash: string|null, hash: string}> $entries
     */
    public static function verify(array $entries): bool
    {
        $expectedPrev = null;
        foreach ($entries as $entry) {
            if (($entry['prev_hash'] ?? null) !== $expectedPrev) {
                return false;
            }
            $recomputed = static::hash($entry['prev_hash'] ?? null, $entry['payload']);
            if (!hash_equals($recomputed, (string)$entry['hash'])) {
                return false;
            }
            $expectedPrev = (string)$entry['hash'];
        }

        return true;
    }

    /**
     * @param array<string, mixed> $value
     *
     * @throws \RuntimeException
     */
    protected static function canonicalize(array $value): string
    {
        $sorted = static::sortRecursive($value);
        $encoded = json_encode($sorted, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($encoded === false) {
            throw new RuntimeException('HashChain: payload is not JSON-encodable.');
        }

        return $encoded;
    }

    /**
     * @param mixed $value
     */
    protected static function sortRecursive(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        $isList = array_is_list($value);
        $sorted = [];
        foreach ($value as $k => $v) {
            $sorted[$k] = static::sortRecursive($v);
        }
        if (!$isList) {
            ksort($sorted);
        }

        return $sorted;
    }
}
