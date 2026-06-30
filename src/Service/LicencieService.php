<?php declare(strict_types=1);

namespace App\Service;

use App\DTO\LicencieCreateData;
use App\DTO\LicencieIdentityData;
use App\Entity\DossierClub;
use App\Entity\Licencie;
use App\Entity\Season;
use App\Entity\Transaction;
use App\Entity\User;
use App\Enum\LicenceStatus;
use App\Enum\PaymentMode;
use App\Repository\LicencieRepository;
use App\Repository\TeamRepository;
use App\Repository\TransactionRepository;
use App\Service\CotisationResolver;
use App\Service\Import\DataSanitizer;
use App\Service\Mail\MailerService;
use App\Service\Stock\DotationBesoinService;
use Doctrine\ORM\EntityManagerInterface;

final class LicencieService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly TransactionRepository $transactionRepo,
        private readonly LicencieRepository $licencieRepo,
        private readonly TeamRepository $teamRepo,
        private readonly DataSanitizer $sanitizer,
        private readonly MailerService $mailerService,
        private readonly DotationBesoinService $dotationBesoinService,
        private readonly CotisationResolver $cotisationResolver,
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

        if ($numLicence !== null && $this->licencieRepo->findByNumLicence($numLicence, $season) !== null) {
            throw new \DomainException(sprintf('Un licencié avec le numéro FootClubs "%s" existe déjà dans cette saison.', $numLicence));
        }

        $existing = $this->licencieRepo->findByNomPrenomNaissance($nom, $prenom, $data->dateNaissance, $season);
        if ($existing !== null) {
            throw new \DomainException(sprintf(
                '%s %s (né(e) le %s) existe déjà dans cette saison.',
                $nom, $prenom,
                $data->dateNaissance->format('d/m/Y'),
            ));
        }

        $licencie = new Licencie();
        $licencie->setNom($nom);
        $licencie->setPrenom($prenom);
        $licencie->setDateNaissance($data->dateNaissance);
        $licencie->setCategory($data->category);
        $team = $data->team ?? $this->teamRepo->findForCategory($data->category, $season);
        $licencie->setTeam($team);
        $licencie->setSeason($season);
        $licencie->setEmail($email);
        $licencie->setTelephone($phone);
        $licencie->setVoieRue($data->voieRue !== null && trim($data->voieRue) !== '' ? trim($data->voieRue) : null);
        $licencie->setCodePostal($data->codePostal !== null && trim($data->codePostal) !== '' ? trim($data->codePostal) : null);
        $licencie->setVille($data->ville !== null && trim($data->ville) !== '' ? trim($data->ville) : null);
        $licencie->setNumLicence($numLicence);
        $licencie->setFormTokenExpiresAt(new \DateTimeImmutable('+30 days'));
        $licencie->setCreatedManually(true);

        $dossier = new DossierClub();
        $dossier->setLicencie($licencie);
        $dossier->setStatus(LicenceStatus::LINK_SENT);

        $this->em->persist($licencie);
        $this->em->persist($dossier);
        $this->em->flush();

        return $licencie;
    }

    /**
     * @throws \DomainException si le nouveau num_licence ou nom+prénom+naissance appartient déjà à un autre licencié
     */
    public function editIdentity(Licencie $licencie, LicencieIdentityData $data): void
    {
        $nom    = mb_strtoupper(trim((string) $data->nom), 'UTF-8');
        $prenom = mb_convert_case(trim((string) $data->prenom), MB_CASE_TITLE, 'UTF-8');
        $email  = $this->sanitizer->sanitizeEmail($data->email);
        $phone  = $this->sanitizer->sanitizePhone($data->telephone);
        $numLicence = $data->numLicence !== null && trim($data->numLicence) !== ''
            ? $this->sanitizer->sanitizeNumLicence($data->numLicence)
            : null;

        $season = $licencie->getSeason();

        if ($numLicence !== null && $numLicence !== $licencie->getNumLicence()) {
            $other = $this->licencieRepo->findByNumLicence($numLicence, $season);
            if ($other !== null && !$other->getUuid()->equals($licencie->getUuid())) {
                throw new \DomainException(sprintf('Le numéro FootClubs "%s" est déjà utilisé par %s dans cette saison.', $numLicence, $other->getNomPrenom()));
            }
        }

        if ($nom !== $licencie->getNom() || $prenom !== $licencie->getPrenom() || $data->dateNaissance != $licencie->getDateNaissance()) {
            $other = $this->licencieRepo->findByNomPrenomNaissance($nom, $prenom, $data->dateNaissance, $season);
            if ($other !== null && !$other->getUuid()->equals($licencie->getUuid())) {
                throw new \DomainException(sprintf('%s %s (né(e) le %s) existe déjà dans cette saison.', $nom, $prenom, $data->dateNaissance->format('d/m/Y')));
            }
        }

        $licencie->setNom($nom);
        $licencie->setPrenom($prenom);
        $licencie->setDateNaissance($data->dateNaissance);
        $licencie->setCategory($data->category);
        $licencie->setEmail($email);
        $licencie->setTelephone($phone);
        $licencie->setVoieRue($data->voieRue !== null && trim($data->voieRue) !== '' ? trim($data->voieRue) : null);
        $licencie->setCodePostal($data->codePostal !== null && trim($data->codePostal) !== '' ? trim($data->codePostal) : null);
        $licencie->setVille($data->ville !== null && trim($data->ville) !== '' ? trim($data->ville) : null);
        $licencie->setNumLicence($numLicence);

        $this->em->flush();
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

    public function addPayment(
        Licencie $licencie,
        PaymentMode $mode,
        float $montant,
        ?string $reference,
        ?string $note,
        \DateTimeImmutable $datePaiement,
        User $confirmedBy,
        Season $season,
    ): void {
        $transaction = new Transaction();
        $transaction->setLicencie($licencie);
        $transaction->setSeason($season);
        $transaction->setMode($mode);
        $transaction->setMontant(number_format($montant, 2, '.', ''));
        $transaction->setReference($reference);
        $transaction->setNote($note);
        $transaction->setDatePaiement($datePaiement);
        $transaction->setConfirmedBy($confirmedBy);
        $this->em->persist($transaction);
        $this->em->flush();

        $expected  = (float) $this->cotisationResolver->resolve($licencie);
        $totalPaid = $this->transactionRepo->sumByLicencieAndSeason($licencie, $season);

        if ($totalPaid >= $expected) {
            $this->setValidated($licencie);
        }
    }

    /**
     * Supprime un paiement saisi par erreur. Le total payé est recalculé à l'affichage ;
     * le statut de la licence n'est pas modifié (une licence validée le reste).
     */
    public function deletePayment(Transaction $transaction): void
    {
        $this->em->remove($transaction);
        $this->em->flush();
    }

    public function validateManually(Licencie $licencie): void
    {
        $this->setValidated($licencie);
    }

    private function setValidated(Licencie $licencie): void
    {
        $dossier = $licencie->getDossierClub();
        if ($dossier !== null && $dossier->getStatus() !== LicenceStatus::VALIDATED) {
            $dossier->setStatus(LicenceStatus::VALIDATED);
            $this->em->flush();
            $this->dotationBesoinService->recomputeForLicencie($licencie);
            if ($licencie->getEmail() !== null) {
                $this->mailerService->sendValidation($licencie);
            }
        }
    }
}
