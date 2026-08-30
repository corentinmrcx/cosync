<?php declare(strict_types=1);

namespace App\Tests\Service\Compte;

use App\Entity\RoleAcces;
use App\Entity\User;
use App\Enum\Permission;
use App\Service\Compte\UserService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Le super-admin : le passe-partout, et ce qui l'empêche de disparaître.
 *
 * ⚠️ Ce statut était auparavant *déduit* de `DIAG_EMAIL`, l'email de redirection du mode
 * bêta : un réglage d'exploitation décidait donc de qui administrait l'application. C'est
 * désormais un fait porté par le compte, et ces tests verrouillent la seule règle qui le
 * rend sûr — il doit toujours en rester un.
 */
final class UserServiceSuperAdminTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private UserService $service;

    protected function setUp(): void
    {
        self::bootKernel();

        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->service = self::getContainer()->get(UserService::class);

        // La base de test peut déjà en porter : on part d'un terrain connu.
        foreach ($this->em->getRepository(User::class)->findBy(['superAdmin' => true]) as $existant) {
            $existant->setSuperAdmin(false);
        }
        $this->em->flush();
    }

    public function testLeStatutNeSeDeduitPlusDeLEmailDeRedirection(): void
    {
        // admin@example.test est le DIAG_EMAIL du .env.test : il ne suffit plus.
        $user = $this->creer('admin@example.test');

        self::assertFalse($this->service->estSuperAdmin($user));
    }

    public function testLeDernierSuperAdminNeSeRetirePas(): void
    {
        $seul = $this->creer('seul@example.test', superAdmin: true);

        $this->expectException(\DomainException::class);
        $this->service->definirSuperAdmin($seul, false);
    }

    public function testUnSuperAdminSeRetireDesQuUnAutreExiste(): void
    {
        $premier = $this->creer('premier@example.test', superAdmin: true);
        $second = $this->creer('second@example.test', superAdmin: true);

        $this->service->definirSuperAdmin($premier, false);

        self::assertFalse($premier->estSuperAdmin());
        self::assertTrue($second->estSuperAdmin());
    }

    public function testUnSuperAdminNeSeSupprimePas(): void
    {
        $auteur = $this->creer('auteur@example.test', superAdmin: true);
        $cible = $this->creer('cible@example.test', superAdmin: true);

        $this->expectException(\DomainException::class);
        $this->service->supprimer($cible, $auteur);
    }

    public function testOnNeSupprimePasSonPropreCompte(): void
    {
        $auteur = $this->creer('soi@example.test');

        $this->expectException(\DomainException::class);
        $this->service->supprimer($auteur, $auteur);
    }

    /** Le mot de passe d'un super-admin ne se prend pas : il le change depuis « Mon profil ». */
    public function testLeMotDePasseDUnSuperAdminNeSeChangePasDepuisLEcranDesComptes(): void
    {
        $super = $this->creer('protege@example.test', superAdmin: true);
        $ordinaire = $this->creer('ordinaire@example.test');

        self::assertFalse($this->service->peutChangerLeMotDePasseDe($super));
        self::assertTrue($this->service->peutChangerLeMotDePasseDe($ordinaire));
    }

    /** Remplacer, pas ajouter : un rôle retiré de la sélection doit réellement partir. */
    public function testRemplacerLesRolesRetireLesAnciens(): void
    {
        $ancien = $this->role('Ancien', Permission::EFFECTIF_LIRE);
        $nouveau = $this->role('Nouveau', Permission::STOCK_LIRE);

        $user = $this->creer('roles@example.test');
        $this->service->remplacerRoles($user, [$ancien]);
        $this->em->flush();

        $this->service->remplacerRoles($user, [$nouveau]);
        $this->em->flush();

        $noms = array_map(static fn (RoleAcces $r): string => $r->getNom(), $user->getRolesAcces()->toArray());

        self::assertSame(['Nouveau'], array_values($noms));
    }

    public function testLaListeSignaleLeDernierSuperAdminCommeNonRetirable(): void
    {
        $seul = $this->creer('unique@example.test', superAdmin: true);
        $autre = $this->creer('autre@example.test');

        $lignes = $this->service->lignes([$seul, $autre], $autre);

        self::assertFalse($lignes[0]->superAdminRetirable);
        self::assertFalse($lignes[0]->supprimable);
    }

    private function creer(string $email, bool $superAdmin = false): User
    {
        $user = (new User())->setEmail($email)->setSuperAdmin($superAdmin);
        $user->setPassword('x');

        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    private function role(string $nom, Permission $permission): RoleAcces
    {
        $role = (new RoleAcces())->setNom($nom)->setPermissions([$permission]);

        $this->em->persist($role);
        $this->em->flush();

        return $role;
    }
}
