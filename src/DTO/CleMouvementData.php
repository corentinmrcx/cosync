<?php declare(strict_types=1);

namespace App\DTO;

use App\Enum\CleMouvementType;

final class CleMouvementData
{
    public function __construct(
        public readonly CleMouvementType $type,
        public readonly int $quantite,
        public readonly \DateTimeImmutable $dateMouvement,
        public readonly ?string $note = null,
    ) {}
}
