<?php declare(strict_types=1);

namespace App\Service\Import;

use App\DTO\ImportResultData;
use App\Entity\DossierClub;
use App\Entity\Licencie;
use App\Entity\Season;
use App\Enum\LicenceStatus;
use App\Repository\CategoryRepository;
use App\Repository\LicencieRepository;
use App\Repository\SeasonRepository;
use Doctrine\ORM\EntityManagerInterface;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final class ImportService
{
    // Noms de colonnes FootClubs (insensible à la casse après trim)
    private const COL_TYPE_LICENCE   = 'type licence';
    private const COL_NOM_PRENOM     = 'nom, prénom';
    private const COL_DATE_NAISSANCE = 'né(e) le';
    private const COL_SOUS_CATEGORIE = 'sous catégorie';

    // Seules les licences "Libre" sont importées
    private const TYPE_LICENCE_LIBRE = 'libre';

    public function __construct(
        private readonly DataSanitizer $sanitizer,
        private readonly SeasonRepository $seasonRepository,
        private readonly LicencieRepository $licencieRepository,
        private readonly CategoryRepository $categoryRepository,
        private readonly EntityManagerInterface $em,
    ) {}

    public function importFromXlsx(UploadedFile $file): ImportResultData
    {
        $result = new ImportResultData();

        $season = $this->seasonRepository->findActive();
        if ($season === null) {
            $result->addError(0, 'Aucune saison active. Lancez app:seed-referential ou créez une saison.');
            return $result;
        }

        $spreadsheet = IOFactory::load($file->getPathname());
        $rows        = $spreadsheet->getActiveSheet()->toArray(null, true, true, false);

        if (count($rows) < 2) {
            $result->addError(0, 'Le fichier est vide ou ne contient pas de données.');
            return $result;
        }

        $headers    = array_map(fn(mixed $h): string => mb_strtolower(trim((string) $h), 'UTF-8'), $rows[0]);
        $colIndexes = $this->resolveColumnIndexes($headers);

        $missing = array_keys(array_filter($colIndexes, fn(?int $i) => $i === null));
        if (!empty($missing)) {
            $result->addError(1, sprintf('Colonnes introuvables : %s', implode(', ', $missing)));
            return $result;
        }

        foreach (array_slice($rows, 1) as $offset => $row) {
            $lineNumber = $offset + 2;

            // Ignorer les lignes dont le type de licence n'est pas "Libre"
            $typeLicence = mb_strtolower(trim((string) ($row[$colIndexes[self::COL_TYPE_LICENCE]] ?? '')), 'UTF-8');
            if ($typeLicence !== self::TYPE_LICENCE_LIBRE) {
                continue;
            }

            $rawNomPrenom = trim((string) ($row[$colIndexes[self::COL_NOM_PRENOM]] ?? ''));
            if ($rawNomPrenom === '') {
                continue;
            }

            try {
                $this->processRow($row, $colIndexes, $season, $result, $lineNumber);
            } catch (\Throwable $e) {
                $result->addError($lineNumber, $e->getMessage());
            }
        }

        $this->em->flush();

        return $result;
    }

    private function processRow(
        array $row,
        array $colIndexes,
        Season $season,
        ImportResultData $result,
        int $lineNumber,
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

        $licencie = $this->licencieRepository->findByUpsertKey($nom, $prenom, $dateNaissance);

        if ($licencie !== null) {
            // Licencié existant : on met à jour uniquement les données FFF
            $licencie->setNom($nom);
            $licencie->setPrenom($prenom);
            $licencie->setCategory($category);
            // dateNaissance et season ne changent jamais à l'import
            $result->updated++;
        } else {
            // Nouveau licencié
            $licencie = new Licencie();
            $licencie->setNom($nom);
            $licencie->setPrenom($prenom);
            $licencie->setDateNaissance($dateNaissance);
            $licencie->setCategory($category);
            $licencie->setSeason($season);
            $licencie->setFormTokenExpiresAt(new \DateTimeImmutable('+30 days'));

            $dossier = new DossierClub();
            $dossier->setLicencie($licencie);
            $dossier->setStatus(LicenceStatus::LINK_SENT);

            $this->em->persist($licencie);
            $this->em->persist($dossier);

            $result->created++;
        }
    }

    /**
     * @return array<string, int|null>
     */
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
            self::COL_TYPE_LICENCE   => $find(self::COL_TYPE_LICENCE),
            self::COL_NOM_PRENOM     => $find(self::COL_NOM_PRENOM),
            self::COL_DATE_NAISSANCE => $find(self::COL_DATE_NAISSANCE),
            self::COL_SOUS_CATEGORIE => $find(self::COL_SOUS_CATEGORIE),
        ];
    }
}
