<?php declare(strict_types=1);

namespace App\DTO\Planning;

/**
 * Un match tel qu'une source extérieure le décrit, **déjà normalisé**.
 *
 * C'est la frontière du domaine : le mapper FFF et le parseur de collage produisent tous
 * deux cet objet, et rien en aval ne sait d'où vient la ligne. Ajouter une troisième
 * source (un fichier iCal, un autre district) ne touchera donc ni la synchronisation ni
 * l'enregistrement.
 *
 * `fffMaNo` est null pour tout ce qui ne vient pas du flux fédéral — c'est lui, et lui
 * seul, qui rend une ligne réconciliable d'une synchronisation à l'autre.
 */
final class MatchImporteData
{
    public function __construct(
        public readonly \DateTimeImmutable $date,
        public readonly ?string $heure,
        public readonly string $categorie,
        public readonly ?string $adversaire,
        public readonly ?int $fffMaNo = null,
        public readonly ?string $fffCompetition = null,
        public readonly ?string $fffTerrain = null,
        public readonly ?string $note = null,
    ) {}

    /** Libellé court pour les rapports de synchronisation et l'aperçu de collage. */
    public function resume(): string
    {
        return sprintf(
            '%s%s — %s%s',
            $this->date->format('d/m/Y'),
            $this->heure !== null ? ' ' . $this->heure : '',
            $this->categorie,
            $this->adversaire !== null ? ' contre ' . $this->adversaire : '',
        );
    }
}
