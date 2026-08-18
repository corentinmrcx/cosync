<?php declare(strict_types=1);

namespace App\Tests\Service\Stock;

use App\Entity\Dirigeant;
use App\Entity\DotationAffectation;
use App\Entity\DotationModele;
use App\Entity\Season;
use App\Enum\DirigeantRole;
use App\Service\Dotation\DotationResolver;

/**
 * Dotation de l'encadrement : un responsable, un responsable d'équipe et un dirigeant standard ne
 * reçoivent pas le même kit. Le rôle est le pendant, côté dirigeants, de la catégorie FFF côté
 * licenciés — même rang de priorité, et les deux ne se disputent jamais une même personne.
 */
final class DotationRoleDirigeantTest extends StockIntegrationTestCase
{
    private function resolver(): DotationResolver
    {
        return $this->service(DotationResolver::class);
    }

    private function makeDirigeant(Season $season, DirigeantRole $role): Dirigeant
    {
        static $n = 0;
        ++$n;
        $dirigeant = (new Dirigeant())
            ->setNom('ENCADRANT' . $n)
            ->setPrenom('Paul' . $n)
            ->setSeason($season)
            ->setRole($role);
        $this->em->persist($dirigeant);

        return $dirigeant;
    }

    private function affecterRole(Season $season, DotationModele $modele, DirigeantRole $role): void
    {
        $this->em->persist((new DotationAffectation())->setSeason($season)->setModele($modele)->setRole($role));
    }

    public function testChaqueRoleRecoitSonKit(): void
    {
        $season = $this->makeSeason();

        $kitCoach = $this->makeModele($season, 'Kit responsable équipe');
        $this->addLigne($kitCoach, $this->makeItem('Coupe-vent'));
        $this->affecterRole($season, $kitCoach, DirigeantRole::RESPONSABLE_EQUIPE);

        $kitChef = $this->makeModele($season, 'Kit responsable foot');
        $this->addLigne($kitChef, $this->makeItem('Polo'));
        $this->affecterRole($season, $kitChef, DirigeantRole::RESPONSABLE_FOOT);

        $unCoach = $this->makeDirigeant($season, DirigeantRole::RESPONSABLE_EQUIPE);
        $unChef = $this->makeDirigeant($season, DirigeantRole::RESPONSABLE_FOOT);

        /** @var Dirigeant $unCoach */
        $unCoach = $this->reload($unCoach);
        /** @var Dirigeant $unChef */
        $unChef = $this->em->find(Dirigeant::class, $unChef->getUuid());

        self::assertSame('Kit responsable équipe', $this->resolver()->resolveModele($unCoach)?->getNom());
        self::assertSame('Kit responsable foot', $this->resolver()->resolveModele($unChef)?->getNom());
    }

    /** Le rôle est obligatoire : un dirigeant non ciblé retombe sur le kit par défaut de la saison. */
    public function testUnRoleNonCibleRetombeSurLeKitParDefaut(): void
    {
        $season = $this->makeSeason();

        $kitDefaut = $this->makeModele($season, 'Kit standard');
        $this->addLigne($kitDefaut, $this->makeItem('Sweat'));
        $this->em->persist((new DotationAffectation())->setSeason($season)->setModele($kitDefaut));

        $kitCoach = $this->makeModele($season, 'Kit responsable équipe');
        $this->addLigne($kitCoach, $this->makeItem('Coupe-vent'));
        $this->affecterRole($season, $kitCoach, DirigeantRole::RESPONSABLE_EQUIPE);

        $benevole = $this->makeDirigeant($season, DirigeantRole::DIRIGEANT);
        /** @var Dirigeant $benevole */
        $benevole = $this->reload($benevole);

        self::assertSame('Kit standard', $this->resolver()->resolveModele($benevole)?->getNom());
    }

    public function testUneAffectationIndividuelleLEmporteSurLeRole(): void
    {
        $season = $this->makeSeason();

        $kitCoach = $this->makeModele($season, 'Kit responsable équipe');
        $this->addLigne($kitCoach, $this->makeItem('Coupe-vent'));
        $this->affecterRole($season, $kitCoach, DirigeantRole::RESPONSABLE_EQUIPE);

        $kitPerso = $this->makeModele($season, 'Kit sur mesure');
        $this->addLigne($kitPerso, $this->makeItem('Doudoune'));

        $dirigeant = $this->makeDirigeant($season, DirigeantRole::RESPONSABLE_EQUIPE);
        $this->em->persist((new DotationAffectation())->setSeason($season)->setModele($kitPerso)->setDirigeant($dirigeant));

        /** @var Dirigeant $dirigeant */
        $dirigeant = $this->reload($dirigeant);

        self::assertSame('Kit sur mesure', $this->resolver()->resolveModele($dirigeant)?->getNom());
    }

    /** Une cible « rôle » ne doit jamais accrocher un licencié, qui n'en a pas. */
    public function testUnLicencieNEstJamaisCapteParUneCibleRole(): void
    {
        $season = $this->makeSeason();
        $cat = $this->makeCategory('SENIOR');

        $kitCoach = $this->makeModele($season, 'Kit responsable équipe');
        $this->addLigne($kitCoach, $this->makeItem('Coupe-vent'));
        $this->affecterRole($season, $kitCoach, DirigeantRole::RESPONSABLE_EQUIPE);

        $licencie = $this->makeLicencie($season, $cat);
        $licencie = $this->reload($licencie);

        self::assertNull($this->resolver()->resolveModele($licencie));
    }

    /**
     * Une cible « équipe » ne désigne que des joueurs. L'équipe d'un dirigeant dit de qui il
     * s'occupe, pas ce qu'il reçoit : sans ce cloisonnement, un dirigeant rattaché aux Séniors
     * héritait du kit joueur de l'équipe alors qu'aucune affectation ne visait son rôle.
     */
    public function testUnDirigeantNEstJamaisCaptePArUneCibleEquipe(): void
    {
        $season = $this->makeSeason();
        $seniors = $this->makeTeam($season, 'Séniors');

        $kitJoueurs = $this->makeModele($season, 'Kit joueur Séniors');
        $this->addLigne($kitJoueurs, $this->makeItem('Maillot'));
        $this->em->persist((new DotationAffectation())->setSeason($season)->setModele($kitJoueurs)->setTeam($seniors));

        $dirigeant = $this->makeDirigeant($season, DirigeantRole::DIRIGEANT);
        $dirigeant->setTeam($seniors);

        /** @var Dirigeant $dirigeant */
        $dirigeant = $this->reload($dirigeant);

        self::assertNull($this->resolver()->resolveModele($dirigeant));
        self::assertSame([], $this->resolver()->resolveDotation($dirigeant));
    }

    /** Le cloisonnement ne coupe pas le kit par défaut de la saison, qui vise bien tout le monde. */
    public function testLeKitParDefautContinueDeCouvrirUnDirigeantAvecEquipe(): void
    {
        $season = $this->makeSeason();
        $seniors = $this->makeTeam($season, 'Séniors');

        $kitJoueurs = $this->makeModele($season, 'Kit joueur Séniors');
        $this->addLigne($kitJoueurs, $this->makeItem('Maillot'));
        $this->em->persist((new DotationAffectation())->setSeason($season)->setModele($kitJoueurs)->setTeam($seniors));

        $kitDefaut = $this->makeModele($season, 'Kit standard');
        $this->addLigne($kitDefaut, $this->makeItem('Sweat'));
        $this->em->persist((new DotationAffectation())->setSeason($season)->setModele($kitDefaut));

        $dirigeant = $this->makeDirigeant($season, DirigeantRole::DIRIGEANT);
        $dirigeant->setTeam($seniors);

        /** @var Dirigeant $dirigeant */
        $dirigeant = $this->reload($dirigeant);

        self::assertSame('Kit standard', $this->resolver()->resolveModele($dirigeant)?->getNom());
    }
}
