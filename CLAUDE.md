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
telephone: ?string
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
status: LicenceStatus  // IMPORTED | LINK_SENT | FORM_COMPLETED | VALIDATED
```

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
- **Une taille non couverte donne `null`**, jamais une valeur approchante. Le besoin reste
  « à renseigner » dans le suivi, et l'admin tranche. Mieux vaut un trou visible qu'une
  déclinaison inventée. L'écran de la grille annonce ces trous avant qu'ils ne se voient.
- Le point d'insertion unique est **`StockTailleResolver`** : `traduire()` pour la dotation
  (appelé par `DotationResolver::sizeFor()`), `options()` pour restreindre la saisie d'un
  mouvement aux déclinaisons réellement vendues. En aval, remise, ventilation, achat et bon de
  commande parlent déjà « la taille du besoin » et suivent tout seuls.
- L'ordre d'affichage reste celui du **référentiel**, pas de la grille : les libellés
  fournisseur y figurent déjà, `TailleReferentiel::comparer()` les range sans rien savoir des
  grilles.

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
local, sa fiche continue donc d'afficher le registre et son attestation.

Ne pas remplacer ce drapeau par trois réglages indépendants : c'est justement ce qui faisait
oublier l'un des trois.

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
    case VALIDATED = 'validated';         // paiement soldé (ou validation manuelle)
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
```

---

## 6. Workflows Métier Clés

### A. Import XLSX FootClubs

**Comportement attendu : idempotent, jamais destructeur.**

#### Procédure d'export depuis FootClubs (documentée dans l'UI `/admin/import`)
1. Menu gauche → **Licenciés → Éditions et extractions**
2. Sélectionner **Édition licenciés**
3. Catégories : **tout sélectionner** (clic sur la première, puis Shift+clic sur la dernière)
4. Format : **Extraction MS Excel**
5. Sortie et tri : **Complet** ← important, donne les colonnes avec emails et mobiles
6. Cliquer **Valider**

#### Colonnes utiles du fichier
| Colonne FootClubs | Champ CoSync |
|---|---|
| `Numéro personne` | `num_licence` — clé d'upsert |
| `Nom, prénom` | split → `nom` (MAJUSCULES) + `prenom` (Capitalize) |
| `Né(e) le` | `date_naissance` |
| `Sous catégorie` | `category` |
| `Email principal` | `email` |
| `Mobile personnel` | `telephone` |
| Toutes les autres | ignorées |

#### Traitement dans CoSync
1. Admin drag & drop le fichier XLSX sur `/admin/import`
2. `ImportService` lit le fichier via PhpSpreadsheet
3. `DataSanitizer` normalise chaque ligne :
   - Ignorer les lignes où `Type licence` ≠ `Libre` (pas de dirigeants, éducateurs)
   - Nom en MAJUSCULES, Prénom en Capitalize
   - Téléphone : supprime espaces/tirets, format +33
   - Email : trim + lowercase
4. Pour chaque ligne : `upsert` sur `num_licence` (`Numéro personne`)
   - Si le licencié existe : mise à jour des données FFF uniquement. Les données club (DossierClub, Transaction) ne sont jamais touchées.
   - Si nouveau : création + génération UUID, **sans aucun mail**
5. Rapport d'import affiché : X mis à jour, Y créés, Z erreurs

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
  - 🟢 `VALIDATED` — Validé

Action depuis le tableau :
- "Confirmer paiement" → modal rapide (mode, montant, référence) → crée `Transaction` + passe statut à `VALIDATED`
- "Renvoyer le lien" → rouvre la fenêtre de 30 jours et renvoie le mail.
  ⚠️ L'UUID n'est **pas** régénéré, volontairement : le régénérer invaliderait les liens déjà
  distribués, ce qui casserait les licenciés en cours de saisie.
- Clic sur une ligne → fiche détail du licencié

### D. Archivage Drive

```
Drive/
└── FC Soudron/
    ├── 2025-2026/
    │   ├── Documents signés/
    │   │   └── Règlement intérieur/
    │   │       └── RI_DUPONT_Thomas.pdf
    │   ├── Attestations Transport/
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

### E. Tâches planifiées (conteneur `cosync_cron`)

| Fréquence | Commande | Pourquoi |
|---|---|---|
| toutes les 15 min | `app:drive-retry-upload` | rattrape les PDF restés en local |
| toutes les 30 min | `app:helloasso:sync-paiements` | rattrape un encaissement dont la notification n'est jamais arrivée — sans lui, le club encaisse sans que la licence passe en validée |
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
`Document/`, `Payment/`, `Import/`, `Mail/`, `Pdf/`, `Drive/`, `Ops/` (exploitation),
`Ui/` (état d'affichage).

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

### Ce que Claude Code ne doit jamais faire (schéma)
- ❌ `doctrine:schema:update --force` sur une base contenant des données
- ❌ Modifier une migration déjà appliquée en prod
- ❌ `ADD COLUMN NOT NULL` sans DEFAULT ni backfill sur une table non vide
- ❌ Proposer un `DROP` de colonne/table sans avoir signalé la perte de données