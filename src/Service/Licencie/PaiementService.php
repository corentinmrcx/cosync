<?php declare(strict_types=1);

namespace App\Service\Licencie;

use App\Entity\Licencie;
use App\Entity\Season;
use App\Entity\Transaction;
use App\Entity\User;
use App\Enum\LicenceStatus;
use App\Enum\PaymentMode;
use App\Repository\TransactionRepository;
use App\Service\Dotation\DotationBesoinSynchronizer;
use App\Service\Mail\MailerService;
use App\Service\Payment\CotisationResolver;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Encaissements d'un licencié et validation de sa licence.
 *
 * Deux faits distincts, deux statuts :
 *
 * - **le solde** — le total encaissé atteint la cotisation due, par saisie manuelle comme par
 *   encaissement HelloAsso vérifié. La licence passe en `A_VALIDER_FFF` : côté club, tout est
 *   fait, le kit est dû et le licencié est prévenu par mail ;
 * - **la validation FootClubs** — le club signe la licence dans l'outil fédéral. Ce geste-là
 *   n'a aucun automatisme possible : il se déclare à la main, et lui seul pose `VALIDATED`.
 *
 * Ne pas refondre les deux en un : c'est justement parce qu'un seul statut portait les deux
 * que le club ne savait pas ce qu'il lui restait à valider à la FFF.
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
            $this->marquerSolde($licencie);
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

    /**
     * Validation à la main, quand le club encaisse hors circuit (geste commercial, échange).
     *
     * C'est le **paiement** qu'elle court-circuite, pas la démarche FFF : la licence arrive donc
     * en « à valider sur FootClubs », comme si elle avait été soldée.
     */
    public function validerManuellement(Licencie $licencie): void
    {
        $this->marquerSolde($licencie);
    }

    /**
     * Le club a signé la licence dans FootClubs. Dernier statut du parcours.
     *
     * Aucun mail, aucune dotation à recalculer : le licencié a déjà été prévenu au solde et son
     * droit au kit est ouvert depuis. Ce geste ne concerne que le club.
     */
    public function validerSurFootclubs(Licencie $licencie): void
    {
        $dossier = $licencie->getDossierClub();

        if ($dossier === null || $dossier->getStatus() !== LicenceStatus::A_VALIDER_FFF) {
            throw new \DomainException('Seule une licence soldée et pas encore validée peut être validée sur FootClubs.');
        }

        $dossier->setStatus(LicenceStatus::VALIDATED);
        $this->em->flush();
    }

    /**
     * Validation groupée : le club valide dans FootClubs par paquets, pas fiche par fiche.
     *
     * La liste éligible est repassée au crible ici, jamais crue sur parole — un uuid ajouté au
     * formulaire posté ne doit pas pouvoir valider une licence qui n'était pas proposée. Même
     * règle que {@see \App\Service\Document\SignatureRelanceService}.
     *
     * @param Licencie[] $eligibles
     * @param string[]   $uuidsRetenus
     *
     * @return int nombre de licences validées
     */
    public function validerSurFootclubsEnMasse(array $eligibles, array $uuidsRetenus): int
    {
        $retenus = array_flip($uuidsRetenus);
        $valides = 0;

        foreach ($eligibles as $licencie) {
            if (!isset($retenus[(string) $licencie->getUuid()])) {
                continue;
            }

            $this->validerSurFootclubs($licencie);
            ++$valides;
        }

        return $valides;
    }

    /**
     * Retour en arrière après un clic malheureux : la licence redevient « à valider ».
     *
     * Sans cette sortie, une validation posée par erreur serait définitive et le club perdrait
     * de vue une licence qu'il lui reste réellement à signer.
     */
    public function annulerValidationFootclubs(Licencie $licencie): void
    {
        $dossier = $licencie->getDossierClub();

        if ($dossier === null || $dossier->getStatus() !== LicenceStatus::VALIDATED) {
            throw new \DomainException('Cette licence n\'est pas validée.');
        }

        $dossier->setStatus(LicenceStatus::A_VALIDER_FFF);
        $this->em->flush();
    }

    private function soldeAtteint(Licencie $licencie, Season $season): bool
    {
        $du = (float) $this->cotisationResolver->resolve($licencie);

        return $this->transactionRepo->sumByLicencieAndSeason($licencie, $season) >= $du;
    }

    /** Le solde ouvre le droit à la dotation et prévient le licencié — une seule fois. */
    private function marquerSolde(Licencie $licencie): void
    {
        $dossier = $licencie->getDossierClub();

        // Idempotence : une licence déjà soldée — a fortiori déjà validée à la FFF — ne
        // redescend pas d'un statut et ne redéclenche pas le mail.
        if ($dossier === null || $dossier->estSoldee()) {
            return;
        }

        $dossier->setStatus(LicenceStatus::A_VALIDER_FFF);
        $this->em->flush();

        $this->dotationSynchronizer->recomputeForLicencie($licencie);

        if ($licencie->getEmail() !== null) {
            $this->mailerService->sendValidation($licencie);
        }
    }
}
