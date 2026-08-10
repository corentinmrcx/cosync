<?php declare(strict_types=1);

namespace App\Tests\Service\Drive;

use App\Service\Drive\DriveDestination;
use App\Service\Drive\DriveUploader;
use App\Service\Drive\LocalFileDriveSync;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

/**
 * L'archivage sur Drive est la seule étape où une signature manuscrite peut disparaître :
 * le PDF local en est la seule copie tant que l'upload n'a pas abouti.
 *
 * Ces tests fixent la garantie centrale — le fichier local n'est jamais supprimé avant
 * que Drive ait confirmé — et les cas où l'on ne doit rien tenter.
 */
final class LocalFileDriveSyncTest extends TestCase
{
    private string $fichier;

    protected function setUp(): void
    {
        $this->fichier = tempnam(sys_get_temp_dir(), 'sync') . '.pdf';
        file_put_contents($this->fichier, '%PDF-signature');
    }

    protected function tearDown(): void
    {
        @unlink($this->fichier);
    }

    public function testUnUploadReussiEnregistreLIdentifiantEtSupprimeLeFichierLocal(): void
    {
        $sujet = new SujetArchivable($this->fichier);
        $sync = $this->sync($this->uploaderQuiRepond('drive-id-42'));

        self::assertTrue($sync->sync($sujet));
        self::assertSame('drive-id-42', $sujet->chemin, 'La colonne porte désormais l\'identifiant Drive.');
        self::assertFileDoesNotExist($this->fichier);
    }

    public function testUnUploadEnEchecConserveLeFichierLocal(): void
    {
        $sujet = new SujetArchivable($this->fichier);
        $sync = $this->sync($this->uploaderQuiRepond(null));

        self::assertFalse($sync->sync($sujet));
        self::assertSame($this->fichier, $sujet->chemin, 'Le chemin local reste, pour que le cron réessaie.');
        self::assertFileExists($this->fichier, 'Sans cette copie, la signature serait perdue.');
    }

    public function testUnFichierDejaSurDriveNeDeclencheAucunUpload(): void
    {
        $sujet = new SujetArchivable('drive-id-deja-la');
        $uploader = $this->uploaderQuiEchoueSiAppele();

        self::assertTrue($this->sync($uploader)->sync($sujet));
        self::assertSame('drive-id-deja-la', $sujet->chemin);
    }

    public function testUnSujetSansFichierNeDeclencheAucunUpload(): void
    {
        $sujet = new SujetArchivable(null);

        self::assertFalse($this->sync($this->uploaderQuiEchoueSiAppele())->sync($sujet));
    }

    /** Le fichier a été effacé entre-temps : rien à envoyer, et surtout pas d'erreur fatale. */
    public function testUnFichierLocalIntrouvableEstSignaleSansExploser(): void
    {
        $sujet = new SujetArchivable('/tmp/fichier-qui-nexiste-pas-' . uniqid() . '.pdf');

        self::assertFalse($this->sync($this->uploaderQuiEchoueSiAppele())->sync($sujet));
    }

    private function sync(DriveUploader $uploader): SyncDeTest
    {
        return new SyncDeTest($uploader, $this->createStub(EntityManagerInterface::class));
    }

    private function uploaderQuiRepond(?string $driveId): DriveUploader
    {
        return new class($driveId) implements DriveUploader {
            public function __construct(private readonly ?string $driveId) {}

            public function uploadToPath(string $localPdfPath, string $seasonLabel, array $segments, string $filename, string $logRef = ''): ?string
            {
                return $this->driveId;
            }
        };
    }

    private function uploaderQuiEchoueSiAppele(): DriveUploader
    {
        return new class implements DriveUploader {
            public function uploadToPath(string $localPdfPath, string $seasonLabel, array $segments, string $filename, string $logRef = ''): ?string
            {
                TestCase::fail('Aucun upload ne devait être tenté.');
            }
        };
    }
}

/**
 * Implémentation minimale de l'archivage, pour éprouver l'ossature sans dépendre
 * d'une entité Doctrine.
 *
 * @extends LocalFileDriveSync<SujetArchivable>
 */
final class SyncDeTest extends LocalFileDriveSync
{
    protected function cheminActuel(object $sujet): ?string
    {
        return $sujet->chemin;
    }

    protected function enregistrerDriveId(object $sujet, string $driveId): void
    {
        $sujet->chemin = $driveId;
    }

    protected function destination(object $sujet): DriveDestination
    {
        return new DriveDestination('2025-2026', ['Documents signés'], 'document.pdf');
    }
}

/** Sujet minimal : une colonne qui porte tantôt un chemin local, tantôt un identifiant Drive. */
final class SujetArchivable
{
    public function __construct(public ?string $chemin) {}
}
