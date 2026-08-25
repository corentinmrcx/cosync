# Epic 07 — Boutique du club

> **Statut** : besoin exprimé, **jamais lancé côté club** — un lien externe existe déjà dans CoSync
> **Source** : `prepa_epic/epic_cosync_boutique_club.md`
> **Dépend de** : rien pour le lot 1 ; partage la formule de prix de l'Epic 10
> **Alimente** : Epic 02 (recettes de vente et marges)
> **⚠️ Prérequis non technique : la boutique HelloAsso doit exister administrativement (JO/SIRENE, statuts, RIB, pièce d'identité du mandataire). Aucun développement ne remplace cette démarche.**

---

## 1. Pourquoi cette epic existe

Des joueurs et des parents demandent régulièrement où acheter des vêtements aux couleurs du club. Le club **n'a aucun canal officiel** à leur proposer.

Il a pourtant l'avantage commercial : **35 % de remise** chez Intersport Clubs et Collectivités (marque Erima), sans frais de port — le même accord que celui qui sert aux dotations. Ce n'est pas une opération commerciale : *l'objectif n'est pas de maximiser le profit*, les articles doivent rester accessibles avec une marge raisonnable. Mais chaque vente est un petit bénéfice net, dans un club où les licences ne couvrent pas les coûts.

Le travail de préparation existe déjà — et c'est exactement là qu'est le problème : il vit dans **un fichier Excel personnel, accessible à une seule personne**, relié à aucun système de commande ni de paiement, sans aucun suivi de qui a commandé quoi ni de qui a payé. Chaque évolution tarifaire du fournisseur oblige à recalculer toutes les lignes à la main.

## 2. Ce qui existe déjà dans CoSync

**Attention à ne pas confondre deux choses qui portent le même nom.**

Ce qui existe s'appelle « boutique » mais n'est **qu'un lien externe** :

- `ClubSettings.boutiqueUrl` — l'URL de la page HelloAsso du club, réglée dans `/admin/boutique/lien` ;
- `ClubSettings.boutiqueOuverte` — un booléen distinct du lien, parce que le club prépare le lien à froid et n'ouvre que plus tard ;
- `getBoutiqueUrlPublique()` — ne rend le lien qu'une fois la boutique ouverte ; c'est par là que passent tous les écrans publics et les mails ;
- `/admin/boutique/annoncer` — l'envoi groupé de l'annonce, une seule fois par licencié (`Licencie.boutiqueAnnonceeAt`).

Ce mécanisme reste utile et **n'est pas à défaire** : il gèrera l'annonce de la boutique quelle qu'en soit la forme.

Ce qui existe aussi, et qu'il ne faut **pas** réutiliser tel quel : le module **Stock** (`StockItem`, `StockMovement`, `Commande`, `Fournisseur`, `GrilleTaille`). Voir §7.1 — c'est la décision de conception principale de cette epic.

## 3. Périmètre

**Dans le périmètre**

- Un catalogue d'articles avec tarif fournisseur, remise, coût de personnalisation, prix de vente, et **marge et taux de marque calculés**.
- La mise à jour des tarifs par un dirigeant, sans intervention technique.
- Une commande passée par une famille, payée via HelloAsso.
- Le regroupement des commandes avant envoi au fournisseur, et le suivi de statut jusqu'au retrait.

**Hors périmètre — explicitement**

- **Pas de gestion de stock physique en temps réel.** Le fonctionnement reste « commande à la demande », pas de réserve permanente. C'est la contrainte qui commande toute la conception (§7.1).
- **Pas de facturation comptable.** Le suivi financier détaillé est l'Epic 02 ; la boutique produit une trace des ventes et des marges, rien de plus.
- Aucune manipulation d'argent physique : tout passe par HelloAsso.

## 4. Règles métier

### 4.1 Le prix d'achat

1. **Prix d'achat = tarif catalogue × (1 − taux de remise).** Le taux standard est de **35 %** pour tout ce qui passe par Intersport / Erima.

2. **Le taux de remise est porté par l'article, pas par une constante.** Exception réelle : les gobelets viennent d'un autre fournisseur (« gobelet français »), **sans remise** — leur prix d'achat est le tarif catalogue. Une remise codée en dur à 0,65 rendrait ces lignes fausses.

### 4.2 Les catégories de prix

3. **Deux niveaux de prix seulement : Adulte et Enfant.** Dans **100 % des lignes observées**, la catégorie « Femme » n'a jamais un tarif différent de l'Adulte. À confirmer avec le club avant de le figer, mais c'est ce que montrent toutes les données actuelles.

4. **Certains articles n'ont aucune différenciation de prix** : sacs, gants, tours de cou, jambières, chaussettes, gobelets — un seul tarif quel que soit l'acheteur. Le modèle doit accepter un article sans tarif enfant distinct, sans le forcer à dupliquer le tarif adulte.

### 4.3 La personnalisation

5. **Le coût de personnalisation se déclare par article, pas comme une constante.** Le floquage standard est à **3,50 €**, mais le maillot de match (nom + numéro) est à **8 €**. Une constante unique produirait une marge fausse sur le maillot.

6. **Certains articles ne proposent aucune personnalisation** (short, pantalon d'entraînement, gant, tour de cou, jambière, chaussette, gobelet). Pour eux, coût total = prix d'achat.

7. **La broderie (5 €) est présente dans les données mais probablement pas proposée aux familles.** À trancher avant de l'afficher comme choix : le fichier de préparation la calcule sur tous les articles personnalisables, mais rien n'indique qu'elle soit activée en pratique.

### 4.4 La marge

8. **Coût total = prix d'achat + coût de personnalisation. Marge nette = prix de vente − coût total.**

9. **Le taux de marque se calcule sur le prix de vente** — marge nette ÷ prix de vente — et **pas** sur le coût d'achat. C'est la donnée que le club utilise pour juger de la rentabilité d'un article ; se tromper de dénominateur donnerait des pourcentages plus flatteurs et fausserait tous les arbitrages.

10. **La marge normale du club est de 15 % à 22 %** sur le textile et le sport. Les 47 % à 58 % constatés sur les gobelets sont un cas particulier hors accord fournisseur, **pas la norme à reproduire**. L'écran doit signaler visuellement un article qui sort de la fourchette, comme le fichier Excel le fait aujourd'hui par des couleurs.

### 4.5 Les commandes

11. **Les commandes sont regroupées avant d'être transmises au fournisseur.** Le club ne commande jamais à l'unité.

12. **Une commande n'est lancée en commande fournisseur que si elle est payée.** Vérification explicitement demandée : c'est ce qui évite d'avancer l'argent d'un article qui ne sera jamais retiré.

13. **Le statut suit un chemin en cinq temps** : passée → regroupée → envoyée au fournisseur → reçue par le club → retirée par la famille. La famille est prévenue à la réception.

### 4.6 Les tarifs

14. **Le retarifage est une contrainte permanente, pas un ajustement ponctuel.** Les prix connus datent de **fin 2025** et le fournisseur a déjà changé ses tarifs depuis. Le catalogue doit être modifiable par un dirigeant à tout moment, sans intervention technique — c'est une exigence de conception, pas un confort.

## 5. Modèle de données proposé

```php
// src/Entity/ArticleBoutique.php — le catalogue de vente (distinct de StockItem, cf. §7.1)
ArticleBoutique
    id: int
    nom: string
    categorieBoutique: ?CategorieBoutique   // vestes, sweats, t-shirts, sacs, accessoires…
    referenceAdulte: ?string
    referenceEnfant: ?string
    couleurs: json                    // liste de couleurs disponibles
    tailles: json                     // libellés issus du référentiel Taille (cf. §7.2)
    tarifCatalogueAdulte: float
    tarifCatalogueEnfant: ?float      // null = pas de tarif enfant distinct (règle 4)
    tauxRemise: float = 0.35          // règle 2 : porté par l'article
    personnalisation: TypePersonnalisation  // AUCUNE | FLOQUAGE | BRODERIE
    coutPersonnalisation: ?float      // règle 5 : 3,50 € ou 8 € selon l'article
    prixVenteAdulte: float
    prixVenteEnfant: ?float
    actif: bool = true
    stockItem: ?StockItem             // lien facultatif quand l'article existe aussi au stock
    // prixAchat(), coutTotal(), margeNette(), tauxDeMarque() — calculés (règles 1, 8, 9)

// src/Entity/CommandeBoutique.php
CommandeBoutique
    id: int
    season: Season
    licencie: ?Licencie               // null = commande d'une personne hors effectif
    nomClient: ?string                // quand pas de licencié rattaché
    email: string
    statut: StatutCommandeBoutique    // règle 13
    montantTotal: float
    helloassoCheckoutIntentId: ?string
    payeeLe: ?\DateTimeImmutable      // règle 12 : conditionne l'envoi au fournisseur
    regroupeeDans: ?CommandeGroupee
    recueLe: ?\DateTimeImmutable
    retireeLe: ?\DateTimeImmutable
    lignes: Collection<CommandeBoutiqueLigne>

// src/Entity/CommandeBoutiqueLigne.php
CommandeBoutiqueLigne
    id: int
    commande: CommandeBoutique
    article: ArticleBoutique
    taille: string
    couleur: ?string
    categorieTarif: CategorieTarif    // ADULTE | ENFANT
    personnalisationTexte: ?string    // initiales, nom
    quantite: int = 1
    prixUnitaire: float               // recopié à la commande, jamais relu (règle 14)

// src/Entity/CommandeGroupee.php — le regroupement avant envoi fournisseur (règle 11)
CommandeGroupee
    id: int
    season: Season
    fournisseur: ?Fournisseur
    envoyeeLe: ?\DateTimeImmutable
    recueLe: ?\DateTimeImmutable
```

```php
// src/Enum/TypePersonnalisation.php
enum TypePersonnalisation: string { case AUCUNE = 'aucune'; case FLOQUAGE = 'floquage'; case BRODERIE = 'broderie'; }

// src/Enum/CategorieTarif.php — règle 3 : deux niveaux, pas trois
enum CategorieTarif: string { case ADULTE = 'adulte'; case ENFANT = 'enfant'; }

// src/Enum/StatutCommandeBoutique.php — règle 13
enum StatutCommandeBoutique: string {
    case PASSEE = 'passee';
    case REGROUPEE = 'regroupee';
    case ENVOYEE_FOURNISSEUR = 'envoyee_fournisseur';
    case RECUE = 'recue';
    case RETIREE = 'retiree';
}
```

**Pourquoi `prixUnitaire` est recopié sur la ligne de commande** : le tarif fournisseur bouge (règle 14). Une commande passée à 38 € reste à 38 €, même si le catalogue passe à 41 € le lendemain. Relire le prix du catalogue ferait varier a posteriori le montant d'une commande déjà payée. C'est la même discipline que le libellé de taille recopié dans `stock_movement`.

## 6. Services & écrans

| Classe | Rôle |
|---|---|
| `Service/Boutique/PrixArticleResolver` | règles 1, 5, 8, 9 — prix d'achat, coût total, marge, taux de marque |
| `Service/Boutique/CatalogueService` | écriture du catalogue, retarifage |
| `Service/Boutique/CommandeBoutiqueService` | passage de commande, transitions de statut, garde de la règle 12 |
| `Service/Boutique/RegroupementService` | constitution d'une commande groupée |
| `Controller/Admin/CatalogueController` | `/admin/boutique/catalogue` — tableau de rentabilité, alerte hors fourchette (règle 10) |
| `Controller/Admin/CommandeBoutiqueController` | `/admin/boutique/commandes` — suivi et regroupement |
| `Controller/Public/BoutiqueController` | `/boutique` — catalogue public, panier, redirection HelloAsso |

**Le paiement réutilise l'existant sans le dupliquer.** `HelloAssoPaymentRecorder` porte déjà la règle absolue du projet : *aucune transaction n'est enregistrée sur la foi d'une `returnUrl` ou d'une notification — l'état du paiement est relu auprès de l'API HelloAsso avant tout enregistrement, de façon idempotente*. La boutique passe par ce même chemin. Écrire un second recorder pour la boutique serait la faute la plus coûteuse de cette epic.

## 7. Points de jonction avec l'existant

### 7.1 Pourquoi un `ArticleBoutique` distinct de `StockItem`

C'est la décision de conception centrale, et elle mérite d'être argumentée parce que la tentation inverse est forte : les deux décrivent des vêtements Erima achetés chez Intersport avec 35 % de remise.

`StockItem` est entièrement construit **autour du stock physique** : mouvements d'entrée et de sortie, compteur par taille, notes par déclinaison, grilles de traduction fournisseur, besoins de dotation, article d'écoulement, kits, bons de commande, feuille d'inventaire. Or la boutique déclare explicitement **ne pas gérer de stock**.

Faire entrer les articles de vente dans `StockItem` produirait, immédiatement :

- des articles boutique dans les écrans de stock, avec un solde qui ne veut rien dire ;
- des articles boutique proposés à la composition d'un `DotationModele` ;
- des besoins de dotation possiblement servis par un article destiné à la vente ;
- une feuille d'inventaire qui demande de compter au local des articles qui n'y sont jamais.

Le lien `ArticleBoutique.stockItem` reste **facultatif**, pour le cas réel où un même article existe des deux côtés (le t-shirt vendu est le même que celui de la dotation). Il sert à afficher le rapprochement, jamais à fusionner les deux logiques.

### 7.2 Les autres jonctions

- **`Taille` / `TailleReferentiel`** : les tailles proposées à la boutique doivent venir du référentiel existant, avec son ordre d'affichage. Ne pas réinventer une liste de tailles — l'ordre du référentiel est celui de **tous** les sélecteurs, public compris.
- **`Fournisseur`** existe déjà et sert aux commandes de stock : le réutiliser tel quel pour les commandes groupées.
- **La formule de prix est commune avec l'Epic 10** : `(catalogue × 0,65) + flocage forfaitaire`. **Un seul service doit la porter.** Si l'Epic 10 est livrée avant, la boutique consomme son résolveur ; sinon l'inverse. Deux implémentations de la même formule divergeraient à la première évolution de remise.
- **`ClubSettings.boutiqueOuverte`** continue de commander l'ouverture publique et l'annonce groupée. Une boutique interne développée ici s'ouvre par le même interrupteur.
- **Epic 02 (Finance)** : les ventes alimentent une ligne de recette, les achats une ligne de dépense. Le résultat net de la boutique est un poste du budget, pas un module financier autonome.

## 8. Lots livrables

1. **Catalogue + calcul de marge, en admin seulement** — remplace le fichier Excel personnel et le rend partageable. **Livrable seul, et c'est le lot le plus utile** : il élimine le point de fragilité principal (une seule personne détient les données).
2. **Catalogue public en lecture** — les familles voient enfin quelque chose, la commande se fait encore hors ligne.
3. **Commande + paiement HelloAsso** — via le recorder existant.
4. **Regroupement et suivi de statut jusqu'au retrait.**

## 9. Points à trancher avant de coder

- **Retarifage obligatoire avant tout lancement.** Les montants du §10 datent de fin 2025 et sont périmés. Ils servent de jeu de test, **pas de catalogue de lancement**.
- **Broderie proposée ou non ?** (règle 7). Trancher avant d'afficher un choix aux familles.
- **Périmètre du catalogue de lancement** : le document de présentation aux dirigeants listait une dizaine d'articles, le fichier de calcul en couvre 18-20. Une sélection réduite au lancement est probablement plus sage.
- **La catégorie « Femme »** est-elle réellement toujours alignée sur l'adulte, ou est-ce une simplification du fichier de calcul ? (règle 3).
- **Une commande peut-elle venir d'une personne hors effectif ?** Un grand-parent, un ancien joueur. Le modèle le permet (`nomClient` sans `licencie`) ; à confirmer que le club le veut.

## 10. Catalogue de référence — données fin 2025, **périmées, pour test uniquement**

Prix d'achat = catalogue remisé de 35 % sauf mention. Coût total = PA + personnalisation. Taux de marque = marge ÷ PV.

**Adulte / Femme**

| Article | Personnalisation | PA | Coût total | PV | Marge | Taux de marque |
|---|---|---|---|---|---|---|
| Veste zippée | Floquage 3,50 € | 28,60 € | 32,10 € | 38 € | 5,90 € | 15,53 % |
| T-shirt | Floquage 3,50 € | 15,60 € | 19,10 € | 23 € | 3,90 € | 16,96 % |
| Veste de pluie | Floquage 3,50 € | 25,35 € | 28,85 € | 34 € | 5,15 € | 15,15 % |
| Pantalon d'entraînement | Aucune | 21,45 € | 21,45 € | 26 € | 4,55 € | 17,50 % |
| Sac à dos 25 L | Floquage 3,50 € | 21,45 € | 24,95 € | 30 € | 5,05 € | 16,83 % |
| T-shirt (réf. 208650) | Floquage 3,50 € | 13,65 € | 17,15 € | 21 € | 3,85 € | 18,33 % |
| Sac (réf. 7232303, M) | Floquage 3,50 € | 20,15 € | 23,65 € | 30 € | 6,35 € | 21,17 % |
| Gant | Aucune | 13,65 € | 13,65 € | 17 € | 3,35 € | 19,71 % |
| Tour de cou | Aucune | 9,75 € | 9,75 € | 12 € | 2,25 € | 18,75 % |
| Jambière | Aucune | 7,80 € | 7,80 € | 10 € | 2,20 € | 22,00 % |
| Chaussette | Aucune | 6,50 € | 6,50 € | 8 € | 1,50 € | 18,75 % |
| Short RIO | Aucune | 12,35 € | 12,35 € | 15 € | 2,65 € | 17,67 % |
| **Maillot match Kappa** | **Floquage complet 8 €** | 19,50 € | 27,50 € | 33 € | 5,50 € | 16,67 % |
| Veste soft | Floquage 3,50 € | 84,50 € | 88,00 € | 104 € | 16,00 € | 15,38 % |
| T-shirt Intro | Floquage 3,50 € | 11,70 € | 15,20 € | 18 € | 2,80 € | 15,56 % |
| Short Calcuta | Aucune | 10,40 € | 10,40 € | 13 € | 2,60 € | 20,00 % |
| **Gobelet ×25** (hors accord) | Aucune | 1,58 € | 1,58 € | 3 € | 1,42 € | **47,33 %** |
| **Gobelet ×50** (hors accord) | Aucune | 2,10 € | 2,10 € | 5 € | 2,90 € | **58,00 %** |

**Enfant** — seulement les articles où le prix diffère. Sacs, gants, tours de cou, jambières, chaussettes et gobelets **n'ont pas de tarif enfant distinct**.

| Article | Personnalisation | PA | Coût total | PV | Marge | Taux de marque |
|---|---|---|---|---|---|---|
| Veste zippée | Floquage 3,50 € | 24,70 € | 28,20 € | 34 € | 5,80 € | 17,06 % |
| T-shirt | Floquage 3,50 € | 12,35 € | 15,85 € | 19 € | 3,15 € | 16,58 % |
| Veste de pluie | Floquage 3,50 € | 22,10 € | 25,60 € | 31 € | 5,40 € | 17,42 % |
| Pantalon d'entraînement | Aucune | 18,20 € | 18,20 € | 22 € | 3,80 € | 17,27 % |
| T-shirt (réf. 208650) | Floquage 3,50 € | 12,35 € | 15,85 € | 19 € | 3,15 € | 16,58 % |
| Short RIO | Aucune | 9,75 € | 9,75 € | 12 € | 2,25 € | 18,75 % |
| Maillot match Kappa | Floquage complet 8 € | 16,90 € | 24,90 € | 31 € | 6,10 € | 19,68 % |
| Veste soft | Floquage 3,50 € | 71,50 € | 75,00 € | 89 € | 14,00 € | 15,73 % |
| T-shirt Intro | Floquage 3,50 € | 10,40 € | 13,90 € | 17 € | 3,10 € | 18,24 % |
| Short Calcuta | Aucune | 9,10 € | 9,10 € | 11 € | 1,90 € | 17,27 % |

**Trois cas de test que ce tableau contient déjà** : le maillot à 8 € de flocage (règle 5), les gobelets sans remise et hors fourchette de marge (règles 2 et 10), et les articles sans tarif enfant (règle 4).
