<?php

declare(strict_types=1);

namespace Compliance\Test\TestCase\DualApproval;

use Cake\Datasource\ConnectionManager;
use Cake\ORM\TableRegistry;
use Compliance\DualApproval\ApprovalService;
use Compliance\DualApproval\Exception\ApprovalConflictException;
use Compliance\DualApproval\Exception\ApprovalNotFoundException;
use PHPUnit\Framework\TestCase;

class ApprovalServiceTest extends TestCase
{
    protected ApprovalService $service;

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
        $this->service = new ApprovalService();
    }

    protected function tearDown(): void
    {
        TableRegistry::getTableLocator()->clear();
        parent::tearDown();
    }

    public function testRequestCreatesPendingRow(): void
    {
        $approval = $this->service->request(
            action: 'close_cashbook',
            payload: ['year' => 2026],
            initiatorId: 'user-1',
        );
        $this->assertSame('pending', $approval->get('status'));
        $this->assertSame('user-1', $approval->get('initiator_id'));
        $this->assertNull($approval->get('approver_id'));
        $this->assertSame(['year' => 2026], $approval->get('payload'));
    }

    public function testApproveMarksResolved(): void
    {
        $approval = $this->service->request('close_cashbook', ['year' => 2026], 'user-1');
        $approved = $this->service->approve((int)$approval->get('id'), 'user-2');
        $this->assertSame('approved', $approved->get('status'));
        $this->assertSame('user-2', $approved->get('approver_id'));
        $this->assertNotNull($approved->get('resolved_at'));
    }

    public function testApproveBySameUserIsRejected(): void
    {
        $approval = $this->service->request('close_cashbook', ['year' => 2026], 'user-1');
        $this->expectException(ApprovalConflictException::class);
        $this->service->approve((int)$approval->get('id'), 'user-1');
    }

    public function testRejectMarksRejectedAndCaptuesReason(): void
    {
        $approval = $this->service->request('close_cashbook', ['year' => 2026], 'user-1');
        $rejected = $this->service->reject((int)$approval->get('id'), 'user-2', 'Missing documentation');
        $this->assertSame('rejected', $rejected->get('status'));
        $this->assertSame('user-2', $rejected->get('approver_id'));
        $this->assertSame('Missing documentation', $rejected->get('reason'));
    }

    public function testRejectBySameUserIsRejected(): void
    {
        $approval = $this->service->request('close_cashbook', ['year' => 2026], 'user-1');
        $this->expectException(ApprovalConflictException::class);
        $this->service->reject((int)$approval->get('id'), 'user-1', 'Nope');
    }

    public function testApproveUnknownIdThrows(): void
    {
        $this->expectException(ApprovalNotFoundException::class);
        $this->service->approve(999, 'user-2');
    }

    public function testAlreadyResolvedRequestCannotBeApprovedAgain(): void
    {
        $approval = $this->service->request('close_cashbook', ['year' => 2026], 'user-1');
        $this->service->approve((int)$approval->get('id'), 'user-2');
        $this->expectException(ApprovalConflictException::class);
        $this->service->approve((int)$approval->get('id'), 'user-3');
    }

    public function testAlreadyResolvedRequestCannotBeRejected(): void
    {
        $approval = $this->service->request('close_cashbook', ['year' => 2026], 'user-1');
        $this->service->approve((int)$approval->get('id'), 'user-2');
        $this->expectException(ApprovalConflictException::class);
        $this->service->reject((int)$approval->get('id'), 'user-3', 'Too late');
    }

    public function testPayloadRoundTripsThroughJson(): void
    {
        $payload = [
            'ints' => [1, 2, 3],
            'nested' => ['key' => 'value'],
            'umlaut' => 'Größe',
        ];
        $approval = $this->service->request('close_cashbook', $payload, 'user-1');
        $fresh = $this->service->find((int)$approval->get('id'));
        $this->assertSame($payload, $fresh->get('payload'));
    }
}
