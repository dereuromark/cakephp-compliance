<?php

declare(strict_types=1);

namespace Compliance\Test\TestCase\DualApproval;

use Compliance\DualApproval\Traits\RequiresDualApprovalTrait;
use PHPUnit\Framework\TestCase;

class RequiresDualApprovalTraitTest extends TestCase
{
    public function testDeclareAddsActionsToProtectedList(): void
    {
        $target = new class {
            use RequiresDualApprovalTrait;
        };
        $target->requiresDualApproval('close_cashbook', 'issue_dues_run');
        $this->assertSame(
            ['close_cashbook', 'issue_dues_run'],
            $target->protectedDualApprovalActions(),
        );
    }

    public function testRequiresApprovalReportsTruthyForDeclaredAction(): void
    {
        $target = new class {
            use RequiresDualApprovalTrait;
        };
        $target->requiresDualApproval('close_cashbook');
        $this->assertTrue($target->requiresApproval('close_cashbook'));
        $this->assertFalse($target->requiresApproval('view_cashbook'));
    }

    public function testMultipleDeclareCallsAccumulate(): void
    {
        $target = new class {
            use RequiresDualApprovalTrait;
        };
        $target->requiresDualApproval('close_cashbook');
        $target->requiresDualApproval('issue_dues_run');
        $this->assertSame(
            ['close_cashbook', 'issue_dues_run'],
            $target->protectedDualApprovalActions(),
        );
    }

    public function testDeclareIsIdempotent(): void
    {
        $target = new class {
            use RequiresDualApprovalTrait;
        };
        $target->requiresDualApproval('close_cashbook');
        $target->requiresDualApproval('close_cashbook');
        $this->assertSame(
            ['close_cashbook'],
            $target->protectedDualApprovalActions(),
        );
    }
}
