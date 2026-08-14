<?php declare(strict_types=1);

namespace App\Tests\Controller\Admin;

use App\Entity\Dirigeant;
use App\Entity\Season;
use App\Entity\User;
use App\Enum\DirigeantRole;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Création manuelle d'un dirigeant.
 *
 * Même règle que pour les licenciés : aucun mail ne part sans que l'admin l'ait demandé.
 * Enregistrer une fiche n'est pas demander l'envoi d'un lien.
 */
final class CreationManuelleDirigeantTest extends WebTestCase
{
    private Season $season;

    public function testAucunMailNePartSansCocherLaCase(): void
    {
        $client = static::createClient();
        $this->loginAdmin($client);

        $this->soumettre($client);

        self::assertResponseRedirects();
        self::assertCount(0, self::getMailerMessages(), 'La case n\'est pas cochée par défaut');
        self::assertNull($this->relire()->getLinkSentAt());
    }

    public function testLeLienPartQuandLaCaseEstCochee(): void
    {
        $client = static::createClient();
        $this->loginAdmin($client);

        $this->soumettre($client, ['dirigeant[sendLink]' => '1']);

        self::assertCount(1, self::getMailerMessages());

        $dirigeant = $this->relire();
        self::assertNotNull($dirigeant->getLinkSentAt());
        self::assertNotNull($dirigeant->getFormTokenExpiresAt(), 'Le lien reçu doit être utilisable');
    }

    /** @param array<string, string> $extra */
    private function soumettre(KernelBrowser $client, array $extra = []): void
    {
        $crawler = $client->request('GET', '/admin/effectif/dirigeants/nouveau');
        $form = $crawler->selectButton('Ajouter le dirigeant')->form();

        $form['dirigeant[nom]'] = 'BUREAU';
        $form['dirigeant[prenom]'] = 'Martine';
        $form['dirigeant[role]'] = DirigeantRole::DIRIGEANT->value;
        $form['dirigeant[email]'] = 'martine@example.test';

        foreach ($extra as $champ => $valeur) {
            $form[$champ] = $valeur;
        }

        $client->submit($form);
    }

    private function relire(): Dirigeant
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $em->clear();

        return $em->getRepository(Dirigeant::class)->findOneBy(['nom' => 'BUREAU']);
    }

    private function loginAdmin(KernelBrowser $client): void
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);

        $this->season = (new Season())->setLabel('2025-2026')->setCotisationDefaut(85);
        $user = (new User())->setEmail('admin-creation-dirigeant@example.test')->setPassword('x');
        $user->setSelectedSeason($this->season);

        $em->persist($this->season);
        $em->persist($user);
        $em->flush();

        $client->loginUser($user);
    }
}
