<?php declare(strict_types=1);

namespace App\Tests\Service\Import;

use App\Entity\Category;
use App\Entity\Season;
use Doctrine\ORM\EntityManagerInterface;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Base des tests d'intégration de l'import : conteneur réel + base réelle, chaque test dans une
 * transaction annulée (dama/doctrine-test-bundle). Fabrique les fichiers XLSX en mémoire.
 */
abstract class ImportIntegrationTestCase extends KernelTestCase
{
    protected EntityManagerInterface $em;
    /** @var string[] */
    private array $tempFiles = [];

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
    }

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $path) {
            if (is_file($path)) {
                @unlink($path);
            }
        }
        $this->tempFiles = [];
        parent::tearDown();
    }

    protected function service(string $class): object
    {
        return self::getContainer()->get($class);
    }

    protected function makeSeason(string $label = '2025-2026'): Season
    {
        $season = (new Season())->setLabel($label)->setCotisationDefaut(85);
        $this->em->persist($season);

        return $season;
    }

    protected function makeCategory(string $code, bool $ecoleFoot = false): Category
    {
        // Certaines catégories sont déjà présentes via migration (ex. FOOTLOISIR) : find-or-create.
        $existing = $this->em->getRepository(Category::class)->findOneBy(['code' => $code]);
        if ($existing !== null) {
            return $existing;
        }

        $category = (new Category())->setCode($code)->setLabel($code)->setIsEcoleFoot($ecoleFoot);
        $this->em->persist($category);

        return $category;
    }

    /**
     * Construit un fichier XLSX temporaire à partir d'un en-tête et de lignes, et le retourne
     * enveloppé dans un UploadedFile en mode test.
     *
     * @param string[]           $headers
     * @param array<int, array<int, string|null>> $rows
     */
    protected function makeXlsx(array $headers, array $rows): UploadedFile
    {
        $spreadsheet = new Spreadsheet();
        $sheet       = $spreadsheet->getActiveSheet();
        $sheet->fromArray([$headers, ...$rows], null, 'A1');

        $path = tempnam(sys_get_temp_dir(), 'cosync_import_') . '.xlsx';
        (new Xlsx($spreadsheet))->save($path);
        $this->tempFiles[] = $path;

        return new UploadedFile($path, 'import.xlsx', null, null, true);
    }
}
