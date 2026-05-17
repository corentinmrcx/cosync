# CLAUDE.md — CoSync

> Ce fichier est la référence absolue du projet. Lis-le intégralement avant toute action.
> En cas de doute sur une décision d'architecture ou de style, ce document a raison.

---

## 1. Contexte & Vision

**CoSync** est un cockpit interne pour le Club de Football de Soudron (Marne).
Ce n'est **pas** un remplacement de FootClubs (outil fédéral FFF). C'est un **add-on** qui gère ce que FootClubs ne gère pas : la vie interne du club.

### Ce que l'outil fait
- Importer la liste des licenciés depuis un export XLSX FootClubs (upsert idempotent)
- Envoyer un lien unique par mail à chaque licencié/parent pour collecter les données club
- Collecter via formulaire mobile-first : tailles équipement, autorisations, signature règlement
- Générer un PDF du règlement signé et l'archiver automatiquement sur Google Drive
- Donner à l'admin un tableau de bord clair : statut formulaire + statut paiement de chaque licencié
- Permettre à l'admin de confirmer manuellement les paiements reçus
- Gérer le stock d'équipements et les dotations par joueur
- Gérer les saisons (création, tarifs, activation) depuis l'interface admin
- Gérer le référentiel catégories (édition des codes/labels si la FFF les modifie)

### Ce que l'outil ne fait PAS
- Ne recrée pas FootClubs (pas de gestion de licences FFF)
- Ne stocke pas les données médicales (hors scope RGPD V1)
- Ne gère pas l'attestation de conduite parentsSection F (trop sensible pour V1)
- Ne fait pas de paiement en ligne intégré V1 (SumUp lien externe possible, pas de webhook)

### Philosophie
- **Aller à l'essentiel.** Si une fonctionnalité peut attendre la V2, elle attend.
- **Zéro saisie libre non nécessaire.** Sélecteurs partout où c'est possible.
- **Source de vérité claire.** FootClubs = identité FFF. CoSync = vie interne club.
- **Multi-saisons dès le départ.** Toutes les données sont cloisonnées par `season_id`.

---

## 2. Stack Technique

| Couche | Technologie |
|---|---|
| Framework | Symfony 7.x (monolithe) |
| Base de données | PostgreSQL |
| ORM | Doctrine |
| Frontend | Twig + CSS natif + Alpine.js |
| Interactivité légère | Alpine.js (multi-step form, toggles conditionnels) |
| PDF | DomPDF via bundle Symfony |
| Import XLSX | PhpSpreadsheet |
| Drive | Google Drive API (Service Account) |
| Mail | Symfony Mailer (SMTP Gmail ou Brevo) |
| Auth admin | Symfony Security Bundle (email/password) |
| Auth public | UUID v4 dans l'URL (sans session, sans login) |

**Pas de React. Pas d'API REST. Pas de SPA.** Symfony rend les pages, Alpine.js gère l'interactivité côté client quand nécessaire. C'est suffisant pour ce projet.

---

## 3. Architecture des Dossiers

```
src/
├── Controller/
│   ├── Admin/          # Tout ce qui est derrière l'auth admin
│   └── Public/         # Formulaire public /inscription/{uuid}
├── Entity/             # Entités Doctrine uniquement, zéro logique métier
├── Repository/         # Requêtes Doctrine custom
├── Service/            # TOUTE la logique métier ici
│   ├── Import/         # ImportService, DataSanitizer
│   ├── Form/           # InscriptionFormService
│   ├── Pdf/            # PdfGeneratorService
│   ├── Drive/          # DriveUploaderService
│   ├── Mail/           # MailerService
│   └── Stock/          # StockService
├── Form/               # Symfony Form Types
├── DTO/                # Data Transfer Objects (entrées/sorties propres)
├── Enum/               # PaymentMode, LicenceStatus, StockMovementType...
└── EventListener/      # Listeners Doctrine si nécessaire

templates/
├── admin/              # Toutes les vues admin
├── public/             # Formulaire d'inscription public
├── pdf/                # Templates Twig utilisés par DomPDF
└── email/              # Templates mails
```

---

## 4. Modèle de Données

### Season
```php
id: int
label: string          // ex: "2025-2026"
active: bool           // une seule saison active à la fois
base_costs: json       // { "jeunes": 85, "seniors": 120 }
created_at: datetime
```

### Category (référentiel FFF, fixe)
```php
id: int
code: string           // "U6", "U7", ..., "U13", "U15", "SENIOR"
label: string
is_ecole_foot: bool    // true pour U6 à U13
min_year: int          // année de naissance min
max_year: int          // année de naissance max
```

### Team (équipe sportive interne)
```php
id: int
name: string           // "U15 A", "Séniors 1", "Loisirs"
season: Season
```

### Licencie
```php
uuid: Uuid             // clé publique, dans l'URL du formulaire
num_licence: string    // PK FFF, clé d'upsert à l'import
nom: string
prenom: string
date_naissance: Date
email: string
telephone: string
category: Category
team: ?Team            // assigné manuellement par l'admin
season: Season
form_token_expires_at: ?datetime  // expiration du lien public
imported_at: datetime
```

### DossierClub
```php
id: int
licencie: Licencie     // relation 1-1
taille_haut: ?string   // Enum: XS/S/M/L/XL/XXL ou taille enfant
taille_bas: ?string
pointure: ?string
autorisation_photo: ?bool
autorisation_transport_dirigeants: ?bool  // null si non applicable (seniors)
autorisation_transport_parents: ?bool     // null si non applicable (seniors)
payment_intention: ?PaymentMode  // ce que le licencié a déclaré vouloir payer
is_signed: bool
signature_path: ?string   // chemin Drive après upload
signature_date: ?datetime
form_completed_at: ?datetime
status: LicenceStatus  // LINK_SENT | FORM_COMPLETED | PAYMENT_CONFIRMED | VALIDATED
```

### Transaction
```php
id: int
licencie: Licencie
montant: float
mode: PaymentMode      // CB | CHEQUE | ESPECES | VIREMENT | PASS_SPORT | CAF | ANCV
reference: ?string     // numéro chèque, référence virement
date_paiement: datetime
confirmed_by: User
season: Season
```

### StockItem
```php
id: int
nom: string
couleur: ?string
ref_catalogue: ?string
lien_achat: ?string
season: Season
```

### StockMovement
```php
id: int
item: StockItem
quantite: int
type: StockMovementType  // ENTREE | SORTIE | REBUT
licencie: ?Licencie      // si sortie liée à un joueur
note: ?string
created_by: User
created_at: datetime
```

---

## 5. Enums PHP

Utilise des `enum` PHP 8.1 stricts, jamais des chaînes en dur dans le code.

```php
enum LicenceStatus: string {
    case LINK_SENT = 'link_sent';
    case FORM_COMPLETED = 'form_completed';
    case PAYMENT_CONFIRMED = 'payment_confirmed';
    case VALIDATED = 'validated';
}

enum PaymentMode: string {
    case CB_ONLINE = 'cb_online';
    case CHEQUE = 'cheque';
    case ESPECES = 'especes';
    case VIREMENT = 'virement';
    case PASS_SPORT = 'pass_sport';
    case CAF = 'caf';
    case ANCV = 'ancv';
}

enum StockMovementType: string {
    case ENTREE = 'entree';
    case SORTIE = 'sortie';
    case REBUT = 'rebut';
}
```

---

## 6. Workflows Métier Clés

### A. Import XLSX FootClubs

**Comportement attendu : idempotent, jamais destructeur.**

1. Admin drag & drop le fichier XLSX sur `/admin/import`
2. `ImportService` lit le fichier via PhpSpreadsheet
3. `DataSanitizer` normalise chaque ligne :
   - Nom en MAJUSCULES, Prénom en Capitalize
   - Téléphone : supprime espaces/tirets, format +33
   - Email : trim + lowercase
   - Catégorie : calculée depuis `date_naissance` et la saison active
4. Pour chaque ligne : `upsert` sur `num_licence`
   - Si le licencié existe : mise à jour des données FFF uniquement (nom, prénom, email, tel, catégorie). Les données club (DossierClub, Transaction) ne sont jamais touchées.
   - Si nouveau : création + génération UUID + envoi mail automatique
5. Rapport d'import affiché : X mis à jour, Y créés, Z erreurs

### B. Formulaire Public `/inscription/{uuid}`

Formulaire multi-étapes Alpine.js, mobile-first, sans login.

**Étape 1 — Bienvenue**
- Affichage nom/prénom du licencié (pré-rempli depuis la BDD)
- Rappel : "Ce formulaire complète votre inscription au Club de Foot de Soudron"
- Bouton "Commencer"

**Étape 2 — Équipement**
- Taille haut (select : XS / S / M / L / XL / XXL / Tailles enfants 6ans→16ans)
- Taille bas (même logique)
- Pointure (select : 28 → 48)

**Étape 3 — Autorisations** *(conditionnel : affiché uniquement si `category.is_ecole_foot === true`)*
- Autorisation transport par les dirigeants (OUI / NON)
- Autorisation transport par d'autres parents (OUI / NON)
- Droit à l'image (OUI / NON) — affiché pour TOUS les licenciés

**Étape 4 — Règlement**
- Affichage texte du règlement intérieur (scrollable)
- Checkbox "J'ai lu et j'accepte le règlement intérieur"
- Pad de signature tactile (canvas, bibliothèque Signature_pad.js)

**Étape 5 — Paiement**
- Affichage : "Comment souhaitez-vous régler votre cotisation ?"
- Montant affiché dynamiquement (jeunes : 85€ / seniors : 120€ depuis `season.base_costs`)
- Options radio :
  - Virement → affiche RIB du club + libellé exact à mettre
  - Chèque → "À l'ordre de [nom club], à remettre au local"
  - Espèces → "À remettre au local lors d'une permanence"
  - CB en ligne (SumUp) → lien externe + mention frais (ex: "+1,5% de frais")
  - Pass Sport / Chèque CAF / ANCV → "À remettre au local"
- Mention bien visible : **"Votre inscription ne sera validée qu'à réception du paiement."**

**Validation finale**
- Génération PDF règlement signé (template Twig → DomPDF)
- Upload sur Drive : `[Saison]/[Equipe]/[NOM_Prenom_NumLicence]/reglement_signe.pdf`
- Suppression du fichier local
- `DossierClub.is_signed = true`, `status = FORM_COMPLETED`
- Page de confirmation affichée au licencié

### C. Dashboard Admin

URL : `/admin/licencies`

Tableau filtrable (Tailwind, pas de lib externe) :
- Filtres : Saison | Équipe | Catégorie | Statut
- Colonnes : Nom Prénom | Catégorie | Équipe | Formulaire | Paiement | Statut global
- Badges colorés pour le statut :
  - 🔵 `LINK_SENT` — Lien envoyé
  - 🟡 `FORM_COMPLETED` — Formulaire complété, paiement en attente
  - 🟢 `VALIDATED` — Validé

Action depuis le tableau :
- "Confirmer paiement" → modal rapide (mode, montant, référence) → crée `Transaction` + passe statut à `VALIDATED`
- "Renvoyer le lien" → regénère l'UUID et renvoie le mail
- Clic sur une ligne → fiche détail du licencié

### D. Archivage Drive

```
Drive/
└── FC Soudron/
    └── 2025-2026/
        ├── U11/
        │   └── DUPONT_Thomas_123456/
        │       └── reglement_signe.pdf
        └── Seniors/
            └── MARTIN_Kevin_789012/
                └── reglement_signe.pdf
```

`DriveUploaderService` utilise un Service Account Google (credentials JSON en variable d'env, jamais committé).

---

## 7. Standards de Qualité — Règles Absolues

### 7.1 Service Layer — Règle d'or

**Zéro logique métier dans les contrôleurs.**

Un contrôleur fait exactement trois choses : récupérer la requête, appeler un service, retourner une réponse.

```php
// ✅ CORRECT
class ImportController extends AbstractController
{
    public function __construct(private readonly ImportService $importService) {}

    #[Route('/admin/import', methods: ['POST'])]
    public function import(Request $request): Response
    {
        $file = $request->files->get('xlsx');
        $result = $this->importService->importFromXlsx($file);
        return $this->render('admin/import/result.html.twig', ['result' => $result]);
    }
}

// ❌ INTERDIT — logique métier dans le contrôleur
class ImportController extends AbstractController
{
    public function import(Request $request, EntityManagerInterface $em): Response
    {
        $file = $request->files->get('xlsx');
        $spreadsheet = IOFactory::load($file->getPathname());
        foreach ($spreadsheet->getActiveSheet()->getRowIterator() as $row) {
            // ... traitement direct ici
        }
    }
}
```

### 7.2 Typage strict — Obligatoire partout

```php
<?php declare(strict_types=1);

// Tous les arguments typés, tous les retours typés
public function generatePdf(Licencie $licencie): string  // retourne le chemin local
public function uploadToDrive(string $localPath, string $drivePath): string  // retourne l'URL Drive
public function sanitizePhone(?string $phone): ?string
```

Jamais de `mixed`, jamais d'argument sans type, jamais de retour sans type sauf `void`.

### 7.3 Responsabilité unique des classes

Chaque classe/service a **une seule raison de changer**.

- `DataSanitizer` → normalise les données brutes du XLSX. Rien d'autre.
- `PdfGeneratorService` → génère un PDF depuis une entité. Rien d'autre.
- `DriveUploaderService` → upload un fichier sur Drive. Rien d'autre.
- `InscriptionFormService` → orchestre la soumission du formulaire public. Appelle les autres services, ne fait rien lui-même.

Si tu te demandes "pourquoi ce code est là ?", c'est que la classe en fait trop.

### 7.4 DTOs pour les entrées/sorties

Ne passe jamais une `Request` brute à un service. Transforme-la en DTO typé d'abord.

```php
// DTO
final class InscriptionFormData
{
    public function __construct(
        public readonly string $tailleHaut,
        public readonly string $tailleBas,
        public readonly string $pointure,
        public readonly bool $autorisationPhoto,
        public readonly ?bool $autorisationTransportDirigeants,
        public readonly ?bool $autorisationTransportParents,
        public readonly string $signatureData,      // base64 canvas
        public readonly PaymentMode $paymentIntention,
    ) {}
}
```

### 7.5 Enums, pas de magic strings

```php
// ✅
$dossier->setStatus(LicenceStatus::FORM_COMPLETED);

// ❌
$dossier->setStatus('form_completed');
```

### 7.6 Twig — Composants réutilisables via include/macro

Quand plusieurs templates partagent la même structure visuelle, extraire en composant Twig.

```twig
{# components/_badge_status.html.twig #}
<span class="badge badge--{{ status }}">
    {{ status|trans }}
</span>
```

```css
/* assets/styles/components/_badge.css */
.badge { padding: 0.25rem 0.5rem; border-radius: var(--radius-xl); font-size: var(--font-size-xs); font-weight: 500; }
.badge--link_sent       { background: var(--color-status-sent-bg);      color: var(--color-status-sent); }
.badge--form_completed  { background: var(--color-status-completed-bg);  color: var(--color-status-completed); }
.badge--validated       { background: var(--color-status-validated-bg);  color: var(--color-status-validated); }
```

Un composant Twig n'a pas de logique métier. Il affiche ce qu'on lui passe.

### 7.7 Alpine.js — Séparation données/affichage

```html
<!-- ✅ Les données sont dans x-data, le HTML affiche seulement -->
<div x-data="{ step: 1, maxStep: 5 }">
    <div x-show="step === 2">...</div>
    <button @click="step++" x-show="step < maxStep">Suivant</button>
</div>

<!-- ❌ Pas de logique dans les attributs HTML -->
<button @click="if(step < 5 && someCondition && otherThing) { step = step + 1 }">
```

Si la logique Alpine devient complexe, elle va dans un composant JS séparé (`x-data="inscriptionForm()"`).

### 7.8 CSS — Organisation et conventions de nommage

Le CSS est écrit en CSS natif, sans framework utilitaire. `app.css` ne contient que les variables globales et les `@import` — un fichier par template.

**Flexbox obligatoire pour tous les layouts.** Pas de float, pas de positionnement absolu pour faire de la mise en page. Flex (ou Grid pour les layouts 2D complexes) partout.

**Structure des fichiers :**
```
assets/styles/
├── app.css              ← variables :root + @import de tout le reste
├── components/          ← éléments réutilisables sur plusieurs pages
│   └── badge.css
└── pages/               ← un fichier par template Twig
    ├── login.css
    ├── dashboard.css
    └── inscription.css
```

**Convention de nommage : préfixe par page ou composant.**

Chaque classe est préfixée par le nom de la page ou du composant dans lequel elle est définie. Jamais de classe générique sans contexte.

```css
/* login.css → préfixe login- */
.login-page { ... }
.login-card { ... }
.login-card-header { ... }
.login-card-body { ... }
.login-input { ... }
.login-field-last { ... }

/* badge.css → préfixe badge- */
.badge { ... }
.badge-validated { ... }
```

Convention : **tirets simples uniquement**. Pas de `__` ni de `--` BEM. Le préfixe de page/composant est toujours présent.

**Variables uniquement pour l'identité visuelle :**

```css
/* assets/styles/app.css */
:root {
    /* — Identité club — */
    --color-primary:       #ff3131;   /* Rouge club — boutons CTA, headers, badges actifs */
    --color-primary-dark:  #cc0000;   /* Rouge foncé — hover, focus */
    --color-primary-light: #ffe5e5;   /* Rouge pâle — backgrounds de badges, alertes légères */

    /* — Textes — */
    --color-text-base:     #1f1f1f;
    --color-text-body:     #374151;
    --color-text-muted:    #6b7280;
    --color-text-disabled: #9ca3af;
    --color-text-inverse:  #ffffff;

    /* — Fonds — */
    --color-bg-page:   #ffffff;
    --color-bg-subtle: #f9fafb;
    --color-bg-muted:  #f3f4f6;

    /* — Bordures — */
    --color-border:        #e5e7eb;
    --color-border-strong: #d1d5db;

    /* — Statuts licenciés — */
    --color-status-sent:         #3b82f6;
    --color-status-sent-bg:      #eff6ff;
    --color-status-completed:    #f59e0b;
    --color-status-completed-bg: #fffbeb;
    --color-status-validated:    #22c55e;
    --color-status-validated-bg: #f0fdf4;

    /* — Feedback — */
    --color-success:    #22c55e;  --color-success-bg: #f0fdf4;
    --color-warning:    #f59e0b;  --color-warning-bg: #fffbeb;
    --color-danger:     #ef4444;  --color-danger-bg:  #fef2f2;
    --color-info:       #3b82f6;  --color-info-bg:    #eff6ff;

    /* — Typographie — */
    --font-sans:      'Montserrat', system-ui, -apple-system, sans-serif;
    --font-size-xs:   0.75rem;
    --font-size-sm:   0.875rem;
    --font-size-base: 1rem;
    --font-size-lg:   1.125rem;
    --font-size-xl:   1.25rem;
    --font-size-2xl:  1.5rem;

    /* — Espacements — */
    --radius-sm: 0.25rem;
    --radius-md: 0.5rem;
    --radius-lg: 0.75rem;
    --radius-xl: 1rem;

    /* — Ombres — */
    --shadow-card:  0 1px 3px 0 rgb(0 0 0 / 0.1), 0 1px 2px -1px rgb(0 0 0 / 0.1);
    --shadow-modal: 0 10px 15px -3px rgb(0 0 0 / 0.1), 0 4px 6px -4px rgb(0 0 0 / 0.1);
}
```

### Principes visuels à respecter
- **Fond blanc dominant.** Le rouge est un accent, pas un fond de page.
- **Rouge réservé aux éléments forts** : bouton CTA principal, header admin, bandeaux de section, badges de statut actif.
- **Texte anthracite (`--color-text-body`)** pour tout le corps de texte. Noir pur uniquement pour les titres.
- **Tableaux lisibles** : alternance `--color-bg-page` / `--color-bg-subtle` sur les lignes. Bordures légères `--color-border`.
- **Jamais de couleur en dur dans les templates.** Toujours passer par les variables CSS.

---

## 8. Sécurité & RGPD

### Accès public
- Le lien `/inscription/{uuid}` est valide **30 jours** après génération.
- Après soumission, le lien devient invalide (token consommé).
- L'admin peut régénérer un lien depuis la fiche licencié.
- Aucune donnée sensible dans l'URL (seulement l'UUID, pas le nom).

### Stockage
- Signature et PDF : **jamais stockés en local de façon permanente**. Upload Drive → suppression locale immédiate.
- Données médicales : **non collectées** en V1.
- Numéro de sécurité sociale : **non collecté**.
- Attestation de conduite parents (Section F des anciens formulaires) : **non collectée** en V1.

### Variables d'environnement (jamais dans le code)
```
GOOGLE_DRIVE_CREDENTIALS_JSON=
GOOGLE_DRIVE_FOLDER_ID=
MAILER_DSN=
APP_SECRET=
DATABASE_URL=
```

---

## 9. Règles de Nommage

| Élément | Convention | Exemple |
|---|---|---|
| Entités | PascalCase | `Licencie`, `DossierClub` |
| Services | PascalCase + Suffix `Service` | `ImportService`, `PdfGeneratorService` |
| DTOs | PascalCase + Suffix `Data` | `InscriptionFormData` |
| Enums | PascalCase | `LicenceStatus`, `PaymentMode` |
| Controllers | PascalCase + Suffix `Controller` | `ImportController` |
| Routes admin | snake_case préfixé | `admin_licencies_list` |
| Routes public | snake_case préfixé | `public_inscription_show` |
| Templates | snake_case | `admin/licencies/list.html.twig` |
| Variables Twig | camelCase | `{{ licencie.nomPrenom }}` |

---

## 10. Ce qu'il ne faut pas faire — Liste Noire

- ❌ Logique métier dans un contrôleur
- ❌ Requête SQL / Doctrine dans un contrôleur
- ❌ Magic strings pour les statuts, modes de paiement, types de mouvement
- ❌ Credentials Google Drive dans le code source ou le repo Git
- ❌ Données médicales ou numéro de sécu dans le formulaire public
- ❌ Supprimer des données licencié lors d'un nouvel import XLSX
- ❌ Plusieurs saisons actives simultanément
- ❌ React / SPA / API REST (hors scope V1)
- ❌ Logique conditionnelle complexe dans les templates Twig (ça va dans un service ou un helper)
- ❌ Classes CSS en dur dans le PHP

---

## 11. Ordre de Développement Recommandé (V1)

1. **Setup** : Symfony, PostgreSQL, Tailwind, Alpine.js, DomPDF, PhpSpreadsheet
2. **Entités & migrations** : toutes les entités + enums
3. **Auth admin** : login simple email/password
4. **Import XLSX** : `ImportService` + `DataSanitizer` + UI drag & drop
5. **Envoi mail** : génération UUID + template mail avec lien
6. **Formulaire public** : multi-étapes Alpine.js + signature pad
7. **Génération PDF + Drive** : `PdfGeneratorService` + `DriveUploaderService`
8. **Dashboard admin** : tableau licenciés filtrable + badges statut
9. **Confirmation paiement** : modal admin + création `Transaction`
10. **Fiche licencié** : vue détail complète
11. **Gestion stock** : `StockItem`, `StockMovement`, aide à la commande
12. **Gestion saisons** : création/activation depuis l'admin (label, tarifs jeunes/seniors)
13. **Gestion catégories** : édition admin des codes et labels (si la FFF en modifie)

---

## 12. Notes techniques importantes

### Import XLSX FootClubs
- Format réel : 5 colonnes — `Type licence`, `Nom, prénom`, `Né(e) le`, `Sous catégorie`, `Validité Certif Médic N+1`
- Seules les lignes `Type licence = Libre` sont importées
- `Nom, prénom` est **une seule colonne** : split sur le premier espace (avant = NOM, après = Prénom)
- Clé d'upsert : `nom + prenom + date_naissance` (pas de numéro de licence dans l'export)
- Catégorie lue depuis `Sous catégorie`, jamais calculée depuis la date de naissance
- Format date : `JJ/MM/AAAA`
- L'email n'est **pas** dans l'export FootClubs — il est saisi manuellement par l'admin

### Référentiel catégories (`app:seed-referential`)
- À lancer **une seule fois** au setup pour pré-remplir la table `category`
- Les codes FFF (U6, U7…SENIOR, variantes F) sont stables entre les saisons
- Si la FFF crée de nouveaux codes, les ajouter via l'interface admin (section 13)

### Gestion des saisons
- Une seule saison `active = true` à la fois (contrainte métier)
- Création via l'interface admin : label (ex: `2026-2027`), tarifs (`base_costs`)
- Changer de saison = désactiver l'ancienne + activer la nouvelle
