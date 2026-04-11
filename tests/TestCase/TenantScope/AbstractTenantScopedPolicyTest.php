<?php

declare(strict_types=1);

namespace Compliance\Test\TestCase\TenantScope;

use Cake\ORM\Entity;
use Compliance\TenantScope\Exception\MissingScopeException;
use Compliance\TenantScope\Policy\AbstractTenantScopedPolicy;
use Compliance\TenantScope\TenantScopeRegistry;
use PHPUnit\Framework\TestCase;

class AbstractTenantScopedPolicyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        TenantScopeRegistry::clear();
    }

    protected function tearDown(): void
    {
        TenantScopeRegistry::clear();
        parent::tearDown();
    }

    public function testBelongsToCurrentTenantReturnsTrueWhenFieldMatches(): void
    {
        $policy = $this->concretePolicy('tenant_id');
        $entity = new Entity(['tenant_id' => 'tenant-a']);
        TenantScopeRegistry::setTenant('tenant-a');
        $this->assertTrue($policy->belongsToCurrentTenant($entity));
    }

    public function testBelongsToCurrentTenantReturnsFalseWhenFieldMismatch(): void
    {
        $policy = $this->concretePolicy('tenant_id');
        $entity = new Entity(['tenant_id' => 'tenant-b']);
        TenantScopeRegistry::setTenant('tenant-a');
        $this->assertFalse($policy->belongsToCurrentTenant($entity));
    }

    public function testBelongsToCurrentTenantThrowsWhenNoTenantActive(): void
    {
        $policy = $this->concretePolicy('tenant_id');
        $entity = new Entity(['tenant_id' => 'tenant-a']);
        $this->expectException(MissingScopeException::class);
        $policy->belongsToCurrentTenant($entity);
    }

    public function testCustomScopeFieldIsHonoured(): void
    {
        $policy = $this->concretePolicy('account_id');
        $entity = new Entity(['account_id' => 7]);
        TenantScopeRegistry::setTenant(7);
        $this->assertTrue($policy->belongsToCurrentTenant($entity));
    }

    public function testStrictComparisonFailsOnDifferentTypes(): void
    {
        $policy = $this->concretePolicy('account_id');
        $entity = new Entity(['account_id' => '7']);
        TenantScopeRegistry::setTenant(7);
        $this->assertFalse($policy->belongsToCurrentTenant($entity));
    }

    protected function concretePolicy(string $field): AbstractTenantScopedPolicy
    {
        $policy = new class extends AbstractTenantScopedPolicy {
            public string $field = 'tenant_id';

            protected function scopeField(): string
            {
                return $this->field;
            }
        };
        $policy->field = $field;

        return $policy;
    }
}
