<?php declare(strict_types=1);

namespace App\Tests\Controller\Public;

use App\Entity\Dirigeant;
use App\Entity\DocumentSignature;
use App\Entity\Season;
use App\Tests\Support\DocumentFixtures;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * Parcours public du dirigeant. Couvre la régression où un dossier partiellement
 * rempli (transport déjà renseigné) ne pouvait plus être complété : la garde
 * « transport déjà renseigné → rejet » bloquait toute soumission complémentaire.
 */
final class DirigeantFormControllerTest extends WebTestCase
{
    private const SIGNATURE = 'data:image/png;base64,iVBORw0KGgo=';

    public function testCompletionPartielleSurDossierAuTransportDejaRenseigne(): void
    {
        // Transport et règlement déjà OK, il ne manque que les tailles.
        // Ce chemin n'échouait plus qu'à cause de la garde sur le transport.
        $client = static::createClient();
        $uuid = $this->createDirigeant(
            withTaille: false,
            reglementDejaSigne: true,
        );

        $crawler = $client->request('GET', '/dirigeant/' . $uuid);
        $token = $crawler->filter('input[name="_token"]')->attr('value');

        $client->request('POST', '/dirigeant/' . $uuid, [
            '_token' => $token,
            'taille_haut' => 'L',
            'taille_bas' => 'M',
            'pointure' => '42',
        ]);

        // Ne doit PAS repartir sur le formulaire avec l'erreur « Formulaire incomplet ».
        self::assertResponseRedirects('/dirigeant/' . $uuid . '/confirmation');

        $em = self::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        $dirigeant = $em->find(Dirigeant::class, Uuid::fromString($uuid));

        self::assertNotNull($dirigeant->getFormCompletedAt());
        self::assertSame('L', $dirigeant->getTailleHaut());
        self::assertFalse($dirigeant->isFormTokenValid(), 'Le lien doit être consommé.');
    }

    public function testSignatureManquanteRenvoieSurLeFormulaire(): void
    {
        $client = static::createClient();
        $uuid = $this->createDirigeant(withTaille: true, reglementDejaSigne: false);

        $crawler = $client->request('GET', '/dirigeant/' . $uuid);
        $token = $crawler->filter('input[name="_token"]')->attr('value');

        // Document requis mais aucune signature → rejet avant toute génération de PDF.
        $client->request('POST', '/dirigeant/' . $uuid, [
            '_token' => $token,
        ]);

        self::assertResponseRedirects('/dirigeant/' . $uuid);
        self::assertSame(0, $this->countSignatures());
    }

    public function testUneSignatureInvalideEstRejetee(): void
    {
        $client = static::createClient();
        $uuid = $this->createDirigeant(withTaille: true, reglementDejaSigne: false);

        $crawler = $client->request('GET', '/dirigeant/' . $uuid);
        $token = $crawler->filter('input[name="_token"]')->attr('value');
        $document = $this->documentId();

        $client->request('POST', '/dirigeant/' . $uuid, [
            '_token' => $token,
            'signature_data' => [$document => 'pas-une-image'],
        ]);

        self::assertResponseRedirects('/dirigeant/' . $uuid);
        self::assertSame(0, $this->countSignatures());
    }

    public function testUnIdDeDocumentNonAttenduNeCreeAucuneSignature(): void
    {
        $client = static::createClient();
        $uuid = $this->createDirigeant(withTaille: true, reglementDejaSigne: false);

        $crawler = $client->request('GET', '/dirigeant/' . $uuid);
        $token = $crawler->filter('input[name="_token"]')->attr('value');
        $document = $this->documentId();

        // Le client ajoute un id fantaisiste : il doit être ignoré, et seul le
        // document réellement attendu est enregistré.
        $client->request('POST', '/dirigeant/' . $uuid, [
            '_token' => $token,
            'signature_data' => [
                $document => self::SIGNATURE,
                999999 => self::SIGNATURE,
            ],
        ]);

        self::assertResponseRedirects('/dirigeant/' . $uuid . '/confirmation');
        self::assertSame(1, $this->countSignatures());
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

    private function documentId(): int
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);

        return (int) $em->createQueryBuilder()
            ->select('d.id')
            ->from(\App\Entity\DocumentSignable::class, 'd')
            ->getQuery()
            ->getSingleScalarResult();
    }

    private function createDirigeant(bool $withTaille, bool $reglementDejaSigne): string
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $fixtures = new DocumentFixtures($em);

        $season = (new Season())->setLabel('2025-2026')->setCotisationDefaut(85);

        $dirigeant = (new Dirigeant())
            ->setNom('MARTIN')
            ->setPrenom('Kevin')
            ->setSeason($season)
            ->setAutorisationPhoto(true)
            ->setVolontaireTransport(false)
            ->setFormTokenExpiresAt(new \DateTimeImmutable('+30 days'));

        if ($withTaille) {
            $dirigeant->setTailleHaut('L')->setTailleBas('M')->setPointure('42');
        }

        $document = $fixtures->documentDirigeant($season);

        $em->persist($season);
        $em->persist($dirigeant);
        $em->flush();

        if ($reglementDejaSigne) {
            // Chemin Drive (pas un chemin local) → considéré comme déjà archivé.
            $fixtures->signerParDirigeant($document, $dirigeant, 'drive-file-id');
            $em->flush();
        }

        $uuid = (string) $dirigeant->getUuid();
        $em->clear();

        return $uuid;
    }
}
