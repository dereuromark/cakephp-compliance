<?php

declare(strict_types=1);

namespace Compliance\Gobd;

final class ChainVerificationResult
{
    public function __construct(
        public readonly bool $intact,
        public readonly int $rowsChecked,
        public readonly ?int $brokenRowId,
        public readonly ?string $reason,
    ) {
    }

    public static function intact(int $rowsChecked): self
    {
        return new self(true, $rowsChecked, null, null);
    }

    public static function broken(int $rowId, int $rowsChecked, string $reason): self
    {
        return new self(false, $rowsChecked, $rowId, $reason);
    }
}
