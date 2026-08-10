<?php declare(strict_types=1);

namespace App\Tests\Command;

use App\Entity\Dirigeant;
use App\Entity\DocumentSignable;
use App\Entity\DocumentSignature;
use App\Entity\Season;
use App\Repository\DocumentSignatureRepository;
use App\Tests\Support\DocumentFixtures;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Rattrapage des uploads Drive. Un document reste « en attente » tant que sa
 * colonne porte un chemin local absolu ; une fois sur Drive elle porte un ID.
 * Tous les documents signés — règlements comme chartes, licenciés comme
 * dirigeants — partagent désormais la même section de rattrapage.
 */
final class DriveRetryUploadCommandTest extends KernelTestCase
{
    private ?Season $season = null;
    private ?DocumentSignable $document = null;

    public function testUnDocumentEnLocalEstDetecte(): void
    {
        self::bootKernel();
        $enAttente = $this->makeSignature('MARTIN', '/var/www/html/var/pdfs/abc_reglement_dirigeant.pdf');
        $dejaSurDrive = $this->makeSignature('DUPONT', 'drive-file-id');

        $trouves = self::getContainer()->get(DocumentSignatureRepository::class)->findWithLocalPath();
        $ids = array_map(static fn (DocumentSignature $s): int => $s->getId(), $trouves);

        self::assertContains($enAttente->getId(), $ids);
        self::assertNotContains($dejaSurDrive->getId(), $ids, 'Un document déjà archivé n\'est pas à rattraper.');
    }

    public function testLaCommandeRapporteLaSectionDocumentsSignes(): void
    {
        self::bootKernel();
        $this->makeSignature('LAGRANGE', '/var/www/html/var/pdfs/def_reglement_dirigeant.pdf');

        $tester = $this->runCommand();
        $output = $tester->getDisplay();

        self::assertStringContainsString('document(s) signé(s) en attente', $output);
        self::assertStringContainsString('LAGRANGE', $output);
        self::assertStringContainsString('Règlement intérieur des dirigeants', $output);
    }

    public function testUneFileVideNEmpechePasLesAutresSections(): void
    {
        self::bootKernel();

        $tester = $this->runCommand();
        $output = $tester->getDisplay();

        // Les deux familles restantes sont annoncées même quand la première n'a rien à faire.
        self::assertStringContainsString('document(s) signé(s)', $output);
        self::assertStringContainsString('attestation(s) de remise de clés', $output);
        self::assertSame(0, $tester->getStatusCode(), 'Rien à rattraper → succès.');
    }

    private function runCommand(): CommandTester
    {
        $command = (new Application(self::$kernel))->find('app:drive-retry-upload');
        $tester = new CommandTester($command);
        $tester->execute([]);

        return $tester;
    }

    private function makeSignature(string $nom, string $drivePath): DocumentSignature
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $fixtures = new DocumentFixtures($em);

        $dirigeant = (new Dirigeant())
            ->setNom($nom)
            ->setPrenom('Kevin')
            ->setSeason($this->season());

        $em->persist($dirigeant);
        $em->flush();

        $signature = $fixtures->signerParDirigeant($this->document($fixtures), $dirigeant, $drivePath);
        $em->flush();

        return $signature;
    }

    /** Un seul document pour la saison : son code est unique par saison. */
    private function document(DocumentFixtures $fixtures): DocumentSignable
    {
        if ($this->document === null) {
            $this->document = $fixtures->documentDirigeant($this->season());
            self::getContainer()->get(EntityManagerInterface::class)->flush();
        }

        return $this->document;
    }

    /** Le label de saison est unique en base : une seule saison par test. */
    private function season(): Season
    {
        if ($this->season !== null) {
            return $this->season;
        }

        $em = self::getContainer()->get(EntityManagerInterface::class);

        $this->season = (new Season())->setLabel('2025-2026')->setCotisationDefaut(85);
        $em->persist($this->season);
        $em->flush();

        return $this->season;
    }
}
