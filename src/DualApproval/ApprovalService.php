<?php

declare(strict_types=1);

namespace Compliance\DualApproval;

use Cake\I18n\DateTime;
use Cake\ORM\TableRegistry;
use Compliance\DualApproval\Exception\ApprovalConflictException;
use Compliance\DualApproval\Exception\ApprovalNotFoundException;
use Compliance\Model\Entity\PendingApproval;
use Compliance\Model\Table\PendingApprovalsTable;

/**
 * Two-person integrity workflow service.
 *
 * Actions that require dual approval (closing a Kassenbuch, issuing a
 * DuesRun, modifying a Kaution, etc.) are captured via `request()` and held
 * in the `pending_approvals` table until a *second* user resolves them via
 * `approve()` or `reject()`.
 *
 * The core invariant: the approving user MUST differ from the initiating
 * user. The same person cannot both request and approve an action — that
 * is the entire point of the plugin and is enforced at the service layer.
 */
class ApprovalService
{
    protected PendingApprovalsTable $table;

    public function __construct(?PendingApprovalsTable $table = null)
    {
        /** @var \Compliance\Model\Table\PendingApprovalsTable $resolved */
        $resolved = $table ?? TableRegistry::getTableLocator()->get('Compliance.PendingApprovals');
        $this->table = $resolved;
    }

    /**
     * @param string $action
     * @param array<string, mixed> $payload
     * @param string $initiatorId
     */
    public function request(string $action, array $payload, string $initiatorId): PendingApproval
    {
        $entity = $this->table->newEntity([
            'action' => $action,
            'payload' => $payload,
            'initiator_id' => $initiatorId,
            'status' => PendingApproval::STATUS_PENDING,
        ]);

        return $this->table->saveOrFail($entity);
    }

    public function approve(int $id, string $approverId): PendingApproval
    {
        $entity = $this->loadPending($id, $approverId);
        $entity->set('status', PendingApproval::STATUS_APPROVED);
        $entity->set('approver_id', $approverId);
        $entity->set('resolved_at', new DateTime());

        return $this->table->saveOrFail($entity);
    }

    public function reject(int $id, string $approverId, string $reason): PendingApproval
    {
        $entity = $this->loadPending($id, $approverId);
        $entity->set('status', PendingApproval::STATUS_REJECTED);
        $entity->set('approver_id', $approverId);
        $entity->set('reason', $reason);
        $entity->set('resolved_at', new DateTime());

        return $this->table->saveOrFail($entity);
    }

    public function find(int $id): PendingApproval
    {
        /** @var \Compliance\Model\Entity\PendingApproval|null $entity */
        $entity = $this->table->find()->where(['id' => $id])->first();
        if ($entity === null) {
            throw new ApprovalNotFoundException(sprintf('Pending approval %d not found.', $id));
        }

        return $entity;
    }

    /**
     * Load a pending approval and enforce the two-person rule against the
     * supplied acting user.
     *
     * @throws \Compliance\DualApproval\Exception\ApprovalConflictException
     */
    protected function loadPending(int $id, string $actorId): PendingApproval
    {
        $entity = $this->find($id);
        if ($entity->get('status') !== PendingApproval::STATUS_PENDING) {
            throw new ApprovalConflictException(sprintf(
                'Approval %d is already resolved with status "%s".',
                $id,
                (string)$entity->get('status'),
            ));
        }
        if ($entity->get('initiator_id') === $actorId) {
            throw new ApprovalConflictException(sprintf(
                'User "%s" cannot approve or reject their own request (approval %d).',
                $actorId,
                $id,
            ));
        }

        return $entity;
    }
}
