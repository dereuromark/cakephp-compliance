<?php

declare(strict_types=1);

namespace Compliance\Test\TestCase\Gobd;

use AuditStash\Event\AuditCreateEvent;
use AuditStash\Event\AuditUpdateEvent;
use Cake\Datasource\ConnectionManager;
use Compliance\Gobd\HashChain;
use Compliance\Gobd\Persister\HashChainAuditPersister;
use PHPUnit\Framework\TestCase;

class HashChainAuditPersisterTest extends TestCase
{
    protected HashChainAuditPersister $persister;

    protected function setUp(): void
    {
        parent::setUp();
        $connection = ConnectionManager::get('test');
        $connection->execute('DROP TABLE IF EXISTS compliance_audit_chain');
        $connection->execute(
            'CREATE TABLE compliance_audit_chain (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                transaction_id VARCHAR(100) NOT NULL,
                event_type VARCHAR(20) NOT NULL,
                source VARCHAR(100) NOT NULL,
                target_id VARCHAR(100) NOT NULL,
                payload TEXT NOT NULL,
                prev_hash VARCHAR(64) NULL,
                hash VARCHAR(64) NOT NULL,
                created DATETIME NOT NULL
            )',
        );
        $this->persister = new HashChainAuditPersister($connection);
    }

    public function testPersistSingleEventWritesOneRowWithGenesisPrevHash(): void
    {
        $event = new AuditCreateEvent('tx-1', 1, 'widgets', ['name' => 'Alpha'], null, null);
        $this->persister->logEvents([$event]);

        $rows = $this->fetchAll();
        $this->assertCount(1, $rows);
        $this->assertNull($rows[0]['prev_hash']);
        $this->assertSame(64, strlen((string)$rows[0]['hash']));
    }

    public function testPersistMultipleEventsChainsCorrectly(): void
    {
        $a = new AuditCreateEvent('tx-1', 1, 'widgets', ['name' => 'Alpha'], null, null);
        $b = new AuditUpdateEvent('tx-2', 1, 'widgets', ['name' => 'Beta'], ['name' => 'Alpha'], null);
        $c = new AuditUpdateEvent('tx-3', 1, 'widgets', ['name' => 'Gamma'], ['name' => 'Beta'], null);
        $this->persister->logEvents([$a, $b, $c]);

        $rows = $this->fetchAll();
        $this->assertCount(3, $rows);
        $this->assertNull($rows[0]['prev_hash']);
        $this->assertSame($rows[0]['hash'], $rows[1]['prev_hash']);
        $this->assertSame($rows[1]['hash'], $rows[2]['prev_hash']);
    }

    public function testChainIsVerifiableAfterPersistence(): void
    {
        $events = [
            new AuditCreateEvent('tx-1', 1, 'widgets', ['name' => 'Alpha'], null, null),
            new AuditUpdateEvent('tx-2', 1, 'widgets', ['name' => 'Beta'], ['name' => 'Alpha'], null),
        ];
        $this->persister->logEvents($events);

        $entries = [];
        foreach ($this->fetchAll() as $row) {
            $entries[] = [
                'payload' => (array)json_decode((string)$row['payload'], true),
                'prev_hash' => $row['prev_hash'],
                'hash' => (string)$row['hash'],
            ];
        }
        $this->assertTrue(HashChain::verify($entries));
    }

    public function testPersistAppendsToExistingChain(): void
    {
        $this->persister->logEvents([
            new AuditCreateEvent('tx-1', 1, 'widgets', ['name' => 'Alpha'], null, null),
        ]);
        $firstHash = $this->fetchAll()[0]['hash'];

        $this->persister->logEvents([
            new AuditUpdateEvent('tx-2', 1, 'widgets', ['name' => 'Beta'], ['name' => 'Alpha'], null),
        ]);

        $rows = $this->fetchAll();
        $this->assertCount(2, $rows);
        $this->assertSame($firstHash, $rows[1]['prev_hash']);
    }

    public function testEmptyEventListIsNoop(): void
    {
        $this->persister->logEvents([]);
        $this->assertCount(0, $this->fetchAll());
    }

    public function testTamperingDetectionOnPayloadMutation(): void
    {
        $this->persister->logEvents([
            new AuditCreateEvent('tx-1', 1, 'widgets', ['name' => 'Alpha'], null, null),
            new AuditUpdateEvent('tx-2', 1, 'widgets', ['name' => 'Beta'], ['name' => 'Alpha'], null),
        ]);

        // Mutate the first row's payload (attacker scenario)
        ConnectionManager::get('test')->update(
            'compliance_audit_chain',
            ['payload' => '{"name":"HackedAlpha"}'],
            ['id' => 1],
        );

        $entries = [];
        foreach ($this->fetchAll() as $row) {
            $entries[] = [
                'payload' => (array)json_decode((string)$row['payload'], true),
                'prev_hash' => $row['prev_hash'],
                'hash' => (string)$row['hash'],
            ];
        }
        $this->assertFalse(HashChain::verify($entries));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function fetchAll(): array
    {
        return ConnectionManager::get('test')
            ->execute('SELECT * FROM compliance_audit_chain ORDER BY id ASC')
            ->fetchAll('assoc') ?: [];
    }
}
