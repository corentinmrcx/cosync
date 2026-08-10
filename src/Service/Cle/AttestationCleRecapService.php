<?php declare(strict_types=1);

namespace App\Service\Cle;

use App\DTO\AttestationCleRecapRow;
use App\DTO\CleRegistreRow;
use App\Entity\Season;

/**
 * Construit les lignes du récapitulatif des détenteurs de clés, destiné à la mairie.
 * Le service PDF ne touche donc jamais au registre.
 */
final class AttestationCleRecapService
{
    public function __construct(
        private readonly CleRegistrePresenter $presenter,
    ) {}

    /**
     * Détenteurs actuels de clés uniquement, triés par nom. Une personne ayant tout
     * restitué sort du récapitulatif ; son historique et ses attestations restent.
     *
     * @return AttestationCleRecapRow[]
     */
    public function buildRows(Season $season): array
    {
        $lignes = array_filter(
            $this->presenter->lignes($season),
            static fn (CleRegistreRow $ligne): bool => $ligne->estDetenteur(),
        );

        usort($lignes, static fn (CleRegistreRow $a, CleRegistreRow $b): int => [$a->detenteur()->getNom(), $a->detenteur()->getPrenom()]
           <=> [$b->detenteur()->getNom(), $b->detenteur()->getPrenom()]);

        return array_map(
            static fn (CleRegistreRow $ligne): AttestationCleRecapRow => new AttestationCleRecapRow(
                nom: $ligne->detenteur()->getNom(),
                prenom: $ligne->detenteur()->getPrenom(),
                nbCles: $ligne->detention->solde,
                signedAt: $ligne->signedAt(),
                aRenouveler: !$ligne->attestationAJour() && $ligne->signedAt() !== null,
            ),
            $lignes,
        );
    }
}
