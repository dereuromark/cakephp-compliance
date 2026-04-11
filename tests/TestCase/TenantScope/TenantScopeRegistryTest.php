<?php

declare(strict_types=1);

namespace Compliance\Test\TestCase\TenantScope;

use Compliance\TenantScope\Exception\MissingScopeException;
use Compliance\TenantScope\TenantScopeRegistry;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class TenantScopeRegistryTest extends TestCase
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

    public function testDefaultHasNoTenant(): void
    {
        $this->assertFalse(TenantScopeRegistry::hasTenant());
    }

    public function testGetTenantWithoutSetThrows(): void
    {
        $this->expectException(MissingScopeException::class);
        TenantScopeRegistry::getTenant();
    }

    public function testSetAndGetReturnsTheSameValue(): void
    {
        TenantScopeRegistry::setTenant('tenant-42');
        $this->assertSame('tenant-42', TenantScopeRegistry::getTenant());
        $this->assertTrue(TenantScopeRegistry::hasTenant());
    }

    public function testClearRemovesTenant(): void
    {
        TenantScopeRegistry::setTenant('tenant-42');
        TenantScopeRegistry::clear();
        $this->assertFalse(TenantScopeRegistry::hasTenant());
    }

    public function testIntegerTenantIdIsAllowed(): void
    {
        TenantScopeRegistry::setTenant(42);
        $this->assertSame(42, TenantScopeRegistry::getTenant());
    }

    public function testWithTenantRunsCallbackAndRestoresPrevious(): void
    {
        TenantScopeRegistry::setTenant('outer');
        $result = TenantScopeRegistry::withTenant('inner', fn () => TenantScopeRegistry::getTenant());
        $this->assertSame('inner', $result);
        $this->assertSame('outer', TenantScopeRegistry::getTenant());
    }

    public function testWithTenantRestoresEvenOnException(): void
    {
        TenantScopeRegistry::setTenant('outer');
        try {
            TenantScopeRegistry::withTenant('inner', function (): void {
                throw new RuntimeException('boom');
            });
        } catch (RuntimeException) {
            // expected
        }
        $this->assertSame('outer', TenantScopeRegistry::getTenant());
    }
}
