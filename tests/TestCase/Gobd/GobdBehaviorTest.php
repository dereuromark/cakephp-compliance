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

    public function testFreshRowIsDeletableWithinGraceWindow(): void
    {
        $connection = ConnectionManager::get('test');
        $connection->execute('DROP TABLE IF EXISTS fresh_entries');
        $connection->execute(
            'CREATE TABLE fresh_entries (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                amount DECIMAL(10,2) NOT NULL,
                created DATETIME NOT NULL
            )',
        );
        $connection->insert('fresh_entries', [
            'amount' => '50.00',
            'created' => (new DateTime())->format('Y-m-d H:i:s'),
        ]);

        TableRegistry::getTableLocator()->clear();
        $table = TableRegistry::getTableLocator()->get('FreshEntries');
        $table->addBehavior('Compliance.Gobd', [
            'retentionYears' => 10,
            'dateField' => 'created',
            'graceHours' => 24,
        ]);

        $row = $table->find()->where(['amount' => '50.00'])->firstOrFail();
        $this->assertTrue($table->delete($row));
    }

    public function testFreshRowIsBlockedWhenGraceDisabled(): void
    {
        $connection = ConnectionManager::get('test');
        $connection->execute('DROP TABLE IF EXISTS nograce_entries');
        $connection->execute(
            'CREATE TABLE nograce_entries (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                amount DECIMAL(10,2) NOT NULL,
                created DATETIME NOT NULL
            )',
        );
        $connection->insert('nograce_entries', [
            'amount' => '75.00',
            'created' => (new DateTime())->format('Y-m-d H:i:s'),
        ]);

        TableRegistry::getTableLocator()->clear();
        $table = TableRegistry::getTableLocator()->get('NograceEntries');
        $table->addBehavior('Compliance.Gobd', [
            'retentionYears' => 10,
            'dateField' => 'created',
            'graceHours' => 0,
        ]);

        $row = $table->find()->where(['amount' => '75.00'])->firstOrFail();
        $this->expectException(GobdRetentionException::class);
        $table->delete($row);
    }

    public function testIsDeletableHelper(): void
    {
        $connection = ConnectionManager::get('test');
        $connection->execute('DROP TABLE IF EXISTS helper_entries');
        $connection->execute(
            'CREATE TABLE helper_entries (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                label VARCHAR(50) NOT NULL,
                created DATETIME NOT NULL
            )',
        );
        $connection->insert('helper_entries', [
            'label' => 'fresh',
            'created' => (new DateTime())->format('Y-m-d H:i:s'),
        ]);
        $connection->insert('helper_entries', [
            'label' => 'retained',
            'created' => (new DateTime('-6 months'))->format('Y-m-d H:i:s'),
        ]);

        TableRegistry::getTableLocator()->clear();
        $table = TableRegistry::getTableLocator()->get('HelperEntries');
        $table->addBehavior('Compliance.Gobd', [
            'retentionYears' => 10,
            'dateField' => 'created',
            'graceHours' => 24,
        ]);

        /** @var \Compliance\Model\Behavior\GobdBehavior $gobd */
        $gobd = $table->behaviors()->get('Gobd');

        $fresh = $table->find()->where(['label' => 'fresh'])->firstOrFail();
        $retained = $table->find()->where(['label' => 'retained'])->firstOrFail();

        $this->assertTrue($gobd->isDeletable($fresh));
        $this->assertFalse($gobd->isDeletable($retained));
    }
}
