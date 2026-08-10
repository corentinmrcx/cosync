<?php declare(strict_types=1);

namespace App\Tests\Controller\Admin;

use App\Entity\Season;
use App\Entity\User;
use App\Repository\SeasonRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Création d'une saison : le texte d'attestation de clé est repris de la saison précédente,
 * et la saison créée devient celle dans laquelle on travaille — sans quoi il faudrait
 * repasser par le tableau de bord du club pour y entrer.
 */
final class SeasonCreationTest extends WebTestCase
{
    public function testLaNouvelleSaisonReprendLAttestationEtDevientCourante(): void
    {
        $client = static::createClient();
        $user = $this->loginAdmin($client);

        $crawler = $client->request('GET', '/admin/saisons/nouvelle');
        self::assertResponseIsSuccessful();

        $form = $crawler->filter('form[name="season"]')->form();
        $form['season[startYear]'] = '2026';
        $form['season[cotisationDefaut]'] = '90';
        $client->submit($form);

        self::assertResponseRedirects('/admin/saison');

        $em = self::getContainer()->get(EntityManagerInterface::class);
        $em->clear();

        $nouvelle = self::getContainer()->get(SeasonRepository::class)->findOneBy(['label' => '2026-2027']);
        self::assertNotNull($nouvelle);
        self::assertSame(90, $nouvelle->getCotisationDefaut());
        self::assertSame('Texte attestation', $nouvelle->getAttestationCleText());

        $userRecharge = $em->find(User::class, $user->getId());
        self::assertSame(
            '2026-2027',
            $userRecharge->getSelectedSeason()?->getLabel(),
            'La saison créée devient la saison de travail',
        );
    }

    /* ── Outils ── */

    private function loginAdmin(KernelBrowser $client): User
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);

        $season = (new Season())
            ->setLabel('2025-2026')
            ->setCotisationDefaut(85)
            ->setAttestationCleText('Texte attestation');

        $user = (new User())->setEmail('admin-saisons@example.test')->setPassword('x');
        $user->setSelectedSeason($season);

        $em->persist($season);
        $em->persist($user);
        $em->flush();

        $client->loginUser($user);

        return $user;
    }
}
