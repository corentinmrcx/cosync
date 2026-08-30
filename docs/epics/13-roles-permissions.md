# Epic 13 — Rôles et permissions

> **Statut** : ✅ **livrée** le 29/08/2026 — voir « Ce qui a été livré » en fin de fichier
> **Source** : besoin exprimé par le club (ouvrir l'outil à la trésorière et à la présidente)
> **Dépend de** : rien. **Touche à** : `User`, tous les contrôleurs `Admin/`
> **Arbitrages tranchés** : cumul de rôles **oui**, rôles **créables par le club**, périmètre par équipe **plus tard** (§3)
>
> La référence à jour est le **§8 du CLAUDE.md**. Ce fichier garde le raisonnement qui a mené
> là — le « pourquoi », qui ne se relit pas dans le code.

---

## 1. Pourquoi cette epic existe

Aujourd'hui, **tout compte connecté peut tout faire**. `security.yaml` ne porte qu'une règle
(`^/ → ROLE_USER`) et les quelque 200 routes d'administration sont derrière elle, sans autre
distinction. Le seul cloisonnement existant est `ACCES_DIAGNOSTIC`, qui protège la purge et la
bascule bêta.

Ça tenait tant qu'une seule personne se connectait. Ce n'est plus le cas :

- la **trésorière** doit voir les licenciés et les dirigeants, savoir qui a payé, encaisser et
  émettre des attestations. Elle n'a rien à faire dans les kits de dotation ;
- la **présidente** veut voir l'état du club. Elle n'a aucune raison de modifier une dotation, ni
  de renommer une équipe ;
- la **configuration de la saison** (cotisations, équipes, documents à signer) reste au
  responsable foot.

Le risque n'est pas théorique. Un compte ouvert aujourd'hui à la trésorière donne accès au mode
édition des listes, à la purge des données de bêta, à la suppression d'un mouvement de stock et à
la reconfiguration des documents à signer. **Ouvrir un deuxième compte, en l'état, c'est confier
l'administration entière à quelqu'un qui ne l'a pas demandée.**

## 2. Ce qui existe déjà dans CoSync

| Élément | État |
|---|---|
| `User.roles` (tableau Symfony) | La colonne existe, **n'est jamais remplie**. `getRoles()` ajoute `ROLE_USER` à la volée |
| `security.yaml` | Une seule règle d'accès admin : `^/ → ROLE_USER` |
| `SuperAdminVoter` | Un seul attribut, `ACCES_DIAGNOSTIC` |
| `UserService::estSuperAdmin()` | ⚠️ Compare l'email du compte à `BetaModeService::getRedirectEmail()` |
| Écran utilisateurs | `/admin/club/utilisateurs` — création, mot de passe, suppression. Aucun droit à régler |

⚠️ **Le super-admin est aujourd'hui « celui dont l'email est égal à l'email de redirection
bêta ».** Un réglage d'exploitation décide donc de qui administre l'application : changer l'email
de redirection déplace le super-admin sans que personne ne l'ait voulu. Cette epic le corrige
(§5), c'est un préalable et non une amélioration annexe.

## 3. Périmètre

**Dans le périmètre**

- Un catalogue de permissions, en code.
- Des rôles, en base, créés et modifiés par le club.
- L'affectation de plusieurs rôles à un compte.
- L'application effective sur tous les contrôleurs d'administration, garantie par un contrôle CI.
- Le masquage des entrées de menu et des cartes de hub qu'un compte ne peut pas ouvrir.

**Hors périmètre — et à tenir**

- **Pas de périmètre par équipe.** « L'éducateur des U15 ne voit que ses U15 » n'est pas une
  permission : c'est un jugement porté sur un **sujet** (ce licencié-ci), donc un autre voter et
  un filtrage de **chaque** requête de liste. L'oubli d'un filtre y est invisible — un écran
  affiche simplement trop. C'est un second chantier, et rien dans le modèle ci-dessous ne
  l'empêche.
- **Pas de journal d'audit** (« qui a supprimé quoi »). Utile le jour où plusieurs personnes
  écrivent réellement, mais séparable et non bloquant.
- **Pas de multi-club.** `Role` prendrait un `club_id` le jour venu ; le catalogue, lui, resterait
  du code.
- **Pas d'invitation par mail ni de réinitialisation de mot de passe en libre-service.** Les
  comptes continuent d'être créés à la main par un administrateur.

## 4. Règles métier

### 1. Les permissions sont du code, les rôles sont de la donnée

C'est la règle qui porte toute l'epic.

Une **permission** existe parce qu'une ligne de code la vérifie. La créer depuis un écran
d'administration ne donnerait aucun droit : personne ne la lit. Un écran qui inventerait des
permissions produirait des rôles qui ne protègent rien — une flexibilité fausse, et pire qu'une
absence de flexibilité, parce qu'elle rassure. Le catalogue est donc une **enum PHP**, versionnée
avec le code qui l'applique (§7.5 du CLAUDE.md).

Un **rôle** est un paquet de permissions que le club compose. « Trésorière » ne veut pas dire la
même chose d'un club à l'autre, et le club de Soudron voudra un jour « Responsable arbitrage ».
C'est donc une **entité en base**, avec un écran de cases à cocher.

### 2. Pas d'héritage entre rôles — une seule implication, dans le domaine

`role_hierarchy` de Symfony et l'héritage de rôles en général rendent les droits illisibles :
« pourquoi la présidente peut-elle modifier le stock ? — parce que RESPONSABLE hérite
d'INTENDANT qui… ». Personne ne débogue ça dans un club de 200 licenciés, et un droit qu'on ne
sait pas expliquer est un droit qu'on n'ose plus retirer.

La seule hiérarchie retenue est **verticale et interne à un domaine** : `stock.gerer` implique
`stock.lire`. Elle est déclarée **sur la permission elle-même**, dépliée par le voter, et c'est
tout. Un rôle reste un **ensemble plat**, lisible d'un coup d'œil sur son écran.

Corollaire à ne pas défaire : cocher une permission d'écriture sans sa lecture doit rester
impossible à produire, pas seulement déconseillé. Sinon un rôle peut encaisser un paiement sur
une fiche qu'il n'a pas le droit d'ouvrir.

### 3. Refus par défaut

Une route d'administration qui ne déclare aucune permission est **refusée**, jamais autorisée. Le
sens du défaut est ce qui fait la différence entre un oubli visible (« je n'ai pas accès, il
manque un droit ») et un oubli invisible (une trésorière en lecture qui supprime une fiche
signée).

### 4. La couverture est tenue par le CI, pas par la discipline

Le modèle est facile ; ce qui échoue, c'est **la route qu'on oublie**. `bin/check-permissions.php`
échoue si une action publique d'un contrôleur de `src/Controller/Admin/` ne déclare aucune
permission, au niveau de la classe ou de la méthode. Même dispositif et même raison d'être que
`bin/check-prod-deps.php`, `bin/check-csp.php` et `bin/check-tables-scroll.php`.

### 5. Le super-admin passe partout, et il ne se supprime pas

Un compte `superAdmin` obtient toute permission sans en porter aucune. C'est la sortie de secours
qui empêche de se verrouiller dehors en décochant la mauvaise case — un club sans accès à ses
propres signatures n'a aucun recours. `UserService` porte déjà l'esprit de la règle (« le compte
super-admin ne peut pas être supprimé ») ; elle s'étend au retrait du drapeau lui-même : **il doit
toujours rester au moins un super-admin**.

### 6. Les rôles sont au niveau du club, pas de la saison

La trésorière l'est toutes les saisons. Cloisonner les rôles par saison obligerait à les
réaffecter chaque 1ᵉʳ juillet, et le premier oubli fermerait l'outil à quelqu'un en pleine
campagne d'inscriptions. Même raisonnement que pour `Detenteur` et `ClubSettings`.

### 7. Un module qu'on ne possède pas se masque ; une action qu'on ne peut pas jouer s'explique

Les cartes de hub et les liens de menu d'un module inaccessible **disparaissent** : sans ça, la
présidente clique sur six cartes pour obtenir six pages d'erreur.

Ce n'est pas une contradiction avec le §7.6 quater du CLAUDE.md, qui vaut **à l'intérieur** d'un
écran qu'on utilise : là, une action bloquée affiche son motif plutôt que de s'évanouir. La
frontière : on masque ce qu'on ne possède pas, on explique ce qu'on possède mais ne peut pas
jouer maintenant.

### 8. Personne ne perd d'accès le jour du déploiement

La migration affecte à **tous les comptes existants** un rôle qui couvre ce qu'ils faisaient la
veille. Un déploiement de sécurité qui commence par bloquer les gens en place se fait annuler
dans l'heure, et c'est le blocage qu'on retient, pas la sécurité.

## 5. Modèle de données

```php
// src/Enum/Permission.php — le catalogue, en code (règle 1)
enum Permission: string
{
    case EFFECTIF_LIRE  = 'effectif.lire';
    case EFFECTIF_GERER = 'effectif.gerer';
    // …

    public function domaine(): DomainePermission;   // pour le groupage de l'écran des rôles
    public function libelle(): string;
    public function estEcriture(): bool;
    /** @return list<self> — les permissions que celle-ci accorde d'office (règle 2) */
    public function implique(): array;
}

// src/Entity/Role.php — la composition, en base (règle 1)
Role
    id: int
    nom: string                  // "Trésorière"
    description: ?string
    permissions: json            // list<string> de valeurs de Permission
    systeme: bool                // livré par le seed ; renommable, non supprimable
    createdAt: \DateTimeImmutable

// src/Entity/User.php — modifications
User
    roles: Collection<Role>      // ManyToMany — union des permissions (arbitrage validé)
    superAdmin: bool             // règle 5 — remplace la dérivation depuis l'email bêta
```

⚠️ **`User.roles` change de nature.** La colonne `roles` (json, tableau Symfony) et la relation
ManyToMany ne peuvent pas porter le même nom. Renommer la colonne existante est **destructif** au
sens du §13 — même si elle est vide en pratique, il faut le vérifier en prod avant, pas après.
Le plus sûr est de nommer la relation `rolesClub` et de laisser `roles` en place, dé-mappée, comme
l'a été `cle_mouvement.season_id`.

**Pourquoi un `json` de permissions plutôt qu'une table de liaison** : les permissions ne sont pas
des lignes de référentiel, ce sont des valeurs d'enum. Une table `role_permission` avec des FK
vers quoi ? Une table de permissions en base recréerait exactement l'illusion que la règle 1
écarte. Le `json` est la forme honnête : une liste de valeurs, validée à l'écriture contre l'enum.

## 6. Le catalogue proposé

Un couple `lire` / `gerer` par domaine, plus quelques permissions d'écriture nommées quand le
geste est nettement plus lourd que le reste de son domaine.

| Domaine | Lecture | Écriture | Écrans concernés |
|---|---|---|---|
| Effectif | `effectif.lire` | `effectif.gerer` | `/admin/effectif/joueurs`, `/dirigeants`, fiches, coordonnées, envoi de liens, relances |
| — | | `effectif.importer` | `/admin/effectif/import` |
| — | | `effectif.supprimer` | mode édition des listes (aujourd'hui `ACCES_DIAGNOSTIC`) |
| Paiements | `paiement.lire` | `paiement.encaisser` | modale de paiement, suppression d'un paiement |
| — | | `paiement.attester` | `/admin/attestations-paiement` |
| — | | `licence.valider_fff` | validation FootClubs, à l'unité et groupée |
| Dotations | `dotation.lire` | `dotation.gerer` | suivi, remises, tailles, flocage |
| — | | `dotation.configurer` | modèles, affectations, écoulement |
| Stock | `stock.lire` | `stock.gerer` | mouvements, notes, inventaire |
| — | | `stock.configurer` | articles, catégories, fournisseurs, grilles de tailles |
| Commandes | `commande.lire` | `commande.gerer` | `/admin/commandes` |
| Clés | `cle.lire` | `cle.gerer` | registre, campagne d'attestations |
| Boutique | `boutique.lire` | `boutique.gerer` | ouverture, lien, annonce |
| Saison | `saison.lire` | `saison.configurer` | cotisations, équipes, documents à signer, création de saison |
| Club | — | `club.configurer` | identité, RIB, relances, catégories FFF, tailles |
| — | — | `utilisateur.gerer` | comptes **et rôles** |
| Diagnostic | — | `diagnostic.acceder` | purge, bascule bêta, mails de test |

`diagnostic.acceder` reste de fait réservé au super-admin : la case existe, mais ces écrans
détruisent des données de bêta.

### Rôles livrés par le seed

Idempotents, façon `SeedReferentialCommand`, et **modifiables ensuite** — ce sont des points de
départ, pas des rôles figés.

| Rôle | Permissions |
|---|---|
| **Responsable foot** | tout sauf `utilisateur.gerer` et `diagnostic.acceder` |
| **Trésorière** | `effectif.lire`, `paiement.*`, `licence.valider_fff`, `saison.lire` |
| **Présidente** | toutes les `*.lire` |
| **Intendant** | `stock.*`, `commande.*`, `dotation.*`, `effectif.lire` |

## 7. Services & écrans

| Classe | Rôle |
|---|---|
| `App\Enum\Permission` | Le catalogue, ses libellés, ses implications |
| `App\Enum\DomainePermission` | Le groupage de l'écran des rôles |
| `App\Security\Voter\PermissionVoter` | **Le point d'application unique.** Déplie les implications, court-circuite pour le super-admin |
| `App\Service\Compte\RoleService` | Créer, renommer, modifier les permissions, supprimer un rôle. Refuse la suppression d'un rôle `systeme` ou encore affecté |
| `App\Service\Compte\PermissionCollector` | Union des permissions d'un `User`, avec implications — le seul calcul, appelé par le voter |
| `App\Service\Compte\UserService` | Étendu : affectation des rôles, drapeau super-admin, règle « au moins un super-admin » |

**Écrans**

1. `/admin/club/roles` — liste des rôles, nombre de comptes affectés à chacun.
2. `/admin/club/roles/{id}` — cases à cocher groupées par domaine, lecture et écriture côte à
   côte. Cocher une écriture coche sa lecture et la verrouille (règle 2).
3. `/admin/club/utilisateurs` — une colonne « Rôles », un sélecteur multiple au formulaire.
4. Twig : `is_granted('effectif.lire')` dans les templates. Les includes `hub-card` et
   `quicklink` prennent une `permission` optionnelle et ne rendent rien sans elle (règle 7).

## 8. Application sur les contrôleurs

```php
#[Route('/admin/effectif/joueurs', name: 'admin_licencies_')]
#[IsGranted(Permission::EFFECTIF_LIRE->value)]          // valide en constante — PHP ≥ 8.2
class LicencieController extends AbstractController
{
    #[Route('/{uuid}/ajouter-paiement', methods: ['POST'])]
    #[IsGranted(Permission::PAIEMENT_ENCAISSER->value)] // l'écriture se redéclare à la méthode
    public function ajouterPaiement(...): Response {}
}
```

Le motif : **la lecture du domaine au niveau de la classe, l'écriture à la méthode**. Il rend
`bin/check-permissions.php` simple à écrire — une classe non annotée dont une méthode ne l'est pas
non plus est une erreur — et il fait que l'oubli le plus probable (une nouvelle action d'écriture)
tombe sur la lecture du domaine, donc du côté restrictif.

## 9. Points de jonction avec l'existant

| Existant | Ce qui change |
|---|---|
| `SuperAdminVoter` | Devient `PermissionVoter`. `ACCES_DIAGNOSTIC` devient `diagnostic.acceder` ; les 6 usages actuels suivent |
| `UserService::estSuperAdmin()` | Lit `User.superAdmin`, plus l'email de redirection bêta. `BetaModeService` n'est plus une dépendance de la sécurité |
| Mode édition des listes | Passe de `ACCES_DIAGNOSTIC` à `effectif.supprimer` — un droit qui peut enfin être donné sans donner la purge |
| `FicheActionsResolver` | Filtre les actions sur les permissions : c'est déjà lui qui décide ce qu'une fiche propose |
| `security.yaml` | Inchangé — `^/ → ROLE_USER` reste le portail d'entrée, les permissions se jouent au-dessus |
| Templates de hub | `dashboard.html.twig`, `saison/dashboard`, `club/index`, `stock/dashboard`, `dotations/dashboard`, `effectif/index`, `navbar` |

## 10. Bascule (règle 8)

Pattern *expand / backfill / contract* du §13 :

1. **Expand** — création de `role` et `user_role`, ajout de `user.super_admin` (`DEFAULT false`).
2. **Backfill**, dans la même migration :
   - insertion des rôles système ;
   - affectation de **« Responsable foot » à tous les comptes existants** ;
   - `super_admin = true` sur le compte dont l'email vaut l'email de redirection bêta au moment
     de la migration — la valeur est **écrite en dur dans le SQL de la migration**, relue avant
     déploiement, et non lue depuis la configuration au moment de l'exécution ;
   - garde-fou : la migration **échoue** si ce backfill ne désigne aucun compte. Une base sans
     super-admin est une base dont plus personne ne peut modifier les droits.
3. **Contract** — rien à contraindre. La colonne `user.roles` est laissée en place, dé-mappée.

Aucun `DROP`, aucune perte de données.

## 11. Lots livrables

| Lot | Contenu | Sans lui |
|---|---|---|
| **1** | `Permission`, `PermissionVoter`, `PermissionCollector`, `Role`, migration, seed, backfill | rien ne tient |
| **2** | `#[IsGranted]` sur les ~30 contrôleurs + `bin/check-permissions.php` + job CI | le modèle existe mais ne protège rien |
| **3** | Écrans `/admin/club/roles` + colonne rôles sur les utilisateurs | les rôles ne sont modifiables qu'en base |
| **4** | Masquage des hubs, cartes, quicklinks et navbar | ça marche, mais on navigue à travers des 403 |
| **5** | Bascule de `ACCES_DIAGNOSTIC` et retrait du couplage à l'email bêta | le défaut du §2 subsiste |

Les lots 1 et 2 vont ensemble : livrer le 1 seul crée un modèle que personne n'applique, et c'est
la pire des situations — l'illusion d'être protégé.

## 12. Points à trancher avant de coder

1. **`effectif.supprimer` doit-il rester au super-admin seul ?** Le mode édition est décrit dans le
   CLAUDE.md comme « la sortie de secours d'un import mal filtré, pas un outil de gestion
   courante ». Le sortir de `ACCES_DIAGNOSTIC` le rend délégable — c'est l'intention, mais ça
   mérite d'être voulu explicitement.
2. **La trésorière doit-elle pouvoir valider une licence dans FootClubs ?** Le geste est fédéral,
   pas financier. Il est dans son rôle par défaut ci-dessus parce qu'il suit immédiatement
   l'encaissement, mais c'est discutable.
3. **Le lien vers la documentation et `/admin/profil`** restent ouverts à tous — à confirmer.
4. **Un rôle sans aucune permission** doit-il pouvoir exister ? Oui à mon sens (un rôle en cours de
   composition), mais un compte qui n'aurait que celui-là se connecte sur un tableau de bord vide.
   Prévoir le message qui l'explique plutôt qu'un écran nu.

---

## 13. Ce qui a été livré *(29/08/2026)*

Les cinq lots du §11 sont en place. Ce qui **s'écarte de la spec ci-dessus**, et pourquoi :

| Écart | Raison |
|---|---|
| L'entité s'appelle **`RoleAcces`** (table `role_acces`), pas `Role` | `Dirigeant.role` et `DirigeantRole` existent déjà et désignent la **fonction** d'un dirigeant, pas ses droits. L'epic 05 va renforcer cette notion : deux « Role » se seraient confondus à la première relecture |
| La relation s'appelle **`rolesAcces`**, pas `rolesClub` | Même raison, et la symétrie avec le nom de l'entité |
| **`user.roles` est laissée strictement intacte** | Elle est exposée par `UserInterface` et sert la règle `^/ → ROLE_USER`. Aucun renommage, donc aucun risque §13 — le point d'attention du §5 est sans objet |
| Un attribut **`#[AccesLibre('raison')]`** a été ajouté au dispositif | La spec disait « refus par défaut » sans dire comment déclarer les 8 exceptions réelles (hubs, bascule de saison, profil, documentation). Sans lui, le contrôle CI aurait forcé à inventer une fausse permission de navigation — ou à s'en remettre à la relecture. L'exception doit s'écrire pour exister |
| Un domaine **`planning`** a été ajouté au catalogue | `OutilsController` et `PlanningMatchController` sont arrivés dans l'arbre pendant le chantier. Les protéger à la construction coûtait moins que d'y revenir |
| `peutChangerLeMotDePasseDe()` ne prend plus l'auteur | La règle utile est « on ne prend pas le mot de passe d'un super-admin ». La conditionner à *être* super-admin aurait rendu `utilisateur.gerer` inutilisable : sans réinitialisation en libre-service, un mot de passe oublié n'a pas d'autre issue |
| Un **bouton de bascule super-admin** a été ajouté à l'écran des comptes | Sans lui, le drapeau n'était réglable qu'en base — et le jour où la personne quitte le club, plus personne ne peut le reprendre. Réservé aux super-admins, avec le garde-fou du dernier |
| **Deux rôles livrés** au lieu de quatre, et **pas de description** sur un rôle | Livrer Présidence et Intendance revenait à deviner l'organigramme du club, et un rôle livré inutilisé encombre l'écran sans pouvoir être supprimé. La description, elle, redit en moins bien ce que les cases cochées montrent déjà — et se périme dès qu'on les change |

**Les quatre points du §12, tranchés :**

1. `effectif.supprimer` **sort** de `ACCES_DIAGNOSTIC` et devient délégable. C'était l'intention ;
   le mode édition n'est plus lié à la purge des données de bêta.
2. La trésorerie **garde** `licence.valider_fff` par défaut : le geste suit immédiatement
   l'encaissement. Décochable en deux clics si le club préfère.
3. `/admin/profil` et `/admin/documentation` restent ouverts, en `#[AccesLibre]`.
4. Un rôle **sans aucune permission** peut exister ; la liste des utilisateurs signale en rouge
   un compte qui n'en porte aucun (« ce compte ne voit rien »).

**Vérification.** 913 tests passent, dont trois fichiers dédiés :
`PermissionCollectorTest` (les implications), `PermissionsAccesTest` (ce que les écrans ferment
réellement), `RoleAccesEcranTest` (le formulaire, dont le relais des cases verrouillées).
`bin/check-permissions.php` annonce 8 exceptions assumées et échoue sur une action non déclarée.

**Reste ouvert**, tel que le §3 le prévoyait : le périmètre par équipe, le journal d'audit, le
multi-club.
