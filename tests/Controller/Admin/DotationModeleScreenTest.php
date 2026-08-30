<?php declare(strict_types=1);

namespace App\Tests\Controller\Admin;

use App\Entity\Category;
use App\Entity\DossierClub;
use App\Entity\DotationAffectation;
use App\Entity\DotationModele;
use App\Entity\DotationModeleLigne;
use App\Entity\Licencie;
use App\Entity\Season;
use App\Entity\StockItem;
use App\Entity\Team;
use App\Entity\User;
use App\Enum\DotationEligibilite;
use App\Enum\NatureLicence;
use App\Enum\StockItemVetementType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * L'écran de composition d'un kit : ce qu'il affiche, et ses deux mutations propres
 * (réglages de toutes les options d'un choix en un envoi, renommage d'un choix).
 */
final class DotationModeleScreenTest extends WebTestCase
{
    private EntityManagerInterface $em;
    private Season $season;

    /** Kit « Veste ou T-shirt », veste réservée aux nouveaux, t-shirt floqué. */
    private function makeKit(): DotationModele
    {
        $veste = $this->makeItem('Veste de survêtement');
        $tshirt = $this->makeItem('T-shirt');

        $modele = (new DotationModele())->setSeason($this->season)->setNom('Kit sénior');
        $this->em->persist($modele);

        $this->addLigne($modele, $veste, 'Votre dotation', DotationEligibilite::NOUVEAUX);
        $ligneTshirt = $this->addLigne($modele, $tshirt, 'Votre dotation', DotationEligibilite::TOUS);
        $ligneTshirt->setPersonnalisationRequise(true)->setPersonnalisationLabel('Nom à floquer au dos');

        $this->em->flush();

        return $modele;
    }

    public function testLEcranAfficheLesChoixEtLApercuParProfil(): void
    {
        $client = static::createClient();
        $this->loginAdmin($client);
        $modele = $this->makeKit();

        $crawler = $client->request('GET', '/admin/dotations/' . $modele->getId() . '/modifier');
        $html = $crawler->html();

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Nom du choix (usage interne)', $html, 'Le nom du choix est un repère interne, pas la question posée au licencié.');
        self::assertStringContainsString('Ce que recevra chaque profil', $html);

        // La veste étant réservée aux nouveaux, elle reste seule éligible pour eux : plus de
        // question posée, l'article est imposé. C'est la règle métier que l'aperçu doit montrer.
        self::assertStringContainsString('Nouveau licencié', $html);
        self::assertStringContainsString('au titre de « Votre dotation »', $html);

        // Un kit sans cible ne dote personne : l'écran doit le dire.
        self::assertStringContainsString('n\'est attribué à personne', $html);
    }

    /**
     * Le club crée un article par déclinaison : plusieurs portent le même nom et ne se
     * distinguent que par la marque et la couleur. Un sélecteur qui ne montre que le nom
     * rend le choix impossible — la désignation doit porter les trois.
     */
    public function testLesArticlesHomonymesSontDistinguesParMarqueEtCouleur(): void
    {
        $client = static::createClient();
        $this->loginAdmin($client);
        $modele = $this->makeKit();

        $rouge = $this->makeItem('Short');
        $rouge->setMarque('Nike')->setCouleur('Rouge');
        $noir = $this->makeItem('Short');
        $noir->setMarque('Adidas')->setCouleur('Noir');
        $this->em->flush();

        $crawler = $client->request('GET', '/admin/dotations/' . $modele->getId() . '/modifier');
        $html = $crawler->html();

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Short · Nike · Rouge', $html);
        self::assertStringContainsString('Short · Adidas · Noir', $html);

        // Un article sans marque ni couleur garde son nom seul, sans séparateur orphelin.
        self::assertStringContainsString('T-shirt<', $html);
    }

    /**
     * Composer un kit et dire qui le reçoit est la même décision : l'attribution se fait sur la
     * page du kit, et l'aperçu s'y adapte immédiatement.
     */
    public function testAttribuerLeKitDepuisSaPageRestreintLesProfilsDeLApercu(): void
    {
        $client = static::createClient();
        $this->loginAdmin($client);
        $modele = $this->makeKit();

        $team = (new Team())->setName('Séniors 1')->setSeason($this->season);
        $this->em->persist($team);
        $this->em->flush();

        // Sans cible, on ne peut rien exclure : les trois profils sont annoncés.
        $html = $client->request('GET', '/admin/dotations/' . $modele->getId() . '/modifier')->html();
        self::assertStringContainsString('Dirigeant', $html);

        $crawler = $client->request('GET', '/admin/dotations/' . $modele->getId() . '/modifier');
        $token = $crawler->filter('form[action$="/affectations"] input[name="_token"]')->attr('value');

        $client->request('POST', '/admin/dotations/' . $modele->getId() . '/affectations', [
            '_token' => $token,
            'cible_type' => 'team',
            'cible_ids' => [(string) $team->getId()],
        ]);

        // On revient sur la page du kit, pas sur l'index.
        self::assertResponseRedirects('/admin/dotations/' . $modele->getId() . '/modifier');

        $html = $client->followRedirect()->html();
        self::assertStringContainsString('Équipe — Séniors 1', $html);
        self::assertStringContainsString('Nouveau licencié', $html);
        self::assertStringNotContainsString(
            'Ne remplit pas de formulaire d\'inscription',
            $html,
            'Un kit attribué à une équipe de joueurs n\'annonce pas ce qu\'un dirigeant recevrait.',
        );
    }

    /**
     * Le geste que l'écran doit permettre : donner le kit à plusieurs équipes d'un coup,
     * au lieu de refaire l'attribution autant de fois qu'il y a d'équipes.
     */
    public function testPlusieursCiblesSontAttribueesEnUnSeulEnvoi(): void
    {
        $client = static::createClient();
        $this->loginAdmin($client);
        $modele = $this->makeKit();

        $u15 = (new Team())->setName('U15 A')->setSeason($this->season);
        $u17 = (new Team())->setName('U17')->setSeason($this->season);
        $seniors = (new Team())->setName('Séniors 1')->setSeason($this->season);
        $this->em->persist($u15);
        $this->em->persist($u17);
        $this->em->persist($seniors);
        $this->em->flush();

        $crawler = $client->request('GET', '/admin/dotations/' . $modele->getId() . '/modifier');
        $token = $crawler->filter('form[action$="/affectations"] input[name="_token"]')->attr('value');

        // Les trois équipes sont proposées à la coche dans la liste déroulante des destinataires.
        $proposees = $crawler->filter('[x-ref="panneau-team"] .cselect-option')->each(
            static fn ($li): string => $li->attr('data-label'),
        );
        self::assertSame(['séniors 1', 'u15 a', 'u17'], $proposees);

        $client->request('POST', '/admin/dotations/' . $modele->getId() . '/affectations', [
            '_token' => $token,
            'cible_type' => 'team',
            'cible_ids' => [(string) $u15->getId(), (string) $u17->getId(), (string) $seniors->getId()],
        ]);

        self::assertResponseRedirects('/admin/dotations/' . $modele->getId() . '/modifier');
        $crawler = $client->followRedirect();
        $html = $crawler->html();

        foreach (['U15 A', 'U17', 'Séniors 1'] as $nom) {
            self::assertStringContainsString('Équipe — ' . $nom, $html);
        }

        // Ce que le kit dote déjà revient verrouillé dans la liste : plus moyen d'en faire un doublon.
        self::assertCount(
            3,
            $crawler->filter('[x-ref="panneau-team"] .cselect-option-locked'),
            'Les trois équipes attribuées sont verrouillées dans le sélecteur.',
        );
    }

    /** La cible par défaut ne désigne personne : elle ne se pose qu'une fois. */
    public function testLeKitParDefautNeSePoseQuUneFois(): void
    {
        $client = static::createClient();
        $this->loginAdmin($client);
        $modele = $this->makeKit();

        $crawler = $client->request('GET', '/admin/dotations/' . $modele->getId() . '/modifier');
        $token = $crawler->filter('form[action$="/affectations"] input[name="_token"]')->attr('value');

        foreach ([1, 2] as $ignore) {
            $client->request('POST', '/admin/dotations/' . $modele->getId() . '/affectations', [
                '_token' => $token,
                'cible_type' => 'default',
            ]);
            $client->followRedirect();
        }

        $this->em->clear();

        self::assertCount(
            1,
            $this->em->getRepository(DotationAffectation::class)->findBy(['modele' => $modele->getId()]),
            'Le second envoi est refusé plutôt que d\'ajouter un second défaut.',
        );
    }

    /** Deux admins en parallèle ne doivent pas doubler une attribution déjà posée. */
    public function testUneCibleDejaAttribueeNEstPasDoublee(): void
    {
        $client = static::createClient();
        $this->loginAdmin($client);
        $modele = $this->makeKit();

        $team = (new Team())->setName('U13')->setSeason($this->season);
        $this->em->persist($team);
        $this->em->persist((new DotationAffectation())->setSeason($this->season)->setModele($modele)->setTeam($team));
        $this->em->flush();

        $crawler = $client->request('GET', '/admin/dotations/' . $modele->getId() . '/modifier');
        $token = $crawler->filter('form[action$="/affectations"] input[name="_token"]')->attr('value');

        $client->request('POST', '/admin/dotations/' . $modele->getId() . '/affectations', [
            '_token' => $token,
            'cible_type' => 'team',
            'cible_ids' => [(string) $team->getId()],
        ]);

        self::assertResponseRedirects('/admin/dotations/' . $modele->getId() . '/modifier');
        $this->em->clear();

        self::assertCount(
            1,
            $this->em->getRepository(DotationAffectation::class)->findBy(['modele' => $modele->getId()]),
            'La cible déjà dotée est ignorée, pas enregistrée deux fois.',
        );
    }

    public function testUnSeulEnvoiEnregistreLesReglagesDeToutesLesOptions(): void
    {
        $client = static::createClient();
        $this->loginAdmin($client);
        $modele = $this->makeKit();

        [$veste, $tshirt] = $modele->getLignes()->toArray();

        $crawler = $client->request('GET', '/admin/dotations/' . $modele->getId() . '/modifier');
        $token = $crawler->filter('form[action$="/choix/reglages"] input[name="_token"]')->attr('value');

        $client->request('POST', '/admin/dotations/' . $modele->getId() . '/choix/reglages', [
            '_token' => $token,
            'nom' => 'Votre dotation',
            'reglages' => [
                (string) $veste->getId() => [
                    '_present' => '1',
                    'eligibilite' => DotationEligibilite::TOUS->value,
                ],
                (string) $tshirt->getId() => [
                    '_present' => '1',
                    'eligibilite' => DotationEligibilite::TOUS->value,
                    'personnalisation_requise' => '1',
                    'personnalisation_label' => 'Surnom au dos',
                    'personnalisation_max' => '12',
                ],
            ],
        ]);

        self::assertResponseRedirects();
        $this->em->clear();
        $lignes = $this->em->find(DotationModele::class, $modele->getId())->getLignes();

        self::assertSame(DotationEligibilite::TOUS, $lignes[0]->getEligibilite(), 'La veste s\'ouvre à tous.');
        self::assertSame('Surnom au dos', $lignes[1]->getPersonnalisationLabel());
        self::assertSame(12, $lignes[1]->getPersonnalisationMaxLength());
    }

    /** Décocher « texte à personnaliser » ne poste rien : le marqueur _present doit le rattraper. */
    public function testDecocherLaPersonnalisationEstBienEnregistre(): void
    {
        $client = static::createClient();
        $this->loginAdmin($client);
        $modele = $this->makeKit();

        [$veste, $tshirt] = $modele->getLignes()->toArray();

        $crawler = $client->request('GET', '/admin/dotations/' . $modele->getId() . '/modifier');
        $token = $crawler->filter('form[action$="/choix/reglages"] input[name="_token"]')->attr('value');

        $client->request('POST', '/admin/dotations/' . $modele->getId() . '/choix/reglages', [
            '_token' => $token,
            'nom' => 'Votre dotation',
            'reglages' => [
                (string) $veste->getId() => ['_present' => '1', 'eligibilite' => DotationEligibilite::NOUVEAUX->value],
                (string) $tshirt->getId() => ['_present' => '1', 'eligibilite' => DotationEligibilite::TOUS->value],
            ],
        ]);

        $this->em->clear();
        $tshirt = $this->em->find(DotationModeleLigne::class, $tshirt->getId());

        self::assertFalse($tshirt->isPersonnalisationRequise());
        self::assertNull($tshirt->getPersonnalisationLabel(), 'Les réglages orphelins sont effacés.');
    }

    public function testRenommerUnChoixEmmeneLesReponsesDejaSaisies(): void
    {
        $client = static::createClient();
        $this->loginAdmin($client);
        $modele = $this->makeKit();

        $category = (new Category())->setCode('SENIOR')->setLabel('Séniors')->setIsEcoleFoot(false);
        $this->em->persist($category);
        $this->em->persist((new DotationAffectation())->setSeason($this->season)->setModele($modele)->setCategory($category));

        $licencie = (new Licencie())
            ->setNom('DUPONT')->setPrenom('Thomas')
            ->setDateNaissance(new \DateTimeImmutable('2000-01-01'))
            ->setCategory($category)->setSeason($this->season)
            ->setNatureLicence(NatureLicence::RENOUVELLEMENT);
        $this->em->persist($licencie);

        $tshirt = $modele->getLignes()[1]->getStockItem();
        $dossier = (new DossierClub())->setLicencie($licencie);
        $dossier->setDotationChoix(['Votre dotation' => $tshirt->getId()]);
        $dossier->setDotationPersonnalisation(['Votre dotation' => 'Coco']);
        $this->em->persist($dossier);
        $this->em->flush();

        $crawler = $client->request('GET', '/admin/dotations/' . $modele->getId() . '/modifier');
        $token = $crawler->filter('form[action$="/choix/renommer"] input[name="_token"]')->attr('value');

        $client->request('POST', '/admin/dotations/' . $modele->getId() . '/choix/renommer', [
            '_token' => $token,
            'ancien' => 'Votre dotation',
            'nouveau' => 'Haut au choix',
        ]);

        self::assertResponseRedirects();
        $this->em->clear();

        $dossier = $this->em->getRepository(DossierClub::class)->findOneBy(['licencie' => $licencie->getUuid()]);
        self::assertSame(['Haut au choix' => $tshirt->getId()], $dossier->getDotationChoix(), 'Le choix suit le renommage.');
        self::assertSame(['Haut au choix' => 'Coco'], $dossier->getDotationPersonnalisation(), 'Le texte de flocage aussi.');

        $groupes = array_map(
            static fn (DotationModeleLigne $l): ?string => $l->getGroupeChoix(),
            $this->em->find(DotationModele::class, $modele->getId())->getLignes()->toArray(),
        );
        self::assertSame(['Haut au choix', 'Haut au choix'], $groupes);
    }

    public function testRenommerVersUnNomDejaPrisEstRefuse(): void
    {
        $client = static::createClient();
        $this->loginAdmin($client);
        $modele = $this->makeKit();

        $this->addLigne($modele, $this->makeItem('Short'), 'Bas au choix', DotationEligibilite::TOUS);
        $this->addLigne($modele, $this->makeItem('Jogging'), 'Bas au choix', DotationEligibilite::TOUS);
        $this->em->flush();

        $crawler = $client->request('GET', '/admin/dotations/' . $modele->getId() . '/modifier');
        $token = $crawler->filter('form[action$="/choix/renommer"] input[name="_token"]')->attr('value');

        $client->request('POST', '/admin/dotations/' . $modele->getId() . '/choix/renommer', [
            '_token' => $token,
            'ancien' => 'Votre dotation',
            'nouveau' => 'Bas au choix',
        ]);

        $this->em->clear();
        $groupes = array_unique(array_map(
            static fn (DotationModeleLigne $l): ?string => $l->getGroupeChoix(),
            $this->em->find(DotationModele::class, $modele->getId())->getLignes()->toArray(),
        ));
        sort($groupes);

        self::assertSame(['Bas au choix', 'Votre dotation'], $groupes, 'Les deux choix restent distincts.');
    }

    /* ── Fabriques ── */

    private function loginAdmin(KernelBrowser $client): void
    {
        $this->em = self::getContainer()->get(EntityManagerInterface::class);

        $this->season = (new Season())->setLabel('2025-2026')->setCotisationDefaut(85);
        $user = (new User())->setSuperAdmin(true)->setEmail('admin-dotations@example.com')->setPassword('x');

        $this->em->persist($this->season);
        $this->em->persist($user);
        $this->em->flush();

        $client->loginUser($user);
    }

    private function makeItem(string $nom): StockItem
    {
        $item = (new StockItem())->setNom($nom)->setTypeVetement(StockItemVetementType::HAUT);
        $this->em->persist($item);

        return $item;
    }

    private function addLigne(DotationModele $modele, StockItem $item, ?string $groupe, DotationEligibilite $eligibilite): DotationModeleLigne
    {
        $ligne = (new DotationModeleLigne())
            ->setStockItem($item)
            ->setQuantite(1)
            ->setGroupeChoix($groupe)
            ->setEligibilite($eligibilite);
        $modele->addLigne($ligne);
        $this->em->persist($ligne);

        return $ligne;
    }
}
