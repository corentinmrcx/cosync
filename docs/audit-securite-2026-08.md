# Audit de sécurité CoSync — pré-mise en production

**Date :** 2026-08-10
**Périmètre :** formulaire public d'inscription, paiement HelloAsso, routes publiques secondaires (dirigeant, attestation clés, pages légales), zone admin & modèle d'autorisation, génération PDF / stockage / upload Drive, configuration / infrastructure / dépendances.
**Méthode :** lecture du code, traçage des flux, pas de test d'intrusion actif. Aucun fichier modifié.

---

> **Mise à jour 2026-08-10 (après correctifs).** Les deux failles Élevées (E1, E2) et les
> trois Moyennes prioritaires (M1, M2, M3) ont été corrigées et vérifiées (474 tests + PHPStan +
> lint container + `nginx -t` au vert). Voir le statut par ligne dans le tableau de synthèse.
> **Un point nouveau et important est apparu pendant les correctifs** : `composer audit` remonte
> des CVE actives sur des dépendances de production (dont une **critique** sur `twig/twig`),
> disclosées après la connaissance de l'auditeur initial. Voir la section **D1 — Dépendances**.

## Verdict global

**Aucune faille Critique. Aucune faille Élevée applicative.** L'architecture est saine et déjà bien durcie pour ce stade : les décisions structurantes du projet tiennent réellement à la lecture du code.

Contrôles vérifiés comme réellement en place (à conserver) :

- **Paiement HelloAsso** : aucune `Transaction` n'est créée sur la foi d'un webhook ou d'une `returnUrl` ; l'état est relu chez HelloAsso (`state === Authorized`) avant tout enregistrement. Idempotence à triple garde (contrôle applicatif + contrainte d'unicité BDD + `catch` de la violation concurrente). Montant issu de l'API, jamais du client → « payer 1 € pour valider 150 € » impossible.
- **Contrôle d'accès public** : tout passe par des UUID v4 (122 bits, non énumérables), contraints par `Requirement::UUID`. Aucun identifiant séquentiel utilisé pour l'autorisation. Pas d'IDOR.
- **Tokens de lien** : expiration 30 jours vérifiée à chaque étape, token consommé (`formTokenExpiresAt = null`) après finalisation → pas de rejeu.
- **Recalcul systématique côté serveur** des documents attendus, choix de dotation, flags de transport et montant. Les entrées du client hors périmètre sont ignorées.
- **CSRF** présent sur quasi tous les POST mutateurs (jetons dédiés par action via `CsrfGuard`).
- **Pas d'injection SQL/DQL** (Doctrine paramétré ; les rares concaténations ne portent que des identifiants en dur).
- **PDF/DomPDF** : `isRemoteEnabled = false`, autoescape Twig actif, noms de fichiers sanitisés, `var/` hors racine web, `Process` en tableau (pas de shell), `PGPASSWORD` passé par l'environnement.
- **Secrets** : aucun secret committé dans l'historique git ; `.env` tracké ne contient que des valeurs vides/dev ; `.env.local`, `.env.prod`, `config/secrets/` gitignorés. Entrypoint prod qui refuse de démarrer en mode dev ou avec un `APP_SECRET` par défaut. Profiler absent en prod (`--no-dev`).
- **Dépendances à jour**, aucune CVE ouverte identifiée (Symfony 7.4.x, dompdf 3.1.5, phpspreadsheet 5.7, guzzle 7.10) — sauf Quill 1.3.7 (cf. M1).

---

## Tableau de synthèse

| # | Sévérité | Titre | Domaine | Statut |
|---|---|---|---|---|
| D1 | 🔴 Critique | CVE actives sur dépendances de prod (twig critique, etc.) | Dépendances | ✅ corrigé |
| E1 | 🟠 Élevée | En-têtes de sécurité HTTP absents (clickjacking, MIME-sniffing) | Infra Nginx | ✅ corrigé |
| E2 | 🟠 Élevée | Aucune protection anti-brute-force sur `/login` | Auth | ✅ corrigé |
| M1 | 🟡 Moyenne | XSS stocké admin→public via HTML `|raw` non assaini (+ Quill 1.3.7) | Public / Admin | ✅ corrigé |
| M2 | 🟡 Moyenne | Signature acceptée en `data:image/svg+xml` sans validation du contenu | Public / PDF | ✅ corrigé |
| M3 | 🟡 Moyenne | CSRF manquant sur l'import XLSX (déclenche des envois de mails) | Admin | ✅ corrigé |
| M4 | 🟡 Moyenne | Modèle d'autorisation plat : tout compte = admin total | Admin |
| M5 | 🟡 Moyenne | Dumps SQL non chiffrés poussés sur le Drive (RGPD) | Ops / Backup |
| M6 | 🟡 Moyenne | `trusted_proxies` trop large + `X-Forwarded-Host` de confiance | Infra |
| M7 | 🟡 Moyenne | Cookies de session non durcis explicitement | Config |
| F1 | 🟢 Faible | Pas de validation serveur des tailles / pointures | Public |
| F2 | 🟢 Faible | Open redirect protocole-relatif (`//evil.com`) dans le switch de saison | Admin |
| F3 | 🟢 Faible | DomPDF : `chroot` / `isPhpEnabled` / `isJavascriptEnabled` non verrouillés | PDF |
| F4 | 🟢 Faible | `PdfStorage::ecrire()` sans garde `basename` (défense en profondeur) | PDF |
| F5 | 🟢 Faible | Pas de rate limiting sur les POST publics et le webhook | Public |
| F6 | 🟢 Faible | Super-admin dépend d'un env optionnel (`DIAG_EMAIL`) — fail-open sur suppression | Admin |
| F7 | 🟢 Faible | `expose_php = On`, compose dev Postgres exposé, HTTPS non forcé côté app | Infra |
| F8 | 🟢 Faible | Incohérence credentials Google (chemin de fichier vs JSON inline) | Ops |

---

## Détail des findings

### 🔴 D1 — CVE actives sur des dépendances de production
**Découvert le 2026-08-10** en exécutant `composer audit` dans le conteneur (l'audit initial n'avait pas connaissance des advisories disclosées le 2026-05-20). `composer audit --no-dev` remonte **58 advisories sur 15 paquets de production**, dont :

| Paquet | Sévérité max | Nature |
|---|---|---|
| `twig/twig` | **Critique** | contournements de sandbox / `__toString()` (CVE-2026-47732, -46638, -24425, -46634…) |
| `symfony/security-http` | Haute | advisories authentification |
| `symfony/http-kernel` | Haute | — |
| `symfony/mime` | Haute | — |
| `phpoffice/phpspreadsheet` | Haute | parsing (utilisé sur l'import XLSX) |
| `guzzlehttp/guzzle` | Haute | client HTTP (appels HelloAsso / Drive) |
| `dompdf/dompdf`, `symfony/routing`, `symfony/cache`, `symfony/mailer`, `symfony/http-foundation`, `symfony/runtime`, `symfony/yaml`, `guzzlehttp/psr7`, `symfony/polyfill-intl-idn` | Moyenne / Basse | — |

**Portée réelle pour CoSync :** les CVE Twig concernent le **mode sandbox** (templates fournis par l'utilisateur), que CoSync **n'utilise pas** — le risque d'exploitation directe est donc faible en l'état. Mais `phpspreadsheet` (haute, sur le flux d'import) et `symfony/security-http` (haute, sur l'authentification) sont sur des chemins réellement exposés. À la veille d'une mise en prod, **toutes ces dépendances doivent être mises à jour**.

**Correctif appliqué le 2026-08-10 :** `composer update` (les contraintes en `^7.x` / `^3.x`
autorisaient déjà les versions patchées) → `composer audit` remonte désormais **0 advisory**,
474 tests + PHPStan toujours au vert. *À réintégrer ensuite dans un contrôle CI récurrent
(`composer audit` bloquant) pour ne pas re-dériver.*

### 🟠 E1 — En-têtes de sécurité HTTP absents
**Fichier :** `docker/nginx/nginx.prod.conf`
Aucun `Strict-Transport-Security`, `X-Frame-Options`, `X-Content-Type-Options`, `Referrer-Policy`, `Content-Security-Policy`. Le formulaire public de signature manuscrite est exposé au clickjacking (chargement en iframe) et au MIME-sniffing. La terminaison TLS étant faite par Nginx Proxy Manager (config non versionnée), rien ne garantit HSTS.

**Correctif :** dans le `server{}` de `nginx.prod.conf` :
```nginx
add_header X-Frame-Options DENY always;
add_header X-Content-Type-Options nosniff always;
add_header Referrer-Policy strict-origin-when-cross-origin always;
add_header Strict-Transport-Security "max-age=31536000; includeSubDomains" always;
# CSP : à adapter — Alpine.js nécessite de gérer les handlers inline
```
À défaut, poser ces en-têtes dans NPM et le documenter.

### 🟠 E2 — Aucune protection anti-brute-force sur le login
**Fichier :** `config/packages/security.yaml`
Le firewall `main` n'a pas de `login_throttling` et aucun `rate_limiter` global n'est défini. Le mot de passe admin unique est la surface de compromission de tout le back-office ; les tentatives sont illimitées.

**Correctif :** sous le firewall `main` :
```yaml
login_throttling:
    max_attempts: 5
    interval: '15 minutes'
```
(`symfony/rate-limiter`, déjà tiré transitivement.)

### 🟡 M1 — XSS stocké admin→public via HTML `|raw` non assaini
**Fichiers :**
- `templates/public/_document_signature_step.html.twig:23` → `{{ doc.contenuHtml|raw }}`
- `templates/public/attestation_cle/form.html.twig:40` → `{{ season.attestationCleText|raw }}`
- `templates/pdf/document_signe.html.twig:13` → `{{ reglementHtml|raw }}`
- Écriture : `DocumentSignableService` (`setContenuHtml`) et `SeasonService::updateAttestationCleText` — **aucune sanitisation serveur**.

Le HTML est saisi via l'éditeur Quill **1.3.7** (branche 1.x abandonnée, advisories XSS connus), dont le nettoyage est purement côté client donc contournable par un POST direct sur l'endpoint admin. Le contenu est ensuite rendu `|raw` sur des pages publiques **non authentifiées** (chaque licencié / détenteur) et dans les PDF. Vecteur : défiguration, redirection de paiement (phishing HelloAsso), exfiltration des données saisies (permis, assurance, signatures), et vol de session admin si l'admin rouvre la page.

**Correctif :** assainir le HTML **à l'écriture** via `symfony/html-sanitizer` (whitelist des balises produites par Quill : `p, br, strong, em, u, ul, ol, li, h1-h3, a`). Conserver `|raw` mais sur du contenu déjà purifié. Migrer Quill vers `^2`. Défense en profondeur : CSP sans `unsafe-inline`.

### 🟡 M2 — Signature acceptée en `data:image/svg+xml` sans validation du contenu
**Fichiers :** `src/Service/Document/SignatureCollector.php:62-68`, `src/Service/Inscription/AttestationTransportRequestFactory.php:33`, `src/Controller/Public/AttestationCleController.php:120-122`
La seule validation est `str_starts_with($sig, 'data:image/')` + taille ≤ 2,8 Mo. Un SVG (`data:image/svg+xml;base64,…`) passe et est injecté dans un `<img src>` rendu par DomPDF : bombe d'entités XML (DoS mémoire/CPU sur le worker), tentative de lecture `file://` locale (bloquée par `isRemoteEnabled=false` pour http/https mais dépendante du `chroot`, cf. F3), ou signature bidon non probante (répudiation).

**Correctif :** restreindre à `data:image/png;base64,` (format réellement produit par Signature_pad.js), décoder le base64 (`base64_decode($s, true) !== false`) et vérifier les octets PNG (`getimagesizefromstring` → `IMAGETYPE_PNG`, dimensions bornées). Coût fonctionnel nul.

### 🟡 M3 — CSRF manquant sur l'import XLSX
**Fichier :** `src/Controller/Admin/ImportController.php:35-69`
La route `POST /admin/effectif/import` lit `$request->files->get('xlsx')` sans `CsrfGuard::valider()` ni `createForm`. C'est le **seul** POST mutateur de la zone admin sans CSRF. L'import crée des licenciés et **déclenche l'envoi automatique de mails** → un admin piégé par un formulaire auto-soumis pollue les données et envoie des mails vers des adresses choisies par l'attaquant.

**Correctif :** `$this->csrf->valider('import_xlsx', $request);` en tête de `process()` + champ caché `_token` dans `templates/admin/import/index.html.twig`.

### 🟡 M4 — Modèle d'autorisation entièrement plat
**Fichiers :** `src/Entity/User.php:55-61` (`getRoles()` renvoie toujours `['ROLE_USER']`), `config/packages/security.yaml` (`access_control` n'impose que `ROLE_USER`).
Tout compte authentifié a un accès identique à toute la zone `/admin`, y compris la création et la suppression d'autres comptes admin (`UserController`). La seule distinction est le super-admin par correspondance d'email (`DIAG_EMAIL`), qui ne protège que les écrans diag/purge et le changement de mot de passe d'autrui. Aucun moindre privilège : un compte « bénévole » ou une session volée = contrôle total.

**Correctif :** introduire un vrai `ROLE_ADMIN` distinct de `ROLE_USER`, gater `UserController`, la gestion de saison et les actions destructives via `#[IsGranted('ROLE_ADMIN')]`. Le champ `roles` existe déjà en base ; ne jamais l'exposer dans un formulaire self-service.

### 🟡 M5 — Dumps SQL non chiffrés poussés sur le Drive
**Fichiers :** `src/Service/Ops/DatabaseBackupService.php:41-89`, `src/Command/DatabaseBackupCommand.php:63-82`
`pg_dump --format=plain` produit un `.sql.gz` **en clair** (noms, emails, téléphones, adresses de mineurs, transactions) uploadé sur le Drive du club sans chiffrement. Toute personne avec accès au dossier « Sauvegardes » (partage trop large, compromission du compte Google) obtient une copie exfiltrable de la base.

**Correctif :** chiffrer le dump avant upload (age/gpg, clé privée hors serveur), restreindre le partage du dossier Drive, resserrer les permissions locales (`0700`). `PGPASSWORD` est déjà passé proprement par l'environnement.

### 🟡 M6 — `trusted_proxies` trop large + `X-Forwarded-Host`
**Fichier :** `config/packages/framework.yaml:6-7`
Toute la plage RFC1918 (`10/8`, `172.16/12`, `192.168/16`) est de confiance et `x-forwarded-host` est dans `trusted_headers`. Un conteneur/hôte sur ces réseaux peut usurper `X-Forwarded-For` (contourne un rate-limiting par IP) et surtout `X-Forwarded-Host`. Le Host sert à construire les liens des mails d'inscription et les `returnUrl` HelloAsso → un Host empoisonné détourne les liens de paiement.

**Correctif :** restreindre `trusted_proxies` à l'adresse réelle du proxy NPM (via env `TRUSTED_PROXIES`), retirer `x-forwarded-host` de `trusted_headers` (Symfony reconstruira le host depuis `DEFAULT_URI`).

### 🟡 M7 — Cookies de session non durcis explicitement
**Fichier :** `config/packages/framework.yaml`
`framework.session` ne fixe pas `cookie_secure` / `cookie_samesite` / `cookie_httponly`. Les défauts Symfony sont corrects (`secure:auto`, `samesite:lax`, `httponly:true`) mais implicites, et `auto` dépend d'une détection HTTPS elle-même liée à M6.

**Correctif :**
```yaml
session:
    cookie_secure: true
    cookie_samesite: strict   # lax si les retours HelloAsso posent problème
    cookie_httponly: true
```

### 🟢 F1 — Pas de validation serveur des tailles / pointures
**Fichier :** `src/Service/Inscription/InscriptionFormRequestFactory.php:27-32`
`taille_haut`, `taille_bas`, `pointure` ne sont contrôlés que non-vides, jamais contre les référentiels. Un POST direct stocke une valeur arbitraire (commandes d'équipement faussées) ou une chaîne trop longue → dépassement de colonne → HTTP 500. Pas d'injection (Doctrine paramétré) ni de XSS (Twig échappe). **Correctif :** valider contre les listes autorisées, rejeter sinon.

### 🟢 F2 — Open redirect protocole-relatif dans le switch de saison
**Fichier :** `src/Controller/Admin/SeasonController.php:170-173`
Le garde `str_starts_with($returnTo, '/')` laisse passer `//evil.com` → redirection externe (phishing). **Correctif :** rejeter les valeurs commençant par `//` ou `/\`, ou valider contre une liste de routes connues.

### 🟢 F3 — DomPDF : options de confinement non verrouillées
**Fichier :** `src/Service/Pdf/PdfRenderer.php:30-34`
Seul `isRemoteEnabled=false` est posé. `isPhpEnabled`, `isJavascriptEnabled` et `chroot` restent aux défauts (sûrs en 3.1.5 mais non verrouillés) — dernière barrière contre une lecture `file://` combinée à M1/M2. **Correctif :** poser explicitement `isPhpEnabled=false`, `isJavascriptEnabled=false`, `chroot` sur un répertoire d'assets dédié.

### 🟢 F4 — `PdfStorage::ecrire()` sans garde `basename`
**Fichier :** `src/Service/Pdf/PdfStorage.php:21-33`
Aucun contrôle de `..`/`/` sur `$nomFichier`. Les appelants actuels sont sûrs (UUID + slug), mais un futur appelant moins prudent écrirait hors `var/pdfs/`. **Correctif :** `basename($nomFichier)` + vérifier que le `realpath` reste sous le répertoire.

### 🟢 F5 — Pas de rate limiting sur les POST publics et le webhook
**Fichiers :** POST `submit` de `InscriptionController`, `DirigeantController`, `AttestationCleController` ; `/webhook/helloasso`.
Chaque POST déclenche une génération DomPDF (coûteuse). Un porteur d'UUID valide peut marteler l'endpoint (saturation worker / file Drive) ; le webhook peut être spammé pour amplifier des appels vers l'API HelloAsso. Énumération d'UUID non exploitable (v4). **Correctif :** `RateLimiter` sliding window par IP (+ UUID) sur les `submit` et le webhook.

### 🟢 F6 — Super-admin dépend d'un env optionnel (fail-open sur suppression)
**Fichier :** `src/Service/Compte/UserService.php` (`estSuperAdmin`)
Si `DIAG_EMAIL` est vide, la garde « le super-admin ne peut pas être supprimé » devient inopérante — combiné à M4, risque de lock-out. Les écrans diag/purge, eux, restent fail-closed. **Correctif :** valider `DIAG_EMAIL` non vide au boot, ou matérialiser le super-admin par un flag/rôle en base.

### 🟢 F7 — Durcissements infra divers
- `docker/php/php.prod.ini` : `expose_php = On` fuite la version PHP → `expose_php = Off`.
- `docker-compose.yml` (dev) : Postgres en `5432:5432` avec creds par défaut + Mailpit `ACCEPT_ANY` → binder sur `127.0.0.1`, documenter « dev only ». Sans impact prod (compose séparé).
- HTTPS non forcé côté app (repose entièrement sur NPM, config non versionnée) → vérifier redirection 80→443 + HSTS (E1).

### 🟢 F8 — Incohérence credentials Google
**Fichier :** `src/Service/Drive/DriveUploaderService.php:143-151`
Le code traite `GOOGLE_DRIVE_CREDENTIALS_JSON` comme un **chemin de fichier**, mais `.env.*.example` montre un JSON inline. Si la prod fournit du JSON inline, `file_exists()` échoue → Drive tombe en panne silencieuse (PDF restent en local). Pas une fuite (erreurs non loggées avec le contenu). **Correctif :** imposer une seule convention, ou détecter `str_starts_with(trim($v), '{')`.

---

## Corrections appliquées le 2026-08-10

Vérifiées par 474 tests + PHPStan (0 erreur) + `lint:container` + `nginx -t` au vert.

- **E1** — en-têtes `X-Frame-Options`, `X-Content-Type-Options`, `Referrer-Policy`, HSTS et une CSP ajoutés dans `docker/nginx/nginx.prod.conf` (avec répétition de `nosniff` dans le bloc des assets, `add_header` n'héritant pas).
- **E2** — `login_throttling` (5 tentatives / 15 min) sur le firewall `main` ; `symfony/rate-limiter` installé.
- **M1** — `symfony/html-sanitizer` installé ; nouveau `RichTextSanitizer` (whitelist Quill) appliqué à l'écriture dans `DocumentSignableService::hydrater` et `SeasonService::updateAttestationCleText`. *Reste à faire : Quill 1.3.7 → 2.x (défense en profondeur côté éditeur), et une passe de sanitisation des lignes déjà stockées en base.*
- **M2** — nouveau `SignatureImageValidator` : n'accepte que `data:image/png;base64,`, décode le base64 et vérifie les octets PNG + dimensions bornées. Câblé dans `SignatureCollector`, `AttestationCleController` et `AttestationTransportRequestFactory` (dé-duplique 3 validations divergentes).
- **M3** — `CsrfGuard::valider('import_xlsx')` en tête de `ImportController::process` + champ `_token` dans le template d'import.

## Plan d'action restant avant mise en prod

**Reliquats des correctifs (défense en profondeur) :**
1. Migrer Quill 1.3.7 → 2.x et sanitiser les contenus HTML déjà en base (complète M1).
2. Ajouter `composer audit` en contrôle CI bloquant (empêche D1 de re-dériver).

**Fortement recommandé (semaines suivantes) :**
6. M4 — vrai `ROLE_ADMIN` si des comptes à privilèges réduits doivent exister
7. M5 — chiffrement des dumps + verrouillage du partage Drive
8. M6 / M7 — `trusted_proxies` restreint, `x-forwarded-host` retiré, cookies de session explicites

**Durcissement (backlog) :** F1 à F8.

---

*Audit réalisé par analyse statique du code. Il ne remplace pas un test d'intrusion sur l'environnement de production réel (config NPM/TLS, permissions Drive, exposition réseau), qui reste recommandé une fois E1–E2 en place.*
