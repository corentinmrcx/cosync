<?php declare(strict_types=1);

namespace App\Tests\Service\Cle;

use App\Entity\CleMouvement;
use App\Entity\Detenteur;
use App\Entity\Dirigeant;
use App\Entity\Fonction;
use App\Entity\Season;
use App\Entity\Team;
use App\Enum\CleMouvementType;
use App\Enum\DirigeantRole;
use App\Service\Cle\AttestationCleRecapService;
use App\Service\Cle\DetenteurFonctionResolver;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Ce qu'on écrit en face d'un détenteur de clés sur le récapitulatif remis à la mairie.
 *
 * Le défaut d'origine : la seule information disponible était le rôle du dirigeant, et
 * `RESPONSABLE_FOOT` groupe les membres du bureau du foot sans en titrer aucun. Le document
 * annonçait donc quatre responsables de section là où le club n'en a qu'un.
 */
final class DetenteurFonctionTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private DetenteurFonctionResolver $resolver;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->resolver = self::getContainer()->get(DetenteurFonctionResolver::class);
    }

    public function testLaFonctionDeclareeFaitFoi(): void
    {
        $dirigeant = $this->makeDirigeant('WILK', 'Anthony', DirigeantRole::RESPONSABLE_FOOT);
        $dirigeant->addFonction($this->makeFonction('Responsable de la section foot'));
        $this->em->flush();

        self::assertSame(
            'Responsable de la section foot',
            $this->resolver->pour($this->makeDetenteur('WILK', 'Anthony'), $dirigeant),
        );
    }

    /** Le cas qui a motivé la séparation : deux fonctions pour une personne, une seule ligne. */
    public function testPlusieursFonctionsSeLisentSurUneLigne(): void
    {
        $dirigeant = $this->makeDirigeant('LAGRANGE', 'Marlène', DirigeantRole::RESPONSABLE_FOOT);
        $dirigeant->addFonction($this->makeFonction('En charge de l\'école de foot', position: 1));
        $dirigeant->addFonction($this->makeFonction('Contribue à l\'organisation du foot', position: 2));
        $this->em->flush();

        self::assertSame(
            'En charge de l\'école de foot · Contribue à l\'organisation du foot',
            $this->resolver->pour($this->makeDetenteur('LAGRANGE', 'Marlène'), $dirigeant),
        );
    }

    /** L'équipe n'est pas recopiée dans le libellé : elle est reprise de la fiche à l'affichage. */
    public function testUneFonctionMarqueeReprendLEquipeDeLaFiche(): void
    {
        $season = $this->makeSeason();
        $dirigeant = $this->makeDirigeant('GRIFFON', 'Charley', DirigeantRole::RESPONSABLE_EQUIPE, $season);
        $dirigeant->setTeam($this->makeTeam($season, 'U16'));
        $dirigeant->addFonction($this->makeFonction('Entraîneur', porteEquipe: true));
        $this->em->flush();

        self::assertSame(
            'Entraîneur U16',
            $this->resolver->pour($this->makeDetenteur('GRIFFON', 'Charley'), $dirigeant),
        );
    }

    public function testUneFonctionMarqueeSansEquipeResteLisible(): void
    {
        $dirigeant = $this->makeDirigeant('SANS', 'Equipe', DirigeantRole::DIRIGEANT);
        $dirigeant->addFonction($this->makeFonction('Entraîneur', porteEquipe: true));
        $this->em->flush();

        self::assertSame('Entraîneur', $this->resolver->pour($this->makeDetenteur('SANS', 'Equipe'), $dirigeant));
    }

    /**
     * Le cœur du problème posé par la mairie : sans fonction déclarée, le repli doit décrire
     * l'appartenance au bureau, jamais reprendre le libellé d'écran du rôle.
     */
    public function testLeRepliNeTitrePersonneResponsableDeLaSection(): void
    {
        $dirigeant = $this->makeDirigeant('FLEURIET', 'Alexandre', DirigeantRole::RESPONSABLE_FOOT);

        $fonction = $this->resolver->pour($this->makeDetenteur('FLEURIET', 'Alexandre'), $dirigeant);

        self::assertSame('Membre du bureau du foot', $fonction);
        self::assertStringNotContainsStringIgnoringCase('responsable', $fonction);
    }

    public function testLeRepliDUnResponsableDEquipeNommeSonEquipe(): void
    {
        $season = $this->makeSeason();
        $dirigeant = $this->makeDirigeant('MURON', 'Damien', DirigeantRole::RESPONSABLE_EQUIPE, $season);
        $dirigeant->setTeam($this->makeTeam($season, 'Séniors'));
        $this->em->flush();

        self::assertSame(
            'Responsable de l\'équipe Séniors',
            $this->resolver->pour($this->makeDetenteur('MURON', 'Damien'), $dirigeant),
        );
    }

    /** Une clé sort du club sans que personne soit à l'effectif : c'est à ça que sert la qualité. */
    public function testLaQualiteRepondPourQuiNEstPasALEffectif(): void
    {
        $detenteur = $this->makeDetenteur('MAIRIE', 'Service', 'Mairie de Soudron');

        self::assertSame('Mairie de Soudron', $this->resolver->pour($detenteur, null));
    }

    /** Une case blanche sur un document officiel se lit comme un oubli. */
    public function testUnDetenteurSansRienNeLaissePasLaCaseVide(): void
    {
        self::assertNotSame('', $this->resolver->pour($this->makeDetenteur('INCONNU', 'Jean'), null));
    }

    /** Le livrable : la fonction arrive bien sur la ligne du récapitulatif remis à la mairie. */
    public function testLeRecapitulatifMairiePorteLaFonction(): void
    {
        $season = $this->makeSeason();
        $dirigeant = $this->makeDirigeant('MARCOUX', 'Corentin', DirigeantRole::RESPONSABLE_FOOT, $season);
        $dirigeant->addFonction($this->makeFonction('Coordinateur général'));

        $detenteur = $this->makeDetenteur('MARCOUX', 'Corentin');
        $this->remise($detenteur, 2);

        $lignes = self::getContainer()->get(AttestationCleRecapService::class)->buildRows($season);

        self::assertCount(1, $lignes);
        self::assertSame('Coordinateur général', $lignes[0]->fonction);
        self::assertSame(2, $lignes[0]->nbCles);
    }

    /* ── Fabriques ── */

    private function makeSeason(): Season
    {
        $season = (new Season())->setLabel('2026-2027')->setCotisationDefaut(85);
        $this->em->persist($season);
        $this->em->flush();

        return $season;
    }

    private function makeTeam(Season $season, string $nom): Team
    {
        $team = (new Team())->setName($nom)->setSeason($season);
        $this->em->persist($team);
        $this->em->flush();

        return $team;
    }

    private function makeFonction(string $libelle, bool $porteEquipe = false, int $position = 0): Fonction
    {
        $fonction = (new Fonction())
            ->setLibelle($libelle)
            ->setPorteEquipe($porteEquipe)
            ->setPosition($position);

        $this->em->persist($fonction);
        $this->em->flush();

        return $fonction;
    }

    private function makeDirigeant(string $nom, string $prenom, DirigeantRole $role, ?Season $season = null): Dirigeant
    {
        $dirigeant = (new Dirigeant())
            ->setNom($nom)
            ->setPrenom($prenom)
            ->setRole($role)
            ->setSeason($season ?? $this->makeSeason());

        $this->em->persist($dirigeant);
        $this->em->flush();

        return $dirigeant;
    }

    private function makeDetenteur(string $nom, string $prenom, ?string $qualite = null): Detenteur
    {
        $detenteur = (new Detenteur())
            ->setNom($nom)
            ->setPrenom($prenom)
            ->setQualite($qualite);

        $this->em->persist($detenteur);
        $this->em->flush();

        return $detenteur;
    }

    private function remise(Detenteur $detenteur, int $quantite): void
    {
        $mouvement = (new CleMouvement())
            ->setDetenteur($detenteur)
            ->setType(CleMouvementType::REMISE)
            ->setQuantite($quantite)
            ->setDateMouvement(new \DateTimeImmutable('2026-08-15'));

        $this->em->persist($mouvement);
        $this->em->flush();
    }
}
