<?php declare(strict_types=1);

namespace App\DTO;

use App\Enum\DirigeantRole;
use App\Enum\DocumentCible;

/** Saisie de l'écran d'administration d'un document signable. */
final class DocumentSignableData
{
    /**
     * @param DirigeantRole[] $roles      rôles visés ; vide = aucun ciblage par rôle
     * @param string[]        $dirigeants uuids des dirigeants nommément désignés
     */
    public function __construct(
        public readonly string $titre,
        public readonly string $libelle,
        public readonly ?string $contenuHtml,
        public readonly DocumentCible $cible,
        public readonly array $roles = [],
        public readonly array $dirigeants = [],
        public readonly bool $actif = true,
    ) {}
}
