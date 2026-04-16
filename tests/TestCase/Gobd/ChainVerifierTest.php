<?php

declare(strict_types=1);

namespace Compliance\Test\TestCase\Gobd;

use Cake\Datasource\ConnectionManager;
use Compliance\Gobd\AuditChainWriter;
use Compliance\Gobd\ChainVerifier;
use PHPUnit\Framework\TestCase;

class ChainVerifierTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $connection = ConnectionManager::get('test');
        $connection->execute('DROP TABLE IF EXISTS compliance_audit_chain');
        $connection->execute(
            'CREATE TABLE compliance_audit_chain (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                transaction_id VARCHAR(100) NULL,
                event_type VARCHAR(20) NOT NULL,
                source VARCHAR(100) NOT NULL,
                target_id VARCHAR(100) NULL,
                payload TEXT NOT NULL,
                prev_hash VARCHAR(64) NULL,
                hash VARCHAR(64) NOT NULL,
                created DATETIME NOT NULL
            )',
        );
    }

    public function testVerifyReportsIntactChainAcrossChunks(): void
    {
        $this->seedChain();

        $result = (new ChainVerifier(ConnectionManager::get('test')))->verify(1);

        $this->assertTrue($result->intact, (string)$result->reason);
        $this->assertSame(3, $result->rowsChecked);
        $this->assertNull($result->brokenRowId);
    }

    public function testVerifyReportsBrokenRowAndReason(): void
    {
        $this->seedChain();
        ConnectionManager::get('test')->update(
            'compliance_audit_chain',
            ['payload' => '{"name":"Hacked"}'],
            ['id' => 2],
        );

        $result = (new ChainVerifier(ConnectionManager::get('test')))->verify();

        $this->assertFalse($result->intact);
        $this->assertSame(2, $result->brokenRowId);
        $this->assertStringContainsString('hash mismatch', (string)$result->reason);
    }

    protected function seedChain(): void
    {
        $writer = new AuditChainWriter(ConnectionManager::get('test'));
        $writer->logMany([
            ['event_type' => 'create', 'source' => 'widgets', 'target_id' => '1', 'payload' => ['name' => 'Alpha'], 'transaction_id' => 'tx-1'],
            ['event_type' => 'update', 'source' => 'widgets', 'target_id' => '1', 'payload' => ['name' => 'Beta'], 'transaction_id' => 'tx-2'],
            ['event_type' => 'update', 'source' => 'widgets', 'target_id' => '1', 'payload' => ['name' => 'Gamma'], 'transaction_id' => 'tx-3'],
        ]);
    }
}
