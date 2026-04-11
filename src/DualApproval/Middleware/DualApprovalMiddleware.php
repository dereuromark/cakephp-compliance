<?php

declare(strict_types=1);

namespace Compliance\DualApproval\Middleware;

use Cake\Http\Response;
use Compliance\DualApproval\ApprovalService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Gate protected actions behind the dual-approval workflow.
 *
 * The middleware looks at a configurable request attribute (default
 * `dualApprovalAction`) to decide whether the incoming request is a
 * protected action. If so, it creates a pending approval via `ApprovalService`
 * and short-circuits the handler with a `202 Accepted` response carrying the
 * new approval id in the `X-Dual-Approval-Id` header.
 *
 * When the second user later re-dispatches the same request with the
 * `dualApprovalResume` attribute set to the approval id, the middleware
 * resolves the approval through `ApprovalService::approve()` and then lets
 * the handler run — executing the originally-requested action under the
 * authority of the second user.
 *
 * Upstream middleware is responsible for authenticating the user and setting
 * the `currentUserId` attribute on the request. This middleware intentionally
 * does no authentication.
 *
 * ```php
 * $middleware->add(new DualApprovalMiddleware([
 *     'protected' => ['close_cashbook', 'issue_dues_run'],
 * ]));
 * ```
 *
 * @phpstan-type MiddlewareConfig array{
 *     protected?: list<string>,
 *     actionAttribute?: string,
 *     userAttribute?: string,
 *     payloadAttribute?: string,
 *     resumeAttribute?: string,
 * }
 */
class DualApprovalMiddleware implements MiddlewareInterface
{
    /**
     * @var array<string, mixed>
     */
    protected array $config;

    protected ApprovalService $service;

    /**
     * @param array<string, mixed> $config
     * @param \Compliance\DualApproval\ApprovalService|null $service
     */
    public function __construct(array $config = [], ?ApprovalService $service = null)
    {
        $this->config = $config + [
            'protected' => [],
            'actionAttribute' => 'dualApprovalAction',
            'userAttribute' => 'currentUserId',
            'payloadAttribute' => 'dualApprovalPayload',
            'resumeAttribute' => 'dualApprovalResume',
        ];
        $this->service = $service ?? new ApprovalService();
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $resumeId = $request->getAttribute((string)$this->config['resumeAttribute']);
        if ($resumeId !== null) {
            $this->service->approve((int)$resumeId, (string)$request->getAttribute(
                (string)$this->config['userAttribute'],
            ));

            return $handler->handle($request);
        }

        $action = $request->getAttribute((string)$this->config['actionAttribute']);
        if ($action === null) {
            return $handler->handle($request);
        }

        /** @var list<string> $protected */
        $protected = (array)$this->config['protected'];
        if (!in_array($action, $protected, true)) {
            return $handler->handle($request);
        }

        $userId = (string)$request->getAttribute((string)$this->config['userAttribute']);
        /** @var array<string, mixed> $payload */
        $payload = (array)($request->getAttribute((string)$this->config['payloadAttribute']) ?? []);

        $approval = $this->service->request((string)$action, $payload, $userId);

        return (new Response())
            ->withStatus(202)
            ->withHeader('X-Dual-Approval-Id', (string)$approval->get('id'));
    }
}
