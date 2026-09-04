# Décisions métier — CoSync

Ce document est la **mémoire longue** du projet : pour chaque domaine, l'invariant qui le tient et
le bug qu'il évite. Il n'est pas à lire en entier — on l'ouvre sur le domaine qu'on s'apprête à
toucher. Les règles transverses (code, front, droits, migrations) sont dans `CLAUDE.md`.

Ce qui est écrit ici décrit le modèle **réel**, pas l'intention d'origine. Les écarts avec la V1
sont assumés, pas à « corriger ».

---

## Sommaire

- [Saison, catégorie, équipe](#saison-catégorie-équipe)
- [Licencié — coordonnées et verrous d'import](#licencié--coordonnées-et-verrous-dimport)
- [Dossier club — « payé » et « validé » sont deux faits](#dossier-club--payé-et-validé-sont-deux-faits)
- [Tailles — référentiel, deux publics, grilles fournisseur](#tailles--référentiel-deux-publics-grilles-fournisseur)
- [Stock — écoulement, notes, correction, retrait](#stock--écoulement-notes-correction-retrait)
- [Dotations — qui reçoit quel kit, flocage](#dotations--qui-reçoit-quel-kit-flocage)
- [Dirigeants — licence administrative](#dirigeants--licence-administrative)
- [Attestations de paiement](#attestations-de-paiement)
- [Mails — journal, relance automatique](#mails--journal-relance-automatique)
- [ClubSettings — identité de l'association](#clubsettings--identité-de-lassociation)
- [Planning des matchs à domicile](#planning-des-matchs-à-domicile)
- [Clés du local](#clés-du-local)
- [Import XLSX FootClubs](#import-xlsx-footclubs)
- [Formulaire public d'inscription](#formulaire-public-dinscription)
- [Suppression d'une fiche entrée par erreur](#suppression-dune-fiche-entrée-par-erreur)
- [Archivage Drive](#archivage-drive)
- [Tâches planifiées](#tâches-planifiées)
- [Permissions — le détail des règles](#permissions--le-détail-des-règles)

---

## Saison, catégorie, équipe

```php
Season   // label, cotisation_defaut, attestation_cle_text, created_at
Category // code ("U6"…"SENIOR"), label, is_ecole_foot, min_year, max_year
Team     // name ("U15 A", "Séniors 1"), season, cotisation
```

Il n'y a **pas de saison active globale** : chaque admin travaille dans la saison de son choix
(`User.selectedSeason` + session, via `SeasonContext`). Le montant dû est résolu par
`CotisationResolver` — équipe d'abord, défaut de la saison ensuite.

**`Category::isJeune()`** (`str_starts_with($code, 'U')`) décide de l'affichage des autorisations
parentales — donc U14→U19 sont traités comme des jeunes, contrairement à `is_ecole_foot`, qui est
saisi en admin mais qu'**aucune logique métier ne lit**. `min_year` / `max_year` sont des colonnes
mortes, jamais renseignées.

Les colonnes `reglement_text` et `reglement_dirigeant_text`, non mappées depuis la bascule vers
`DocumentSignable`, ont été supprimées par `Version20260809103000` — avec celles de signature de
`dossier_club` et `dirigeant`. La migration recompte les données historiques et refuse de
s'appliquer si une seule n'a pas son équivalent dans `document_signature` / `document_signable`.

---

## Licencié — coordonnées et verrous d'import

```php
Licencie // uuid (clé publique du formulaire), num_licence (clé d'upsert, nullable),
         // nom, prenom, date_naissance, email + email_manuel, telephone + telephone_manuel,
         // adresse, category, team (assignée à la main), season,
         // nature_licence + nature_manuelle, form_token_expires_at, link_sent_at,
         // boutique_annoncee_at, created_manually, imported_at
```

FootClubs fait foi sur l'identité, jamais sur la **joignabilité** : une adresse fausse empêche le
lien d'inscription d'arriver, et elle ne peut pas toujours se corriger là-bas le jour même
(dossier en cours de validation à la ligue). D'où le verrou `email_manuel` / `telephone_manuel`,
porté à l'identique par `Licencie` et par `Dirigeant` :

- l'admin corrige depuis `/admin/effectif/joueurs/{uuid}/coordonnees` — ouvert à **tous**, y
  compris aux fiches importées, contrairement à l'écran d'identité qui reste réservé aux fiches
  saisies à la main. Côté dirigeant, l'écran de modification existant suffit ;
- le verrou se pose **au changement de valeur**, pas à l'enregistrement : rouvrir l'écran et
  valider sans rien toucher ne fige rien. Il est posé par `LicencieService` et `DirigeantService`,
  jamais par l'import ;
- `ImportService` saute le champ verrouillé — pour ce champ seul, l'autre continue de suivre
  l'export ;
- une fois FootClubs corrigé, la fiche affiche « corrigé à la main » et un bouton **Reprendre
  FootClubs** (`reprendreImport()`) qui relâche le verrou. Sans cette sortie, CoSync ignorerait
  l'export pour toujours sur ce champ, même une fois la donnée bonne des deux côtés.

---

## Dossier club — « payé » et « validé » sont deux faits

```php
DossierClub // licencie (1-1), taille_haut, taille_bas, pointure,
            // autorisation_photo, autorisation_accident,
            // autorisation_transport_dirigeants, autorisation_transport_parents,
            // volontaire_transport, attestation_transport_drive_id,
            // payment_intentions (json — le paiement peut être fractionné),
            // dotation_choix (json), dotation_personnalisation (json),
            // helloasso_checkout_intent_id, helloasso_checkout_started_at,
            // form_completed_at, status: LicenceStatus

Transaction // licencie, montant, mode: PaymentMode, reference, date_paiement,
            // confirmed_by: User, season
```

Une licence soldée dans CoSync n'est pas une licence validée : le club doit encore la **signer
dans FootClubs**, et rien ne peut faire ce geste à sa place — les deux outils ne se parlent pas.
Tant qu'un seul statut portait les deux, le club n'avait aucun moyen de savoir ce qu'il lui
restait à faire côté fédéral.

| Fait | Statut | Qui le pose |
|---|---|---|
| Le total encaissé atteint la cotisation due | `A_VALIDER_FFF` | `PaiementService`, automatiquement (saisie manuelle, HelloAsso vérifié, ou « Valider quand même ») |
| Le club a signé la licence dans FootClubs | `VALIDATED` | un admin, depuis la fiche ou l'écran groupé `/admin/effectif/joueurs/valider-footclubs` |

Ce qui ne doit pas se défaire :

- **Tout ce qui demandait « la licence est-elle validée ? » demandait en réalité « a-t-elle
  payé ? »** — droit à la dotation, sortie de stock, réconciliation HelloAsso, compteurs du hub
  Effectif. Le point de lecture unique est **`LicenceStatus::estSolde()`** (et
  `DossierClub::estSoldee()`, `LicenceStatus::soldes()` pour les requêtes). Tester
  `=== VALIDATED` en aval suspendrait le kit d'un licencié payé à un clic administratif sans
  rapport.
- **Le mail « votre licence est validée » part au solde**, pas à la validation FootClubs : c'est
  l'encaissement qui intéresse le licencié, la démarche fédérale est interne au club.
- **La validation se défait** (`annulerValidationFootclubs`). Sans cette sortie, un clic de trop
  ferait disparaître pour toujours une licence qu'il reste réellement à signer.
- **Aucune donnée n'a été rétro-migrée** : les dossiers déjà en `validated` avant la bascule le
  restent. Ce n'est pas un backfill oublié (cf. `Version20260828211444`).

Côté **dirigeant**, le même dernier geste existe (`Dirigeant.validatedFffAt`, un fait daté), mais
son avancement n'est **pas stocké** : tout ce qui le compose est déjà en base — lien parti,
formulaire soumis, documents signés, licence validée. `DirigeantStatutResolver` le calcule
(`DirigeantStatut`), `pourLot()` pour les listes. L'ordre des règles est la règle : `VALIDE` passe
avant `LICENCE_ADMINISTRATIVE`, qui n'attend ni lien ni document mais existe bien à la FFF et se
signe comme les autres.

---

## Tailles — référentiel, deux publics, grilles fournisseur

Les tailles ne sont plus une constante PHP : elles vivent dans la table `taille`, réglée depuis
`/admin/club/tailles` (ordre au glisser-déposer). `TailleReferentiel` lit, `TailleService` écrit.

```php
Taille // libelle, type (VETEMENT|POINTURE), groupe, proposeeAuxLicencies, position
```

- `groupesProposes()` sert les **formulaires** : une personne n'y déclare que ce qu'elle sait dire
  d'elle-même — adulte, ou enfant **en âge**.
- `pourLeStock()` sert le **stock** : tout le référentiel du type, étiquetages fournisseur compris
  (`104`…`176`, `XS enfant`…`XL enfant`). C'est `proposeeAuxLicencies = false` qui fait la
  différence. Ne pas remonter ces déclinaisons dans les formulaires : un parent ne sait pas si le
  maillot de son enfant est un 128, et la taille déduite pour la dotation en sortirait fausse.

**Le libellé est une clé de fait, pas un simple label** : il est recopié tel quel dans
`dossier_club`, `dirigeant`, `stock_movement`, `dotation_besoin` et `stock_taille_note`.
`TailleService` refuse donc de le **renommer** ou de le **supprimer** dès qu'un enregistrement le
désigne — on décoche « proposée » à la place. L'ordre du référentiel est celui de **tous** les
sélecteurs, public compris.

### GrilleTaille — traduire le déclaré en étiquette fournisseur

Séparer les deux publics ne suffisait pas : il fallait encore **passer de l'un à l'autre**. Un
licencié déclare « 44 » ou « 12 ans » ; le fournisseur vend en « 43-46 » et en « 128 ». Sans
traduction, la dotation sortait du stock une déclinaison qui n'existe à aucun carton — le compteur
du « 44 » partait en négatif pendant que celui du « 43-46 » ne bougeait pas.

```php
GrilleTaille       // nom, type (VETEMENT|POINTURE), valeurs
GrilleTailleValeur // cible: Taille (le libellé du carton), couvertures: Taille[] (le déclaré)
StockItem          // grilleTaille: ?GrilleTaille
```

- **Les deux côtés sont des `Taille`**, jamais du texte libre : la cible est recopiée dans
  `stock_movement` et `dotation_besoin`, elle doit donc exister au référentiel — sinon la saisie
  d'un mouvement ne la proposerait même pas. `TailleService` compte les grilles parmi les emplois :
  une taille traduite ou couverte ne se renomme ni ne se supprime plus.
- **`grilleTaille` nullable = pas de traduction**, et c'est le cas courant : le maillot adulte se
  vend dans les tailles du formulaire. On ne crée une grille que quand le fournisseur a son propre
  barème.
- **Une taille déclarée mène à un seul libellé.** `GrilleTailleService` refuse le chevauchement :
  deux plages pour une même pointure rendraient la traduction indécidable.
- **Une grille ne traduit que ce qu'elle mentionne** : une taille qu'aucune ligne ne couvre passe
  **telle quelle**, jamais une valeur approchante. C'est le cas courant d'un fournisseur qui ne
  relabellise qu'une partie de sa gamme — les vestes enfant en `140`, les adultes en `L`. Rendre
  `null` (la V1) obligeait à écrire « L couvre L », « M couvre M »… pour tout le reste du
  référentiel : une cérémonie que personne ne comprend, qu'on oublie, et dont l'oubli envoyait
  **chaque adulte** en « à renseigner ». Le prix assumé : une taille que le fournisseur ne vend
  réellement pas ressort telle quelle au lieu de signaler un trou — c'est au bon de commande qu'on
  le voit, et l'écran de la grille liste ce qui passe sans traduction.
- **`options()` suit la même règle** que `traduire()`, et il le faut : une taille que la dotation
  sert doit pouvoir se saisir en mouvement de stock. La grille écarte les tailles qu'elle
  **traduit** (le `10 ans` se range en `140`), pas celles qu'elle ignore.
- Le point d'insertion unique est **`StockTailleResolver`** : `traduire()` pour la dotation
  (appelé par `DotationResolver::sizeFor()`), `options()` pour restreindre la saisie d'un mouvement
  aux déclinaisons réellement vendues. En aval, remise, ventilation, achat et bon de commande
  parlent déjà « la taille du besoin » et suivent tout seuls.
- L'ordre d'affichage reste celui du **référentiel**, pas de la grille : les libellés fournisseur
  y figurent déjà, `TailleReferentiel::comparer()` les range sans rien savoir des grilles.

---

## Stock — écoulement, notes, correction, retrait

```php
StockItem     // nom, marque, couleur, ref_catalogue, lien_achat, note, season,
              // typeVetement, grilleTaille, remplaceArticle
StockMovement // item, quantite, type: StockMovementType (ENTREE|SORTIE|REBUT),
              // licencie (si sortie liée à un joueur), taille, note, created_by, created_at
```

### Écoulement — servir l'ancien stock avant de commander du neuf

Le club change de fournisseur sans jeter ce qui reste : les chaussettes du kit sont des ERIMA,
mais il dort des Nike au local. Sans arbitrage, le besoin porte l'article du kit, `AchatService`
ne déduit que **son** stock, et le club rachète du neuf par-dessus un carton plein.

```php
StockItem.remplaceArticle: ?StockItem         // « je m'écoule à la place de celui-ci »
DotationBesoin.articleEcoulement: ?StockItem  // l'article réellement servi (null = celui du kit)
DotationBesoin.articleManuel: bool            // l'admin a épinglé, l'arbitrage ne touche plus
```

- **La règle est portée par l'article à écouler**, et une seule fois pour le club — pas kit par
  kit. Un club change de fournisseur une fois ; la déclarer dans chaque `DotationModele` ferait
  oublier l'un des cinq et l'écoulement ne se ferait qu'à moitié.
- **Mais elle se déclare dans l'autre sens**, sur `/admin/stock/ecoulement` : l'article principal —
  celui qu'on commande désormais — en tête, les anciens stocks fléchés en dessous. C'est ainsi que
  la décision se prend (« je passe à l'ERIMA, il me reste des Nike »), et la poser depuis la fiche
  du Nike se lisait à l'envers : la règle existait, personne ne la retrouvait, et elle a été saisie
  à l'envers en prod. `EcoulementPresenter` retourne la lecture,
  `StockItemService::appliquerEcoulement()` reste seul à écrire. La fiche article n'en garde qu'une
  **mention en lecture seule** — la rebrancher au formulaire effacerait la règle à chaque
  enregistrement, le champ n'y étant plus. Corollaire : un article engagé dans une correspondance
  refuse de changer de `typeVetement` tant qu'elle n'est pas retirée.
- **`DotationBesoin.stockItem` reste l'article du kit.** C'est lui que `realigner()` réaligne et
  que `emplacementDe()` identifie ; changer sa valeur ferait purger et recréer le besoin à chaque
  bascule, en perdant le statut « donné », la taille manuelle et l'historique. Le point de lecture
  unique en aval est **`getArticleServi()`** — achats, remise, suivi, flocage passent tous par là.
  Lire `getStockItem()` en aval fait recommander du neuf.
- **L'arbitrage est une passe saison entière, idempotente** (`DotationEcoulementAllocator`), jouée
  avant chaque lecture du suivi et des achats — même dispositif que `syncTaillesFromDossiers()`, et
  pour la même raison : il dépend d'un stock qui bouge. Ordre de service : par création du besoin,
  premier inscrit premier servi. Il doit rester déterministe, sinon deux écrans consécutifs
  n'annoncent pas la même chose.
- **Jamais au-delà du stock, jamais à moitié, jamais dans une taille approchée.** La première règle
  est celle qui tient tout : un besoin servi par un substitut étant toujours couvert,
  `AchatService` ne propose jamais de racheter un article d'écoulement. Un épinglage manuel que le
  stock ne couvre plus est **relâché** — c'est ce qui préserve l'invariant.
- **Les deux articles doivent porter le même `typeVetement`** : c'est lui qui dit quel champ du
  dossier lire. Écouler un short à la place d'un maillot servirait la taille du bas sur le haut.
  Ni chaîne (Nike → Adidas → ERIMA) ni auto-remplacement :
  `StockItemService::appliquerEcoulement()` refuse les deux, et `analyserSuppression()` compte ces
  liens parmi les emplois.

### Notes, correction et retrait d'un article

**Deux notes, deux portées.** `StockItem.note` vaut pour l'article entier (où il est rangé, ce
qu'il reste à commander) ; `StockTailleNote` vaut pour une déclinaison (« le 128 taille petit »).
Une note vidée est **supprimée**, jamais conservée vide. Le tableau n'affiche qu'un bouton — le
texte vit dans une modale, une note de trois lignes déformait la ligne. Les deux se lisent aussi
sur la feuille d'inventaire, qui se remplit au local.

**Corriger un mouvement n'est pas l'effacer.** `StockMovementService::corrigerMouvementManuel()`
change la quantité ou la taille d'un mouvement **manuel**, exige un **motif**, et écrit une ligne
`StockMovementCorrection` (append-only) qui garde la valeur d'avant. Le stock, dérivé des
mouvements, suit tout seul. Une dotation, une réception de commande ou une vente ne se corrige pas
ici : son écran dédié tient le besoin ou la commande en face.

**Supprimer ou archiver** — `StockItemService::analyserSuppression()` tranche, et l'écran de
confirmation l'annonce **avant** d'agir. Un article part pour de bon quand les trois conditions
tiennent : stock soldé **taille par taille**, mouvements **tous manuels**, aucun kit / besoin de
dotation / bon de commande ne le référence. C'est le cas de l'erreur de saisie, et lui seul : ses
mouvements et ses notes partent avec lui, après une case à cocher. Dès qu'une dotation, une
commande ou une caisse l'a touché, on **archive** — la trace n'est plus une erreur mais une
histoire. Supprimable ne veut pas dire obligé : l'écran offre toujours « Archiver plutôt ». Ne pas
le remplacer par un `confirm()` : lui seul sait dire lequel des deux va se produire, et pourquoi.

---

## Dotations — qui reçoit quel kit, flocage

**Une personne relève d'un seul modèle de dotation.** `DotationResolver::resolveModele()` retient
la cible la plus spécifique — individu > équipe > catégorie FFF ou rôle dirigeant > défaut saison —
et rend **ce modèle-là**, pas la somme des modèles qui la visent. Créer un « kit exceptionnel » à
côté du « kit joueur » ne cumule donc rien : le plus spécifique remplace l'autre, et à priorité
égale c'est la dernière affectation créée qui gagne. Un article exceptionnel s'ajoute en **ligne**
du modèle existant, ou dans un modèle complet affecté nommément à la personne.

**L'équipe d'un dirigeant n'est pas une cible de dotation.** Elle dit de qui il s'occupe, pas ce
qu'il reçoit. Une cible « équipe » ne capte donc que des `Licencie` — sans ce cloisonnement, un
dirigeant rattaché aux Séniors héritait du kit joueur de l'équipe alors qu'aucune affectation ne
visait son rôle. Un dirigeant se cible par son **rôle** ou **nommément** ; le défaut saison, lui,
continue de couvrir tout le monde. `DotationModelePreview` tenait déjà ce raisonnement côté aperçu :
le résolveur s'y est aligné, pas l'inverse.

**Le suivi sépare les deux populations.** `/admin/dotations/suivi` groupe par équipe, puis « Sans
équipe », puis **« Dirigeants »** en fin de liste. Mêler l'encadrement aux joueurs de son équipe
mettait deux kits sans rapport dans le même tableau, et renvoyait le reste de l'encadrement dans un
« Sans équipe » qu'on lisait comme un oubli d'affectation. Une personne à la fois joueuse et
dirigeante tient **deux blocs de lignes** — c'est bien deux kits qu'elle reçoit.

### Flocage — le club peut saisir ce que le licencié n'a pas pu dire

Le texte à floquer vient du formulaire d'inscription. Deux situations le laissent vide sans que
personne ne se soit trompé : un kit créé **après** la validation d'une licence — le dossier ne
porte alors aucune réponse — et l'incident qui a empêché la personne de répondre. Sans saisie
admin, il ne restait que la base.

- `DotationFlocageService` porte le sujet en entier : `reglagesPour()` dit si un besoin se floque
  (en lisant **le kit**, seul à distinguer « floqué, texte pas encore saisi » de « pas floqué du
  tout » — le besoin porte `null` dans les deux cas), `changer()` écrit le texte.
- Le verrou `DotationBesoin.personnalisationManuelle` est le jumeau de `tailleManuelle` : une fois
  le texte saisi, le recalcul ne le remplace plus par celui — absent — du dossier. **Vider le champ
  relâche le verrou** et rend la ligne au dossier.
- **Le kit garde le dernier mot** : une option qui ne se floque plus n'emporte aucun texte, pas
  même un texte manuel, et le verrou tombe avec lui.
- Refusé une fois l'article remis : le vêtement est déjà floqué, et le texte porté par le besoin
  est la trace de ce qui a réellement été donné.

---

## Dirigeants — licence administrative

Le district impose de déclarer une licence dirigeante pour le président, la secrétaire et le
trésorier de l'association. Ces personnes ne sont pas forcément dans le foot : elles ne signent
rien, ne remplissent aucun formulaire et ne veulent pas de kit.

`Dirigeant.licenceAdministrative` enregistre **un seul fait** — « cette licence existe pour le
district » — d'où découlent **trois** conséquences, réglées ensemble et jamais séparément :

- `DotationBesoinSynchronizer::aDroitALaDotation()` retourne `false` **avant** de regarder la
  complétude du dossier. Verrou dur : sans lui, il suffisait qu'un admin renseigne une taille sur
  la fiche pour que le kit se matérialise en sortie de stock à préparer ;
- `DocumentRequirementResolver` ne lui attend **aucun** document, quel que soit le ciblage — son
  dossier ne reste donc pas éternellement « incomplet » et elle ne remonte dans aucune relance ;
- `DirigeantRepository::queryLienJamaisEnvoye()` l'exclut de l'écran d'envoi groupé et
  `DirigeantLinkService::send()` refuse l'envoi à l'unité.

Les **clés font exception** : un président sans dossier club détient souvent le trousseau du local,
sa fiche continue donc d'afficher le registre et son attestation. La **validation FootClubs** aussi :
la licence existe à la FFF, le club la signe comme les autres.

Ne pas remplacer ce drapeau par trois réglages indépendants : c'est justement ce qui faisait
oublier l'un des trois.

---

## Attestations de paiement

Un employeur ou un CE rembourse tout ou partie d'une licence sur présentation d'une attestation.
`AttestationPaiement` est le document remis, et il obéit à deux règles qui tiennent tout le reste :

**1. On n'atteste qu'une licence soldée, et le verrou porte sur l'encaissement.**
`AttestationPaiementService::motifBlocage()` compare la cotisation due au total réellement encaissé
— **jamais** au statut du dossier. « Valider quand même » passe une licence en `A_VALIDER_FFF` sans
qu'un centime soit entré : un verrou posé sur le statut aurait émis un document affirmant un
versement qui n'a pas eu lieu. Le motif est *rendu*, pas réduit à un booléen : l'écran doit pouvoir
dire ce qui manque.

**2. Le montant, la date et le mode ne se saisissent pas.** Ils sont dérivés des `Transaction` de
la saison — total, date du dernier versement, modes dédoublonnés — puis **figés** sur l'attestation.
Le formulaire ne porte que ce qu'aucune donnée du club ne sait dire : **qui a payé**. FootClubs ne
connaît qu'un parent, le payeur peut être l'autre, et `Licencie` n'a ni sexe ni civilité — d'où
`LienParente`, choisi à chaque fois.

Tout ce que le document affirme est recopié à l'émission, **signataire compris** : le club change de
trésorier, une attestation déjà remise continue de nommer celui qui l'a signée. Le lien vers les
`Transaction` (`ON DELETE CASCADE`) n'est qu'une trace de rapprochement — un paiement supprimé plus
tard retire la jointure sans rien changer à ce qui est écrit. La table est append-only : une
réémission ajoute une ligne, le fichier Drive est daté.

Le retéléchargement **régénère** le PDF depuis ces valeurs figées plutôt que de rapatrier le fichier
de Drive : `DriveUploader` n'expose qu'`uploadToPath()`, et un document doit rester récupérable
Drive en panne. Prix assumé : l'identité de l'association et le paraphe scanné sont relus en direct
— l'exemplaire qui fait foi reste celui archivé.

⚠️ Le montant en toutes lettres est produit par `MontantEnLettresFormatter`, écrit à la main.
`NumberFormatter::SPELLOUT` **ne convient pas** : l'image PHP embarque un ICU aux données réduites
au seul anglais et rendait « one hundred twenty » **sans lever d'erreur**. Ne pas y revenir.

---

## Mails — journal, relance automatique

### EnvoiMail — un mail parti laisse une trace, toujours

`Licencie.linkSentAt` atteste que la personne a été contactée **un jour**. C'est ce qu'il faut aux
écrans d'envoi groupé, et rien d'autre : la colonne est écrasée à chaque renvoi. Une relance ne se
voyait donc nulle part — et un admin pouvait réécrire à quelqu'un que le club venait de relancer.
Les mails qui ne passent par aucun lien (signature, complément, boutique, validation, attestation)
ne laissaient, eux, aucune trace du tout.

`EnvoiMail` est un journal **append-only**, une ligne par envoi, rattaché à un `Licencie`, un
`Dirigeant` ou un `Detenteur`. Quatre règles le tiennent :

- **Une seule plume : `ClubMailer::envoyer()`.** Le `TypeMail` y est obligatoire à l'appel, aucun
  mail ne peut plus partir en silence. Confier le traçage aux services appelants les ferait
  diverger, et c'est le côté oublié qui enverrait le mail invisible.
- **Après l'envoi, jamais avant.** Une ligne posée sur un envoi qui a échoué ferait croire la
  personne relancée et empêcherait la vraie relance de partir — pire que pas de trace.
  Symétriquement, un échec d'écriture du journal ne fait pas échouer un mail déjà parti : il est
  journalisé en erreur.
- **L'adresse enregistrée est celle réellement visée**, pas la redirection du mode bêta.
- **Pas de colonne `season`** : `Licencie` et `Dirigeant` sont déjà cloisonnés par saison, toute
  question sur les envois d'une saison passe par eux. Seul un `Detenteur` vit hors saison,
  délibérément — une colonne saison serait le seul endroit à prétendre l'inverse.

`linkSentAt` et `boutiqueAnnonceeAt` **restent** : ils continuent de faire foi pour les écrans
d'envoi groupé. La migration `Version20260829090000` les a repris dans le journal — sans ce
backfill, l'historique des fiches, qui lit désormais le journal, aurait perdu la ligne « lien
envoyé » de chaque personne déjà contactée.

Les fiches licencié et dirigeant affichent en tête un **dernier contact**
(`DernierContactResolver`) : c'est le repère à lire avant de relancer à la main.

### Relance automatique — le délai part du dernier mail, pas de l'inscription

Le club relance de lui-même les licences non soldées : une passe par jour à 9 h
(`app:relances:envoyer`, cron du conteneur `cosync_cron`). Heure ouvrable et non 2 h du matin — un
mail horodaté à 3 h part en indésirable.

**La règle qui tient tout le dispositif : le délai est compté depuis le dernier mail reçu par la
personne, quel qu'il soit.** Une relance passée à la main hier repousse donc mécaniquement celle du
robot de dix jours. Sans cette ancre, un envoi automatique serait suivi d'une relance manuelle
quelques heures plus tard, et le club harcèlerait ceux qu'il vient de relancer. C'est précisément ce
que le journal rend possible.

`RelanceResolver` énonce la règle **une seule fois**, et les trois chemins la lisent : le cron,
l'écran groupé `/admin/effectif/joueurs/relancer`, le bouton d'une fiche. Six conditions, toutes
nécessaires :

| Condition | Pourquoi |
|---|---|
| dossier **non** `estSoldee()` | `estSolde()`, jamais `=== VALIDATED` : c'est l'encaissement qui intéresse le licencié |
| `linkSentAt !== null` | relancer qui n'a jamais été contacté n'est pas une relance : c'est l'envoi initial, décidé par un admin |
| une adresse email existe | sans elle, la relance se fait au téléphone ; ces personnes ne sont donc **pas** listées |
| relances déjà envoyées `< relanceMax` | sans plafond, on écrirait tous les dix jours jusqu'en juin à qui ne paiera pas |
| dernier mail plus vieux que `relanceDelaiJours` | l'ancre ci-dessus |
| `relanceActive` | vérifié par `RelanceService`, **pas** par le resolver : l'écran groupé doit rester utilisable robot éteint |

**Deux étapes, deux mails** (`EtapeRelance`) : `DOSSIER` redonne un lien à qui n'a rien rempli — en
**rouvrant le jeton** de 30 jours, sans quoi le mail renverrait vers un lien expiré ; `PAIEMENT`
rappelle le montant et les instructions du mode déclaré à qui a rempli. La page de confirmation
n'étant protégée par aucun jeton, ce second lien reste valide.

Ce qui ne doit pas se défaire :

- **L'interrupteur est éteint à la migration.** Un automate qui écrit à tout un effectif ne démarre
  jamais d'un déploiement : il démarre d'une décision, prise dans `/admin/club/relances` après un
  `app:relances:envoyer --dry-run`.
- **La relance à l'unité depuis une fiche ignore délai et plafond**, volontairement : c'est un acte
  délibéré, et la fiche affiche le dernier contact juste au-dessus du bouton. On montre
  l'information, on ne bloque pas la personne qui la lit. Elle compte en revanche **dans** le
  plafond, et repousse la relance automatique suivante.
- **Les lectures du resolver sont groupées** (`dernierEnvoiParLicencie`, `compterEnvoisParLicencie`) :
  la liste des joueurs affiche son compteur à chaque ouverture, deux requêtes par licencié en
  feraient trois cents.
- **Il n'y a pas de module de templates de mails**, et c'est assumé : pour huit messages par an,
  l'éditeur, la substitution de variables, l'aperçu et les envois de test ne se paient pas.
  `TypeMail` en est l'amorce si le besoin vient — un envoi libre à une sélection couvrirait
  l'essentiel (annonces, événements) pour une fraction du coût.

---

## ClubSettings — identité de l'association

`ClubSettings` porte, à côté du RIB et de la boutique, ce qu'une attestation engage juridiquement :
raison sociale, adresse, SIRET, email, et le **signataire** (civilité, nom, qualité libre). Ces
valeurs étaient écrites en dur dans une trentaine de templates ; les autres peuvent migrer plus
tard, sans urgence.

Il porte aussi les trois réglages de la **relance automatique** — `relanceActive`,
`relanceDelaiJours` (10), `relanceMax` (3). Au niveau du club et non de la saison : c'est une
politique de relance, elle ne se redécide pas chaque rentrée.

Rien n'impose que le signataire soit le trésorier — président, secrétaire ou toute personne ayant
délégation peuvent engager l'association. Le **nom**, lui, doit figurer : un document signé sans nom
n'engage personne. Ne pas tenter de dériver le signataire de `Dirigeant`, dont les rôles ne
distinguent plus le bureau depuis `Version20260807220000`.

La signature scannée est **facultative** — sans elle, le PDF imprime un cadre à signer à la main, et
la fonctionnalité reste utilisable dès le premier jour. Elle vit dans `var/signatures/` (volume
`cosync_signatures`), **hors de `public/`** : c'est un paraphe, il ne doit jamais être servi par le
serveur web. Elle n'est pas dans `ClubSettings` en base parce que `ClubSettingsService::get()` est
appelé à chaque rendu de page par `AppExtension` — y loger 100 Ko de base64 les chargerait sur
toutes les requêtes. Contrepartie à connaître : le volume n'est pas couvert par `app:db:backup`,
l'image est à re-téléverser après une reconstruction du VPS.

`ClubSettings` porte enfin le rattachement du club à la FFF pour le planning des matchs —
`fffClubNo`, `fffSyncActive` — et la boutique (`boutiqueUrl`, `boutiqueOuverte`). Au niveau du
club : le numéro d'un club à la fédération ne change pas à la rentrée.

⚠️ Quand un troisième outil aura besoin de réglages, il faudra les sortir d'ici : `ClubSettings` ne
doit pas devenir le fourre-tout de tous les outils.

### Boutique du club

L'**ouverture** est un booléen distinct du lien : le club lance ses licences puis sa boutique
quelques jours plus tard, le lien se prépare donc à froid. `getBoutiqueUrlPublique()` — et donc la
variable globale `club_boutique_url` — ne rend le lien qu'une fois la boutique ouverte. **Les écrans
publics et les mails passent toujours par là**, jamais par `getBoutiqueUrl()`, réservé au formulaire
d'admin qui doit relire un lien préparé mais pas encore annoncé.

L'annonce est un **mail distinct** de l'accusé de réception, et **ne part pas** à la soumission du
formulaire : une annonce accrochée à l'inscription ne rattraperait jamais ceux qui se sont inscrits
avant l'ouverture. C'est un envoi groupé décidé écran en main (`/admin/boutique/annoncer`), proposé
aux licenciés dont le dossier est complété, une seule fois chacun — ce que
`Licencie.boutiqueAnnonceeAt` atteste, comme `linkSentAt` pour les liens d'inscription.

---

## Planning des matchs à domicile

Le club imprime chaque mois la liste de ses matchs à domicile, pour **deux publics qui n'ont pas le
même besoin** : la mairie, qui planifie la tonte du terrain, et les boîtes aux lettres du village.
D'où trois tirages d'une même donnée (`PlanningFormat`) — A4 mairie, A5 flyer, et surtout **A4
« duo »**, deux A5 côte à côte à couper au massicot : c'est le tirage réel, imprimer les flyers un
par un gâche la moitié du papier.

```php
MatchDomicile // season, date, heure (?string 'HH:MM'), categorie, adversaire, note,
              // source: MatchSource (MANUEL|FFF), fffMaNo, fffCompetition, fffTerrain, masque
```

**Qui possède quoi — la règle qui tient tout le reste.** Sur une ligne venue de la FFF, le district
fait foi : date, heure, catégorie et adversaire sont **réécrits à chaque synchronisation**, sinon un
report de match ne remonterait jamais sur le planning distribué. Le club possède la **note** et le
**masque**. Pour corriger un horaire fédéral faux, on **détache** la ligne (`detacherDeLaFff()`, et
son inverse `reprendreLaFff()`) — même doctrine que `reprendreImport()` pour les coordonnées. Sans
cette sortie explicite, la correction serait effacée à la sync suivante sans que personne le voie ;
et le `fffMaNo` est **conservé** au détachement, faute de quoi la sync recréerait le match en double.

Ce qui ne doit pas se défaire :

- **`heure` est une chaîne, pas un `time`.** C'est un libellé qu'on imprime, jamais un instant qu'on
  calcule. En `DateTimeImmutable`, un fuseau entrerait dans un document papier et un match de 15h00
  s'imprimerait à 14h00.
- **Un match fédéral ne se supprime pas** : la sync le recréerait. Le **masque** est la seule façon
  de l'écarter durablement des documents. L'écran le dit plutôt que de laisser l'admin recommencer
  trois fois.
- **Un match disparu du flux n'est supprimé que s'il est resté intact** (ni note, ni masque).
  Annoté, il est conservé et **signalé** : le club y a mis du travail, l'automate n'a pas à
  l'effacer en silence.
- **Les dates françaises passent par `DateFrancaiseFormatter`**, écrit à la main.
  `IntlDateFormatter('fr_FR')` rend « Sunday 20 September » **sans lever d'erreur** — l'ICU de
  l'image est réduit au seul anglais, exactement comme pour `NumberFormatter::SPELLOUT`. Et dans les
  templates PDF, `|capitalize` de Twig, jamais `text-transform: capitalize` : DomPDF l'applique à
  chaque mot et rend « Dimanche 20 Septembre ».
- **Le tirage duo est en positionnement absolu**, pas en `<table>` : DomPDF ajoutait marges et
  rembourrages à la hauteur de ligne, le tableau dépassait la page et sortait rejeté sur une
  deuxième feuille, la première blanche. Le défaut est invisible à l'écran, il ne se voit qu'à
  l'impression.

### Récupérer le calendrier depuis la FFF

L'API publique DOFA (`https://api-dofa.fff.fr/api/clubs/{cl_no}/matchs`) est **ouverte, sans jeton**,
et rend exactement la donnée utile. `cl_no` n'est **pas** le numéro d'affiliation : c'est
l'identifiant interne DOFA, réglé dans `/admin/outils/planning-matchs/reglages`, où un bouton
**vérifie** le numéro en réaffichant le nom du club — un numéro faux ramènerait le calendrier d'un
autre club sans que rien ne le signale.

⚠️ **Ce n'est pas une API contractuelle** : elle a déjà changé d'hôte deux fois, n'a plus de
documentation publique, et la FFF sert son calendrier derrière une protection anti-robot qui
**refuse les clients non navigateurs**. Un serveur peut donc recevoir un **403 permanent** là où le
même appel passe depuis un poste de travail. `app:planning:sync-fff --dry-run` répond à la question
sur l'hébergement visé ; `FffApiException::estRefusParProtection()` fait dire à l'écran « la FFF
refuse les appels venant du serveur » plutôt que « réessayez », car il n'y a rien à réessayer. **La
saisie à la main et l'import par collage ne sont donc pas un repli mais un mode de plein exercice**
— d'autant que les **plateaux U7/U9 n'existent pas** dans ce flux.

Trois pièges tenus par `FffMatchMapper`, tous vus dans les données réelles :

| Donnée FFF | Traitement |
|---|---|
| `away: null` | **équipe exempte** : personne ne joue. La ligne est écartée — l'inscrire ferait tondre la mairie pour rien |
| `terrain: null` | affecté après parution du calendrier : le match a bien lieu. C'est `home.club.cl_no` qui décide du domicile, jamais le terrain |
| `time: "15H30"` | traduit en `15:30` ; `date` ISO à minuit UTC dont on prend la **partie date**, sans conversion de fuseau |

La catégorie imprimée vient de la **compétition** (`U16 DISTRICT` → « U16 ») et non du code fédéral,
qui classe cette même équipe en `U17` : c'est « U16 » que le village reconnaît. Le numéro d'équipe
n'est pas ajouté — sa signification varie d'un club à l'autre, et un « Séniors 2 » faux sur un tract
vaut moins qu'un « Séniors » un peu large.

L'interrupteur `fffSyncActive` est **éteint à la migration**, comme `relanceActive` : un automate
démarre d'une décision, pas d'un déploiement.

---

## Clés du local

Les clés sont le seul domaine où **le fait et l'engagement ne vivent pas dans la même échelle de
temps**, et c'est délibéré :

| Ce qu'on veut savoir | Où ça vit | Pourquoi |
|---|---|---|
| Qui détient une clé, depuis quand | `Detenteur` + `CleMouvement`, **hors saison** | Un trousseau ne change pas de main au 1ᵉʳ juillet |
| Qui s'est engagé cette année | `AttestationCle`, **par saison** | L'attestation se resigne chaque année |

```php
Detenteur      // niveau club : nom, prenom, email, telephone, num_licence, qualite
CleMouvement   // append-only : detenteur, type (REMISE|RESTITUTION|PERTE), quantite, date
AttestationCle // append-only : detenteur, season, signed_at, nb_cles, drive_path, uuid public
```

- `CleMouvement` **ne porte pas de saison**. La colonne `season_id` subsiste en base, dé-mappée.
  Filtrer le registre par saison ramènerait le défaut d'origine : un solde remis à zéro chaque été
  alors que les clés sont physiquement dehors.
- `Detenteur` n'est **pas** un `Dirigeant` : ce dernier est cloisonné par saison et ne fournit donc
  aucune identité stable. Le rapprochement des deux se fait dans `DetenteurEffectifResolver`, sur le
  numéro de licence puis sur le nom.
- Un détenteur qui n'est plus à l'effectif **reste visible**, en alerte « hors effectif ». Ses clés
  sont dehors : le faire disparaître serait mentir.
- `AttestationCle` est append-only : une re-signature ajoute une ligne, elle n'écrase pas la
  précédente. Les deux PDF font foi à leur date.
- La campagne de renouvellement est **manuelle** (`AttestationCleService::lancerCampagne`). Aucun
  mail ne part sans décision de l'admin.

---

## Import XLSX FootClubs

**Comportement attendu : idempotent, jamais destructeur.**

Deux formats coexistent, reconnus à leurs en-têtes par `ImportLayoutResolver` : **Licences
dématérialisées** (celui de la procédure) et **Éditions et extractions** (l'ancien).

### Procédure d'export depuis FootClubs (documentée dans l'UI `/admin/import`)

1. Menu gauche → **Licences → Dématérialisées**
2. Filtre **Statut** : « En attente de signature club » → **Rechercher**
3. **Télécharger** → fichier Excel. Contrôler les licences ; inutile de retirer les déjà importées
4. CoSync → saison → **Effectif → Import**, déposer le fichier

L'ancien export reste lisible : **Licenciés → Éditions et extractions → Édition licenciés**, toutes
les catégories, format **Extraction MS Excel**, sortie et tri **Complet** (donne emails et mobiles).
Il ne contient que des licences signées — rien à y filtrer.

### N'importer que les dossiers que le licencié a remplis

CoSync ne refait pas FootClubs : une fiche n'y entre qu'une fois la démarche FFF faite. L'export
dématérialisé, lui, contient **tout le fichier des licences**, y compris des dossiers en « Prise de
contact » que personne n'a remplis. Importés, ils sont indiscernables des vrais et faussent
effectifs comme relances — c'est arrivé en prod le 18/08/2026, sur un export non filtré.

Le filtre de la procédure ne suffit donc pas : il s'oublie. `ImportService::statutPermetImport()`
relit la colonne **Statut** et n'accepte que `StatutDossierFff::permetImport()` — « En attente
signature club » et au-delà. Trois règles à ne pas défaire :

- **La colonne absente ne filtre rien.** `rawStatut === null` distingue « format sans statut »
  (l'ancien export, déjà signé) de « statut vide ». Filtrer sans colonne écarterait tout.
- **Un statut inconnu n'est pas importé.** Si la FFF renomme ses libellés, mieux vaut un rapport qui
  annonce des lignes écartées qu'un effectif rempli en silence. Le rapport nomme le libellé incompris
  (`ImportResultData::$statutsInconnus`), il ne se contente pas de le compter.
- **Le filtre vaut aussi pour les dirigeants** : la même passe les crée depuis le même fichier.

### Colonnes utiles du fichier

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

### Traitement

1. Admin drag & drop le fichier XLSX sur `/admin/import`
2. `ImportService` lit le fichier via PhpSpreadsheet, `ImportLayoutResolver` choisit le layout
3. Le layout mappe la ligne vers `ImportRowData` — **rien d'autre** : aucune décision d'import
4. `ImportService` écarte la ligne si son `Statut` ne permet pas l'import
5. `DataSanitizer` normalise : nom en MAJUSCULES, prénom en Capitalize, téléphone en +33, email en
   trim + lowercase
6. Pour chaque ligne : `upsert` sur `num_licence`
   - Si la fiche existe : mise à jour des données FFF uniquement. Les données club (`DossierClub`,
     `Transaction`) ne sont **jamais** touchées.
   - Si nouvelle : création + génération UUID, **sans aucun mail**
7. Rapport d'import affiché : X créés, Y mis à jour, Z écartés (par statut), erreurs

**Aucun mail ne part de lui-même — ni pour un licencié, ni pour un dirigeant.** Un fichier déposé
par erreur écrirait à tout un effectif avant que le rapport soit lu. Le départ des liens est une
décision, prise sur un écran dédié qui liste les destinataires case par case
(`/admin/effectif/joueurs/envoyer-liens` et `/admin/effectif/dirigeants/envoyer-liens`), ou à
l'unité depuis une fiche. La création manuelle propose une case, **décochée d'office**.

Ce qui fait foi, des deux côtés, c'est `linkSentAt` — un fait daté. Ni le statut du dossier (qui
peut avancer par une saisie admin) ni `formTokenExpiresAt` (effacé dès le dossier complet) ne savent
dire si la personne a été contactée un jour.

---

## Formulaire public d'inscription

`/inscription/{uuid}` — multi-étapes Alpine.js, mobile-first, sans login.

1. **Bienvenue** — nom/prénom pré-remplis, bouton « Commencer »
2. **Équipement** — taille haut, taille bas, pointure (sélecteurs issus de `groupesProposes()`)
3. **Autorisations** — droit à l'image pour tous ; transport par les dirigeants et par d'autres
   parents uniquement si `Category::isJeune()`
4. **Attestation de transport** *(jeune + volontaire au transport)* — conducteur, permis, assurance,
   contrôle technique, signature. PDF généré et archivé sur Drive.
5. **..N — Documents à signer** — une étape par document actif. Les documents sont **paramétrables**
   depuis `/admin/config/documents` (`DocumentSignable`), ciblables par population (licenciés, rôles
   dirigeants, dirigeants nommés). Lecture scrollée + pad tactile (Signature_pad.js). La liste des
   documents attendus est **recalculée côté serveur** à la soumission : un id envoyé mais non
   attendu est ignoré, un document attendu manquant rejette la soumission.
6. **Paiement** — montant résolu par `CotisationResolver`. Bouton principal : **carte via
   HelloAsso** (aucun frais pour le club) — le clic enregistre l'inscription *puis* redirige, le
   licencié ne peut rien perdre s'il abandonne. Sous un séparateur « ou régler autrement » : virement
   (RIB + libellé exact), chèque, espèces, Pass Sport / CAF / ANCV. Mention visible : « Votre
   inscription ne sera validée qu'à réception du paiement. »

**Règle absolue du paiement en ligne** — une licence n'est jamais marquée payée sans encaissement
vérifié. Aucune `Transaction` n'est créée sur la foi d'une `returnUrl` ou du corps d'une
notification : `HelloAssoPaymentRecorder` relit l'état du paiement auprès de l'API HelloAsso
(`state === Authorized`) avant tout enregistrement, de façon idempotente. Le licencié ne voit jamais
autre chose que « paiement en cours de validation » tant qu'aucune transaction n'existe.

**Validation finale** : génération du PDF signé (Twig → DomPDF), upload Drive, suppression du
fichier local, `status = FORM_COMPLETED`, page de confirmation.

**Renvoyer le lien** rouvre la fenêtre de 30 jours et renvoie le mail. ⚠️ L'UUID n'est **pas**
régénéré, volontairement : le régénérer invaliderait les liens déjà distribués et casserait les
licenciés en cours de saisie.

---

## Suppression d'une fiche entrée par erreur

Les listes `/admin/effectif/joueurs` et `/admin/effectif/dirigeants` ont une bascule **Mode édition**
(`?edition=1`), réservée à `ACCES_DIAGNOSTIC` : cases à cocher, écran de confirmation nominatif,
suppression. C'est la sortie de secours d'un import mal filtré, pas un outil de gestion courante —
un joueur qui quitte le club se gère par la saison suivante.

`SuppressionFicheService` porte la règle, **une seule fois pour les deux populations** : une fiche ne
se supprime que si **rien ne s'y est passé** — aucun lien envoyé (`linkSentAt`), aucune annonce
boutique, aucun formulaire rempli, dossier resté à `IMPORTED`, aucun paiement engagé ou encaissé,
aucune signature, aucune sortie de stock, aucune dotation affectée nominativement. Dupliquer ces
tests dans `LicencieService` et `DirigeantService` les ferait diverger, et c'est justement le côté
qui aurait dérivé qui supprimerait une signature.

- **L'analyse est rejouée juste avant la suppression.** L'écran de confirmation dit ce qui était vrai
  à son affichage ; entre les deux, un lien a pu partir.
- **Le lot n'est pas tout-ou-rien** : une fiche redevenue intouchable est épargnée, les autres
  partent, et le message de retour nomme les épargnées avec leur motif.
- **`DossierClub` part explicitement avec le licencié** (FK `NO ACTION`). Les besoins et affectations
  de dotation tombent en cascade côté base.
- **Le mode édition n'est jamais mémorisé** par `ListFilterMemory` : ce n'est pas un filtre, et une
  liste qui rouvre ses cases de suppression toute seule est un piège.

---

## Archivage Drive

```
Drive/
└── FC Soudron/
    ├── 2025-2026/
    │   ├── Documents signés/
    │   │   └── Règlement intérieur/RI_DUPONT_Thomas.pdf
    │   ├── Attestations de paiement/
    │   ├── Attestations Transport/
    │   ├── Plannings/
    │   └── Club house/Clés/Attestations de remise/
    └── Sauvegardes/2026-08/backup_20260808_023000.sql.gz
```

Le classement se fait **par type de document**, pas par équipe ni par licencié : les `driveSegments`
sont fixés à la création de chaque `DocumentSignable`.

⚠️ Le segment `Club house/Clés` n'a **pas** suivi le renommage du module en « Clés » : il désigne des
dossiers qui contiennent déjà des PDF archivés, et le renommer laisserait un dossier vide à côté des
documents signés.

`DriveUploaderService` utilise un Service Account Google (credentials JSON en variable d'env, jamais
committé).

**L'upload est différé** : le PDF est écrit dans `var/pdfs/`, la colonne `drivePath` porte le chemin
**local absolu**, et l'upload part sur `kernel.terminate` (`DriveUploadTerminateListener`). Une fois
sur Drive, la colonne porte l'**ID Drive** — d'où la convention « commence par `/` = encore en
local ». La commande `app:drive-retry-upload` (cron toutes les 15 min) rattrape les échecs. Le
fichier local n'est supprimé qu'après un upload réussi : tant que Drive est injoignable, c'est la
seule copie de la signature.

**Le planning des matchs échappe à ce dispositif, volontairement.** File d'attente, colonne
`drivePath` et reprise cron existent parce qu'une signature perdue l'est pour toujours ; un planning
se **régénère intégralement depuis la base**. `PlanningDriveSync` archive donc de façon **synchrone
et à la demande**, via `replaceAtPath` — régénérer la même période remplace le fichier au lieu
d'empiler des copies — et un échec est **rendu à l'admin** plutôt qu'entré dans une file. Ne pas l'y
faire rentrer « par cohérence » : ce serait trois mécanismes de plus pour protéger un document
reproductible en un clic.

---

## Tâches planifiées (conteneur `cosync_cron`)

| Fréquence | Commande | Pourquoi |
|---|---|---|
| toutes les 15 min | `app:drive-retry-upload` | rattrape les PDF restés en local |
| toutes les 30 min | `app:helloasso:sync-paiements` | rattrape un encaissement dont la notification n'est jamais arrivée — sans lui, le club encaisse sans que la licence passe en soldée |
| 07h00 | `app:planning:sync-fff` | aligne le planning sur le calendrier du district ; ne fait rien tant que `fffSyncActive` est faux |
| 09h00 | `app:relances:envoyer` | relance les licences non soldées ; ne fait rien tant que `relanceActive` est faux. Heure ouvrable : un mail du club horodaté à 3 h part en indésirable |
| 02h30 | `app:db:backup` | dump PostgreSQL + copie sur le Drive |

⚠️ Toute commande console rend potentiellement du Twig (mails), et `AppExtension` expose la saison
courante en variable globale. `SeasonContext` doit donc rester utilisable **hors requête HTTP** : ne
jamais y appeler `RequestStack::getSession()` sans garde.

---

## Permissions — le détail des règles

Le principe et les obligations quotidiennes sont dans `CLAUDE.md`. Voici les raisons.

```php
Permission        // enum : domaine, libellé, description, estEcriture(), implique()
DomainePermission // enum : le groupage d'affichage de l'écran d'un rôle
RoleAcces         // entité : nom, permissions (json), systeme
User.rolesAcces   // ManyToMany — plusieurs rôles, droits cumulés
User.superAdmin   // passe-partout
```

- **Pas d'héritage entre rôles.** `role_hierarchy` rend les droits illisibles (« pourquoi la
  présidente peut-elle modifier le stock ? — parce que X hérite de Y qui… »), et un droit qu'on ne
  sait pas expliquer est un droit qu'on n'ose plus retirer. La seule hiérarchie est **verticale et
  interne à un domaine** : `stock.gerer` implique `stock.lire`, déclaré sur `Permission::implique()`,
  déplié **transitivement** par `PermissionCollector`. Un rôle reste un ensemble plat.
- **Une écriture entraîne sa lecture, et c'est impossible à produire autrement.**
  `PermissionCollector::completer()` est appelé à chaque enregistrement d'un rôle. Sans ça, on
  compose un rôle qui encaisse un paiement sur une fiche qu'il n'a pas le droit d'ouvrir — d'où les
  rares passerelles inter-domaines (`paiement.lire` → `effectif.lire`, `commande.lire` →
  `stock.lire`).
- **Refus par défaut, et c'est le CI qui le tient.** Le modèle est facile ; ce qui échoue, c'est la
  route qu'on oublie — et une route oubliée, ici, c'est une lecture seule qui supprime une fiche
  signée. `AccesLibre` ne vaut que pour un **point de navigation** (un hub, la bascule de saison) ou
  un écran qui ne parle **que du compte connecté** (profil, documentation).
- **`User.roles` (json) n'est pas `User.rolesAcces`.** Le premier est le tableau de rôles de Symfony
  exigé par `UserInterface` ; il ne porte que `ROLE_USER` et ne sert qu'à la règle `^/ → ROLE_USER`
  de `security.yaml`, qui reste la porte d'entrée. Les droits métier sont dans le second.
- **Le super-admin passe partout sans porter aucun rôle**, et il doit toujours en rester au moins un
  (`UserService::definirSuperAdmin`). C'est la sortie de secours qui empêche de se verrouiller
  dehors : un club sans accès à ses propres signatures n'a aucun recours. ⚠️ Ce statut était
  auparavant **déduit** de `DIAG_EMAIL`, l'email de redirection du mode bêta : un réglage
  d'exploitation décidait de qui administrait l'application. Ne pas revenir à une dérivation.
- **On masque ce qu'on ne possède pas, on explique ce qu'on ne peut pas jouer.** Les cartes de hub
  (`permission:` sur `hub-card.html.twig`), les quicklinks et les entrées de navbar disparaissent —
  sinon on clique sur six cartes pour six 403. À l'intérieur d'un écran qu'on utilise, une action
  **qu'on ne possède pas** disparaît aussi ; c'est une action possédée mais **injouable** (pas
  d'adresse email, dossier incomplet) qui affiche son motif. `FicheActionsResolver` fait les deux :
  il filtre sur `FicheAction::permission()`, puis rend le motif de ce qui reste.
- **Un bouton se garde par sa route, jamais par une permission recopiée.** `peut_acceder()` lit le
  droit sur le contrôleur de la route (`RoutePermissionResolver`, réflexion sur `#[IsGranted]`, carte
  mise en cache). Recopier le droit dans le template le fait se tromper — un « Modifier » gardé par
  `stock.gerer` alors que la route exige `stock.configurer` — et surtout **ne suit pas** : changer la
  permission d'une action laisserait derrière elle un bouton gardé par l'ancienne. `is_granted()`
  reste le bon outil pour ce qui n'est pas un lien : une colonne de tableau, un bloc d'information.
  ⚠️ Masquer n'est pas protéger : le refus reste celui du contrôleur.
- **`bin/check-boutons.php`** refuse un `path()` menant à une route dont l'écran n'exige pas le
  droit, hors garde. Cent douze actions étaient dans ce cas — un rôle « consultation du stock »
  ouvrait `/admin/stock/gestion` et y trouvait neuf boutons qui répondaient tous « Access Denied » :
  l'application était sûre et illisible. L'exception s'écrit pour exister —
  `{# droits-verifies-cote-serveur: raison #}`, en tête de fichier pour tout le template, au-dessus
  des lignes concernées sinon. Deux angles morts connus : une action de formulaire posée par le
  contrôleur (`createForm(..., ['action' => generateUrl(…)])`) et une garde portée par une variable
  ne se voient pas dans le template — la garde s'y pose à la main.
- **La maille d'une permission, c'est le geste, pas l'écran de menu.** Le domaine « Le club » n'avait
  qu'un cran, `club.configurer` : donner le RIB à la trésorerie lui donnait aussi le **signataire des
  attestations** et les référentiels sportifs. Quatre droits désormais — `club.identite`,
  `club.rib`, `club.relances`, `club.referentiels`. La question à se poser devant une permission
  fourre-tout : *deux fonctions différentes du club voudraient-elles l'une sans l'autre ?* Si oui,
  elle en fait deux. `Version20260830120000` a converti les rôles existants — la valeur est stockée
  en clair dans le `json`, une valeur disparue du catalogue est écartée **en silence** par
  `Permission::depuisValeurs()`.
- **Une porte de hub se garde par son domaine, pas par la liste de ses droits.**
  `possede_un_droit('club')` sur la navbar et la carte du tableau de bord : la route d'un hub est
  `#[AccesLibre]`, `peut_acceder()` la déclare donc ouverte — elle l'est, mais elle ne mène à rien
  quand toutes ses cartes sont fermées. À l'intérieur, la section capture ses cartes (`{% set %}`) et
  ne s'affiche que si l'une a survécu. Réénumérer les droits à la main dans ces trois endroits en
  ferait oublier un au prochain réglage ajouté.
- **Les rôles sont au niveau du club, pas de la saison.** La trésorière l'est toutes les saisons ;
  les cloisonner obligerait à les réaffecter chaque 1ᵉʳ juillet, et le premier oubli fermerait
  l'outil en pleine campagne d'inscriptions.
- **`RolesSysteme` livre deux rôles seulement** — Responsable foot et Trésorerie —, créés par
  `Version20260829200000` puis maintenus par `app:seed-referential`. Ce sont les deux fonctions qui
  existent dans tous les clubs ; en livrer davantage reviendrait à deviner l'organigramme du club à
  sa place, et un rôle livré inutilisé encombre l'écran sans pouvoir être supprimé. Ils se renomment
  et se modifient librement, mais **ne se suppriment pas**. Le seed est idempotent **au sens strict**
  — un rôle déjà présent n'est pas remis à ses permissions d'origine, sinon chaque déploiement
  effacerait les ajustements du club. Les noms désignent des **fonctions**, pas des personnes.
- **Un rôle ne porte pas de description** : les permissions cochées disent déjà ce qu'il fait, et
  mieux qu'une phrase que personne ne relit quand les cases changent.
- **Hors périmètre, et pas par oubli** : le périmètre par équipe (« l'éducateur des U15 ne voit que
  ses U15 ») n'est pas une permission mais un jugement porté sur un **sujet**, donc un autre voter et
  un filtrage de chaque requête de liste — où l'oubli d'un filtre est invisible. Rien dans ce modèle
  ne l'empêche plus tard.

La migration a attribué « Responsable foot » à **tous les comptes existants** : jusque-là, tout
compte connecté pouvait tout faire, et un déploiement de sécurité qui commence par bloquer les gens
en place se fait annuler dans l'heure.
