<?php declare(strict_types=1);

namespace App\DTO;

use App\Entity\Detenteur;

/**
 * État de détention d'une personne, dérivé de son historique de mouvements sur
 * toute la vie du club. Jamais persisté : recalculé à chaque lecture par
 * CleRegistreService.
 *
 * Ne dit rien des attestations : celles-ci dépendent d'une saison, que le registre
 * ne connaît pas. C'est CleRegistreRow qui rapproche les deux.
 */
final class CleDetention
{
    public function __construct(
        public readonly Detenteur $detenteur,
        public readonly int $remises,
        public readonly int $restitutions,
        public readonly int $pertes,
        public readonly int $solde,
        /** Date de la remise qui a fait passer le solde de 0 à >0 — null si la personne ne détient plus rien */
        public readonly ?\DateTimeImmutable $detenteurDepuis,
        /** Date de la dernière remise, même si elle n'a fait qu'augmenter un solde déjà positif */
        public readonly ?\DateTimeImmutable $derniereRemiseLe,
        public readonly ?\DateTimeImmutable $dernierMouvementLe,
    ) {}

    public function estDetenteur(): bool
    {
        return $this->solde > 0;
    }
}
