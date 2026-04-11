<?php

declare(strict_types=1);

namespace Compliance\DualApproval\Traits;

/**
 * Declare which actions on a controller (or any consuming class) require the
 * dual-approval workflow.
 *
 * Usage inside a controller:
 *
 * ```php
 * class CashbookController extends AppController
 * {
 *     use RequiresDualApprovalTrait;
 *
 *     public function initialize(): void
 *     {
 *         parent::initialize();
 *         $this->requiresDualApproval('close_cashbook');
 *     }
 * }
 * ```
 *
 * The middleware can then inspect `protectedDualApprovalActions()` to decide
 * whether to gate a given action.
 */
trait RequiresDualApprovalTrait
{
    /**
     * @var list<string>
     */
    protected array $_dualApprovalActions = [];

    /**
     * Declare one or more actions as requiring dual approval.
     *
     * @param string ...$actions
     */
    public function requiresDualApproval(string ...$actions): void
    {
        foreach ($actions as $action) {
            if (!in_array($action, $this->_dualApprovalActions, true)) {
                $this->_dualApprovalActions[] = $action;
            }
        }
    }

    /**
     * @return list<string>
     */
    public function protectedDualApprovalActions(): array
    {
        return $this->_dualApprovalActions;
    }

    public function requiresApproval(string $action): bool
    {
        return in_array($action, $this->_dualApprovalActions, true);
    }
}
