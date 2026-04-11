<?php

declare(strict_types=1);

namespace Compliance\TenantScope\Policy;

use Cake\Datasource\EntityInterface;
use Compliance\TenantScope\TenantScopeRegistry;

/**
 * Base class for CakePHP Authorization policies that enforce tenant ownership.
 *
 * Concrete policies override `scopeField()` to declare which entity property
 * holds the tenant identifier, then call `belongsToCurrentTenant()` from each
 * permission method:
 *
 * ```php
 * class InvoicePolicy extends AbstractTenantScopedPolicy
 * {
 *     protected function scopeField(): string
 *     {
 *         return 'account_id';
 *     }
 *
 *     public function canView(User $user, Invoice $invoice): bool
 *     {
 *         return $this->belongsToCurrentTenant($invoice);
 *     }
 * }
 * ```
 *
 * Comparison is strict (`===`) to avoid `"1" == 1`-style leaks between tenant
 * ids that share a numeric representation but differ in type.
 */
abstract class AbstractTenantScopedPolicy
{
    /**
     * Return the entity property that holds the tenant identifier.
     */
    abstract protected function scopeField(): string;

    public function belongsToCurrentTenant(EntityInterface $entity): bool
    {
        $activeTenant = TenantScopeRegistry::getTenant();
        $entityTenant = $entity->get($this->scopeField());

        return $entityTenant === $activeTenant;
    }
}
