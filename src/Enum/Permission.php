<?php declare(strict_types=1);

namespace App\Enum;

/**
 * Le catalogue des droits de l'application.
 *
 * ⚠️ **Les permissions sont du code, les rôles sont de la donnée.** Une permission existe
 * parce qu'une ligne de code la vérifie ; la créer depuis un écran d'administration ne
 * donnerait aucun droit, puisque personne ne la lit. C'est pourquoi le catalogue vit ici et
 * non en base — un écran qui inventerait des permissions produirait des rôles qui ne
 * protègent rien, et c'est pire qu'une absence de flexibilité parce que ça rassure.
 *
 * Ce qui est configurable, ce sont les paquets : cf. {@see \App\Entity\RoleAcces}.
 *
 * La valeur de chaque cas est l'attribut passé au voter et à `is_granted()` dans Twig. Elle
 * est **stockée en base** dans `role_acces.permissions` : la renommer casse les rôles
 * existants — prévoir une migration de données, ou ne pas la renommer.
 */
enum Permission: string
{
    // — Effectif —
    case EFFECTIF_LIRE = 'effectif.lire';
    case EFFECTIF_GERER = 'effectif.gerer';
    case EFFECTIF_IMPORTER = 'effectif.importer';
    case EFFECTIF_SUPPRIMER = 'effectif.supprimer';

    // — Paiements et licences —
    case PAIEMENT_LIRE = 'paiement.lire';
    case PAIEMENT_ENCAISSER = 'paiement.encaisser';
    case PAIEMENT_ATTESTER = 'paiement.attester';
    case LICENCE_VALIDER_FFF = 'licence.valider_fff';

    // — Dotations —
    case DOTATION_LIRE = 'dotation.lire';
    case DOTATION_GERER = 'dotation.gerer';
    case DOTATION_CONFIGURER = 'dotation.configurer';

    // — Stock —
    case STOCK_LIRE = 'stock.lire';
    case STOCK_GERER = 'stock.gerer';
    case STOCK_CONFIGURER = 'stock.configurer';

    // — Commandes —
    case COMMANDE_LIRE = 'commande.lire';
    case COMMANDE_GERER = 'commande.gerer';

    // — Clés —
    case CLE_LIRE = 'cle.lire';
    case CLE_GERER = 'cle.gerer';

    // — Boutique —
    case BOUTIQUE_LIRE = 'boutique.lire';
    case BOUTIQUE_GERER = 'boutique.gerer';

    // — Planning des matchs —
    case PLANNING_LIRE = 'planning.lire';
    case PLANNING_GERER = 'planning.gerer';

    // — Saison —
    case SAISON_LIRE = 'saison.lire';
    case SAISON_CONFIGURER = 'saison.configurer';

    // — Le club —
    case CLUB_CONFIGURER = 'club.configurer';
    case UTILISATEUR_GERER = 'utilisateur.gerer';

    // — Diagnostic —
    case DIAGNOSTIC_ACCEDER = 'diagnostic.acceder';

    public function domaine(): DomainePermission
    {
        return match ($this) {
            self::EFFECTIF_LIRE, self::EFFECTIF_GERER,
            self::EFFECTIF_IMPORTER, self::EFFECTIF_SUPPRIMER => DomainePermission::EFFECTIF,

            self::PAIEMENT_LIRE, self::PAIEMENT_ENCAISSER,
            self::PAIEMENT_ATTESTER, self::LICENCE_VALIDER_FFF => DomainePermission::PAIEMENT,

            self::DOTATION_LIRE, self::DOTATION_GERER, self::DOTATION_CONFIGURER => DomainePermission::DOTATION,

            self::STOCK_LIRE, self::STOCK_GERER, self::STOCK_CONFIGURER => DomainePermission::STOCK,

            self::COMMANDE_LIRE, self::COMMANDE_GERER => DomainePermission::COMMANDE,

            self::CLE_LIRE, self::CLE_GERER => DomainePermission::CLE,

            self::BOUTIQUE_LIRE, self::BOUTIQUE_GERER => DomainePermission::BOUTIQUE,

            self::PLANNING_LIRE, self::PLANNING_GERER => DomainePermission::PLANNING,

            self::SAISON_LIRE, self::SAISON_CONFIGURER => DomainePermission::SAISON,

            self::CLUB_CONFIGURER, self::UTILISATEUR_GERER => DomainePermission::CLUB,

            self::DIAGNOSTIC_ACCEDER => DomainePermission::DIAGNOSTIC,
        };
    }

    public function libelle(): string
    {
        return match ($this) {
            self::EFFECTIF_LIRE => 'Consulter l\'effectif',
            self::EFFECTIF_GERER => 'Gérer l\'effectif',
            self::EFFECTIF_IMPORTER => 'Importer depuis FootClubs',
            self::EFFECTIF_SUPPRIMER => 'Supprimer une fiche',

            self::PAIEMENT_LIRE => 'Consulter les paiements',
            self::PAIEMENT_ENCAISSER => 'Enregistrer un paiement',
            self::PAIEMENT_ATTESTER => 'Émettre une attestation de paiement',
            self::LICENCE_VALIDER_FFF => 'Valider une licence dans FootClubs',

            self::DOTATION_LIRE => 'Consulter les dotations',
            self::DOTATION_GERER => 'Gérer les dotations',
            self::DOTATION_CONFIGURER => 'Configurer les kits',

            self::STOCK_LIRE => 'Consulter le stock',
            self::STOCK_GERER => 'Gérer le stock',
            self::STOCK_CONFIGURER => 'Configurer le stock',

            self::COMMANDE_LIRE => 'Consulter les commandes',
            self::COMMANDE_GERER => 'Gérer les commandes',

            self::CLE_LIRE => 'Consulter le registre des clés',
            self::CLE_GERER => 'Gérer les clés',

            self::BOUTIQUE_LIRE => 'Consulter la boutique',
            self::BOUTIQUE_GERER => 'Gérer la boutique',

            self::PLANNING_LIRE => 'Consulter le planning des matchs',
            self::PLANNING_GERER => 'Gérer le planning des matchs',

            self::SAISON_LIRE => 'Consulter les réglages de la saison',
            self::SAISON_CONFIGURER => 'Configurer les saisons',

            self::CLUB_CONFIGURER => 'Configurer le club',
            self::UTILISATEUR_GERER => 'Gérer les comptes et les rôles',

            self::DIAGNOSTIC_ACCEDER => 'Accéder au diagnostic',
        };
    }

    /** Ce que la case coche vraiment, quand le libellé ne suffit pas à s'en faire une idée. */
    public function description(): string
    {
        return match ($this) {
            self::EFFECTIF_LIRE => 'Voir les listes et les fiches des joueurs et des dirigeants.',
            self::EFFECTIF_GERER => 'Créer et modifier une fiche, corriger des coordonnées, envoyer les liens et les relances.',
            self::EFFECTIF_IMPORTER => 'Déposer un export XLSX FootClubs.',
            self::EFFECTIF_SUPPRIMER => 'Mode édition des listes — la sortie de secours d\'un import mal filtré, pas un outil courant.',

            self::PAIEMENT_LIRE => 'Voir ce que chacun a réglé et ce qu\'il reste dû.',
            self::PAIEMENT_ENCAISSER => 'Saisir un règlement, le supprimer, valider une licence sans encaissement.',
            self::PAIEMENT_ATTESTER => 'Émettre et renvoyer une attestation de paiement.',
            self::LICENCE_VALIDER_FFF => 'Marquer une licence comme signée dans FootClubs, et défaire ce marquage.',

            self::DOTATION_LIRE => 'Voir le suivi, les kits et le flocage.',
            self::DOTATION_GERER => 'Remettre un équipement, corriger une taille ou un flocage, relancer le calcul.',
            self::DOTATION_CONFIGURER => 'Créer et modifier les kits et leurs affectations.',

            self::STOCK_LIRE => 'Voir l\'inventaire, les articles et l\'historique des mouvements.',
            self::STOCK_GERER => 'Saisir un mouvement, le corriger, annoter une taille.',
            self::STOCK_CONFIGURER => 'Créer et archiver des articles, gérer catégories, fournisseurs, grilles et écoulement.',

            self::COMMANDE_LIRE => 'Voir les bons de commande et leur avancement.',
            self::COMMANDE_GERER => 'Générer une commande, la passer, réceptionner les lignes.',

            self::CLE_LIRE => 'Voir qui détient quoi et depuis quand.',
            self::CLE_GERER => 'Enregistrer remises et restitutions, lancer une campagne d\'attestations.',

            self::BOUTIQUE_LIRE => 'Voir l\'état de la boutique et son lien.',
            self::BOUTIQUE_GERER => 'Ouvrir ou fermer la boutique, changer le lien, annoncer aux licenciés.',

            self::PLANNING_LIRE => 'Voir les matchs à domicile de la saison.',
            self::PLANNING_GERER => 'Ajouter, modifier et synchroniser les matchs.',

            self::SAISON_LIRE => 'Voir les cotisations, les équipes et les documents à signer.',
            self::SAISON_CONFIGURER => 'Modifier les cotisations et les équipes, gérer les documents, créer une saison.',

            self::CLUB_CONFIGURER => 'Identité de l\'association, RIB, relances automatiques, catégories FFF et tailles.',
            self::UTILISATEUR_GERER => 'Créer des comptes, changer leurs rôles, définir les rôles eux-mêmes.',

            self::DIAGNOSTIC_ACCEDER => 'Purge des données, mode bêta, mails de test. Réservé au super-admin.',
        };
    }

    public function estEcriture(): bool
    {
        return !in_array($this, [
            self::EFFECTIF_LIRE,
            self::PAIEMENT_LIRE,
            self::DOTATION_LIRE,
            self::STOCK_LIRE,
            self::COMMANDE_LIRE,
            self::CLE_LIRE,
            self::BOUTIQUE_LIRE,
            self::PLANNING_LIRE,
            self::SAISON_LIRE,
        ], true);
    }

    /**
     * Les permissions que celle-ci accorde d'office — la seule hiérarchie du dispositif.
     *
     * Elle est **verticale et interne à un domaine** (`stock.gerer` implique `stock.lire`),
     * avec les rares passerelles que l'interface impose : les paiements se saisissent depuis
     * la fiche d'un licencié, une commande se lit avec les articles qu'elle porte. Sans ces
     * passerelles, un rôle pourrait encaisser sur une fiche qu'il n'a pas le droit d'ouvrir.
     *
     * Volontairement **pas** d'héritage entre rôles : c'est ce qui rend les droits
     * illisibles, et un droit qu'on ne sait pas expliquer est un droit qu'on n'ose plus
     * retirer.
     *
     * Ne rend que les implications **directes** : {@see \App\Service\Compte\PermissionCollector}
     * les déplie de proche en proche.
     *
     * @return list<self>
     */
    public function implique(): array
    {
        return match ($this) {
            self::EFFECTIF_GERER,
            self::EFFECTIF_IMPORTER,
            self::EFFECTIF_SUPPRIMER => [self::EFFECTIF_LIRE],

            self::PAIEMENT_LIRE => [self::EFFECTIF_LIRE],
            self::PAIEMENT_ENCAISSER,
            self::PAIEMENT_ATTESTER => [self::PAIEMENT_LIRE],
            self::LICENCE_VALIDER_FFF => [self::EFFECTIF_LIRE],

            self::DOTATION_GERER,
            self::DOTATION_CONFIGURER => [self::DOTATION_LIRE],

            self::STOCK_GERER,
            self::STOCK_CONFIGURER => [self::STOCK_LIRE],

            self::COMMANDE_LIRE => [self::STOCK_LIRE],
            self::COMMANDE_GERER => [self::COMMANDE_LIRE],

            self::CLE_GERER => [self::CLE_LIRE],
            self::BOUTIQUE_GERER => [self::BOUTIQUE_LIRE],
            self::PLANNING_GERER => [self::PLANNING_LIRE],
            self::SAISON_CONFIGURER => [self::SAISON_LIRE],

            default => [],
        };
    }

    /** @return array<string, list<self>> domaine → permissions, dans l'ordre d'affichage */
    public static function parDomaine(): array
    {
        $groupes = [];

        foreach (DomainePermission::cases() as $domaine) {
            $groupes[$domaine->value] = $domaine->permissions();
        }

        return $groupes;
    }

    /**
     * Filtre une liste de valeurs brutes (celles stockées en base) sur le catalogue.
     *
     * Une valeur inconnue est **ignorée**, jamais rejetée : une permission retirée du code
     * doit laisser les rôles qui la portaient utilisables, sinon un déploiement bloquerait
     * l'accès de tout le monde le temps qu'on nettoie la base.
     *
     * @param list<string> $valeurs
     *
     * @return list<self>
     */
    public static function depuisValeurs(array $valeurs): array
    {
        $permissions = [];

        foreach ($valeurs as $valeur) {
            $permission = self::tryFrom($valeur);

            if ($permission !== null) {
                $permissions[] = $permission;
            }
        }

        return $permissions;
    }
}
