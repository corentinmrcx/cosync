<?php declare(strict_types=1);

namespace App\Tests\Service\Stock;

use App\Entity\Dirigeant;
use App\Entity\Season;
use App\Enum\DirigeantRole;
use App\Service\Dotation\DotationSuiviPresenter;

/**
 * Regroupement de l'écran de suivi : les équipes de joueurs d'un côté, l'encadrement de l'autre.
 *
 * L'équipe d'un dirigeant est une indication interne — « il s'occupe des Séniors ». La mêler aux
 * joueurs de cette équipe mettait deux kits sans rapport dans le même tableau, et renvoyait le
 * reste de l'encadrement dans un « Sans équipe » qu'on lisait comme un oubli d'affectation.
 */
final class DotationSuiviGroupesTest extends StockIntegrationTestCase
{
    private function suivi(): DotationSuiviPresenter
    {
        return $this->service(DotationSuiviPresenter::class);
    }

    private function makeDirigeant(Season $season, string $nom): Dirigeant
    {
        $dirigeant = (new Dirigeant())
            ->setNom($nom)
            ->setPrenom('Olivier')
            ->setSeason($season)
            ->setRole(DirigeantRole::DIRIGEANT);
        $this->em->persist($dirigeant);

        return $dirigeant;
    }

    public function testLesDirigeantsFormentLeurPropreGroupe(): void
    {
        $season = $this->makeSeason();
        $cat = $this->makeCategory('SENIOR');
        $seniors = $this->makeTeam($season, 'Séniors');
        $maillot = $this->makeItem('Maillot');
        $polo = $this->makeItem('Polo');

        $joueur = $this->makeLicencie($season, $cat, $seniors);
        $this->makeBesoin($season, $maillot, 'L')->setLicencie($joueur);

        // Le dirigeant est rattaché à la même équipe : c'est le cas qui brouillait l'écran.
        $dirigeant = $this->makeDirigeant($season, 'MARCOUX');
        $dirigeant->setTeam($seniors);
        $this->makeBesoin($season, $polo, 'XL')->setDirigeant($dirigeant);

        $this->em->flush();

        $groupes = $this->suivi()->groupesDeSuivi($season);

        self::assertSame(['Séniors', 'Dirigeants'], array_column($groupes, 'nom'));
        self::assertSame('Maillot', $groupes[0]->besoins[0]->getStockItem()->getNom());
        self::assertSame('Polo', $groupes[1]->besoins[0]->getStockItem()->getNom());
    }

    /** Joueur et dirigeant à la fois : deux fiches, deux kits, donc deux blocs de lignes. */
    public function testUnePersonneJoueuseEtDirigeanteTientDeuxLignes(): void
    {
        $season = $this->makeSeason();
        $cat = $this->makeCategory('SENIOR');
        $seniors = $this->makeTeam($season, 'Séniors');
        $maillot = $this->makeItem('Maillot');
        $polo = $this->makeItem('Polo');

        $joueur = $this->makeLicencie($season, $cat, $seniors);
        $this->makeBesoin($season, $maillot, 'L')->setLicencie($joueur);

        $dirigeant = $this->makeDirigeant($season, $joueur->getNom());
        $this->makeBesoin($season, $polo, 'L')->setDirigeant($dirigeant);

        $this->em->flush();

        $groupes = $this->suivi()->groupesDeSuivi($season);

        self::assertCount(2, $groupes, 'Sa dotation de joueur et celle de dirigeant ne se mélangent pas.');
        self::assertSame(1, $groupes[0]->total);
        self::assertSame(1, $groupes[1]->total);
    }

    /** Les deux fourre-tout ferment la marche : ce sont les seuls qu'on ne prépare pas par équipe. */
    public function testLesGroupesFourreToutPassentEnFinDeListe(): void
    {
        $season = $this->makeSeason();
        $cat = $this->makeCategory('SENIOR');
        $item = $this->makeItem('Maillot');

        $this->makeBesoin($season, $item, 'L')
            ->setLicencie($this->makeLicencie($season, $cat, $this->makeTeam($season, 'U15')));
        $this->makeBesoin($season, $item, 'L')
            ->setLicencie($this->makeLicencie($season, $cat, null));  // sans équipe
        $this->makeBesoin($season, $item, 'XL')->setDirigeant($this->makeDirigeant($season, 'ZOLA'));
        $this->makeBesoin($season, $item, 'M')
            ->setLicencie($this->makeLicencie($season, $cat, $this->makeTeam($season, 'Séniors')));

        $this->em->flush();

        self::assertSame(
            ['Séniors', 'U15', 'Sans équipe', 'Dirigeants'],
            array_column($this->suivi()->groupesDeSuivi($season), 'nom'),
        );
    }
}
