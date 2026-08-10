<?php declare(strict_types=1);

namespace App\DTO;

use App\Enum\DotationCibleType;

final class DotationAffectationData
{
    public function __construct(
        public readonly int $modeleId,
        public readonly DotationCibleType $cibleType,
        public readonly ?string $cibleId,
    ) {}
}
