<?php declare(strict_types=1);

namespace App\Tests\Controller\Admin;

use App\Entity\AttestationCle;
use App\Entity\CleMouvement;
use App\Entity\Detenteur;
use App\Entity\Dirigeant;
use App\Entity\Fonction;
use App\Entity\Season;
use App\Entity\User;
use App\Enum\CleMouvementType;
use App\Enum\DirigeantRole;
use App\Repository\AttestationCleRepository;
use App\Service\Cle\AttestationCleRecapService;
use App\Tests\Support\EditeurRicheAssertions;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Écrans admin des clés : registre au niveau du club, édition du texte d'attestation,
 * plus les mutations (mouvement de clé, demande de signature, campagne annuelle).
 */
final class ClesScreensTest extends WebTestCase
{
    use EditeurRicheAssertions;

    private ?Season $season = null;

    public function testLesEcransRepondent(): void
    {
        $client = static::createClient();
        $this->loginAdmin($client);

        $urls = ['/admin/cles', '/admin/cles/detenteurs', '/admin/cles/mouvements', '/admin/cles/attestation'];

        foreach ($urls as $url) {
            $client->request('GET', $url);
            self::assertResponseIsSuccessful(sprintf('L\'écran %s doit répondre.', $url));
        }
    }

    public function testLeRegistreAfficheLesDetenteursEtLeursCles(): void
    {
        $client = static::createClient();
        $this->loginAdmin($client);

        $detenteur = $this->makeDetenteur('DUPONT', 'Thomas');
        $this->makeMouvement($detenteur, CleMouvementType::REMISE, 2, '2026-01-10');

        $crawler = $client->request('GET', '/admin/cles/detenteurs');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('DUPONT Thomas', $crawler->html());
        self::assertStringContainsString('10/01/2026', $crawler->html());
        self::assertStringContainsString('Non signée', $crawler->html());
    }

    public function testUnMouvementValideEstEnregistre(): void
    {
        $client = static::createClient();
        $this->loginAdmin($client);

        $detenteur = $this->makeDetenteur();
        $crawler = $client->request('GET', '/admin/cles/detenteurs');
        $token = $crawler->filter('form[action$="cles/mouvement"] input[name="_token"]')->attr('value');

        $client->request('POST', '/admin/cles/mouvement', [
            '_token' => $token,
            'personne' => 'detenteur:' . $detenteur->getId(),
            'type' => 'remise',
            'quantite' => '2',
            'date_mouvement' => '2026-03-15',
        ]);

        self::assertResponseRedirects('/admin/cles/detenteurs');
        self::assertSame(2, $this->soldeDe($detenteur));
    }

    /**
     * Le geste courant : remettre une clé à quelqu'un qui n'est pas encore au
     * registre ne doit pas demander de l'y ajouter d'abord dans un autre écran.
     */
    public function testRemettreUneCleAUnDirigeantLInscritAuRegistre(): void
    {
        $client = static::createClient();
        $this->loginAdmin($client);

        $dirigeant = $this->makeDirigeant('BERNARD', 'Alice');
        $crawler = $client->request('GET', '/admin/cles/detenteurs');
        $token = $crawler->filter('form[action$="cles/mouvement"] input[name="_token"]')->attr('value');

        $client->request('POST', '/admin/cles/mouvement', [
            '_token' => $token,
            'personne' => 'dirigeant:' . $dirigeant->getUuid(),
            'type' => 'remise',
            'quantite' => '1',
            'date_mouvement' => '2026-03-15',
        ]);

        self::assertResponseRedirects('/admin/cles/detenteurs');

        $em = $this->em();
        $em->clear();
        $detenteur = $em->getRepository(Detenteur::class)->findOneBy(['nom' => 'BERNARD', 'prenom' => 'Alice']);

        self::assertNotNull($detenteur, 'Le dirigeant doit être entré au registre au passage.');
        self::assertSame(1, $this->soldeDe($detenteur));
    }

    public function testUneRestitutionImpossibleAfficheUneErreurSansRienEnregistrer(): void
    {
        $client = static::createClient();
        $this->loginAdmin($client);

        $detenteur = $this->makeDetenteur();
        $this->makeMouvement($detenteur, CleMouvementType::REMISE, 1, '2026-01-10');

        $crawler = $client->request('GET', '/admin/cles/detenteurs');
        $token = $crawler->filter('form[action$="cles/mouvement"] input[name="_token"]')->attr('value');

        $client->request('POST', '/admin/cles/mouvement', [
            '_token' => $token,
            'personne' => 'detenteur:' . $detenteur->getId(),
            'type' => 'restitution',
            'quantite' => '5',
            'date_mouvement' => '2026-03-15',
        ]);

        self::assertResponseRedirects('/admin/cles/detenteurs');
        self::assertSame(1, $this->soldeDe($detenteur), 'Le solde ne doit pas bouger.');
    }

    public function testUnJetonCsrfInvalideNEnregistreAucunMouvement(): void
    {
        $client = static::createClient();
        $this->loginAdmin($client);

        $detenteur = $this->makeDetenteur();

        $client->request('POST', '/admin/cles/mouvement', [
            '_token' => 'jeton-bidon',
            'personne' => 'detenteur:' . $detenteur->getId(),
            'type' => 'remise',
            'quantite' => '1',
        ], [], ['HTTP_REFERER' => '/admin/cles/detenteurs']);

        self::assertResponseRedirects('/admin/cles/detenteurs');
        self::assertSame(0, $this->soldeDe($detenteur));
    }

    public function testLaDemandeDeSignatureOuvreUneAttestationPourLaSaison(): void
    {
        $client = static::createClient();
        $this->loginAdmin($client);

        $detenteur = $this->makeDetenteur();
        $this->makeMouvement($detenteur, CleMouvementType::REMISE, 1, '2026-01-10');

        $crawler = $client->request('GET', '/admin/cles/detenteurs');
        $token = $crawler->filter('form[action$="attestation/demander"] input[name="_token"]')->attr('value');

        $client->request('POST', '/admin/cles/detenteurs/' . $detenteur->getId() . '/attestation/demander', [
            '_token' => $token,
        ]);

        self::assertResponseRedirects('/admin/cles/detenteurs');

        $attestation = $this->derniereAttestationDe($detenteur);

        self::assertNotNull($attestation);
        self::assertTrue($attestation->isTokenValid());
        self::assertFalse($attestation->estSignee());
        self::assertNotNull($attestation->getDemandeEnvoyeeLe());
    }

    /** La campagne ne s'adresse qu'aux détenteurs actuels sans engagement à jour. */
    public function testLaCampagneNAdressePasCeuxQuiOntToutRestitue(): void
    {
        $client = static::createClient();
        $this->loginAdmin($client);

        $detenteur = $this->makeDetenteur('DUPONT', 'Thomas');
        $this->makeMouvement($detenteur, CleMouvementType::REMISE, 1, '2026-01-10');

        $ancien = $this->makeDetenteur('MARTIN', 'Kevin');
        $this->makeMouvement($ancien, CleMouvementType::REMISE, 1, '2026-01-10');
        $this->makeMouvement($ancien, CleMouvementType::RESTITUTION, 1, '2026-02-01');

        $crawler = $client->request('GET', '/admin/cles');
        $token = $crawler->filter('form[action$="attestations/campagne"] input[name="_token"]')->attr('value');

        $client->request('POST', '/admin/cles/attestations/campagne', ['_token' => $token]);

        self::assertResponseRedirects('/admin/cles');
        self::assertNotNull($this->derniereAttestationDe($detenteur), 'Le détenteur actuel doit être sollicité.');
        self::assertNull($this->derniereAttestationDe($ancien), 'Qui a tout rendu n\'a rien à signer.');
    }

    public function testUnDetenteurExterieurPeutEtreAjouteAuRegistre(): void
    {
        $client = static::createClient();
        $this->loginAdmin($client);

        $crawler = $client->request('GET', '/admin/cles');
        $token = $crawler->filter('form[action$="detenteurs/exterieur"] input[name="_token"]')->attr('value');

        $client->request('POST', '/admin/cles/detenteurs/exterieur', [
            '_token' => $token,
            'nom' => 'COMMUNE',
            'prenom' => 'Soudron',
            'qualite' => 'Mairie de Soudron',
        ]);

        self::assertResponseRedirects('/admin/cles/detenteurs');

        $em = $this->em();
        $em->clear();
        $detenteur = $em->getRepository(Detenteur::class)->findOneBy(['nom' => 'COMMUNE']);

        self::assertNotNull($detenteur);
        self::assertSame('Mairie de Soudron', $detenteur->getQualite());
    }

    /**
     * La qualité est ce qui s'imprime en face d'un extérieur sur le récapitulatif de la
     * mairie. Sans écran pour la poser après coup, une fiche entrée sans elle restait
     * « Détenteur extérieur au club » définitivement.
     */
    public function testLaQualiteDUnDetenteurExterieurSeCorrigeApresCoup(): void
    {
        $client = static::createClient();
        $this->loginAdmin($client);

        $detenteur = $this->makeDetenteur('COMMUNE', 'Soudron');
        $this->makeMouvement($detenteur, CleMouvementType::REMISE, 1, '2025-09-01');

        $url = '/admin/cles/detenteurs/' . $detenteur->getId() . '/modifier';
        $crawler = $client->request('GET', $url);
        self::assertResponseIsSuccessful();

        $client->request('POST', $url, [
            '_token' => $crawler->filter('form input[name="_token"]')->attr('value'),
            'nom' => 'COMMUNE',
            'prenom' => 'Soudron',
            'qualite' => 'Mairie de Soudron',
            'email' => 'mairie@soudron.test',
            'telephone' => '',
        ]);

        self::assertResponseRedirects('/admin/cles/detenteurs');

        $em = $this->em();
        $em->clear();
        self::assertSame(
            'Mairie de Soudron',
            $em->getRepository(Detenteur::class)->find($detenteur->getId())->getQualite(),
        );

        // Et c'est bien elle qui part sur le document remis à la mairie.
        $lignes = self::getContainer()->get(AttestationCleRecapService::class)->buildRows($this->season);
        self::assertSame('Mairie de Soudron', $lignes[0]->fonction);
    }

    /**
     * Une fiche rattachée à l'effectif tient son identité de sa fiche dirigeant, réécrite à
     * chaque mouvement et réalignée par l'import. La corriger ici la ferait diverger de
     * FootClubs — et comme le rapprochement retombe sur le nom faute de licence, la fiche
     * décrocherait du registre. L'écran ne le propose pas, et le contrôleur le refuse.
     */
    public function testUnDetenteurDeLEffectifNeSeModifiePasDepuisLeRegistre(): void
    {
        $client = static::createClient();
        $this->loginAdmin($client);

        $this->makeDirigeant('MARCOUX', 'Corentin');
        $detenteur = $this->makeDetenteur('MARCOUX', 'Corentin');
        $this->makeMouvement($detenteur, CleMouvementType::REMISE, 1, '2025-09-01');

        $url = '/admin/cles/detenteurs/' . $detenteur->getId() . '/modifier';

        $crawler = $client->request('GET', '/admin/cles/detenteurs');
        self::assertCount(
            0,
            $crawler->filter('a[href="' . $url . '"]'),
            'Le registre ne doit pas offrir un geste que le contrôleur refusera.',
        );

        $client->request('GET', $url);
        self::assertResponseRedirects('/admin/cles/detenteurs');
    }

    /**
     * Même un ex-dirigeant, absent de l'effectif de la saison : il revient au prochain
     * import, et son nom corrigé entre-temps décrocherait sa fiche du registre.
     */
    public function testUnAncienDirigeantHorsEffectifResteProtege(): void
    {
        $client = static::createClient();
        $this->loginAdmin($client);

        // Dirigeant d'une autre saison : hors de l'effectif affiché, mais du club quand même.
        $ancienne = (new Season())->setLabel('2024-2025')->setCotisationDefaut(80);
        $this->em()->persist($ancienne);
        $parti = (new Dirigeant())->setNom('PARTI')->setPrenom('Jean')->setSeason($ancienne);
        $this->em()->persist($parti);
        $this->em()->flush();

        $detenteur = $this->makeDetenteur('PARTI', 'Jean');
        $this->makeMouvement($detenteur, CleMouvementType::REMISE, 1, '2025-09-01');

        $client->request('GET', '/admin/cles/detenteurs/' . $detenteur->getId() . '/modifier');

        self::assertResponseRedirects('/admin/cles/detenteurs');
    }

    /** Deux fiches pour une personne répartiraient ses clés sur deux lignes du registre. */
    public function testRenommerUnDetenteurVersUnHomonymeDuRegistreEstRefuse(): void
    {
        $client = static::createClient();
        $this->loginAdmin($client);

        $this->makeDetenteur('MARTIN', 'Kevin');
        $autre = $this->makeDetenteur('COMMUNE', 'Soudron');

        $url = '/admin/cles/detenteurs/' . $autre->getId() . '/modifier';
        $crawler = $client->request('GET', $url);

        $client->request('POST', $url, [
            '_token' => $crawler->filter('form input[name="_token"]')->attr('value'),
            'nom' => 'MARTIN',
            'prenom' => 'Kevin',
        ]);

        $em = $this->em();
        $em->clear();
        self::assertSame('COMMUNE', $em->getRepository(Detenteur::class)->find($autre->getId())->getNom());
    }

    /**
     * L'écran et le récapitulatif doivent dire la même chose : le rôle d'un dirigeant
     * groupe — quatre personnes partagent « Bureau du foot » — là où la fonction nomme.
     */
    public function testLeRegistreAfficheLaFonctionEtNonLeRoleDuDirigeant(): void
    {
        $client = static::createClient();
        $this->loginAdmin($client);

        $dirigeant = $this->makeDirigeant('MARCOUX', 'Corentin');
        $dirigeant->setRole(DirigeantRole::RESPONSABLE_FOOT);

        $fonction = (new Fonction())->setLibelle('Coordinateur général')->setPorteEquipe(false)->setPosition(0);
        $this->em()->persist($fonction);
        $dirigeant->addFonction($fonction);

        $detenteur = $this->makeDetenteur('MARCOUX', 'Corentin');
        $this->em()->flush();
        $this->makeMouvement($detenteur, CleMouvementType::REMISE, 1, '2025-09-01');

        $crawler = $client->request('GET', '/admin/cles/detenteurs');

        self::assertStringContainsString('Coordinateur général', $crawler->filter('table')->text());
        self::assertStringNotContainsString('Bureau du foot', $crawler->filter('table')->text());
    }

    /**
     * L'historique garde **tout** : une restitution et une perte n'apparaissent nulle part
     * ailleurs, s'en tenir aux derniers en date les ferait disparaître au mouvement suivant.
     */
    public function testLHistoriquePagineEtNOublieAucunMouvement(): void
    {
        $client = static::createClient();
        $this->loginAdmin($client);

        $detenteur = $this->makeDetenteur('ANCIEN', 'Registre');

        // 26 mouvements : une page pleine (25) et un débordement.
        for ($i = 1; $i <= 13; ++$i) {
            $this->makeMouvement($detenteur, CleMouvementType::REMISE, 1, sprintf('2026-01-%02d', $i));
            $this->makeMouvement($detenteur, CleMouvementType::RESTITUTION, 1, sprintf('2026-02-%02d', $i));
        }

        $crawler = $client->request('GET', '/admin/cles/mouvements');

        self::assertResponseIsSuccessful();
        self::assertCount(25, $crawler->filter('tbody tr'), 'La première page est pleine.');
        self::assertStringContainsString('26 mouvements', $crawler->html());

        $suivante = $client->request('GET', '/admin/cles/mouvements?page=2');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $suivante->filter('tbody tr'), 'Le 26e mouvement est sur la page suivante.');
    }

    /** L'aperçu rend le vrai gabarit de l'attestation : c'est ce qui garde le PDF signé testé. */
    public function testLApercuDeLAttestationEstUnPdf(): void
    {
        $client = static::createClient();
        $this->loginAdmin($client);

        $client->request('GET', '/admin/cles/attestation/apercu');

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'application/pdf');
        self::assertStringStartsWith('%PDF', $client->getResponse()->getContent());
    }

    public function testLeRecapitulatifEstUnPdfTelechargeable(): void
    {
        $client = static::createClient();
        $this->loginAdmin($client);

        $client->request('GET', '/admin/cles/attestation/recapitulatif');

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'application/pdf');
        self::assertStringContainsString('attachment', (string) $client->getResponse()->headers->get('Content-Disposition'));
        self::assertStringStartsWith('%PDF', $client->getResponse()->getContent());
    }

    public function testLeTexteDAttestationEstEnregistreSurLaSaisonCourante(): void
    {
        $client = static::createClient();
        $this->loginAdmin($client);

        $crawler = $client->request('GET', '/admin/cles/attestation');
        // Cibler le formulaire d'édition : le premier _token de la page est celui
        // du sélecteur de saison de la navbar.
        $token = $crawler->filter('#attestation-form input[name="_token"]')->attr('value');

        $client->request('POST', '/admin/cles/attestation', [
            '_token' => $token,
            'attestation_cle_text' => '<p>La commune met le local à disposition.</p>',
        ]);

        self::assertResponseRedirects('/admin/cles/attestation');

        $em = $this->em();
        $em->clear();
        $season = $em->find(Season::class, $this->season->getId());

        self::assertStringContainsString('met le local à disposition', (string) $season->getAttestationCleText());
    }

    public function testLEcranDAttestationEmbarqueUnEditeurUtilisable(): void
    {
        $client = static::createClient();
        $this->loginAdmin($client);

        $crawler = $client->request('GET', '/admin/cles/attestation');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('#quill-editor'), 'Le conteneur de l\'éditeur doit être rendu.');
        $this->assertEditeurRicheInitialisable($crawler, 'le texte d\'attestation de clé');
    }

    public function testLesEcransSontInaccessiblesSansAuthentification(): void
    {
        $client = static::createClient();

        $client->request('GET', '/admin/cles');

        self::assertResponseRedirects();
        self::assertStringContainsString('/login', (string) $client->getResponse()->headers->get('Location'));
    }

    /* ── Fabriques ── */

    private function em(): EntityManagerInterface
    {
        return self::getContainer()->get(EntityManagerInterface::class);
    }

    private function loginAdmin(KernelBrowser $client): void
    {
        $em = $this->em();

        $this->season = (new Season())->setLabel('2025-2026')->setCotisationDefaut(85);
        $user = (new User())->setSuperAdmin(true)->setEmail('admin-cles@example.com')->setPassword('x');

        $em->persist($this->season);
        $em->persist($user);
        $em->flush();

        $client->loginUser($user);
    }

    private function makeDetenteur(string $nom = 'MARTIN', string $prenom = 'Kevin'): Detenteur
    {
        static $n = 0;
        ++$n;

        $em = $this->em();

        $detenteur = (new Detenteur())
            ->setNom($nom)
            ->setPrenom($prenom)
            ->setEmail(sprintf('cles%d@example.com', $n));

        $em->persist($detenteur);
        $em->flush();

        return $detenteur;
    }

    private function makeDirigeant(string $nom, string $prenom): Dirigeant
    {
        static $n = 0;
        ++$n;

        $em = $this->em();

        $dirigeant = (new Dirigeant())
            ->setNom($nom)
            ->setPrenom($prenom)
            ->setEmail(sprintf('dirigeant-cles%d@example.com', $n))
            ->setSeason($this->season);

        $em->persist($dirigeant);
        $em->flush();

        return $dirigeant;
    }

    private function makeMouvement(Detenteur $detenteur, CleMouvementType $type, int $quantite, string $date): void
    {
        $em = $this->em();

        $mouvement = (new CleMouvement())
            ->setDetenteur($detenteur)
            ->setType($type)
            ->setQuantite($quantite)
            ->setDateMouvement(new \DateTimeImmutable($date));

        $em->persist($mouvement);
        $em->flush();
    }

    private function derniereAttestationDe(Detenteur $detenteur): ?AttestationCle
    {
        $em = $this->em();
        $em->clear();

        /** @var AttestationCleRepository $repo */
        $repo = $em->getRepository(AttestationCle::class);

        return $repo->findDerniereDe(
            $em->find(Detenteur::class, $detenteur->getId()),
            $em->find(Season::class, $this->season->getId()),
        );
    }

    private function soldeDe(Detenteur $detenteur): int
    {
        $em = $this->em();

        return $em->getRepository(CleMouvement::class)->getSolde(
            $em->find(Detenteur::class, $detenteur->getId()),
        );
    }
}
