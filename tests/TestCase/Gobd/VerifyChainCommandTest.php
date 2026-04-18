<?php

declare(strict_types=1);

namespace Compliance\Test\TestCase\Gobd;

use Cake\Console\Arguments;
use Cake\Console\ConsoleIo;
use Cake\Console\TestSuite\StubConsoleInput;
use Cake\Console\TestSuite\StubConsoleOutput;
use Cake\Datasource\ConnectionManager;
use Compliance\Gobd\AuditChainWriter;
use Compliance\Gobd\Command\VerifyChainCommand;
use PHPUnit\Framework\TestCase;

class VerifyChainCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $connection = ConnectionManager::get('test');
        $connection->execute('DROP TABLE IF EXISTS compliance_audit_chain');
        $connection->execute(
            'CREATE TABLE compliance_audit_chain (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                account_id INTEGER NULL,
                user_id INTEGER NULL,
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
    }

    public function testIntactChainReturnsSuccess(): void
    {
        $this->seedChain();
        [$out, $code] = $this->runCommand();
        $this->assertStringContainsString('chain is intact', $out);
        $this->assertSame(0, $code);
    }

    public function testEmptyChainIsConsideredIntact(): void
    {
        [$out, $code] = $this->runCommand();
        $this->assertSame(0, $code);
        $this->assertStringContainsString('empty', $out);
    }

    public function testTamperedPayloadReportsFailure(): void
    {
        $this->seedChain();
        ConnectionManager::get('test')->update(
            'compliance_audit_chain',
            ['payload' => '{"name":"Hacked"}'],
            ['id' => 1],
        );
        [$out, $code] = $this->runCommand();
        $this->assertStringContainsString('tampered', $out);
        $this->assertStringContainsString('row id=1', $out);
        $this->assertSame(1, $code);
    }

    public function testMutatedHashReportsFailure(): void
    {
        $this->seedChain();
        ConnectionManager::get('test')->update(
            'compliance_audit_chain',
            ['hash' => str_repeat('0', 64)],
            ['id' => 2],
        );
        [$out, $code] = $this->runCommand();
        $this->assertStringContainsString('row id=2', $out);
        $this->assertSame(1, $code);
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

    /**
     * @return array{0: string, 1: int}
     */
    protected function runCommand(): array
    {
        $command = new VerifyChainCommand(ConnectionManager::get('test'));
        $output = new StubConsoleOutput();
        $io = new ConsoleIo($output, $output, new StubConsoleInput([]));
        $args = new Arguments([], [], []);

        $code = $command->execute($args, $io);
        $outText = implode("\n", $output->messages());

        return [$outText, (int)$code];
    }
}
