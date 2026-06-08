#!/bin/sh
set -e

# Installe le crontab
echo "0 6 * * * php /var/www/html/bin/console app:drive-retry-upload 2>&1" | crontab -

# Lance crond au premier plan (les logs vont dans stderr du conteneur)
exec crond -f -l 2
