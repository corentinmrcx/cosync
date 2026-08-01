<?php declare(strict_types=1);

namespace App\Service\ClubHouse;

use App\DTO\CleDetention;
use App\DTO\AttestationCleRecapRow;
use App\Entity\Season;

/**
 * Construit les lignes du récapitulatif des détenteurs de clés, destiné à la mairie.
 * Le service PDF ne touche donc jamais au registre.
 */
final class AttestationCleRecapService
{
    public function __construct(
        private readonly CleRegistreService $registre,
    ) {}

    /**
     * Détenteurs actuels de clés uniquement, triés par nom. Une personne ayant tout
     * restitué sort du récapitulatif ; son historique et sa feuille individuelle restent.
     *
     * @return AttestationCleRecapRow[]
     */
    public function buildRows(Season $season): array
    {
        $detentions = $this->registre->getDetenteursActuels($season);

        usort($detentions, static fn (CleDetention $a, CleDetention $b): int
            => [$a->dirigeant->getNom(), $a->dirigeant->getPrenom()]
           <=> [$b->dirigeant->getNom(), $b->dirigeant->getPrenom()]);

        return array_map(
            static fn (CleDetention $d): AttestationCleRecapRow => new AttestationCleRecapRow(
                nom: $d->dirigeant->getNom(),
                prenom: $d->dirigeant->getPrenom(),
                nbCles: $d->solde,
                signedAt: $d->aSigne() ? $d->dirigeant->getAttestationCleSignedAt() : null,
                aRenouveler: $d->doitResigner(),
            ),
            $detentions,
        );
    }
}
