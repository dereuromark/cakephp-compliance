<?php

declare(strict_types=1);

namespace Compliance\DualApproval\Exception;

use RuntimeException;

/**
 * Thrown when an approval action violates the two-person integrity rule —
 * e.g. the same user tries to approve their own request, or a second
 * approval decision is attempted on an already-resolved approval.
 */
class ApprovalConflictException extends RuntimeException
{
}
