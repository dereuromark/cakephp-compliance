<?php

declare(strict_types=1);

use Migrations\BaseMigration;

/**
 * Dual-approval workflow backing table.
 *
 * Persists requests that require a second user to approve or reject
 * before they actually execute. See docs/DualApproval.md and
 * `Compliance\Model\Table\PendingApprovalsTable` for the runtime
 * integration.
 *
 * `payload` stores a JSON blob of whatever data the initiator wants to
 * replay on approval — `PendingApprovalsTable::initialize()` registers
 * the column as a json type so consumers pass/receive arrays.
 */
class CreatePendingApprovals extends BaseMigration
{
    public function change(): void
    {
        $this->table('pending_approvals')
            ->addColumn('action', 'string', [
                'limit' => 100,
                'null' => false,
                'comment' => 'Symbolic name of the action being guarded (e.g. BankTransaction.reimport).',
            ])
            ->addColumn('payload', 'text', [
                'null' => false,
                'comment' => 'JSON payload replayed when the approval is accepted.',
            ])
            ->addColumn('initiator_id', 'string', [
                'limit' => 50,
                'null' => false,
            ])
            ->addColumn('approver_id', 'string', [
                'limit' => 50,
                'null' => true,
                'default' => null,
            ])
            ->addColumn('status', 'string', [
                'limit' => 20,
                'null' => false,
                'default' => 'pending',
                'comment' => 'pending | approved | rejected',
            ])
            ->addColumn('reason', 'text', [
                'null' => true,
                'default' => null,
            ])
            ->addColumn('created', 'datetime', ['null' => false])
            ->addColumn('modified', 'datetime', ['null' => false])
            ->addColumn('resolved_at', 'datetime', [
                'null' => true,
                'default' => null,
            ])
            ->addIndex(['status'], ['name' => 'idx_pending_approvals_status'])
            ->addIndex(['initiator_id'], ['name' => 'idx_pending_approvals_initiator'])
            ->create();
    }
}
