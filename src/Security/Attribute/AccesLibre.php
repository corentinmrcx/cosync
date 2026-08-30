<?php declare(strict_types=1);

namespace App\Security\Attribute;

/**
 * Marque une action d'administration qui n'exige **que** d'être connecté.
 *
 * Elle n'accorde rien : elle documente une exception, et surtout elle oblige à l'écrire.
 * `bin/check-permissions.php` refuse une action qui ne déclare ni permission ni cet
 * attribut — sans lui, la seule façon de tenir la règle « refus par défaut » aurait été de
 * faire confiance à la relecture, et c'est exactement ce qui laisse passer une route.
 *
 * Ne convient qu'à deux cas, tous deux vérifiés à la relecture :
 *
 * - un **point de navigation** (un hub, la bascule de saison) dont chaque carte porte
 *   elle-même sa permission — le fermer condamnerait l'accès à ce qu'il y a derrière ;
 * - un écran qui ne parle **que du compte connecté** (son profil, la documentation).
 *
 * Jamais pour un écran qui montre ou modifie des données du club.
 */
#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::TARGET_METHOD)]
final class AccesLibre
{
    public function __construct(
        public readonly string $raison,
    ) {}
}
