# Epic 05 — Organigramme et rôles des dirigeants

> **Statut** : à construire — **mais touche un existant très branché** (`Dirigeant.role`)
> **Source** : `prepa_epic/besoin_cosync_organigramme_roles.md`
> **Dépend de** : Epic 04 pour les catégories en entente (facultatif au lot 1)
> **Alimente** : Epic 02 (effectif dirigeants budgété), Epic 06 (qui encadre quoi)
> **⚠️ Cette epic modifie un champ que la dotation et les documents signés utilisent pour cibler. Lire le §7 avant tout.**

---

## 1. Pourquoi cette epic existe

La répartition des rôles est la **seule brique de la gestion du club qui repose encore entièrement sur un document texte statique** — les licences vivent dans FootClubs, les paiements dans HelloAsso, les dotations dans CoSync, et l'organigramme dans un fichier reconstruit de mémoire chaque saison.

Deux défauts ont été observés directement en construisant la liste 26-27 :

- le décompte des dirigeants par famille de rôle, fait à la main, **contenait une erreur d'addition**, repérée après coup ;
- le document est figé au moment de sa rédaction : une licence dirigeant validée ou un bénévole qui se désiste imposent de rouvrir le fichier, sans aucun lien avec les données réelles du club.

## 2. Ce qui existe déjà dans CoSync

`Dirigeant` est une entité complète : identité, coordonnées avec verrous manuels, taille, autorisations, `team`, `season`, `licenceAdministrative`, lien optionnel vers un `Licencie`.

Et surtout : **`Dirigeant.role` est un `DirigeantRole` unique** — `RESPONSABLE_FOOT`, `RESPONSABLE_EQUIPE` ou `DIRIGEANT`.

Ce champ n'est pas décoratif. Il est lu par :

| Consommateur | Usage |
|---|---|
| `DotationAffectation` + `DotationCibleType::ROLE` | attribue un kit de dotation selon le rôle (les 3 paliers de l'Epic 10) |
| `DocumentSignable` + `DocumentCible` | cible quels dirigeants doivent signer quel document |
| `DirigeantRepository` | filtres et écrans d'effectif |
| `ImportService` | affectation du rôle à l'import |
| `DirigeantService`, `DirigeantType`, `DirigeantData` | saisie et mise à jour |

**Le besoin d'organigramme est fondamentalement incompatible avec un rôle unique** : *« une même personne peut porter plusieurs casquettes en même temps — ce n'est pas une exception, c'est la norme dans un club de cette taille »*.

## 3. Périmètre

**Dans le périmètre**

- Attribuer plusieurs rôles à une même personne, classés en trois familles.
- Rattacher un rôle d'encadrement à une catégorie, avec un responsable et des adjoints.
- Un statut confirmé / à confirmer, adossé à la licence dirigeant.
- Les catégories actives par saison.
- Le décompte automatique, comparé à l'objectif budgété.
- Un export lisible de l'organigramme.

**Hors périmètre**

- Pas de gestion des droits applicatifs. Un rôle d'organigramme (« responsable buvette ») n'ouvre aucun accès dans CoSync — les droits restent portés par `User` et Symfony Security. Confondre les deux transformerait un outil d'organisation en système de permissions, avec les risques que ça implique.
- Pas de fiches de poste ni de planning de présence.
- La trésorerie et le secrétariat général **ne sont pas des rôles du foot** : ils relèvent de l'association. Ils ne doivent pas apparaître comme des postes à pourvoir dans l'organigramme du foot, même quand ils sont assurés dans les faits par un responsable foot.

## 4. Règles métier

1. **Le cumul de rôles est la norme.** Une personne peut porter plusieurs rôles, y compris à travers les trois familles : responsable foot **et** encadrant d'une catégorie **et** porteur d'une fonction transverse.

2. **Trois familles, non interchangeables.**
   - *Direction du foot* — les décisions structurantes (budget, orientations). Ils sont 5, dont 4 siègent au CA.
   - *Encadrement d'une catégorie* — un responsable et des adjoints, rattachés à une catégorie précise.
   - *Fonction transverse* — une tâche qui ne dépend d'aucune catégorie (communication, buvette, commandes matériel, relation mairie), à intitulé libre.

3. **« Responsable foot » et « encadrant » sont distincts.** Le premier relève de la direction générale, le second de l'encadrement sportif d'une tranche d'âge. Une personne peut avoir l'un, l'autre, ou les deux — les fusionner ferait disparaître la distinction que le club utilise pour savoir qui tranche.

4. **Un encadrement de catégorie a au plus un responsable, et autant d'adjoints que nécessaire.**

5. **Une fonction transverse peut être portée par plusieurs personnes**, avec des niveaux d'implication différents (titulaire, renfort ponctuel). Ce n'est pas « un seul responsable par fonction ».

6. **« À confirmer » ne veut pas dire « vacant ».** La personne est identifiée ; c'est sa licence dirigeant qui n'est pas encore validée auprès de la FFF. Le rôle reste visible dans l'organigramme, signalé comme non garanti. Il passe à « confirmé » une fois la licence enregistrée.

7. **Le statut se porte par rôle, pas seulement par personne.** Une même personne peut être confirmée sur un rôle et pressentie sur un autre.

8. **Une catégorie sans effectif n'est pas un poste à pourvoir : elle est absente.** Pas d'école de foot U7/U9 en 26-27 — l'organigramme ne doit pas afficher deux trous à combler pour des catégories qui n'existent pas cette saison.

9. **Une catégorie en entente n'a pas de responsable côté Soudron.** Le responsable est rattaché au club partenaire. Des dirigeants Soudron peuvent malgré tout être rattachés à la catégorie sans en être responsables, souvent avec un statut « à confirmer ».

10. **Le décompte est recalculé à chaque changement, jamais recompté.** C'est la règle qui répond directement à l'erreur d'addition constatée. Il se ventile par famille et par statut, et se compare à l'objectif budgété de la saison.

## 5. Modèle de données proposé

```php
// src/Entity/DirigeantAffectation.php — un rôle tenu par une personne, sur une saison
DirigeantAffectation
    id: int
    dirigeant: Dirigeant
    season: Season
    famille: FamilleRole             // DIRECTION | ENCADREMENT | TRANSVERSE
    category: ?Category              // renseigné ssi famille = ENCADREMENT
    niveau: ?NiveauEncadrement       // RESPONSABLE | ADJOINT — ssi ENCADREMENT (règle 4)
    intitule: ?string                // texte libre — ssi TRANSVERSE (règle 2)
    implication: ?ImplicationRole     // TITULAIRE | RENFORT — ssi TRANSVERSE (règle 5)
    statut: StatutRole               // CONFIRME | A_CONFIRMER (règles 6-7)
    note: ?string

// src/Entity/CategorieSaison.php — quelles catégories sont actives cette saison (règle 8)
CategorieSaison
    id: int
    season: Season
    category: Category
    active: bool = true
    enEntente: bool = false          // dérivable de l'Epic 04 quand elle existe
    entente: ?Entente

// Season — un champ à ajouter
Season
    objectifDirigeants: ?int         // l'effectif budgété, pour la comparaison de la règle 10
```

```php
// src/Enum/FamilleRole.php
enum FamilleRole: string {
    case DIRECTION   = 'direction';
    case ENCADREMENT = 'encadrement';
    case TRANSVERSE  = 'transverse';
}

// src/Enum/NiveauEncadrement.php
enum NiveauEncadrement: string { case RESPONSABLE = 'responsable'; case ADJOINT = 'adjoint'; }

// src/Enum/ImplicationRole.php
enum ImplicationRole: string { case TITULAIRE = 'titulaire'; case RENFORT = 'renfort'; }

// src/Enum/StatutRole.php
enum StatutRole: string { case CONFIRME = 'confirme'; case A_CONFIRMER = 'a_confirmer'; }
```

**Pourquoi une entité d'affectation plutôt que des colonnes sur `Dirigeant`** : le cumul est la norme (règle 1) et le nombre de rôles n'est pas borné. Une table d'affectation est aussi ce qui permet à l'historique de saison de tenir : un dirigeant change de rôle d'une saison à l'autre sans que l'organigramme passé ne bouge.

## 6. Services & écrans

| Classe | Rôle |
|---|---|
| `Service/Dirigeant/OrganigrammeService` | ajout, retrait, changement de statut d'une affectation |
| `Service/Dirigeant/OrganigrammeCollector` | assemble l'organigramme d'une saison : familles, catégories actives, ententes |
| `Service/Dirigeant/OrganigrammeDecompteResolver` | règle 10 — décompte par famille et par statut, écart à l'objectif |
| `Service/Dirigeant/OrganigrammePresenter` | mise en forme pour l'écran et pour l'export |
| `Controller/Admin/OrganigrammeController` | `/admin/effectif/organigramme` |

L'export (règle 8 du besoin : « générer un export lisible… utilisable aussi bien en interne que pour un lecteur externe ») passe par `PdfRenderer`, déjà en place pour les documents signés et les attestations.

## 7. ⚠️ Le point dur : migrer `Dirigeant.role` sans casser dotation ni documents

**Ne pas supprimer `Dirigeant.role`.** C'est la recommandation centrale de cette epic.

`DirigeantRole` est aujourd'hui la clé de ciblage de la dotation (`DotationCibleType::ROLE`) et des documents signables (`DocumentCible`). Le supprimer ou le rendre multi-valué d'un coup ferait, en production :

- des kits de dotation qui ne se résolvent plus, donc des `DotationBesoin` qui disparaissent ou se dupliquent ;
- des documents qui cessent d'être attendus de dirigeants qui ne les ont pas encore signés, ou qui le deviennent pour des dirigeants qui n'auraient pas dû.

**Stratégie recommandée — cohabitation, sur deux temps :**

1. `Dirigeant.role` **reste** ce qu'il est : le rôle **principal**, celui qui cible la dotation et les documents. Il garde exactement sa sémantique actuelle.
2. `DirigeantAffectation` s'ajoute **à côté**, et porte l'organigramme complet (cumul, familles, catégories, transverses, statuts). L'organigramme lit les affectations ; la dotation et les documents continuent de lire `role`.
3. Une cohérence douce s'affiche à l'écran quand `role` et les affectations divergent (un dirigeant `DIRIGEANT` qui est responsable d'une catégorie dans l'organigramme). **Signalé, jamais corrigé automatiquement** : c'est le rôle principal qui décide de ce que la personne reçoit et signe, et cette décision reste humaine.

Une bascule complète vers les affectations comme unique source de ciblage est envisageable **plus tard**, une fois l'organigramme rempli et vérifié sur une saison entière — pas avant, et jamais dans le même déploiement (§13 du CLAUDE.md : expand / backfill / contract).

### Autres jonctions

- **`Category`** est le référentiel des catégories ; `CategorieSaison` dit lesquelles sont actives (règle 8).
- **`Dirigeant.licenceAdministrative`** (président, secrétaire, trésorier déclarés au district) : ces personnes existent dans l'outil sans dossier ni dotation. Dans l'organigramme, elles peuvent parfaitement porter un rôle — et la règle §3 rappelle que trésorerie et secrétariat général ne sont pas des rôles *du foot*. Ne pas les faire apparaître comme des postes foot à pourvoir.
- **Epic 04 (Ententes)** alimente `CategorieSaison.enEntente` et le nom du référent partenaire (règle 9).
- **Epic 02 (Finance)** consomme le décompte pour la ligne dotation dirigeants et le compare à `Season.objectifDirigeants`.

## 8. Lots livrables

1. **Affectations + écran d'organigramme + décompte** — la valeur immédiate : plus de recomptage à la main.
2. **Catégories actives par saison** — fait disparaître les faux postes à pourvoir.
3. **Statut confirmé / à confirmer + comparaison à l'objectif budgété.**
4. **Export PDF.**
5. **Catégories en entente** — après l'Epic 04.

## 9. Points à trancher avant de coder

- **Une fonction transverse à intitulé libre ou à référentiel ?** Le besoin dit « texte libre ». Le risque connu de l'outil est la saisie libre (« zéro saisie libre non nécessaire », CLAUDE.md §1) : trois orthographes de « buvette » rendraient tout regroupement faux. **Recommandation** : un référentiel de fonctions transverses, éditable en admin comme les catégories de stock, plutôt qu'un champ texte.
- **Le statut « à confirmer » peut-il se lever automatiquement à l'import FootClubs ?** Une licence dirigeant validée apparaît dans l'export. Tentant, mais l'import ne doit jamais décider seul (cf. le principe des liens : « aucun mail ne part de lui-même »). **Recommandation** : proposer la levée, la faire valider.

## 10. Données réelles 26-27 pour tester

- **Anthony Wilk** — Président du foot / Vice-président de l'association ; porte le volet sportif (règles du jeu, District et Ligue, licences, feuilles de match).
- **Corentin Marcoux** — Coordinateur Général ; porte l'organisationnel et la logistique (matériel, calendrier, mairie, communication).
- **Direction du foot** : 5 responsables foot, dont 4 siègent au CA.
- **Effectif dirigeants budgété** : 15 — dont 3 coachs responsables, 4 responsables foot restants, 8 dirigeants classiques (répartition **théorique**, nominatif à confirmer).
- **Catégories actives** : Séniors, U16, U13, U11 — **pas de U7 ni U9** cette saison. U15 n'existe plus, fusionnée dans U16.
- **U16 en entente avec Conantre** (Soudron directeur) et **U13 en entente avec Vertus** (Vertus directeur, référent Julien Dupont, 1 à 2 dirigeants Soudron en soutien non nommés).
