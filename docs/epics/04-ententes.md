# Epic 04 — Ententes sportives

> **Statut** : à construire — aucun équivalent dans CoSync aujourd'hui
> **Source** : `prepa_epic/besoin_fonctionnel_ententes.md`
> **Dépend de** : rien
> **Alimente** : Epic 02 (l'effectif Soudron par catégorie), Epic 05 (organigramme d'une catégorie en entente)

---

## 1. Pourquoi cette epic existe

Certaines catégories n'ont pas assez de joueurs pour aligner une équipe. Plutôt que de laisser ces jeunes arrêter, deux clubs mutualisent leurs effectifs sur une catégorie. Ce n'est ni une opération financière ni un partenariat commercial : **l'objectif unique est de sauver des effectifs.**

Le besoin d'outil vient d'un incident précis et coûteux : le prévisionnel financier comptait **16 joueurs U16 sans distinguer** combien étaient licenciés à Soudron et combien à Conantre. Or seuls les licenciés Soudron génèrent une recette de licence. L'erreur n'a été repérée qu'en reconstituant les ententes en détail, à la main.

Tout le reste suit : les ententes sont reconduites de mémoire, aucune convention écrite n'existe (même pour Conantre, reconduite depuis 3 saisons), et les avances d'équipement entre clubs ne sont tracées nulle part.

## 2. Ce qui existe déjà dans CoSync

- **`Team`** est l'équipe interne, rattachée à une saison, avec ses `categories`. C'est le support naturel d'une équipe d'entente.
- **`Licencie.team` et `Licencie.category`** portent le rattachement d'un joueur.
- **Rien** ne permet aujourd'hui de représenter un joueur qui joue dans l'équipe sans être licencié au club. Et c'est **précisément le trou** : `Licencie` est, par construction, un licencié Soudron importé de FootClubs.

## 3. Périmètre

**Dans le périmètre**

- Déclarer une entente pour une saison : club partenaire, catégorie, ancienneté, club directeur, lieux.
- Suivre l'effectif de l'équipe engagée, réparti par club de licence.
- Suivre l'encadrement des deux côtés et le statut administratif (FootClubs, convention).
- Tracer les avances d'équipement et leur remboursement.

**Hors périmètre**

- **Aucun joueur du club partenaire n'entre dans `Licencie`.** Le club ne gère pas leurs licences, ne collecte pas leurs données, ne leur envoie pas de formulaire. Les faire entrer dans `Licencie` polluerait tous les décomptes de l'outil et poserait un problème RGPD — le club n'est pas responsable de traitement pour les licenciés d'un autre club.
- Pas de rédaction ni de signature de convention dans l'outil. Le club suit **l'état** de la convention, il ne la produit pas ici.
- Pas de gestion des feuilles de match ni des convocations.

## 4. Règles métier

1. **Aucun transfert de licence, jamais.** Chaque club licencie et facture ses propres joueurs. Un joueur n'est jamais « licencié entente » : il est licencié Soudron ou licencié partenaire, point.

2. **Un seul club directeur par entente.** C'est lui qui inscrit l'équipe en compétition et règle les frais d'engagement **pour l'effectif entier**, y compris les joueurs qui ne sont pas ses licenciés. L'autre club ne paie aucun frais d'engagement pour cette équipe.

3. **Le club directeur se saisit, ne se déduit jamais.** Il ne résulte pas de qui a le plus de joueurs — c'est un accord entre les deux clubs. Le déduire automatiquement donnerait un résultat faux sur l'entente U13 (Vertus est directeur alors que la répartition ne l'impose pas).

4. **La recette de licence suit le club de licence, pas l'équipe.** Un joueur licencié Conantre qui porte les couleurs de Soudron ne génère **aucune** recette pour Soudron. C'est la règle dont la violation a causé l'erreur du prévisionnel 26-27.

5. **Le nombre de licenciés Soudron est le seul chiffre qui alimente les calculs financiers du club** — recette de licence, budget dotation, coût par catégorie. L'effectif total de l'équipe est une donnée d'affichage et de logistique, jamais une donnée de budget. L'outil doit rendre cette distinction impossible à confondre à l'écran.

6. **La dotation suit le club de licence, indépendamment du rôle de club directeur.**
   - Un licencié Soudron reçoit sa dotation standard, que son équipe soit en entente ou non, et que Soudron soit directeur ou non. Les 5 U13 licenciés Soudron reçoivent la dotation du club alors que Vertus est directeur.
   - Si Soudron est directeur et équipe des joueurs licenciés ailleurs, le club partenaire **rembourse à l'euro près**.
   - Le kit de match (maillots de compétition) est fourni par le club directeur pour toute l'équipe — ce n'est pas de la dotation individuelle.

7. **L'avance d'équipement est une transaction blanche : nette nulle, mais tracée.** Une fois remboursée, elle ne pèse pas sur le budget du club. Tant qu'elle ne l'est pas, l'écart est visible. Le club doit pouvoir justifier le mouvement d'argent à tout moment.

8. **Aucun autre flux financier entre les deux clubs.** L'entente est un service rendu, pas un accord commercial. Ne pas modéliser de contrepartie, de participation ou de refacturation : les inventer ouvrirait une porte que le club ne veut pas.

9. **Une entente sans convention écrite est un état valide, pas une erreur.** Aucune des deux ententes actuelles n'a de convention signée. Le système représente cet état **sans le bloquer**, mais le rend visible d'une saison sur l'autre — c'est ce qui manque aujourd'hui pour penser à régulariser.

10. **Un lieu de match n'est pas forcément figé sur la saison.** Les matchs U13 sont prévus à Vertus, avec une possibilité non actée de jouer à Soudron si le terrain est occupé. Le champ doit accepter cette nuance plutôt que forcer un lieu unique.

## 5. Modèle de données proposé

```php
// src/Entity/ClubPartenaire.php — hors saison : un club voisin ne change pas au 1er juillet
ClubPartenaire
    id: int
    nomUsage: string                 // "Vertus"
    nomOfficiel: ?string             // "Football Club de la Côte des Blancs"
    contact: ?string
    actif: bool = true

// src/Entity/Entente.php
Entente
    id: int
    season: Season
    partenaire: ClubPartenaire
    category: Category
    team: ?Team                      // l'équipe interne support, quand Soudron est directeur
    clubDirecteur: ClubDirecteur     // SOUDRON | PARTENAIRE (règle 3 : saisi, jamais déduit)
    ancienneteSaisons: int = 1
    lieuMatchs: ?string              // texte libre (règle 10)
    lieuxEntrainements: ?string
    declareeFootclubs: bool = false
    conventionSignee: bool = false
    conventionSigneeLe: ?\DateTimeImmutable
    effectifPartenaire: int = 0      // règle : compté, jamais nominatif (cf. §3 hors périmètre)
    referentPartenaireNom: ?string
    referentPartenaireContact: ?string
    dirigeantsSoudron: Collection<Dirigeant>
    avances: Collection<AvanceEquipement>
    note: ?string

// src/Entity/AvanceEquipement.php — la transaction blanche (règle 7)
AvanceEquipement
    id: int
    entente: Entente
    libelle: string                  // "Dotation 3 joueurs Conantre"
    montantAvance: float
    montantRembourse: float = 0
    rembourseLe: ?\DateTimeImmutable
    // ecart() = montantAvance - montantRembourse
```

```php
// src/Enum/ClubDirecteur.php
enum ClubDirecteur: string { case SOUDRON = 'soudron'; case PARTENAIRE = 'partenaire'; }
```

**Pourquoi `effectifPartenaire` est un simple compteur et non une liste de personnes** : le club n'a pas besoin de leurs identités, ne les collecte pas, et n'a aucune base légale pour les stocker. Un entier suffit à tout ce qui est demandé — afficher la répartition et l'effectif total. C'est aussi ce qui garantit qu'aucun joueur partenaire ne pourra jamais être compté par erreur dans une recette : il n'existe pas comme personne dans l'outil.

**L'effectif Soudron ne se stocke pas** : il se compte depuis `Licencie` (saison + catégorie), donc il ne peut pas diverger de la réalité de l'effectif.

## 6. Services & écrans

| Classe | Rôle |
|---|---|
| `Service/Entente/EntenteService` | création, reconduction d'une saison sur l'autre (incrémente l'ancienneté) |
| `Service/Entente/EntenteEffectifResolver` | **le point de lecture unique de la règle 5** : rend `{soudron, partenaire, total}` pour une entente, et l'effectif Soudron d'une catégorie pour l'Epic 02 |
| `Service/Entente/AvanceEquipementService` | enregistrement d'une avance et de son remboursement |
| `Controller/Admin/EntenteController` | `/admin/effectif/ententes` — liste, fiche, statut administratif |

**`EntenteEffectifResolver` est la classe qui empêche l'erreur de 26-27 de se reproduire.** Tout écran ou service qui a besoin d'un effectif pour un calcul financier passe par lui, jamais par un décompte d'équipe.

## 7. Points de jonction avec l'existant

- **`Team`** : une équipe d'entente reste une `Team` ordinaire quand Soudron est directeur. Elle ne contient que des `Licencie` Soudron — les joueurs partenaires sont hors `Team`, comme ils sont hors `Licencie`.
- **`Category`** : l'entente porte la catégorie concernée. Rien n'empêche plusieurs ententes sur des catégories différentes la même saison (c'est le cas actuel : U16 et U13).
- **Epic 02 (Finance)** : la recette de licence et le budget dotation d'une catégorie en entente se calculent sur l'effectif Soudron rendu par `EntenteEffectifResolver`. L'avance d'équipement devient une `LigneCloisonnee` (Epic 02, règles 12-13) — même mécanique, même invariant de résultat net nul.
- **Epic 05 (Organigramme)** : une catégorie en entente n'a pas de responsable d'équipe côté Soudron ; l'organigramme affiche le référent partenaire à la place. `Entente.referentPartenaireNom` est la source.
- **Epic 10 (Dotation)** : les licenciés Soudron d'une catégorie en entente sont traités comme tous les autres par `DotationBesoinSynchronizer`. Rien à changer — c'est exactement ce que dit la règle 6, et l'existant s'y conforme déjà puisqu'il travaille sur `Licencie`.

## 8. Lots livrables

1. **Entente + club partenaire + effectif réparti** — l'écran de suivi et `EntenteEffectifResolver`. Livrable seul : le trou du prévisionnel est bouché.
2. **Statut administratif** — FootClubs, convention, avec le rappel d'une saison sur l'autre.
3. **Avances d'équipement** — la transaction blanche, branchée sur les lignes cloisonnées de l'Epic 02.
4. **Reconduction d'une saison sur l'autre** — dupliquer l'entente en incrémentant l'ancienneté.

## 9. Points à trancher avant de coder

- **Faut-il une `Team` quand Soudron n'est pas directeur ?** Les 5 U13 licenciés Soudron jouent dans une équipe gérée par Vertus. Leur créer une `Team` interne « U13 entente Vertus » facilite l'affichage mais crée une équipe qui n'existe dans aucune compétition. **Recommandation** : oui, une `Team` interne, parce que `Licencie.team` sert déjà partout (dotation, cotisation, filtres) et qu'une catégorie sans équipe crée plus de cas particuliers qu'elle n'en évite.
- **L'ancienneté s'incrémente-t-elle automatiquement à la reconduction ?** Oui, mais la reconduction doit rester un geste explicite : une entente n'est jamais reconduite d'office, elle se redécide chaque saison.

## 10. Données réelles 26-27 pour tester

**Entente U16 — Soudron / Conantre**
Ancienneté 3 · Club directeur **Soudron** · 14 licenciés Soudron + 3 licenciés Conantre = 17 · Matchs et entraînements 100 % à Soudron · Engagement payé par Soudron · Soudron avance l'équipement des 3 joueurs Conantre, remboursé à l'euro près · Référente Conantre : Ophélie Morin · **Déclarée FootClubs, pas de convention signée**.

**Entente U13 — Soudron / Vertus** (Football Club de la Côte des Blancs)
Ancienneté 1 · Club directeur **Vertus** · 5 licenciés Soudron · Matchs à Vertus (possibilité non actée à Soudron) · Entraînements lundi à Vertus, mercredi à Soudron · Engagement payé par Vertus, **aucun coût pour Soudron** · Les 5 licenciés Soudron reçoivent la dotation standard, kit de match fourni par Vertus · Encadrant Vertus : Julien Dupont · 1 à 2 dirigeants Soudron en soutien, non nommés · **Accord oral, pas de convention**.

Ces deux cas doivent être représentables **intégralement, sans exception ni contournement**. C'est le test d'acceptation de l'epic.
