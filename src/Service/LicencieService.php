<?php declare(strict_types=1);

namespace App\Service;

use App\DTO\LicencieCreateData;
use App\Entity\DossierClub;
use App\Entity\Licencie;
use App\Entity\Season;
use App\Entity\Transaction;
use App\Entity\User;
use App\Enum\LicenceStatus;
use App\Enum\PaymentMode;
use App\Repository\LicencieRepository;
use App\Repository\TransactionRepository;
use App\Service\Import\DataSanitizer;
use App\Service\Mail\MailerService;
use Doctrine\ORM\EntityManagerInterface;

final class LicencieService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly TransactionRepository $transactionRepo,
        private readonly LicencieRepository $licencieRepo,
        private readonly DataSanitizer $sanitizer,
        private readonly MailerService $mailerService,
    ) {}

    /**
     * @throws \DomainException si un doublon est détecté (num_licence ou nom+prénom+naissance)
     */
    public function create(LicencieCreateData $data, Season $season): Licencie
    {
        $nom    = mb_strtoupper(trim((string) $data->nom), 'UTF-8');
        $prenom = mb_convert_case(trim((string) $data->prenom), MB_CASE_TITLE, 'UTF-8');
        $email  = $this->sanitizer->sanitizeEmail($data->email);
        $phone  = $this->sanitizer->sanitizePhone($data->telephone);
        $numLicence = $data->numLicence !== null && trim($data->numLicence) !== ''
            ? $this->sanitizer->sanitizeNumLicence($data->numLicence)
            : null;

        if ($numLicence !== null && $this->licencieRepo->findByNumLicence($numLicence) !== null) {
            throw new \DomainException(sprintf('Un licencié avec le numéro FootClubs "%s" existe déjà.', $numLicence));
        }

        $existing = $this->licencieRepo->findByNomPrenomNaissance($nom, $prenom, $data->dateNaissance);
        if ($existing !== null) {
            throw new \DomainException(sprintf(
                '%s %s (né(e) le %s) existe déjà dans la base.',
                $nom, $prenom,
                $data->dateNaissance->format('d/m/Y'),
            ));
        }

        $licencie = new Licencie();
        $licencie->setNom($nom);
        $licencie->setPrenom($prenom);
        $licencie->setDateNaissance($data->dateNaissance);
        $licencie->setCategory($data->category);
        $licencie->setSeason($season);
        $licencie->setEmail($email);
        $licencie->setTelephone($phone);
        $licencie->setNumLicence($numLicence);
        $licencie->setFormTokenExpiresAt(new \DateTimeImmutable('+30 days'));

        $dossier = new DossierClub();
        $dossier->setLicencie($licencie);
        $dossier->setStatus(LicenceStatus::LINK_SENT);

        $this->em->persist($licencie);
        $this->em->persist($dossier);
        $this->em->flush();

        return $licencie;
    }

    public function edit(
        Licencie $licencie,
        ?string $tailleHaut,
        ?string $tailleBas,
        ?string $pointure,
    ): void {
        $dossier = $licencie->getDossierClub();
        if ($dossier !== null) {
            $dossier->setTailleHaut($tailleHaut ?: null);
            $dossier->setTailleBas($tailleBas ?: null);
            $dossier->setPointure($pointure ?: null);
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

        $this->mailerService->sendValidation($licencie);
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
