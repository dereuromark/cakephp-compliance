<?php

declare(strict_types=1);

namespace Compliance\Gobd\Exception;

use RuntimeException;

/**
 * Thrown when a save or delete is attempted on a row that has been finalized.
 */
class ImmutableRowException extends RuntimeException
{
}
