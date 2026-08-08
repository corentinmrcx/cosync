<?php declare(strict_types=1);

namespace App\Tests\Controller\Public;

use App\Entity\Category;
use App\Entity\DossierClub;
use App\Entity\Licencie;
use App\Entity\Season;
use App\Enum\LicenceStatus;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * Parcours de paiement en ligne côté licencié. HelloAsso n'est pas configuré dans
 * l'environnement de test : les appels échouent, ce qui permet de vérifier le point
 * essentiel — un paiement indisponible ne casse rien et ne valide rien.
 */
final class PaiementControllerTest extends WebTestCase
{
    /** Relit le licencié depuis la base, l'EntityManager étant recréé à chaque requête du client. */
    private function reload(string $uuid): Licencie
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();

        return $em->getRepository(Licencie::class)->find(Uuid::fromString($uuid));
    }

    private function createLicencie(EntityManagerInterface $em, bool $formulaireSoumis = true): Licencie
    {
        static $n = 0;
        ++$n;

        $season = (new Season())->setLabel('2025-2026')->setCotisationDefaut(85);
        $em->persist($season);

        $category = (new Category())->setCode('SEN' . $n)->setLabel('Séniors')->setIsEcoleFoot(false);
        $em->persist($category);

        $licencie = (new Licencie())
            ->setNom('DUPONT')
            ->setPrenom('Thomas' . $n)
            ->setDateNaissance(new \DateTimeImmutable('1995-04-12'))
            ->setCategory($category)
            ->setSeason($season);
        $em->persist($licencie);

        $dossier = (new DossierClub())
            ->setLicencie($licencie)
            ->setStatus($formulaireSoumis ? LicenceStatus::FORM_COMPLETED : LicenceStatus::LINK_SENT);
        if ($formulaireSoumis) {
            $dossier->setFormCompletedAt(new \DateTimeImmutable());
        }
        $em->persist($dossier);
        $em->flush();

        // Sans ce clear, la première requête du client réutilise cet EntityManager et le côté
        // inverse licencie.dossierClub reste vide, ce qui ne reflète pas une requête réelle.
        return $this->reload((string) $licencie->getUuid());
    }

    public function testUnFormulaireNonSoumisNAccedePasAuPaiement(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $licencie = $this->createLicencie($em, formulaireSoumis: false);

        $crawler = $client->request('GET', '/inscription/' . $licencie->getUuid() . '/paiement/erreur');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('lien', mb_strtolower($crawler->html()));
        self::assertStringNotContainsString('Réessayer le paiement', $crawler->html());
    }

    public function testLaPageErreurRassureSurLInscriptionEtProposeDeReessayer(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $licencie = $this->createLicencie($em);

        $crawler = $client->request('GET', '/inscription/' . $licencie->getUuid() . '/paiement/erreur');

        self::assertResponseIsSuccessful();
        $html = $crawler->html();
        self::assertStringContainsString('est bien enregistrée', $html);
        self::assertStringContainsString('Réessayer le paiement par carte', $html);
        self::assertStringContainsString('85 €', $html);
    }

    /** On n'annonce jamais un encaissement au licencié : le retour reste au conditionnel. */
    public function testLaPageDeRetourNAnnonceJamaisUnPaiementConfirme(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $licencie = $this->createLicencie($em);

        $crawler = $client->request('GET', '/inscription/' . $licencie->getUuid() . '/paiement/retour');

        self::assertResponseIsSuccessful();
        $html = $crawler->html();
        self::assertStringContainsString('en cours de validation', $html);
        self::assertStringNotContainsString('Paiement reçu', $html);
        self::assertStringNotContainsString('Paiement confirmé', $html);
    }

    public function testLeCheckoutSansJetonCsrfNeLanceAucunPaiement(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $licencie = $this->createLicencie($em);

        $uuid = (string) $licencie->getUuid();
        $client->request('POST', '/inscription/' . $uuid . '/paiement/checkout');

        self::assertResponseRedirects('/inscription/' . $uuid . '/confirmation');
        self::assertNull($this->reload($uuid)->getDossierClub()->getHelloassoCheckoutIntentId());
    }

    /** HelloAsso indisponible : l'inscription reste intacte et le licencié est renvoyé sans erreur 500. */
    public function testUnPaiementIndisponibleRenvoieVersLaConfirmationSansRienValider(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $licencie = $this->createLicencie($em);

        $uuid = (string) $licencie->getUuid();
        $client->request('GET', '/inscription/' . $uuid . '/paiement/demarrer');

        self::assertResponseRedirects('/inscription/' . $uuid . '/confirmation');

        $client->followRedirect();
        self::assertSelectorTextContains('.inscription-flash-error', 'momentanément indisponible');
        self::assertSame(LicenceStatus::FORM_COMPLETED, $this->reload($uuid)->getDossierClub()->getStatus());
    }

    public function testLaConfirmationProposeLePaiementParCarteTantQueRienNEstPaye(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $licencie = $this->createLicencie($em);

        $crawler = $client->request('GET', '/inscription/' . $licencie->getUuid() . '/confirmation');

        self::assertResponseIsSuccessful();
        $html = $crawler->html();
        self::assertStringContainsString('Payer', $html);
        self::assertStringNotContainsString('Paiement reçu', $html);
        self::assertStringNotContainsString('SumUp', $html);
    }
}
