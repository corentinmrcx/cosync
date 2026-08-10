# Tests

Tests d'intégration de la logique métier stock/dotations (conteneur réel + base réelle).
Chaque test tourne dans une transaction **annulée à la fin** (`dama/doctrine-test-bundle`) :
la base de test reste propre, aucune donnée n'est conservée.

## Mise en place (une seule fois)

La suite utilise une base **séparée** `cosync_test` (suffixe `_test` ajouté automatiquement en env `test`) :

```bash
php bin/console doctrine:database:create --env=test --if-not-exists
php bin/console doctrine:migrations:migrate --env=test --no-interaction
```

> À refaire après une nouvelle migration : `php bin/console doctrine:migrations:migrate --env=test -n`.

## Lancer les tests

```bash
php bin/phpunit                 # toute la suite
php bin/phpunit tests/Service   # un dossier
```

## Documents signables (`tests/Service/Document/`, `tests/Controller/*/Document*`)

- **DocumentRequirementResolver** — ciblage par rôle, par personne désignée, union des deux,
  document inactif, document d'une autre saison, disparition des manquants après signature.
- **DirigeantDossierCompletion** — un document ajouté en cours de saison rend le dossier incomplet.
- **Parcours publics** — chaque population ne voit que ses documents ; un id non attendu est ignoré.
- **CRUD admin** — code dérivé du titre, indépendance des textes, aperçu PDF, relance groupée.

`App\Tests\Support\DocumentFixtures` construit documents et signatures : presque tous ces
scénarios commencent par là.

## Couverture actuelle (`tests/Service/Stock/`)

- **DotationResolver** — résolution par priorité (individu > équipe > catégorie), taille déduite du dossier,
  groupe de choix (première option par défaut), absence d'affectation.
- **DotationBesoinService** — génération du besoin, idempotence du recalcul, préservation d'un besoin « donné »,
  remise (mouvement `SORTIE/DOTATION` + décrément du stock par taille) et annulation.
- **AchatService** — équation « à commander » = besoins − stock − commandes en attente, séparation par taille,
  regroupement par fournisseur, exclusion des besoins donnés/couverts.
- **CommandeService** — génération d'un brouillon par fournisseur (prix snapshot), réception partielle puis
  complète (statuts + stock par taille), réception bornée au restant, passage en « commandée ».
