<?php

declare(strict_types=1);

namespace Compliance\Test\TestCase\Gobd;

use Compliance\Gobd\HashChain;
use PHPUnit\Framework\TestCase;

class HashChainTest extends TestCase
{
    public function testFirstEntryHasGenesisPreviousHash(): void
    {
        $entry = HashChain::hash(null, ['event' => 'create', 'id' => 1]);
        $this->assertSame(64, strlen($entry));
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $entry);
    }

    public function testSameInputProducesSameHash(): void
    {
        $a = HashChain::hash(null, ['event' => 'create', 'id' => 1]);
        $b = HashChain::hash(null, ['event' => 'create', 'id' => 1]);
        $this->assertSame($a, $b);
    }

    public function testDifferentPayloadProducesDifferentHash(): void
    {
        $a = HashChain::hash(null, ['event' => 'create', 'id' => 1]);
        $b = HashChain::hash(null, ['event' => 'update', 'id' => 1]);
        $this->assertNotSame($a, $b);
    }

    public function testDifferentPreviousHashProducesDifferentHash(): void
    {
        $prev = str_repeat('a', 64);
        $a = HashChain::hash(null, ['event' => 'create', 'id' => 1]);
        $b = HashChain::hash($prev, ['event' => 'create', 'id' => 1]);
        $this->assertNotSame($a, $b);
    }

    public function testPayloadKeysAreOrderIndependent(): void
    {
        $a = HashChain::hash(null, ['event' => 'create', 'id' => 1]);
        $b = HashChain::hash(null, ['id' => 1, 'event' => 'create']);
        $this->assertSame($a, $b);
    }

    public function testVerifyReturnsTrueForIntactChain(): void
    {
        $entries = $this->buildValidChain();
        $this->assertTrue(HashChain::verify($entries));
    }

    public function testVerifyReturnsFalseWhenPayloadMutated(): void
    {
        $entries = $this->buildValidChain();
        $entries[1]['payload']['id'] = 999;
        $this->assertFalse(HashChain::verify($entries));
    }

    public function testVerifyReturnsFalseWhenHashMutated(): void
    {
        $entries = $this->buildValidChain();
        $entries[1]['hash'] = str_repeat('f', 64);
        $this->assertFalse(HashChain::verify($entries));
    }

    public function testVerifyReturnsFalseWhenEntryRemoved(): void
    {
        $entries = $this->buildValidChain();
        array_splice($entries, 1, 1);
        $this->assertFalse(HashChain::verify($entries));
    }

    /**
     * @return array<int, array{payload: array<string, mixed>, prev_hash: string|null, hash: string}>
     */
    protected function buildValidChain(): array
    {
        $entries = [];
        $prev = null;
        foreach (
            [
                ['event' => 'create', 'id' => 1],
                ['event' => 'update', 'id' => 1, 'amount' => 100],
                ['event' => 'finalize', 'id' => 1],
            ] as $payload
        ) {
            $hash = HashChain::hash($prev, $payload);
            $entries[] = ['payload' => $payload, 'prev_hash' => $prev, 'hash' => $hash];
            $prev = $hash;
        }

        return $entries;
    }
}
