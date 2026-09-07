#!/usr/bin/env bash
#
# Despliegue de ACTUALIZACIONES del helpdesk en un servidor SSH (sin Plesk).
# El frontend (React) va YA COMPILADO en public/ dentro del repo, así que el
# servidor NO necesita Node ni compilar: basta con git pull.
#
# Uso (desde la raíz del proyecto en el servidor):
#   ./deploy.sh
#
# Primera instalación: ver .env.production.example (no uses este script para eso).

set -euo pipefail
cd "$(dirname "$0")"

echo "→ Trayendo cambios (git pull)…"
git pull --ff-only origin main

echo "→ Dependencias PHP (composer)…"
composer install --no-dev --optimize-autoloader --no-interaction

echo "→ Migraciones de base de datos…"
php artisan migrate --force

# Roles y permisos: idempotente (registra permisos nuevos y hace backfill).
# Seguro relanzarlo en cada despliegue; aplica los cambios de RBAC si los hubiera.
echo "→ Sincronizando roles y permisos…"
php artisan db:seed --class=RolesPermissionsSeeder --force

echo "→ Limpiando y cacheando (config/rutas/vistas)…"
php artisan optimize:clear
php artisan optimize

# Si OPcache está con validate_timestamps=0 (recomendado en prod por rendimiento),
# el código nuevo NO se carga hasta recargar PHP-FPM. Descomenta la línea que
# corresponda a tu versión/servicio de PHP:
# sudo systemctl reload php8.2-fpm

echo "✓ Despliegue completado · $(git rev-parse --short HEAD) ($(git log -1 --format=%s))"
