COMPOSE      = docker compose
COMPOSE_PROD = docker compose -f docker-compose.prod.yml

.PHONY: up down build bash db-migrate db-reset assets watch cache-clear logs setup-dirs \
        test test-setup \
        lint lint-php lint-css lint-js fix stan check \
        prod-up prod-down prod-build prod-deploy prod-migrate prod-migrate-dry prod-bash prod-db prod-logs \
        prod-backup prod-backup-list prod-restore prod-init

# ── Développement ────────────────────────────────────────────────────────────

up:
	$(COMPOSE) up -d

down:
	$(COMPOSE) down

build:
	$(COMPOSE) build --no-cache

bash:
	$(COMPOSE) exec php sh

db-migrate:
	$(COMPOSE) exec php php bin/console doctrine:migrations:migrate --no-interaction

db-reset:
	$(COMPOSE) exec php php bin/console doctrine:database:drop --force
	$(COMPOSE) exec php php bin/console doctrine:database:create
	$(COMPOSE) exec php php bin/console doctrine:migrations:migrate --no-interaction

assets:
	npm run build

watch:
	npm run watch

drive-retry:
	$(COMPOSE) exec php php bin/console app:drive-retry-upload

cache-clear:
	$(COMPOSE) exec php php bin/console cache:clear

logs:
	$(COMPOSE) logs -f

setup-dirs:
	$(COMPOSE) exec --user root php mkdir -p /var/www/html/var/locks
	$(COMPOSE) exec --user root php chown www-data:www-data /var/www/html/var/locks

# ── Tests ────────────────────────────────────────────────────────────────────
# La base de test est distincte (suffixe _test) et doit être migrée, pas créée
# par schema:update : les tests tournent donc sur le même schéma que la prod.

test-setup:
	$(COMPOSE) exec php php bin/console doctrine:database:create --env=test --if-not-exists
	$(COMPOSE) exec php php bin/console doctrine:migrations:migrate --env=test --no-interaction

test:
	$(COMPOSE) exec php vendor/bin/phpunit

# ── Qualité ──────────────────────────────────────────────────────────────────
# Ces contrôles sont ceux que le CI rejoue sur chaque push : les faire passer en
# local évite un aller-retour. `make check` = tout ce que la CI vérifie.

stan:
	$(COMPOSE) exec -T php php -d memory_limit=1G vendor/bin/phpstan analyse --no-progress

lint-php:
	$(COMPOSE) exec -T php vendor/bin/php-cs-fixer fix --dry-run --diff --show-progress=none

lint-css:
	npm run lint:css

lint-js:
	npm run lint:js

lint: lint-php lint-css lint-js

fix:
	$(COMPOSE) exec -T php vendor/bin/php-cs-fixer fix --show-progress=none
	npm run fix:css
	npm run fix:js

check: test stan lint

# ── Production (VPS) ─────────────────────────────────────────────────────────

prod-init: prod-build prod-up prod-migrate prod-backup
	@echo ""
	@echo "========================================"
	@echo "  CoSync — Initialisation prod terminée"
	@echo "========================================"
	@echo ""
	@echo "La sauvegarde nightly (02h30) est planifiée dans le conteneur cosync_cron,"
	@echo "avec copie sur le Drive du club. Aucune crontab à configurer sur le VPS."
	@echo "Vérifie avec :  docker logs cosync_cron"
	@echo ""
	@echo "Fais une répétition de restauration avant l'ouverture aux licenciés :"
	@echo "  make prod-backup-list  puis  make prod-restore FILE=backup_....sql.gz"
	@echo ""

prod-build:
	$(COMPOSE_PROD) build --no-cache

prod-up:
	$(COMPOSE_PROD) up -d

prod-down:
	$(COMPOSE_PROD) down

# Le dump précède la migration : la prod contient des données irremplaçables et
# PostgreSQL annule une migration qui plante, mais pas une migration qui « réussit »
# en perdant des données (cf. CLAUDE.md §13).
prod-deploy: prod-build prod-up prod-nginx-reload prod-backup prod-migrate
	@echo "Déploiement terminé."

# nginx.prod.conf est monté en bind mount et l'image nginx ne bouge jamais : `up -d` n'a
# donc aucune raison de recréer le conteneur. Le fichier modifié est bien visible à
# l'intérieur, mais le processus nginx tourne toujours avec la conf lue à son démarrage —
# un changement d'en-tête ou de route ne prend effet qu'après ce rechargement. Sans cette
# étape, un correctif de CSP peut être déployé trois fois sans jamais atteindre personne.
prod-nginx-reload:
	$(COMPOSE_PROD) exec nginx nginx -t
	$(COMPOSE_PROD) exec nginx nginx -s reload
	@echo "Configuration Nginx rechargée."

prod-migrate:
	$(COMPOSE_PROD) exec php php bin/console doctrine:migrations:migrate --no-interaction

# Affiche le SQL sans l'appliquer — à lire avant tout déploiement de migration
prod-migrate-dry:
	$(COMPOSE_PROD) exec php php bin/console doctrine:migrations:migrate --no-interaction --dry-run

prod-bash:
	$(COMPOSE_PROD) exec php sh

prod-db:
	$(COMPOSE_PROD) exec database sh -c 'psql -U $$POSTGRES_USER $$POSTGRES_DB'

prod-logs:
	$(COMPOSE_PROD) logs -f

# ── Sauvegardes BDD ──────────────────────────────────────────────────────────
# Automatique : app:db:backup tourne toutes les nuits à 02h30 dans le conteneur
# cosync_cron (dump local dans le volume cosync_backups + copie sur le Drive du club).
# Les cibles ci-dessous sont là pour les dumps manuels et la restauration.

prod-backup:
	$(COMPOSE_PROD) exec -T php php bin/console app:db:backup

prod-backup-list:
	@$(COMPOSE_PROD) exec -T php sh -c 'ls -lh var/backups/backup_*.sql.gz 2>/dev/null' \
		|| echo "Aucune sauvegarde locale. Les copies off-site sont sur le Drive, dossier Sauvegardes/."

# FILE = nom du fichier tel que listé par prod-backup-list (sans chemin)
prod-restore:
	@test -n "$(FILE)" || (echo "Usage : make prod-restore FILE=backup_YYYYMMDD_HHMMSS.sql.gz" && exit 1)
	@echo "⚠️  Cette opération ÉCRASE les données actuelles de la base de production."
	@printf "Taper 'oui' pour confirmer : " && read reponse && [ "$$reponse" = "oui" ]
	$(COMPOSE_PROD) exec -T php sh -c 'gunzip -c var/backups/$(FILE)' \
		| $(COMPOSE_PROD) exec -T database sh -c 'psql -U $$POSTGRES_USER $$POSTGRES_DB'
	@echo "Restauration terminée."
