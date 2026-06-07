<?php declare(strict_types=1);

namespace App\Service;

use App\Entity\Licencie;
use App\Entity\Season;
use App\Entity\Transaction;
use App\Entity\User;
use App\Enum\LicenceStatus;
use App\Enum\PaymentMode;
use App\Repository\TransactionRepository;
use Doctrine\ORM\EntityManagerInterface;

final class LicencieService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly TransactionRepository $transactionRepo,
    ) {}

    public function edit(
        Licencie $licencie,
        ?string $tailleHaut,
        ?string $tailleBas,
        ?string $pointure,
        ?PaymentMode $paymentMode,
        ?float $montant,
        ?string $reference,
        User $confirmedBy,
        Season $season,
    ): void {
        $dossier = $licencie->getDossierClub();
        if ($dossier !== null) {
            $dossier->setTailleHaut($tailleHaut ?: null);
            $dossier->setTailleBas($tailleBas ?: null);
            $dossier->setPointure($pointure ?: null);
        }

        $hasTransaction = false;
        if ($paymentMode !== null && $montant !== null && $montant > 0) {
            $transaction = $this->transactionRepo->findByLicencieAndSeason($licencie, $season);
            if ($transaction === null) {
                $transaction = new Transaction();
                $transaction->setLicencie($licencie);
                $transaction->setSeason($season);
                $transaction->setDatePaiement(new \DateTimeImmutable());
                $this->em->persist($transaction);
            }
            $transaction->setMode($paymentMode);
            $transaction->setMontant(number_format($montant, 2, '.', ''));
            $transaction->setReference($reference ?: null);
            $transaction->setConfirmedBy($confirmedBy);
            $hasTransaction = true;
        } else {
            $hasTransaction = $this->transactionRepo->findByLicencieAndSeason($licencie, $season) !== null;
        }

        if ($dossier !== null) {
            $dossier->setStatus($this->computeStatus($dossier->isSigned(), $hasTransaction));
        }

        $this->em->flush();
    }

    public function confirmPaiement(
        Licencie $licencie,
        PaymentMode $mode,
        float $montant,
        ?string $reference,
        User $confirmedBy,
        Season $season,
    ): void {
        $transaction = $this->transactionRepo->findByLicencieAndSeason($licencie, $season);
        if ($transaction === null) {
            $transaction = new Transaction();
            $transaction->setLicencie($licencie);
            $transaction->setSeason($season);
            $this->em->persist($transaction);
        }

        $transaction->setMode($mode);
        $transaction->setMontant(number_format($montant, 2, '.', ''));
        $transaction->setReference($reference);
        $transaction->setDatePaiement(new \DateTimeImmutable());
        $transaction->setConfirmedBy($confirmedBy);

        $dossier = $licencie->getDossierClub();
        if ($dossier !== null) {
            $dossier->setStatus(LicenceStatus::VALIDATED);
        }

        $this->em->flush();
    }

    private function computeStatus(bool $isSigned, bool $hasTransaction): LicenceStatus
    {
        if (!$isSigned) {
            return LicenceStatus::LINK_SENT;
        }
        if (!$hasTransaction) {
            return LicenceStatus::FORM_COMPLETED;
        }
        return LicenceStatus::VALIDATED;
    }
}
