<?php

declare(strict_types=1);

namespace Compliance\Test\TestCase\Gobd;

use Cake\Datasource\ConnectionManager;
use Cake\I18n\DateTime;
use Cake\ORM\TableRegistry;
use Compliance\Gobd\Exception\ImmutableRowException;
use PHPUnit\Framework\TestCase;

class ImmutabilityBehaviorTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $connection = ConnectionManager::get('test');
        $connection->execute('DROP TABLE IF EXISTS invoices');
        $connection->execute(
            'CREATE TABLE invoices (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                number VARCHAR(50) NOT NULL,
                amount DECIMAL(10,2) NOT NULL,
                finalized_at DATETIME NULL
            )',
        );
        $connection->insert('invoices', [
            'number' => 'RE-001',
            'amount' => '100.00',
            'finalized_at' => null,
        ]);
        $connection->insert('invoices', [
            'number' => 'RE-002',
            'amount' => '200.00',
            'finalized_at' => (new DateTime())->format('Y-m-d H:i:s'),
        ]);

        TableRegistry::getTableLocator()->clear();
        $table = TableRegistry::getTableLocator()->get('Invoices');
        $table->addBehavior('Compliance.Immutability', ['field' => 'finalized_at']);
    }

    protected function tearDown(): void
    {
        TableRegistry::getTableLocator()->clear();
        parent::tearDown();
    }

    public function testDraftRowCanStillBeEdited(): void
    {
        $table = TableRegistry::getTableLocator()->get('Invoices');
        $row = $table->find()->where(['number' => 'RE-001'])->firstOrFail();
        $row->set('amount', '150.00');
        $saved = $table->save($row);
        $this->assertNotFalse($saved);
    }

    public function testFinalizedRowCannotBeEdited(): void
    {
        $table = TableRegistry::getTableLocator()->get('Invoices');
        $row = $table->find()->where(['number' => 'RE-002'])->firstOrFail();
        $row->set('amount', '300.00');
        $this->expectException(ImmutableRowException::class);
        $table->save($row);
    }

    public function testTransitionFromDraftToFinalizedIsAllowed(): void
    {
        $table = TableRegistry::getTableLocator()->get('Invoices');
        $row = $table->find()->where(['number' => 'RE-001'])->firstOrFail();
        $row->set('finalized_at', new DateTime());
        $saved = $table->save($row);
        $this->assertNotFalse($saved);
    }

    public function testFinalizedRowCannotBeDeleted(): void
    {
        $table = TableRegistry::getTableLocator()->get('Invoices');
        $row = $table->find()->where(['number' => 'RE-002'])->firstOrFail();
        $this->expectException(ImmutableRowException::class);
        $table->delete($row);
    }
}
