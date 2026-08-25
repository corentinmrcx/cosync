# Epic 03 — Événementiel

> **Statut** : à construire — aucun équivalent dans CoSync aujourd'hui
> **Source** : `prepa_epic/besoin_metier_evenementiel.md` + notes vrac (template fiche événement à 3 scénarios, sous-module loto, buvette par type d'occasion)
> **Dépend de** : rien pour le lot 1 ; l'Epic 02 pour la consolidation
> **Alimente** : Epic 02 (ligne `EVENEMENTIEL` du budget)

---

## 1. Pourquoi cette epic existe

Les recettes annexes ne sont pas un supplément dans ce club : les cotisations ne couvrent pas les coûts de fonctionnement, et le loto en particulier porte l'équilibre de la saison. Pourtant chaque événement est budgété à la main, en rouvrant l'Excel de l'année précédente ou en repartant de rien, et son bilan réel est noté dans un fichier **séparé**, après coup, sans lien avec l'estimation de départ.

La conséquence est nette : personne ne peut voir en un clic ce qu'un même événement a rapporté sur les dernières éditions. Sans traçabilité du prix de carnet, du nombre de lots et du résultat net par édition, **aucun objectif chiffré fiable ne peut être fixé d'une année sur l'autre** — il est reconstitué de mémoire.

À l'inverse, les documents BBQ École de foot montrent que la méthode existe déjà côté club et qu'elle est bonne : scénarios bas / moyen / haut, base de calcul par personne, prix unitaires, puis retour d'expérience post-événement pour ajuster les ratios l'année suivante. **Cette epic ne réinvente pas la méthode, elle l'outille.**

## 2. Ce qui existe déjà dans CoSync

Rien sur l'événementiel. Deux voisinages à ne pas confondre :

- **`ClubSettings.boutiqueOuverte` / la boutique HelloAsso** : un canal de vente, pas un événement.
- **Le module Stock** : il gère l'équipement des licenciés. Les consommables d'un événement (charbon, gobelets, fûts) n'ont **pas** vocation à y entrer — ils ne se dotent pas, ne se dénombrent pas par taille, et ne survivent pas à l'événement.

## 3. Périmètre

**Dans le périmètre**

- Une liste d'événements par saison, avec estimé et réel qui coexistent.
- Le rattachement à un type d'événement récurrent, et le pré-remplissage d'une nouvelle édition depuis le réel de la précédente.
- Le calcul de buvette (fûts) et le calcul par ratios (scénarios bas / moyen / haut).
- Un sous-module loto : prix du carnet, quantité vendue, coût des lots, marge par carnet.
- Un total consolidé transmis au budget.

**Hors périmètre**

- Pas de billetterie, pas d'inscription en ligne des participants, pas de gestion de bénévoles par créneau. Le club n'a pas exprimé ce besoin et il ferait basculer l'epic dans un autre ordre de grandeur.
- Pas d'encaissement. La buvette passe par SumUp, les collectes par HelloAsso — l'outil enregistre des montants, il n'en encaisse aucun.

## 4. Règles métier

### 4.1 Estimé et réel

1. **Le résultat net ne se saisit jamais.** Il se calcule, toujours de la même façon : recette − dépense. Un résultat saisissable à la main est un résultat qu'on peut faire mentir.

2. **L'estimé et le réel coexistent, aucun n'écrase l'autre.** Un événement porte un résultat estimé (avant) et un résultat réel (après). C'est la comparaison des deux qui a de la valeur — l'écrasement du premier par le second, qui est ce que fait le double fichier Excel actuel, détruit précisément l'information utile.

### 4.2 Le financement

3. **Un événement pris en charge par le club** : le club paie les dépenses et encaisse les recettes.

4. **Un événement autofinancé par les participants** : le club ne comptabilise **que ce qu'il finance réellement**. Cas réel du barbecue séniors : chacun apporte sa contribution, seuls charbon et gobelets restent à la charge du club. Comptabiliser la dépense complète fausserait le budget du club d'un montant qu'il n'a jamais sorti.

5. **Un événement peut être organisé par une autre structure que la section foot.** Le loto est piloté par le Conseil d'Administration de l'association. L'outil doit pouvoir le noter et en suivre le résultat sans que la section foot en porte l'organisation.

### 4.3 Les événements récurrents

6. **Un événement récurrent est relié à un type commun** (Loto, BBQ École de foot, Chasse aux œufs…), qui porte l'historique des éditions.

7. **Une nouvelle édition se pré-remplit depuis le réel de la dernière**, pas depuis son estimé. Le réel est la seule donnée qui a été vérifiée. Les montants pré-remplis restent librement modifiables — c'est une aide à la saisie, pas une contrainte.

### 4.4 La buvette

8. **On n'achète jamais une fraction de fût.** Le nombre de fûts est **toujours arrondi à l'entier supérieur**, même à 4,05. Pas de demi-fût, pas de complément.

9. **Le format de fût est une donnée de référence, pas une constante.** Plusieurs formats coexistent (5 L ≈ 18 verres, 30 L ≈ 120 verres), chacun avec son coût et son nombre de verres, et la liste doit pouvoir s'étendre sans développement si le club change de fournisseur.

10. **Cas de test vérifié, à conserver comme test unitaire** : 35 personnes × 2 verres = 70 verres ; fût de 5 L (15 €, 18 verres) → 70 ÷ 18 = 3,89 → **4 fûts** → coût 60 €, recette 105 € à 1,50 € le verre, **marge 45 €**.

11. **La recette de buvette est distinguée par type d'occasion** : buvette de match courant et buvette d'événement dédié ne se mélangent pas. Un agrégat SumUp indifférencié ne permet pas de savoir quel type d'occasion est rentable.

### 4.5 Les scénarios et les ratios

12. **Un événement à ratios porte trois hypothèses d'effectif** (bas / moyen / haut) et des ratios unitaires enregistrés (viande, boissons, consommables par personne, avec un tarif distinct enfant/adulte quand le club en tient un). Le coût de chaque scénario se recalcule à l'effectif, au lieu d'être figé.

13. **Le réel saisi après coup met à jour les ratios de référence du type d'événement.** C'est la boucle qui fait la valeur du module : la deuxième édition est mieux estimée que la première parce que la première a été mesurée. Sans cette reprise, on a juste un Excel avec une base de données dessous.

### 4.6 Le loto

14. **Un loto se suit au carnet** : prix du carnet, nombre de carnets vendus, coût total des lots, et la marge par carnet qui en découle. C'est le grain qui rend deux éditions comparables — un résultat net global ne dit pas si l'édition a été meilleure ou simplement plus fréquentée.

## 5. Modèle de données proposé

```php
// src/Entity/TypeEvenement.php — le fil qui relie les éditions
TypeEvenement
    id: int
    nom: string                      // "Loto", "BBQ École de foot"
    ratios: json                     // ratios de référence, mis à jour par le réel (règle 13)
    actif: bool = true

// src/Entity/Evenement.php — une édition
Evenement
    id: int
    season: Season
    type: ?TypeEvenement             // null = événement ponctuel, sans historique
    nom: string
    dateDebut: ?\DateTimeImmutable   // nullable : une date peut n'être qu'approximative
    datePeriodeLibelle: ?string      // "courant mars" quand la date n'est pas fixée
    description: ?string
    financement: FinancementEvenement  // CLUB | AUTOFINANCE
    organisateur: OrganisateurEvenement // SECTION_FOOT | CONSEIL_ADMINISTRATION | AUTRE
    depenseEstimee: float = 0
    recetteEstimee: float = 0
    depenseReelle: ?float
    recetteReelle: ?float
    effectifBas / effectifMoyen / effectifHaut: ?int
    scenarioRetenu: ?ScenarioEvenement  // BAS | MOYEN | HAUT
    buvette: ?BuvetteEvenement
    loto: ?LotoEvenement
    // resultatEstime() / resultatReel() — calculés, jamais stockés (règle 1)

// src/Entity/FormatFut.php — référentiel, éditable en admin (règle 9)
FormatFut
    id: int
    libelle: string                  // "Fût 5 L"
    volumeLitres: float
    nbVerres: int
    coutAchat: float
    actif: bool = true

// src/Entity/BuvetteEvenement.php
BuvetteEvenement
    id: int
    evenement: Evenement
    formatFut: FormatFut
    nbPersonnes: int
    verresParPersonne: float
    prixVenteVerre: float
    typeOccasion: TypeOccasionBuvette   // MATCH_COURANT | EVENEMENT_DEDIE (règle 11)
    // nbFuts() = ceil(nbPersonnes * verresParPersonne / formatFut.nbVerres) — règle 8

// src/Entity/LotoEvenement.php
LotoEvenement
    id: int
    evenement: Evenement
    prixCarnet: float
    carnetsPrevus: int
    carnetsVendus: ?int
    coutLots: float
    // margeParCarnet() = (recette - coutLots) / carnetsVendus
```

```php
// src/Enum/FinancementEvenement.php
enum FinancementEvenement: string { case CLUB = 'club'; case AUTOFINANCE = 'autofinance'; }

// src/Enum/OrganisateurEvenement.php
enum OrganisateurEvenement: string {
    case SECTION_FOOT = 'section_foot';
    case CONSEIL_ADMINISTRATION = 'conseil_administration';
    case AUTRE = 'autre';
}

// src/Enum/ScenarioEvenement.php
enum ScenarioEvenement: string { case BAS = 'bas'; case MOYEN = 'moyen'; case HAUT = 'haut'; }

// src/Enum/TypeOccasionBuvette.php
enum TypeOccasionBuvette: string { case MATCH_COURANT = 'match_courant'; case EVENEMENT_DEDIE = 'evenement_dedie'; }
```

**Pourquoi `buvette` et `loto` sont des entités liées et non des colonnes de `Evenement`** : la majorité des événements n'ont ni l'un ni l'autre. Quinze colonnes nulles sur chaque ligne rendraient l'entité illisible, et la table `evenement` porterait la forme du loto pour toujours.

## 6. Services & écrans

| Classe | Rôle |
|---|---|
| `Service/Evenement/EvenementService` | création, saisie de l'estimé et du réel |
| `Service/Evenement/BuvetteCalculator` | règle 8 et 10 — arrondi supérieur, coût, recette, marge |
| `Service/Evenement/ScenarioCalculator` | coût d'un scénario à partir des ratios et de l'effectif |
| `Service/Evenement/EditionPrecedenteResolver` | pré-remplissage depuis le réel de la dernière édition (règle 7) |
| `Service/Evenement/RatioSynchronizer` | met à jour les ratios du type depuis le réel saisi (règle 13) — idempotent |
| `Service/Evenement/EvenementConsolidationPresenter` | total des résultats nets de la saison, pour l'Epic 02 |
| `Controller/Admin/EvenementController` | `/admin/evenements` — liste, fiche, saisie du réel |
| `Controller/Admin/FormatFutController` | `/admin/config/formats-fut` — le référentiel |

## 7. Points de jonction avec l'existant

- **Epic 02 (Finance)** : `EvenementConsolidationPresenter` rend le total consolidé → une ligne `NatureBudget::EVENEMENTIEL`. Attention à la règle 4 : un événement autofinancé ne remonte que la part financée par le club.
- **`Season`** : chaque événement appartient à une saison ; `TypeEvenement` est **hors saison** (comme `Detenteur` pour les clés) — c'est ce qui permet à l'historique de traverser les saisons.
- **Le loto est organisé par le CA**, donc `OrganisateurEvenement::CONSEIL_ADMINISTRATION`. Son résultat entre au budget du club sans que la section foot en porte l'organisation.

## 8. Lots livrables

1. **Événements + estimé/réel + consolidation** — le cœur. Livrable seul, remplace immédiatement les deux fichiers Excel.
2. **Types récurrents + pré-remplissage depuis le réel** — la valeur d'une saison sur l'autre.
3. **Buvette + formats de fût** — petit lot, très utilisé.
4. **Scénarios à ratios + reprise des ratios par le réel** — la boucle d'apprentissage.
5. **Sous-module loto.**

## 9. Points à trancher avant de coder

- **Les ratios sont-ils repris automatiquement ou sur validation ?** La règle 13 dit que le réel met à jour les ratios de référence. Une mise à jour automatique peut écraser un bon ratio avec une édition atypique (année de pluie, effectif anormal). **Recommandation** : proposer la mise à jour et la faire valider, en affichant l'ancien et le nouveau ratio côte à côte.
- **Le club veut-il un événement rattachable à plusieurs saisons** (un loto à cheval sur décembre/janvier) ? Probablement non, mais à confirmer avant de figer `season` en non-nullable.

## 10. Données réelles pour tester

- **Buvette** — 35 personnes, 2 verres/personne, 1,50 € le verre, fût 5 L à 15 € (18 verres) → 4 fûts, 60 € de coût, 105 € de recette, 45 € de marge.
- **BBQ École de foot** — coût par enfant et par adulte distincts, scénarios bas/moyen/haut, ratios ajustés après l'édition.
- **BBQ Séniors** — `AUTOFINANCE` : seuls charbon et gobelets à la charge du club.
- **Loto** — `CONSEIL_ADMINISTRATION`, poste le plus structurant de l'équilibre de saison.
- **Événements 26-27** : loto, chasse aux œufs, barbecues, match en nocturne (nouveauté), diffusion Ligue des Champions.
