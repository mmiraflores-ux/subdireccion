#!/bin/sh
set -e

# Render, Koyeb, Railway, etc. indican el puerto en $PORT.
PORT="${PORT:-8080}"
sed -ri "s/^Listen .*/Listen ${PORT}/" /etc/apache2/ports.conf
sed -ri "s/<VirtualHost \*:[0-9]+>/<VirtualHost *:${PORT}>/" /etc/apache2/sites-available/000-default.conf

# Crea o actualiza las tablas automáticamente (no hace nada si ya están al día).
if [ "${AUTO_MIGRATE:-1}" = "1" ]; then
  php /var/www/app/migrate.php
fi

exec apache2-foreground
