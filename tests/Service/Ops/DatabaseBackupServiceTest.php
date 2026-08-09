<?php declare(strict_types=1);

namespace App\Tests\Service\Ops;

use App\Service\Ops\DatabaseBackupService;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * La rétention des sauvegardes n'était couverte par rien. Une purge trop large
 * effacerait le seul filet dont dispose une base qui contient des signatures et des
 * encaissements irremplaçables.
 */
final class DatabaseBackupServiceTest extends KernelTestCase
{
    private DatabaseBackupService $service;
    private string $repertoire;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->service = self::getContainer()->get(DatabaseBackupService::class);
        $this->repertoire = $this->service->backupDirectory();

        if (!is_dir($this->repertoire)) {
            mkdir($this->repertoire, 0755, true);
        }

        foreach ($this->service->listerDumps() as $dump) {
            @unlink($dump);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->service->listerDumps() as $dump) {
            @unlink($dump);
        }
    }

    public function testLesDumpsSontListesDuPlusRecentAuPlusAncien(): void
    {
        $this->creerDump('backup_20260101_020000.sql.gz');
        $this->creerDump('backup_20260315_020000.sql.gz');
        $this->creerDump('backup_20260210_020000.sql.gz');

        $noms = array_map('basename', $this->service->listerDumps());

        self::assertSame([
            'backup_20260315_020000.sql.gz',
            'backup_20260210_020000.sql.gz',
            'backup_20260101_020000.sql.gz',
        ], $noms);
    }

    public function testLaPurgeNeTouchePasAuxDumpsDansLaFenetreDeRetention(): void
    {
        $recent = $this->creerDump('backup_20260801_020000.sql.gz', jours: 5);

        self::assertSame([], $this->service->purgerAnciens(30));
        self::assertFileExists($recent);
    }

    public function testLaPurgeSupprimeLesDumpsAuDelaDeLaRetention(): void
    {
        $vieux = $this->creerDump('backup_20260101_020000.sql.gz', jours: 45);
        $recent = $this->creerDump('backup_20260801_020000.sql.gz', jours: 2);

        $supprimes = $this->service->purgerAnciens(30);

        self::assertSame([$vieux], $supprimes);
        self::assertFileDoesNotExist($vieux);
        self::assertFileExists($recent, 'La sauvegarde récente est le filet du jour : elle reste.');
    }

    /** Le Drive est rangé par mois : une année de sauvegardes nightly fait 365 fichiers. */
    public function testLeCheminDriveEstDecoupeParMois(): void
    {
        $segments = $this->service->driveSegments(new \DateTimeImmutable('2026-03-15'));

        self::assertSame('2026-03', end($segments));
    }

    private function creerDump(string $nom, int $jours = 0): string
    {
        $chemin = $this->repertoire . '/' . $nom;
        file_put_contents($chemin, 'dump');

        if ($jours > 0) {
            touch($chemin, (new \DateTimeImmutable(sprintf('-%d days', $jours)))->getTimestamp());
        }

        return $chemin;
    }
}
