<?php

declare(strict_types=1);

namespace Compliance\Test\TestCase\DualApproval;

use Cake\Datasource\ConnectionManager;
use Cake\Http\Response;
use Cake\Http\ServerRequest;
use Cake\ORM\TableRegistry;
use Compliance\DualApproval\ApprovalService;
use Compliance\DualApproval\Middleware\DualApprovalMiddleware;
use Compliance\Model\Entity\PendingApproval;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

class DualApprovalMiddlewareTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $connection = ConnectionManager::get('test');
        $connection->execute('DROP TABLE IF EXISTS pending_approvals');
        $connection->execute(
            'CREATE TABLE pending_approvals (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                action VARCHAR(100) NOT NULL,
                payload TEXT NOT NULL,
                initiator_id VARCHAR(50) NOT NULL,
                approver_id VARCHAR(50) NULL,
                status VARCHAR(20) NOT NULL,
                reason TEXT NULL,
                created DATETIME NOT NULL,
                modified DATETIME NOT NULL,
                resolved_at DATETIME NULL
            )',
        );
        TableRegistry::getTableLocator()->clear();
    }

    protected function tearDown(): void
    {
        TableRegistry::getTableLocator()->clear();
        parent::tearDown();
    }

    public function testMiddlewareLetsUnprotectedActionsThroughUntouched(): void
    {
        $middleware = new DualApprovalMiddleware([
            'protected' => [],
        ]);

        $handler = $this->passThroughHandler();
        $response = $middleware->process(new ServerRequest(), $handler);
        $this->assertSame(200, $response->getStatusCode());
    }

    public function testMiddlewareCreatesPendingApprovalForProtectedAction(): void
    {
        $middleware = new DualApprovalMiddleware([
            'protected' => ['close_cashbook'],
            'actionAttribute' => 'dualApprovalAction',
            'userAttribute' => 'currentUserId',
            'payloadAttribute' => 'dualApprovalPayload',
        ]);

        $request = (new ServerRequest())
            ->withAttribute('dualApprovalAction', 'close_cashbook')
            ->withAttribute('currentUserId', 'user-1')
            ->withAttribute('dualApprovalPayload', ['year' => 2026]);

        $handler = $this->passThroughHandler();
        $response = $middleware->process($request, $handler);

        $this->assertSame(202, $response->getStatusCode());

        $service = new ApprovalService();
        $approvals = $service->find(1);
        $this->assertSame('pending', $approvals->get('status'));
        $this->assertSame('user-1', $approvals->get('initiator_id'));
    }

    public function testMiddlewareShortCircuitsHandlerOnProtectedAction(): void
    {
        $middleware = new DualApprovalMiddleware(['protected' => ['close_cashbook']]);
        $request = (new ServerRequest())
            ->withAttribute('dualApprovalAction', 'close_cashbook')
            ->withAttribute('currentUserId', 'user-1');

        $handler = new class implements RequestHandlerInterface {
            public bool $called = false;

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $this->called = true;

                return new Response();
            }
        };

        $middleware->process($request, $handler);
        $this->assertFalse($handler->called, 'Handler should not run when protected action is in flight.');
    }

    public function testMiddlewarePassesThroughWhenActionAttributeAbsent(): void
    {
        $middleware = new DualApprovalMiddleware(['protected' => ['close_cashbook']]);
        $handler = $this->passThroughHandler();
        $response = $middleware->process(new ServerRequest(), $handler);
        $this->assertSame(200, $response->getStatusCode());
    }

    public function testMiddlewareResolvesPendingApprovalOnCallback(): void
    {
        $service = new ApprovalService();
        $approval = $service->request('close_cashbook', ['year' => 2026], 'user-1');

        $middleware = new DualApprovalMiddleware([
            'protected' => ['close_cashbook'],
            'resumeAttribute' => 'dualApprovalResume',
        ]);

        $request = (new ServerRequest())
            ->withAttribute('dualApprovalResume', (int)$approval->get('id'))
            ->withAttribute('currentUserId', 'user-2');

        $handler = new class implements RequestHandlerInterface {
            public bool $called = false;

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $this->called = true;

                return new Response();
            }
        };

        $middleware->process($request, $handler);
        $this->assertTrue($handler->called, 'Handler should run when a matching approval exists.');
        $fresh = $service->find((int)$approval->get('id'));
        $this->assertSame(PendingApproval::STATUS_APPROVED, $fresh->get('status'));
        $this->assertSame('user-2', $fresh->get('approver_id'));
    }

    protected function passThroughHandler(): RequestHandlerInterface
    {
        return new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new Response();
            }
        };
    }
}
