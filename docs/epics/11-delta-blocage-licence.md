# Epic 11 — Delta Documents signés : bloquer la validation sans signature

> **Statut** : **delta sur un module déjà livré.** La signature électronique multi-documents existe ; le verrou manque.
> **Source** : `prepa_epic/contexte_reglement_interieur_signature_2627.md`
> **Dépend de** : rien
> **Petit delta, règle non négociable. Probablement l'epic au meilleur rapport valeur/effort du lot.**

---

## 1. Pourquoi ce delta existe

La refonte du règlement intérieur 26-27 a produit deux documents indépendants (Joueurs & Familles, 18 articles ; Dirigeants, 15 articles) et une règle écrite noir sur blanc dans les deux :

> **« Aucune licence n'est validée tant que cette signature n'a pas été effectuée. »**

Le passage à la signature électronique ne sert pas qu'au confort : il sert de **preuve d'engagement**, pour pouvoir démontrer que la personne a pris connaissance du règlement et l'a accepté à une date précise.

Or le constat d'origine tient toujours, mot pour mot : *« Rien n'empêche techniquement qu'une licence soit activée sans que la signature ait été réellement récupérée : c'est une vérification manuelle, dépendante de la vigilance de la personne qui traite l'inscription. »*

C'est vrai aujourd'hui dans CoSync — pour une population précise, identifiée au §3.

## 2. Ce qui existe déjà — presque tout

Le module de documents signables est complet et va au-delà du besoin exprimé :

| Besoin du document source | État |
|---|---|
| Un document par public, indépendants | **Fait** — `DocumentSignable` + `DocumentCible`, paramétrable depuis `/admin/config/documents`, ciblable par population, par rôle dirigeant, ou nominativement |
| Une personne cumulant deux rôles signe les deux documents | **Fait** — les documents attendus se résolvent séparément pour `Licencie` et pour `Dirigeant` |
| Lecture puis signature tactile | **Fait** — une étape par document dans le formulaire public, pad de signature |
| PDF horodaté généré et archivé | **Fait** — `PdfRenderer` → `var/pdfs/` → upload Drive différé sur `kernel.terminate`, avec rattrapage par `app:drive-retry-upload` toutes les 15 min |
| Le texte ne nomme aucun outil interne | **Fait** — c'est une contrainte de rédaction du texte, respectée côté club |
| Savoir qui a signé et qui n'a pas signé | **Fait** — `DocumentRequirementResolver::manquantsPourLicencie()`, `manquantsPourDirigeant()`, `dirigeantsEnAttente()` |
| Re-signature chaque saison, version datée | **Fait** — `DocumentSignable` est rattaché à une `Season` |

Le module est même **plus robuste que demandé** : la liste des documents attendus est recalculée côté serveur à la soumission (un id envoyé mais non attendu est ignoré, un document attendu manquant rejette la soumission), et une licence purement administrative n'attend aucun document.

## 3. Ce qui manque réellement — le trou est étroit et précis

**Par le formulaire public, le verrou existe déjà** : un licencié ne peut pas soumettre son dossier sans avoir signé tous les documents attendus. Le chemin normal est sûr.

**Le trou est ailleurs.** `PaiementService::valider()` pose `LicenceStatus::VALIDATED` dès que le solde est atteint :

```php
private function valider(Licencie $licencie): void
{
    $dossier = $licencie->getDossierClub();
    if ($dossier === null || $dossier->getStatus() === LicenceStatus::VALIDATED) {
        return;
    }
    $dossier->setStatus(LicenceStatus::VALIDATED);   // ← aucune vérification de signature
    // ...
    $this->dotationSynchronizer->recomputeForLicencie($licencie);
```

Aucune vérification de signature n'intervient. Deux chemins réels aboutissent donc à une licence validée sans règlement signé :

1. **Une fiche créée manuellement en admin**, dont l'admin confirme le paiement sans que le formulaire public ait jamais été rempli.
2. **Un encaissement HelloAsso ou une confirmation manuelle** sur un dossier dont les documents attendus ont changé après coup — un document ajouté en cours de saison rend le dossier incomplet, mais le statut `VALIDATED` déjà posé ne bouge pas.

Et la conséquence n'est pas seulement un statut inexact : `valider()` appelle `recomputeForLicencie()`, donc **la dotation se matérialise en sortie de stock à préparer** pour une personne qui n'a rien signé.

## 4. Règles métier

1. **`VALIDATED` exige le paiement soldé ET tous les documents attendus signés.** Les deux conditions, jamais l'une seule. C'est la traduction directe de la phrase du règlement.

2. **Un dossier payé mais non signé n'est pas une erreur, c'est un état.** Il reste en `FORM_COMPLETED`, avec un motif explicite : « paiement soldé, signature manquante ». Ne pas inventer un statut supplémentaire — `LicenceStatus` a quatre valeurs qui suffisent, et en ajouter une casserait les badges, les filtres et les décomptes existants.

3. **Le motif du blocage s'affiche sur la fiche.** Un admin qui voit un paiement encaissé et un dossier non validé doit comprendre pourquoi en une seconde, sans quoi il cherchera un bug.

4. **La validation se rejoue toute seule quand la signature arrive.** Signer le document manquant doit valider le dossier sans qu'un admin ait à repasser derrière. Sinon le verrou crée une file d'attente invisible.

5. **Le verrou vaut aussi pour les dirigeants**, avec la même logique — à l'exception déjà codée : `licenceAdministrative` n'attend aucun document, donc rien ne la bloque.

6. **Un forçage administratif reste possible, tracé et motivé.** Cas réel : une signature récupérée sur papier, un cas litigieux à débloquer avant un match. Interdire tout forçage ferait contourner l'outil ; l'autoriser sans trace ferait disparaître la preuve d'engagement que ce chantier existe pour produire.

7. **Le verrou ne s'applique qu'aux saisons qui l'activent.** Poser la règle sur les saisons passées ferait basculer en `FORM_COMPLETED` des dossiers validés il y a deux ans, avec des dotations déjà remises. **C'est le point le plus dangereux de ce delta** (cf. §7).

## 5. Modèle de données proposé

Presque rien — c'est un delta de logique, pas de schéma.

```php
// Season — l'interrupteur de la règle 7
Season
    signatureBloqueValidation: bool = false   // false sur les saisons existantes (migration)

// DossierClub — la trace du forçage (règle 6)
DossierClub
    validationForceeLe: ?\DateTimeImmutable
    validationForceePar: ?User
    validationForceeMotif: ?string
```

**Le `DEFAULT false` sur `Season.signatureBloqueValidation` n'est pas un détail de migration : c'est la règle 7.** Une colonne à `true` par défaut invaliderait rétroactivement des dossiers de saisons closes. `ADD COLUMN ... NOT NULL DEFAULT false` sur une table déjà remplie, conformément au §13 du CLAUDE.md.

## 6. Services & écrans

| Classe | Rôle |
|---|---|
| `Service/Document/ValidationGuard` | **le verrou, en un seul endroit** : `peutValider(Licencie): bool` et `motifBlocage(Licencie): ?string`. Consulte `DocumentRequirementResolver::manquantsPourLicencie()`. |
| `PaiementService::valider()` | **modification** : consulte le guard avant de poser `VALIDATED` |
| `DocumentSignatureService::signerParLicencie()` | **modification** : rejoue la validation après signature (règle 4) |
| `LicencieController` | affichage du motif de blocage sur la fiche (règle 3) + action de forçage motivé (règle 6) |

Le suffixe `Guard` est déjà dans les conventions du projet (`CsrfGuard`, §9 du CLAUDE.md) et dit exactement ce que fait la classe.

**Ne pas disperser la vérification.** Un seul point d'entrée, appelé par les deux chemins (paiement et signature). C'est ce qui garantit qu'un troisième chemin de validation ajouté plus tard ne pourra pas oublier le verrou.

## 7. ⚠️ Le point dangereux : la production contient des dossiers validés

C'est le risque principal de ce delta, et il est réel.

La base de production contient des `DossierClub` en `VALIDATED` dont certains, selon les cas décrits au §3, n'ont pas toutes leurs signatures. Poser le verrou sans précaution produirait, au premier recalcul :

- des dossiers qui **régressent** de `VALIDATED` à `FORM_COMPLETED` ;
- `DotationBesoinSynchronizer` qui **retire des besoins de dotation** pour des kits déjà préparés, voire déjà remis ;
- des licenciés qui reçoivent un mail de relance alors que leur saison est terminée.

**Le verrou ne doit jamais s'appliquer rétroactivement.** Trois garde-fous, à tenir ensemble :

1. `signatureBloqueValidation` à **`false` par défaut**, activé saison par saison, en connaissance de cause.
2. Le verrou **conditionne le passage** à `VALIDATED`, il ne **révoque** jamais un `VALIDATED` déjà posé. Un dossier validé le reste, quoi qu'il arrive ensuite au ciblage des documents.
3. **Avant d'activer la saison en cours** : jouer un décompte en lecture seule des dossiers `VALIDATED` sans signature complète. S'il n'est pas nul, régulariser à la main **avant** l'activation, jamais après.

Un mode « aperçu » (le guard répond, rien ne bloque, l'écran signale) est le meilleur moyen d'obtenir ce décompte sans risque. C'est le lot 1.

### Autres jonctions

- **`DocumentRequirementResolver`** est déjà la source de vérité du « qui doit signer quoi ». Le guard le consulte, ne le double pas.
- **`DotationBesoinSynchronizer`** dépend de `VALIDATED` (`aDroitALaDotation()`). Il n'a rien à changer : il suivra le statut, qui sera devenu juste. C'est précisément l'effet recherché — plus de kit préparé pour quelqu'un qui n'a rien signé.
- **`MailerService::sendValidation()`** ne partira plus tant que le dossier n'est pas réellement validable. Cohérent avec la discipline du projet : rien ne part prématurément.
- **Epic 02 (Finance)** : un dossier payé mais non validé reste une recette encaissée. Le verrou est un statut de dossier, **jamais un statut de paiement** — ne pas laisser fuir la confusion dans le budget.

## 8. Lots livrables

1. **`ValidationGuard` en mode aperçu** — le guard répond, rien n'est bloqué, l'écran affiche le motif et un décompte. **C'est le lot qui donne l'information dont dépend la décision d'activer.**
2. **Verrou actif, activable par saison** — `signatureBloqueValidation`, branché dans `PaiementService`.
3. **Revalidation automatique à la signature** (règle 4).
4. **Forçage motivé et tracé** (règle 6).

## 9. Points à trancher avant de coder

- **Le décompte du lot 1 sur la base réelle** conditionne tout le reste. À faire avant de coder le lot 2.
- **Le forçage doit-il être réservé au super-admin (`DIAG_EMAIL`) ?** Il contourne une règle que le club a écrite dans un document signé. **Recommandation** : ouvert à tous les admins mais toujours motivé et tracé — le club a quatre ou cinq admins et une restriction créerait surtout un blocage un samedi matin.
- **Le blocage vaut-il aussi pour les dirigeants ?** La règle 5 dit oui. Mais un dirigeant n'a pas de `DossierClub` ni de statut `VALIDATED` — le verrou n'a pas de statut sur lequel mordre. Concrètement, pour eux, cela se traduit par un signalement dans les écrans d'effectif plutôt que par un blocage. À confirmer que ça suffit au club.

## 10. Contexte utile pour tester

**Ce qui doit rester possible** — cotisation 26-27 : **85 €** jeunes, **120 €** séniors. Le tarif *« peut être révisé à titre exceptionnel en cours de saison, uniquement après validation du Conseil d'Administration »* — cas vécu : 3 licences U15 signées en février à tarif réduit. Un montant dû plus faible que le tarif facial doit donc pouvoir solder un dossier (c'est déjà le cas via `Team.cotisation`, et l'Epic 02 y ajoute `ModeFinancementLicence::TARIF_REDUIT`).

**Cas de test du verrou**

| Situation | Attendu |
|---|---|
| Formulaire public complet + paiement soldé | `VALIDATED` |
| Fiche créée à la main, paiement confirmé, aucune signature | reste `FORM_COMPLETED`, motif affiché, **aucun besoin de dotation créé** |
| Dossier payé non signé, puis signature du document manquant | passe à `VALIDATED` **sans intervention admin** |
| Document ajouté en cours de saison sur un dossier déjà `VALIDATED` | reste `VALIDATED` — jamais de régression (§7) |
| Dirigeant en `licenceAdministrative` | jamais bloqué, aucun document attendu |
| Saison passée avec `signatureBloqueValidation = false` | aucun changement de statut, aucun mail |

**Une personne qui cumule joueur et dirigeant signe les deux documents indépendamment** — un parent entraîneur signe le règlement Joueurs & Familles *et* le règlement Dirigeants. Chaque règlement est autonome et aucun ne fait référence à l'autre. C'est déjà le comportement du module ; le verrou ne doit pas l'altérer.
