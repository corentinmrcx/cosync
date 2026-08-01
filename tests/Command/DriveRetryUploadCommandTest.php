<?php declare(strict_types=1);

namespace App\Tests\Command;

use App\Entity\Dirigeant;
use App\Entity\Season;
use App\Repository\DirigeantRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Rattrapage des uploads Drive. Un document reste « en attente » tant que sa
 * colonne porte un chemin local absolu ; une fois sur Drive elle porte un ID.
 * Le règlement des dirigeants n'était pas couvert : son PDF pouvait rester
 * indéfiniment sur le disque sans reprise possible.
 */
final class DriveRetryUploadCommandTest extends KernelTestCase
{
    private ?Season $season = null;

    public function testLeReglementDirigeantEnLocalEstDetecte(): void
    {
        self::bootKernel();
        $enAttente = $this->makeDirigeant('MARTIN', '/var/www/html/var/pdfs/abc_reglement_dirigeant.pdf');
        $dejaSurDrive = $this->makeDirigeant('DUPONT', 'drive-file-id');

        $trouves = self::getContainer()->get(DirigeantRepository::class)->findWithLocalReglement();
        $uuids   = array_map(static fn (Dirigeant $d): string => (string) $d->getUuid(), $trouves);

        self::assertContains((string) $enAttente->getUuid(), $uuids);
        self::assertNotContains((string) $dejaSurDrive->getUuid(), $uuids, 'Un règlement déjà archivé n\'est pas à rattraper.');
    }

    public function testLaCommandeRapporteLaSectionReglementsDirigeants(): void
    {
        self::bootKernel();
        $this->makeDirigeant('LAGRANGE', '/var/www/html/var/pdfs/def_reglement_dirigeant.pdf');

        $tester = $this->runCommand();
        $output = $tester->getDisplay();

        self::assertStringContainsString('règlement(s) dirigeant(s) en attente', $output);
        self::assertStringContainsString('LAGRANGE', $output);
    }

    public function testUneFileVideNEmpechePasLesAutresSections(): void
    {
        self::bootKernel();

        $tester = $this->runCommand();
        $output = $tester->getDisplay();

        // Les trois familles sont annoncées même quand la première n'a rien à faire.
        self::assertStringContainsString('règlement(s) licencié(s)', $output);
        self::assertStringContainsString('règlement(s) dirigeant(s)', $output);
        self::assertStringContainsString('attestation(s) de remise de clés', $output);
        self::assertSame(0, $tester->getStatusCode(), 'Rien à rattraper → succès.');
    }

    private function runCommand(): CommandTester
    {
        $command = (new Application(self::$kernel))->find('app:drive-retry-upload');
        $tester  = new CommandTester($command);
        $tester->execute([]);

        return $tester;
    }

    private function makeDirigeant(string $nom, string $reglementPath): Dirigeant
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);

        $dirigeant = (new Dirigeant())
            ->setNom($nom)
            ->setPrenom('Kevin')
            ->setSeason($this->season())
            ->setReglementSignePath($reglementPath)
            ->setReglementSignedAt(new \DateTimeImmutable());

        $em->persist($dirigeant);
        $em->flush();

        return $dirigeant;
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
