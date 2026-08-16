<?php declare(strict_types=1);

namespace App\Tests\Service\Stock;

use App\Entity\Dirigeant;
use App\Entity\DotationAffectation;
use App\Entity\Season;
use App\Repository\DotationBesoinRepository;
use App\Service\Dotation\DotationBesoinSynchronizer;

/**
 * Licence déclarée au district pour l'association (président, secrétaire, trésorier) : la
 * personne n'a rien à voir avec le terrain et ne veut pas de kit.
 *
 * Le verrou est en amont de la complétude du dossier, et c'est le point du test : sans lui,
 * il suffisait qu'un admin renseigne des tailles sur la fiche pour que le kit se matérialise
 * et parte en sortie de stock à préparer.
 */
final class DotationLicenceAdministrativeTest extends StockIntegrationTestCase
{
    private function synchronizer(): DotationBesoinSynchronizer
    {
        return $this->service(DotationBesoinSynchronizer::class);
    }

    private function besoinRepo(): DotationBesoinRepository
    {
        return $this->service(DotationBesoinRepository::class);
    }

    /** Dossier de base complet : c'est ce qui ouvrirait normalement le droit à la dotation. */
    private function makeDirigeant(Season $season, bool $administrative): Dirigeant
    {
        static $n = 0;
        ++$n;

        $dirigeant = (new Dirigeant())
            ->setNom('PRESIDENT' . $n)
            ->setPrenom('Jean' . $n)
            ->setSeason($season)
            ->setTailleHaut('L')
            ->setTailleBas('L')
            ->setPointure('42')
            ->setAutorisationPhoto(true)
            ->setVolontaireTransport(false)
            ->setLicenceAdministrative($administrative);

        $this->em->persist($dirigeant);
        $this->em->flush();

        /** @var Dirigeant $reloaded */
        $reloaded = $this->reload($dirigeant);

        return $reloaded;
    }

    private function kitParDefaut(Season $season): void
    {
        $modele = $this->makeModele($season, 'Kit dirigeant');
        $this->addLigne($modele, $this->makeItem('Polo'));
        $this->em->persist((new DotationAffectation())->setSeason($season)->setModele($modele));
        $this->em->flush();
    }

    public function testUnDirigeantOrdinaireAuDossierCompletRecoitSonKit(): void
    {
        $season = $this->makeSeason();
        $this->kitParDefaut($season);

        $dirigeant = $this->makeDirigeant($season, administrative: false);
        $this->synchronizer()->recomputeForDirigeant($dirigeant);

        self::assertCount(1, $this->besoinRepo()->findForDirigeant($dirigeant));
    }

    public function testUneLicenceAdministrativeNaAucunBesoinMalgreUnDossierComplet(): void
    {
        $season = $this->makeSeason();
        $this->kitParDefaut($season);

        $dirigeant = $this->makeDirigeant($season, administrative: true);
        self::assertFalse($this->synchronizer()->recomputeForDirigeant($dirigeant));

        self::assertSame([], $this->besoinRepo()->findForDirigeant($dirigeant));
    }

    /**
     * Le recalcul est aussi un outil de réparation : cocher la case après coup doit retirer
     * les besoins déjà matérialisés à tort, sinon la personne resterait à équiper.
     */
    public function testCocherLaCaseRetireLesBesoinsDejaMaterialises(): void
    {
        $season = $this->makeSeason();
        $this->kitParDefaut($season);

        $dirigeant = $this->makeDirigeant($season, administrative: false);
        $this->synchronizer()->recomputeForDirigeant($dirigeant);
        self::assertCount(1, $this->besoinRepo()->findForDirigeant($dirigeant));

        $dirigeant->setLicenceAdministrative(true);
        $this->em->flush();

        $this->synchronizer()->recomputeForDirigeant($dirigeant);
        self::assertSame([], $this->besoinRepo()->findForDirigeant($dirigeant));
    }
}
