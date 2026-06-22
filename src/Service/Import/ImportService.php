<?php declare(strict_types=1);

namespace App\Service\Import;

use App\DTO\ImportResultData;
use App\Entity\Dirigeant;
use App\Entity\DirigeantRole;
use App\Entity\DossierClub;
use App\Entity\Licencie;
use App\Entity\Season;
use App\Enum\LicenceStatus;
use App\Repository\CategoryRepository;
use App\Repository\DirigeantRepository;
use App\Repository\DirigeantRoleRepository;
use App\Repository\LicencieRepository;
use App\Repository\TeamRepository;
use App\Service\Mail\MailerService;
use Doctrine\ORM\EntityManagerInterface;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final class ImportService
{
    // Colonnes FootClubs 26-colonnes (valeurs normalisées en minuscules)
    private const COL_TYPE_LICENCE        = 'type licence';
    private const COL_NOM_PRENOM          = 'nom, prénom';
    private const COL_DATE_NAISSANCE      = 'né(e) le';
    private const COL_SOUS_CATEGORIE      = 'sous catégorie';
    private const COL_VOIE_RUE            = 'voie-rue';
    private const COL_CODE_POSTAL         = 'code postal';
    private const COL_BUREAU_DISTRIBUTEUR = 'bureau distributeur';
    private const COL_NUMERO_PERSONNE     = 'numéro personne';
    private const COL_MOBILE_PERSONNEL    = 'mobile personnel';
    private const COL_EMAIL_PRINCIPAL     = 'email principal';

    private const TYPE_LICENCE_LIBRE      = 'libre';
    private const TYPE_LICENCE_DIRIGEANT  = 'dirigeant';

    public function __construct(
        private readonly DataSanitizer $sanitizer,
        private readonly LicencieRepository $licencieRepository,
        private readonly DirigeantRepository $dirigeantRepository,
        private readonly DirigeantRoleRepository $dirigeantRoleRepository,
        private readonly CategoryRepository $categoryRepository,
        private readonly TeamRepository $teamRepository,
        private readonly EntityManagerInterface $em,
        private readonly MailerService $mailerService,
    ) {
        $this->roleCache = [];
    }

    /** @var array<string, DirigeantRole> */
    private array $roleCache;

    public function importFromXlsx(UploadedFile $file, Season $season): ImportResultData
    {
        $result = new ImportResultData();

        $spreadsheet = IOFactory::load($file->getPathname());
        $rows        = $spreadsheet->getActiveSheet()->toArray(null, true, true, false);

        if (count($rows) < 2) {
            $result->addError(0, 'Le fichier est vide ou ne contient pas de données.');
            return $result;
        }

        $headers    = array_map(fn(mixed $h): string => mb_strtolower(trim((string) $h), 'UTF-8'), $rows[0]);
        $colIndexes = $this->resolveColumnIndexes($headers);

        // Seules les colonnes obligatoires bloquent l'import
        $required = [
            self::COL_TYPE_LICENCE,
            self::COL_NOM_PRENOM,
            self::COL_DATE_NAISSANCE,
            self::COL_SOUS_CATEGORIE,
            self::COL_NUMERO_PERSONNE,
        ];
        $missing = array_filter($required, fn(string $col) => $colIndexes[$col] === null);
        if (!empty($missing)) {
            $result->addError(1, sprintf('Colonnes obligatoires introuvables : %s', implode(', ', $missing)));
            return $result;
        }

        $pendingNumLicences = [];
        $newLicencies       = [];

        foreach (array_slice($rows, 1) as $offset => $row) {
            $lineNumber = $offset + 2;

            $typeLicence = mb_strtolower(trim((string) ($row[$colIndexes[self::COL_TYPE_LICENCE]] ?? '')), 'UTF-8');
            if ($typeLicence !== self::TYPE_LICENCE_LIBRE) {
                continue;
            }

            $rawNomPrenom = trim((string) ($row[$colIndexes[self::COL_NOM_PRENOM]] ?? ''));
            if ($rawNomPrenom === '') {
                continue;
            }

            try {
                if ($typeLicence === self::TYPE_LICENCE_DIRIGEANT) {
                    $this->processDirigeantRow($row, $colIndexes, $season, $result, $lineNumber, $pendingNumLicences);
                } else {
                    $this->processRow($row, $colIndexes, $season, $result, $lineNumber, $pendingNumLicences, $newLicencies);
                }
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

        foreach ($newLicencies as $licencie) {
            if ($licencie->getEmail() === null) {
                continue;
            }
            try {
                $this->mailerService->sendInscriptionLink($licencie);
                $result->emailsSent++;
            } catch (\Throwable) {
                $result->emailsFailed++;
            }
        }

        return $result;
    }

    private function processRow(
        array $row,
        array $colIndexes,
        Season $season,
        ImportResultData $result,
        int $lineNumber,
        array &$pendingNumLicences,
        array &$newLicencies,
    ): void {
        ['nom' => $nom, 'prenom' => $prenom] = $this->sanitizer->splitNomPrenom(
            (string) $row[$colIndexes[self::COL_NOM_PRENOM]]
        );

        $rawDate = trim((string) ($row[$colIndexes[self::COL_DATE_NAISSANCE]] ?? ''));
        if ($rawDate === '') {
            $result->addError($lineNumber, "Date de naissance manquante pour $nom $prenom.");
            return;
        }
        $dateNaissance = $this->sanitizer->sanitizeDateNaissance($rawDate);

        $rawCategorie = trim((string) ($row[$colIndexes[self::COL_SOUS_CATEGORIE]] ?? ''));
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

        $rawNumLicence = trim((string) ($row[$colIndexes[self::COL_NUMERO_PERSONNE]] ?? ''));
        if ($rawNumLicence === '') {
            $result->addError($lineNumber, "Numéro de personne manquant pour $nom $prenom.");
            return;
        }
        $numLicence = $this->sanitizer->sanitizeNumLicence($rawNumLicence);

        if (isset($pendingNumLicences[$numLicence])) {
            $result->addError($lineNumber, "Doublon ignoré : $nom $prenom (n°$numLicence) apparaît plusieurs fois dans le fichier.");
            return;
        }

        // Données optionnelles
        $email     = $this->sanitizer->sanitizeEmail($this->colValue($row, $colIndexes, self::COL_EMAIL_PRINCIPAL));
        $telephone = $this->sanitizer->sanitizePhone($this->colValue($row, $colIndexes, self::COL_MOBILE_PERSONNEL));
        $voieRue   = $this->colValue($row, $colIndexes, self::COL_VOIE_RUE);
        $voieRue   = $voieRue !== null ? trim($voieRue) ?: null : null;
        $codePostal = $this->colValue($row, $colIndexes, self::COL_CODE_POSTAL);
        $codePostal = $codePostal !== null ? trim($codePostal) ?: null : null;
        $ville      = $this->colValue($row, $colIndexes, self::COL_BUREAU_DISTRIBUTEUR);
        $ville      = $ville !== null ? trim($ville) ?: null : null;

        $licencie = $this->licencieRepository->findByNumLicence($numLicence)
            ?? $this->licencieRepository->findByNomPrenomNaissance($nom, $prenom, $dateNaissance);

        $pendingNumLicences[$numLicence] = true;

        if ($licencie !== null) {
            // Enrichit le num_licence si absent (migration depuis l'ancien format)
            if ($licencie->getNumLicence() === null) {
                $licencie->setNumLicence($numLicence);
            }
            $licencie->setNom($nom);
            $licencie->setPrenom($prenom);
            $licencie->setDateNaissance($dateNaissance);
            $licencie->setCategory($category);
            $licencie->setEmail($email);
            $licencie->setTelephone($telephone);
            $licencie->setVoieRue($voieRue);
            $licencie->setCodePostal($codePostal);
            $licencie->setVille($ville);
            $result->updated++;
        } else {
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
            $licencie->setFormTokenExpiresAt(new \DateTimeImmutable('+30 days'));

            // Auto-assignation d'équipe si une équipe par défaut est configurée
            $defaultTeam = $this->teamRepository->findDefaultForCategory($category, $season);
            if ($defaultTeam !== null) {
                $licencie->setTeam($defaultTeam);
            }

            $dossier = new DossierClub();
            $dossier->setLicencie($licencie);
            $dossier->setStatus(LicenceStatus::LINK_SENT);

            $this->em->persist($licencie);
            $this->em->persist($dossier);

            $newLicencies[] = $licencie;
            $result->created++;
        }
    }

    private function processDirigeantRow(
        array $row,
        array $colIndexes,
        Season $season,
        ImportResultData $result,
        int $lineNumber,
        array &$pendingNumLicences,
    ): void {
        ['nom' => $nom, 'prenom' => $prenom] = $this->sanitizer->splitNomPrenom(
            (string) $row[$colIndexes[self::COL_NOM_PRENOM]]
        );

        $rawNumLicence = trim((string) ($row[$colIndexes[self::COL_NUMERO_PERSONNE]] ?? ''));
        $numLicence    = $rawNumLicence !== '' ? $this->sanitizer->sanitizeNumLicence($rawNumLicence) : null;

        if ($numLicence !== null && isset($pendingNumLicences[$numLicence])) {
            $result->addError($lineNumber, "Doublon ignoré : $nom $prenom (n°$numLicence) apparaît plusieurs fois dans le fichier.");
            return;
        }

        $email      = $this->sanitizer->sanitizeEmail($this->colValue($row, $colIndexes, self::COL_EMAIL_PRINCIPAL));
        $telephone  = $this->sanitizer->sanitizePhone($this->colValue($row, $colIndexes, self::COL_MOBILE_PERSONNEL));
        $rawDate    = trim((string) ($row[$colIndexes[self::COL_DATE_NAISSANCE]] ?? ''));
        $dateNaissance = $rawDate !== '' ? $this->sanitizer->sanitizeDateNaissance($rawDate) : null;
        $rawRole    = trim((string) ($row[$colIndexes[self::COL_SOUS_CATEGORIE]] ?? ''));
        $role       = $rawRole !== '' ? $this->findOrCreateRole(mb_convert_case($rawRole, MB_CASE_TITLE, 'UTF-8')) : null;

        $dirigeant = ($numLicence !== null ? $this->dirigeantRepository->findByNumLicence($numLicence) : null)
            ?? $this->dirigeantRepository->findByNomPrenomSaison($nom, $prenom, $season);

        if ($numLicence !== null) {
            $pendingNumLicences[$numLicence] = true;
        }

        if ($dirigeant !== null) {
            if ($dirigeant->getNumLicence() === null && $numLicence !== null) {
                $dirigeant->setNumLicence($numLicence);
            }
            $dirigeant->setNom($nom);
            $dirigeant->setPrenom($prenom);
            $dirigeant->setEmail($email);
            $dirigeant->setTelephone($telephone);
            $dirigeant->setDateNaissance($dateNaissance);
            $dirigeant->setRole($role);
            $result->updated++;
        } else {
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
            $this->em->flush();
        }

        $this->roleCache[$label] = $role;
        return $role;
    }

    private function colValue(array $row, array $colIndexes, string $col): ?string
    {
        if (!isset($colIndexes[$col]) || $colIndexes[$col] === null) {
            return null;
        }
        $val = $row[$colIndexes[$col]] ?? null;
        return $val !== null ? (string) $val : null;
    }

    /** @return array<string, int|null> */
    private function resolveColumnIndexes(array $headers): array
    {
        $find = function (string $name) use ($headers): ?int {
            foreach ($headers as $index => $header) {
                if ($header === $name) {
                    return $index;
                }
            }
            return null;
        };

        return [
            self::COL_TYPE_LICENCE        => $find(self::COL_TYPE_LICENCE),
            self::COL_NOM_PRENOM          => $find(self::COL_NOM_PRENOM),
            self::COL_DATE_NAISSANCE      => $find(self::COL_DATE_NAISSANCE),
            self::COL_SOUS_CATEGORIE      => $find(self::COL_SOUS_CATEGORIE),
            self::COL_VOIE_RUE            => $find(self::COL_VOIE_RUE),
            self::COL_CODE_POSTAL         => $find(self::COL_CODE_POSTAL),
            self::COL_BUREAU_DISTRIBUTEUR => $find(self::COL_BUREAU_DISTRIBUTEUR),
            self::COL_NUMERO_PERSONNE     => $find(self::COL_NUMERO_PERSONNE),
            self::COL_MOBILE_PERSONNEL    => $find(self::COL_MOBILE_PERSONNEL),
            self::COL_EMAIL_PRINCIPAL     => $find(self::COL_EMAIL_PRINCIPAL),
        ];
    }
}
