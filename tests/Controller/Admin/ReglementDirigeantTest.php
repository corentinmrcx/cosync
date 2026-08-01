<?php declare(strict_types=1);

namespace App\Tests\Controller\Admin;

use App\Entity\Season;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Édition admin du règlement des dirigeants. Le même écran sert les deux
 * règlements : l'enjeu est qu'ils restent bien deux textes indépendants.
 */
final class ReglementDirigeantTest extends WebTestCase
{
    private ?Season $season = null;

    public function testLesDeuxEcransDEditionRepondent(): void
    {
        $client = static::createClient();
        $this->loginAdmin($client);

        $client->request('GET', $this->url('/reglement'));
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Aperçu — rendu licencié', (string) $client->getResponse()->getContent());

        $client->request('GET', $this->url('/reglement-dirigeant'));
        self::assertResponseIsSuccessful();

        $html = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('Règlement intérieur des dirigeants', $html);
        self::assertStringContainsString('Aperçu — rendu dirigeant', $html);
    }

    public function testEnregistrerLeReglementDirigeantNEcrasePasCeluiDesJoueurs(): void
    {
        $client = static::createClient();
        $this->loginAdmin($client);
        $this->season->setReglementText('<p>Texte joueurs</p>');
        self::getContainer()->get(EntityManagerInterface::class)->flush();

        $crawler = $client->request('GET', $this->url('/reglement-dirigeant'));
        $token   = $crawler->filter('form#reglement-form input[name="_token"]')->attr('value');

        $client->request('POST', $this->url('/reglement-dirigeant'), [
            '_token'         => $token,
            'reglement_text' => '<p>Texte dirigeants</p>',
        ]);

        self::assertResponseRedirects('/admin/config');

        $season = $this->reloadSeason();
        self::assertSame('<p>Texte dirigeants</p>', $season->getReglementDirigeantText());
        self::assertSame('<p>Texte joueurs</p>', $season->getReglementText(), 'Le règlement des joueurs doit être intact.');
    }

    public function testEnregistrerLeReglementJoueursNEcrasePasCeluiDesDirigeants(): void
    {
        $client = static::createClient();
        $this->loginAdmin($client);
        $this->season->setReglementDirigeantText('<p>Texte dirigeants</p>');
        self::getContainer()->get(EntityManagerInterface::class)->flush();

        $crawler = $client->request('GET', $this->url('/reglement'));
        $token   = $crawler->filter('form#reglement-form input[name="_token"]')->attr('value');

        $client->request('POST', $this->url('/reglement'), [
            '_token'         => $token,
            'reglement_text' => '<p>Texte joueurs</p>',
        ]);

        $season = $this->reloadSeason();
        self::assertSame('<p>Texte joueurs</p>', $season->getReglementText());
        self::assertSame('<p>Texte dirigeants</p>', $season->getReglementDirigeantText());
    }

    public function testUnTokenCsrfInvalideNEnregistreRien(): void
    {
        $client = static::createClient();
        $this->loginAdmin($client);

        $client->request('POST', $this->url('/reglement-dirigeant'), [
            '_token'         => 'invalide',
            'reglement_text' => '<p>Texte dirigeants</p>',
        ]);

        self::assertResponseRedirects($this->url('/reglement-dirigeant'));
        self::assertNull($this->reloadSeason()->getReglementDirigeantText());
    }

    public function testLApercuPdfDirigeantEstTelechargeable(): void
    {
        $client = static::createClient();
        $this->loginAdmin($client);
        $this->season->setReglementDirigeantText('<p>Texte dirigeants</p>');
        self::getContainer()->get(EntityManagerInterface::class)->flush();

        $client->request('GET', $this->url('/reglement-dirigeant/apercu'));

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'application/pdf');
        self::assertStringStartsWith('%PDF', (string) $client->getResponse()->getContent());
    }

    public function testLEcranDEditionExigeUneAuthentification(): void
    {
        $client = static::createClient();
        $this->makeSeason();

        $client->request('GET', $this->url('/reglement-dirigeant'));

        self::assertResponseRedirects();
        self::assertStringContainsString('/login', (string) $client->getResponse()->headers->get('Location'));
    }

    private function url(string $suffix): string
    {
        return '/admin/config/saisons/' . $this->season->getId() . $suffix;
    }

    private function loginAdmin(KernelBrowser $client): void
    {
        $em   = self::getContainer()->get(EntityManagerInterface::class);
        $user = (new User())->setEmail('admin-reglement@example.com')->setPassword('x');

        $this->makeSeason();
        $em->persist($user);
        $em->flush();

        $client->loginUser($user);
    }

    private function makeSeason(): void
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);

        $this->season = (new Season())->setLabel('2025-2026')->setCotisationDefaut(85);
        $em->persist($this->season);
        $em->flush();
    }

    private function reloadSeason(): Season
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $em->clear();

        return $em->find(Season::class, $this->season->getId());
    }
}
