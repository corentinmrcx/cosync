<?php declare(strict_types=1);

namespace App\Tests\Service\Referentiel;

use App\Entity\Category;
use App\Entity\DossierClub;
use App\Entity\Licencie;
use App\Entity\Season;
use App\Entity\Taille;
use App\Enum\TailleType;
use App\Repository\TailleRepository;
use App\Service\Referentiel\TailleReferentiel;
use App\Service\Referentiel\TailleService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Les gardes du référentiel, côté service : une taille employée est un mot recopié dans des
 * dossiers et des mouvements, pas une simple ligne de configuration.
 */
final class TailleServiceTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private TailleService $service;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->service = self::getContainer()->get(TailleService::class);
    }

    public function testUneTailleDeclareeDansUnDossierNeSeSupprimePas(): void
    {
        $taille = $this->taille('L');
        $this->dossierAvecTaille('L');

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessageMatches('/Impossible de supprimer/');

        $this->service->supprimer($taille);
    }

    public function testUneTailleInutiliseeSeSupprimeEtQuitteLesSelecteurs(): void
    {
        $referentiel = self::getContainer()->get(TailleReferentiel::class);
        $taille = $this->taille('164');

        self::assertContains('164', $referentiel->pourLeStock(TailleType::VETEMENT));

        $this->service->supprimer($taille);

        self::assertNotContains('164', $referentiel->pourLeStock(TailleType::VETEMENT), 'Le cache de requête doit être oublié après écriture.');
    }

    public function testDeuxTaillesDeTypesDifferentsPeuventPorterLeMemeLibelle(): void
    {
        // « 42 » est une pointure ; rien n'interdit un vêtement taillé 42.
        $this->service->creer((new Taille())->setLibelle('42')->setType(TailleType::VETEMENT));

        self::assertNotNull(self::getContainer()->get(TailleRepository::class)->findOneByLibelle(TailleType::VETEMENT, '42'));
        self::assertNotNull(self::getContainer()->get(TailleRepository::class)->findOneByLibelle(TailleType::POINTURE, '42'));
    }

    private function taille(string $libelle): Taille
    {
        $taille = self::getContainer()->get(TailleRepository::class)->findOneBy(['libelle' => $libelle]);
        self::assertNotNull($taille, sprintf('Taille "%s" absente du référentiel.', $libelle));

        return $taille;
    }

    private function dossierAvecTaille(string $libelle): void
    {
        $season = (new Season())->setLabel('2025-2026')->setCotisationDefaut(85);
        $this->em->persist($season);

        $categorie = (new Category())->setCode('U15')->setLabel('U15')->setIsEcoleFoot(false);
        $this->em->persist($categorie);

        $licencie = (new Licencie())
            ->setNom('DUPONT')
            ->setPrenom('Thomas')
            ->setDateNaissance(new \DateTimeImmutable('2010-01-01'))
            ->setCategory($categorie)
            ->setSeason($season);
        $this->em->persist($licencie);

        $dossier = (new DossierClub())->setLicencie($licencie)->setTailleHaut($libelle);
        $this->em->persist($dossier);

        $this->em->flush();
    }
}
