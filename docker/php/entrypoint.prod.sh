#!/bin/sh
set -e

# Synchronise public/ vers le named volume partagé avec Nginx.
# Nécessaire à chaque démarrage pour que les assets du nouveau build
# remplacent ceux de l'image précédente dans le volume.
echo "[entrypoint] Synchronisation des assets publics..."
cp -rp /var/www/public_template/. /var/www/html/public/
echo "[entrypoint] Assets synchronisés."

# Crée les répertoires var/ nécessaires avec les bonnes permissions www-data.
# Les volumes Docker sont montés vides en root à la première création.
mkdir -p var/locks var/pdfs var/signatures var/pdf_temp var/cache var/log
chown -R www-data:www-data var/locks var/pdfs var/signatures var/pdf_temp var/cache var/log
echo "[entrypoint] Répertoires var/ initialisés."

exec php-fpm
