<?php

declare(strict_types=1);

namespace Compliance\Test\TestCase\TenantScope;

use Cake\Http\Response;
use Cake\Http\ServerRequest;
use Compliance\TenantScope\Middleware\TenantScopeMiddleware;
use Compliance\TenantScope\TenantScopeRegistry;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

class TenantScopeMiddlewareTest extends TestCase
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

    public function testMiddlewareResolvesTenantFromRequestAttribute(): void
    {
        $middleware = new TenantScopeMiddleware();
        $request = (new ServerRequest())->withAttribute('tenantId', 'tenant-99');

        $handler = new class implements RequestHandlerInterface {
            public ?string $seenTenant = null;

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $this->seenTenant = (string)TenantScopeRegistry::getTenant();

                return new Response();
            }
        };

        $middleware->process($request, $handler);

        $this->assertSame('tenant-99', $handler->seenTenant);
    }

    public function testMiddlewareClearsTenantAfterRequest(): void
    {
        $middleware = new TenantScopeMiddleware();
        $request = (new ServerRequest())->withAttribute('tenantId', 'tenant-99');

        $handler = new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new Response();
            }
        };

        $middleware->process($request, $handler);

        $this->assertFalse(TenantScopeRegistry::hasTenant());
    }

    public function testMiddlewareUsesCustomAttributeName(): void
    {
        $middleware = new TenantScopeMiddleware(['attribute' => 'accountId']);
        $request = (new ServerRequest())->withAttribute('accountId', 'acct-7');

        $handler = new class implements RequestHandlerInterface {
            public ?string $seenTenant = null;

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $this->seenTenant = (string)TenantScopeRegistry::getTenant();

                return new Response();
            }
        };

        $middleware->process($request, $handler);

        $this->assertSame('acct-7', $handler->seenTenant);
    }

    public function testMiddlewareWithoutAttributeLeavesRegistryEmpty(): void
    {
        $middleware = new TenantScopeMiddleware();
        $request = new ServerRequest();

        $handler = new class implements RequestHandlerInterface {
            public bool $hasTenant = true;

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $this->hasTenant = TenantScopeRegistry::hasTenant();

                return new Response();
            }
        };

        $middleware->process($request, $handler);

        $this->assertFalse($handler->hasTenant);
    }
}
