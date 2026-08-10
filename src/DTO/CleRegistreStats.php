<?php declare(strict_types=1);

namespace App\DTO;

/**
 * Synthèse du registre. Les compteurs de clés valent pour le club entier ; ceux
 * d'attestations valent pour la saison affichée, puisque l'engagement est annuel.
 */
final class CleRegistreStats
{
    public function __construct(
        /** Clés actuellement détenues par des personnes — pas le total confié par la mairie */
        public readonly int $clesEnCirculation,
        public readonly int $nbDetenteurs,
        public readonly int $clesPerdues,
        public readonly int $clesRestituees,
        /** Détenteurs qui ne figurent plus à l'effectif de la saison affichée */
        public readonly int $nbHorsEffectif,
        public readonly int $nbAttestationsSignees,
        public readonly int $nbAttestationsManquantes,
    ) {}
}
