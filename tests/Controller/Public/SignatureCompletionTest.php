<?php declare(strict_types=1);

namespace App\Tests\Controller\Public;

use App\Entity\Category;
use App\Entity\DocumentSignable;
use App\Entity\DocumentSignature;
use App\Entity\DossierClub;
use App\Entity\Licencie;
use App\Entity\Season;
use App\Enum\LicenceStatus;
use App\Service\Mail\LienPublic;
use App\Tests\Support\DocumentFixtures;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Signer un document ajouté après l'inscription.
 *
 * C'est le seul chemin par lequel un dossier de joueur peut rester sans signature :
 * son formulaire est complet, son lien est consommé, et le parcours d'inscription ne
 * lui sera plus jamais présenté. Le parcours de signature ne redemande rien d'autre.
 */
final class SignatureCompletionTest extends WebTestCase
{
    private const SIGNATURE = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

    public function testLeParcoursNePresenteQueLeDocumentManquant(): void
    {
        $client = static::createClient();
        $licencie = $this->licencieAvecDossierComplet();
        $this->documentAjouteApresCoup($licencie->getSeason());

        $client->request('GET', '/inscription/' . $licencie->getUuid() . '/signer');
        $html = (string) $client->getResponse()->getContent();

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Charte communication', $html);
        // Le dossier est déjà complet : rien de ce qui a été saisi ne doit être redemandé.
        self::assertStringNotContainsString('Mode de paiement', $html);
        self::assertStringNotContainsString('Droit à l\'image', $html);
    }

    public function testLaSignatureEstEnregistreeEtLeLienConsomme(): void
    {
        $client = static::createClient();
        $licencie = $this->licencieAvecDossierComplet();
        $document = $this->documentAjouteApresCoup($licencie->getSeason());

        $crawler = $client->request('GET', '/inscription/' . $licencie->getUuid() . '/signer');
        $token = $crawler->filter('input[name="_token"]')->attr('value');

        $client->request('POST', '/inscription/' . $licencie->getUuid() . '/signer', [
            '_token' => $token,
            'signature_data' => [$document->getId() => self::SIGNATURE],
        ]);

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Merci', (string) $client->getResponse()->getContent());
        self::assertSame(1, $this->countSignatures());

        // Lien à usage unique : le rejouer ne doit pas rouvrir le parcours.
        $client->request('GET', '/inscription/' . $licencie->getUuid() . '/signer');
        self::assertStringContainsString('Lien expiré ou invalide', (string) $client->getResponse()->getContent());
    }

    public function testUneSignatureManquanteRejetteLaSoumission(): void
    {
        $client = static::createClient();
        $licencie = $this->licencieAvecDossierComplet();
        $this->documentAjouteApresCoup($licencie->getSeason());

        $crawler = $client->request('GET', '/inscription/' . $licencie->getUuid() . '/signer');
        $token = $crawler->filter('input[name="_token"]')->attr('value');

        $client->request('POST', '/inscription/' . $licencie->getUuid() . '/signer', [
            '_token' => $token,
        ]);

        self::assertResponseRedirects('/inscription/' . $licencie->getUuid() . '/signer');
        self::assertSame(0, $this->countSignatures());
    }

    /** Tout signé : le lien mène à un écran qui le dit, jamais à un formulaire vide. */
    public function testUnDossierDejaSigneAnnonceQuIlNYARienASigner(): void
    {
        $client = static::createClient();
        $licencie = $this->licencieAvecDossierComplet();

        $client->request('GET', '/inscription/' . $licencie->getUuid() . '/signer');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Rien à signer', (string) $client->getResponse()->getContent());
    }

    private function countSignatures(): int
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);

        return (int) $em->createQueryBuilder()
            ->select('COUNT(s.id)')
            ->from(DocumentSignature::class, 's')
            ->getQuery()
            ->getSingleScalarResult();
    }

    /** Un document créé après que le licencié a terminé son inscription. */
    private function documentAjouteApresCoup(Season $season): DocumentSignable
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);

        $document = (new DocumentFixtures($em))->documentLicencie(
            $season,
            code: 'charte_communication',
            titre: 'Charte communication',
            contenuHtml: '<p>Charte ajoutee en cours de saison.</p>',
            sortOrder: 20,
        );

        $em->flush();

        return $document;
    }

    private function licencieAvecDossierComplet(): Licencie
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);

        $season = (new Season())->setLabel('2025-2026')->setCotisationDefaut(85);
        $category = (new Category())->setCode('SENIOR')->setLabel('Séniors')->setIsEcoleFoot(false);

        $licencie = (new Licencie())
            ->setNom('DUPONT')
            ->setPrenom('Thomas')
            ->setDateNaissance(new \DateTimeImmutable('1990-01-01'))
            ->setEmail('thomas@example.test')
            ->setCategory($category)
            ->setSeason($season)
            // Le lien a été rouvert par la demande de signature.
            ->setFormTokenExpiresAt(LienPublic::expiration());

        $dossier = (new DossierClub())
            ->setLicencie($licencie)
            ->setStatus(LicenceStatus::VALIDATED)
            ->setFormCompletedAt(new \DateTimeImmutable('-2 months'));

        $em->persist($season);
        $em->persist($category);
        $em->persist($licencie);
        $em->persist($dossier);
        $em->flush();

        return $licencie;
    }
}
