<?php declare(strict_types=1);

namespace App\Tests\Command;

use App\Service\Backup\DatabaseBackupService;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Sauvegarde de la base. Rien n'est simulé : pg_dump tourne réellement contre la base
 * de test, et l'absence de credentials Google en environnement de test fait naturellement
 * échouer la copie Drive — ce qui est précisément le scénario de panne à couvrir.
 *
 * Ce que ces tests garantissent : un dump raté ou vide fait échouer la commande. Sans
 * cela, le cron nightly rapporterait un succès en n'archivant rien, et la panne ne se
 * découvrirait que le jour où l'on cherche à restaurer.
 */
final class DatabaseBackupCommandTest extends KernelTestCase
{
    /** @var string[] */
    private array $crees = [];

    protected function tearDown(): void
    {
        foreach ($this->crees as $path) {
            @unlink($path);
        }
        $this->crees = [];

        parent::tearDown();
    }

    public function testLaCommandeProduitUnDumpGzipExploitable(): void
    {
        $tester = $this->lancer(['--sans-drive' => true]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('Dump créé', $tester->getDisplay());

        $dump = $this->dernierDump();
        self::assertNotNull($dump, 'La commande doit laisser un fichier dans var/backups');

        $contenu = gzdecode((string) file_get_contents($dump));
        self::assertIsString($contenu, 'Le fichier doit être un gzip valide');
        self::assertStringContainsString('CREATE TABLE', $contenu);
        self::assertStringContainsString('licencie', $contenu, 'Le dump doit contenir le schéma métier');
        self::assertStringContainsString('transaction', $contenu, 'Le dump doit contenir les encaissements');
    }

    /**
     * Drive injoignable (aucun credential en test) : le dump local reste la sauvegarde
     * qui compte, la commande ne doit donc pas échouer — mais l'alerte doit être visible,
     * sinon personne ne saura que les copies off-site ont cessé.
     */
    public function testUnEchecDriveAlerteSansDetruireLaSauvegardeLocale(): void
    {
        $tester = $this->lancer([]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('Copie Drive impossible', $tester->getDisplay());
        self::assertNotNull($this->dernierDump(), 'Le dump local doit survivre à un échec Drive');
    }

    public function testLeDumpEstDeposeDansUnDossierMensuelDuDrive(): void
    {
        self::bootKernel();
        $service = self::getContainer()->get(DatabaseBackupService::class);

        self::assertSame(
            ['Sauvegardes', '2026-03'],
            $service->driveSegments(new \DateTimeImmutable('2026-03-15')),
        );
        self::assertSame(
            'application/gzip',
            DatabaseBackupService::MIME_TYPE,
            'Un .sql.gz ne doit pas être annoncé comme un PDF',
        );
    }

    public function testLesDumpsPlusVieuxQueLaRetentionSontSupprimes(): void
    {
        self::bootKernel();
        $service = self::getContainer()->get(DatabaseBackupService::class);

        @mkdir($service->backupDirectory(), 0775, true);

        $ancien = $service->backupDirectory() . '/backup_20200101_000000.sql.gz';
        $recent = $service->backupDirectory() . '/backup_' . (new \DateTimeImmutable())->format('Ymd_His') . '.sql.gz';

        file_put_contents($ancien, gzencode('vieux dump'));
        file_put_contents($recent, gzencode('dump du jour'));
        touch($ancien, (new \DateTimeImmutable('-60 days'))->getTimestamp());
        $this->crees[] = $ancien;
        $this->crees[] = $recent;

        $supprimes = $service->purgerAnciens(30);

        self::assertSame([$ancien], $supprimes);
        self::assertFileDoesNotExist($ancien);
        self::assertFileExists($recent, 'Un dump récent ne doit jamais être purgé');
    }

    /**
     * Base injoignable : pg_dump échoue et ne doit surtout pas laisser derrière lui un
     * fichier tronqué qui passerait pour une sauvegarde valable.
     */
    public function testUnPgDumpEnEchecNeLaissePasDeFichier(): void
    {
        self::bootKernel();
        $reference = self::getContainer()->get(DatabaseBackupService::class);

        $service = new DatabaseBackupService(
            projectDir: \dirname($reference->backupDirectory(), 2),
            database: 'base_qui_nexiste_pas',
            user: 'utilisateur_inexistant',
            password: 'mauvais',
        );

        $avant = $service->listerDumps();

        try {
            $service->dump();
            self::fail('Un pg_dump en échec doit lever une RuntimeException');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('pg_dump', $e->getMessage());
        }

        self::assertSame($avant, $service->listerDumps(), 'Aucun fichier partiel ne doit subsister');
    }

    /* ── Outils ── */

    /** @param array<string, mixed> $options */
    private function lancer(array $options): CommandTester
    {
        self::bootKernel();

        $avant = $this->dumpsPresents();
        $tester = new CommandTester((new Application(self::$kernel))->find('app:db:backup'));
        $tester->execute($options);

        $this->crees = array_merge($this->crees, array_diff($this->dumpsPresents(), $avant));

        return $tester;
    }

    /** @return string[] */
    private function dumpsPresents(): array
    {
        return self::getContainer()->get(DatabaseBackupService::class)->listerDumps();
    }

    private function dernierDump(): ?string
    {
        return $this->dumpsPresents()[0] ?? null;
    }
}
