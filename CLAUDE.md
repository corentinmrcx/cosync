# CLAUDE.md — CoSync

> Ce fichier est la référence absolue du projet. Lis-le intégralement avant toute action.
> En cas de doute sur une décision d'architecture ou de style, ce document a raison.

---

## ⚠️ L'application est en production avec des données réelles

La base contient des **signatures manuscrites**, des **PDF signés**, des **autorisations parentales**
et des **encaissements HelloAsso**. Rien de tout cela n'est reproductible : une signature perdue se
redemande au licencié, un encaissement perdu se retrouve à la main dans les relevés HelloAsso.

L'époque de la bêta où l'on pouvait faire un `db-reset`, supprimer une migration et repartir d'une
base vide est **terminée**. Conséquences directes, non négociables :

- ❌ Jamais de `db-reset`, `doctrine:database:drop` ni `doctrine:schema:update --force` sur la prod
- ❌ Jamais de modification d'une migration déjà déployée — on corrige par une **nouvelle** migration
- ✅ Tout changement de schéma passe par une migration **relue**, **testée sur une copie des données
  de prod**, et **précédée d'un dump**
- ✅ Tout `DROP` de colonne ou de table est signalé explicitement comme une **perte de données**
  avant d'être proposé — jamais appliqué sans validation humaine

Le rituel complet est décrit au **§13**. En cas de doute sur une évolution de schéma : relire §13
avant d'écrire la moindre ligne.

---

## 1. Contexte & Vision

**CoSync** est un cockpit interne pour le Club de Football de Soudron (Marne).
Ce n'est **pas** un remplacement de FootClubs (outil fédéral FFF). C'est un **add-on** qui gère ce que FootClubs ne gère pas : la vie interne du club.

### Ce que l'outil fait
- Importer la liste des licenciés depuis un export XLSX FootClubs (upsert idempotent)
- Envoyer un lien unique par mail à chaque licencié/parent pour collecter les données club
- Collecter via formulaire mobile-first : tailles équipement, autorisations, signature règlement
- Générer un PDF du règlement signé et l'archiver automatiquement sur Google Drive
- Donner à l'admin un tableau de bord clair : statut formulaire + statut paiement de chaque licencié
- Permettre à l'admin de confirmer manuellement les paiements reçus
- Gérer le stock d'équipements et les dotations par joueur

### Ce que l'outil ne fait PAS
- Ne recrée pas FootClubs (pas de gestion de licences FFF)
- Ne stocke pas les données médicales (hors scope RGPD V1)
- Ne gère pas l'attestation de conduite parentsSection F (trop sensible pour V1)
- Ne traite aucune donnée bancaire : le paiement en ligne est intégralement délégué à HelloAsso Checkout
  (aucun numéro de carte ne transite ni n'est stocké dans CoSync)

### Philosophie
- **Aller à l'essentiel.** Si une fonctionnalité peut attendre la V2, elle attend.
- **Zéro saisie libre non nécessaire.** Sélecteurs partout où c'est possible.
- **Source de vérité claire.** FootClubs = identité FFF. CoSync = vie interne club.
- **Multi-saisons dès le départ.** Toutes les données sont cloisonnées par `season_id`.

---

## 2. Stack Technique

| Couche | Technologie |
|---|---|
| Framework | Symfony 7.x (monolithe) |
| Base de données | PostgreSQL |
| ORM | Doctrine |
| Frontend | Twig + CSS natif + Alpine.js |
| Interactivité légère | Alpine.js (multi-step form, toggles conditionnels) |
| PDF | DomPDF via bundle Symfony |
| Import XLSX | PhpSpreadsheet |
| Drive | Google Drive API (Service Account) |
| Mail | Symfony Mailer (SMTP Gmail ou Brevo) |
| Auth admin | Symfony Security Bundle (email/password) |
| Auth public | UUID v4 dans l'URL (sans session, sans login) |

**Pas de React. Pas d'API REST. Pas de SPA.** Symfony rend les pages, Alpine.js gère l'interactivité côté client quand nécessaire. C'est suffisant pour ce projet.

---

## 3. Architecture des Dossiers

```
src/
├── Controller/
│   ├── Admin/          # Tout ce qui est derrière l'auth admin
│   └── Public/         # Formulaire public /inscription/{uuid}
├── Entity/             # Entités Doctrine uniquement, zéro logique métier
├── Repository/         # Requêtes Doctrine custom
├── Service/            # TOUTE la logique métier ici
│   ├── Import/         # ImportService, DataSanitizer
│   ├── Form/           # InscriptionFormService
│   ├── Pdf/            # PdfGeneratorService
│   ├── Drive/          # DriveUploaderService
│   ├── Mail/           # MailerService
│   └── Stock/          # StockService
├── Form/               # Symfony Form Types
├── DTO/                # Data Transfer Objects (entrées/sorties propres)
├── Enum/               # PaymentMode, LicenceStatus, StockMovementType...
└── EventListener/      # Listeners Doctrine si nécessaire

templates/
├── admin/              # Toutes les vues admin
├── public/             # Formulaire d'inscription public
├── pdf/                # Templates Twig utilisés par DomPDF
└── email/              # Templates mails
```

---

## 4. Modèle de Données

> ⚠️ Cette section décrit le modèle **réel**, pas l'intention d'origine. Les écarts avec la V1
> initiale sont signalés en commentaire : ils sont assumés, pas à « corriger ».

### Season
```php
id: int
label: string                    // ex: "2025-2026"
cotisation_defaut: int           // remplace base_costs ; Team.cotisation prime si renseignée
attestation_cle_text: ?text
created_at: datetime
```
Il n'y a **pas de saison active globale** : chaque admin travaille dans la saison de son
choix (`User.selectedSeason` + session, via `SeasonContext`). Le montant dû est résolu par
`CotisationResolver` — équipe d'abord, défaut de la saison ensuite.

Les colonnes `reglement_text` et `reglement_dirigeant_text`, non mappées depuis la bascule vers
`DocumentSignable`, ont été supprimées par `Version20260809103000` — avec celles de signature de
`dossier_club` et `dirigeant`. La migration recompte les données historiques et refuse de
s'appliquer si une seule n'a pas son équivalent dans `document_signature` / `document_signable`.

### Category (référentiel FFF, fixe)
```php
id: int
code: string           // "U6", "U7", ..., "U13", "U15", "SENIOR"
label: string
is_ecole_foot: bool    // saisi en admin, mais AUCUNE logique métier ne s'en sert
min_year: int          // jamais renseigné — colonne morte
max_year: int          // jamais renseigné — colonne morte
```
C'est **`Category::isJeune()`** (`str_starts_with($code, 'U')`) qui décide de l'affichage des
autorisations parentales dans le formulaire public — donc U14→U19 sont traités comme des jeunes,
contrairement à `is_ecole_foot`. Utiliser `isJeune()`, jamais `isEcoleFoot`.

### Team (équipe sportive interne)
```php
id: int
name: string           // "U15 A", "Séniors 1", "Loisirs"
season: Season
```

### Licencie
```php
uuid: Uuid             // clé publique, dans l'URL du formulaire
num_licence: ?string   // clé d'upsert à l'import ; nullable (fallback nom+prénom+naissance)
nom: string
prenom: string
date_naissance: Date
email: ?string
email_manuel: bool                // verrou : l'import ne réécrase pas une correction admin
telephone: ?string
telephone_manuel: bool            // même verrou, pour le téléphone
voie_rue / code_postal / ville: ?string
category: Category
team: ?Team            // assigné manuellement par l'admin
season: Season
nature_licence: ?NatureLicence    // nouvelle demande / mutation / renouvellement
nature_manuelle: bool             // verrou : l'import ne réécrase pas une correction admin
form_token_expires_at: ?datetime  // expiration du lien public (30 jours)
link_sent_at: ?datetime
created_manually: bool
imported_at: datetime
```

### Corriger des coordonnées fausses sans que l'import les ramène

FootClubs fait foi sur l'identité, jamais sur la **joignabilité** : une adresse fausse
empêche le lien d'inscription d'arriver, et elle ne peut pas toujours se corriger là-bas le
jour même (dossier en cours de validation à la ligue). D'où le verrou `email_manuel` /
`telephone_manuel`, porté à l'identique par `Licencie` et par `Dirigeant` :

- l'admin corrige depuis `/admin/effectif/joueurs/{uuid}/coordonnees` — ouvert à **tous**, y
  compris aux fiches importées, contrairement à l'écran d'identité qui reste réservé aux
  fiches saisies à la main. Côté dirigeant, l'écran de modification existant suffit ;
- le verrou se pose **au changement de valeur**, pas à l'enregistrement : rouvrir l'écran et
  valider sans rien toucher ne fige rien. Il est posé par `LicencieService` et
  `DirigeantService`, jamais par l'import ;
- `ImportService` saute le champ verrouillé — pour ce champ seul, l'autre continue de suivre
  l'export ;
- une fois FootClubs corrigé, la fiche affiche « corrigé à la main » et un bouton **Reprendre
  FootClubs** (`reprendreImport()`) qui relâche le verrou. Sans cette sortie, CoSync
  ignorerait l'export pour toujours sur ce champ, même une fois la donnée bonne des deux côtés.

### DossierClub
```php
id: int
licencie: Licencie     // relation 1-1
taille_haut: ?string   // Enum: XS/S/M/L/XL/XXL ou taille enfant
taille_bas: ?string
pointure: ?string
autorisation_photo: ?bool
autorisation_accident: ?bool              // null si non applicable (seniors)
autorisation_transport_dirigeants: ?bool  // null si non applicable (seniors)
autorisation_transport_parents: ?bool     // null si non applicable (seniors)
volontaire_transport: ?bool               // déclenche l'attestation de transport
attestation_transport_drive_id: ?string   // chemin local tant que l'upload Drive n'a pas eu lieu
payment_intentions: json                  // liste de PaymentMode (le paiement peut être fractionné)
dotation_choix: json                      // choix d'équipement par groupe
dotation_personnalisation: json           // textes de flocage par groupe
helloasso_checkout_intent_id: ?string
helloasso_checkout_started_at: ?datetime  // borne la réconciliation des paiements
form_completed_at: ?datetime
status: LicenceStatus  // IMPORTED | LINK_SENT | FORM_COMPLETED | A_VALIDER_FFF | VALIDATED
```

### « Payé » et « validé » sont deux faits, et deux statuts

Une licence soldée dans CoSync n'est pas une licence validée : le club doit encore la
**signer dans FootClubs**, et rien ne peut faire ce geste à sa place — les deux outils ne se
parlent pas. Tant qu'un seul statut portait les deux, le club n'avait aucun moyen de savoir
ce qu'il lui restait à faire côté fédéral.

| Fait | Statut | Qui le pose |
|---|---|---|
| Le total encaissé atteint la cotisation due | `A_VALIDER_FFF` | `PaiementService`, automatiquement (saisie manuelle, HelloAsso vérifié, ou « Valider quand même ») |
| Le club a signé la licence dans FootClubs | `VALIDATED` | un admin, depuis la fiche ou l'écran groupé `/admin/effectif/joueurs/valider-footclubs` |

Ce qui ne doit pas se défaire :

- **Tout ce qui demandait « la licence est-elle validée ? » demandait en réalité « a-t-elle
  payé ? »** — droit à la dotation, sortie de stock, réconciliation HelloAsso, compteurs du
  hub Effectif. Le point de lecture unique est **`LicenceStatus::estSolde()`** (et
  `DossierClub::estSoldee()`, `LicenceStatus::soldes()` pour les requêtes). Tester
  `=== VALIDATED` en aval suspendrait le kit d'un licencié payé à un clic administratif sans
  rapport.
- **Le mail « votre licence est validée » part au solde**, pas à la validation FootClubs :
  c'est l'encaissement qui intéresse le licencié, la démarche fédérale est interne au club.
- **La validation se défait** (`annulerValidationFootclubs`). Sans cette sortie, un clic de
  trop ferait disparaître pour toujours une licence qu'il reste réellement à signer.
- **Aucune donnée n'a été rétro-migrée** : les dossiers déjà en `validated` avant la bascule
  le restent. Ce n'est pas un backfill oublié (cf. `Version20260828211444`).

Côté **dirigeant**, le même dernier geste existe (`Dirigeant.validatedFffAt`, un fait daté),
mais son avancement n'est **pas stocké** : tout ce qui le compose est déjà en base — lien
parti, formulaire soumis, documents signés, licence validée. `DirigeantStatutResolver` le
calcule (`DirigeantStatut`), `pourLot()` pour les listes. L'ordre des règles est la règle :
`VALIDE` passe avant `LICENCE_ADMINISTRATIVE`, qui n'attend ni lien ni document mais existe
bien à la FFF et se signe comme les autres.

### Transaction
```php
id: int
licencie: Licencie
montant: float
mode: PaymentMode      // CB | CHEQUE | ESPECES | VIREMENT | PASS_SPORT | CAF | ANCV
reference: ?string     // numéro chèque, référence virement
date_paiement: datetime
confirmed_by: User
season: Season
```

### StockItem
```php
id: int
nom: string
couleur: ?string
ref_catalogue: ?string
lien_achat: ?string
season: Season
```

### StockMovement
```php
id: int
item: StockItem
quantite: int
type: StockMovementType  // ENTREE | SORTIE | REBUT
licencie: ?Licencie      // si sortie liée à un joueur
note: ?string
created_by: User
created_at: datetime
```

### Taille — un référentiel en base, deux publics

Les tailles ne sont plus une constante PHP : elles vivent dans la table `taille`, réglée
depuis `/admin/club/tailles` (ordre au glisser-déposer, comme les catégories de stock).
`TailleReferentiel` lit, `TailleService` écrit.

```php
Taille  // libelle, type (VETEMENT|POINTURE), groupe, proposeeAuxLicencies, position
```

- `groupesProposes()` sert les **formulaires** : une personne n'y déclare que ce qu'elle
  sait dire d'elle-même — adulte, ou enfant **en âge**.
- `pourLeStock()` sert le **stock** : tout le référentiel du type, étiquetages fournisseur
  compris (`104`…`176`, `XS enfant`…`XL enfant`). C'est `proposeeAuxLicencies = false` qui
  fait la différence. Ne pas remonter ces déclinaisons dans les formulaires : un parent ne
  sait pas si le maillot de son enfant est un 128, et la taille déduite pour la dotation en
  sortirait fausse.

**Le libellé est une clé de fait, pas un simple label** : il est recopié tel quel dans
`dossier_club`, `dirigeant`, `stock_movement`, `dotation_besoin` et `stock_taille_note`.
`TailleService` refuse donc de le **renommer** ou de le **supprimer** dès qu'un
enregistrement le désigne — on décoche « proposée » à la place. L'ordre du référentiel est
celui de **tous** les sélecteurs, public compris.

### GrilleTaille — traduire le déclaré en étiquette fournisseur

Séparer les deux publics ne suffisait pas : il fallait encore **passer de l'un à l'autre**. Un
licencié déclare « 44 » ou « 12 ans » ; le fournisseur vend en « 43-46 » et en « 128 ». Sans
traduction, la dotation sortait du stock une déclinaison qui n'existe à aucun carton — le
compteur du « 44 » partait en négatif pendant que celui du « 43-46 » ne bougeait pas.

```php
GrilleTaille       // nom, type (VETEMENT|POINTURE), valeurs
GrilleTailleValeur // cible: Taille (le libellé du carton), couvertures: Taille[] (le déclaré)
StockItem          // grilleTaille: ?GrilleTaille
```

- **Les deux côtés sont des `Taille`**, jamais du texte libre : la cible est recopiée dans
  `stock_movement` et `dotation_besoin`, elle doit donc exister au référentiel — sinon la
  saisie d'un mouvement ne la proposerait même pas. `TailleService` compte les grilles parmi
  les emplois : une taille traduite ou couverte ne se renomme ni ne se supprime plus.
- **`grilleTaille` nullable = pas de traduction**, et c'est le cas courant : le maillot adulte
  se vend dans les tailles du formulaire. On ne crée une grille que quand le fournisseur a son
  propre barème.
- **Une taille déclarée mène à un seul libellé.** `GrilleTailleService` refuse le
  chevauchement : deux plages pour une même pointure rendraient la traduction indécidable.
- **Une grille ne traduit que ce qu'elle mentionne** : une taille qu'aucune ligne ne couvre
  passe **telle quelle**, jamais une valeur approchante. C'est le cas courant d'un fournisseur
  qui ne relabellise qu'une partie de sa gamme — les vestes enfant en `140`, les adultes en
  `L`. Rendre `null` (la V1) obligeait à écrire « L couvre L », « M couvre M »… pour tout le
  reste du référentiel : une cérémonie que personne ne comprend, qu'on oublie, et dont l'oubli
  envoyait **chaque adulte** en « à renseigner ». Le prix assumé : une taille que le
  fournisseur ne vend réellement pas ressort telle quelle au lieu de signaler un trou — c'est
  au bon de commande qu'on le voit, et l'écran de la grille liste ce qui passe sans traduction.
- **`options()` suit la même règle** que `traduire()`, et il le faut : une taille que la
  dotation sert doit pouvoir se saisir en mouvement de stock. La grille écarte les tailles
  qu'elle **traduit** (le `10 ans` se range en `140`), pas celles qu'elle ignore.
- Le point d'insertion unique est **`StockTailleResolver`** : `traduire()` pour la dotation
  (appelé par `DotationResolver::sizeFor()`), `options()` pour restreindre la saisie d'un
  mouvement aux déclinaisons réellement vendues. En aval, remise, ventilation, achat et bon de
  commande parlent déjà « la taille du besoin » et suivent tout seuls.
- L'ordre d'affichage reste celui du **référentiel**, pas de la grille : les libellés
  fournisseur y figurent déjà, `TailleReferentiel::comparer()` les range sans rien savoir des
  grilles.

### Écoulement — servir l'ancien stock avant de commander du neuf

Le club change de fournisseur sans jeter ce qui reste : les chaussettes du kit sont des ERIMA,
mais il dort des Nike au local. Sans arbitrage, le besoin porte l'article du kit, `AchatService`
ne déduit que **son** stock, et le club rachète du neuf par-dessus un carton plein.

```php
StockItem.remplaceArticle: ?StockItem   // « je m'écoule à la place de celui-ci »
DotationBesoin.articleEcoulement: ?StockItem  // l'article réellement servi (null = celui du kit)
DotationBesoin.articleManuel: bool             // l'admin a épinglé, l'arbitrage ne touche plus
```

- **La règle est portée par l'article à écouler**, et une seule fois pour le club — pas kit par
  kit. Un club change de fournisseur une fois ; la déclarer dans chaque `DotationModele` ferait
  oublier l'un des cinq et l'écoulement ne se ferait qu'à moitié.
- **Mais elle se déclare dans l'autre sens**, sur `/admin/stock/ecoulement` : l'article
  principal — celui qu'on commande désormais — en tête, les anciens stocks fléchés en dessous.
  C'est ainsi que la décision se prend (« je passe à l'ERIMA, il me reste des Nike »), et la
  poser depuis la fiche du Nike se lisait à l'envers : la règle existait, personne ne la
  retrouvait, et elle a été saisie à l'envers en prod. `EcoulementPresenter` retourne la
  lecture, `StockItemService::appliquerEcoulement()` reste seul à écrire. La fiche article
  n'en garde qu'une **mention en lecture seule** — la rebrancher au formulaire effacerait la
  règle à chaque enregistrement, le champ n'y étant plus. Corollaire : un article engagé dans
  une correspondance refuse de changer de `typeVetement` tant qu'elle n'est pas retirée.
- **`DotationBesoin.stockItem` reste l'article du kit.** C'est lui que `realigner()` réaligne et
  que `emplacementDe()` identifie ; changer sa valeur ferait purger et recréer le besoin à chaque
  bascule, en perdant le statut « donné », la taille manuelle et l'historique. Le point de
  lecture unique en aval est **`getArticleServi()`** — achats, remise, suivi, flocage passent
  tous par là. Lire `getStockItem()` en aval fait recommander du neuf.
- **L'arbitrage est une passe saison entière, idempotente** (`DotationEcoulementAllocator`),
  jouée avant chaque lecture du suivi et des achats — même dispositif que
  `syncTaillesFromDossiers()`, et pour la même raison : il dépend d'un stock qui bouge. Ordre de
  service : par création du besoin, premier inscrit premier servi. Il doit rester déterministe,
  sinon deux écrans consécutifs n'annoncent pas la même chose.
- **Jamais au-delà du stock, jamais à moitié, jamais dans une taille approchée.** La première
  règle est celle qui tient tout : un besoin servi par un substitut étant toujours couvert,
  `AchatService` ne propose jamais de racheter un article d'écoulement. Un épinglage manuel que
  le stock ne couvre plus est **relâché** — c'est ce qui préserve l'invariant.
- **Les deux articles doivent porter le même `typeVetement`** : c'est lui qui dit quel champ du
  dossier lire. Écouler un short à la place d'un maillot servirait la taille du bas sur le haut.
  Ni chaîne (Nike → Adidas → ERIMA) ni auto-remplacement : `StockItemService::appliquerEcoulement()`
  refuse les deux, et `analyserSuppression()` compte ces liens parmi les emplois.

### Qui reçoit quel kit — et qui ne reçoit rien

**Une personne relève d'un seul modèle de dotation.** `DotationResolver::resolveModele()` retient
la cible la plus spécifique — individu > équipe > catégorie FFF ou rôle dirigeant > défaut saison
— et rend **ce modèle-là**, pas la somme des modèles qui la visent. Créer un « kit exceptionnel »
à côté du « kit joueur » ne cumule donc rien : le plus spécifique remplace l'autre, et à priorité
égale c'est la dernière affectation créée qui gagne. Un article exceptionnel s'ajoute en **ligne**
du modèle existant, ou dans un modèle complet affecté nommément à la personne.

**L'équipe d'un dirigeant n'est pas une cible de dotation.** Elle dit de qui il s'occupe, pas ce
qu'il reçoit. Une cible « équipe » ne capte donc que des `Licencie` — sans ce cloisonnement, un
dirigeant rattaché aux Séniors héritait du kit joueur de l'équipe alors qu'aucune affectation ne
visait son rôle. Un dirigeant se cible par son **rôle** ou **nommément** ; le défaut saison, lui,
continue de couvrir tout le monde. `DotationModelePreview` tenait déjà ce raisonnement côté
aperçu : le résolveur s'y est aligné, pas l'inverse.

**Le suivi sépare les deux populations.** `/admin/dotations/suivi` groupe par équipe, puis
« Sans équipe », puis **« Dirigeants »** en fin de liste. Mêler l'encadrement aux joueurs de son
équipe mettait deux kits sans rapport dans le même tableau, et renvoyait le reste de l'encadrement
dans un « Sans équipe » qu'on lisait comme un oubli d'affectation. Une personne à la fois joueuse
et dirigeante tient **deux blocs de lignes** — c'est bien deux kits qu'elle reçoit.

### Flocage — le club peut saisir ce que le licencié n'a pas pu dire

Le texte à floquer vient du formulaire d'inscription. Deux situations le laissent vide sans que
personne ne se soit trompé : un kit créé **après** la validation d'une licence — le dossier ne
porte alors aucune réponse — et l'incident qui a empêché la personne de répondre. Sans saisie
admin, il ne restait que la base.

- `DotationFlocageService` porte le sujet en entier : `reglagesPour()` dit si un besoin se floque
  (en lisant **le kit**, seul à distinguer « floqué, texte pas encore saisi » de « pas floqué du
  tout » — le besoin porte `null` dans les deux cas), `changer()` écrit le texte.
- Le verrou `DotationBesoin.personnalisationManuelle` est le jumeau de `tailleManuelle` : une fois
  le texte saisi, le recalcul ne le remplace plus par celui — absent — du dossier. **Vider le
  champ relâche le verrou** et rend la ligne au dossier.
- **Le kit garde le dernier mot** : une option qui ne se floque plus n'emporte aucun texte, pas
  même un texte manuel, et le verrou tombe avec lui.
- Refusé une fois l'article remis : le vêtement est déjà floqué, et le texte porté par le besoin
  est la trace de ce qui a réellement été donné.

### Notes, correction et retrait d'un article de stock

**Deux notes, deux portées.** `StockItem.note` vaut pour l'article entier (où il est rangé,
ce qu'il reste à commander) ; `StockTailleNote` vaut pour une déclinaison (« le 128 taille
petit »). Une note vidée est **supprimée**, jamais conservée vide. Le tableau n'affiche
qu'un bouton — le texte vit dans une modale, une note de trois lignes déformait la ligne.
Les deux se lisent aussi sur la feuille d'inventaire, qui se remplit au local.

**Corriger un mouvement n'est pas l'effacer.** `StockMovementService::corrigerMouvementManuel()`
change la quantité ou la taille d'un mouvement **manuel**, exige un **motif**, et écrit une
ligne `StockMovementCorrection` (append-only) qui garde la valeur d'avant. Le stock, dérivé
des mouvements, suit tout seul. Une dotation, une réception de commande ou une vente ne se
corrige pas ici : son écran dédié tient le besoin ou la commande en face.

**Supprimer ou archiver** — `StockItemService::analyserSuppression()` tranche, et l'écran de
confirmation l'annonce **avant** d'agir. Un article part pour de bon quand les trois
conditions tiennent : stock soldé **taille par taille**, mouvements **tous manuels**, aucun
kit / besoin de dotation / bon de commande ne le référence. C'est le cas de l'erreur de
saisie, et lui seul : ses mouvements et ses notes partent avec lui, après une case à cocher.
Dès qu'une dotation, une commande ou une caisse l'a touché, on **archive** — la trace n'est
plus une erreur mais une histoire. Supprimable ne veut pas dire obligé : l'écran offre
toujours « Archiver plutôt ». Ne pas le remplacer par un `confirm()` : lui seul sait dire
lequel des deux va se produire, et pourquoi.

### Dirigeant.licenceAdministrative — la licence que le district exige

Le district impose de déclarer une licence dirigeante pour le président, la secrétaire et le
trésorier de l'association. Ces personnes ne sont pas forcément dans le foot : elles ne
signent rien, ne remplissent aucun formulaire et ne veulent pas de kit.

`Dirigeant.licenceAdministrative` enregistre **un seul fait** — « cette licence existe pour le
district » — d'où découlent **trois** conséquences, réglées ensemble et jamais séparément :

- `DotationBesoinSynchronizer::aDroitALaDotation()` retourne `false` **avant** de regarder la
  complétude du dossier. Verrou dur : sans lui, il suffisait qu'un admin renseigne une taille
  sur la fiche pour que le kit se matérialise en sortie de stock à préparer ;
- `DocumentRequirementResolver` ne lui attend **aucun** document, quel que soit le ciblage —
  son dossier ne reste donc pas éternellement « incomplet » et elle ne remonte dans aucune
  relance ;
- `DirigeantRepository::queryLienJamaisEnvoye()` l'exclut de l'écran d'envoi groupé et
  `DirigeantLinkService::send()` refuse l'envoi à l'unité.

Les **clés font exception** : un président sans dossier club détient souvent le trousseau du
local, sa fiche continue donc d'afficher le registre et son attestation. La **validation
FootClubs** aussi : la licence existe à la FFF, le club la signe comme les autres (cf. « Payé »
et « validé »).

Ne pas remplacer ce drapeau par trois réglages indépendants : c'est justement ce qui faisait
oublier l'un des trois.

### AttestationPaiement — attester un encaissement, sans jamais le réécrire

Un employeur ou un CE rembourse tout ou partie d'une licence sur présentation d'une
attestation. `AttestationPaiement` est le document remis, et il obéit à deux règles qui
tiennent tout le reste :

**1. On n'atteste qu'une licence soldée, et le verrou porte sur l'encaissement.**
`AttestationPaiementService::motifBlocage()` compare la cotisation due au total réellement
encaissé — **jamais** au statut du dossier. « Valider quand même » passe une licence en
`A_VALIDER_FFF` sans qu'un centime soit entré : un verrou posé sur le statut aurait émis un
document affirmant un versement qui n'a pas eu lieu. Le motif est *rendu*, pas réduit à un
booléen : l'écran doit pouvoir dire ce qui manque.

**2. Le montant, la date et le mode ne se saisissent pas.** Ils sont dérivés des
`Transaction` de la saison — total, date du dernier versement, modes dédoublonnés — puis
**figés** sur l'attestation. Le formulaire ne porte que ce qu'aucune donnée du club ne sait
dire : **qui a payé**. FootClubs ne connaît qu'un parent, le payeur peut être l'autre, et
`Licencie` n'a ni sexe ni civilité — d'où `LienParente`, choisi à chaque fois.

Tout ce que le document affirme est recopié à l'émission, **signataire compris** : le club
change de trésorier, une attestation déjà remise continue de nommer celui qui l'a signée.
Le lien vers les `Transaction` (`ON DELETE CASCADE`) n'est qu'une trace de rapprochement —
un paiement supprimé plus tard retire la jointure sans rien changer à ce qui est écrit.
La table est append-only : une réémission ajoute une ligne, le fichier Drive est daté.

Le retéléchargement **régénère** le PDF depuis ces valeurs figées plutôt que de rapatrier
le fichier de Drive : `DriveUploader` n'expose qu'`uploadToPath()`, et un document doit
rester récupérable Drive en panne. Prix assumé : l'identité de l'association et le paraphe
scanné sont relus en direct — l'exemplaire qui fait foi reste celui archivé.

⚠️ Le montant en toutes lettres est produit par `MontantEnLettresFormatter`, écrit à la
main. `NumberFormatter::SPELLOUT` **ne convient pas** : l'image PHP embarque un ICU aux
données réduites au seul anglais et rendait « one hundred twenty » **sans lever d'erreur**.
Ne pas y revenir.

### EnvoiMail — un mail parti laisse une trace, toujours

`Licencie.linkSentAt` atteste que la personne a été contactée **un jour**. C'est ce qu'il
faut aux écrans d'envoi groupé, et rien d'autre : la colonne est écrasée à chaque renvoi.
Une relance ne se voyait donc nulle part — et un admin pouvait réécrire à quelqu'un que le
club venait de relancer. Les mails qui ne passent par aucun lien (signature, complément,
boutique, validation, attestation) ne laissaient, eux, aucune trace du tout.

`EnvoiMail` est un journal **append-only**, une ligne par envoi, rattaché à un `Licencie`,
un `Dirigeant` ou un `Detenteur`. Quatre règles le tiennent :

- **Une seule plume : `ClubMailer::envoyer()`.** Le `TypeMail` y est obligatoire à l'appel,
  aucun mail ne peut plus partir en silence. Confier le traçage aux services appelants les
  ferait diverger, et c'est le côté oublié qui enverrait le mail invisible.
- **Après l'envoi, jamais avant.** Une ligne posée sur un envoi qui a échoué ferait croire
  la personne relancée et empêcherait la vraie relance de partir — pire que pas de trace.
  Symétriquement, un échec d'écriture du journal ne fait pas échouer un mail déjà parti :
  il est journalisé en erreur.
- **L'adresse enregistrée est celle réellement visée**, pas la redirection du mode bêta.
- **Pas de colonne `season`** : `Licencie` et `Dirigeant` sont déjà cloisonnés par saison,
  toute question sur les envois d'une saison passe par eux. Seul un `Detenteur` vit hors
  saison, délibérément — une colonne saison serait le seul endroit à prétendre l'inverse.

`linkSentAt` et `boutiqueAnnonceeAt` **restent** : ils continuent de faire foi pour les
écrans d'envoi groupé. La migration `Version20260829090000` les a repris dans le journal —
sans ce backfill, l'historique des fiches, qui lit désormais le journal, aurait perdu la
ligne « lien envoyé » de chaque personne déjà contactée.

Les fiches licencié et dirigeant affichent en tête un **dernier contact**
(`DernierContactResolver`) : c'est le repère à lire avant de relancer à la main.

### Relance automatique — le délai part du dernier mail, pas de l'inscription

Le club relance de lui-même les licences non soldées : une passe par jour à 9 h
(`app:relances:envoyer`, cron du conteneur `cosync_cron`). Heure ouvrable et non 2 h du
matin — un mail horodaté à 3 h part en indésirable.

**La règle qui tient tout le dispositif : le délai est compté depuis le dernier mail reçu
par la personne, quel qu'il soit.** Une relance passée à la main hier repousse donc
mécaniquement celle du robot de dix jours. Sans cette ancre, un envoi automatique serait
suivi d'une relance manuelle quelques heures plus tard, et le club harcèlerait ceux qu'il
vient de relancer. C'est précisément ce que le journal rend possible.

`RelanceResolver` énonce la règle **une seule fois**, et les trois chemins la lisent :
le cron, l'écran groupé `/admin/effectif/joueurs/relancer`, le bouton d'une fiche. Six
conditions, toutes nécessaires :

| Condition | Pourquoi |
|---|---|
| dossier **non** `estSoldee()` | `estSolde()`, jamais `=== VALIDATED` : c'est l'encaissement qui intéresse le licencié |
| `linkSentAt !== null` | relancer qui n'a jamais été contacté n'est pas une relance : c'est l'envoi initial, décidé par un admin |
| une adresse email existe | sans elle, la relance se fait au téléphone ; ces personnes ne sont donc **pas** listées |
| relances déjà envoyées `< relanceMax` | sans plafond, on écrirait tous les dix jours jusqu'en juin à qui ne paiera pas |
| dernier mail plus vieux que `relanceDelaiJours` | l'ancre ci-dessus |
| `relanceActive` | vérifié par `RelanceService`, **pas** par le resolver : l'écran groupé doit rester utilisable robot éteint |

**Deux étapes, deux mails** (`EtapeRelance`) : `DOSSIER` redonne un lien à qui n'a rien
rempli — en **rouvrant le jeton** de 30 jours, sans quoi le mail renverrait vers un lien
expiré ; `PAIEMENT` rappelle le montant et les instructions du mode déclaré à qui a rempli.
La page de confirmation n'étant protégée par aucun jeton, ce second lien reste valide.

Ce qui ne doit pas se défaire :

- **L'interrupteur est éteint à la migration.** Un automate qui écrit à tout un effectif ne
  démarre jamais d'un déploiement : il démarre d'une décision, prise dans
  `/admin/club/relances` après un `app:relances:envoyer --dry-run`.
- **La relance à l'unité depuis une fiche ignore délai et plafond**, volontairement : c'est
  un acte délibéré, et la fiche affiche le dernier contact juste au-dessus du bouton. On
  montre l'information, on ne bloque pas la personne qui la lit. Elle compte en revanche
  **dans** le plafond, et repousse la relance automatique suivante.
- **Les lectures du resolver sont groupées** (`dernierEnvoiParLicencie`,
  `compterEnvoisParLicencie`) : la liste des joueurs affiche son compteur à chaque
  ouverture, deux requêtes par licencié en feraient trois cents.
- **Il n'y a pas de module de templates de mails**, et c'est assumé : pour huit messages par
  an, l'éditeur, la substitution de variables, l'aperçu et les envois de test ne se paient
  pas. `TypeMail` en est l'amorce si le besoin vient — un envoi libre à une sélection
  couvrirait l'essentiel (annonces, événements) pour une fraction du coût.

### ClubSettings — l'identité de l'association

`ClubSettings` porte désormais, à côté du RIB et de la boutique, ce qu'une attestation
engage juridiquement : raison sociale, adresse, SIRET, email, et le **signataire**
(civilité, nom, qualité libre). Ces valeurs étaient écrites en dur dans une trentaine de
templates ; les autres peuvent migrer plus tard, sans urgence.

`ClubSettings` porte aussi les trois réglages de la **relance automatique** —
`relanceActive`, `relanceDelaiJours` (10), `relanceMax` (3). Au niveau du club et non de la
saison : c'est une politique de relance, elle ne se redécide pas chaque rentrée.

Rien n'impose que le signataire soit le trésorier — président, secrétaire ou toute personne
ayant délégation peuvent engager l'association. Le **nom**, lui, doit figurer : un document
signé sans nom n'engage personne. Ne pas tenter de dériver le signataire de `Dirigeant`, dont
les rôles ne distinguent plus le bureau depuis `Version20260807220000`.

La signature scannée est **facultative** — sans elle, le PDF imprime un cadre à signer à la
main, et la fonctionnalité reste utilisable dès le premier jour. Elle vit dans
`var/signatures/` (volume `cosync_signatures`), **hors de `public/`** : c'est un paraphe, il
ne doit jamais être servi par le serveur web. Elle n'est pas dans `ClubSettings` en base
parce que `ClubSettingsService::get()` est appelé à chaque rendu de page par `AppExtension` —
y loger 100 Ko de base64 les chargerait sur toutes les requêtes. Contrepartie à connaître :
le volume n'est pas couvert par `app:db:backup`, l'image est à re-téléverser après une
reconstruction du VPS.

`ClubSettings` porte enfin le rattachement du club à la FFF pour le planning des matchs —
`fffClubNo`, `fffSyncActive` (cf. ci-dessous). Au niveau du club : le numéro d'un club à la
fédération ne change pas à la rentrée. ⚠️ Quand un troisième outil aura besoin de réglages,
il faudra les sortir d'ici : `ClubSettings` ne doit pas devenir le fourre-tout de tous les
outils.

### MatchDomicile — un planning distribué, pas un miroir du calendrier fédéral

Le club imprime chaque mois la liste de ses matchs à domicile, pour **deux publics qui n'ont
pas le même besoin** : la mairie, qui planifie la tonte du terrain, et les boîtes aux lettres
du village. D'où trois tirages d'une même donnée (`PlanningFormat`) — A4 mairie, A5 flyer, et
surtout **A4 « duo »**, deux A5 côte à côte à couper au massicot : c'est le tirage réel,
imprimer les flyers un par un gâche la moitié du papier.

```php
MatchDomicile  // season, date, heure (?string 'HH:MM'), categorie, adversaire, note,
               // source: MatchSource (MANUEL|FFF), fffMaNo, fffCompetition, fffTerrain, masque
```

**Qui possède quoi — la règle qui tient tout le reste.** Sur une ligne venue de la FFF, le
district fait foi : date, heure, catégorie et adversaire sont **réécrits à chaque
synchronisation**, sinon un report de match ne remonterait jamais sur le planning distribué.
Le club possède la **note** et le **masque**. Pour corriger un horaire fédéral faux, on
**détache** la ligne (`detacherDeLaFff()`, et son inverse `reprendreLaFff()`) — même doctrine
que `reprendreImport()` pour les coordonnées. Sans cette sortie explicite, la correction
serait effacée à la sync suivante sans que personne le voie ; et le `fffMaNo` est **conservé**
au détachement, faute de quoi la sync recréerait le match en double.

Ce qui ne doit pas se défaire :

- **`heure` est une chaîne, pas un `time`.** C'est un libellé qu'on imprime, jamais un instant
  qu'on calcule. En `DateTimeImmutable`, un fuseau entrerait dans un document papier et un
  match de 15h00 s'imprimerait à 14h00.
- **Un match fédéral ne se supprime pas** : la sync le recréerait. Le **masque** est la seule
  façon de l'écarter durablement des documents. L'écran le dit plutôt que de laisser
  l'admin recommencer trois fois.
- **Un match disparu du flux n'est supprimé que s'il est resté intact** (ni note, ni masque).
  Annoté, il est conservé et **signalé** : le club y a mis du travail, l'automate n'a pas à
  l'effacer en silence.
- **Les dates françaises passent par `DateFrancaiseFormatter`**, écrit à la main.
  `IntlDateFormatter('fr_FR')` rend « Sunday 20 September » **sans lever d'erreur** — l'ICU de
  l'image est réduit au seul anglais, exactement comme pour `NumberFormatter::SPELLOUT`. Et
  dans les templates PDF, `|capitalize` de Twig, jamais `text-transform: capitalize` : DomPDF
  l'applique à chaque mot et rend « Dimanche 20 Septembre ».
- **Le tirage duo est en positionnement absolu**, pas en `<table>` : DomPDF ajoutait marges et
  rembourrages à la hauteur de ligne, le tableau dépassait la page et sortait rejeté sur une
  deuxième feuille, la première blanche. Le défaut est invisible à l'écran, il ne se voit
  qu'à l'impression.

#### Récupérer le calendrier depuis la FFF

L'API publique DOFA (`https://api-dofa.fff.fr/api/clubs/{cl_no}/matchs`) est **ouverte, sans
jeton**, et rend exactement la donnée utile. `cl_no` n'est **pas** le numéro d'affiliation :
c'est l'identifiant interne DOFA, réglé dans `/admin/outils/planning-matchs/reglages`, où un
bouton **vérifie** le numéro en réaffichant le nom du club — un numéro faux ramènerait le
calendrier d'un autre club sans que rien ne le signale.

⚠️ **Ce n'est pas une API contractuelle** : elle a déjà changé d'hôte deux fois, n'a plus de
documentation publique, et la FFF sert son calendrier derrière une protection anti-robot qui
**refuse les clients non navigateurs**. Un serveur peut donc recevoir un **403 permanent** là
où le même appel passe depuis un poste de travail. `app:planning:sync-fff --dry-run` répond à
la question sur l'hébergement visé ; `FffApiException::estRefusParProtection()` fait dire à
l'écran « la FFF refuse les appels venant du serveur » plutôt que « réessayez », car il n'y a
rien à réessayer. **La saisie à la main et l'import par collage ne sont donc pas un repli
mais un mode de plein exercice** — d'autant que les **plateaux U7/U9 n'existent pas** dans ce
flux.

Trois pièges tenus par `FffMatchMapper`, tous vus dans les données réelles :

| Donnée FFF | Traitement |
|---|---|
| `away: null` | **équipe exempte** : personne ne joue. La ligne est écartée — l'inscrire ferait tondre la mairie pour rien |
| `terrain: null` | affecté après parution du calendrier : le match a bien lieu. C'est `home.club.cl_no` qui décide du domicile, jamais le terrain |
| `time: "15H30"` | traduit en `15:30` ; `date` ISO à minuit UTC dont on prend la **partie date**, sans conversion de fuseau |

La catégorie imprimée vient de la **compétition** (`U16 DISTRICT` → « U16 ») et non du code
fédéral, qui classe cette même équipe en `U17` : c'est « U16 » que le village reconnaît. Le
numéro d'équipe n'est pas ajouté — sa signification varie d'un club à l'autre, et un
« Séniors 2 » faux sur un tract vaut moins qu'un « Séniors » un peu large.

L'interrupteur `fffSyncActive` est **éteint à la migration**, comme `relanceActive` : un
automate démarre d'une décision, pas d'un déploiement.

### Detenteur, CleMouvement, AttestationCle — deux échelles de temps

Les clés du local sont le seul domaine où **le fait et l'engagement ne vivent pas dans la
même échelle de temps**, et c'est délibéré :

| Ce qu'on veut savoir | Où ça vit | Pourquoi |
|---|---|---|
| Qui détient une clé, depuis quand | `Detenteur` + `CleMouvement`, **hors saison** | Un trousseau ne change pas de main au 1ᵉʳ juillet |
| Qui s'est engagé cette année | `AttestationCle`, **par saison** | L'attestation se resigne chaque année |

```php
Detenteur       // niveau club : nom, prenom, email, telephone, num_licence, qualite
CleMouvement    // append-only : detenteur, type (REMISE|RESTITUTION|PERTE), quantite, date
AttestationCle  // append-only : detenteur, season, signed_at, nb_cles, drive_path, uuid public
```

Conséquences à ne pas défaire :

- `CleMouvement` **ne porte pas de saison**. La colonne `season_id` subsiste en base,
  dé-mappée. Filtrer le registre par saison ramènerait le défaut d'origine : un solde
  remis à zéro chaque été alors que les clés sont physiquement dehors.
- `Detenteur` n'est **pas** un `Dirigeant` : ce dernier est cloisonné par saison et ne
  fournit donc aucune identité stable. Le rapprochement des deux se fait dans
  `DetenteurEffectifResolver`, sur le numéro de licence puis sur le nom.
- Un détenteur qui n'est plus à l'effectif **reste visible**, en alerte « hors effectif ».
  Ses clés sont dehors : le faire disparaître serait mentir.
- `AttestationCle` est append-only : une re-signature ajoute une ligne, elle n'écrase pas
  la précédente. Les deux PDF font foi à leur date.
- La campagne de renouvellement est **manuelle** (`AttestationCleService::lancerCampagne`).
  Aucun mail ne part sans décision de l'admin.

---

## 5. Enums PHP

Utilise des `enum` PHP 8.1 stricts, jamais des chaînes en dur dans le code.

```php
enum LicenceStatus: string {
    case IMPORTED = 'imported';           // créé à l'import, lien pas encore envoyé
    case LINK_SENT = 'link_sent';
    case FORM_COMPLETED = 'form_completed';
    case A_VALIDER_FFF = 'a_valider_fff'; // paiement soldé (ou validation manuelle) — cf. §4
    case VALIDATED = 'validated';         // licence signée dans FootClubs, sur décision d'un admin
}

enum PaymentMode: string {
    case CB_ONLINE = 'cb_online';
    case CHEQUE = 'cheque';
    case ESPECES = 'especes';
    case VIREMENT = 'virement';
    case PASS_SPORT = 'pass_sport';
    case CAF = 'caf';
    case ANCV = 'ancv';
}

enum StockMovementType: string {
    case ENTREE = 'entree';
    case SORTIE = 'sortie';
    case REBUT = 'rebut';
}

enum MatchSource: string {
    case MANUEL = 'manuel';   // le club décide ; rien ne l'écrase
    case FFF = 'fff';         // le district décide ; réécrit à chaque synchronisation
}

enum PlanningFormat: string {
    case A4_MAIRIE = 'a4_mairie';  // feuille de service pour la tonte du terrain
    case A5_FLYER  = 'a5_flyer';   // un flyer par page A5
    case A4_DUO    = 'a4_duo';     // deux A5 côte à côte sur une A4 paysage, à couper
}
```

---

## 6. Workflows Métier Clés

### A. Import XLSX FootClubs

**Comportement attendu : idempotent, jamais destructeur.**

Deux formats coexistent, reconnus à leurs en-têtes par `ImportLayoutResolver` :
**Licences dématérialisées** (celui de la procédure) et **Éditions et extractions** (l'ancien).

#### Procédure d'export depuis FootClubs (documentée dans l'UI `/admin/import`)
1. Menu gauche → **Licences → Dématérialisées**
2. Filtre **Statut** : « En attente de signature club » → **Rechercher**
3. **Télécharger** → fichier Excel. Contrôler les licences ; inutile de retirer les déjà importées
4. CoSync → saison → **Effectif → Import**, déposer le fichier

L'ancien export reste lisible : **Licenciés → Éditions et extractions → Édition licenciés**,
toutes les catégories, format **Extraction MS Excel**, sortie et tri **Complet** (donne emails
et mobiles). Il ne contient que des licences signées — rien à y filtrer.

#### N'importer que les dossiers que le licencié a remplis

CoSync ne refait pas FootClubs : une fiche n'y entre qu'une fois la démarche FFF faite. L'export
dématérialisé, lui, contient **tout le fichier des licences**, y compris des dossiers en
« Prise de contact » que personne n'a remplis. Importés, ils sont indiscernables des vrais et
faussent effectifs comme relances — c'est arrivé en prod le 18/08/2026, sur un export non filtré.

Le filtre de la procédure ne suffit donc pas : il s'oublie. `ImportService::statutPermetImport()`
relit la colonne **Statut** et n'accepte que `StatutDossierFff::permetImport()` — « En attente
signature club » et au-delà. Trois règles à ne pas défaire :

- **La colonne absente ne filtre rien.** `rawStatut === null` distingue « format sans statut »
  (l'ancien export, déjà signé) de « statut vide ». Filtrer sans colonne écarterait tout.
- **Un statut inconnu n'est pas importé.** Si la FFF renomme ses libellés, mieux vaut un rapport
  qui annonce des lignes écartées qu'un effectif rempli en silence. Le rapport nomme le libellé
  incompris (`ImportResultData::$statutsInconnus`), il ne se contente pas de le compter.
- **Le filtre vaut aussi pour les dirigeants** : la même passe les crée depuis le même fichier.

#### Colonnes utiles du fichier
| Dématérialisées | Éditions et extractions | Champ CoSync |
|---|---|---|
| `Numéro personne` | `Numéro personne` | `num_licence` — clé d'upsert |
| `Nom` + `Prénom` | `Nom, prénom` (split) | `nom` (MAJUSCULES) + `prenom` (Capitalize) |
| `Date de naissance` | `Né(e) le` | `date_naissance` |
| `Sous-catégorie` (préfixe famille retiré) | `Sous catégorie` | `category` |
| `Type` (Joueur → licencié, reste → dirigeant) | `Type licence` (Libre / Dirigeant) | cible de la ligne |
| `Statut` | — | filtre d'import, jamais stocké |
| `Email` | `Email principal` | `email` |
| `Téléphone mobile` | `Mobile personnel` | `telephone` |
| Toutes les autres | Toutes les autres | ignorées |

#### Traitement dans CoSync
1. Admin drag & drop le fichier XLSX sur `/admin/import`
2. `ImportService` lit le fichier via PhpSpreadsheet, `ImportLayoutResolver` choisit le layout
3. Le layout mappe la ligne vers `ImportRowData` — **rien d'autre** : aucune décision d'import
4. `ImportService` écarte la ligne si son `Statut` ne permet pas l'import (cf. ci-dessus)
5. `DataSanitizer` normalise : nom en MAJUSCULES, prénom en Capitalize, téléphone en +33,
   email en trim + lowercase
6. Pour chaque ligne : `upsert` sur `num_licence` (`Numéro personne`)
   - Si la fiche existe : mise à jour des données FFF uniquement. Les données club (DossierClub, Transaction) ne sont jamais touchées.
   - Si nouvelle : création + génération UUID, **sans aucun mail**
7. Rapport d'import affiché : X créés, Y mis à jour, Z écartés (par statut), erreurs

**Aucun mail ne part de lui-même — ni pour un licencié, ni pour un dirigeant.** Un fichier
déposé par erreur écrirait à tout un effectif avant que le rapport soit lu. Le départ des liens
est une décision, prise sur un écran dédié qui liste les destinataires case par case
(`/admin/effectif/joueurs/envoyer-liens` et `/admin/effectif/dirigeants/envoyer-liens`), ou à
l'unité depuis une fiche. La création manuelle propose une case, **décochée d'office**.

Ce qui fait foi, des deux côtés, c'est `linkSentAt` — un fait daté. Ni le statut du dossier
(qui peut avancer par une saisie admin) ni `formTokenExpiresAt` (effacé dès le dossier complet)
ne savent dire si la personne a été contactée un jour.


### B. Formulaire Public `/inscription/{uuid}`

Formulaire multi-étapes Alpine.js, mobile-first, sans login.

**Étape 1 — Bienvenue**
- Affichage nom/prénom du licencié (pré-rempli depuis la BDD)
- Rappel : "Ce formulaire complète votre inscription au Club de Foot de Soudron"
- Bouton "Commencer"

**Étape 2 — Équipement**
- Taille haut (select : XS / S / M / L / XL / XXL / Tailles enfants 6ans→16ans)
- Taille bas (même logique)
- Pointure (select : 28 → 48)

**Étape 3 — Autorisations** *(conditionnel : affiché uniquement si `category.is_ecole_foot === true`)*
- Autorisation transport par les dirigeants (OUI / NON)
- Autorisation transport par d'autres parents (OUI / NON)
- Droit à l'image (OUI / NON) — affiché pour TOUS les licenciés

**Étape 4 — Attestation de transport** *(conditionnel : jeune + volontaire au transport)*
- Nom/prénom du conducteur, n° de permis, assurance, date de contrôle technique
- Signature. Un PDF d'attestation est généré et archivé sur Drive.

**Étapes 5..N — Documents à signer** *(une étape par document actif)*
- Le règlement intérieur n'est plus figé : les documents sont **paramétrables** depuis
  `/admin/config/documents` (entité `DocumentSignable`), ciblables par population
  (licenciés, rôles dirigeants, dirigeants nommés).
- Chaque étape : lecture scrollée du texte + pad de signature tactile (Signature_pad.js).
- La liste des documents attendus est **recalculée côté serveur** à la soumission : un id
  envoyé mais non attendu est ignoré, un document attendu manquant rejette la soumission.

**Étape finale — Paiement**
- Affichage : "Comment souhaitez-vous régler votre cotisation ?"
- Montant résolu par `CotisationResolver` (cotisation de l'équipe, sinon défaut de la saison)
- Bouton principal mis en avant : **paiement par carte via HelloAsso** (aucun frais pour le club).
  Le clic enregistre l'inscription puis redirige vers HelloAsso — le licencié ne peut rien perdre
  s'il abandonne.
- Puis, sous un séparateur "ou régler autrement", les options radio :
  - Virement → affiche RIB du club + libellé exact à mettre
  - Chèque → "À l'ordre de [nom club], à remettre au local"
  - Espèces → "À remettre au local lors d'une permanence"
  - Pass Sport / Chèque CAF / ANCV → "À remettre au local"
- Mention bien visible : **"Votre inscription ne sera validée qu'à réception du paiement."**

**Règle absolue du paiement en ligne** — une licence n'est jamais marquée payée sans encaissement
vérifié. Aucune `Transaction` n'est créée sur la foi d'une `returnUrl` ou du corps d'une notification :
`HelloAssoPaymentRecorder` relit l'état du paiement auprès de l'API HelloAsso (`state === Authorized`)
avant tout enregistrement, de façon idempotente. Le licencié ne voit jamais autre chose que
"paiement en cours de validation" tant qu'aucune transaction n'existe.

**Validation finale**
- Génération PDF règlement signé (template Twig → DomPDF)
- Upload sur Drive : `[Saison]/[Equipe]/[NOM_Prenom_NumLicence]/reglement_signe.pdf`
- Suppression du fichier local
- `DossierClub.is_signed = true`, `status = FORM_COMPLETED`
- Page de confirmation affichée au licencié

**Boutique du club** — `ClubSettings.boutiqueUrl`, réglé dans `/admin/boutique/lien`. Réglage du
club et non de la saison : la boutique HelloAsso est une page de l'association.

L'**ouverture** est un booléen distinct du lien (`ClubSettings.boutiqueOuverte`, basculé depuis
`/admin/boutique`) : le club lance ses licences puis sa boutique quelques jours plus tard, le lien
se prépare donc à froid. `getBoutiqueUrlPublique()` — et donc la variable globale
`club_boutique_url` — ne rend le lien qu'une fois la boutique ouverte. **Les écrans publics et les
mails passent toujours par là**, jamais par `getBoutiqueUrl()`, réservé au formulaire d'admin qui
doit relire un lien préparé mais pas encore annoncé.

L'annonce est un **mail distinct** de l'accusé de réception, et **ne part pas** à la soumission du
formulaire : une annonce accrochée à l'inscription ne rattraperait jamais ceux qui se sont inscrits
avant l'ouverture. C'est un envoi groupé décidé écran en main (`/admin/boutique/annoncer`), proposé
aux licenciés dont le dossier est complété, une seule fois chacun — ce que `Licencie.boutiqueAnnonceeAt`
atteste, comme `linkSentAt` pour les liens d'inscription.

### C. Dashboard Admin

URL : `/admin/licencies`

Tableau filtrable (CSS natif, pas de lib externe) :
- Filtres : Saison | Équipe | Catégorie | Statut
- Colonnes : Nom Prénom | Catégorie | Équipe | Formulaire | Paiement | Statut global
- Badges colorés pour le statut :
  - ⚪ `IMPORTED` — Importé, lien pas encore envoyé
  - 🔵 `LINK_SENT` — Lien envoyé
  - 🟡 `FORM_COMPLETED` — Formulaire complété, paiement en attente
  - 🟣 `A_VALIDER_FFF` — Payé, à valider sur FootClubs
  - 🟢 `VALIDATED` — Validé

Action depuis le tableau :
- "Confirmer paiement" → modal rapide (mode, montant, référence) → crée `Transaction` + passe statut à `A_VALIDER_FFF`
- "Renvoyer le lien" → rouvre la fenêtre de 30 jours et renvoie le mail.
  ⚠️ L'UUID n'est **pas** régénéré, volontairement : le régénérer invaliderait les liens déjà
  distribués, ce qui casserait les licenciés en cours de saisie.
- Clic sur une ligne → fiche détail du licencié

#### Mode édition — retirer une fiche entrée par erreur

Les listes `/admin/effectif/joueurs` et `/admin/effectif/dirigeants` ont une bascule
**Mode édition** (`?edition=1`), réservée à `ACCES_DIAGNOSTIC` : cases à cocher, écran de
confirmation nominatif, suppression. C'est la sortie de secours d'un import mal filtré, pas un
outil de gestion courante — un joueur qui quitte le club se gère par la saison suivante.

`SuppressionFicheService` porte la règle, **une seule fois pour les deux populations** : une fiche
ne se supprime que si **rien ne s'y est passé** — aucun lien envoyé (`linkSentAt`), aucune annonce
boutique, aucun formulaire rempli, dossier resté à `IMPORTED`, aucun paiement engagé ou encaissé,
aucune signature, aucune sortie de stock, aucune dotation affectée nominativement. Dupliquer ces
tests dans `LicencieService` et `DirigeantService` les ferait diverger, et c'est justement le
côté qui aurait dérivé qui supprimerait une signature.

Ce qui ne doit pas se défaire :

- **L'analyse est rejouée juste avant la suppression.** L'écran de confirmation dit ce qui était
  vrai à son affichage ; entre les deux, un lien a pu partir.
- **Le lot n'est pas tout-ou-rien** : une fiche redevenue intouchable est épargnée, les autres
  partent, et le message de retour nomme les épargnées avec leur motif.
- **`DossierClub` part explicitement avec le licencié** (FK `NO ACTION`). Les besoins et
  affectations de dotation tombent en cascade côté base.
- **Le mode édition n'est jamais mémorisé** par `ListFilterMemory` : ce n'est pas un filtre, et
  une liste qui rouvre ses cases de suppression toute seule est un piège.

### D. Archivage Drive

```
Drive/
└── FC Soudron/
    ├── 2025-2026/
    │   ├── Documents signés/
    │   │   └── Règlement intérieur/
    │   │       └── RI_DUPONT_Thomas.pdf
    │   ├── Attestations de paiement/
    │   │   └── attestation_paiement_MARCOUX_Maxence_2026-08-28.pdf
    │   ├── Attestations Transport/
    │   ├── Plannings/
    │   │   └── planning_matchs_2026-09-01_2026-09-30_flyer-duo.pdf
    │   └── Club house/Clés/Attestations de remise/
    └── Sauvegardes/
        └── 2026-08/
            └── backup_20260808_023000.sql.gz
```

⚠️ Le segment `Club house/Clés` n'a **pas** suivi le renommage du module en « Clés » : il
désigne des dossiers qui contiennent déjà des PDF archivés, et le renommer laisserait un
dossier vide à côté des documents signés.

Le classement se fait **par type de document**, pas par équipe ni par licencié : les
`driveSegments` sont fixés à la création de chaque `DocumentSignable`.

`DriveUploaderService` utilise un Service Account Google (credentials JSON en variable d'env,
jamais committé).

**L'upload est différé** : le PDF est écrit dans `var/pdfs/`, la colonne `drivePath` porte le
chemin **local absolu**, et l'upload part sur `kernel.terminate` (`DriveUploadTerminateListener`).
Une fois sur Drive, la colonne porte l'**ID Drive** — d'où la convention « commence par `/` =
encore en local ». La commande `app:drive-retry-upload` (cron toutes les 15 min) rattrape les
échecs. Le fichier local n'est supprimé qu'après un upload réussi : tant que Drive est
injoignable, c'est la seule copie de la signature.

**Le planning des matchs échappe à ce dispositif, volontairement.** File d'attente, colonne
`drivePath` et reprise cron existent parce qu'une signature perdue l'est pour toujours ; un
planning se **régénère intégralement depuis la base**. `PlanningDriveSync` archive donc de
façon **synchrone et à la demande**, via `replaceAtPath` — régénérer la même période remplace
le fichier au lieu d'empiler des copies — et un échec est **rendu à l'admin** plutôt qu'entré
dans une file. Ne pas l'y faire rentrer « par cohérence » : ce serait trois mécanismes de plus
pour protéger un document reproductible en un clic.

### E. Tâches planifiées (conteneur `cosync_cron`)

| Fréquence | Commande | Pourquoi |
|---|---|---|
| toutes les 15 min | `app:drive-retry-upload` | rattrape les PDF restés en local |
| toutes les 30 min | `app:helloasso:sync-paiements` | rattrape un encaissement dont la notification n'est jamais arrivée — sans lui, le club encaisse sans que la licence passe en validée |
| 07h00 | `app:planning:sync-fff` | aligne le planning des matchs sur le calendrier du district ; ne fait rien tant que `fffSyncActive` est faux |
| 09h00 | `app:relances:envoyer` | relance les licences non soldées ; ne fait rien tant que `relanceActive` est faux. Heure ouvrable : un mail du club horodaté à 3 h part en indésirable |
| 02h30 | `app:db:backup` | dump PostgreSQL + copie sur le Drive |

⚠️ Toute commande console rend potentiellement du Twig (mails), et `AppExtension` expose la
saison courante en variable globale. `SeasonContext` doit donc rester utilisable **hors requête
HTTP** : ne jamais y appeler `RequestStack::getSession()` sans garde.

---

## 7. Standards de Qualité — Règles Absolues

### 7.1 Service Layer — Règle d'or

**Zéro logique métier dans les contrôleurs.**

Un contrôleur fait exactement trois choses : récupérer la requête, appeler un service, retourner une réponse.

```php
// ✅ CORRECT
class ImportController extends AbstractController
{
    public function __construct(private readonly ImportService $importService) {}

    #[Route('/admin/import', methods: ['POST'])]
    public function import(Request $request): Response
    {
        $file = $request->files->get('xlsx');
        $result = $this->importService->importFromXlsx($file);
        return $this->render('admin/import/result.html.twig', ['result' => $result]);
    }
}

// ❌ INTERDIT — logique métier dans le contrôleur
class ImportController extends AbstractController
{
    public function import(Request $request, EntityManagerInterface $em): Response
    {
        $file = $request->files->get('xlsx');
        $spreadsheet = IOFactory::load($file->getPathname());
        foreach ($spreadsheet->getActiveSheet()->getRowIterator() as $row) {
            // ... traitement direct ici
        }
    }
}
```

### 7.2 Typage strict — Obligatoire partout

```php
<?php declare(strict_types=1);

// Tous les arguments typés, tous les retours typés
public function generatePdf(Licencie $licencie): string  // retourne le chemin local
public function uploadToDrive(string $localPath, string $drivePath): string  // retourne l'URL Drive
public function sanitizePhone(?string $phone): ?string
```

Jamais de `mixed`, jamais d'argument sans type, jamais de retour sans type sauf `void`.

### 7.3 Responsabilité unique des classes

Chaque classe/service a **une seule raison de changer**.

- `DataSanitizer` → normalise les données brutes du XLSX. Rien d'autre.
- `PdfGeneratorService` → génère un PDF depuis une entité. Rien d'autre.
- `DriveUploaderService` → upload un fichier sur Drive. Rien d'autre.
- `InscriptionFormService` → orchestre la soumission du formulaire public. Appelle les autres services, ne fait rien lui-même.

Si tu te demandes "pourquoi ce code est là ?", c'est que la classe en fait trop.

### 7.4 DTOs pour les entrées/sorties

Ne passe jamais une `Request` brute à un service. Transforme-la en DTO typé d'abord.

```php
// DTO
final class InscriptionFormData
{
    public function __construct(
        public readonly string $tailleHaut,
        public readonly string $tailleBas,
        public readonly string $pointure,
        public readonly bool $autorisationPhoto,
        public readonly ?bool $autorisationTransportDirigeants,
        public readonly ?bool $autorisationTransportParents,
        public readonly string $signatureData,      // base64 canvas
        public readonly PaymentMode $paymentIntention,
    ) {}
}
```

### 7.5 Enums, pas de magic strings

```php
// ✅
$dossier->setStatus(LicenceStatus::FORM_COMPLETED);

// ❌
$dossier->setStatus('form_completed');
```

### 7.6 Twig — Composants réutilisables via include/macro

Quand plusieurs templates partagent la même structure visuelle, extraire en composant Twig.

```twig
{# components/_badge_status.html.twig #}
<span class="badge badge--{{ status }}">
    {{ status|trans }}
</span>
```

```css
/* assets/styles/components/_badge.css */
.badge { padding: 0.25rem 0.5rem; border-radius: var(--radius-xl); font-size: var(--font-size-xs); font-weight: 500; }
.badge--link_sent       { background: var(--color-status-sent-bg);      color: var(--color-status-sent); }
.badge--form_completed  { background: var(--color-status-completed-bg);  color: var(--color-status-completed); }
.badge--validated       { background: var(--color-status-validated-bg);  color: var(--color-status-validated); }
```

Un composant Twig n'a pas de logique métier. Il affiche ce qu'on lui passe.

### 7.6 bis Boutons — le registre est porté par la variante, pas par le libellé

Une variante de bouton dit **ce que l'action fait**, jamais l'importance qu'on lui prête.
Se tromper de variante fait mentir l'interface : un « Annuler » en rouge annonce une
destruction alors qu'il ne fait que refermer un formulaire.

| Variante | Registre | Exemples |
|---|---|---|
| `btn-primary` | l'action attendue de l'écran, une seule par zone | Enregistrer, Créer l'article, Ajouter |
| `btn-secondary` | une autre action, neutre et sans risque | Modifier, Voir les archivés |
| `btn-ghost` | **fermer sans rien faire** | Annuler, ✕ d'un champ en édition |
| `btn-danger` | **perte ou revirement de données** | Supprimer, Annuler une remise de dotation |

Deux pièges vérifiés à la relecture :

- « Annuler » n'est `btn-danger` que lorsqu'il **défait un état enregistré** (annuler une
  remise de dotation régénère un mouvement de stock). Refermer un formulaire, c'est `btn-ghost`.
- Les boutons d'une même rangée partagent leur taille. Un `btn-primary` pleine taille à côté
  d'un `btn-sm` dans la même ligne de tableau est un défaut, pas une mise en avant.

**Placement du pied de formulaire** : le couple Enregistrer / Annuler d'une **page de
formulaire** est **centré** (`justify-content: center`). Seuls les pieds de **modale**
restent alignés à droite — c'est la convention universelle du genre, et la modale n'est
pas une page.

**Annuler mène là où mène Enregistrer.** Si le contrôleur redirige vers `admin_stock_gestion`
après enregistrement, le lien Annuler pointe sur `admin_stock_gestion`, pas sur un écran
voisin : quitter un formulaire ne doit pas déplacer l'utilisateur.

### 7.6 quinquies Le mobile : la page ne défile jamais horizontalement

L'outil se consulte au local, téléphone en main. La règle tient en une phrase : **rien ne
dépasse de la largeur de l'écran**, et ce qui est trop large porte son propre défilement.

- **`.main-content` est en `overflow-x: hidden`**, garde-fou et non solution. Sans lui,
  `overflow-y: auto` forçait l'autre axe à `auto` : le moindre débordement, n'importe où,
  sortait une barre horizontale au niveau de **la page**. Ne pas s'en servir pour masquer un
  contenu large : il deviendrait inatteignable, faute de barre pour l'atteindre.
- **Un `<table class="table">` vit toujours dans un `.table-wrapper`.** C'est lui qui porte
  le cadre et le défilement, avec des ombres de bord qui signalent qu'il reste du contenu.
  `bin/check-tables-scroll.php` (job CI `csp`) refuse un tableau posé sans conteneur.
  Deux variantes : `table-wrapper-nu` quand le tableau est déjà dans une carte (pas de
  second cadre), `table-cartes` pour le passage en cartes ci-dessous.
- **Les listes qu'on consulte passent en cartes sous 640 px** (`.table-cartes`) : joueurs,
  dirigeants, suivi des dotations, clés, flocage, paiements et attestations d'une fiche.
  Marqueurs sur les `<td>` : `data-label="Équipe"` affiche l'intitulé devant la valeur,
  `carte-titre` fait la tête de carte, `carte-meta` une ligne discrète.
- **Les tableaux denses gardent leur défilement** — mouvements de stock, commandes. Empilés,
  ils perdent ce qui fait leur intérêt : la comparaison d'une ligne à l'autre.
- **`body` est en `overflow-wrap: break-word`** : une adresse email un peu longue revient à
  la ligne au lieu d'élargir sa carte, puis la page.
- **Un enfant de grille ou de flex vaut `min-width: auto`** — « jamais plus étroit que mon
  contenu ». C'est la cause n°1 des débordements : une rangée non sécable élargit la colonne
  `1fr`, la carte dépasse l'écran, et son bord droit disparaît sous la coupe. D'où deux
  réflexes : `minmax(0, 1fr)` plutôt que `1fr` dans une grille, `min-width: 0` sur les
  conteneurs qui doivent pouvoir rétrécir, et `flex-wrap: wrap` sur toute rangée
  `space-between` qui met un titre en face d'un bouton.
- **Deux points de rupture**, à respecter pour toute nouvelle règle : **640 px** (téléphone)
  et **1024 px** (tablette). L'existant en compte une dizaine, hérités ; ne pas en ajouter.

### 7.6 quater Une fiche met en avant **une** action, et range les autres

Une fiche accumule les gestes au fil des fonctionnalités. Celle du licencié en alignait cinq
côte à côte, dont trois en `btn-primary` : l'écran ne disait plus lequel comptait, et le
registre du §7.6 bis ne voulait plus rien dire.

Le motif retenu, à reprendre partout où une zone d'actions déborde :

- **Un bouton mis en avant**, et un seul : la **première étape non franchie du parcours**.
  Le choix est fait côté serveur — c'est du métier (l'ordre des étapes, les conditions de
  chaque geste), pas de la mise en forme. Pour la fiche licencié : `FicheActionsResolver`,
  qui rend un `FicheActions` (principale + secondaires + motif de blocage).
- **Le reste dans un menu « ⋯ »** (`components/fiche-menu.css`, ouverture Alpine avec
  `@click.outside` et `x-transition`). Une action destructive n'y est jamais mise en avant :
  elle vit en bas du menu, séparée d'un filet (`fiche-menu-item-danger`).
- **Un seul balisage pour les deux contextes.** `admin/licencies/_action.html.twig` rend le
  même geste en bouton d'en-tête ou en ligne de menu selon `contexte` : dupliquer les deux
  formes ferait diverger un intitulé ou un jeton CSRF au premier changement.
- **Une action injouable ne se cache pas en silence** : si la seule étape du moment part par
  mail et que la personne n'a pas d'adresse, le motif s'affiche à la place du bouton. « Rien
  ne s'affiche » n'apprend rien à l'admin qui cherche le bouton.

### 7.6 ter Un article du stock se désigne par nom · marque · couleur

Le club crée **un article par déclinaison** : plusieurs `StockItem` portent le même `nom`
et ne se distinguent que par leur `marque` et leur `couleur`. Un écran qui n'affiche que
le nom présente donc à l'admin plusieurs lignes identiques entre lesquelles il ne peut pas
choisir.

Partout où un admin doit **reconnaître** un article — un `<select>`, une ligne de kit, un
récapitulatif — la désignation passe par
`components/_stock_item_label.html.twig` :

```twig
{% import 'components/_stock_item_label.html.twig' as article %}
{{ article.label(item) }}     {# Short · Nike · Rouge — nom seul si ni marque ni couleur #}
{{ article.details(item) }}   {# Nike · Rouge, à glisser dans une ligne de méta #}
```

L'ordre `nom · marque · couleur` est celui des écrans de stock ; ne pas en inventer un autre.
Les écrans de stock ajoutent la taille entre les deux, parce qu'elle est portée par l'article ;
côté dotation elle vit sur le `DotationBesoin` (déduite du dossier) et n'a rien à faire dans
la désignation.

### 7.7 Alpine.js — Séparation données/affichage

```html
<!-- ✅ Les données sont dans x-data, le HTML affiche seulement -->
<div x-data="{ step: 1, maxStep: 5 }">
    <div x-show="step === 2">...</div>
    <button @click="step++" x-show="step < maxStep">Suivant</button>
</div>

<!-- ❌ Pas de logique dans les attributs HTML -->
<button @click="if(step < 5 && someCondition && otherThing) { step = step + 1 }">
```

Si la logique Alpine devient complexe, elle va dans un composant JS séparé (`x-data="inscriptionForm()"`).

### 7.8 CSS — Organisation et conventions de nommage

Le CSS est écrit en CSS natif, sans framework utilitaire. `app.css` ne contient que les variables globales et les `@import` — un fichier par template.

**Flexbox obligatoire pour tous les layouts.** Pas de float, pas de positionnement absolu pour faire de la mise en page. Flex (ou Grid pour les layouts 2D complexes) partout.

**Structure des fichiers :**
```
assets/styles/
├── app.css              ← variables :root + @import de tout le reste
├── components/          ← éléments réutilisables sur plusieurs pages
│   └── badge.css
└── pages/               ← un fichier par template Twig
    ├── login.css
    ├── dashboard.css
    └── inscription.css
```

**Convention de nommage : préfixe par page ou composant.**

Chaque classe est préfixée par le nom de la page ou du composant dans lequel elle est définie. Jamais de classe générique sans contexte.

```css
/* login.css → préfixe login- */
.login-page { ... }
.login-card { ... }
.login-card-header { ... }
.login-card-body { ... }
.login-input { ... }
.login-field-last { ... }

/* badge.css → préfixe badge- */
.badge { ... }
.badge-validated { ... }
```

Convention : **tirets simples uniquement**. Pas de `__` ni de `--` BEM. Le préfixe de page/composant est toujours présent.

**Variables uniquement pour l'identité visuelle :**

```css
/* assets/styles/app.css */
:root {
    /* — Identité club — */
    --color-primary:       #ff3131;   /* Rouge club — boutons CTA, headers, badges actifs */
    --color-primary-dark:  #cc0000;   /* Rouge foncé — hover, focus */
    --color-primary-light: #ffe5e5;   /* Rouge pâle — backgrounds de badges, alertes légères */

    /* — Textes — */
    --color-text-base:     #1f1f1f;
    --color-text-body:     #374151;
    --color-text-muted:    #6b7280;
    --color-text-disabled: #9ca3af;
    --color-text-inverse:  #ffffff;

    /* — Fonds — */
    --color-bg-page:   #ffffff;
    --color-bg-subtle: #f9fafb;
    --color-bg-muted:  #f3f4f6;

    /* — Bordures — */
    --color-border:        #e5e7eb;
    --color-border-strong: #d1d5db;

    /* — Statuts licenciés — */
    --color-status-sent:         #3b82f6;
    --color-status-sent-bg:      #eff6ff;
    --color-status-completed:    #f59e0b;
    --color-status-completed-bg: #fffbeb;
    --color-status-validated:    #22c55e;
    --color-status-validated-bg: #f0fdf4;

    /* — Feedback — */
    --color-success:    #22c55e;  --color-success-bg: #f0fdf4;
    --color-warning:    #f59e0b;  --color-warning-bg: #fffbeb;
    --color-danger:     #ef4444;  --color-danger-bg:  #fef2f2;
    --color-info:       #3b82f6;  --color-info-bg:    #eff6ff;

    /* — Typographie — */
    --font-sans:      'Montserrat', system-ui, -apple-system, sans-serif;
    --font-size-xs:   0.75rem;
    --font-size-sm:   0.875rem;
    --font-size-base: 1rem;
    --font-size-lg:   1.125rem;
    --font-size-xl:   1.25rem;
    --font-size-2xl:  1.5rem;

    /* — Espacements — */
    --radius-sm: 0.25rem;
    --radius-md: 0.5rem;
    --radius-lg: 0.75rem;
    --radius-xl: 1rem;

    /* — Ombres — */
    --shadow-card:  0 1px 3px 0 rgb(0 0 0 / 0.1), 0 1px 2px -1px rgb(0 0 0 / 0.1);
    --shadow-modal: 0 10px 15px -3px rgb(0 0 0 / 0.1), 0 4px 6px -4px rgb(0 0 0 / 0.1);
}
```

### Principes visuels à respecter
- **Fond blanc dominant.** Le rouge est un accent, pas un fond de page.
- **Rouge réservé aux éléments forts** : bouton CTA principal, header admin, bandeaux de section, badges de statut actif.
- **Texte anthracite (`--color-text-body`)** pour tout le corps de texte. Noir pur uniquement pour les titres.
- **Tableaux lisibles** : alternance `--color-bg-page` / `--color-bg-subtle` sur les lignes. Bordures légères `--color-border`.
- **Jamais de couleur en dur dans les templates.** Toujours passer par les variables CSS.

---

## 8. Sécurité & RGPD

### Rôles et permissions — les droits sont du code, les rôles sont de la donnée

C'est la règle qui porte tout le dispositif, et celle qui décide de ce qui peut être
configurable.

Une **permission** existe parce qu'une ligne de code la vérifie. La créer depuis un écran
d'administration ne donnerait aucun droit, puisque personne ne la lit — un tel écran
produirait des rôles qui ne protègent rien, et c'est pire qu'une absence de flexibilité
parce que ça rassure. Le catalogue est donc l'enum **`Permission`**, versionnée avec le code
qui l'applique.

Un **rôle** est un paquet de permissions que le club compose lui-même : « Trésorerie » ne
veut pas dire la même chose d'un club à l'autre. C'est l'entité **`RoleAcces`** (`role_acces`),
un `json` de valeurs d'enum, éditable depuis `/admin/club/roles`.

```php
Permission          // enum : domaine, libellé, description, estEcriture(), implique()
DomainePermission   // enum : le groupage d'affichage de l'écran d'un rôle
RoleAcces           // entité : nom, permissions (json), systeme
User.rolesAcces     // ManyToMany — plusieurs rôles, droits cumulés
User.superAdmin     // passe-partout
```

Ce qui ne doit pas se défaire :

- **Pas d'héritage entre rôles.** `role_hierarchy` rend les droits illisibles (« pourquoi la
  présidente peut-elle modifier le stock ? — parce que X hérite de Y qui… »), et un droit
  qu'on ne sait pas expliquer est un droit qu'on n'ose plus retirer. La seule hiérarchie est
  **verticale et interne à un domaine** : `stock.gerer` implique `stock.lire`, déclaré sur
  `Permission::implique()`, déplié **transitivement** par `PermissionCollector`. Un rôle
  reste un ensemble plat.
- **Une écriture entraîne sa lecture, et c'est impossible à produire autrement.**
  `PermissionCollector::completer()` est appelé à chaque enregistrement d'un rôle. Sans ça,
  on compose un rôle qui encaisse un paiement sur une fiche qu'il n'a pas le droit d'ouvrir —
  d'où les rares passerelles inter-domaines (`paiement.lire` → `effectif.lire`,
  `commande.lire` → `stock.lire`).
- **Refus par défaut, et c'est le CI qui le tient.** Toute action de `src/Controller/Admin/`
  déclare `#[IsGranted(Permission::X->value)]` ou, pour une exception assumée,
  `#[AccesLibre('raison')]`. `bin/check-permissions.php` (job CI `csp`) refuse une action qui
  ne déclare ni l'un ni l'autre. Le modèle est facile ; ce qui échoue, c'est la route qu'on
  oublie — et une route oubliée, ici, c'est une lecture seule qui supprime une fiche signée.
  `AccesLibre` ne vaut que pour un **point de navigation** (un hub, la bascule de saison) ou
  un écran qui ne parle **que du compte connecté** (profil, documentation).
- **Le motif : lecture du domaine sur la classe, écriture sur la méthode.** L'oubli le plus
  probable — une nouvelle action d'écriture — tombe alors du côté restrictif.
- **`User.roles` (json) n'est pas `User.rolesAcces`.** Le premier est le tableau de rôles de
  Symfony exigé par `UserInterface` ; il ne porte que `ROLE_USER` et ne sert qu'à la règle
  `^/ → ROLE_USER` de `security.yaml`, qui reste la porte d'entrée. Les droits métier sont
  dans le second.
- **Le super-admin passe partout sans porter aucun rôle**, et il doit toujours en rester au
  moins un (`UserService::definirSuperAdmin`). C'est la sortie de secours qui empêche de se
  verrouiller dehors : un club sans accès à ses propres signatures n'a aucun recours.
  ⚠️ Ce statut était auparavant **déduit** de `DIAG_EMAIL`, l'email de redirection du mode
  bêta : un réglage d'exploitation décidait de qui administrait l'application. Ne pas revenir
  à une dérivation.
- **On masque ce qu'on ne possède pas, on explique ce qu'on ne peut pas jouer.** Les cartes
  de hub (`permission:` sur `hub-card.html.twig`), les quicklinks et les entrées de navbar
  disparaissent — sinon on clique sur six cartes pour six 403. À l'intérieur d'un écran qu'on
  utilise, une action **qu'on ne possède pas** disparaît aussi ; c'est une action possédée
  mais **injouable** (pas d'adresse email, dossier incomplet) qui affiche son motif
  (§7.6 quater). `FicheActionsResolver` fait les deux : il filtre sur
  `FicheAction::permission()`, puis rend le motif de ce qui reste.
- **Un bouton se garde par sa route, jamais par une permission recopiée.**
  `{% if peut_acceder('admin_stock_items_new') %}` — la fonction Twig lit le droit sur le
  contrôleur de la route (`RoutePermissionResolver`, réflexion sur `#[IsGranted]`, carte mise
  en cache). Recopier le droit dans le template (`is_granted('stock.configurer')`) le fait se
  tromper — un « Modifier » gardé par `stock.gerer` alors que la route exige
  `stock.configurer` — et surtout **ne suit pas** : changer la permission d'une action
  laisserait derrière elle un bouton gardé par l'ancienne. `is_granted()` reste le bon outil
  pour ce qui n'est pas un lien : une colonne de tableau, un bloc d'information.
  ⚠️ Masquer n'est pas protéger : le refus reste celui du contrôleur.
- **Le garde-fou est `bin/check-boutons.php`** (job CI `csp`), qui refuse un `path()` menant à
  une route dont l'écran n'exige pas le droit, hors garde. Cent douze actions étaient dans ce
  cas — un rôle « consultation du stock » ouvrait `/admin/stock/gestion` et y trouvait neuf
  boutons qui répondaient tous « Access Denied » : l'application était sûre et illisible.
  L'exception s'écrit pour exister — `{# droits-verifies-cote-serveur: raison #}`, en tête de
  fichier pour tout le template, au-dessus des lignes concernées sinon. Deux angles morts
  connus, à garder en tête plutôt qu'à découvrir : une action de formulaire posée par le
  contrôleur (`createForm(..., ['action' => generateUrl(…)])`) et une garde portée par une
  variable ne se voient pas dans le template — la garde s'y pose à la main.
- **La maille d'une permission, c'est le geste, pas l'écran de menu.** Le domaine « Le club »
  n'avait qu'un cran, `club.configurer` : donner le RIB à la trésorerie lui donnait aussi le
  **signataire des attestations** et les référentiels sportifs. Quatre droits désormais —
  `club.identite` (raison sociale, SIRET, signataire, paraphe), `club.rib`, `club.relances`,
  `club.referentiels` (catégories FFF et tailles). La question à se poser devant une
  permission fourre-tout : *deux fonctions différentes du club voudraient-elles l'une sans
  l'autre ?* Si oui, elle en fait deux. `Version20260830120000` a converti les rôles existants
  — la valeur est stockée en clair dans le `json`, une valeur disparue du catalogue est
  écartée **en silence** par `Permission::depuisValeurs()`.
- **Une porte de hub se garde par son domaine, pas par la liste de ses droits.**
  `{% if possede_un_droit('club') %}` sur la navbar et la carte du tableau de bord : la route
  d'un hub est `#[AccesLibre]`, `peut_acceder()` la déclare donc ouverte — elle l'est, mais
  elle ne mène à rien quand toutes ses cartes sont fermées. À l'intérieur, la section capture
  ses cartes (`{% set %}`) et ne s'affiche que si l'une a survécu. Réénumérer les droits à la
  main dans ces trois endroits en ferait oublier un au prochain réglage ajouté.
- **Les rôles sont au niveau du club, pas de la saison.** La trésorière l'est toutes les
  saisons ; les cloisonner obligerait à les réaffecter chaque 1ᵉʳ juillet, et le premier oubli
  fermerait l'outil en pleine campagne d'inscriptions.
- **`RolesSysteme` livre deux rôles seulement** — Responsable foot et Trésorerie —, créés par
  `Version20260829200000` puis maintenus par `app:seed-referential`. Ce sont les deux fonctions
  qui existent dans tous les clubs ; en livrer davantage reviendrait à deviner l'organigramme
  du club à sa place, et un rôle livré inutilisé encombre l'écran sans pouvoir être supprimé.
  Ils se renomment et se modifient librement, mais **ne se suppriment pas** : il reste toujours
  de quoi rouvrir un accès. Le seed est idempotent **au sens strict** — un rôle déjà présent
  n'est pas remis à ses permissions d'origine, sinon chaque déploiement effacerait les
  ajustements du club. Les noms désignent des **fonctions**, pas des personnes.
- **Un rôle ne porte pas de description** : les permissions cochées disent déjà ce qu'il fait,
  et mieux qu'une phrase que personne ne relit quand les cases changent.
- **Hors périmètre, et pas par oubli** : le périmètre par équipe (« l'éducateur des U15 ne
  voit que ses U15 ») n'est pas une permission mais un jugement porté sur un **sujet**, donc
  un autre voter et un filtrage de chaque requête de liste — où l'oubli d'un filtre est
  invisible. Rien dans ce modèle ne l'empêche plus tard.

La migration a attribué « Responsable foot » à **tous les comptes existants** : jusque-là,
tout compte connecté pouvait tout faire, et un déploiement de sécurité qui commence par
bloquer les gens en place se fait annuler dans l'heure.

### Accès public
- Le lien `/inscription/{uuid}` est valide **30 jours** après génération.
- Après soumission, le lien devient invalide (token consommé).
- L'admin peut régénérer un lien depuis la fiche licencié.
- Aucune donnée sensible dans l'URL (seulement l'UUID, pas le nom).

### Stockage
- Signature et PDF : **jamais stockés en local de façon permanente**. Upload Drive → suppression locale immédiate.
- Données médicales : **non collectées** en V1.
- Numéro de sécurité sociale : **non collecté**.
- Attestation de conduite parents (Section F des anciens formulaires) : **non collectée** en V1.

### Variables d'environnement (jamais dans le code)
```
GOOGLE_DRIVE_CREDENTIALS_JSON=
GOOGLE_DRIVE_FOLDER_ID=
MAILER_DSN=
APP_SECRET=
DATABASE_URL=
```

---

## 9. Règles de Nommage

| Élément | Convention | Exemple |
|---|---|---|
| Entités | PascalCase | `Licencie`, `DossierClub` |
| Services | PascalCase + suffixe de rôle (cf. ci-dessous) | `ImportService`, `PdfRenderer` |
| DTOs | PascalCase, suffixe `Data` pour une entrée de formulaire | `InscriptionFormData`, `DotationAvancement` |
| Enums | PascalCase | `LicenceStatus`, `PaymentMode` |
| Controllers | PascalCase + Suffix `Controller` | `ImportController` |
| Routes admin | snake_case préfixé | `admin_licencies_list` |
| Routes public | snake_case préfixé | `public_inscription_show` |
| Templates | snake_case | `admin/licencies/list.html.twig` |
| Variables Twig | camelCase | `{{ licencie.nomPrenom }}` |

### Suffixe des services : le rôle, pas le mot « Service »

`Service` est le suffixe par défaut, pas le seul autorisé. Quand une classe a un rôle
plus précis, le nom le dit — c'est plus informatif que `XxxService` :

| Suffixe | Rôle | Exemple |
|---|---|---|
| `Service` | orchestre une opération métier | `LicencieService`, `PaiementService` |
| `Resolver` | choisit une valeur parmi plusieurs règles | `CotisationResolver`, `DotationResolver` |
| `Factory` | construit un DTO depuis une `Request` | `InscriptionFormRequestFactory` |
| `Presenter` | met en forme pour l'affichage, n'écrit rien | `DotationSuiviPresenter` |
| `Synchronizer` | aligne un état sur un autre, idempotent | `DotationBesoinSynchronizer` |
| `Sync` | archive un fichier local vers Drive | `DocumentSignatureDriveSync` |
| `Renderer`, `Storage`, `Encoder` | infrastructure technique | `PdfRenderer`, `PdfStorage` |
| `Context`, `Guard`, `Collector`, `Filter` | rôle explicite en un mot | `SeasonContext`, `CsrfGuard` |

Règle de tri : **une classe sans suffixe de rôle est une erreur**, sauf pour un référentiel de
constantes (`Tailles`, `LienPublic`). Un lecteur doit savoir ce que fait la classe avant
de l'ouvrir.

### Organisation de `src/Service/`

Un dossier = un domaine métier, pas une couche technique. `Licencie/`, `Dirigeant/`,
`Inscription/`, `Dotation/`, `Stock/`, `Cle/`, `Saison/`, `Referentiel/`, `Compte/`,
`Document/`, `Payment/`, `Import/`, `Planning/` (matchs à domicile), `Relance/`, `Boutique/`,
`Effectif/`, `Mail/`, `Pdf/`, `Drive/`, `Ops/` (exploitation), `Ui/` (état d'affichage).

**Aucun service à la racine de `src/Service/`.** Si le domaine d'une nouvelle classe
n'est pas évident, c'est le signe qu'elle en fait trop.

---

## 10. Ce qu'il ne faut pas faire — Liste Noire

- ❌ Logique métier dans un contrôleur
- ❌ Requête SQL / Doctrine dans un contrôleur
- ❌ Magic strings pour les statuts, modes de paiement, types de mouvement
- ❌ Credentials Google Drive dans le code source ou le repo Git
- ❌ Données médicales ou numéro de sécu dans le formulaire public
- ❌ Supprimer des données licencié lors d'un nouvel import XLSX
- ❌ Utiliser `Category::isEcoleFoot` pour décider d'un comportement métier (c'est `isJeune()`)
- ❌ React / SPA / API REST (hors scope V1)
- ❌ Logique conditionnelle complexe dans les templates Twig (ça va dans un service ou un helper)
- ❌ Classes CSS en dur dans le PHP
- ❌ Utiliser dans `src/` une classe fournie par une dépendance de `require-dev` : l'image de
  prod installe `--no-dev`, la classe manque et le code casse **en production uniquement**.
  Tout paquet utilisé par `src/`, `bin/`, `config/` ou `public/` va dans `require`.
  Garde-fou : `bin/check-prod-deps.php`, joué par le job CI `dependances-prod`.
- ❌ Ajouter une action à `src/Controller/Admin/` sans `#[IsGranted(Permission::…)]` ni
  `#[AccesLibre('raison')]` : elle serait ouverte à tout compte connecté. Garde-fou :
  `bin/check-permissions.php`, joué par le job CI `csp`.
- ❌ Créer une table `permission` en base, ou un écran qui inventerait des permissions : une
  permission n'existe que parce qu'une ligne de code la vérifie (§8).
- ❌ Afficher un bouton ou un lien vers une route d'écriture sans garde : l'admin clique et
  reçoit « Access Denied », ce qui ne lui apprend rien. Utiliser
  `{% if peut_acceder('nom_de_la_route') %}`, et non un `is_granted()` qui recopie le droit.
  Garde-fou : `bin/check-boutons.php`, joué par le job CI `csp`.
- ❌ Redériver le statut de super-admin d'un réglage d'exploitation (`DIAG_EMAIL` ou autre) :
  c'est un fait porté par le compte.

### Schéma & données (la prod contient des données réelles — cf. bandeau en tête et §13)

- ❌ `db-reset`, `doctrine:database:drop` ou `doctrine:schema:update --force` sur une base contenant des données
- ❌ Ré-éditer une migration déjà déployée (fait diverger les bases) — corriger par une nouvelle migration
- ❌ `ADD COLUMN ... NOT NULL` sans `DEFAULT` ni backfill sur une table déjà remplie
- ❌ Proposer un `DROP` de colonne ou de table sans avoir signalé la perte de données
- ❌ Déployer une migration sans dump préalable (`make prod-backup` est intégré à `make prod-deploy`)

---

## 11. Ordre de Développement Recommandé (V1)

1. **Setup** : Symfony, PostgreSQL, CSS natif, Alpine.js, DomPDF, PhpSpreadsheet
2. **Entités & migrations** : toutes les entités + enums
3. **Auth admin** : login simple email/password
4. **Import XLSX** : `ImportService` + `DataSanitizer` + UI drag & drop
5. **Envoi mail** : génération UUID + template mail avec lien
6. **Formulaire public** : multi-étapes Alpine.js + signature pad
7. **Génération PDF + Drive** : `PdfGeneratorService` + `DriveUploaderService`
8. **Dashboard admin** : tableau licenciés filtrable + badges statut
9. **Confirmation paiement** : modal admin + création `Transaction`
10. **Fiche licencié** : vue détail complète
11. **Gestion stock** : `StockItem`, `StockMovement`, aide à la commande

## 12. Workflow Git
 
### Branches
 
| Branche | Rôle | Push direct |
|---|---|---|
| `développement` | Tout le dev actif | ✅ Autorisé |
| `main` | Code propre et validé | ❌ Bloqué — PR obligatoire |
| `production` | Code déployé en prod | ❌ Bloqué — PR obligatoire |
 
### Workflow
 
```
développement → PR → main → PR → production
                                      ↓
                               déploiement automatique
```
 
1. Tout le développement se fait sur `développement`
2. Une fois une feature terminée et testée → PR de `développement` vers `main`
3. Quand on veut mettre en prod → PR de `main` vers `production` → déploiement automatique déclenché
### Règles de merge
 
- **Jamais de fast-forward** — toujours `--no-ff` pour garder l'historique lisible
- **Jamais de commit direct** sur `main` ou `production` — même en urgence
- Une PR = une feature ou un fix, pas un mois de développement groupé
- Ne pas laisser `développement` dériver trop longtemps sans merger sur `main`
### Nommage des PRs
 
Format : `type: description courte`
 
| Type | Usage |
|---|---|
| `feat` | Nouvelle fonctionnalité |
| `fix` | Correction de bug |
| `refactor` | Refactoring sans changement de comportement |
| `style` | CSS / UI uniquement |
| `chore` | Config, dépendances, CI |
 
Exemples :
- `feat: formulaire public étape signature`
- `fix: import XLSX doublon sur nom composé`
- `chore: ajout règles GitHub branch protection`
### Ce que Claude Code ne doit jamais faire
 
- ❌ Commiter directement sur `main` ou `production`
- ❌ Proposer un `git push --force` sur une branche protégée
- ❌ Grouper plusieurs features sans rapport dans un seul commit

## 13. Évolution du Schéma en Production

> **C'est le cas dès maintenant.** La prod contient des signatures manuscrites, des PDF signés, des
> autorisations parentales et des encaissements HelloAsso : **toute évolution doit transformer
> l'existant sans le perdre**. La logique métier (services, contrôleurs, formulaires, front) se code
> exactement pareil — seule la **façon de faire évoluer le schéma** se ritualise.

### Règle centrale
La migration Doctrine est le **contrat** entre deux états de la base. Elle doit toujours savoir
migrer les **données déjà présentes**, pas seulement créer des tables vides.

### Les 5 règles d'or

1. **Toute modification d'entité → une migration** (`php bin/console make:migration`).
   **Jamais** `doctrine:schema:update --force` en prod.
2. **Ne jamais ré-éditer une migration déjà déployée.** Une erreur se corrige par une *nouvelle*
   migration. Modifier une migration appliquée fait diverger les bases.
3. **Toujours relire le SQL généré** avant d'appliquer. L'auto-génération propose parfois des
   `DROP` dangereux à retoucher.
4. **Dump de la base avant chaque migration prod.** PostgreSQL exécute le DDL en transaction
   (une migration qui plante est annulée), mais le backup reste le vrai parachute.
5. **Tester la migration sur une copie des données prod**, pas sur une base vide
   (`dump prod → restore local → migrate`). C'est le test qui change avec la prod.

### Piège n°1 — colonne obligatoire sur une table déjà remplie

`ADD COLUMN … NOT NULL` sur une table contenant des lignes **échoue**. Pattern **expand / backfill / contract** :

```
1. Expand   : ajouter la colonne nullable (ou avec DEFAULT)
2. Backfill : remplir les lignes existantes (UPDATE dans la migration)
3. Contract : passer en NOT NULL une fois rempli
```

Exemple déjà appliqué dans le projet : `Version20260622180000` (création `dirigeant_role` + INSERT des
rôles + UPDATE de backfill + contrainte FK ensuite).

### Renommer / supprimer = destructif

`DROP COLUMN` / `DROP TABLE` perd les données, **irréversiblement**. Pour un renommage critique :
`add new → copy (UPDATE) → drop old`, au besoin sur deux déploiements. Réfléchir avant tout `DROP`.

### Workflow par changement (avec données en prod)

1. Modifier l'entité
2. `make:migration` → **relire le SQL** (`make prod-migrate-dry` affiche le SQL sans l'appliquer)
3. Restaurer un dump prod en local → `doctrine:migrations:migrate` → vérifier que les données survivent
4. `make test` (les tests tournent sur base migrée)
5. Déployer : **backup** → `doctrine:migrations:migrate` → contrôle
   (`make prod-deploy` enchaîne `prod-backup` puis `prod-migrate` — le dump est automatique)

### Sauvegardes

La sauvegarde est **automatique et externalisée**, pas à déclencher à la main :

| Quoi | Où |
|---|---|
| Dump nightly (02h30) | commande `app:db:backup`, planifiée dans le conteneur `cosync_cron` |
| Copie locale | volume `cosync_backups` → `var/backups/backup_YYYYmmdd_HHMMSS.sql.gz`, rétention 30 jours |
| Copie off-site | Google Drive du club → `Sauvegardes/{YYYY-MM}/` (même Service Account que les PDF) |
| Dump avant migration | `make prod-deploy` appelle `prod-backup` avant `prod-migrate` |

Commandes utiles :

```
make prod-backup                  # dump immédiat (local + Drive)
make prod-backup-list             # lister les dumps disponibles
make prod-restore FILE=backup_….sql.gz
```

**Un backup jamais restauré n'est pas un backup.** Faire une répétition de restauration sur une base
locale au moins une fois par saison : `make prod-restore` puis vérifier les comptes de lignes de
`licencie`, `dossier_club`, `transaction`, `document_signature`.

⚠️ Ne sont **pas** couverts par ce mécanisme : la configuration de Nginx Proxy Manager (certificats
TLS, domaines) et le contenu du Drive lui-même. À sauvegarder séparément.

### Référentiels & seeds
Peupler les référentiels (catégories FFF, rôles dirigeants) via migration **ou** la commande
`SeedReferentialCommand` (idempotente). Ne jamais en dépendre d'une purge — la purge (`PurgeService`)
les **conserve** et reste réservée au **mode beta**.

### La donnée du club ne passe pas par une migration

Un référentiel vaut pour toute base ; **l'inventaire du local de Soudron n'existe qu'une fois**.
Porté par une migration, il était rejoué sur chaque base construite depuis zéro — dont la base de
test de la CI, qui héritait de 87 articles et 95 mouvements avant le premier test. Treize tests
sont tombés : ceux qui interrogent le stock entier, et — plus retors — ceux qui insèrent après
`PurgeServiceTest`, dont le `setval(sequence, 1, false)` **n'est pas annulé** par la transaction
de `dama/doctrine-test-bundle` alors que les lignes semées, elles, sont restaurées. Les ids
repartaient de 1 et percutaient le semis.

Une reprise de données est donc une **commande console idempotente**, lancée à la main une fois
sur la prod — `InventaireAout2026Command` en est le modèle : garde-fous avant la première
écriture (catégories et auteur présents, sinon `FAILURE`), création gardée par « n'existe pas
déjà », entrée de stock gardée par « aucun mouvement encore ». Le critère de tri : *cette donnée
aurait-elle un sens dans une base neuve ?* Si non, ce n'est pas une migration.

### Ce que Claude Code ne doit jamais faire (schéma)
- ❌ `doctrine:schema:update --force` sur une base contenant des données
- ❌ Modifier une migration déjà appliquée en prod
- ❌ `ADD COLUMN NOT NULL` sans DEFAULT ni backfill sur une table non vide
- ❌ Proposer un `DROP` de colonne/table sans avoir signalé la perte de données
- ❌ Semer de la donnée du club (inventaire, effectif, paiements) depuis une migration : elle
  atterrirait dans toute base neuve, à commencer par celle de la CI (cf. ci-dessus)