# Epic 02 — Finance & Budget prévisionnel

> **Statut** : à construire — aucun équivalent dans CoSync aujourd'hui
> **Source** : `prepa_epic/Epic_CoSync_Finance_Budget.md` (intégral) + notes vrac (tarif facial/recette nette, tag catégorie, tag fiabilité, relevés Ligue/District, prévisionnel semi-automatisé, ligne cloisonnée)
> **Dépend de** : Epic 01 (référentiel tarifaire)
> **Consomme** : Epic 03 (événementiel), 04 (ententes), 06 (CFI), 10 (dotation)
> **C'est l'epic la plus lourde du lot. Elle se livre en 5 lots, chacun utile seul.**

---

## 1. Pourquoi cette epic existe

Le prévisionnel 26-27 a été construit à la main, par itérations, et le compte-rendu du chantier est surtout un inventaire d'erreurs rattrapées de justesse :

- une recette de loto comptée **sans sa dépense en face**, repérée seulement parce que le résultat (+7 000 €) « paraissait trop beau » ;
- des frais de mutation budgétés sur une hypothèse jamais revérifiée (**268 € au lieu de 68 €**) ;
- une dotation chiffrée sur une enveloppe approximative alors que les prix réels étaient connus ;
- deux subventions municipales différentes confondues l'une avec l'autre ;
- une facture Intersport lue comme « de la dotation » alors qu'elle contenait aussi des ballons, chasubles et chronomètres.

Aucune de ces erreurs n'est une erreur de calcul. Ce sont toutes des erreurs de **traçabilité** : un montant dont on ne sait plus d'où il vient, à quoi il se rattache, ni s'il a été vérifié. L'outil ne doit donc pas être une calculette — le club sait calculer — mais **une mémoire de la provenance de chaque montant**.

Deuxième constat structurant, celui des boîtes noires : un bilan affiche « District Marne : 2 667,12 € » sans détail, alors que ce montant mélange sept natures de coûts. Tant qu'une ligne de dépense reste indivise, aucun coût par catégorie n'est calculable sans dépouiller le relevé à la main — ce qui a été fait une fois, et qu'il s'agit de ne plus jamais refaire.

## 2. Ce qui existe déjà dans CoSync

| Existant | Ce qu'il apporte | Ce qui manque pour Finance |
|---|---|---|
| `Transaction` | les encaissements réels de cotisation, par licencié, par mode | ne porte ni catégorie ni rattachement budgétaire ; ne connaît que les recettes de licence |
| `CotisationResolver` | le montant dû par un licencié | résout par **équipe** puis défaut de saison — **jamais par catégorie** (cf. §7) |
| `StockItem.prixAchat` | le prix d'achat d'un article | pas de remise, pas de flocage, pas de rattachement à une ligne de budget |
| `Commande` / `CommandeLigne` | ce qui est commandé au fournisseur | pas de lien vers une dépense budgétaire |
| `Season` | le cloisonnement par saison | — |

Rien ne porte aujourd'hui la notion de **budget**, de **dépense hors équipement**, ni de **résultat de saison**.

## 3. Périmètre

**Dans le périmètre**

- Un budget par saison, composé de lignes de recette et de dépense, chacune tracée (catégorie bénéficiaire, source, fiabilité).
- Le calcul du coût et de la marge **par catégorie de licencié**.
- Le prévisionnel semi-automatisé : à partir d'une hypothèse d'effectif, générer les lignes dérivables du référentiel tarifaire.
- La saisie des relevés District et Ligue au fil de leur réception, et leur ventilation en natures de coûts.
- Les lignes cloisonnées (recette fléchée compensée par sa dépense).
- La séparation entre l'état de travail et l'état restituable.

**Hors périmètre**

- **Aucune comptabilité.** Pas de plan comptable, pas de journal, pas de grand livre, pas d'exercice à clôturer au sens comptable. Ce n'est pas un manque à combler : **la comptabilité existe déjà, un cran au-dessus.** Le Foyer de Soudron est la personne morale, le football n'est qu'une de ses activités, et c'est la trésorière du Foyer qui tient les comptes de l'ensemble ([Epic 12 §1.1](12-journal-encaissements-reversements.md)). Ce que la section veut est un prévisionnel fiable et un réel comparable — le budget d'une activité, pas les comptes d'une association.
- Aucun rapprochement bancaire automatique. Les relevés Ligue/District se saisissent ou se collent, ils ne s'importent pas d'une API qui n'existe pas.
- Le paiement des licences reste géré par l'existant (`Transaction`, HelloAsso). Finance le **lit**, ne le remplace pas.
- **Le détail des encaissements et leur rattachement aux mouvements d'argent** — le reversement HelloAsso, puis le virement au compte du Foyer — sont l'objet de l'[Epic 12](12-journal-encaissements-reversements.md). Finance en consomme les totaux ; elle ne refait ni le journal, ni le justificatif remis à la trésorière.

## 4. Règles métier

### 4.1 La répartition des coûts — la règle centrale

C'est la règle qui a demandé le plus d'itérations au club, et celle dont tout le reste découle.

1. **Pour chaque dépense, une seule question : ce coût appartient-il à une catégorie, ou au club ?**
   - *Rattachable à une catégorie* → compté pour elle, divisible par son effectif. Licence fédérale, dotation vêtements, engagement en compétition et coupe, arbitrage, frais de mutation des recrues de cette catégorie.
   - *Coût de structure* → **jamais réparti par tête**. Cotisations fédérales et de ligue, cartons, forfaits, goûters, événementiel, subventions, buvette, loto.

2. **Un carton et un forfait ne se répartissent pas, même quand on le pourrait.** Le club a réussi à retrouver, sur les relevés, à quelle équipe chaque forfait 25-26 appartenait — et a tranché de ne pas s'en servir. La justification est à conserver telle quelle : *un forfait est un accident, pas une caractéristique structurelle d'une catégorie ; le répartir donnerait l'illusion qu'on peut prévoir laquelle en aura*. L'outil ne doit donc pas offrir de rattacher un carton à une catégorie, même si la donnée le permet.

3. **La marge d'une catégorie = recette de licence de la catégorie − coûts rattachés à cette catégorie.** Jamais moins les coûts de structure. C'est ce chiffre qui autorise ou non une dépense de dotation.

4. **Ne jamais répartir un coût de structure au prorata pour « équilibrer ».** L'ancien fichier Excel du club le faisait — et divisait par 4 pour les séniors et par 5 pour les autres, une incohérence de copier-coller jamais corrigée qui rendait des marges faussement négatives. La règle protège contre la reproduction de ce défaut.

### 4.2 La traçabilité de chaque montant

5. **Toute ligne porte une catégorie bénéficiaire, obligatoirement** — la valeur « club (non réparti) » étant une réponse pleine et entière, pas une absence de réponse. Sans ce champ à la saisie, le coût par catégorie n'est plus reconstituable qu'à la main, ce qui est précisément le défaut à supprimer.

6. **Toute ligne porte sa fiabilité** : `OFFICIEL` (issu d'un barème du référentiel), `REEL` (constaté sur un relevé ou une facture), `ESTIME` (hypothèse assumée). L'outil affiche directement quelle part du prévisionnel est solide, au lieu de laisser à relire ligne par ligne.

7. **Un montant `REEL` issu d'un relevé partiel est un plancher, pas un total.** Le relevé District utilisé en 25-26 s'arrêtait fin avril. Une ligne alimentée par un relevé doit porter la **période couverte** ; un relevé qui ne couvre pas la saison entière rend la ligne explicitement incomplète.

8. **Une ligne peut porter une marge de prudence assumée.** Le club a volontairement budgété l'arbitrage au-dessus du réel constaté (450 € pour 310 € réels en sénior). Ce n'est pas une erreur à corriger : c'est une décision, qui doit être visible comme telle — montant retenu, montant constaté, et le fait que l'écart est délibéré.

### 4.3 Le tarif facial et la recette réelle

9. **Le tarif facial et l'encaissement réel sont deux montants distincts, tous les deux conservés.** Le club affiche 85 €/120 €, mais n'encaisse jamais exactement ça : aides CAF, Pass Sport, tarifs réduits ponctuels (3 licences enfants à 55 € en 25-26, validées par le CA). Aujourd'hui l'écart est une perte d'information pure.

10. **Le prévisionnel se construit au tarif plein.** Décision explicite du club : l'ajustement par un taux de recouvrement est « du bruit » dans un document prévisionnel. Le taux de recouvrement réel est calculé et affiché **en analyse a posteriori**, jamais injecté dans le prévisionnel. Les deux usages ne se mélangent pas.

11. **Le mode de financement d'une licence est une donnée à part entière** : plein tarif / CAF / Pass Sport / tarif réduit accordé par le CA. C'est lui qui rend le taux de recouvrement calculable par catégorie et par saison sans dépouiller un bilan.

### 4.4 Les lignes cloisonnées

12. **Une ligne cloisonnée a une recette attendue, une dépense associée, et un résultat net nul par construction.** Cas type : Soudron avance l'équipement de 3 joueurs licenciés à Conantre, qui rembourse à l'euro près (Epic 04). Autre cas : une collecte fléchée sur un poste précis.

13. **Une ligne cloisonnée ne pollue jamais le résultat global du club**, mais reste visible et traçable — le club doit pouvoir justifier le mouvement d'argent. Son écart en temps réel (attendu − collecté) est suivi ; c'est cet écart, et lui seul, qui remonte au résultat.

### 4.5 Travail et restitution

14. **L'outil sépare l'état de travail de l'état restituable.** Le club a une discipline stricte sur le document final : *« il ne contient que des décisions actées, jamais de comparaison avec une version antérieure, jamais de section non résolu, jamais de justification réel constaté vs budgété »*. Pendant la construction, au contraire, plusieurs hypothèses coexistent et les erreurs doivent être visibles.

15. **Une ligne non arbitrée n'apparaît pas dans la restitution.** Elle n'est pas affichée avec une réserve, elle est absente. C'est la règle du club, pas une préférence d'affichage.

## 5. Modèle de données proposé

```php
// src/Entity/Budget.php — un budget par saison
Budget
    id: int
    season: Season
    hypotheseEffectif: json          // [{categoryId, effectif}] — cf. §4 du prévisionnel semi-auto
    clotureLe: ?\DateTimeImmutable   // au-delà, plus d'écriture (cf. règle 2 de l'Epic 01)
    lignes: Collection<BudgetLigne>

// src/Entity/BudgetLigne.php — le cœur : une recette ou une dépense, tracée
BudgetLigne
    id: int
    budget: Budget
    sens: SensBudget                 // RECETTE | DEPENSE
    nature: NatureBudget             // cf. enum
    libelle: string
    category: ?Category              // null = coût club, non réparti (règle 5)
    fiabilite: FiabiliteDonnee       // OFFICIEL | REEL | ESTIME (règle 6)
    montantPrevu: float
    montantReel: ?float              // saisi a posteriori, ne remplace jamais le prévu
    margeProdence: bool = false      // règle 8 : l'écart prévu/réel est délibéré
    periodeCouverte: ?string         // règle 7 : "jusqu'au 30/04" sur un relevé partiel
    ligneCloisonnee: ?LigneCloisonnee
    sourceRef: ?string               // n° de relevé, référence de facture, lien
    arbitree: bool = false           // règle 15 : seules les lignes arbitrées sont restituées
    note: ?string

// src/Entity/LigneCloisonnee.php — recette fléchée compensée
LigneCloisonnee
    id: int
    budget: Budget
    libelle: string
    montantAttendu: float
    montantCollecte: float = 0
    montantDepense: float = 0
    // ecart() = attendu - collecte ; seul l'écart remonte au résultat (règle 13)

// src/Entity/ReleveFederal.php — un relevé District ou Ligue reçu en cours de saison
ReleveFederal
    id: int
    season: Season
    instance: InstanceFederale       // réutilise l'enum de l'Epic 01
    periodeDebut: \DateTimeImmutable
    periodeFin: \DateTimeImmutable
    recuLe: \DateTimeImmutable
    lignes: Collection<ReleveLigne>

// src/Entity/ReleveLigne.php — une transaction du relevé, ventilée
ReleveLigne
    id: int
    releve: ReleveFederal
    libelleBrut: string              // la ligne telle qu'elle arrive : "DIST3 507442 Bastos A"
    montant: float
    nature: ?NatureBudget            // ventilation manuelle : c'est un carton, pas une licence
    category: ?Category
    licencie: ?Licencie              // quand la ligne est nominative (cartons)
    ventilee: bool = false
```

```php
// src/Enum/SensBudget.php
enum SensBudget: string { case RECETTE = 'recette'; case DEPENSE = 'depense'; }

// src/Enum/FiabiliteDonnee.php
enum FiabiliteDonnee: string {
    case OFFICIEL = 'officiel';   // barème du référentiel (Epic 01)
    case REEL     = 'reel';       // relevé, facture, encaissement constaté
    case ESTIME   = 'estime';     // hypothèse assumée
}

// src/Enum/NatureBudget.php — les natures qui étaient noyées dans "District Marne : 2 667,12€"
enum NatureBudget: string {
    case LICENCE_RECETTE   = 'licence_recette';
    case LICENCE_FEDERALE  = 'licence_federale';
    case ENGAGEMENT        = 'engagement';
    case ARBITRAGE         = 'arbitrage';
    case MUTATION          = 'mutation';
    case SANCTION          = 'sanction';        // jamais rattachable à une catégorie (règle 2)
    case FORFAIT           = 'forfait';         // idem
    case COTISATION_CLUB   = 'cotisation_club';
    case DOTATION          = 'dotation';
    case EVENEMENTIEL      = 'evenementiel';
    case SUBVENTION        = 'subvention';
    case BUVETTE           = 'buvette';
    case FORMATION         = 'formation';
    case GOUTER            = 'gouter';
    case AUTRE             = 'autre';
}

// src/Enum/ModeFinancementLicence.php — règle 11
enum ModeFinancementLicence: string {
    case PLEIN_TARIF   = 'plein_tarif';
    case CAF           = 'caf';
    case PASS_SPORT    = 'pass_sport';
    case TARIF_REDUIT  = 'tarif_reduit';   // accordé par le CA, cf. règlement intérieur
}
```

**Pourquoi `SANCTION` et `FORFAIT` acceptent quand même un `category` nullable dans `ReleveLigne` mais pas dans `BudgetLigne`** : la ventilation d'un relevé sert à comprendre d'où vient un montant (et le club *sait* à quelle équipe appartenait chaque forfait 25-26) ; le budget, lui, applique la règle 2 et refuse le rattachement. La donnée existe en amont, elle n'est pas utilisée en aval — c'est délibéré.

## 6. Services & écrans

| Classe | Rôle |
|---|---|
| `Service/Finance/BudgetService` | écriture des lignes, arbitrage, clôture |
| `Service/Finance/CoutCategorieResolver` | applique la règle 4.1 : coût et marge par catégorie, à partir des lignes et de l'effectif |
| `Service/Finance/PrevisionnelGenerator` | à partir de l'hypothèse d'effectif + `BaremeResolver` (Epic 01), génère les lignes dérivables **en brouillon** |
| `Service/Finance/RecouvrementResolver` | taux de recouvrement réel par catégorie, à partir de `Transaction` + `ModeFinancementLicence` |
| `Service/Finance/ReleveVentilationService` | saisie d'un relevé et ventilation de ses lignes en natures |
| `Service/Finance/BudgetRestitutionPresenter` | l'état restituable : lignes arbitrées uniquement, sans historique ni réserve (règles 14-15) |
| `Controller/Admin/BudgetController` | `/admin/finance/budget` — le plan de travail |
| `Controller/Admin/ReleveController` | `/admin/finance/releves` — saisie et ventilation |
| `Controller/Admin/CoutCategorieController` | `/admin/finance/cout-categorie` — le tableau coût/marge |

**`PrevisionnelGenerator` ne décide rien.** Il génère des lignes de brouillon, modifiables une par une, sur les seuls postes dérivables mécaniquement (licences = effectif × tarif, engagements, dotation). Le loto, la buvette et l'événementiel restent des décisions humaines. C'est explicitement la demande : *« ne remplace pas la décision humaine, mais évite de refaire à la main tous les calculs de licence × effectif × tarif à chaque ajustement d'hypothèse »*.

## 7. Points de jonction avec l'existant

### 7.1 La cotisation n'est pas par catégorie — le point dur

`CotisationResolver` résout aujourd'hui : cotisation de l'**équipe** si renseignée, sinon `Season.cotisationDefaut`. Or tout le raisonnement Finance est **par catégorie** (85 € jeunes / 120 € séniors), et la marge par catégorie est le livrable central.

Aujourd'hui ça fonctionne par coïncidence : les équipes correspondent grossièrement aux catégories. Ça casse dès qu'une équipe mélange deux catégories — ce qui est le cas en entente (Epic 04).

Trois options, à trancher avant le lot 2 :

| Option | Effet | Risque |
|---|---|---|
| **A. Ajouter `Category.cotisation`**, et insérer la catégorie dans la cascade de `CotisationResolver` (équipe → catégorie → défaut saison) | le tarif facial devient enfin lisible par catégorie | modifie un résolveur en production qui décide de ce que paie un licencié — migration + test sur copie de prod obligatoires (§13) |
| **B. Laisser `CotisationResolver` intact**, et porter le tarif par catégorie uniquement dans le référentiel (Epic 01) | zéro risque sur l'existant | deux sources de vérité pour le même tarif — exactement le défaut que l'outil combat |
| **C. Migrer vers la catégorie et déprécier `Team.cotisation`** | une seule source | casse les cas où une équipe a un tarif propre ; perte de données si mal migré |

**Recommandation : A.** La cascade équipe → catégorie → défaut conserve tous les cas existants (une équipe qui portait un tarif continue de primer) et ajoute le niveau manquant. C'est un `ADD COLUMN` nullable, sans backfill destructif.

### 7.2 Les autres jonctions

- **`Transaction`** est la source du réel encaissé. À enrichir d'un `modeFinancement` (règle 11) — `ADD COLUMN` nullable, les transactions existantes restant `null` = non renseigné, distinct de `PLEIN_TARIF`.
- **[Epic 12](12-journal-encaissements-reversements.md)** rend le réel encaissé **lisible** : elle porte le journal des transactions, le montant réellement débité au payeur (distinct de ce qui revient au club) et le rattachement aux reversements. C'est elle qui alimente les lignes `LICENCE_RECETTE` en fiabilité `REEL` et le `RecouvrementResolver` du lot 5. Elle n'est pas bloquante — mais faire le lot 5 sans elle revient à ressaisir à la main des totaux déjà en base.
- **`StockItem.prixAchat` + `Commande`** alimentent la ligne `DOTATION`. Le détail du calcul de prix (remise, flocage) est dans l'**Epic 10**.
- **Epic 03 (événementiel)** rend le total consolidé des résultats nets d'événements → une ligne `EVENEMENTIEL`.
- **Epic 04 (ententes)** rend le nombre de licenciés **Soudron** par catégorie — c'est ce chiffre, jamais l'effectif de l'équipe, qui alimente les recettes de licence.
- **Epic 06 (CFI)** rend le total des formations → une ligne `FORMATION`.

## 8. Lots livrables

1. **Budget + lignes tracées** — `Budget`, `BudgetLigne`, les trois enums, l'écran de saisie. Livrable seul : le prévisionnel quitte Excel, chaque montant porte sa catégorie et sa fiabilité. *C'est le lot qui apporte le plus de valeur pour le moins de code.*
2. **Coût et marge par catégorie** — `CoutCategorieResolver` + l'écran. Nécessite la décision §7.1.
3. **Prévisionnel semi-automatisé** — `PrevisionnelGenerator`, branché sur l'Epic 01.
4. **Relevés fédéraux** — saisie et ventilation, qui font sauter les boîtes noires.
5. **Recouvrement réel + lignes cloisonnées + restitution** — l'analyse a posteriori et le document propre.

## 9. Points à trancher avant de coder

- **§7.1 : où vit le tarif de licence par catégorie.** Bloquant pour le lot 2.
- **Le montant des cartons doit-il suivre l'effectif ?** Incohérence assumée et signalée par le club : les goûters ont été proratisés à l'effectif jeunes, les cartons non (865 € maintenus malgré la baisse de l'effectif sénior). Le club a tranché de ne pas proratiser, au motif que le lien effectif/cartons n'est pas direct. À conserver tel quel, mais l'outil doit rendre l'arbitrage visible plutôt que de le figer en dur.
- **L'asymétrie de prudence** (marge sur l'arbitrage, pas sur les cartons) est signalée non tranchée dans le compte-rendu. Le champ `margeProdence` la rend visible sans la trancher — ce qui est probablement la bonne réponse d'outil.

## 10. Données réelles 26-27 pour tester

**Effectifs retenus** — Sénior 19 · U16 14 · U13 5 · U11 10 · U9/U7 0 · Dirigeants 15.

**Résultat prévisionnel final : +2 418 €** (recettes 14 392 €, dépenses 11 974 €).

**Postes utiles en jeu de test**

| Poste | Montant | Fiabilité | Portée |
|---|---|---|---|
| Cartons 25-26 | 865 € (42 sanctions) | RÉEL | club |
| Arbitrage sénior | 450 € budgété / 310 € réel | RÉEL + marge assumée | catégorie |
| Arbitrage U15-U16 | 300 € budgété / 166 € réel | RÉEL + marge assumée | catégorie |
| Mutations | 68 € (1 mutation sénior) | RÉEL | catégorie |
| Goûters jeunes | 294 € pour 44 jeunes, à proratiser | RÉEL | club |
| Dotation joueurs | kit le plus cher, jamais une moyenne | OFFICIEL | catégorie |

**Pièges à reproduire en test** : une recette de loto sans dépense en face doit sauter aux yeux ; une ligne `DOTATION` calculée sur une moyenne de kits doit être impossible à saisir (Epic 10, règle du kit le plus cher).
