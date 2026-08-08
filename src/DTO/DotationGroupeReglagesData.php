<?php declare(strict_types=1);

namespace App\DTO;

/**
 * Réglages de toutes les options d'un même groupe de choix, enregistrés en une fois.
 * Indexé par id de ligne — le service ne retient que les lignes appartenant réellement
 * au groupe visé, ce qui rend inopérant un id posté au hasard.
 */
final class DotationGroupeReglagesData
{
    /** @param array<int, DotationLigneReglagesData> $parLigne */
    public function __construct(
        public readonly array $parLigne,
    ) {}
}
