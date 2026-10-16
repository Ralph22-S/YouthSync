#!/bin/sh
set -e
PORT="${PORT:-8080}"
a2dismod mpm_event mpm_worker >/dev/null 2>&1 || true
a2enmod mpm_prefork >/dev/null 2>&1 || true
{
  echo "ServerName localhost"
  echo "Listen 0.0.0.0:${PORT}"
  if [ "$PORT" != "8080" ]; then
    echo "Listen 0.0.0.0:8080"
  fi
} > /etc/apache2/ports.conf
sed -i 's/<VirtualHost \*:.*>/<VirtualHost *:*>/' /etc/apache2/sites-available/000-default.conf
echo "YouthSync Apache listening on ${PORT}"
exec apache2-foreground
