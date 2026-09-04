# CLAUDE.md — CoSync

Cockpit interne du Club de Football de Soudron (Marne). **Add-on** de FootClubs, pas un
remplacement : FootClubs fait foi sur l'identité FFF, CoSync gère la vie interne du club
(dossiers d'inscription, signatures, paiements, stock, dotations, clés, planning des matchs).

Ce fichier ne contient que les **règles** — ce qu'il faut savoir avant d'écrire une ligne de code.
Le raisonnement derrière chaque invariant métier vit dans
**[docs/decisions-metier.md](docs/decisions-metier.md)** : l'ouvrir sur le domaine qu'on
s'apprête à toucher, avant de « corriger » quoi que ce soit qui paraît bizarre.

---

## 1. ⚠️ La prod contient des données irremplaçables

Signatures manuscrites, PDF signés, autorisations parentales, encaissements HelloAsso. Une
signature perdue se redemande au licencié ; un encaissement perdu se retrouve à la main dans les
relevés HelloAsso.

- ❌ Jamais `db-reset`, `doctrine:database:drop`, `doctrine:schema:update --force` sur une base
  contenant des données
- ❌ Jamais ré-éditer une migration déjà déployée (fait diverger les bases) → **nouvelle** migration
- ❌ Jamais proposer un `DROP` de colonne/table sans avoir annoncé la **perte de données**
- ❌ Jamais `ADD COLUMN ... NOT NULL` sur une table remplie → **expand / backfill / contract**
  (colonne nullable → `UPDATE` de backfill → `NOT NULL`)
- ✅ Toute modif d'entité → `make:migration`, **relire le SQL généré** (`make prod-migrate-dry`),
  tester sur un dump prod restauré en local, puis `make test`
- ✅ Déploiement : `make prod-deploy` (enchaîne `prod-backup` puis `prod-migrate`)

**Une reprise de données du club n'est jamais une migration.** Un référentiel vaut pour toute base ;
l'inventaire du local de Soudron n'existe qu'une fois — porté par une migration, il serait rejoué
sur chaque base neuve, à commencer par celle de la CI. C'est une **commande console idempotente**,
lancée à la main une fois sur la prod (`InventaireAout2026Command` est le modèle : garde-fous avant
la première écriture, chaque création gardée par « n'existe pas déjà »). Critère de tri : *cette
donnée aurait-elle un sens dans une base neuve ?*

Sauvegardes : `app:db:backup` chaque nuit à 02h30 (local `var/backups/`, rétention 30 j + copie
Drive). `make prod-backup` / `prod-backup-list` / `prod-restore FILE=…`. **Un backup jamais restauré
n'est pas un backup** — répétition de restauration au moins une fois par saison. Ne sont *pas*
couverts : Nginx Proxy Manager, le volume `cosync_signatures`, le Drive lui-même.

---

## 2. Stack

| Couche | Choix |
|---|---|
| Framework | Symfony 7.x, monolithe |
| BDD / ORM | PostgreSQL / Doctrine |
| Front | Twig + CSS natif + Alpine.js |
| PDF | DomPDF · **XLSX** PhpSpreadsheet |
| Drive | Google Drive API (Service Account) |
| Mail | Symfony Mailer |
| Auth | Security Bundle côté admin ; UUID dans l'URL côté public (sans session ni login) |

**Pas de React, pas de SPA, pas d'API REST.** Symfony rend les pages, Alpine gère l'interactivité.

Philosophie : aller à l'essentiel, zéro saisie libre non nécessaire (sélecteurs partout), tout
cloisonné par saison.

---

## 3. Architecture

```
src/
├── Controller/Admin|Public/   Trois lignes : lire la requête, appeler un service, rendre
├── Entity/                    Doctrine uniquement, zéro logique métier
├── Repository/                Requêtes Doctrine custom
├── Service/<Domaine>/         TOUTE la logique métier
├── Form/  DTO/  Enum/  EventListener/
templates/  admin/ public/ pdf/ email/ components/
assets/styles/  app.css (variables + @import) · components/ · pages/
```

`src/Service/` — **un dossier par domaine métier, pas par couche technique** : `Licencie/`,
`Dirigeant/`, `Inscription/`, `Dotation/`, `Stock/`, `Cle/`, `Saison/`, `Referentiel/`, `Compte/`,
`Document/`, `Payment/`, `Import/`, `Planning/`, `Relance/`, `Boutique/`, `Effectif/`, `Mail/`,
`Pdf/`, `Drive/`, `Ops/`, `Ui/`. **Aucun service à la racine** — si le domaine n'est pas évident,
la classe en fait trop.

---

## 4. Règles de code

- **Zéro logique métier dans un contrôleur.** Ni requête Doctrine, ni SQL, ni boucle de traitement.
- **`declare(strict_types=1)` partout.** Tout argument typé, tout retour typé. Jamais de `mixed`.
- **Un DTO, jamais une `Request` brute** passée à un service.
- **Enums, jamais de magic string** (`LicenceStatus::FORM_COMPLETED`, pas `'form_completed'`).
- **Une classe = une seule raison de changer.** Si tu te demandes « pourquoi ce code est là ? »,
  c'est qu'elle en fait trop.

### Nommage

| Élément | Convention |
|---|---|
| Entités, Enums, DTOs | PascalCase — DTO d'entrée de formulaire suffixé `Data` |
| Controllers | PascalCase + `Controller` |
| Routes | snake_case préfixé : `admin_licencies_list`, `public_inscription_show` |
| Templates | snake_case : `admin/licencies/list.html.twig` |
| Variables Twig | camelCase |

**Le suffixe d'un service dit son rôle**, `Service` n'étant que le défaut : `Resolver` (choisit une
valeur parmi plusieurs règles), `Factory` (construit un DTO depuis une `Request`), `Presenter` (met
en forme, n'écrit rien), `Synchronizer` (aligne un état sur un autre, idempotent), `Sync` (archive
vers Drive), `Renderer`/`Storage`/`Encoder` (infra), `Context`/`Guard`/`Collector`/`Filter`.
**Une classe sans suffixe de rôle est une erreur**, sauf référentiel de constantes.

---

## 5. Front

### Twig & Alpine

- Un composant Twig n'a **pas de logique métier** : il affiche ce qu'on lui passe. Pas de
  conditionnelle complexe dans un template — ça va dans un service ou un helper.
- Alpine : les données dans `x-data`, le HTML affiche. Dès que ça se complique, composant JS séparé
  (`x-data="inscriptionForm()"`).
- Un article de stock se désigne **toujours** par `nom · marque · couleur` via
  `components/_stock_item_label.html.twig` (`article.label()` / `article.details()`) : plusieurs
  `StockItem` partagent le même nom et ne se distinguent que par là.

### CSS

CSS natif, sans framework. **Flexbox/Grid pour toute mise en page** — pas de float, pas de position
absolue pour placer des blocs. Un fichier par template dans `pages/`, le réutilisable dans
`components/`, `app.css` ne porte que les variables `:root` et les `@import`.

**Chaque classe est préfixée par sa page ou son composant**, tirets simples, jamais de BEM
(`.login-card-header`, `.badge-validated`). Jamais de couleur en dur — toujours une variable de
`app.css`. Fond blanc dominant, le rouge club (`--color-primary`) est un accent : CTA, header,
badges actifs. Texte en `--color-text-body`, noir pur réservé aux titres.

### Boutons — la variante dit ce que l'action fait, pas son importance

| Variante | Registre |
|---|---|
| `btn-primary` | l'action attendue de l'écran — **une seule par zone** |
| `btn-secondary` | autre action, neutre et sans risque |
| `btn-ghost` | **fermer sans rien faire** (Annuler d'un formulaire, ✕) |
| `btn-danger` | **perte ou revirement de données** (Supprimer, annuler une remise) |

« Annuler » n'est `btn-danger` que s'il **défait un état enregistré**. Les boutons d'une même rangée
partagent leur taille. Pied de **page** de formulaire **centré** ; pied de **modale** aligné à
droite. **Annuler mène là où mène Enregistrer** (la même route que la redirection du contrôleur).

### Une fiche met en avant *une* action

Un seul bouton mis en avant — la **première étape non franchie du parcours**, choisie **côté
serveur** (c'est du métier : `FicheActionsResolver` rend un `FicheActions`). Le reste dans un menu
`⋯`, l'action destructive en bas, séparée d'un filet. Un seul balisage pour les deux contextes
(`admin/licencies/_action.html.twig`, paramètre `contexte`). Une action **injouable** (pas
d'adresse email, dossier incomplet) affiche son **motif** au lieu de disparaître en silence.

### Mobile — la page ne défile jamais horizontalement

L'outil se consulte au local, téléphone en main. Rien ne dépasse de la largeur de l'écran ; ce qui
est trop large porte **son propre** défilement.

- Un `<table class="table">` vit **toujours** dans un `.table-wrapper` (variantes :
  `table-wrapper-nu` dans une carte, `table-cartes` pour l'empilement).
- Les listes qu'on **consulte** passent en cartes sous 640 px (`.table-cartes` + `data-label`,
  `carte-titre`, `carte-meta`). Les tableaux **denses** (mouvements de stock, commandes) gardent
  leur défilement : empilés, ils perdent la comparaison ligne à ligne.
- Cause n°1 des débordements : un enfant de grille/flex vaut `min-width: auto`. Réflexes —
  `minmax(0, 1fr)` plutôt que `1fr`, `min-width: 0` sur ce qui doit rétrécir, `flex-wrap: wrap` sur
  toute rangée `space-between` titre + bouton.
- `.main-content` est en `overflow-x: hidden` : **garde-fou, pas solution** — s'en servir pour
  masquer un contenu large le rend inatteignable.
- Deux points de rupture : **640 px** et **1024 px**. Ne pas en ajouter.

---

## 6. Sécurité & droits

**Les droits sont du code, les rôles sont de la donnée.** Une permission existe parce qu'une ligne
de code la vérifie → catalogue dans l'enum `Permission`, versionné avec le code. Un rôle est un
paquet de permissions composé par le club → entité `RoleAcces`, éditable dans `/admin/club/roles`.
❌ Jamais de table `permission` en base ni d'écran qui inventerait des permissions : il produirait
des rôles qui ne protègent rien.

- **Toute action de `src/Controller/Admin/` déclare `#[IsGranted(Permission::X->value)]`** ou, pour
  une exception assumée, `#[AccesLibre('raison')]` — sinon elle est ouverte à tout compte connecté.
  Motif : **lecture du domaine sur la classe, écriture sur la méthode**, pour que l'oubli tombe du
  côté restrictif. `AccesLibre` ne vaut que pour un point de navigation (hub, bascule de saison) ou
  un écran qui ne parle que du compte connecté.
- **Un bouton ou lien se garde par sa route** : `{% if peut_acceder('admin_stock_items_new') %}`,
  jamais un `is_granted()` qui recopie le droit (il se trompe, et surtout il ne suit pas quand la
  permission de l'action change). `is_granted()` reste bon pour ce qui n'est pas un lien : une
  colonne, un bloc d'info. Une porte de hub : `possede_un_droit('domaine')`.
  ⚠️ Masquer n'est pas protéger — le refus reste celui du contrôleur.
- **Une permission d'écriture entraîne sa lecture** (`PermissionCollector::completer()`). Pas
  d'héritage entre rôles ; la seule hiérarchie est interne à un domaine (`Permission::implique()`).
- **La maille d'une permission, c'est le geste, pas l'écran de menu.** Devant une permission
  fourre-tout : *deux fonctions différentes du club voudraient-elles l'une sans l'autre ?*
- **`User.superAdmin` est un fait porté par le compte**, jamais dérivé d'un réglage d'exploitation
  (`DIAG_EMAIL` ou autre). Il en reste toujours au moins un.

### RGPD & accès public

- Lien `/inscription/{uuid}` valide **30 jours**, invalidé après soumission. Aucune donnée sensible
  dans l'URL. L'UUID n'est **jamais** régénéré à un renvoi (casserait les liens déjà distribués).
- **Non collectés** : données médicales, n° de sécurité sociale, attestation de conduite parents.
- Aucune donnée bancaire ne transite : le paiement en ligne est entièrement délégué à HelloAsso.
- Signatures et PDF : jamais conservés en local une fois montés sur Drive.
- Secrets en variables d'env, jamais dans le code : `GOOGLE_DRIVE_CREDENTIALS_JSON`,
  `GOOGLE_DRIVE_FOLDER_ID`, `MAILER_DSN`, `APP_SECRET`, `DATABASE_URL`.

---

## 7. Pièges connus — coûteux et silencieux

Chacun a déjà cassé quelque chose sans lever d'erreur.

- **`Category::isJeune()`, jamais `isEcoleFoot`** pour décider d'un comportement : `is_ecole_foot`
  est saisi en admin mais aucune logique ne le lit.
- **« A payé » se lit `LicenceStatus::estSolde()` / `DossierClub::estSoldee()`**, jamais
  `=== VALIDATED` : payé et validé-FootClubs sont deux faits distincts.
- **ICU réduit à l'anglais dans l'image PHP** : `NumberFormatter::SPELLOUT` et
  `IntlDateFormatter('fr_FR')` rendent de l'anglais **sans erreur**. Utiliser
  `MontantEnLettresFormatter` et `DateFrancaiseFormatter`, écrits à la main.
- **DomPDF** : `|capitalize` de Twig, jamais `text-transform: capitalize` (appliqué à chaque mot).
  Les mises en page serrées se font en positionnement absolu, pas en `<table>` — le défaut est
  invisible à l'écran et ne se voit qu'à l'impression.
- **`SeasonContext` doit rester utilisable hors requête HTTP** : les crons rendent du Twig et
  `AppExtension` expose la saison en variable globale. Pas de `RequestStack::getSession()` sans garde.
- **Une classe de `require-dev` utilisée dans `src/`, `bin/`, `config/` ou `public/` casse en
  production uniquement** (image construite en `--no-dev`).
- **`drivePath` commençant par `/` = fichier encore local**, pas encore sur Drive.
- **Un mail part par `ClubMailer::envoyer()` avec son `TypeMail`**, jamais autrement : c'est ce qui
  alimente le journal `EnvoiMail` et l'ancre des relances.
- **Aucun mail automatique à l'import.** L'envoi de liens est une décision prise sur un écran dédié.
- **HelloAsso** : jamais de `Transaction` sur la foi d'une `returnUrl` ou du corps d'une
  notification — relire l'état auprès de l'API (`state === Authorized`), de façon idempotente.
- **Les verrous `*Manuel`** (email, téléphone, nature, taille/personnalisation de dotation, match
  détaché de la FFF) disent « un humain a corrigé, l'automate n'y touche plus ». Chacun a une
  **sortie** pour le relâcher — ne jamais en ajouter un sans elle.

---

## 8. Garde-fous CI

`make check` = `test` + `stan` + `lint` + `gardes`. `make fix` corrige ce qui l'est.

| Script | Refuse |
|---|---|
| `bin/check-permissions.php` | une action Admin sans `#[IsGranted]` ni `#[AccesLibre]` |
| `bin/check-boutons.php` | un `path()` vers une route d'écriture sans garde — exception : `{# droits-verifies-cote-serveur: raison #}` |
| `bin/check-tables-scroll.php` | un `<table class="table">` hors `.table-wrapper` |
| `bin/check-prod-deps.php` | un paquet `require-dev` utilisé par du code de prod |
| `bin/check-csp.php`, `bin/check-css-imports.php` | inline script/style non déclaré, CSS non importé |

---

## 9. Git

| Branche | Push direct |
|---|---|
| `development` | ✅ tout le dev actif |
| `main` | ❌ PR obligatoire |
| `production` | ❌ PR obligatoire — le merge déclenche le déploiement |

`development` → PR → `main` → PR → `production`. Merge **toujours `--no-ff`**. Une PR = une feature
ou un fix. Nommage : `type: description courte` (`feat`, `fix`, `refactor`, `style`, `chore`).

**Jamais** : commit direct sur `main`/`production`, `push --force` sur une branche protégée,
plusieurs features sans rapport dans un même commit.
