<?php declare(strict_types=1);

namespace App\DTO;

final class DirigeantPublicFormData
{
    public function __construct(
        public readonly string $tailleHaut,
        public readonly string $tailleBas,
        public readonly string $pointure,
    ) {}
}
