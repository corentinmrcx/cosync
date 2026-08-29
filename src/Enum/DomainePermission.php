<?php declare(strict_types=1);

namespace App\Enum;

/**
 * Regroupement d'affichage des permissions sur l'écran d'un rôle.
 *
 * L'ordre des cases est celui de l'écran : il suit le parcours d'un admin dans
 * l'application (l'effectif d'abord, la configuration du club ensuite), pas l'ordre
 * alphabétique.
 */
enum DomainePermission: string
{
    case EFFECTIF = 'effectif';
    case PAIEMENT = 'paiement';
    case DOTATION = 'dotation';
    case STOCK = 'stock';
    case COMMANDE = 'commande';
    case CLE = 'cle';
    case BOUTIQUE = 'boutique';
    case PLANNING = 'planning';
    case SAISON = 'saison';
    case CLUB = 'club';
    case DIAGNOSTIC = 'diagnostic';

    public function libelle(): string
    {
        return match ($this) {
            self::EFFECTIF => 'Effectif',
            self::PAIEMENT => 'Paiements et licences',
            self::DOTATION => 'Dotations',
            self::STOCK => 'Stock',
            self::COMMANDE => 'Commandes',
            self::CLE => 'Clés',
            self::BOUTIQUE => 'Boutique',
            self::PLANNING => 'Planning des matchs',
            self::SAISON => 'Saison',
            self::CLUB => 'Le club',
            self::DIAGNOSTIC => 'Diagnostic',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::EFFECTIF => 'Fiches des joueurs et des dirigeants, envoi des liens, relances, import FootClubs.',
            self::PAIEMENT => 'Encaissements, attestations de paiement et validation des licences dans FootClubs.',
            self::DOTATION => 'Kits, besoins, tailles, flocage et remise des équipements.',
            self::STOCK => 'Inventaire, mouvements, articles, fournisseurs et grilles de tailles.',
            self::COMMANDE => 'Bons de commande et réception des articles.',
            self::CLE => 'Registre des détenteurs de clés et attestations de remise.',
            self::BOUTIQUE => 'Ouverture de la boutique, lien HelloAsso et annonce aux licenciés.',
            self::PLANNING => 'Matchs à domicile de la saison et synchronisation FFF.',
            self::SAISON => 'Cotisations, équipes, documents à signer et création des saisons.',
            self::CLUB => 'Identité de l\'association, coordonnées bancaires, référentiels et comptes.',
            self::DIAGNOSTIC => 'Purge des données, bascule du mode bêta, mails de test.',
        };
    }

    /** @return list<Permission> */
    public function permissions(): array
    {
        return array_values(array_filter(
            Permission::cases(),
            fn (Permission $permission): bool => $permission->domaine() === $this,
        ));
    }
}
