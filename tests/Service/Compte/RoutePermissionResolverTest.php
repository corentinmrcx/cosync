<?php declare(strict_types=1);

namespace App\Tests\Service\Compte;

use App\Enum\Permission;
use App\Service\Compte\RoutePermissionResolver;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Ce que ces tests verrouillent : **le template n'a plus à connaître le droit d'une action**.
 *
 * Le jour où la carte se construirait mal — un attribut de classe perdu, une méthode
 * introuvable —, `peut_acceder()` rendrait `true` partout et les boutons reviendraient tous.
 * Rien ne serait ouvert pour autant, le contrôleur refuse toujours ; mais on serait revenu à
 * l'écran qui promet une action et répond « Access Denied ».
 */
final class RoutePermissionResolverTest extends KernelTestCase
{
    private RoutePermissionResolver $resolver;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->resolver = self::getContainer()->get(RoutePermissionResolver::class);
    }

    /** Le droit déclaré sur la méthode, dans un contrôleur qui n'en déclare pas sur sa classe. */
    public function testUneActionRendLaPermissionDeSonAttribut(): void
    {
        self::assertSame(
            [Permission::SAISON_CONFIGURER],
            $this->resolver->pour('admin_seasons_new'),
        );
    }

    /**
     * Le droit déclaré sur la classe couvre les actions qui n'en portent pas.
     *
     * C'est le cas le plus courant — « lecture du domaine sur la classe, écriture sur la
     * méthode » (§8) — et celui qu'une lecture naïve des seules méthodes manquerait.
     */
    public function testUneActionSansAttributHeriteDeSaClasse(): void
    {
        self::assertSame(
            [Permission::EFFECTIF_LIRE],
            $this->resolver->pour('admin_licencies_show'),
        );
    }

    /**
     * Classe et méthode se **cumulent**, parce que Symfony les applique toutes les deux.
     * Les remplacer l'une par l'autre annoncerait comme jouable une action que la classe
     * refuse encore.
     */
    public function testClasseEtMethodeSeCumulent(): void
    {
        self::assertSame(
            [Permission::EFFECTIF_LIRE, Permission::PAIEMENT_ENCAISSER],
            $this->resolver->pour('admin_licencies_add_payment'),
        );
    }

    /** `#[AccesLibre]` n'est couvert par aucun `#[IsGranted]` : rien à exiger. */
    public function testUneRouteLibreNExigeRien(): void
    {
        self::assertSame([], $this->resolver->pour('admin_seasons_index'));
    }

    /**
     * Un nom de route inconnu n'exige rien — donc l'appelant affiche.
     *
     * Masquer serait pire : une faute de frappe dans un `path()` disparaîtrait de l'écran au
     * lieu de lever l'erreur de route qui la signale.
     */
    public function testUneRouteInconnueNExigeRien(): void
    {
        self::assertSame([], $this->resolver->pour('route_qui_nexiste_pas'));
    }
}
