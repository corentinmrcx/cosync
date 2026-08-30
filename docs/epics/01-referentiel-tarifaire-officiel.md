# Epic 01 — Référentiel tarifaire officiel (District / Ligue / FFF)

> **Statut** : à construire — aucun équivalent dans CoSync aujourd'hui
> **Source** : `prepa_epic/Epic_CoSync_Finance_Budget.md` §3, notes « Grilles tarifaires officielles comme référentiel versionné »
> **Dépendances** : aucune — c'est le socle
> **Alimente** : Epic 02 (Finance), Epic 06 (Formations CFI), Epic 04 (Ententes)

---

## 1. Pourquoi cette epic existe

Chaque montant du prévisionnel 26-27 qui vient d'une source officielle a été relu dans un PDF, à la main, à chaque fois qu'il servait. Le chantier Finance en fait le constat direct : la grille du District Marne est « la source la plus fiable du prévisionnel », mais elle vit dans un PDF qu'il faut rouvrir, et les anciens fichiers Excel du club « contiennent des tarifs périmés (une ancienne grille de licence, avant la revalorisation d'une saison à l'autre) ».

Le défaut n'est pas l'absence du chiffre, c'est **l'absence de datation du chiffre**. Le compte-rendu Finance signale que d'anciens et de nouveaux montants ont été mélangés « plusieurs fois dans ce chat ». Un tarif sans saison attachée est un piège : rien ne distingue visuellement un tarif 25-26 encore valable d'un tarif 25-26 périmé.

Ce référentiel existe donc pour une seule raison : **qu'un montant officiel soit saisi une fois par saison, daté, et lu partout ailleurs sans jamais être ressaisi**.

## 2. Ce qui existe déjà dans CoSync

Rien sur les tarifs fédéraux. À ne pas confondre avec ce qui existe :

- `Season.cotisationDefaut` et `Team.cotisation` portent **ce que le club facture à son licencié** — une décision du club, pas un tarif fédéral.
- `Category` (code `U6`…`SENIOR`) est le référentiel FFF des catégories, et sert de clé d'entrée naturelle à la grille.

Le référentiel décrit ici porte **ce que le club doit au District, à la Ligue et à la FFF**. Les deux ne se confondent jamais : le premier est une recette, le second une dépense.

## 3. Périmètre

**Dans le périmètre**

- Saisie, par saison, des barèmes officiels : licence par catégorie, engagement en compétition par division, droits de mutation par catégorie, barème disciplinaire (cartons, forfaits), cotisations fixes de structure, tarifs de formation.
- Lecture de ces barèmes par le reste de l'outil, via un point d'entrée unique.
- Duplication d'une saison sur l'autre, avec relecture obligatoire des montants.

**Hors périmètre**

- Aucun import automatique depuis un PDF District. Le club reçoit un PDF par saison ; le ressaisir prend vingt minutes une fois l'an, alors qu'un parseur de PDF fédéral casserait à la première refonte de maquette.
- Aucun calcul. Le référentiel **rend des montants**, il n'en dérive aucun. Les calculs sont dans l'Epic 02.

## 4. Règles métier

1. **Un barème appartient toujours à une saison.** Il n'existe pas de « tarif courant » global. Lire un tarif sans préciser la saison est une erreur d'appel, pas un cas par défaut.

2. **Un barème d'une saison close ne se modifie plus.** Les montants d'une saison passée ont produit des chiffres déjà communiqués (bilan, prévisionnel remis au CA) ; les corriger après coup ferait diverger un document distribué et l'outil. Une erreur constatée après clôture se corrige sur la saison en cours, pas rétroactivement.

3. **Dupliquer une saison ne recopie pas la validité.** La duplication est le mode de création normal (les barèmes bougent peu), mais chaque ligne dupliquée arrive marquée **à relire**. Tant qu'une ligne n'est pas relue, tout écran qui l'affiche le signale. C'est la seule protection contre le défaut réel constaté : reconduire machinalement un tarif revalorisé entre-temps.

4. **Le barème disciplinaire porte sa règle de minoration.** La grille District applique une sanction réduite de moitié aux catégories U12/U13. Cette règle est une donnée du barème, pas une exception codée en dur : elle change quand le District la change.

5. **Une catégorie exemptée est une valeur, pas une absence.** Les droits de mutation sont nuls pour les catégories les plus jeunes. « 0 € parce que la FFF exempte » et « montant pas encore saisi » doivent se distinguer à l'écran, sans quoi une saisie oubliée passe pour une exemption.

6. **Le référentiel ne rend jamais un montant approchant.** Un tarif absent pour une catégorie donnée rend `null`, et l'écran appelant affiche un trou. Même principe que `StockTailleResolver` (CLAUDE.md, §GrilleTaille) : « mieux vaut un trou visible qu'une déclinaison inventée ».

## 5. Modèle de données proposé

```php
// src/Entity/BaremeOfficiel.php — l'en-tête d'une grille pour une saison
BaremeOfficiel
    id: int
    season: Season
    instance: InstanceFederale       // DISTRICT | LIGUE | FFF
    libelle: string                  // "Grille District Marne 2026-2027"
    sourceUrl: ?string               // lien ou référence du PDF officiel
    dateBareme: ?\DateTimeImmutable  // date du document officiel
    lignes: Collection<BaremeLigne>

// src/Entity/BaremeLigne.php — un montant, sa nature, sa portée
BaremeLigne
    id: int
    bareme: BaremeOfficiel
    nature: NatureTarif              // cf. enum ci-dessous
    category: ?Category              // null = ne dépend pas de la catégorie
    libelle: string                  // "Engagement D3", "3e avertissement"
    montant: float
    minoration: ?float               // 0.5 pour la règle U12/U13 ; null = pas de minoration
    aRelire: bool = false            // posé par la duplication, levé à la relecture
    note: ?string
```

```php
// src/Enum/NatureTarif.php
enum NatureTarif: string {
    case LICENCE            = 'licence';             // quote-part fédérale par licencié
    case ENGAGEMENT         = 'engagement';          // inscription d'une équipe en compétition
    case MUTATION           = 'mutation';            // droit de mutation d'une recrue
    case SANCTION           = 'sanction';            // cartons
    case FORFAIT            = 'forfait';             // équipe non alignée
    case COTISATION_CLUB    = 'cotisation_club';     // cotisations fixes, caisse de secours, frais généraux
    case FORMATION          = 'formation';           // CFI et certification (cf. Epic 06)
}

// src/Enum/InstanceFederale.php
enum InstanceFederale: string {
    case DISTRICT = 'district';
    case LIGUE    = 'ligue';
    case FFF      = 'fff';
}
```

**Pourquoi une table de lignes plutôt qu'une colonne par tarif** : la grille change de forme d'une saison à l'autre (une division apparaît, un palier de sanction est ajouté). Une colonne par tarif imposerait une migration à chaque évolution du District — exactement ce que le §13 du CLAUDE.md demande d'éviter sur une base de production.

## 6. Services & écrans

| Classe | Rôle |
|---|---|
| `Service/Referentiel/BaremeResolver` | **Point de lecture unique.** `montantPour(Season, NatureTarif, ?Category): ?float`. Applique la minoration. Rend `null` sans jamais approcher. |
| `Service/Referentiel/BaremeService` | Écriture : création, mise à jour, duplication d'une saison sur l'autre (pose `aRelire` partout), refus de modification d'une saison close. |
| `Controller/Admin/BaremeController` | `/admin/config/baremes` — liste par saison et par instance, édition ligne à ligne, action « Dupliquer depuis la saison précédente », badge « à relire ». |

Le référentiel vit sous `/admin/config/`, à côté de `/admin/config/documents` : c'est un réglage de saison, pas un écran d'exploitation quotidienne.

## 7. Points de jonction avec l'existant

- **`Category`** est la clé d'entrée de `BaremeLigne.category`. La grille District raisonne en catégories FFF, `Category` aussi — pas de traduction à écrire.
- **`Season`** porte le cloisonnement, comme partout ailleurs dans l'outil.
- **`SeedReferentialCommand`** peuple déjà catégories FFF et rôles dirigeants de façon idempotente. Les barèmes n'y ont **pas** leur place : ce sont des montants qui changent chaque saison, pas un référentiel structurel. Les seeder ferait réapparaître des tarifs périmés à chaque exécution.

## 8. Lots livrables

1. **Entités + migration + écran de saisie** — création manuelle d'un barème et de ses lignes, une saison. Livrable seul : le club a déjà arrêté de rouvrir le PDF.
2. **`BaremeResolver` + duplication de saison** — la lecture par le reste de l'outil et le drapeau `aRelire`.
3. **Branchements** — consommation par l'Epic 02 (Finance) et l'Epic 06 (CFI).

## 9. Points à trancher avant de coder

- **La quote-part licence est un écart non expliqué.** Le compte-rendu Finance §6 signale 225 € trouvés sur relevé contre 1 289 € reconstitués depuis la grille, écart « écarté délibérément ». Décider si le référentiel porte le tarif de grille (et donc reproduit l'écart) ou si cette nature de tarif est laissée hors référentiel tant que l'écart n'est pas compris. **Recommandation** : porter le tarif de grille et laisser l'écart visible côté Finance — un écart affiché finit par être expliqué, un écart absent jamais.
- Faut-il historiser les modifications d'une ligne en cours de saison (le District corrige rarement mais peut le faire) ? Un `append-only` à la `StockMovementCorrection` serait cohérent avec le reste de l'outil, mais probablement disproportionné ici.

## 10. Données réelles 26-27 pour tester

| Nature | Portée | Montant |
|---|---|---|
| Licence | Jeunes (facturé par le club) | 85 € |
| Licence | Séniors (facturé par le club) | 120 € |
| Sanction | 1er avertissement | 15 € |
| Sanction | 2e avertissement | 20 € |
| Sanction | 3e avertissement | 40 € |
| Sanction | Exclusion | 60 € |
| Sanction | Minoration U12/U13 | × 0,5 |
| Formation | CFI | 90 € |
| Formation | Certification CFI | 20 € |
| Sanction | Encadrant non formé, par match | 15 € |

Saison 25-26 constatée sur relevés, utile en jeu de test : **865 € de cartons sur 42 sanctions**, concentrées sur 3-4 joueurs séniors.
