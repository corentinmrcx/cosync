# Epic 09 — Suivi des décisions et actions de réunion

> **Statut** : à construire — issu des notes de préparation, sans fichier de contexte dédié
> **Source** : notes vrac (« des décisions sont prises mais se perdent faute de suivi structuré — seulement des PDF de CR dispersés »)
> **Dépend de** : rien
> **La plus petite epic du lot avec la 06. Volontairement minimale — voir §3.**

---

## 1. Pourquoi cette epic existe

Le constat est récurrent dans les comptes-rendus du club : des décisions sont prises en réunion — panneaux publicitaires, boutique du club — **et se perdent**. Non pas parce qu'elles sont mauvaises, mais parce que le seul support est un PDF de compte-rendu, rangé quelque part, que personne ne rouvre entre deux réunions.

Le décalage est facile à mesurer : la boutique du club a été présentée en réunion le **27/09/2025** comme un axe de recettes annexes. Un an plus tard, elle n'est toujours pas lancée — et ce n'est pas un renoncement, c'est un dossier qui n'a jamais eu de porteur ni d'échéance visible.

Ce que le club demande est explicitement modeste : *« pas besoin d'un gros module : une liste simple avec rappel suffit »*.

## 2. Ce qui existe déjà dans CoSync

Rien. Les réunions et leurs comptes-rendus vivent hors de l'outil, en PDF.

## 3. Périmètre

**Dans le périmètre**

- Une liste d'actions : titre, responsable, échéance, statut, réunion d'origine.
- Un rappel des actions en retard ou proches de leur échéance.

**Hors périmètre — et à tenir**

- **Pas de gestion de réunions**, pas d'ordre du jour, pas de rédaction de compte-rendu, pas de convocation, pas de vote, pas de quorum. Le club n'a rien demandé de tout ça, et un module de réunions complet mourrait de son propre poids : personne ne rédige un compte-rendu dans un outil interne quand un traitement de texte fait l'affaire.
- Pas de projet, pas de sous-tâches, pas de dépendances entre actions. Ce n'est pas un gestionnaire de tâches.
- Pas de notification poussée vers les responsables au lancement. Le rappel est un écran ; l'envoi de mail est un lot ultérieur, et il obéira à la même discipline que le reste de l'outil (rien ne part sans décision — cf. §6A du CLAUDE.md).

**La tentation de cette epic est de grossir. Si elle dépasse deux écrans, c'est qu'elle a dérivé.**

## 4. Règles métier

1. **Une action a toujours un responsable nommé.** Une action sans responsable est le défaut qu'on corrige : c'est exactement ce qui est arrivé à la boutique. Le champ n'est pas optionnel.

2. **Une action a toujours une échéance**, même approximative. « Avant la reprise », « fin janvier » valent mieux que rien. Sans échéance, aucun rappel n'est possible et l'action retombe dans le PDF.

3. **La réunion d'origine se note, mais ne conditionne rien.** Une action peut naître hors réunion (une décision prise à deux au bord du terrain). Le rattachement à une réunion est une information de traçabilité, pas une contrainte de création.

4. **Quatre statuts suffisent** : à faire, en cours, faite, abandonnée. « Abandonnée » n'est pas un échec — c'est une décision, et elle vaut mieux qu'une action qui traîne indéfiniment en « à faire ». Une action abandonnée porte son motif.

5. **Une action n'appartient pas à une saison.** La boutique est un dossier qui a traversé deux saisons. Les cloisonner par saison ferait disparaître au 1ᵉʳ juillet exactement les dossiers de fond qui traînent — ceux que ce module existe pour rattraper. Même raisonnement que pour `Detenteur` et `CleMouvement`, qui vivent hors saison.

6. **Le rappel se voit, il ne se subit pas.** Un compteur d'actions en retard sur le tableau de bord admin suffit à ramener la liste dans le champ de vision. C'est le seul mécanisme demandé.

## 5. Modèle de données proposé

```php
// src/Entity/ActionSuivi.php — hors saison (règle 5)
ActionSuivi
    id: int
    titre: string
    description: ?string
    responsable: ?Dirigeant          // règle 1 — nullable en base, requis par le formulaire
    responsableLibre: ?string        // quand le porteur n'est pas un dirigeant enregistré
    echeance: ?\DateTimeImmutable
    echeanceLibelle: ?string         // règle 2 : "avant la reprise", quand la date est floue
    statut: StatutAction
    motifAbandon: ?string            // règle 4
    reunionDate: ?\DateTimeImmutable // règle 3
    reunionLibelle: ?string          // "CA du 27/09/2025"
    createdBy: User
    createdAt: \DateTimeImmutable
    closedAt: ?\DateTimeImmutable
```

```php
// src/Enum/StatutAction.php
enum StatutAction: string {
    case A_FAIRE   = 'a_faire';
    case EN_COURS  = 'en_cours';
    case FAITE     = 'faite';
    case ABANDONNEE = 'abandonnee';
}
```

**Pourquoi `responsable` est nullable en base alors que la règle 1 l'exige** : `Dirigeant` est cloisonné par saison, et une action traverse les saisons (règle 5). Un porteur qui n'est plus dirigeant la saison suivante ne doit pas faire disparaître l'action — d'où le doublon `responsable` / `responsableLibre`, sur le même principe que `Detenteur` qui reste visible en « hors effectif » quand la personne quitte le club. Le formulaire, lui, exige l'un ou l'autre.

## 6. Services & écrans

| Classe | Rôle |
|---|---|
| `Service/Ops/ActionSuiviService` | création, changement de statut, clôture avec motif |
| `Service/Ops/ActionSuiviCollector` | les listes utiles : en retard, échéance proche, par responsable |
| `Controller/Admin/ActionSuiviController` | `/admin/actions` — liste filtrable, création, édition inline |

Le dossier `Service/Ops/` (exploitation) est le bon endroit : ce n'est ni un domaine métier du foot, ni une brique technique.

**Deux écrans, pas trois** : la liste (avec les actions en retard en tête) et le formulaire. Le compteur du tableau de bord n'est pas un écran, c'est une tuile sur `/admin/`.

## 7. Points de jonction avec l'existant

- **`Dirigeant`** fournit les responsables candidats — de la saison courante, mais l'action leur survit (§5).
- **`Controller/Admin/DashboardController`** accueille la tuile « actions en retard » (règle 6).
- **Aucune jonction avec les autres epics.** C'est voulu : ce module observe le club, il ne participe à aucun calcul.

## 8. Lots livrables

1. **Liste + création + statuts + tuile de retard** — c'est tout le besoin, en un lot. Une demi-journée.
2. **Relance par mail du responsable** — seulement si le club le demande après usage, et jamais en envoi automatique.

## 9. Points à trancher avant de coder

- **Qui voit les actions ?** Tous les admins, ou seulement les responsables foot ? Une action « relancer la mairie pour le terrain » n'a pas vocation à être lue par tout le monde. **Recommandation** : tous les admins au départ, l'outil n'a que quelques utilisateurs et une gestion de visibilité coûterait plus qu'elle ne rapporte.
- **Faut-il attacher le PDF du compte-rendu ?** Tentant, mais ça fait entrer un stockage de documents dans une epic qui doit rester minimale. Un lien vers le Drive du club suffit — et le Drive est déjà en place.

## 10. Données réelles pour tester

| Action | Origine | Échéance | Statut |
|---|---|---|---|
| Lancer la boutique du club | CA du 27/09/2025 | — jamais fixée | En cours depuis un an |
| Panneaux publicitaires au stade | Réunion CA | — | À faire |
| Commander l'équipement au fur et à mesure des besoins réels | Réunion du 27/09/2025 | Permanent | Faite |
| Rédiger et faire signer les conventions d'entente | — | Avant la reprise | À faire |

Les deux premières lignes sont les cas réels cités dans les notes : **des décisions prises qui se sont perdues faute de suivi.** Elles sont le test d'acceptation de l'epic.
