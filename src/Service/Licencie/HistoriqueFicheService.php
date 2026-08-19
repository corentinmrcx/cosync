<?php declare(strict_types=1);

namespace App\Service\Licencie;

use App\DTO\EvenementHistorique;
use App\Entity\Dirigeant;
use App\Entity\Licencie;
use App\Entity\Transaction;
use App\Enum\PaymentMode;
use App\Repository\AttestationCleRepository;
use App\Service\Cle\DetenteurEffectifResolver;
use App\Service\Document\DocumentRequirementResolver;

/**
 * Chronologie affichée sur une fiche : ce qui s'est passé pour cette personne, dans l'ordre.
 */
final class HistoriqueFicheService
{
    public function __construct(
        private readonly DocumentRequirementResolver $documentResolver,
        private readonly DetenteurEffectifResolver $effectifResolver,
        private readonly AttestationCleRepository $attestationCleRepo,
    ) {}

    /**
     * @param Transaction[] $transactions
     *
     * @return EvenementHistorique[]
     */
    public function pourLicencie(Licencie $licencie, array $transactions): array
    {
        $evenements = [new EvenementHistorique(
            $licencie->getImportedAt(),
            $licencie->isCreatedManually() ? 'Licencié créé manuellement' : 'Licencié importé depuis FootClubs',
            'Admin',
        )];

        if ($licencie->getLinkSentAt() !== null) {
            $evenements[] = new EvenementHistorique(
                $licencie->getLinkSentAt(),
                'Lien d\'inscription envoyé par email',
                'Système',
            );
        }

        $formCompletedAt = $licencie->getDossierClub()?->getFormCompletedAt();
        if ($formCompletedAt !== null) {
            $evenements[] = new EvenementHistorique(
                $formCompletedAt,
                'Formulaire complété par le licencié',
                'Licencié',
            );
        }

        // Affiché à sa date de paiement (celle du chèque), rangé à son heure de saisie :
        // sans quoi minuit le ferait passer devant le formulaire qui l'a déclenché.
        foreach ($transactions as $transaction) {
            $evenements[] = new EvenementHistorique(
                $transaction->getDatePaiement(),
                sprintf('Paiement enregistré — %s %s €', $transaction->getMode()->label(), $transaction->getMontant()),
                $this->auteurDuPaiement($transaction),
                'd/m/Y',
                $transaction->getCreatedAt(),
            );
        }

        return $this->parOrdreChronologique($evenements);
    }

    /** @return EvenementHistorique[] */
    public function pourDirigeant(Dirigeant $dirigeant): array
    {
        $nomPrenom = $dirigeant->getNomPrenom();

        $evenements = [new EvenementHistorique(
            $dirigeant->getImportedAt(),
            $dirigeant->isCreatedManually() ? 'Dirigeant créé manuellement' : 'Dirigeant importé depuis FootClubs',
            'Admin',
        )];

        if ($dirigeant->getLinkSentAt() !== null) {
            $evenements[] = new EvenementHistorique(
                $dirigeant->getLinkSentAt(),
                'Lien de formulaire envoyé par email',
                'Système',
            );
        }

        if ($dirigeant->getFormCompletedAt() !== null) {
            $evenements[] = new EvenementHistorique(
                $dirigeant->getFormCompletedAt(),
                'Formulaire équipement complété',
                $nomPrenom,
            );
        }

        // Les attestations de clés suivent le détenteur, pas le dirigeant : la fiche
        // affiche donc toutes celles de la personne, saison par saison.
        $detenteur = $this->effectifResolver->detenteurDe($dirigeant);

        if ($detenteur !== null) {
            foreach ($this->attestationCleRepo->findSigneesDe($detenteur) as $attestation) {
                $evenements[] = new EvenementHistorique(
                    $attestation->getSignedAt(),
                    sprintf('Attestation de remise de clés signée — saison %s', $attestation->getSeason()->getLabel()),
                    $nomPrenom,
                );
            }
        }

        foreach ($this->documentResolver->signaturesParDocumentPourDirigeant($dirigeant) as $signature) {
            $evenements[] = new EvenementHistorique(
                $signature->getSignedAt(),
                $signature->getDocument()->getTitre() . ' signé',
                $nomPrenom,
            );
        }

        return $this->parOrdreChronologique($evenements);
    }

    /** Aucun dirigeant sur un encaissement en ligne : c'est HelloAsso qui l'a confirmé. */
    private function auteurDuPaiement(Transaction $transaction): string
    {
        return $transaction->getConfirmedBy()?->getEmail()
            ?? ($transaction->getMode() === PaymentMode::CB_ONLINE ? 'HelloAsso' : 'Admin');
    }

    /**
     * @param EvenementHistorique[] $evenements
     *
     * @return EvenementHistorique[]
     */
    private function parOrdreChronologique(array $evenements): array
    {
        usort($evenements, static fn (EvenementHistorique $a, EvenementHistorique $b): int => $a->triDate <=> $b->triDate);

        return $evenements;
    }
}
