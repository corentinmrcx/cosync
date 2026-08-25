# Epic 06 — Formations obligatoires des dirigeants (CFI)

> **Statut** : à construire — aucun équivalent dans CoSync aujourd'hui
> **Source** : `prepa_epic/epic_formations_dirigeants_cfi.md`
> **Dépend de** : Epic 01 (le barème 90 € / 20 € / 15 €), Epic 05 (qui encadre quoi) — les deux facultatives au lot 1
> **Alimente** : Epic 02 (ligne `FORMATION` du budget)
> **La plus petite epic du lot. Une à deux journées, et un poste de budget cesse d'être découvert en cours de saison.**

---

## 1. Pourquoi cette epic existe

Le règlement du district impose que, à certains niveaux de compétition, l'encadrant inscrit sur la feuille de match soit titulaire du CFI (Certificat Fédéral Initiateur) ou en cours de cursus. À défaut : **15 € de pénalité par match**.

L'obligation n'est pas attachée à un dirigeant « en général » : elle dépend du **niveau atteint par l'équipe qu'il encadre**, et peut donc apparaître **en cours de saison**, quand une équipe monte de division ou tombe dans un groupe D1.

Aujourd'hui rien n'est suivi. L'information circule oralement, en réunion, de façon fragmentée — et de façon approximative : *« un même tarif de formation peut être évoqué avec plusieurs valeurs différentes selon la personne qui en parle, faute de source de référence consultée en amont »*. Le club découvre l'état réel d'avancement (formé ? certifié ? rien fait ?) au moment où l'échéance devient urgente.

## 2. Ce qui existe déjà dans CoSync

Rien sur les formations. Deux appuis existants :

- **`Dirigeant`** porte l'identité et le rôle de la personne concernée.
- **Epic 05** dira proprement qui encadre quelle catégorie ; sans elle, `Dirigeant.team` et `Dirigeant.role` suffisent au lot 1.

## 3. Périmètre

**Dans le périmètre**

- Enregistrer qu'un dirigeant est concerné, avec la raison métier du déclenchement.
- Suivre l'avancement en quatre états.
- Afficher le coût par formation et le total consolidé de la saison.
- Ajouter une formation à tout moment de la saison.
- Conserver l'historique d'une saison sur l'autre.

**Hors périmètre — explicitement demandé**

- **L'inscription réelle à la formation** se fait sur les portails FFF / district. L'outil ne s'y substitue pas.
- **Le calcul des pénalités sportives** liées aux feuilles de match est géré ailleurs. Le montant de 15 €/match est enregistré comme information de contexte, pas comme un compteur de sanctions.

## 4. Règles métier

1. **Le CFI est décliné par tranche d'âge** — U6/U9, U10/U13, U14/U19, Seniors, Projet club — pour un **même volume horaire (24 h) et un même tarif**. La déclinaison qualifie la formation, elle n'en change pas le prix.

2. **Formation et certification sont deux étapes séparées**, réalisables à des moments différents, **sans délai maximum** entre les deux. Barème FFF : **formation 90 € + certification 20 € = 110 € par personne**.

3. **Le coût est intégralement pris en charge par le club.** Aucune participation n'est demandée au dirigeant. Ne pas modéliser de reste à charge : ce serait ouvrir une porte que le club a fermée (même raisonnement que pour la participation dirigeants sur la veste Softshell, Epic 10).

4. **« En cours de cursus » vaut conformité.** Tant que le dirigeant a commencé sa formation sans avoir la certification, le district le considère conforme — **pas de pénalité**. Un suivi qui ne distingue pas « en cours » de « rien fait » ne sert à rien : c'est exactement la frontière qui déclenche ou non les 15 €/match.

5. **L'obligation naît du niveau de compétition, et peut naître en cours de saison.** Une formation doit pouvoir être ajoutée à tout moment, pas seulement en préparation de saison. Un formulaire réservé à la préparation de saison manquerait la moitié des cas.

6. **La raison du déclenchement se saisit en clair** — « montée potentielle en D2 », « groupe D1 possible en phase 2 ». Six mois plus tard, personne ne se souviendra pourquoi telle formation avait été inscrite au budget.

7. **Le CFI est un acquis durable.** Aucune durée de validité ni recyclage périodique n'a été identifié, contrairement aux diplômes professionnels (BEES, BEF, BMF). Il vaut donc **pour toute la durée d'engagement du dirigeant au club** : une personne certifiée ne doit jamais se voir reproposer la formation la saison suivante.
   *À reconfirmer auprès du district avant toute décision budgétaire pluriannuelle appuyée dessus.*

8. **Une formation non confirmée ne compte pas dans le total.** Le club a une 3ᵉ formation évoquée mais non confirmée : elle doit pouvoir être notée sans polluer le montant transmis au budget.

## 5. Modèle de données proposé

```php
// src/Entity/FormationDirigeant.php
FormationDirigeant
    id: int
    season: Season                   // la saison où l'obligation apparaît
    dirigeant: Dirigeant
    typeCfi: TypeCfi                 // règle 1
    category: ?Category              // l'équipe encadrée qui déclenche l'obligation
    raisonDeclenchement: string      // règle 6
    statut: StatutFormation          // règle 4
    coutFormation: float             // 90 € par défaut, depuis le barème (Epic 01)
    coutCertification: float         // 20 €
    confirmee: bool = false          // règle 8
    formeLe: ?\DateTimeImmutable
    certifieLe: ?\DateTimeImmutable
    note: ?string
    // coutTotal() = coutFormation + coutCertification
```

```php
// src/Enum/TypeCfi.php
enum TypeCfi: string {
    case U6_U9      = 'u6_u9';
    case U10_U13    = 'u10_u13';
    case U14_U19    = 'u14_u19';
    case SENIORS    = 'seniors';
    case PROJET_CLUB = 'projet_club';
}

// src/Enum/StatutFormation.php — les quatre états de la règle 4
enum StatutFormation: string {
    case NON_COMMENCE     = 'non_commence';      // ⚠️ non conforme : pénalité encourue
    case EN_COURS         = 'en_cours';          // conforme aux yeux du district
    case FORME_NON_CERTIFIE = 'forme_non_certifie';
    case CERTIFIE         = 'certifie';          // acquis durable (règle 7)
}
```

**Pourquoi les coûts sont stockés sur la formation et pas seulement lus dans le barème** : le barème peut évoluer (le besoin dit « modifiable si le barème FFF évolue un jour »). Une formation engagée à 90 € reste à 90 € même si le tarif change ensuite — le montant est recopié à la création, comme le libellé de taille est recopié dans `stock_movement`.

## 6. Services & écrans

| Classe | Rôle |
|---|---|
| `Service/Formation/FormationService` | création, changement de statut, confirmation |
| `Service/Formation/FormationCoutResolver` | total consolidé des formations **confirmées** de la saison, pour l'Epic 02 |
| `Service/Formation/CfiAcquisResolver` | règle 7 — un dirigeant déjà certifié, toutes saisons confondues, ne se voit plus proposer la formation |
| `Controller/Admin/FormationController` | `/admin/effectif/formations` — liste, ajout, avancement |

Un écran unique suffit : une liste avec le dirigeant, la déclinaison, le déclencheur, le statut, le coût, et le total en pied de tableau. C'est exactement le tableau que le club tient de tête aujourd'hui.

## 7. Points de jonction avec l'existant

- **`Dirigeant`** : la personne concernée. Attention, `Dirigeant` est **cloisonné par saison** — un dirigeant présent trois saisons est trois lignes. `CfiAcquisResolver` doit donc rapprocher les personnes **entre saisons** pour appliquer la règle 7. `DetenteurEffectifResolver` fait déjà ce travail pour les clés (rapprochement sur le numéro de licence, puis sur le nom) : reprendre la même mécanique plutôt qu'en inventer une seconde.
- **Epic 01 (Référentiel)** : les 90 €, 20 € et 15 € sont des `NatureTarif::FORMATION` et `SANCTION`. Sans l'Epic 01, les mettre en valeurs par défaut configurables.
- **Epic 05 (Organigramme)** : c'est elle qui dira proprement qui encadre quelle catégorie. Le lien `category` de la formation devrait à terme se déduire de l'affectation d'encadrement.
- **Epic 02 (Finance)** : `FormationCoutResolver` rend le total → ligne `NatureBudget::FORMATION`, fiabilité `OFFICIEL`.

## 8. Lots livrables

1. **Entité, écran, total consolidé** — tout le besoin tient dans ce lot. C'est la plus petite epic du lot, et elle se livre d'un bloc.
2. **`CfiAcquisResolver`** — la mémoire inter-saisons, utile à partir de la deuxième saison d'usage.
3. **Branchements** — barème (Epic 01) et budget (Epic 02).

## 9. Points à trancher avant de coder

- **La durée de validité du CFI est à reconfirmer auprès du district.** La règle 7 (acquis durable) est l'hypothèse retenue, explicitement signalée comme à revérifier dans le besoin. Le modèle la supporte sans la figer : ajouter un `valableJusquAu` nullable coûterait une colonne et éviterait une migration si la réponse du district est différente. **Recommandation** : prévoir la colonne, la laisser vide.
- **Faut-il compter les pénalités réellement subies ?** Explicitement hors périmètre. Mais si le club veut un jour mesurer ce que la non-conformité a coûté, la donnée passerait par les relevés fédéraux de l'Epic 02 (nature `SANCTION`), pas par cette epic.

## 10. Données réelles 26-27 pour tester

| Référent | CFI concerné | Déclencheur | Statut | Coût |
|---|---|---|---|---|
| Référent Seniors (Damien) | CFI Seniors | Montée potentielle en D2 | Non commencé | 110 € |
| Référent U16 (Charley) | CFI U14/U19 | Groupe D1 possible en phase 2 | Non commencé | 110 € |
| **Total confirmé** | | | | **220 €** |

Une 3ᵉ formation est évoquée, **non confirmée** : elle doit pouvoir être saisie sans entrer dans les 220 € (règle 8). C'est le test d'acceptation de cette règle.

**Contexte des deux déclencheurs** : la saison U16 se joue en deux phases — la première mélange les niveaux, la seconde répartit en groupes D1 (« USD1 ») et D2. L'obligation ne se révèle donc qu'en cours de saison, ce qui est exactement le cas d'usage de la règle 5.
