# Epic 10 — Delta Dotation : le volet budgétaire

> **Statut** : **delta sur un module déjà livré.** Le fonctionnel de la dotation existe ; c'est le chiffrage qui manque.
> **Source** : `prepa_epic/Contexte_Chantier_Dotation_Equipements_2026-2027.md` + notes vrac (« dotation configurable par catégorie », « historique pour prévisionnel »)
> **Alimente** : Epic 02 (ligne `DOTATION`), Epic 07 (la formule de prix est commune)
> **Lire le §2 avant tout : les six besoins listés au §3.3 du document source sont déjà couverts par le code en production.**

---

## 1. Pourquoi ce delta existe

Le chantier dotation 26-27 a produit deux choses distinctes, et une seule manque à CoSync.

**Ce qui a été décidé fonctionnellement** — le double choix (veste ou t-shirt), la veste imposée aux nouveaux licenciés, les trois paliers de dotation dirigeants selon le rôle, le non-cumul entre paliers, la personnalisation avec nom brodé — **est déjà implémenté**.

**Ce qui a été calculé à la main et n'existe nulle part dans l'outil** — la formule de prix, les enveloppes budgétaires par catégorie, le barème résultant, et le budget total — reste dans un document. C'est l'objet de ce delta.

Le chantier a d'ailleurs produit une erreur qui illustre exactement le manque : pendant plusieurs versions, le budget dotation a utilisé une **enveloppe approximative (40 €/46 €) fixée avant de connaître les vrais prix des kits**, alors que les prix réels (38,60 €/44,45 €) étaient disponibles. Une marge de prudence sur une incertitude qui n'existait plus.

## 2. Ce qui existe déjà — les six besoins du §3.3 sont couverts

| Besoin exprimé au §3.3 du document source | État dans CoSync |
|---|---|
| 1. Enregistrement du choix par licencié, avec « nouveau → imposé / renouvellement → libre » | **Fait** — `DotationEligibilite::NOUVEAUX / RENOUVELLEMENTS / TOUS` sur `DotationModeleLigne`, croisé avec `Licencie.natureLicence`. Une nature inconnue est traitée comme un renouvellement, mode d'échec délibérément sûr. |
| 2. Texte de personnalisation collecté au moment du choix | **Fait** — `DotationModeleLigne.personnalisationRequise`, `personnalisationLabel`, `personnalisationMaxLength` ; le texte est stocké dans `DossierClub.dotationPersonnalisation`. |
| 3. Champ « rôle » dirigeant déterminant l'article, sans cumul de dotations | **Fait** — `DotationCibleType::ROLE` + `DotationAffectation::priorite()` : la cible la plus spécifique gagne, jamais de cumul. |
| 4. Configuration de saison indépendante, historique préservé | **Fait** — `DotationModele` est rattaché à une `Season`. |
| 5. Liste de commande consolidée pour le fournisseur | **Fait** — `Commande` / `CommandeLigne` / `AchatService`, avec le bon de commande. |
| 6. Historique des choix par saison | **Fait** — les choix vivent dans `DossierClub.dotationChoix`, cloisonné par saison. |

Le module va même **au-delà** du besoin exprimé : grilles de tailles fournisseur (`GrilleTaille`), écoulement de l'ancien stock avant rachat (`StockItem.remplaceArticle`), corrections de mouvements tracées.

**Ne rien reconstruire de tout ça.** Ce delta n'ajoute que du chiffrage.

## 3. Ce qui manque réellement

1. **La formule de prix n'est pas dans l'outil.** `StockItem.prixAchat` porte **un prix final saisi à la main** — le résultat du calcul, pas ses termes. Rien ne dit d'où viennent les 32,10 € de la veste : ni le prix catalogue (44 €), ni la remise (35 %), ni le flocage (3,50 €). Une évolution de remise oblige donc à recalculer et ressaisir chaque article, exactement comme dans l'Excel de la boutique.

2. **L'enveloppe budgétaire par catégorie n'existe pas.** Rien ne dit qu'un kit U11 doit tenir dans 46 €, et rien ne signale un dépassement.

3. **Le coût d'un kit n'est calculé nulle part.** `DotationModele` sait ce que contient un kit, mais pas ce qu'il coûte.

4. **Le budget dotation prévisionnel n'existe pas** — ni la règle du kit le plus cher, ni le total par catégorie.

5. **La répartition réelle des choix n'est pas exploitée.** La donnée est là (`DossierClub.dotationChoix`), mais rien ne la restitue en « 70 % t-shirt / 30 % veste », qui est précisément ce qui permettra d'affiner le prévisionnel 27-28 sur du réel plutôt que sur une hypothèse.

## 4. Règles métier

### 4.1 La formule de prix

1. **Prix final = (prix catalogue × 0,65) + flocage forfaitaire.** La remise de 35 % est négociée avec **Intersport Clubs et Collectivités** (le fournisseur, celui qui facture et livre) sur des articles de marque **Erima** (les liens `erima-online.com` ne servent qu'à identifier la référence). CoSync distingue déjà correctement les deux : `StockItem.marque` et `StockItem.fournisseur`.

2. **Le flocage n'est pas remisé.** C'est un service facturé à part, qui s'ajoute **après** la remise. L'inverser fausse tous les prix.

3. **Le flocage ne dépend pas de la taille.** Seul le prix catalogue varie entre référence enfant et adulte. C'est ce qui a permis de reconstituer les prix enfants sans nouveau devis.

4. **Deux niveaux de flocage constatés** : **3,50 €** pour « logo club seul » — vérifié sur cinq articles différents, cohérence totale — et **8,50 €** pour « logo club + nom personnalisé » (t-shirt joueurs, nom au dos). Le coût se déclare par article, jamais comme une constante.

### 4.2 Les enveloppes

5. **Chaque catégorie a une enveloppe de dotation**, reconduite du barème précédent : **40 €** pour Séniors et U16, **46 €** pour les autres.

6. **Un kit qui dépasse son enveloppe doit se voir avant la commande**, pas après. Le dépassement n'est pas bloquant — c'est une décision du club — mais il doit être annoncé.

7. **Le pool dirigeants est global, pas par palier** : 30 € × effectif dirigeants. Les paliers se compensent entre eux ; c'est ce qui a permis d'arbitrer la veste des coachs.

### 4.3 Le prévisionnel

8. **Le budget dotation retient toujours le kit le plus cher, jamais une moyenne.** Règle explicite et deux fois motivée : on ne peut pas prédire quel choix chaque licencié fera, et une hypothèse de répartition sans données réelles peut faire **sous-budgétiser**. Sur les seuls séniors (~23 licenciés), l'écart entre 100 % veste et 100 % t-shirt atteint **184 €**. Le prix le plus haut ne peut jamais faire sous-estimer.

9. **Cette règle tombe le jour où la donnée réelle existe.** Une fois les choix enregistrés sur une saison, le club a une vraie répartition, exploitable pour le prévisionnel suivant. L'outil doit donc **afficher les deux** : le budget prudent (kit le plus cher) et le budget à répartition constatée — sans jamais remplacer le premier par le second de sa propre initiative.

10. **Les dirigeants ne relèvent pas du pire cas.** Leur dotation dépend d'un palier de rôle connu à l'avance : le coût est celui de la répartition réelle par palier, pas d'un maximum.

11. **Un cas exceptionnel se traite au cas par cas, jamais en recalibrant la catégorie.** Un U13 qui a besoin d'une taille adulte est une commande individuelle imputée à la ligne équipement générale. Calibrer toute la tranche sur ce cas gâcherait 6 à 8 € de marge par licencié pour les 90 % qui n'en ont pas besoin — et contredirait la décision du club de commander « au fur et à mesure, en fonction des besoins réels ».

## 5. Modèle de données proposé

**Trois ajouts, aucune entité nouvelle sauf une.**

```php
// StockItem — ajouts : les termes du calcul, pas seulement son résultat (manque n°1)
StockItem
    prixCatalogueAdulte: ?float      // 44,00 € — le tarif fournisseur avant remise
    prixCatalogueEnfant: ?float      // 38,00 € — null si pas de référence enfant
    tauxRemise: ?float               // 0.35 par défaut ; porté par l'article (cf. Epic 07 règle 2)
    coutFlocage: ?float              // 3,50 € ou 8,50 € selon l'article (règle 4)
    // prixAchat reste la colonne existante : le prix final, désormais calculable
    //   au lieu d'être saisi. Ne pas la supprimer — elle est lue partout.

// Category — ajout : l'enveloppe (manque n°2)
Category
    enveloppeDotation: ?float        // 40 € ou 46 € (règle 5)

// Season — ajout : le pool dirigeants (règle 7)
Season
    poolDotationDirigeantParTete: ?float   // 30 €

// src/Entity/DotationBudgetSnapshot.php — la seule entité nouvelle (facultative, lot 4)
DotationBudgetSnapshot
    id: int
    season: Season
    category: ?Category              // null = dirigeants
    effectif: int
    coutKitLePlusCher: float         // règle 8
    coutRepartitionConstatee: ?float // règle 9 — null tant qu'aucune donnée réelle
    calculeLe: \DateTimeImmutable
```

**Pourquoi `prixAchat` reste et n'est pas remplacé** : la colonne est lue par les achats, les commandes et les écrans de stock, sur une base de production. Le pattern du §13 du CLAUDE.md s'applique — expand d'abord (ajouter les termes), backfill ensuite (recalculer `prixAchat` depuis eux), et surtout **ne jamais contracter** : `prixAchat` reste la valeur de référence, désormais alimentée par le calcul quand les termes sont renseignés, et saisie à la main quand ils ne le sont pas (un article hors accord fournisseur, un achat ponctuel).

## 6. Services & écrans

| Classe | Rôle |
|---|---|
| `Service/Stock/PrixFournisseurResolver` | **la formule, en un seul endroit** (règles 1-4). Partagé avec l'Epic 07 — cf. §7. |
| `Service/Dotation/DotationCoutResolver` | coût d'un kit, comparaison à l'enveloppe (règle 6) |
| `Service/Dotation/DotationBudgetResolver` | budget prévisionnel : kit le plus cher **et** répartition constatée (règles 8-10) |
| `Service/Dotation/DotationRepartitionPresenter` | la répartition réelle des choix par saison (manque n°5) |
| Écrans | enrichissement de `/admin/stock` (les termes du prix) et de `/admin/dotation` (coût du kit, enveloppe, budget) |

## 7. Points de jonction avec l'existant

- **`PrixFournisseurResolver` doit être partagé avec l'Epic 07 (Boutique).** C'est rigoureusement la même formule — `(catalogue × 0,65) + flocage` — appliquée au même accord fournisseur. Deux implémentations divergeraient à la première renégociation de remise. Quelle que soit l'epic livrée en premier, l'autre consomme son résolveur.
- **`DotationBesoinSynchronizer`** matérialise les besoins ; le coût s'y greffe sans rien changer à la synchronisation.
- **`Category.enveloppeDotation`** est nullable : une catégorie sans enveloppe n'est pas contrôlée, elle n'est pas en dépassement. Distinguer les deux à l'écran (même principe que « exemption » vs « non saisi », Epic 01 règle 5).
- **Epic 02 (Finance)** : `DotationBudgetResolver` alimente la ligne `NatureBudget::DOTATION`, avec la fiabilité `OFFICIEL` quand les prix viennent d'un devis. C'est là que la règle 8 doit être **techniquement impossible à contourner** : le budget ne doit pas pouvoir être alimenté par une moyenne de kits.
- **Epic 04 (Ententes)** : les joueurs partenaires équipés par Soudron ne sont pas des `Licencie` et n'ont donc pas de `DotationBesoin`. Leur coût passe par l'avance d'équipement de l'Epic 04, pas par ce calcul.

## 8. Lots livrables

1. **Les termes du prix sur `StockItem` + `PrixFournisseurResolver`** — un article se retarife en changeant son prix catalogue. Livrable seul.
2. **Enveloppe par catégorie + coût du kit + alerte de dépassement.**
3. **Budget prévisionnel au kit le plus cher** — branché sur l'Epic 02.
4. **Répartition réelle des choix + budget à répartition constatée** — la valeur qui n'apparaît qu'à partir de la deuxième saison.

## 9. Points à trancher avant de coder

- **Le backfill de `prixCatalogue` sur les articles existants.** Les articles en production ont un `prixAchat` mais pas les termes. Deux options : laisser les termes vides (le prix reste saisi à la main, aucun risque) ou reconstituer le catalogue par division (`prixAchat − flocage) ÷ 0,65`). **Recommandation : ne rien reconstituer.** La division suppose un flocage connu article par article ; se tromper d'hypothèse écrirait un faux prix catalogue qui se propagerait ensuite à chaque recalcul. Les termes se saisissent au fil des retarifages.
- **Les gants sont un trou connu.** La dotation 25-26 en incluait (renouvelés à l'usure, systématiques pour les gardiens séniors). Le sujet a été « volontairement laissé de côté » de la fiche 26-27 et doit être ajouté au prévisionnel séparément. Décider s'ils entrent dans un kit ou restent une ligne d'équipement général.

## 10. Barème réel 26-27 — jeu de test complet

**Prix catalogue et flocage constatés**

| Article | Réf. | Catalogue enfant | Catalogue adulte | Flocage |
|---|---|---|---|---|
| Chaussette noire | 3180701 | — (taille unique) | 10,00 € | 0 € |
| Short noir | 3152602 | 15,00 € | 19,00 € | 0 € |
| T-shirt jaune Liga Star | 1082334 | 19,00 € | 24,00 € | **8,50 €** (logo + nom au dos) |
| Veste d'entraînement rouge | 1032319 | 38,00 € | 44,00 € | 3,50 € |
| T-shirt noir dirigeant | 1082333 | — | 24,00 € | 3,50 € |
| Polo dirigeant | 2112606 | — | 30,00 € | 3,50 € |
| Veste Softshell noire | 906201 | — | 130,00 € | 3,50 € |

**Prix finaux attendus** (contrôle de `PrixFournisseurResolver`) : chaussette 6,50 € · short enfant 9,75 € / adulte 12,35 € · t-shirt enfant 20,85 € / adulte 24,10 € · veste enfant 28,20 € / adulte 32,10 € · t-shirt dirigeant 19,10 € · polo 23,00 € · Softshell 88,00 €.

**Barème joueurs par catégorie**

| Catégorie | Enveloppe | Choix 1 — Veste | Choix 2 — T-shirt |
|---|---|---|---|
| Séniors | 40 € | 38,60 € | 30,60 € |
| U16 | 40 € | 38,60 € | 30,60 € |
| U13 / U11 / U9 / U7 | 46 € | 44,45 € | 37,10 € |

Séniors et U16 sont en référence adulte, sans short. U7-U13 sont en **référence enfant** sur les trois articles — le passage en référence adulte donnait 50,95 €, soit **4,95 € au-dessus de l'enveloppe**. C'est le test qui valide la règle 6.

**Paliers dirigeants** — pool 30 € × 15 = 450 €

| Palier | Public | Article | Prix |
|---|---|---|---|
| 1 | Dirigeants classiques (8) | T-shirt noir logo club | 19,10 € |
| 2 | Responsables foot hors coachs (4) | Polo logo club | 23,00 € |
| 3 | Coachs responsables (3) | Veste d'entraînement noire **+** t-shirt | 51,20 € |

Total retenu : **398,40 €**, marge +51,60 € sur le pool. L'option Softshell (88 €/coach) donnait 508,80 €, soit **58,80 € de dépassement** — écartée, avec la participation dirigeant (19,60 €/coach) écartée elle aussi pour raison d'équité. Justification officielle retenue : étaler la reconnaissance dans la durée plutôt que tout donner en une fois.

**Non-cumul** : sur 5 responsables foot, 1 est aussi coach responsable — il ne reçoit que le palier 3. C'est le test de `DotationAffectation::priorite()`.

**Résistance du modèle** : un 4ᵉ coach par **nouvelle tête** ramène la marge à +30,40 €, un 5ᵉ à +9,20 € — jamais de déficit, chaque tête apportant ses 30 €. Par **reclassement interne** (effectif inchangé), un 4ᵉ reste positif (+19,50 € à +23,40 €), un 5ᵉ passe en léger déficit (−4,80 € à −12,60 €), absorbable sur la ligne équipement générale.
