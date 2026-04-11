<?php

declare(strict_types=1);

namespace Compliance\Test\TestCase\Gobd;

use Cake\Datasource\ConnectionManager;
use Cake\I18n\DateTime;
use Cake\ORM\TableRegistry;
use Compliance\Gobd\Exception\GobdRetentionException;
use PHPUnit\Framework\TestCase;

class GobdBehaviorTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $connection = ConnectionManager::get('test');
        $connection->execute('DROP TABLE IF EXISTS ledger_entries');
        $connection->execute(
            'CREATE TABLE ledger_entries (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                amount DECIMAL(10,2) NOT NULL,
                booked_on DATE NOT NULL
            )',
        );
        $connection->insert('ledger_entries', [
            'amount' => '100.00',
            'booked_on' => (new DateTime('-11 years'))->format('Y-m-d'),
        ]);
        $connection->insert('ledger_entries', [
            'amount' => '200.00',
            'booked_on' => (new DateTime('-6 months'))->format('Y-m-d'),
        ]);

        TableRegistry::getTableLocator()->clear();
        $table = TableRegistry::getTableLocator()->get('LedgerEntries');
        $table->addBehavior('Compliance.Gobd', [
            'retentionYears' => 10,
            'dateField' => 'booked_on',
        ]);
    }

    protected function tearDown(): void
    {
        TableRegistry::getTableLocator()->clear();
        parent::tearDown();
    }

    public function testDeleteWithinRetentionIsBlocked(): void
    {
        $table = TableRegistry::getTableLocator()->get('LedgerEntries');
        $row = $table->find()->where(['amount' => '200.00'])->firstOrFail();
        $this->expectException(GobdRetentionException::class);
        $table->delete($row);
    }

    public function testDeleteOfRowBeyondRetentionAllowed(): void
    {
        $table = TableRegistry::getTableLocator()->get('LedgerEntries');
        $row = $table->find()->where(['amount' => '100.00'])->firstOrFail();
        $this->assertTrue($table->delete($row));
    }

    public function testDeleteAllIsBlockedForRetainedRows(): void
    {
        $table = TableRegistry::getTableLocator()->get('LedgerEntries');
        $row = $table->find()->where(['amount' => '200.00'])->firstOrFail();
        $this->expectException(GobdRetentionException::class);
        $table->deleteOrFail($row);
    }
}
