COMPOSE      = docker compose
COMPOSE_PROD = docker compose -f docker-compose.prod.yml

.PHONY: up down build bash db-migrate db-reset assets watch cache-clear logs setup-dirs \
        prod-up prod-down prod-build prod-deploy prod-migrate prod-bash prod-logs \
        prod-backup prod-backup-list prod-restore

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

# ── Production (VPS) ─────────────────────────────────────────────────────────

prod-build:
	$(COMPOSE_PROD) build --no-cache

prod-up:
	$(COMPOSE_PROD) up -d

prod-down:
	$(COMPOSE_PROD) down

prod-deploy: prod-build prod-up prod-migrate
	@echo "Déploiement terminé."

prod-migrate:
	$(COMPOSE_PROD) exec php php bin/console doctrine:migrations:migrate --no-interaction

prod-bash:
	$(COMPOSE_PROD) exec php sh

prod-logs:
	$(COMPOSE_PROD) logs -f

# ── Sauvegardes BDD ──────────────────────────────────────────────────────────
# Cron nightly sur le VPS (crontab -e) :
#   0 2 * * * cd /chemin/vers/CoSync && make prod-backup >> ~/backups/cosync/backup.log 2>&1

BACKUP_DIR = $(HOME)/backups/cosync

prod-backup:
	mkdir -p $(BACKUP_DIR)
	$(COMPOSE_PROD) exec -T database sh -c 'pg_dump -U $$POSTGRES_USER $$POSTGRES_DB' \
		| gzip > $(BACKUP_DIR)/backup_$(shell date +%Y%m%d_%H%M%S).sql.gz
	find $(BACKUP_DIR) -name "backup_*.sql.gz" -mtime +30 -delete
	@echo "[backup] Sauvegarde créée dans $(BACKUP_DIR)/"

prod-backup-list:
	@ls -lh $(BACKUP_DIR)/backup_*.sql.gz 2>/dev/null || echo "Aucune sauvegarde trouvée dans $(BACKUP_DIR)/"

prod-restore:
	@test -n "$(FILE)" || (echo "Usage : make prod-restore FILE=~/backups/cosync/backup_YYYYMMDD_HHMMSS.sql.gz" && exit 1)
	@echo "Restauration depuis $(FILE)..."
	gunzip -c $(FILE) | $(COMPOSE_PROD) exec -T database sh -c 'psql -U $$POSTGRES_USER $$POSTGRES_DB'
	@echo "Restauration terminée."
