<?php declare(strict_types=1);

namespace App\Tests\Controller\Admin;

use App\Entity\Category;
use App\Entity\Licencie;
use App\Entity\Season;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Gabarit des formulaires licencié (création manuelle et modification).
 *
 * La nature de licence n'était rendue dans aucun des deux templates : form_end()
 * la recrachait nue, sans espacement, après le bouton d'enregistrement.
 */
final class LicencieFormulairesEcranTest extends WebTestCase
{
    private ?Season $season = null;

    /** @return iterable<string, array{string}> */
    public static function ecransProvider(): iterable
    {
        yield 'création' => ['new'];
        yield 'modification' => ['edit'];
    }

    #[DataProvider('ecransProvider')]
    public function testLaNatureDeLicenceEstUnChampDuFormulaire(string $ecran): void
    {
        $client = static::createClient();
        $this->loginAdmin($client);
        $url = $this->url($ecran);

        $crawler = $client->request('GET', $url);

        self::assertResponseIsSuccessful();

        $champ = $crawler->filter('.field select[id$="_natureLicence"]');
        self::assertCount(1, $champ, 'La nature de licence est rendue dans un .field');
        self::assertCount(
            1,
            $champ->ancestors()->filter('.field')->eq(0)->filter('label'),
            'Le champ porte son propre label, comme les autres champs du formulaire'
        );
    }

    #[DataProvider('ecransProvider')]
    public function testLaNatureDeLicenceEstPlaceeAvantLesBoutonsDAction(string $ecran): void
    {
        $client = static::createClient();
        $this->loginAdmin($client);
        $url = $this->url($ecran);

        $html = (string) $client->request('GET', $url)->html();

        $positionChamp = strpos($html, '_natureLicence');
        $positionActions = strpos($html, 'season-form-actions');

        self::assertIsInt($positionChamp);
        self::assertIsInt($positionActions);
        self::assertLessThan($positionActions, $positionChamp, 'Le champ précède le bouton d\'enregistrement');
    }

    /* ── Outils ── */

    private function url(string $ecran): string
    {
        return $ecran === 'new'
            ? '/admin/effectif/joueurs/nouveau'
            : '/admin/effectif/joueurs/' . $this->creerLicencie()->getUuid() . '/modifier';
    }

    private function creerLicencie(): Licencie
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);

        $category = (new Category())->setCode('SENIOR')->setLabel('Séniors')->setIsEcoleFoot(false);
        $licencie = (new Licencie())
            ->setNom('MARCOUX')
            ->setPrenom('Corentin')
            ->setDateNaissance(new \DateTimeImmutable('2005-06-19'))
            ->setCategory($category)
            ->setSeason($this->season);

        $em->persist($category);
        $em->persist($licencie);
        $em->flush();

        return $licencie;
    }

    private function loginAdmin(KernelBrowser $client): void
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);

        $this->season = (new Season())->setLabel('2025-2026')->setCotisationDefaut(85);
        $user = (new User())->setEmail('admin-licencie-form@example.test')->setPassword('x');
        $user->setSelectedSeason($this->season);

        $em->persist($this->season);
        $em->persist($user);
        $em->flush();

        $client->loginUser($user);
    }
}
