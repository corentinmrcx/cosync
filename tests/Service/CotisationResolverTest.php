<?php declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Category;
use App\Entity\Licencie;
use App\Entity\Season;
use App\Entity\Team;
use App\Service\Payment\CotisationResolver;
use PHPUnit\Framework\TestCase;

final class CotisationResolverTest extends TestCase
{
    private function makeLicencie(Season $season, ?Team $team): Licencie
    {
        $licencie = (new Licencie())
            ->setNom('DUPONT')
            ->setPrenom('Thomas')
            ->setCategory((new Category())->setCode('U17')->setLabel('U17')->setIsEcoleFoot(false))
            ->setSeason($season);
        $licencie->setTeam($team);

        return $licencie;
    }

    public function testCotisationDeLEquipePrimeSurLaSaison(): void
    {
        $season = (new Season())->setCotisationDefaut(85);
        $team = (new Team())->setName('Séniors 1')->setCotisation(120);

        $montant = (new CotisationResolver())->resolve($this->makeLicencie($season, $team));

        self::assertSame(120, $montant);
    }

    public function testEquipeSansCotisationUtiliseLeDefautDeLaSaison(): void
    {
        $season = (new Season())->setCotisationDefaut(85);
        $team = (new Team())->setName('Loisirs'); // cotisation null

        $montant = (new CotisationResolver())->resolve($this->makeLicencie($season, $team));

        self::assertSame(85, $montant);
    }

    public function testLicencieSansEquipeUtiliseLeDefautDeLaSaison(): void
    {
        $season = (new Season())->setCotisationDefaut(85);

        $montant = (new CotisationResolver())->resolve($this->makeLicencie($season, null));

        self::assertSame(85, $montant);
    }
}
