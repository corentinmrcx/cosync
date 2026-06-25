#!/bin/sh
set -e

# Répertoires var/ nécessaires au boot de la console (le conteneur a son propre
# var/cache ; var/pdfs est un volume partagé avec le conteneur php).
mkdir -p /var/www/html/var/cache /var/www/html/var/log /var/www/html/var/pdfs

# Reprise des uploads Drive en attente, toutes les 15 minutes.
echo "*/15 * * * * php /var/www/html/bin/console app:drive-retry-upload 2>&1" | crontab -

# Lance crond au premier plan (les logs vont dans stderr du conteneur)
exec crond -f -l 2
