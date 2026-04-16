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
}
