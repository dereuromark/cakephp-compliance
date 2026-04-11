<?php

declare(strict_types=1);

namespace Compliance\TenantScope\Middleware;

use Compliance\TenantScope\TenantScopeRegistry;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Resolves the active tenant from the incoming request and installs it into
 * `TenantScopeRegistry` for the duration of the handler call.
 *
 * The tenant identifier is read from a configurable request attribute (default
 * `tenantId`). Upstream middleware is expected to authenticate the request and
 * set this attribute — this middleware intentionally does no authentication
 * or authorization, it only propagates an already-resolved tenant.
 *
 * Restores the previous tenant context even if the handler throws, so nested
 * middleware chains and background-job contexts remain clean.
 */
class TenantScopeMiddleware implements MiddlewareInterface
{
    /**
     * @var array<string, mixed>
     */
    protected array $config;

    /**
     * @param array<string, mixed> $config Supported keys: `attribute`
     */
    public function __construct(array $config = [])
    {
        $this->config = $config + ['attribute' => 'tenantId'];
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $attribute = (string)$this->config['attribute'];
        $tenantId = $request->getAttribute($attribute);

        if ($tenantId === null) {
            return $handler->handle($request);
        }

        $previousHasTenant = TenantScopeRegistry::hasTenant();
        $previous = $previousHasTenant ? TenantScopeRegistry::getTenant() : null;
        TenantScopeRegistry::setTenant($tenantId);

        try {
            return $handler->handle($request);
        } finally {
            if ($previousHasTenant && $previous !== null) {
                TenantScopeRegistry::setTenant($previous);
            } else {
                TenantScopeRegistry::clear();
            }
        }
    }
}
