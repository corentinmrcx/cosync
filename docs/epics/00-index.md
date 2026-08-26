# Epics CoSync V2 — index

Ces fichiers sont des **spécifications prêtes à développer**. Chacun est autonome : on en ouvre un, on lance une session, on construit la feature. Aucun ne suppose d'avoir lu les autres — les dépendances réelles sont listées ci-dessous et rappelées en tête de chaque fichier.

Ils sont dérivés des documents de `prepa_epic/`, qui sont le compte-rendu de la préparation **manuelle** de la saison 2026-2027. C'est ce qui fait leur valeur : chaque règle vient d'un problème réellement rencontré, et la plupart portent l'erreur qu'elles évitent. **Ne pas les résumer en les développant** — le « pourquoi » est ce qui empêche de refaire l'erreur.

---

## Les onze fichiers

| # | Epic | Poids | Dépend de | Statut de l'existant |
|---|---|---|---|---|
| [01](01-referentiel-tarifaire-officiel.md) | Référentiel tarifaire officiel (District / Ligue / FFF) | S | — | rien n'existe |
| [02](02-finance-budget.md) | Finance & Budget prévisionnel | **XL** | 01 | rien n'existe |
| [03](03-evenementiel.md) | Événementiel | M | — | rien n'existe |
| [04](04-ententes.md) | Ententes sportives | M | — | rien n'existe |
| [05](05-organigramme-roles.md) | Organigramme et rôles des dirigeants | M | 04 (facultatif) | ⚠️ touche `Dirigeant.role` |
| [06](06-formations-cfi.md) | Formations obligatoires (CFI) | **S** | 01, 05 (facultatifs) | rien n'existe |
| [07](07-boutique.md) | Boutique du club | L | — | lien externe seulement |
| [08](08-cagnotte-equipe.md) | Cagnotte collective par équipe | S | — | rien n'existe |
| [09](09-actions-reunion.md) | Suivi des décisions de réunion | **XS** | — | rien n'existe |
| [10](10-delta-dotation-budget.md) | *Delta* — Dotation : le volet budgétaire | M | — | ✅ fonctionnel déjà livré |
| [11](11-delta-blocage-licence.md) | *Delta* — Bloquer la validation sans signature | **S** | — | ✅ **traitée** — voir ci-dessous |

---

## Ordre de construction recommandé

**La 11 est traitée** (cf. « Ce qui a été traité » ci-dessous). **Commencer par 09 et 06** : ce sont les deux plus petites restantes, et chacune est autonome.

Ensuite **01 puis 02**, dans cet ordre : le référentiel tarifaire est le socle du budget, et il se remplit en une soirée. Attaquer Finance sans lui revient à ressaisir les mêmes montants dans deux endroits.

**04 avant la partie « recettes » de 02** : c'est l'entente qui dit combien de joueurs sont réellement licenciés à Soudron, et c'est l'ignorance de ce chiffre qui a faussé le prévisionnel 26-27.

**03, 07, 08, 10** sont indépendantes et se prennent quand le besoin se présente.

**05 en dernier des chantiers structurants** : c'est la seule qui touche un champ (`Dirigeant.role`) dont dépendent la dotation et les documents signés. Elle mérite d'être faite quand le reste est stable.

```
11                  ✅ traitée

09   06             (petites, autonomes, tout de suite)

01 ──┐
     ├── 02         (le socle et l'effectif réel, puis le budget)
04 ──┘

03   07   08   10   (indépendantes, au fil du besoin)

05                  (en dernier : touche un existant très branché)
```

---

## Ce qui a été traité

### 11 — Delta blocage de licence ✅ *(août 2026)*

**Le verrou de validation a été écarté, sa prémisse ne tenait pas.** Le §3 de l'epic décrit une
fiche créée à la main dont l'admin confirmerait le paiement sans aucune signature. Vérification
faite dans le code : le bouton « Gérer les paiements » n'apparaît que si `DossierClub.formCompletedAt`
est renseigné, et « Valider quand même » vit **dans** cette modale. Une fiche qui n'a jamais été
remplie ne peut donc pas recevoir de paiement par l'interface, et le parcours public exige déjà
toutes les signatures avant de soumettre. Le scénario n'est pas atteignable.

Sont écartés en conséquence — et ne doivent pas être repris sans nouvelle raison :

| Lot de l'epic | Décision |
|---|---|
| 1 — Écran de contrôle en mode aperçu | Écarté : construit, puis supprimé. Il comptait les licences validées sans signature ; le décompte est nul par construction |
| 2 — Verrou dans `PaiementService` + `Season.signatureBloqueValidation` | Écarté : l'interface interdit déjà le scénario. **Aucune migration n'a été créée** |
| 3 — Revalidation automatique à la signature | Sans objet : sans verrou, aucun dossier ne se bloque |
| 4 — Forçage administratif motivé | Sans objet : rien à forcer |

**Ce qui a été livré à la place**, c'est le second trou que le §3 mentionnait — le seul réel : un
document ajouté **en cours de saison** n'est jamais signé par ceux dont le dossier était déjà
complet, leur lien étant consommé et leur formulaire ne repassant plus.

- `SignatureCompletionService` — les documents qu'il reste à signer, **une fois le dossier terminé
  seulement** : tant qu'il ne l'est pas, c'est le lien d'inscription qui s'impose, et deux liens
  vivants sur la même personne la feraient signer deux fois
- `SignatureRelanceService` — la règle d'éligibilité, tenue une seule fois pour les deux
  populations et dans les deux sens (ce que l'écran affiche, ce que l'envoi accepte)
- Parcours public `/inscription/{uuid}/signer` — lecture et signature, rien d'autre redemandé
  (ni tailles, ni autorisations, ni paiement) ; lien à usage unique
- Bouton **« Demander la signature »** sur la fiche joueur, et écrans groupés
  **« Demander les signatures »** sous Joueurs et sous Dirigeants — cases à cocher, sur le modèle
  d'« Envoyer les liens »

**Deux points d'ergonomie à ne pas défaire.** La relance vit dans l'**effectif**, avec la
population, et non dans « Documents à signer » qui sert à *préparer* les documents — mélanger les
deux avait produit le second défaut : le regroupement est **par personne**, jamais par document,
car les parcours publics présentent tous les documents manquants d'un coup et une relance par
document enverrait deux mails à qui en doit deux.

**Si un jour un chemin de validation contourne le formulaire** — import de paiements, API, saisie
en masse — la question du verrou se reposera. Elle ne se pose pas avant.

---

## Ce qui est déjà livré et ne doit pas être reconstruit

Deux des huit documents de `prepa_epic/` décrivent des besoins que CoSync **couvre déjà**. Ils sont devenus des deltas courts (10 et 11) au lieu d'epics complètes.

**Dotation** — les six besoins listés au §3.3 du document source existent tous en production : le choix par licencié avec la règle « nouveau → veste imposée / renouvellement → choix libre » (`DotationEligibilite`), la personnalisation, les paliers dirigeants par rôle sans cumul (`DotationCibleType::ROLE` + `DotationAffectation::priorite()`), la configuration par saison, la liste de commande fournisseur, l'historique des choix. Le module va au-delà : grilles de tailles fournisseur, écoulement de l'ancien stock, corrections tracées. **Seul le chiffrage manque.**

**Règlement intérieur** — la signature électronique multi-documents, ciblée par population, avec PDF horodaté archivé sur Drive et suivi de qui a signé, est en place (`DocumentSignable`, `DocumentCible`, `DocumentRequirementResolver`). Le rattrapage d'un document ajouté en cours de saison l'a complétée depuis (cf. « Ce qui a été traité »). **Le verrou de validation, lui, a été écarté — sa prémisse ne tenait pas.**

Avant d'ouvrir une session sur une epic, lire son §2 : il dit ce qui existe déjà.

---

## Trois points de jonction à connaître avant de commencer

**La cotisation n'est pas modélisée par catégorie.** `CotisationResolver` résout par équipe puis par défaut de saison. Or Finance raisonne en tarif par catégorie (85 € jeunes / 120 € séniors) et la marge par catégorie est son livrable central. Trois options sont pesées dans [02 §7.1](02-finance-budget.md) — **c'est la décision la plus structurante du lot**, à prendre avant le lot 2 de Finance.

**`Dirigeant.role` est mono-valué et sert à cibler.** L'organigramme a besoin du cumul de rôles, mais ce champ décide de la dotation et des documents attendus. [05 §7](05-organigramme-roles.md) recommande la cohabitation plutôt que la migration — ne pas le supprimer.

**La formule de prix fournisseur est commune à la dotation et à la boutique.** `(catalogue × 0,65) + flocage forfaitaire`, même accord Intersport, même marque Erima. Un seul service doit la porter, quelle que soit l'epic livrée en premier ([07 §7.2](07-boutique.md) et [10 §7](10-delta-dotation-budget.md)).

---

## Ce que ces epics ne remettent pas en cause

Les principes du [CLAUDE.md](../../CLAUDE.md) s'appliquent intégralement : zéro logique métier dans les contrôleurs, typage strict, enums plutôt que magic strings, un suffixe de rôle sur chaque service, un dossier par domaine dans `src/Service/`, CSS préfixé par page.

Et surtout le **§13** : la base contient des signatures manuscrites, des PDF signés et des encaissements réels. Chaque migration proposée ici suit expand / backfill / contract, et deux epics portent un avertissement explicite sur le risque en production — [05 §7](05-organigramme-roles.md) et [11 §7](11-delta-blocage-licence.md).

---

## Les données réelles 2026-2027

Chaque epic finit par un §10 avec les données réelles du club, utilisables comme jeu de test et comme critère d'acceptation. Le socle commun :

| | |
|---|---|
| **Effectifs** | Sénior 19 · U16 14 · U13 5 · U11 10 · U9/U7 **0** · Dirigeants 15 |
| **Cotisations** | 85 € jeunes · 120 € séniors |
| **Prévisionnel** | recettes 14 392 € · dépenses 11 974 € · **résultat +2 418 €** |
| **Ententes** | U16 avec Conantre (Soudron directeur) · U13 avec Vertus (Vertus directeur) |
| **Catégories** | U15 n'existe plus, fusionnée dans U16 |
| **Fournisseur** | Intersport Clubs et Collectivités (facture et livre) · marque **Erima** · remise **35 %** |
