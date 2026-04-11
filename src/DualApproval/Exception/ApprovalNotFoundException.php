<?php

declare(strict_types=1);

namespace Compliance\DualApproval\Exception;

use RuntimeException;

/**
 * Thrown when an approval action is requested against an ID that does not
 * exist in the `pending_approvals` table.
 */
class ApprovalNotFoundException extends RuntimeException
{
}
