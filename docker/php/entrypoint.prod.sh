#!/bin/sh
set -e

# Synchronise public/ vers le named volume partagé avec Nginx.
# Nécessaire à chaque démarrage pour que les assets du nouveau build
# remplacent ceux de l'image précédente dans le volume.
echo "[entrypoint] Synchronisation des assets publics..."
cp -rp /var/www/public_template/. /var/www/html/public/
echo "[entrypoint] Assets synchronisés."

exec php-fpm
