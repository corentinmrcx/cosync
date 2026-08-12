<?php declare(strict_types=1);

namespace App\Tests\Controller\Admin;

use App\Entity\Category;
use App\Entity\Licencie;
use App\Entity\Season;
use App\Entity\User;
use App\Enum\LicenceStatus;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Création manuelle d'un licencié.
 *
 * Même règle que pour l'import : aucun mail ne part sans que l'admin l'ait demandé.
 * Une case cochée d'avance n'est pas une demande.
 */
final class CreationManuelleLicencieTest extends WebTestCase
{
    private Season $season;
    private Category $category;

    public function testAucunMailNePartSansCocherLaCase(): void
    {
        $client = static::createClient();
        $this->loginAdmin($client);

        $client->request('GET', '/admin/effectif/joueurs/nouveau');
        $client->submitForm('Ajouter le licencié', $this->champs());

        self::assertCount(0, self::getMailerMessages(), 'La case n\'est pas cochée par défaut');

        $licencie = $this->relire();
        self::assertNull($licencie->getLinkSentAt());
        self::assertSame(LicenceStatus::IMPORTED, $licencie->getDossierClub()?->getStatus());
    }

    /**
     * Le statut suit l'envoi. La relation dossier n'étant renseignée que du côté propriétaire,
     * l'entité fraîchement créée rendait `getDossierClub()` à null : le mail partait et le
     * dossier restait affiché « Importé ».
     */
    public function testLeStatutPasseALienEnvoyeQuandLaCaseEstCochee(): void
    {
        $client = static::createClient();
        $this->loginAdmin($client);

        $client->request('GET', '/admin/effectif/joueurs/nouveau');
        $client->submitForm('Ajouter le licencié', $this->champs(['licencie_create[sendLink]' => '1']));

        self::assertCount(1, self::getMailerMessages());

        $licencie = $this->relire();
        self::assertNotNull($licencie->getLinkSentAt());
        self::assertSame(LicenceStatus::LINK_SENT, $licencie->getDossierClub()?->getStatus());
    }

    /**
     * @param array<string, string> $extra
     *
     * @return array<string, string>
     */
    private function champs(array $extra = []): array
    {
        return array_merge([
            'licencie_create[nom]' => 'UFNZEEB',
            'licencie_create[prenom]' => 'Ejeyfb',
            'licencie_create[dateNaissance]' => '2005-06-19',
            'licencie_create[category]' => (string) $this->category->getId(),
            'licencie_create[email]' => 'ejeyfb@example.test',
        ], $extra);
    }

    private function relire(): Licencie
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $em->clear();

        return $em->getRepository(Licencie::class)->findOneBy(['nom' => 'UFNZEEB']);
    }

    private function loginAdmin(KernelBrowser $client): void
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);

        $this->season = (new Season())->setLabel('2025-2026')->setCotisationDefaut(85);
        $this->category = (new Category())->setCode('SENIOR')->setLabel('Séniors')->setIsEcoleFoot(false);
        $user = (new User())->setEmail('admin-creation@example.test')->setPassword('x');
        $user->setSelectedSeason($this->season);

        $em->persist($this->season);
        $em->persist($this->category);
        $em->persist($user);
        $em->flush();

        $client->loginUser($user);
    }
}
