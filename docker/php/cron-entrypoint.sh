#!/bin/sh
set -e

# Répertoires var/ nécessaires au boot de la console (le conteneur a son propre
# var/cache ; var/pdfs et var/backups sont des volumes partagés avec le conteneur php).
mkdir -p /var/www/html/var/cache /var/www/html/var/log /var/www/html/var/pdfs /var/www/html/var/backups
chown -R www-data:www-data /var/www/html/var

# crond tourne en root ; les commandes s'exécutent en www-data pour ne pas semer
# dans var/ des fichiers que php-fpm ne pourra plus écrire.
CONSOLE="su-exec www-data php /var/www/html/bin/console"

crontab - <<EOF
# Reprise des uploads Drive en attente (PDF signés restés en local après une panne Google)
*/15 * * * * $CONSOLE app:drive-retry-upload 2>&1

# Filet de sécurité paiement : rattrape les encaissements HelloAsso dont la
# notification n'est jamais arrivée. Sans ça, le club encaisse sans que la
# licence passe en validée.
*/30 * * * * $CONSOLE app:helloasso:sync-paiements 2>&1

# Relance des licences non soldées, une fois par jour à heure ouvrable — un mail du
# club horodaté à 3 h du matin part en indésirable. Ne fait rien tant que l'admin n'a
# pas allumé l'interrupteur dans /admin/club/relances.
0 9 * * * $CONSOLE app:relances:envoyer 2>&1

# Calendrier des matchs à domicile, aligné sur celui du district avant l'ouverture du
# bureau : le planning consulté dans la journée est celui de la nuit. Ne fait rien tant
# que la synchronisation n'a pas été activée dans les réglages du planning — ni si l'API
# fédérale refuse les appels venant du serveur, ce qui peut arriver.
0 7 * * * $CONSOLE app:planning:sync-fff 2>&1

# Sauvegarde nightly de la base (locale + copie sur le Drive du club)
30 2 * * * $CONSOLE app:db:backup 2>&1
EOF

echo "[cron] Tâches planifiées :"
crontab -l

# Lance crond au premier plan (les logs vont dans stderr du conteneur)
exec crond -f -l 2
