<?php

declare(strict_types=1);

namespace Compliance\Gobd\Exception;

use RuntimeException;

/**
 * Thrown when an attempt is made to delete a row that is still inside the
 * GoBD retention window (§147 AO, default 10 years).
 */
class GobdRetentionException extends RuntimeException
{
}
