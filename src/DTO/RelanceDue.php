<?php declare(strict_types=1);

namespace App\DTO;

use App\Entity\Licencie;
use App\Enum\EtapeRelance;

/**
 * Une personne que le club doit relancer, avec de quoi l'afficher sans repasser en base.
 *
 * Le DTO porte le `Licencie` lui-même — l'écran de relance groupée en a besoin pour le
 * recroisement des uuid, et l'envoi suit dans la même passe.
 */
final class RelanceDue
{
    public function __construct(
        public readonly Licencie $licencie,
        public readonly EtapeRelance $etape,
        /** Date du dernier mail reçu, quel qu'il soit — jamais null : une relance suppose un premier contact. */
        public readonly DernierContact $dernierContact,
        /** Numéro de la relance à venir : 1 pour la première, 2 pour la suivante… */
        public readonly int $numero,
    ) {}

    public function uuid(): string
    {
        return (string) $this->licencie->getUuid();
    }

    public function nomPrenom(): string
    {
        return $this->licencie->getNomPrenom();
    }

    public function equipe(): string
    {
        return $this->licencie->getTeam()?->getName() ?? $this->licencie->getCategory()->getLabel();
    }
}
