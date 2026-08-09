<?php declare(strict_types=1);

namespace App\Service;

use App\DTO\EvenementHistorique;
use App\Entity\Dirigeant;
use App\Entity\Licencie;
use App\Entity\Transaction;
use App\Enum\PaymentMode;
use App\Service\Document\DocumentRequirementResolver;

/**
 * Chronologie affichée sur une fiche : ce qui s'est passé pour cette personne, dans l'ordre.
 */
final class HistoriqueFicheService
{
    public function __construct(
        private readonly DocumentRequirementResolver $documentResolver,
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

        foreach ($transactions as $transaction) {
            $evenements[] = new EvenementHistorique(
                $transaction->getDatePaiement(),
                sprintf('Paiement enregistré — %s %s €', $transaction->getMode()->label(), $transaction->getMontant()),
                $this->auteurDuPaiement($transaction),
                'd/m/Y',
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

        $expiration = $dirigeant->getFormTokenExpiresAt();
        if ($expiration !== null) {
            $evenements[] = new EvenementHistorique(
                LienPublic::envoiDeduitDe($expiration),
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

        if ($dirigeant->getAttestationCleSignedAt() !== null) {
            $evenements[] = new EvenementHistorique(
                $dirigeant->getAttestationCleSignedAt(),
                'Attestation de remise de clés signée',
                $nomPrenom,
            );
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
        usort($evenements, static fn (EvenementHistorique $a, EvenementHistorique $b): int => $a->date <=> $b->date);

        return $evenements;
    }
}
