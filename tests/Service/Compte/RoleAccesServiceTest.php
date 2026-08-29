<?php declare(strict_types=1);

namespace App\Tests\Service\Compte;

use App\Entity\RoleAcces;
use App\Entity\User;
use App\Enum\Permission;
use App\Service\Compte\RoleAccesService;
use App\Service\Compte\RolesSysteme;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Les règles qui protègent la composition des rôles.
 *
 * Deux d'entre elles tiennent tout le reste : une écriture entraîne sa lecture — sinon on
 * compose un rôle qui modifie ce qu'il ne peut pas consulter —, et un rôle encore porté ne se
 * supprime pas, sinon la suppression retire silencieusement ses droits à quelqu'un sans
 * jamais dire à qui.
 */
final class RoleAccesServiceTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private RoleAccesService $service;

    protected function setUp(): void
    {
        self::bootKernel();

        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->service = self::getContainer()->get(RoleAccesService::class);
    }

    public function testLaCreationCompleteLesImplications(): void
    {
        $role = $this->service->creer('Caisse', [Permission::PAIEMENT_ENCAISSER]);

        self::assertTrue($role->a(Permission::PAIEMENT_LIRE));
        self::assertTrue($role->a(Permission::EFFECTIF_LIRE));
    }

    public function testLaModificationCompleteAussi(): void
    {
        $role = $this->service->creer('Magasin', [Permission::STOCK_LIRE]);

        $this->service->mettreAJour($role, 'Magasin', [Permission::COMMANDE_GERER]);

        self::assertTrue($role->a(Permission::COMMANDE_LIRE));
        self::assertTrue($role->a(Permission::STOCK_LIRE));
        self::assertFalse($role->a(Permission::STOCK_GERER), 'Rien de plus que ce que la chaîne accorde.');
    }

    public function testUnNomDejaPrisEstRefuse(): void
    {
        $this->service->creer('Buvette', [Permission::CLE_LIRE]);

        $this->expectException(\DomainException::class);
        $this->service->creer('Buvette', [Permission::CLE_LIRE]);
    }

    public function testUnRoleGardeSonPropreNomALaModification(): void
    {
        $role = $this->service->creer('Arbitrage', [Permission::EFFECTIF_LIRE]);

        $this->service->mettreAJour($role, 'Arbitrage', [Permission::EFFECTIF_LIRE]);

        self::assertSame('Arbitrage', $role->getNom());
    }

    public function testUnNomVideEstRefuse(): void
    {
        $this->expectException(\DomainException::class);
        $this->service->creer('   ', [Permission::EFFECTIF_LIRE]);
    }

    /** Il doit rester de quoi rouvrir un accès après une fausse manœuvre. */
    public function testUnRoleSystemeNeSeSupprimePas(): void
    {
        $systeme = $this->em->getRepository(RoleAcces::class)->findOneBy(['nom' => RolesSysteme::RESPONSABLE_FOOT]);

        self::assertNotNull($systeme, 'La migration installe les rôles livrés.');
        self::assertNotNull($this->service->motifBlocageSuppression($systeme));

        $this->expectException(\DomainException::class);
        $this->service->supprimer($systeme);
    }

    public function testUnRoleSystemeResteRenommableEtModifiable(): void
    {
        $systeme = $this->em->getRepository(RoleAcces::class)->findOneBy(['nom' => RolesSysteme::TRESORERIE]);
        self::assertNotNull($systeme);

        $this->service->mettreAJour($systeme, 'Trésorerie du club', [Permission::PAIEMENT_LIRE]);

        self::assertSame('Trésorerie du club', $systeme->getNom());
    }

    public function testUnRoleEncorePorteNeSeSupprimePas(): void
    {
        $role = $this->service->creer('Éphémère', [Permission::EFFECTIF_LIRE]);

        $user = (new User())->setEmail('porteur@example.test');
        $user->setPassword('x');
        $user->ajouterRoleAcces($role);
        $this->em->persist($user);
        $this->em->flush();

        $motif = $this->service->motifBlocageSuppression($role);

        self::assertNotNull($motif);
        self::assertStringContainsString('1 compte', $motif, 'Le motif dit combien de comptes le portent.');

        $this->expectException(\DomainException::class);
        $this->service->supprimer($role);
    }

    public function testUnRoleQuePersonneNePorteSeSupprime(): void
    {
        $role = $this->service->creer('Sans porteur', [Permission::EFFECTIF_LIRE]);
        $id = $role->getId();

        self::assertNull($this->service->motifBlocageSuppression($role));

        $this->service->supprimer($role);

        self::assertNull($this->em->getRepository(RoleAcces::class)->find($id));
    }

    public function testLaListeCompteLesPorteursDeChaqueRole(): void
    {
        $role = $this->service->creer('Compté', [Permission::CLE_LIRE]);

        $user = (new User())->setEmail('compteur@example.test');
        $user->setPassword('x');
        $user->ajouterRoleAcces($role);
        $this->em->persist($user);
        $this->em->flush();

        $lignes = array_filter($this->service->lignes(), static fn ($l): bool => $l->role->getNom() === 'Compté');

        self::assertCount(1, $lignes);
        self::assertSame(1, reset($lignes)->comptes);
    }
}
