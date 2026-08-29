<?php declare(strict_types=1);

namespace App\Service\Compte;

use App\DTO\LigneRoleAcces;
use App\Entity\RoleAcces;
use App\Enum\Permission;
use App\Repository\RoleAccesRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Création et modification des rôles d'accès.
 *
 * Deux règles y sont tenues, et une seule fois :
 *
 * - **une écriture entraîne sa lecture** ({@see PermissionCollector::completer()}) — cocher
 *   « enregistrer un paiement » sans « consulter les paiements » ne doit pas être seulement
 *   déconseillé, ça doit être impossible à produire ;
 * - **un rôle système ne se supprime pas**, et un rôle porté par un compte non plus. Sans
 *   quoi une suppression retirerait silencieusement ses droits à quelqu'un, sans jamais dire
 *   à qui.
 */
final class RoleAccesService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly RoleAccesRepository $roles,
        private readonly PermissionCollector $collector,
    ) {}

    /**
     * @param list<Permission> $permissions
     *
     * @throws \DomainException si le nom est vide ou déjà pris
     */
    public function creer(string $nom, array $permissions): RoleAcces
    {
        $nom = trim($nom);
        $this->verifierNom($nom, null);

        $role = (new RoleAcces())
            ->setNom($nom)
            ->setPermissions($this->collector->completer($permissions));

        $this->em->persist($role);
        $this->em->flush();

        return $role;
    }

    /**
     * @param list<Permission> $permissions
     *
     * @throws \DomainException si le nom est vide ou déjà pris par un autre rôle
     */
    public function mettreAJour(RoleAcces $role, string $nom, array $permissions): void
    {
        $nom = trim($nom);
        $this->verifierNom($nom, $role);

        $role->setNom($nom)
            ->setPermissions($this->collector->completer($permissions));

        $this->em->flush();
    }

    /**
     * La liste des rôles, chacun avec le nombre de comptes qui le portent et ce qui
     * empêcherait sa suppression.
     *
     * @return list<LigneRoleAcces>
     */
    public function lignes(): array
    {
        $comptes = $this->roles->compterUtilisateursParRole();

        return array_map(
            fn (RoleAcces $role): LigneRoleAcces => new LigneRoleAcces(
                $role,
                $comptes[$role->getId()] ?? 0,
                $this->motifBlocageSuppression($role, $comptes),
            ),
            $this->roles->findAllOrderedByNom(),
        );
    }

    /** @throws \DomainException si le rôle est livré avec l'application ou encore porté */
    public function supprimer(RoleAcces $role): void
    {
        $motif = $this->motifBlocageSuppression($role);

        if ($motif !== null) {
            throw new \DomainException($motif);
        }

        $this->em->remove($role);
        $this->em->flush();
    }

    /**
     * Ce qui empêche la suppression, ou `null` si elle est possible.
     *
     * Rendu plutôt que réduit à un booléen : l'écran garde le bouton, cadenassé, et porte le
     * motif en infobulle. Un bouton qui disparaît sans rien dire laisse l'admin chercher ce
     * qu'il a mal fait — d'où une phrase courte, faite pour tenir dans une bulle.
     *
     * @param array<int, int>|null $comptes décompte déjà chargé, pour ne pas le refaire par ligne
     */
    public function motifBlocageSuppression(RoleAcces $role, ?array $comptes = null): ?string
    {
        if ($role->estSysteme()) {
            return 'Rôle livré avec l\'application : modifiable et renommable, mais pas supprimable.';
        }

        $comptes ??= $this->roles->compterUtilisateursParRole();
        $porteurs = $comptes[$role->getId()] ?? 0;

        if ($porteurs > 0) {
            return sprintf(
                'Encore attribué à %d compte%s : retirez-le de leur fiche d\'abord.',
                $porteurs,
                $porteurs > 1 ? 's' : '',
            );
        }

        return null;
    }

    /** @throws \DomainException */
    private function verifierNom(string $nom, ?RoleAcces $role): void
    {
        if ($nom === '') {
            throw new \DomainException('Le nom du rôle est obligatoire.');
        }

        $existant = $this->roles->findOneByNom($nom);

        if ($existant !== null && ($role === null || $existant->getId() !== $role->getId())) {
            throw new \DomainException(sprintf('Un rôle nommé « %s » existe déjà.', $nom));
        }
    }
}
