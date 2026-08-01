<?php declare(strict_types=1);

namespace App\DTO;

final class CleRegistreStats
{
    public function __construct(
        /** Clés actuellement détenues par des personnes — pas le total confié par la mairie */
        public readonly int $clesEnCirculation,
        public readonly int $nbDetenteurs,
        public readonly int $clesPerdues,
        public readonly int $clesRestituees,
        public readonly int $nbAttestationsSignees,
        public readonly int $nbAttestationsManquantes,
    ) {}
}
