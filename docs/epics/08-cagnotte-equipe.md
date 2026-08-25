# Epic 08 — Cagnotte collective par équipe

> **Statut** : à construire — issu des notes de préparation, sans fichier de contexte dédié
> **Source** : notes vrac (« module cagnotte collective joueurs », « contribution individuelle, rotation du responsable courses, alerte sous seuil »)
> **Dépend de** : rien
> **Alimente** : rien — **et c'est le point le plus important de cette epic** (cf. règle 1)

---

## 1. Pourquoi cette epic existe

Les collations d'après-match des U16 et des Séniors doivent être **autofinancées par les joueurs**, pas par le club. La décision est prise ; ce qui manque, c'est l'outil pour la tenir. Aujourd'hui personne ne sait dire qui a cotisé, combien, ni ce qu'il reste dans la cagnotte.

La mécanique voulue est simple et déjà décrite : une contribution individuelle en début de saison, une rotation d'un responsable « courses » d'un match à l'autre, et un solde qu'on peut consulter.

Le même besoin revient pour d'autres usages : pot de fin de saison, déplacement collectif. C'est ce qui justifie un module plutôt qu'un champ sur l'équipe.

## 2. Ce qui existe déjà dans CoSync

Rien. Le voisinage à ne surtout pas réutiliser :

- **`Transaction`** porte les encaissements de cotisation du club. Une contribution de cagnotte **n'est pas** une transaction du club (cf. règle 1).
- **Le module Événementiel (Epic 03)** couvre les événements du club. Une cagnotte d'équipe n'est pas un événement : elle vit toute la saison et ne produit pas de résultat net à consolider.

## 3. Périmètre

**Dans le périmètre**

- Une cagnotte par équipe et par saison.
- Les contributions par joueur, et le solde qui en découle.
- Les dépenses engagées sur la cagnotte, avec le responsable qui les a faites.
- La rotation du responsable « courses » d'un match à l'autre.
- Une alerte quand le solde passe sous un seuil.

**Hors périmètre**

- **Aucun encaissement.** La cagnotte enregistre des mouvements décidés hors de l'outil (espèces au vestiaire, virement entre joueurs, HelloAsso si le club le veut). CoSync ne collecte pas cet argent — il n'appartient pas au club.
- Pas de remboursement automatique de solde en fin de saison. Ce que devient un reliquat est une décision d'équipe, pas une règle d'outil.
- Pas de calendrier des matchs. La rotation se saisit ; elle ne se déduit d'aucun calendrier, qui n'existe pas dans CoSync.

## 4. Règles métier

1. **Une cagnotte n'est jamais de la trésorerie du club, et ne remonte jamais au budget.** C'est la règle qui fonde l'epic : *« distinct de la trésorerie club — jamais mélangé dans le bilan association »*. Aucun montant de cagnotte n'apparaît dans l'Epic 02, ni en recette, ni en dépense, ni en ligne cloisonnée. Un club qui ferait transiter cet argent par son bilan devrait le justifier comptablement — c'est précisément ce que l'autofinancement évite.

2. **Le dirigeant référent voit la cagnotte, mais elle appartient à l'équipe.** Une visibilité est demandée pour le référent ; elle sert à accompagner, pas à disposer des fonds.

3. **Le solde se calcule, ne se saisit pas.** Solde = contributions − dépenses. Un solde saisissable serait un solde qu'on peut faire mentir, et une cagnotte ne survit pas à la suspicion.

4. **Une contribution est nominative.** Savoir qui a cotisé et qui ne l'a pas fait est le premier service rendu : c'est ce qui permet la relance amiable sans que quiconque ait à tenir une liste de tête.

5. **Une dépense porte son responsable et son motif.** La rotation des courses n'a d'intérêt que si l'on sait qui a avancé quoi.

6. **Le responsable des courses tourne, et la rotation est une donnée, pas un calcul.** Le club veut désigner un responsable par match. L'outil enregistre la désignation et affiche qui est passé et qui ne l'est pas encore ; il ne calcule pas un tour automatique — un joueur absent, blessé ou indisponible casserait tout ordre théorique.

7. **Le seuil d'alerte est réglable par cagnotte.** Une équipe de 19 séniors et une équipe de 14 U16 n'ont pas le même rythme de dépense.

8. **Une cagnotte appartient à une saison**, comme le reste de l'outil. Un solde ne se reporte pas d'office d'une saison à l'autre : le report est une décision d'équipe, qui se saisit comme une contribution d'ouverture.

## 5. Modèle de données proposé

```php
// src/Entity/Cagnotte.php
Cagnotte
    id: int
    season: Season
    team: Team
    libelle: string                  // "Collations U16", "Pot de fin de saison"
    contributionAttendue: ?float     // ce qui est demandé à chaque joueur (règle 4)
    seuilAlerte: ?float              // règle 7
    referent: ?Dirigeant             // règle 2
    active: bool = true
    mouvements: Collection<CagnotteMouvement>
    // solde() = somme(CONTRIBUTION) - somme(DEPENSE) — règle 3

// src/Entity/CagnotteMouvement.php — append-only, comme CleMouvement
CagnotteMouvement
    id: int
    cagnotte: Cagnotte
    type: CagnotteMouvementType      // CONTRIBUTION | DEPENSE | REPORT
    licencie: ?Licencie              // qui a cotisé (règle 4) ou qui a avancé (règle 5)
    montant: float
    motif: ?string                   // "courses match du 12/10" (règle 5)
    date: \DateTimeImmutable
    createdBy: User
    createdAt: \DateTimeImmutable

// src/Entity/TourCourses.php — la rotation (règle 6)
TourCourses
    id: int
    cagnotte: Cagnotte
    licencie: Licencie
    dateMatch: \DateTimeImmutable
    libelleMatch: ?string
    effectue: bool = false
```

```php
// src/Enum/CagnotteMouvementType.php
enum CagnotteMouvementType: string {
    case CONTRIBUTION = 'contribution';
    case DEPENSE      = 'depense';
    case REPORT       = 'report';      // règle 8 : solde reporté d'une saison, saisi explicitement
}
```

**Pourquoi `append-only`** : c'est le même choix que `CleMouvement` et `AttestationCle`. Une cagnotte est de l'argent qui n'appartient pas au club et que plusieurs personnes alimentent — la première contestation portera sur un mouvement modifié après coup. Une correction s'écrit donc comme un mouvement inverse, motivé, jamais comme une réécriture.

## 6. Services & écrans

| Classe | Rôle |
|---|---|
| `Service/Cagnotte/CagnotteService` | création, enregistrement des mouvements, ouverture/clôture |
| `Service/Cagnotte/CagnotteSoldeResolver` | règle 3 — solde, contributions manquantes, franchissement du seuil |
| `Service/Cagnotte/TourCoursesService` | désignation et suivi de la rotation |
| `Controller/Admin/CagnotteController` | `/admin/effectif/cagnottes` — liste par équipe, fiche, mouvements |

Une fiche de cagnotte affiche trois choses et rien d'autre : le solde, qui n'a pas encore cotisé, et à qui c'est le tour. Tout le reste est de l'historique.

## 7. Points de jonction avec l'existant

- **`Team` et `Licencie`** portent l'équipe et les joueurs. La cagnotte n'introduit aucune notion de membre : les contributeurs sont les licenciés de l'équipe.
- **`Season`** cloisonne, comme partout.
- **Epic 02 (Finance)** : **aucune jonction, volontairement** (règle 1). Si un jour le club veut mesurer ce que l'autofinancement lui a évité de dépenser, ce sera un affichage comparatif, jamais une ligne de budget.
- **Epic 03 (Événementiel)** : un pot de fin de saison peut exister des deux côtés — comme événement autofinancé (le club ne paie que les consommables) et comme cagnotte (les joueurs financent le reste). Les deux ne se contredisent pas : l'Epic 03 porte ce que le club dépense, l'Epic 08 ce que les joueurs mettent. **Ne pas les relier** — c'est justement la frontière qui rend l'autofinancement lisible.

## 8. Lots livrables

1. **Cagnotte + mouvements + solde** — l'essentiel du besoin tient là.
2. **Contributions attendues et relance** — qui n'a pas encore cotisé.
3. **Rotation des courses.**
4. **Seuil d'alerte.**

## 9. Points à trancher avant de coder

- **Qui saisit ?** Un dirigeant depuis l'admin, ou les joueurs eux-mêmes via un lien public façon formulaire d'inscription ? Une saisie joueur suppose une authentification publique qui n'existe que pour les formulaires à UUID. **Recommandation** : admin seulement au départ ; une consultation publique en lecture par lien UUID est un bon lot 5 si le besoin se confirme.
- **Le club veut-il des cagnottes hors équipe** (une cagnotte « dirigeants », une cagnotte d'un déplacement ponctuel qui mélange deux catégories) ? Le modèle force `team` ; le rendre nullable coûte peu et éviterait une migration.
- **U16 est-elle réellement concernée ?** Les notes disent « séniors, potentiellement U16 ». Sans impact sur le modèle, mais à savoir avant de créer les cagnottes.
