<?php

declare(strict_types=1);

namespace Compliance\Test\TestCase\Gobd;

use Cake\Datasource\ConnectionManager;
use Compliance\Gobd\AuditChainWriter;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class AuditChainWriterTest extends TestCase
{
    public function testLogFailsFastWhenTableIsMissing(): void
    {
        $connection = ConnectionManager::get('test');
        $connection->execute('DROP TABLE IF EXISTS compliance_audit_chain');

        $writer = new AuditChainWriter($connection);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Run the compliance migration');

        $writer->log(
            eventType: 'create',
            source: 'widgets',
            targetId: '1',
            payload: ['name' => 'Alpha'],
        );
    }

    public function testLogFailsFastWhenRequiredColumnIsMissing(): void
    {
        $connection = ConnectionManager::get('test');
        $connection->execute('DROP TABLE IF EXISTS compliance_audit_chain');
        $connection->execute(
            'CREATE TABLE compliance_audit_chain (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                event_type VARCHAR(20) NOT NULL,
                source VARCHAR(100) NOT NULL,
                target_id VARCHAR(100) NULL,
                payload TEXT NOT NULL,
                prev_hash VARCHAR(64) NULL,
                hash VARCHAR(64) NOT NULL,
                created DATETIME NOT NULL
            )',
        );

        $writer = new AuditChainWriter($connection);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('`transaction_id`');

        $writer->log(
            eventType: 'create',
            source: 'widgets',
            targetId: '1',
            payload: ['name' => 'Alpha'],
        );
    }
}
