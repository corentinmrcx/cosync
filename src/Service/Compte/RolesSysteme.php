<?php declare(strict_types=1);

namespace App\Service\Compte;

use App\Enum\Permission;

/**
 * Les rôles livrés avec l'application — un point de départ, pas un catalogue.
 *
 * **Deux seulement, et c'est délibéré** : ce sont les deux fonctions qui existent dans tous
 * les clubs, celle qui fait tourner la saison et celle qui suit l'argent. En livrer davantage
 * reviendrait à deviner l'organigramme du club à sa place, et un rôle livré qu'on n'utilise
 * pas encombre l'écran sans pouvoir être supprimé. Le reste se crée en trois clics.
 *
 * Ils sont créés par la migration puis maintenus par `app:seed-referential`, et le club les
 * modifie ensuite librement : cocher, décocher, renommer. Seule leur **suppression** est
 * refusée, pour qu'il reste toujours de quoi rouvrir un accès après une fausse manœuvre.
 *
 * Les noms désignent des **fonctions** et non des personnes (« Trésorerie », pas
 * « Trésorière ») : un rôle survit à celui qui l'occupe, et le suivant n'a pas forcément le
 * même genre.
 *
 * ⚠️ Ce référentiel ne se relit pas à l'exécution des migrations déjà déployées : celles-ci
 * portent leur propre instantané SQL, figé à leur date. C'est voulu — une migration est un
 * contrat entre deux états de la base, pas une lecture du code d'aujourd'hui (§13).
 */
final class RolesSysteme
{
    public const RESPONSABLE_FOOT = 'Responsable foot';
    public const TRESORERIE = 'Trésorerie';

    /**
     * @return array<string, list<Permission>>
     */
    public static function definitions(): array
    {
        return [
            self::RESPONSABLE_FOOT => self::toutSauf([
                Permission::UTILISATEUR_GERER,
                Permission::DIAGNOSTIC_ACCEDER,
            ]),
            self::TRESORERIE => [
                Permission::EFFECTIF_LIRE,
                Permission::PAIEMENT_LIRE,
                Permission::PAIEMENT_ENCAISSER,
                Permission::PAIEMENT_ATTESTER,
                Permission::LICENCE_VALIDER_FFF,
                Permission::SAISON_LIRE,
            ],
        ];
    }

    /**
     * @param list<Permission> $exclues
     *
     * @return list<Permission>
     */
    private static function toutSauf(array $exclues): array
    {
        return array_values(array_filter(
            Permission::cases(),
            static fn (Permission $p): bool => !in_array($p, $exclues, true),
        ));
    }
}
