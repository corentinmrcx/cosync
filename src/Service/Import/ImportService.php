<?php declare(strict_types=1);

namespace App\Service\Import;

use App\DTO\ImportResultData;
use App\DTO\ImportRowData;
use App\Entity\Dirigeant;
use App\Entity\DirigeantRole;
use App\Entity\DossierClub;
use App\Entity\Licencie;
use App\Entity\Season;
use App\Enum\ImportRowType;
use App\Enum\LicenceStatus;
use App\Repository\CategoryRepository;
use App\Repository\DirigeantRepository;
use App\Repository\DirigeantRoleRepository;
use App\Repository\LicencieRepository;
use App\Repository\TeamRepository;
use App\Service\Import\Layout\ImportLayoutResolver;
use App\Service\Mail\MailerService;
use Doctrine\ORM\EntityManagerInterface;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final class ImportService
{
    /** @var array<string, DirigeantRole> */
    private array $roleCache = [];

    public function __construct(
        private readonly DataSanitizer $sanitizer,
        private readonly ImportLayoutResolver $layoutResolver,
        private readonly LicencieRepository $licencieRepository,
        private readonly DirigeantRepository $dirigeantRepository,
        private readonly DirigeantRoleRepository $dirigeantRoleRepository,
        private readonly CategoryRepository $categoryRepository,
        private readonly TeamRepository $teamRepository,
        private readonly EntityManagerInterface $em,
        private readonly MailerService $mailerService,
        private readonly NatureLicenceResolver $natureResolver,
    ) {}

    public function importFromXlsx(UploadedFile $file, Season $season): ImportResultData
    {
        $result = new ImportResultData();

        // Réinitialise le cache de rôles à chaque import : les entités mises en cache appartiennent
        // à l'EntityManager de l'import précédent et seraient détachées si le service est réutilisé.
        $this->roleCache = [];

        $rows = $this->readFirstNonEmptySheet($file);
        if (count($rows) < 2) {
            $result->addError(0, 'Le fichier est vide ou ne contient pas de données.');
            return $result;
        }

        $columns = $this->buildColumnMap($rows[0]);

        $layout = $this->layoutResolver->resolve($columns);
        if ($layout === null) {
            $result->addError(1, 'Format de fichier non reconnu. Utilisez un export FootClubs « Licences dématérialisées » ou « Éditions et extractions ».');
            return $result;
        }
        $result->layoutLabel  = $layout->label();
        $result->emailAutoSend = $layout->sendsEmailOnCreate();

        $pendingLicencies  = [];
        $pendingDirigeants = [];
        $newLicencies      = [];

        foreach (array_slice($rows, 1) as $offset => $row) {
            $lineNumber = $offset + 2;

            try {
                $data = $layout->map($row, $columns);

                match ($data->type) {
                    ImportRowType::LICENCIE  => $this->processLicencieRow($data, $season, $result, $lineNumber, $pendingLicencies, $newLicencies),
                    ImportRowType::DIRIGEANT => $this->processDirigeantRow($data, $season, $result, $lineNumber, $pendingDirigeants),
                    ImportRowType::SKIP      => null,
                };
            } catch (\Throwable $e) {
                $result->addError($lineNumber, $e->getMessage());
            }
        }

        try {
            $this->em->flush();
        } catch (\Throwable) {
            $this->em->clear();
            $result->addError(0, 'Une erreur est survenue lors de l\'enregistrement. Aucune donnée n\'a été importée.');
            return $result;
        }

        if ($layout->sendsEmailOnCreate()) {
            $this->sendInscriptionLinks($newLicencies, $result);
        }

        return $result;
    }

    /**
     * @param ImportRowData[] $newLicencies
     */
    private function sendInscriptionLinks(array $newLicencies, ImportResultData $result): void
    {
        $sent = false;
        foreach ($newLicencies as $licencie) {
            if ($licencie->getEmail() === null) {
                continue;
            }
            try {
                $this->mailerService->sendInscriptionLink($licencie);
                $licencie->setLinkSentAt(new \DateTimeImmutable());
                $licencie->getDossierClub()?->setStatus(LicenceStatus::LINK_SENT);
                $result->emailsSent++;
                $sent = true;
            } catch (\Throwable) {
                $result->emailsFailed++;
            }
        }

        if ($sent) {
            $this->em->flush();
        }
    }

    /**
     * @param array<string, bool>  $pendingLicencies
     * @param Licencie[]           $newLicencies
     */
    private function processLicencieRow(
        ImportRowData $data,
        Season $season,
        ImportResultData $result,
        int $lineNumber,
        array &$pendingLicencies,
        array &$newLicencies,
    ): void {
        $nom    = $data->nom;
        $prenom = $data->prenom;

        $rawDate = trim((string) $data->rawDateNaissance);
        if ($rawDate === '') {
            $result->addError($lineNumber, "Date de naissance manquante pour $nom $prenom.");
            return;
        }
        $dateNaissance = $this->sanitizer->sanitizeDateNaissance($rawDate);

        $rawCategorie = trim((string) $data->rawCategoryOrRole);
        if ($rawCategorie === '') {
            $result->addError($lineNumber, "Sous catégorie manquante pour $nom $prenom.");
            return;
        }
        $codeCategorie = $this->sanitizer->sanitizeSousCategorie($rawCategorie);
        $category      = $this->categoryRepository->findOneBy(['code' => $codeCategorie]);
        if ($category === null) {
            $result->addError($lineNumber, "Catégorie inconnue \"$codeCategorie\" pour $nom $prenom. Ajoutez-la via app:seed-referential.");
            return;
        }

        $rawNumLicence = trim((string) $data->rawNumLicence);
        if ($rawNumLicence === '') {
            $result->addError($lineNumber, "Numéro de personne manquant pour $nom $prenom.");
            return;
        }
        $numLicence = $this->sanitizer->sanitizeNumLicence($rawNumLicence);

        if (isset($pendingLicencies[$numLicence])) {
            $result->addNotice($lineNumber, "Doublon ignoré : $nom $prenom (n°$numLicence) apparaît plusieurs fois dans le fichier.");
            return;
        }

        $email      = $this->sanitizer->sanitizeEmail($data->rawEmail);
        $telephone  = $this->sanitizer->sanitizePhone($data->rawTelephone);
        $voieRue    = $this->nullableTrim($data->rawVoieRue);
        $codePostal = $this->nullableTrim($data->rawCodePostal);
        $ville      = $this->nullableTrim($data->rawVille);

        $nature = $this->natureResolver->resolve($data->rawNature, $numLicence, $season);
        if ($nature !== null && $this->natureResolver->contredit($nature, $numLicence, $season)) {
            $result->addNotice($lineNumber, sprintf(
                'L\'export annonce « %s » pour %s %s (n°%s), ce que l\'historique des saisons contredit. Valeur de l\'export conservée — à vérifier.',
                $nature->label(),
                $nom,
                $prenom,
                $numLicence,
            ));
        }

        $licencie = $this->licencieRepository->findByNumLicence($numLicence, $season)
            ?? $this->licencieRepository->findByNomPrenomNaissance($nom, $prenom, $dateNaissance, $season);

        $pendingLicencies[$numLicence] = true;

        if ($licencie !== null) {
            // Enrichit le num_licence si absent (migration depuis l'ancien format)
            if ($licencie->getNumLicence() === null) {
                $licencie->setNumLicence($numLicence);
            }
            // Champs toujours présents dans les deux exports : mise à jour systématique.
            $licencie->setNom($nom);
            $licencie->setPrenom($prenom);
            $licencie->setDateNaissance($dateNaissance);
            $licencie->setCategory($category);
            // Champs optionnels : on ne met à jour que si l'export apporte une valeur,
            // pour que combiner les deux imports enrichisse sans jamais effacer l'existant.
            if ($email !== null) {
                $licencie->setEmail($email);
            }
            if ($telephone !== null) {
                $licencie->setTelephone($telephone);
            }
            if ($voieRue !== null) {
                $licencie->setVoieRue($voieRue);
            }
            if ($codePostal !== null) {
                $licencie->setCodePostal($codePostal);
            }
            if ($ville !== null) {
                $licencie->setVille($ville);
            }
            // Une correction faite à la main par l'admin fait autorité sur l'export.
            if ($nature !== null && !$licencie->isNatureManuelle()) {
                $licencie->setNatureLicence($nature);
            }
            $result->updated++;
            $this->countNature($licencie, $result);

            return;
        }

        $licencie = new Licencie();
        $licencie->setNumLicence($numLicence);
        $licencie->setNom($nom);
        $licencie->setPrenom($prenom);
        $licencie->setDateNaissance($dateNaissance);
        $licencie->setCategory($category);
        $licencie->setSeason($season);
        $licencie->setEmail($email);
        $licencie->setTelephone($telephone);
        $licencie->setVoieRue($voieRue);
        $licencie->setCodePostal($codePostal);
        $licencie->setVille($ville);
        $licencie->setNatureLicence($nature);
        $licencie->setFormTokenExpiresAt(new \DateTimeImmutable('+30 days'));

        // Auto-assignation si une seule équipe couvre cette catégorie
        $defaultTeam = $this->teamRepository->findForCategory($category, $season);
        if ($defaultTeam !== null) {
            $licencie->setTeam($defaultTeam);
        }

        $dossier = new DossierClub();
        $dossier->setLicencie($licencie);
        $dossier->setStatus(LicenceStatus::IMPORTED);

        $this->em->persist($licencie);
        $this->em->persist($dossier);

        $newLicencies[] = $licencie;
        $result->created++;
        $this->countNature($licencie, $result);
    }

    private function countNature(Licencie $licencie, ImportResultData $result): void
    {
        match ($licencie->estNouveau()) {
            true  => $result->nouveaux++,
            false => $result->renouvellements++,
            null  => $result->natureInconnue++,
        };
    }

    /**
     * @param array<string, bool> $pendingDirigeants
     */
    private function processDirigeantRow(
        ImportRowData $data,
        Season $season,
        ImportResultData $result,
        int $lineNumber,
        array &$pendingDirigeants,
    ): void {
        $nom    = $data->nom;
        $prenom = $data->prenom;

        $rawNumLicence = trim((string) $data->rawNumLicence);
        $numLicence    = $rawNumLicence !== '' ? $this->sanitizer->sanitizeNumLicence($rawNumLicence) : null;

        if ($numLicence !== null && isset($pendingDirigeants[$numLicence])) {
            $result->addNotice($lineNumber, "Doublon ignoré : $nom $prenom (n°$numLicence) apparaît plusieurs fois dans le fichier.");
            return;
        }

        $email         = $this->sanitizer->sanitizeEmail($data->rawEmail);
        $telephone     = $this->sanitizer->sanitizePhone($data->rawTelephone);
        $rawDate       = trim((string) $data->rawDateNaissance);
        $dateNaissance = $rawDate !== '' ? $this->sanitizer->sanitizeDateNaissance($rawDate) : null;
        $rawRole       = trim((string) $data->rawCategoryOrRole);
        $role          = $rawRole !== '' ? $this->findOrCreateRole(mb_convert_case($rawRole, MB_CASE_TITLE, 'UTF-8')) : null;

        $dirigeant = ($numLicence !== null ? $this->dirigeantRepository->findByNumLicence($numLicence, $season) : null)
            ?? $this->dirigeantRepository->findByNomPrenomSaison($nom, $prenom, $season);

        if ($numLicence !== null) {
            $pendingDirigeants[$numLicence] = true;
        }

        if ($dirigeant !== null) {
            if ($dirigeant->getNumLicence() === null && $numLicence !== null) {
                $dirigeant->setNumLicence($numLicence);
            }
            $dirigeant->setNom($nom);
            $dirigeant->setPrenom($prenom);
            // Champs optionnels : mise à jour uniquement si l'export apporte une valeur,
            // pour enrichir sans effacer en combinant les deux imports.
            if ($email !== null) {
                $dirigeant->setEmail($email);
            }
            if ($telephone !== null) {
                $dirigeant->setTelephone($telephone);
            }
            if ($dateNaissance !== null) {
                $dirigeant->setDateNaissance($dateNaissance);
            }
            if ($role !== null) {
                $dirigeant->setRole($role);
            }
            $result->updated++;

            return;
        }

        $dirigeant = new Dirigeant();
        $dirigeant->setNumLicence($numLicence);
        $dirigeant->setNom($nom);
        $dirigeant->setPrenom($prenom);
        $dirigeant->setEmail($email);
        $dirigeant->setTelephone($telephone);
        $dirigeant->setDateNaissance($dateNaissance);
        $dirigeant->setRole($role);
        $dirigeant->setSeason($season);

        $this->em->persist($dirigeant);
        $result->created++;
    }

    private function findOrCreateRole(string $label): DirigeantRole
    {
        if (isset($this->roleCache[$label])) {
            return $this->roleCache[$label];
        }

        $role = $this->dirigeantRoleRepository->findByLabel($label);
        if ($role === null) {
            $role = new DirigeantRole();
            $role->setLabel($label);
            $this->em->persist($role);
            // Pas de flush ici — le flush global en fin d'import s'en charge
        }

        $this->roleCache[$label] = $role;
        return $role;
    }

    private function nullableTrim(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return trim($value) ?: null;
    }

    /**
     * Le nouvel export dématérialisé embarque une seconde feuille vide : on lit la première
     * feuille qui contient réellement des données.
     *
     * @return array<int, array<int, mixed>>
     */
    private function readFirstNonEmptySheet(UploadedFile $file): array
    {
        $spreadsheet = IOFactory::load($file->getPathname());

        foreach ($spreadsheet->getAllSheets() as $sheet) {
            if ($sheet->getHighestDataRow() >= 2) {
                return $sheet->toArray(null, true, true, false);
            }
        }

        return $spreadsheet->getActiveSheet()->toArray(null, true, true, false);
    }

    /**
     * @param array<int, mixed> $headerRow
     * @return array<string, int> en-tête normalisé (minuscules, trim) => index de colonne
     */
    private function buildColumnMap(array $headerRow): array
    {
        $columns = [];
        foreach ($headerRow as $index => $header) {
            $name = mb_strtolower(trim((string) $header), 'UTF-8');
            if ($name !== '') {
                $columns[$name] = $index;
            }
        }

        return $columns;
    }
}
