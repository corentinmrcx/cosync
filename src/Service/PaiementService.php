<?php declare(strict_types=1);

namespace App\Service;

use App\Entity\Licencie;
use App\Entity\Season;
use App\Entity\Transaction;
use App\Entity\User;
use App\Enum\LicenceStatus;
use App\Enum\PaymentMode;
use App\Repository\TransactionRepository;
use App\Service\Mail\MailerService;
use App\Service\Stock\DotationBesoinSynchronizer;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Encaissements d'un licencié et validation de sa licence.
 *
 * Une licence bascule en VALIDATED dès que le total encaissé atteint la cotisation due —
 * qu'il s'agisse d'une saisie manuelle ou d'un encaissement HelloAsso vérifié.
 */
final class PaiementService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly TransactionRepository $transactionRepo,
        private readonly CotisationResolver $cotisationResolver,
        private readonly DotationBesoinSynchronizer $dotationSynchronizer,
        private readonly MailerService $mailerService,
    ) {}

    /**
     * @param ?User   $confirmedBy       null pour un encaissement automatique en ligne (aucun dirigeant ne le saisit)
     * @param ?string $externalPaymentId identifiant du paiement chez le prestataire — unique en base, garantit
     *                                   qu'un encaissement notifié plusieurs fois n'est enregistré qu'une fois
     */
    public function enregistrer(
        Licencie $licencie,
        PaymentMode $mode,
        float $montant,
        ?string $reference,
        ?string $note,
        \DateTimeImmutable $datePaiement,
        ?User $confirmedBy,
        Season $season,
        ?string $externalPaymentId = null,
    ): void {
        $transaction = (new Transaction())
            ->setLicencie($licencie)
            ->setSeason($season)
            ->setMode($mode)
            ->setMontant(number_format($montant, 2, '.', ''))
            ->setReference($reference)
            ->setNote($note)
            ->setDatePaiement($datePaiement)
            ->setConfirmedBy($confirmedBy)
            ->setExternalPaymentId($externalPaymentId);

        $this->em->persist($transaction);
        $this->em->flush();

        if ($this->soldeAtteint($licencie, $season)) {
            $this->valider($licencie);
        }
    }

    /**
     * Supprime un paiement saisi par erreur. Le total payé est recalculé à l'affichage ;
     * le statut de la licence n'est pas modifié (une licence validée le reste).
     */
    public function supprimer(Transaction $transaction): void
    {
        $this->em->remove($transaction);
        $this->em->flush();
    }

    /** Validation à la main, quand le club encaisse hors circuit (geste commercial, échange). */
    public function validerManuellement(Licencie $licencie): void
    {
        $this->valider($licencie);
    }

    private function soldeAtteint(Licencie $licencie, Season $season): bool
    {
        $du = (float) $this->cotisationResolver->resolve($licencie);

        return $this->transactionRepo->sumByLicencieAndSeason($licencie, $season) >= $du;
    }

    /** Valider ouvre le droit à la dotation et prévient le licencié — une seule fois. */
    private function valider(Licencie $licencie): void
    {
        $dossier = $licencie->getDossierClub();

        if ($dossier === null || $dossier->getStatus() === LicenceStatus::VALIDATED) {
            return;
        }

        $dossier->setStatus(LicenceStatus::VALIDATED);
        $this->em->flush();

        $this->dotationSynchronizer->recomputeForLicencie($licencie);

        if ($licencie->getEmail() !== null) {
            $this->mailerService->sendValidation($licencie);
        }
    }
}
