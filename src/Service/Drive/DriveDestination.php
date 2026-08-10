<?php declare(strict_types=1);

namespace App\Service\Drive;

/**
 * Où ranger un fichier sur le Drive du club : sous le dossier de la saison, puis les
 * segments donnés — les dossiers manquants sont créés à la volée.
 */
final class DriveDestination
{
    /** @param string[] $segments */
    public function __construct(
        public readonly string $saison,
        public readonly array $segments,
        public readonly string $nomFichier,
        public readonly string $referenceLog = '',
    ) {}
}
