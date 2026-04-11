<?php

declare(strict_types=1);

namespace Compliance\Model\Behavior;

use ArrayObject;
use Cake\Datasource\EntityInterface;
use Cake\Event\EventInterface;
use Cake\ORM\Behavior;
use Cake\ORM\Query\SelectQuery;
use Compliance\TenantScope\TenantScopeRegistry;

/**
 * Transparently scope all queries and saves against a tenant field.
 *
 * Attach to any Table that holds multi-tenant data:
 *
 * ```php
 * $this->addBehavior('Compliance.TenantScope', ['field' => 'account_id']);
 * ```
 *
 * Every `find()` is automatically filtered by the active tenant from
 * `TenantScopeRegistry`. Every `save()` stamps the active tenant onto the
 * entity if not already set. Queries or saves without an active tenant throw
 * `MissingScopeException` — cross-tenant leakage fails loudly.
 *
 * To bypass the scope intentionally (e.g. for admin tooling), use the
 * `acrossTenants` finder:
 *
 * ```php
 * $table->find('acrossTenants')->all();
 * ```
 */
class TenantScopeBehavior extends Behavior
{
    /**
     * @var array<string, mixed>
     */
    protected array $_defaultConfig = [
        'field' => 'tenant_id',
    ];

    public function beforeFind(EventInterface $event, SelectQuery $query, ArrayObject $options): void
    {
        if (!empty($options['acrossTenants'])) {
            return;
        }

        $tenant = TenantScopeRegistry::getTenant();
        $field = (string)$this->getConfig('field');
        $alias = $this->table()->getAlias();
        $query->where([$alias . '.' . $field => $tenant]);
    }

    /**
     * Provide a `find('acrossTenants')` finder that bypasses the scope.
     *
     * @param \Cake\ORM\Query\SelectQuery $query
     *
     * @return \Cake\ORM\Query\SelectQuery
     */
    public function findAcrossTenants(SelectQuery $query): SelectQuery
    {
        return $query->applyOptions(['acrossTenants' => true]);
    }

    public function beforeSave(EventInterface $event, EntityInterface $entity, ArrayObject $options): void
    {
        $field = (string)$this->getConfig('field');
        if ($entity->get($field) === null) {
            $entity->set($field, TenantScopeRegistry::getTenant());
        }
    }
}
