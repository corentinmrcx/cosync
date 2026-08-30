<?php declare(strict_types=1);

namespace App\DTO;

use App\Entity\RoleAcces;

/**
 * Un rôle dans la liste, avec ce que l'écran a le droit d'en proposer.
 *
 * Le motif de blocage est *rendu*, pas réduit à un booléen : un bouton qui disparaît sans
 * explication laisse l'admin chercher ce qu'il a mal fait.
 */
final class LigneRoleAcces
{
    public function __construct(
        public readonly RoleAcces $role,
        public readonly int $comptes,
        public readonly ?string $motifBlocageSuppression,
    ) {}

    public function estSupprimable(): bool
    {
        return $this->motifBlocageSuppression === null;
    }
}
