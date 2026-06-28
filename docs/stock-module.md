# Module Stock & Dotations — Document fondateur

> Document de **spécifications / instructions** pour la refonte du module Stock de CoSync.
> Il pose les bases d'un gros module, pensé pour être **générique et configurable** : une fois l'app
> en production, les admins doivent pouvoir tout personnaliser (articles, dotations, fournisseurs…)
> sans redéploiement. L'implémentation se fera **plus tard, par phases**. Aucun code n'est figé ici.

---

## 1. Contexte & problème

Le module stock actuel est volontairement basique :
- `/admin/stock` affiche **toute la liste détaillée** des articles par catégorie + une modale de mouvement.
  Au premier abord on ne veut pas ce niveau de détail → **consultation et administration sont mélangées**.
- Une « dotation » n'existe pas en tant que concept : c'est juste un `StockMovement` (type `SORTIE`,
  source `DOTATION`) relié à un licencié/dirigeant. **Ad-hoc, rien de planifié.**
- **Aucune notion de besoin ni de commande.** Or le club fonctionne en **flux tendu / à la commande** :
  il n'y a pas forcément de stock. On commande les vêtements **au fur et à mesure** des inscriptions.

### Réalité métier à modéliser
Le vrai besoin n'est pas « combien j'ai en stock » mais **« qu'est-ce que je dois commander, maintenant »**.
Exemple concret donné par le club :
- 28/06 : 10 séniors s'inscrivent (paiement validé), chacun prend une **veste taille L**.
- L'app regarde le stock de veste L. S'il n'y en a pas → elle indique **« commander 10 vestes L »** (bon de commande).
- L'admin commande, et **enregistre la commande** (date) → elle passe **en attente de réception**.
- 29/06 : 5 vestes L sont reçues → le reste à commander n'est plus 15 mais **5**.
  → Les **commandes en attente comptent comme du stock à venir** dans le calcul.

La dotation se personnalise **jusqu'à l'individu** : deux joueurs d'une même équipe peuvent recevoir une
dotation différente, et une dotation peut proposer **plusieurs choix** (« veste **ou** sweat »).

---

## 2. Décisions actées (cadrage validé)

| Sujet | Décision |
|---|---|
| **Périmètre besoin→commande** | Uniquement **vêtements / équipement perso** (distribué individuellement). L'**épicerie & matériel collectif** restent en **stock simple** avec seuil d'alerte. |
| **Choix multiple de dotation** | C'est le **licencié** qui choisit, **dans son formulaire public** — mais l'étape n'apparaît **que si** l'admin a configuré une dotation à choix multiple (sinon pas d'étape). |
| **Taille du besoin** | **Auto** depuis la taille déclarée (dossier licencié / dirigeant), **ajustable** par l'admin au cas par cas. |
| **Déclenchement du besoin (licencié)** | Quand le **paiement est validé** (`VALIDATED`). On ne commande que pour des inscriptions sûres. |
| **Déclenchement du besoin (dirigeant)** | Quand le **dossier dirigeant est complet** (taille connue). Mêmes modèles que les licenciés. |
| **Fournisseurs** | **Entité Fournisseur configurable**. Un article a un fournisseur ; le « à commander » se **découpe en un bon par fournisseur**. |
| **Bon de commande** | **PDF formaté** (DomPDF), **quantités seules** (document destiné au fournisseur). |
| **Coûts** | **Pas sur le PDF**, mais le **coût de la commande est enregistré en base** pour le futur **module Finance** (les coûts comptent). |
| **Réception** | **Partielle** possible dès le début (ex : 5 reçues sur 10, reste 5 en attente). |
| **Report inter-saison** | Le **surplus se reporte** → **catalogue d'articles partagé, stock continu** (les articles ne sont **pas** recréés par saison). Seuls **dotations & besoins** sont par saison. |
| **Alertes stock bas** | **Sur le tableau de bord** uniquement (pas d'email). |
| **Ordre d'implémentation** | 1) Séparer les pages → 2) Dotations → 3) Commandes. |

---

## 3. Principes directeurs

1. **Configurabilité avant tout.** Aucune règle propre à Soudron en dur. Tout le métier club
   (modèles de dotation, articles, catégories, fournisseurs, seuils, tailles, choix multiples) se paramètre
   depuis l'admin, y compris en production.
2. **Séparer synthèse et détail.** `/stock` devient un **tableau de bord d'aide à la décision**. Le **détail**
   (liste articles, mouvements, config) va dans des pages dédiées.
3. **Le besoin pilote la commande, pas le stock.** Source de vérité = les personnes (licenciés + dirigeants)
   et la dotation qui leur est affectée. Stock et commandes ne font que **couvrir** ce besoin.
4. **Stock continu, dotations par saison.** Le catalogue/stock physique vit dans le temps ; ce qui change
   chaque saison, ce sont les modèles de dotation et les besoins.
5. **Réutiliser l'existant** plutôt que recréer.

---

## 4. Vocabulaire (concepts clés)

| Concept | Définition | Existant ? |
|---|---|---|
| **Article** (`StockItem`) | Un produit : nom, marque, taille/contenance, couleur, type vêtement, prix, seuil, **fournisseur** | ✅ (à faire évoluer) |
| **Mouvement** (`StockMovement`) | Entrée / Sortie / Rebut, avec source (manuel, dotation, **commande**…) | ✅ existe |
| **Stock réel** | `Σ entrées − Σ (sorties + rebuts)` pour un article (continu, non lié à une saison) | ✅ `getCurrentStock()` |
| **Fournisseur** | Liste configurable, reliée aux articles et aux commandes | ❌ à créer |
| **Modèle de dotation** | Liste configurable de ce qu'une personne **doit recevoir** (1..n lignes), avec choix possibles | ❌ à créer |
| **Affectation de dotation** | Quel modèle s'applique à qui (équipe / catégorie / individu) | ❌ à créer |
| **Besoin** | Pour une personne : article (ou type+taille) attendu, statut *à donner / donné* | ❌ à créer |
| **Commande** | Regroupe des besoins non couverts **par fournisseur**. Cycle : *brouillon → commandée → reçue (partielle/totale)* | ❌ à créer |
| **Stock à venir** | Quantités **commandées mais pas encore reçues** | ❌ à créer |

---

## 5. Le cœur du module : l'équation « à commander »

Pour un couple **(article, taille)** donné, à un instant T :

```
À commander = max(0,  Σ besoins non encore servis
                      − stock réel disponible
                      − quantités commandées non encore reçues)
```

- **Besoins non servis** : licenciés (VALIDATED) / dirigeants (dossier complet) à qui la dotation est due
  mais pas encore remise.
- **Stock réel** : mouvements existants (continu).
- **Commandes en attente** : lignes de commande au statut *commandée*, non encore reçues (quantité restante).

Ce calcul, agrégé par (article, taille) **puis regroupé par fournisseur**, produit les **bons de commande**.
Il garantit qu'on ne recommande jamais ce qui est déjà en stock **ou déjà en route**.

---

## 6. Modèle de données cible (proposition)

> Réutilise `StockMovement`, `Team`, `DossierClub`, `Dirigeant`, `Category` (FFF), `Season`.

### Évolutions de l'existant
- **`StockItem`** : **retirer le rattachement à `season`** (catalogue partagé, stock continu).
  Ajouter `fournisseur` (ManyToOne `Fournisseur`, nullable). Conserver `kind` (équipement/épicerie),
  `typeVetement`, `prixAchat`, `alertSeuil`, etc.
  → *Migration* : décrocher `stock_item.season_id` ; prévoir la reprise des données existantes.
- **`StockMovementSource`** : ajouter la valeur **`COMMANDE`** (réception d'une commande).

### Nouveau — Fournisseur
- **`Fournisseur`** : `id`, `nom`, `actif`, (optionnel : `contact`, `email`). Configurable par l'admin.

### Nouveau — Modèles de dotation (par saison)
- **`DotationModele`** : `id`, `season`, `nom` (« Dotation sénior 2025 »…), `actif`.
- **`DotationModeleLigne`** : `modele`, **soit** `stockItem` (article précis) **soit** `typeVetement`
  (HAUT/BAS/CHAUSSURES → taille déduite du dossier), `quantite`, `obligatoire|optionnel`,
  **`groupeChoix`** (les lignes d'un même groupe = « 1 parmi N », proposées au licencié).

### Nouveau — Affectation (qui reçoit quoi)
- **`DotationAffectation`** : lie un `DotationModele` à une cible : `team` **ou** `category` **ou** rien (défaut saison).
  Résolution par priorité : **individu > équipe > catégorie > défaut**.

### Nouveau — Besoin (résolu par personne)
- **`DotationBesoin`** : `licencie?` / `dirigeant?`, `stockItem` (ou `typeVetement`), **`taille`**
  (auto depuis le dossier, **surchargeable**), `statut` (*à donner* / *donné*), `mouvementSortie?`,
  `choixRetenu?` (la variante choisie quand la ligne fait partie d'un `groupeChoix`).
  → Cette table matérialise le **besoin** réel **et** trace **qui a reçu**.
  → Générée à `VALIDATED` (licencié) / dossier complet (dirigeant), à partir du modèle résolu + tailles.

### Nouveau — Commandes
- **`Commande`** : `season`, `fournisseur`, `createdAt`, `dateCommande?` (null tant que brouillon),
  `statut` (brouillon / commandée / reçue partiellement / reçue), `note?`,
  **`coutTotal?`** (pour le module Finance).
- **`CommandeLigne`** : `commande`, `stockItem` (+ `taille`), `quantite`, `quantiteRecue`,
  **`prixUnitaire?`** (snapshot pour Finance).
- **Réception** (partielle ou totale) d'une ligne → met à jour `quantiteRecue`/`statut` + génère un
  `StockMovement` `ENTREE` (source `COMMANDE`).

### Stockage du choix de dotation (formulaire public)
Quand le modèle résolu d'un licencié contient un `groupeChoix`, le **formulaire public** ajoute une étape
de choix (conditionnelle). Le choix est **stocké au moment du formulaire** (avant paiement) — p. ex. sur le
`DossierClub` — puis **appliqué** lors de la matérialisation du besoin à `VALIDATED`.

---

## 7. Restructuration des pages

| Route | Rôle | Contenu |
|---|---|---|
| `/admin/stock` | **Tableau de bord** (synthèse, 1er abord) | Tuiles (alertes stock bas, besoins non couverts, **à commander**, commandes en attente), **bons de commande par fournisseur**, progression des dotations par équipe, commandes en cours. **Pas** la liste détaillée. |
| `/admin/stock/gestion` | **Gestion détaillée** | Liste des articles + mouvements + CRUD articles + catégories stock + **fournisseurs** (ce qui est sur `/stock` aujourd'hui). |
| `/admin/stock/dotations` | **Dotations** | Configurer les modèles (lignes, choix), les affecter (équipe/catégorie/individu), suivre **qui a reçu**. |
| `/admin/stock/commandes` | **Commandes** | Générer les bons de commande (PDF, par fournisseur), marquer *commandée* (date), enregistrer les **réceptions partielles**. |

- **Navbar** : ajouter « Stock » dans la section *Gestion* (`templates/components/navbar.html.twig`), absente aujourd'hui.
- Réutiliser : `components/hub-card.html.twig`, badges `.stock-badge-*`, modale de mouvement,
  conventions CSS (préfixe par page, tirets simples).

---

## 8. Cycle de vie : dotation → commande → distribution

```
1. Licencié VALIDATED (ou dirigeant à dossier complet)
        │  + dotation affectée (modèle résolu) + taille (auto, ajustable) + choix éventuel
        ▼
2. BESOIN matérialisé (DotationBesoin « à donner »)
        ▼
3. Tableau de bord agrège les besoins non servis par (article, taille)
        │  − stock réel − commandes en attente,  puis regroupe par fournisseur
        ▼
4. « À COMMANDER » → l'admin génère un bon de commande PDF (par fournisseur, quantités seules)
        ▼
5. Commande passée : statut « commandée » + date  (compte comme stock à venir ; coût enregistré → Finance)
        ▼
6. Réception partielle/totale → mouvements ENTREE (source COMMANDE) → stock mis à jour
        ▼
7. Distribution : remise au licencié → mouvement SORTIE (source DOTATION) → besoin « donné »
```

---

## 9. Configurabilité (penser au-delà de Soudron)

Éditable par un admin, sans redéploiement :
- Articles, catégories de stock, **fournisseurs**, seuils d'alerte, tailles disponibles.
- Modèles de dotation (création/édition/désactivation), lignes **obligatoires/optionnelles** et
  **groupes de choix** (« 1 veste **ou** 1 sweat »).
- Affectation par équipe / catégorie / individu.
- Prix unitaires (snapshot à la commande) pour le suivi budgétaire **Finance**.

Aucune valeur métier (catégories, dotations, fournisseurs) codée en dur.

---

## 10. Découpage en phases (roadmap)

- **Phase 1 — Restructuration des pages** *(priorité, faible risque)*
  Séparer `/stock` (dashboard synthèse + tuiles + alertes seuil) de `/stock/gestion` (CRUD + mouvements
  actuels). Ajouter l'entrée navbar. *Réutilise* `StockService::getStockSummary()`, `getCurrentStock()`.
  Inclure la gestion des **fournisseurs** (entité + CRUD) et le décrochage de `StockItem.season`.

- **Phase 2 — Dotations configurables + besoin + suivi « qui a reçu »**
  Entités `DotationModele`, `DotationModeleLigne`, `DotationAffectation`, `DotationBesoin`.
  Résolution du modèle par personne, génération du besoin à `VALIDATED` / dossier dirigeant complet,
  taille auto (ajustable), statut donné/à donner. **Étape conditionnelle de choix** dans le formulaire public.

- **Phase 3 — Commandes & bon de commande**
  Calcul « à commander » = besoins − stock − commandes en attente, agrégé par (article, taille) puis fournisseur.
  Entités `Commande`/`CommandeLigne`, statuts, **réception partielle** → mouvements `ENTREE` (source `COMMANDE`).
  **Bon de commande PDF** par fournisseur (DomPDF), quantités seules. Enregistrement du **coût** (→ Finance).
  *Réutilise* `StockItemVetementType::dossierField()`, tailles `DossierClub`/`Dirigeant`.

- **Phase 4 — Compléments**
  Liaison **module Finance** (coûts des commandes), finalisation de l'auto-dotation par taille
  (`templates/admin/stock/dotations/auto.html.twig`, route d'application manquante), exports/historique.

---

## 11. Réutilisation de l'existant (références)

- `src/Service/Stock/StockService.php` — `getStockSummary()`, `recordMovement()`, `getCurrentStock()`.
- `src/Repository/StockMovementRepository.php` — `getCurrentStock()`, `findDotationsByLicencie()`,
  `findDotationsByDirigeant()`, `findWithFilters()`.
- `src/Repository/StockItemRepository.php` — `findVetementsBySeason()`, `findBySeason()`.
- `src/Enum/StockItemVetementType.php` — `dossierField()` (type → champ taille).
- `src/Entity/{StockItem,StockMovement,StockCategory,Team,DossierClub,Dirigeant,Category}.php`.
- UI : `templates/components/hub-card.html.twig`, badges/modale de `templates/admin/stock/dashboard.html.twig`,
  conventions CSS `assets/styles/pages/stock-*.css`, PDF DomPDF (cf. attestations transport).

---

## 12. À préciser au moment d'implémenter (détails ouverts)

1. **Stockage exact du choix de dotation** côté formulaire public (champ dédié sur `DossierClub` vs petite table).
2. **Reprise de données** lors du décrochage de `StockItem.season` (catalogue partagé) : fusion des doublons
   d'articles existants entre saisons.
3. **Champs Fournisseur** (contact/email) — utiles pour Finance, à confirmer.
4. **Granularité Finance** : ce que le module Finance attend exactement (coût par commande, par fournisseur,
   par saison) — à caler quand Finance sera spécifié.
5. **Épicerie / matériel collectif** : confirmer que le réapprovisionnement reste purement basé sur `alertSeuil`
   (pas de besoin/commande piloté).

---

## Vérification (à l'implémentation, plus tard)
Document de spec uniquement — pas de test à ce stade. À chaque phase : `lint:twig`, `lint:container`,
migrations, et parcours end-to-end (créer fournisseur + modèle, affecter, valider un licencié, vérifier le
besoin, générer le bon de commande PDF par fournisseur, réceptionner partiellement, distribuer, et vérifier
le recalcul du « à commander »).
