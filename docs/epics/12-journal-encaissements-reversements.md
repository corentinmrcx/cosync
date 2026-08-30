# Epic 12 — Journal des encaissements et reversements

> **Statut** : à construire — aucun équivalent dans CoSync aujourd'hui
> **Source** : notes vrac (« être capable d'avoir un détail précis des transactions, notamment celles via HelloAsso : on encaisse en ligne, puis on vire l'argent sur le compte du Foyer, et la trésorière doit avoir le détail pour savoir dans ses comptes »)
> **Dépend de** : rien — se construit sur `Transaction`, déjà en production
> **Alimente** : Epic 02 (le réel encaissé), Epic 07 (la boutique passera par le même canal)
> **Le lot 1 se livre seul, en une journée, sans aucune migration.**

---

## 1. Pourquoi cette epic existe

### 1.1 Le Foyer n'est pas un tiers : c'est l'association

**Le Foyer de Soudron est la personne morale ; le football est une de ses activités**, à côté des autres. La section n'a pas d'existence juridique propre — ni SIRET, ni comptabilité séparée. Trois conséquences qui commandent tout le reste de cette epic :

- **L'argent ne « sort » pas du club, il remonte.** Le virement au Foyer n'est pas un paiement à un tiers, c'est un transfert vers le compte de l'entité qui porte la section. Rien à facturer, rien à contractualiser — mais tout à justifier.
- **La comptabilité existe déjà, et elle n'est pas dans CoSync.** Elle est tenue au niveau de l'association, pour toutes ses activités. C'est la raison de fond du « aucune comptabilité » de l'[Epic 02 §3](02-finance-budget.md), et elle est plus forte que « le club n'a pas de comptable » : il en a une, elle est simplement d'un cran au-dessus. CoSync ne la refait pas — il produit le **justificatif** qu'elle consomme.
- **La trésorière doit pouvoir isoler le foot au milieu du reste.** Sa question n'est pas seulement « qui a payé ? », c'est « cette ligne, qu'est-ce qu'elle contient, et qu'est-ce qui relève du football ? ». Un total mensuel ne lui sert à rien : il ne se rapproche d'aucune ligne de son relevé, et ne s'impute nulle part.

### 1.2 Un encaissement n'est pas un mouvement bancaire

C'est le point qui structure le modèle, et il est contre-intuitif : **un paiement HelloAsso ne met aucun argent sur le compte de l'association.** Il crédite un solde qui reste sur la plateforme, et **il y reste tant que personne ne déclenche le reversement**. Le transfert vers le compte bancaire est un **geste**, pas un événement automatique.

Il y a donc **deux temps, et un seul mouvement bancaire** :

| Temps | Ce qui se passe | Ce que CoSync en sait aujourd'hui |
|---|---|---|
| Le licencié paie | son compte est débité, le solde HelloAsso du club augmente | **tout** : `Transaction`, avec le licencié, le montant, la date |
| Quelqu'un déclenche le reversement | le solde HelloAsso part sur le compte de l'association, en **une ligne** | **rien** |

Deux problèmes distincts en découlent, et l'epic traite les deux :

- **La ligne est opaque.** La trésorière voit un virement de quelques centaines d'euros et n'a rien pour dire ce qu'il contient — alors que CoSync possède la totalité du détail. L'information n'est pas perdue, elle est **inatteignable** : faute d'écran pour la lire, et de rattachement pour la grouper.
- **L'argent non reversé est invisible.** Une licence peut être soldée dans CoSync sans qu'un centime ait atteint la banque. Personne, aujourd'hui, ne voit ce qui dort sur la plateforme : ni le club côté CoSync, ni la trésorière côté comptes. C'est de l'argent qui s'oublie.

### 1.3 Ce qui se perd aujourd'hui, en plus

- **Aucune vue globale.** Les transactions ne se lisent que fiche par fiche. Reconstituer un mois de recettes demande d'ouvrir soixante fiches.
- **Le brut et le net ne se rapprochent pas.** Le payeur qui verse 88 € (contribution volontaire à HelloAsso comprise) est enregistré à 85 €. L'écart est correct — c'est bien 85 € qui reviennent au club — mais il n'est **écrit nulle part**, donc indéfendable quand la famille relit son relevé de carte.
- **La boutique va aggraver le tout (Epic 07).** Dès qu'elle est ouverte, un même reversement mêlera des cotisations et des ventes de vêtements — deux natures que l'association impute différemment. Aujourd'hui le lot est homogène ; c'est le dernier moment où le rattachement est simple à construire.

## 2. Ce qui existe déjà dans CoSync

| Existant | Ce qu'il apporte | Ce qui manque |
|---|---|---|
| `Transaction` | chaque encaissement : licencié, montant, mode, référence, date **métier** et date de connaissance | aucun rattachement à un mouvement bancaire ; le montant réellement débité n'est pas conservé |
| `Transaction.externalPaymentId` | l'identifiant du **paiement** chez HelloAsso, sous contrainte d'unicité | l'identifiant du **reversement** n'est ni lu ni stocké |
| `HelloAssoClient` | la seule classe qui parle à l'API, jeton OAuth en cache | n'expose que les intentions de paiement — ni la liste des paiements, ni les reversements |
| `HelloAssoSyncPaiementsCommand` (cron, 30 min) | rattrape un encaissement dont la notification n'est jamais arrivée | ne connaît que les intentions en attente, jamais le mouvement d'argent en aval |
| `AttestationPaiement` | un document qui atteste un encaissement **pour un licencié**, à destination d'un employeur ou d'un CE | ne répond pas à la question de la trésorière : elle part d'une ligne bancaire et cherche les personnes, pas l'inverse |
| **Aucun écran** | — | **il n'existe aucune liste globale des transactions** : la route `/admin/paiements` n'existe pas |

`ClubSettings` mérite une mention à part : `associationNom`, `associationAdresse`, `associationSiret` et le signataire y sont **déjà** renseignés — c'est l'identité du Foyer, celle que porte `AttestationPaiement`. Le justificatif de cette epic n'a donc rien de neuf à saisir (règle 11).

Rien ne porte aujourd'hui la notion de **reversement**, ni celle de **solde en attente sur la plateforme**.

## 3. Périmètre

**Dans le périmètre**

- Un journal des encaissements : toutes les `Transaction`, filtrables, avec leurs totaux.
- Le **reversement** : le transfert du solde HelloAsso vers le compte bancaire de l'association, et les transactions qu'il porte.
- Le **solde en attente** : ce qui est encaissé mais encore sur la plateforme.
- Un justificatif détaillé, remis à la trésorière, qui explique une ligne de son relevé.
- La conservation du montant réellement débité au payeur, à côté de ce qui revient au club.

**Hors périmètre**

- **Aucune comptabilité**, pour les mêmes raisons qu'à l'[Epic 02 §3](02-finance-budget.md) : pas de plan comptable, pas de journal comptable au sens légal, pas d'exercice à clôturer. **La comptabilité de l'association existe déjà et couvre toutes ses activités** (§1.1) ; ce module produit le **justificatif** qu'elle consomme, il ne prétend pas s'y substituer.
- **Aucune vue sur les autres activités du Foyer.** CoSync est l'outil de la section football et le reste.
- **CoSync ne déclenche aucun reversement.** Le transfert se fait sur HelloAsso, par une personne habilitée, avec ses propres identifiants. L'outil **constate** le mouvement, il ne le commande pas — et il n'a aucune raison de détenir de quoi le commander.
- **Aucun rapprochement bancaire automatique.** CoSync ne lit aucun relevé, il n'existe aucune API pour ça.
- **Aucun nouveau canal d'encaissement.** `HelloAssoPaymentRecorder` reste le seul chemin d'entrée d'un paiement en ligne.

## 4. Règles métier

### 4.1 L'unité du détail

1. **La trésorière lit un relevé, pas une base. L'unité du détail est donc la ligne bancaire**, pas le mois ni la saison. Un export « toutes les transactions de septembre » ne répond pas à sa question : il ne tombe jamais juste sur le montant qu'elle a sous les yeux, et c'est précisément la coïncidence des totaux qui fait la valeur du justificatif.

2. **Un reversement n'appartient pas à une saison.** Un transfert de début juillet porte des cotisations des deux saisons à cheval. Lui coller un `season_id` obligerait à trancher arbitrairement et casserait le total. Les `Transaction`, elles, sont déjà cloisonnées — toute question saisonnière passe par elles. Même raisonnement que `CleMouvement` et `Detenteur`, hors saison délibérément.

3. **Le chèque et l'espèce posent exactement le même problème.** Une remise de douze chèques fait une seule ligne de relevé. Le regroupement est donc **générique** ; seule son alimentation diffère : lue chez HelloAsso pour la CB, saisie à la main pour le reste. Construire un mécanisme réservé à HelloAsso obligerait à en écrire un second six mois plus tard.

### 4.2 Le reversement

4. **Un reversement est un fait constaté, jamais déduit.** Deux chemins acceptables, et un seul interdit :
   - *lu chez HelloAsso*, si l'API expose les reversements — c'est la règle absolue du projet appliquée un cran plus loin : l'état réel se relit chez le prestataire avec notre propre jeton ;
   - *saisi par celui qui l'a déclenché* — date et montant lus sur la plateforme. C'est un fait, pas une estimation : la personne vient de cliquer le bouton.
   - ❌ *reconstitué en groupant les paiements par date* : ça produirait des lots plausibles et faux. Le reversement n'a aucun rythme garanti — il part quand quelqu'un le décide.

5. **Le rattachement des transactions se fait par cases à cocher, et c'est suffisant.** L'écran propose les encaissements non encore reversés, l'admin coche ce que le transfert emportait — même motif que « Envoyer les liens ». Si l'API permet de le pré-remplir, tant mieux : c'est un confort, jamais un prérequis. **Le lot doit rester livrable sans elle.**

6. **Un montant qui ne tombe pas juste se voit, il ne se rattrape pas.** L'écart entre le montant du reversement et la somme des transactions rattachées est **affiché**, jamais absorbé par un ajustement silencieux. Un écart signale un paiement oublié, un remboursement, ou une vente de boutique dans le même lot. Corollaire : le montant du reversement est **saisi**, jamais dérivé du rattachement — le dériver ferait toujours tomber juste, y compris quand c'est faux.

7. **HelloAsso ne prend pas de frais au club.** Le service est financé par la contribution volontaire du payeur. Ne pas prévoir de colonne « frais » : elle resterait à zéro et laisserait croire qu'il faut la remplir.

### 4.3 L'argent qui dort sur la plateforme

8. **Le solde en attente est une information de premier plan, pas un reste.** C'est de l'argent encaissé qui n'a atteint aucun compte, invisible des deux côtés — CoSync ne connaît que des encaissements, la trésorière ne connaît que des virements. L'écran l'affiche en tête : *encaissé en ligne / déjà reversé / en attente sur HelloAsso*.

9. **Le solde en attente ne change rien au statut d'une licence.** `LicenceStatus::estSolde()` répond à « la personne a-t-elle payé ? », et elle a payé — le trajet de l'argent ensuite ne la regarde pas. Coupler les deux suspendrait une licence, une dotation et une sortie de stock à un geste de trésorerie sans rapport, exactement le défaut que le CLAUDE.md décrit à propos de la validation FootClubs.

### 4.4 Le brut et le net

10. **Ce que le payeur débite et ce qui revient au club sont deux montants distincts, tous les deux conservés.** `HelloAssoPaymentRecorder::montantRevenantAuClub()` retient déjà `min(cotisation, encaissé)`, et **cette règle ne bouge pas** : c'est ce qui revient au club qui solde une licence, et la borner des deux côtés empêche de valider une licence pour de l'argent jamais reçu. Ce qui manque est la **trace** : le montant débité est aujourd'hui jeté après usage. La colonne est nullable, et son `null` veut dire « non renseigné », jamais « égal au montant » — même convention que `Transaction.confirmedBy`, nul pour les paiements en ligne.

### 4.5 Ce qui sort de l'outil

11. **Le justificatif nomme l'association, pas la section.** L'en-tête porte `ClubSettings.associationNom` et `associationSiret` — la personne morale qui tient les comptes (§1.1). Un justificatif à l'en-tête d'une section sans existence juridique ne s'impute nulle part. `AttestationPaiement` lit déjà ces champs : même source, aucune duplication.

12. **Le détail nomme la nature, pas seulement les personnes.** Cotisation de licence aujourd'hui, vente de boutique demain (Epic 07) : la trésorière impute différemment deux natures arrivées par le même virement. Une colonne « nature » qui ne vaut qu'une seule valeur au lot 1 n'est pas de l'anticipation gratuite — c'est ce qui évite de rouvrir le format du justificatif à l'ouverture de la boutique.

13. **Un justificatif remis est figé.** Une fois transmis à la trésorière, il ne se réécrit pas : une correction postérieure produit une ligne de **régularisation** sur le reversement suivant. Même principe qu'`AttestationPaiement`, et pour la même raison — un document qui change après avoir été classé fait mentir celui qui l'a classé.

14. **Le détail ne porte que ce qu'un justificatif exige** : nom, prénom, catégorie, nature, montant, date, mode, référence. Ni adresse, ni date de naissance, ni email, ni téléphone. Ce fichier quitte CoSync pour vivre dans la comptabilité de l'association ; il n'emporte que le nécessaire (§8 du CLAUDE.md).

15. **Aucune donnée bancaire, dans aucun sens.** Rien ici ne collecte le RIB d'un payeur ni le moindre élément de carte. Le RIB de l'association existe déjà dans `ClubSettings` pour être affiché aux licenciés, rien de plus.

## 5. Modèle de données proposé

```php
// src/Entity/Reversement.php — un mouvement d'argent réel vers le compte de l'association.
// Hors saison (règle 2). C'est la ligne que la trésorière a sous les yeux.
Reversement
    id: int
    origine: OrigineReversement          // HELLOASSO | REMISE_CHEQUES | DEPOT_ESPECES (règle 3)
    dateReversement: \DateTimeImmutable
    montant: string                      // decimal(10,2) — saisi, jamais dérivé (règle 6)
    referenceExterne: ?string            // l'identifiant du reversement chez HelloAsso, si l'API le donne
    libelle: ?string                     // le libellé du virement, pour retrouver la ligne au relevé
    remisLe: ?\DateTimeImmutable         // le justificatif a été remis → figé (règle 13)
    justificatifPath: ?string            // convention Drive du projet : commence par "/" = encore en local (§4D)
    note: ?string
    createdBy: User
    createdAt: \DateTimeImmutable
    // ecart() = montant - somme des transactions rattachées (règle 6), affiché, jamais corrigé

// src/Entity/Transaction.php — deux colonnes ajoutées, toutes deux nullables
    montantDebite: ?string               // règle 10 : ce que le payeur a réellement payé, contribution comprise
    reversement: ?Reversement            // null = encaissé, pas encore parti sur un compte (règle 8)
```

```php
// src/Enum/OrigineReversement.php
enum OrigineReversement: string {
    case HELLOASSO      = 'helloasso';        // le solde de la plateforme, transféré sur décision
    case REMISE_CHEQUES = 'remise_cheques';
    case DEPOT_ESPECES  = 'depot_especes';
}
```

**Une seule entité, et c'est le point clé.** Une première version en prévoyait deux — un reversement HelloAsso, puis un virement du club vers le Foyer. C'était faux : **il n'y a pas de compte intermédiaire.** L'argent dort sur la plateforme puis atterrit directement sur le compte de l'association, en un seul mouvement (§1.2). Modéliser deux étages aurait fait saisir à la main un virement fictif, dont l'écart de la règle 6 aurait été nul par construction — donc inutile, et coûteux à tenir.

**Pourquoi `Transaction.reversement` est nullable, et ce que vaut son `null`.** C'est exactement le solde en attente (règle 8) : une transaction non rattachée est de l'argent encaissé qui n'a atteint aucun compte. Le `null` porte une information, il ne signale pas une saisie incomplète — d'où l'absence d'enum d'état de rapprochement, qui dirait la même chose en pouvant diverger.

**Pourquoi `montantDebite` est une chaîne.** `Transaction.montant` est déjà un `decimal` mappé en `string` : les deux montants se comparent, ils doivent avoir le même type. Ne pas introduire un `float` à côté.

## 6. Services & écrans

Le domaine est **`Service/Payment/`**, qui existe déjà et porte HelloAsso, la cotisation et les attestations. Ne pas ouvrir un `Service/Finance/` pour ça : celui de l'Epic 02 porte le budget prévisionnel, qui est un autre métier.

| Classe | Rôle |
|---|---|
| `Service/Payment/JournalEncaissementPresenter` | le journal filtrable — période, mode, nature, rattachement — et ses totaux. N'écrit rien |
| `Service/Payment/SoldeEnAttenteResolver` | encaissé en ligne / déjà reversé / en attente sur la plateforme (règle 8) |
| `Service/Payment/ReversementService` | création d'un reversement, rattachement des transactions, remise du justificatif et figeage |
| `Service/Payment/RapprochementResolver` | l'écart entre le montant saisi et la somme rattachée (règle 6) |
| `Service/Payment/EncaissementCsvEncoder` | l'export détaillé, restreint aux colonnes de la règle 14 |
| `Service/Pdf/JustificatifReversementRenderer` | le PDF remis à la trésorière, en-tête `ClubSettings` |
| `Controller/Admin/EncaissementController` | `/admin/paiements` — le journal, le solde en attente, l'export |
| `Controller/Admin/ReversementController` | `/admin/paiements/reversements` — création, rattachement, justificatif |

Si l'API HelloAsso expose les reversements, `HelloAssoClient` gagne une méthode de lecture et **reste la seule classe qui parle à l'API**. Ouvrir un second client serait la faute la plus coûteuse du lot — c'est déjà l'avertissement porté par l'[Epic 07 §6](07-boutique.md) à propos du recorder.

L'archivage du justificatif suit le chemin existant : écriture locale, `drivePath` local, upload différé sur `kernel.terminate`, rattrapage par `app:drive-retry-upload`. Aucun mécanisme neuf.

## 7. Points de jonction avec l'existant

- **`Transaction`** est la source unique. Deux `ADD COLUMN` nullables, aucun backfill destructif — mais la table contient des **encaissements réels** : migration relue et testée sur copie de prod (§13 du CLAUDE.md).
- **`HelloAssoPaymentRecorder`** renseigne `montantDebite` au moment où il calcule déjà l'écart. Un seul endroit à toucher, et **sans changer ce qui est crédité** (règle 10).
- **`LicenceStatus::estSolde()`** ne bouge pas et ne doit pas apprendre l'existence du reversement (règle 9).
- **`AttestationPaiement`** répond à une autre question et ne bouge pas. Les deux documents coexistent : l'un justifie une personne à un employeur, l'autre justifie une ligne bancaire à une trésorière.
- **Epic 02 (Finance)** consomme ce module comme source du **réel encaissé** : la ligne `LICENCE_RECETTE` en fiabilité `REEL`, et le taux de recouvrement de `RecouvrementResolver`. Le journal est un préalable naturel à son lot 5, sans en être une dépendance bloquante.
- **Epic 07 (Boutique)** partagera le canal HelloAsso — donc le même solde et les mêmes reversements. Le rattachement construit ici est ce qui permettra de distinguer une cotisation d'une vente dans un même virement. **C'est l'argument de calendrier de cette epic** : la construire après la boutique reviendrait à démêler un historique déjà mélangé.

## 8. Lots livrables

1. **Journal des encaissements + export** — l'écran global qui manque, filtrable, avec totaux et export CSV. **Aucune migration, aucune écriture** : ne fait que lire `Transaction`. *C'est le lot qui répond à l'essentiel du besoin pour le moins de code et zéro risque.*
2. **Reversements + solde en attente + justificatif figé** — `Reversement`, le rattachement à la main, l'écart, le PDF à en-tête de l'association, l'archivage Drive. C'est ce lot qui fait sauter la boîte noire **et** rend visible l'argent qui dort sur la plateforme.
3. **Le montant réellement débité** — `ADD COLUMN montant_debite` nullable + `HelloAssoPaymentRecorder` qui le renseigne. Ne change rien à ce qui est crédité. *Volontairement en dernier : c'est le seul lot qui touche le chemin d'encaissement en production.*
4. **Pré-remplissage depuis l'API HelloAsso** — si et seulement si l'API expose les reversements. Pur confort de saisie, sans lequel les lots 1 à 3 tiennent debout.

## 9. Points à trancher avant de coder

- **L'API HelloAsso expose-t-elle les reversements ?** À vérifier dans la v5 avant de chiffrer le lot 4, et **seulement lui** : la règle 5 impose que le rattachement à la main suffise. Ne pas commencer par là — c'est la partie la plus incertaine et la moins nécessaire.
- **CSV, PDF, ou les deux ?** Le CSV se retravaille sous tableur, le PDF se classe. **Recommandation** : CSV au lot 1, parce que c'est un outil de travail ; PDF au lot 2 seulement, quand le document devient un justificatif figé. Livrer les deux d'emblée produirait un PDF que personne ne classe encore.
- **Que fait-on d'un remboursement ?** HelloAsso sait rembourser un paiement ; CoSync n'a aucune notion de transaction négative, et `HelloAssoPaymentRecorder` ignore délibérément tout état autre qu'`Authorized`. **Recommandation** : hors périmètre, mais l'écart de la règle 6 le rendra **visible** au lieu de le laisser disparaître — déjà mieux que la situation actuelle.
- **Faut-il alerter quand le solde en attente dépasse un seuil ?** L'argent oublié sur la plateforme est le risque réel que le §1.2 met au jour. **Recommandation** : commencer par l'afficher, sans alerte. Une tuile sur `/admin/` suffit à le ramener dans le champ de vision — même réponse qu'à l'[Epic 09](09-actions-reunion.md), règle 6 : *le rappel se voit, il ne se subit pas.*

## 10. Jeu de test

Les montants de reversement sont à reprendre d'un **relevé HelloAsso réel** au moment de coder : les inventer ferait passer un test que la production ne passerait pas. Le socle connu du club :

| | |
|---|---|
| **Cotisations** | 85 € jeunes · 120 € séniors |
| **Effectifs 26-27** | Sénior 19 · U16 14 · U13 5 · U11 10 |
| **Modes réellement rencontrés** | CB HelloAsso, chèque, espèces, virement, Pass Sport, CAF, ANCV |

**Cas à reproduire en test**

| Cas | Ce que le test doit montrer |
|---|---|
| Un sénior paie 123 € en ligne (120 € + 3 € de contribution volontaire) | `montant` = 120 €, `montantDebite` = 123 €, le reversement porte 120 € |
| Huit cotisations encaissées, aucun reversement déclenché | solde en attente = le total, et **aucune licence n'en est affectée** (règle 9) |
| Un reversement portant ces 8 cotisations | écart **nul**, justificatif sur une page, solde en attente retombé à zéro |
| Un paiement oublié au rattachement | écart **non nul et affiché**, jamais absorbé |
| Une remise de 12 chèques | un `Reversement` d'origine `REMISE_CHEQUES`, total égal à la ligne du relevé |
| Une transaction antérieure au lot 3 | `montantDebite` reste `null` et s'affiche « non renseigné », jamais « 0 » ni « = montant » |
| Un justificatif déjà remis | toute correction produit une régularisation, jamais une réécriture du document remis |
