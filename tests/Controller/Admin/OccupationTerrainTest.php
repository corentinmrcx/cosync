<?php declare(strict_types=1);

namespace App\Tests\Controller\Admin;

use App\Entity\CreneauOccupation;
use App\Entity\EspaceTerrain;
use App\Entity\RoleAcces;
use App\Entity\Season;
use App\Entity\Team;
use App\Entity\User;
use App\Enum\JourSemaine;
use App\Enum\Permission;
use App\Enum\UsageOccupation;
use App\Repository\CreneauOccupationRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * La grille d'occupation des terrains, remise à la mairie.
 *
 * Ce que ces tests tiennent : la grille est **une semaine type**, sans dates ; un créneau
 * appartient à une saison mais le terrain n'en dépend pas ; et le document se génère depuis
 * la même donnée que l'écran.
 */
final class OccupationTerrainTest extends WebTestCase
{
    private const GRILLE = '/admin/outils/occupation-terrains';

    private EntityManagerInterface $em;
    private KernelBrowser $client;
    private Season $season;
    private Team $equipe;
    private EspaceTerrain $terrain;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);

        $this->season = (new Season())->setLabel('2026-2027')->setCotisationDefaut(85);
        $this->em->persist($this->season);

        $this->equipe = (new Team())->setName('U15')->setSeason($this->season);
        $this->em->persist($this->equipe);

        $this->terrain = (new EspaceTerrain())->setNom('Terrain d\'honneur')->setOrdre(1);
        $this->em->persist($this->terrain);

        $this->em->flush();
    }

    public function testLaGrilleSAfficheAvecSesSeptJours(): void
    {
        $this->connecter([Permission::PLANNING_LIRE]);

        $html = $this->client->request('GET', '/admin/outils/occupation-terrains')->html();

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Occupation des terrains', $html);
        self::assertStringContainsString('Mercredi', $html);
        self::assertStringContainsString('Dimanche', $html);
    }

    public function testUnCreneauSeposeEtSAfficheDansSaColonne(): void
    {
        $this->connecter([Permission::PLANNING_GERER]);

        $this->poster(self::GRILLE . '/nouveau', $this->jetonCreneau(), [
            'jour' => 'mercredi',
            'heureDebut' => '14:00',
            'heureFin' => '15:30',
            'equipe' => (string) $this->equipe->getId(),
            'espace' => (string) $this->terrain->getId(),
            'usage' => 'entrainement',
        ]);

        self::assertResponseRedirects('/admin/outils/occupation-terrains');

        $creneaux = $this->repository()->findParSaison($this->season);
        self::assertCount(1, $creneaux);
        self::assertSame(JourSemaine::MERCREDI, $creneaux[0]->getJour());
        self::assertSame('14:00', $creneaux[0]->getHeureDebut());

        $html = $this->client->request('GET', '/admin/outils/occupation-terrains')->html();
        self::assertStringContainsString('14h00 – 15h30', $html);
    }

    /**
     * Le pas de la grille est le quart d'heure : une minute intermédiaire est arrondie
     * plutôt que refusée — la grille ne sait pas la dessiner, et faire ressaisir tout le
     * créneau pour une minute que personne ne lira sur le document n'apprendrait rien.
     */
    public function testUnHoraireHorsQuartDHeureEstAccrocheAuPlusProche(): void
    {
        $this->connecter([Permission::PLANNING_GERER]);

        $this->poster(self::GRILLE . '/nouveau', $this->jetonCreneau(), [
            'jour' => 'lundi',
            'heureDebut' => '18:07',
            'heureFin' => '19:38',
            'equipe' => (string) $this->equipe->getId(),
            'espace' => (string) $this->terrain->getId(),
            'usage' => 'entrainement',
        ]);

        $creneau = $this->repository()->findParSaison($this->season)[0];

        self::assertSame('18:00', $creneau->getHeureDebut());
        self::assertSame('19:45', $creneau->getHeureFin());
    }

    public function testUnCreneauQuiSeTermineAvantDAvoirCommenceEstRefuse(): void
    {
        $this->connecter([Permission::PLANNING_GERER]);

        $this->poster(self::GRILLE . '/nouveau', $this->jetonCreneau(), [
            'jour' => 'lundi',
            'heureDebut' => '19:00',
            'heureFin' => '18:00',
            'equipe' => (string) $this->equipe->getId(),
            'espace' => (string) $this->terrain->getId(),
            'usage' => 'entrainement',
        ]);

        self::assertCount(0, $this->repository()->findParSaison($this->season));
    }

    /**
     * Deux catégories sur les deux moitiés du terrain d'honneur : le chevauchement est un
     * cas courant, jamais un refus. Le bloquer fermerait un usage réel du complexe.
     */
    public function testDeuxCreneauxAuMemeHoraireSontAcceptes(): void
    {
        $this->connecter([Permission::PLANNING_GERER]);
        $autre = (new Team())->setName('U13')->setSeason($this->season);
        $this->em->persist($autre);
        $this->em->flush();

        foreach ([$this->equipe, $autre] as $equipe) {
            $this->poster(self::GRILLE . '/nouveau', $this->jetonCreneau(), [
                'jour' => 'mercredi',
                'heureDebut' => '14:00',
                'heureFin' => '15:30',
                'equipe' => (string) $equipe->getId(),
                'espace' => (string) $this->terrain->getId(),
                'usage' => 'entrainement',
            ]);
        }

        self::assertCount(2, $this->repository()->findParSaison($this->season));
    }

    /** Une équipe appartient à une saison : en désigner une d'ailleurs nommerait, sur le document, une catégorie qui ne joue plus. */
    public function testUneEquipeDUneAutreSaisonEstRefusee(): void
    {
        $this->connecter([Permission::PLANNING_GERER]);

        $ancienne = (new Season())->setLabel('2025-2026')->setCotisationDefaut(80);
        $this->em->persist($ancienne);
        $equipeAncienne = (new Team())->setName('U15')->setSeason($ancienne);
        $this->em->persist($equipeAncienne);
        $this->em->flush();

        $this->poster(self::GRILLE . '/nouveau', $this->jetonCreneau(), [
            'jour' => 'lundi',
            'heureDebut' => '18:00',
            'heureFin' => '19:30',
            'equipe' => (string) $equipeAncienne->getId(),
            'espace' => (string) $this->terrain->getId(),
            'usage' => 'entrainement',
        ]);

        self::assertCount(0, $this->repository()->findParSaison($this->season));
    }

    public function testUnCreneauSeCorrigeEtSeSupprime(): void
    {
        $this->connecter([Permission::PLANNING_GERER]);
        $creneau = $this->creneau(JourSemaine::VENDREDI, '19:00', '20:30');

        $this->poster(self::GRILLE . '/' . $creneau->getId() . '/modifier', $this->jetonCreneau(), [
            'jour' => 'jeudi',
            'heureDebut' => '19:15',
            'heureFin' => '20:45',
            'equipe' => (string) $this->equipe->getId(),
            'espace' => (string) $this->terrain->getId(),
            'usage' => 'match',
        ]);

        $this->em->clear();
        $modifie = $this->repository()->findParSaison($this->season)[0];
        self::assertSame(JourSemaine::JEUDI, $modifie->getJour());
        self::assertSame(UsageOccupation::MATCH, $modifie->getUsage());

        $this->poster(
            self::GRILLE . '/' . $modifie->getId() . '/supprimer',
            $this->jeton(self::GRILLE, '.occupation-bloc[data-id="' . $modifie->getId() . '"]', 'data-token'),
            [],
        );

        self::assertCount(0, $this->repository()->findParSaison($this->season));
    }

    /**
     * Le geste de début de saison. Les équipes appartenant à une saison, elles se
     * retrouvent par leur nom ; celle qui n'a pas d'équivalent laisse le créneau à
     * compléter plutôt que d'être remplacée au jugé.
     */
    public function testLaGrilleSeReprendDUneSaisonSurLAutre(): void
    {
        $ancienne = (new Season())->setLabel('2025-2026')->setCotisationDefaut(80);
        $this->em->persist($ancienne);

        $u15 = (new Team())->setName('U15')->setSeason($ancienne);
        $disparue = (new Team())->setName('U18')->setSeason($ancienne);
        $this->em->persist($u15);
        $this->em->persist($disparue);
        $this->em->flush();

        foreach ([[$u15, JourSemaine::MARDI], [$disparue, JourSemaine::JEUDI]] as [$equipe, $jour]) {
            $this->em->persist(
                (new CreneauOccupation())
                    ->setSeason($ancienne)
                    ->setJour($jour)
                    ->setHeureDebut('18:00')
                    ->setHeureFin('19:30')
                    ->setEquipe($equipe)
                    ->setEspace($this->terrain)
                    ->setUsage(UsageOccupation::ENTRAINEMENT),
            );
        }

        $this->em->flush();

        $this->connecter([Permission::PLANNING_GERER]);
        $this->poster(self::GRILLE . '/reprendre', $this->jetonReprise(), []);

        $reprises = $this->repository()->findParSaison($this->season);
        self::assertCount(2, $reprises);

        $equipes = array_map(static fn (CreneauOccupation $c): ?string => $c->getEquipe()?->getName(), $reprises);
        self::assertContains('U15', $equipes, 'L\'équipe de même nom est retrouvée dans la nouvelle saison.');
        self::assertContains(null, $equipes, 'Celle qui n\'existe plus laisse le créneau à compléter.');
    }

    /** Recopier par-dessus une grille déjà composée la doublerait en silence. */
    public function testLaRepriseRefuseDEcrirePardessusUneGrilleExistante(): void
    {
        $ancienne = (new Season())->setLabel('2025-2026')->setCotisationDefaut(80);
        $this->em->persist($ancienne);
        $this->em->flush();

        $this->connecter([Permission::PLANNING_GERER]);

        // Le bandeau de reprise ne s'affiche que sur une grille vide : on lit son jeton
        // avant de poser le créneau, puis on tente la reprise par-dessus.
        $jeton = $this->jetonReprise();
        $this->creneau(JourSemaine::LUNDI, '18:00', '19:30');

        $this->poster(self::GRILLE . '/reprendre', $jeton, []);

        self::assertCount(1, $this->repository()->findParSaison($this->season));
    }

    /* ── Référentiel des terrains ── */

    public function testUnTerrainReserveNeSeSupprimePasMaisSeRetireDuService(): void
    {
        $this->connecter([Permission::PLANNING_GERER]);
        $this->creneau(JourSemaine::LUNDI, '18:00', '19:30');

        $jeton = $this->jetonTerrain($this->terrain->getId());
        $this->poster(self::GRILLE . '/terrains/' . $this->terrain->getId() . '/supprimer', $jeton, []);

        $this->em->clear();
        self::assertNotNull($this->em->getRepository(EspaceTerrain::class)->find($this->terrain->getId()));

        $this->poster(self::GRILLE . '/terrains/' . $this->terrain->getId() . '/service', $jeton, []);

        $this->em->clear();
        $terrain = $this->em->getRepository(EspaceTerrain::class)->find($this->terrain->getId());
        self::assertFalse($terrain->isActif(), 'Le retrait du service est la sortie prévue.');
    }

    /** Le nom se lit dans la ligne et se corrige depuis le menu « ⋯ », pas dans un champ posé là. */
    public function testUnTerrainSeRenommeDepuisSonMenu(): void
    {
        $this->connecter([Permission::PLANNING_GERER]);

        $this->poster(
            self::GRILLE . '/terrains/' . $this->terrain->getId() . '/renommer',
            $this->jeton(self::GRILLE . '/terrains', '[data-id="' . $this->terrain->getId() . '"]', 'data-token'),
            ['nom' => 'Terrain d\'honneur — moitié 1'],
        );

        $this->em->clear();
        $terrain = $this->em->getRepository(EspaceTerrain::class)->find($this->terrain->getId());

        self::assertSame('Terrain d\'honneur — moitié 1', $terrain->getNom());
    }

    public function testUnTerrainEnDoubleEstRefuse(): void
    {
        $this->connecter([Permission::PLANNING_GERER]);

        $this->poster(
            self::GRILLE . '/terrains/nouveau',
            $this->jeton(self::GRILLE . '/terrains', '#terrain-form-nouveau input[name="_token"]'),
            ['nom' => 'Terrain d\'honneur'],
        );

        self::assertCount(1, $this->em->getRepository(EspaceTerrain::class)->findAll());
    }

    /* ── Document ── */

    public function testLeDocumentSortEnPdf(): void
    {
        $this->connecter([Permission::PLANNING_GERER]);
        $this->creneau(JourSemaine::MERCREDI, '14:00', '15:30');

        $this->poster(
            self::GRILLE . '/generer',
            $this->jeton(self::GRILLE . '/generer', 'form[action$="/generer"] input[name="_token"]'),
            [],
        );

        $reponse = $this->client->getResponse();
        self::assertResponseIsSuccessful();
        self::assertSame('application/pdf', $reponse->headers->get('Content-Type'));
        self::assertStringContainsString('occupation_terrains_2026-2027.pdf', (string) $reponse->headers->get('Content-Disposition'));
        self::assertStringStartsWith('%PDF', $reponse->getContent());
    }

    /** Un document vide se génère quand même : l'écran l'annonce, il ne le refuse pas. */
    public function testLeDocumentSeGenereMemeSansCreneau(): void
    {
        $this->connecter([Permission::PLANNING_GERER]);

        $html = $this->client->request('GET', '/admin/outils/occupation-terrains/generer')->html();

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('le document sortirait vide', $html);
    }

    /* ── Droits ── */

    public function testLaLectureSeuleNOuvrePasLEcriture(): void
    {
        $this->connecter([Permission::PLANNING_LIRE]);

        $this->client->request('GET', '/admin/outils/occupation-terrains');
        self::assertResponseIsSuccessful();

        $this->client->request('GET', '/admin/outils/occupation-terrains/generer');
        self::assertResponseStatusCodeSame(403);

        $this->client->request('POST', '/admin/outils/occupation-terrains/nouveau');
        self::assertResponseStatusCodeSame(403);
    }

    public function testUnCompteSansDroitDePlanningNEntrePas(): void
    {
        $this->connecter([Permission::STOCK_LIRE]);

        $this->client->request('GET', '/admin/outils/occupation-terrains');
        self::assertResponseStatusCodeSame(403);
    }

    /* ── Utilitaires ── */

    private function creneau(JourSemaine $jour, string $debut, string $fin): CreneauOccupation
    {
        $creneau = (new CreneauOccupation())
            ->setSeason($this->season)
            ->setJour($jour)
            ->setHeureDebut($debut)
            ->setHeureFin($fin)
            ->setEquipe($this->equipe)
            ->setEspace($this->terrain)
            ->setUsage(UsageOccupation::ENTRAINEMENT);

        $this->em->persist($creneau);
        $this->em->flush();

        return $creneau;
    }

    /**
     * Le jeton CSRF se lit **dans la page rendue** et non auprès du gestionnaire de jetons :
     * hors requête HTTP, il n'y a pas de session dont le tirer.
     */
    private function jeton(string $url, string $selecteur, string $attribut = 'value'): string
    {
        $champ = $this->client->request('GET', $url)->filter($selecteur);

        self::assertGreaterThan(0, $champ->count(), 'Jeton introuvable : ' . $selecteur);

        return (string) $champ->first()->attr($attribut);
    }

    /** Le jeton du formulaire de la modale, qui sert à la création comme à la correction. */
    private function jetonCreneau(): string
    {
        return $this->jeton(self::GRILLE, '#occupation-form input[name="_token"]');
    }

    private function jetonReprise(): string
    {
        return $this->jeton(self::GRILLE, 'form[action$="/reprendre"] input[name="_token"]');
    }

    private function jetonTerrain(int $id): string
    {
        return $this->jeton(self::GRILLE . '/terrains', 'form[action$="/terrains/' . $id . '/service"] input[name="_token"]');
    }

    /** @param array<string, string> $champs */
    private function poster(string $url, string $jeton, array $champs): void
    {
        $this->client->request('POST', $url, $champs + ['_token' => $jeton]);
    }

    private function repository(): CreneauOccupationRepository
    {
        return self::getContainer()->get(CreneauOccupationRepository::class);
    }

    /** @param list<Permission> $permissions */
    private function connecter(array $permissions): void
    {
        static $n = 0;
        ++$n;

        $role = (new RoleAcces())
            ->setNom(sprintf('Rôle occupation %d', $n))
            ->setPermissions($permissions);
        $this->em->persist($role);

        $user = (new User())->setEmail(sprintf('occupation%d@example.test', $n));
        $user->setPassword('x');
        $user->setSelectedSeason($this->season);
        $user->ajouterRoleAcces($role);

        $this->em->persist($user);
        $this->em->flush();

        $this->client->loginUser($user);
    }
}
