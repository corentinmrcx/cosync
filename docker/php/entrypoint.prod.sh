#!/bin/sh
set -e

# ── Garde-fou d'environnement ────────────────────────────────────────────────
# L'image embarque le .env committé (APP_ENV=dev, APP_SECRET=change_me_in_env_local,
# POSTGRES_PASSWORD=cosync) : Symfony en a besoin pour booter. Sans ce contrôle, un
# .env.prod absent, mal nommé ou incomplet ferait démarrer l'application en mode dev
# avec un secret public — profiler exposé, stack traces publiques — sans aucune erreur.
fail() {
    echo "[entrypoint] ERREUR DE CONFIGURATION : $1" >&2
    echo "[entrypoint] Vérifie .env.prod sur le VPS (modèle : .env.prod.example)." >&2
    exit 1
}

[ "$APP_ENV" = "prod" ] || fail "APP_ENV vaut '${APP_ENV:-<vide>}' au lieu de 'prod'."

case "$APP_SECRET" in
    ""|change_me_in_env_local) fail "APP_SECRET absent ou laissé à sa valeur par défaut (openssl rand -hex 32)." ;;
esac

case "$DATABASE_URL" in
    "")            fail "DATABASE_URL absente." ;;
    *POSTGRES_PASSWORD*) fail "DATABASE_URL contient encore le placeholder 'POSTGRES_PASSWORD'." ;;
esac

[ -n "$DEFAULT_URI" ] || fail "DEFAULT_URI absente (nécessaire aux liens des mails et aux retours HelloAsso)."

echo "[entrypoint] Configuration de production validée."

# ── Assets ───────────────────────────────────────────────────────────────────
# Synchronise public/ vers le named volume partagé avec Nginx. Le contenu de build/
# est purgé d'abord : sans ça, les assets hashés des builds précédents s'accumulent
# indéfiniment dans le volume.
echo "[entrypoint] Synchronisation des assets publics..."
rm -rf /var/www/html/public/build
cp -rp /var/www/public_template/. /var/www/html/public/
echo "[entrypoint] Assets synchronisés."

# ── Répertoires var/ ─────────────────────────────────────────────────────────
# Les volumes Docker sont montés vides en root à la première création.
mkdir -p var/cache/prod var/log var/locks var/pdfs var/signatures var/backups
chown -R www-data:www-data var/
echo "[entrypoint] Répertoires var/ initialisés."

# ── Préchauffage du cache ────────────────────────────────────────────────────
# Sans ça la première requête HTTP compile tout le conteneur (lente), et une erreur
# de compilation n'apparaît qu'au premier visiteur au lieu du démarrage.
echo "[entrypoint] Préchauffage du cache..."
su-exec www-data php bin/console cache:warmup --env=prod --no-debug
echo "[entrypoint] Cache prêt."

exec php-fpm
