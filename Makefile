COMPOSE      = docker compose
COMPOSE_PROD = docker compose -f docker-compose.prod.yml

.PHONY: up down build bash db-migrate db-reset assets watch cache-clear logs setup-dirs \
        test test-setup \
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
prod-deploy: prod-build prod-up prod-backup prod-migrate
	@echo "Déploiement terminé."

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
