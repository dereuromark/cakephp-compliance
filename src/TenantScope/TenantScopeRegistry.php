<?php

declare(strict_types=1);

namespace Compliance\TenantScope;

use Compliance\TenantScope\Exception\MissingScopeException;

/**
 * Ambient tenant context for the current request.
 *
 * The registry holds the active tenant identifier that `TenantScopeBehavior`
 * consults on every query. Typical flow: middleware inspects the request,
 * calls `setTenant()`, and the ORM transparently scopes all queries for the
 * rest of the request.
 *
 * Queries against a scoped Table without an active tenant throw
 * `MissingScopeException` — cross-tenant leakage fails loudly instead of
 * silently returning another tenant's data.
 */
class TenantScopeRegistry
{
    /**
     * @var string|int|null
     */
    protected static int|string|null $tenantId = null;

    /**
     * Set the active tenant for the current request / test / CLI invocation.
     *
     * @param string|int $tenantId
     */
    public static function setTenant(int|string $tenantId): void
    {
        static::$tenantId = $tenantId;
    }

    /**
     * Return the active tenant or throw if none is set.
     *
     * @throws \Compliance\TenantScope\Exception\MissingScopeException
     *
     * @return string|int
     */
    public static function getTenant(): int|string
    {
        if (static::$tenantId === null) {
            throw new MissingScopeException(
                'No active tenant context. Call TenantScopeRegistry::setTenant() '
                . 'before querying a scoped table.',
            );
        }

        return static::$tenantId;
    }

    public static function hasTenant(): bool
    {
        return static::$tenantId !== null;
    }

    public static function clear(): void
    {
        static::$tenantId = null;
    }

    /**
     * Temporarily switch the active tenant for the duration of the callback.
     *
     * Restores the previous value even if the callback throws. Useful for
     * request-side test setup and for background-job processing that spans
     * multiple tenants.
     *
     * @template T
     *
     * @param string|int $tenantId
     * @param callable(): T $callback
     *
     * @return T
     */
    public static function withTenant(int|string $tenantId, callable $callback): mixed
    {
        $previous = static::$tenantId;
        static::$tenantId = $tenantId;

        try {
            return $callback();
        } finally {
            static::$tenantId = $previous;
        }
    }
}
