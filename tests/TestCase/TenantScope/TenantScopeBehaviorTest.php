<?php

declare(strict_types=1);

namespace Compliance\Test\TestCase\TenantScope;

use Cake\Datasource\ConnectionManager;
use Cake\ORM\TableRegistry;
use Compliance\TenantScope\Exception\MissingScopeException;
use Compliance\TenantScope\TenantScopeRegistry;
use PHPUnit\Framework\TestCase;

class TenantScopeBehaviorTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $connection = ConnectionManager::get('test');
        $connection->execute('DROP TABLE IF EXISTS widgets');
        $connection->execute(
            'CREATE TABLE widgets (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                tenant_id VARCHAR(50) NOT NULL,
                name VARCHAR(100) NOT NULL
            )',
        );
        $connection->insert('widgets', ['tenant_id' => 'tenant-a', 'name' => 'Alpha']);
        $connection->insert('widgets', ['tenant_id' => 'tenant-a', 'name' => 'Apollo']);
        $connection->insert('widgets', ['tenant_id' => 'tenant-b', 'name' => 'Bravo']);
        $connection->insert('widgets', ['tenant_id' => 'tenant-b', 'name' => 'Beacon']);

        TableRegistry::getTableLocator()->clear();
        $table = TableRegistry::getTableLocator()->get('Widgets');
        $table->addBehavior('Compliance.TenantScope', ['field' => 'tenant_id']);

        TenantScopeRegistry::clear();
    }

    protected function tearDown(): void
    {
        TenantScopeRegistry::clear();
        TableRegistry::getTableLocator()->clear();
        parent::tearDown();
    }

    public function testFindWithActiveTenantReturnsOnlyThatTenantRows(): void
    {
        TenantScopeRegistry::setTenant('tenant-a');
        $table = TableRegistry::getTableLocator()->get('Widgets');
        $names = $table->find()->all()->extract('name')->toList();
        sort($names);
        $this->assertSame(['Alpha', 'Apollo'], $names);
    }

    public function testFindWithDifferentTenantReturnsOtherRows(): void
    {
        TenantScopeRegistry::setTenant('tenant-b');
        $table = TableRegistry::getTableLocator()->get('Widgets');
        $names = $table->find()->all()->extract('name')->toList();
        sort($names);
        $this->assertSame(['Beacon', 'Bravo'], $names);
    }

    public function testFindWithoutTenantThrows(): void
    {
        $table = TableRegistry::getTableLocator()->get('Widgets');
        $this->expectException(MissingScopeException::class);
        $table->find()->all()->toArray();
    }

    public function testFindAcrossTenantsBypassesScopeWhenExplicit(): void
    {
        TenantScopeRegistry::setTenant('tenant-a');
        $table = TableRegistry::getTableLocator()->get('Widgets');
        $names = $table->find('acrossTenants')->all()->extract('name')->toList();
        sort($names);
        $this->assertSame(['Alpha', 'Apollo', 'Beacon', 'Bravo'], $names);
    }

    public function testSaveStampsTenantIdAutomatically(): void
    {
        TenantScopeRegistry::setTenant('tenant-a');
        $table = TableRegistry::getTableLocator()->get('Widgets');
        $entity = $table->newEntity(['name' => 'Axel']);
        $saved = $table->save($entity);
        $this->assertNotFalse($saved);
        $this->assertSame('tenant-a', $saved->get('tenant_id'));
    }

    public function testSaveWithoutTenantThrows(): void
    {
        $table = TableRegistry::getTableLocator()->get('Widgets');
        $entity = $table->newEntity(['name' => 'Orphan']);
        $this->expectException(MissingScopeException::class);
        $table->save($entity);
    }
}
