#!/usr/bin/env bash
#
# Activa el TIEMPO REAL (Reverb) en el servidor. Idempotente: se puede relanzar.
#
#   REVERB_KEY=<clave pública del .env.production local> ./deploy/reverb/instalar.sh
#
# Qué hace:
#   1. .env: BROADCAST_CONNECTION=reverb y las REVERB_*. El SECRETO se genera aquí y no
#      sale del servidor; la clave pública tiene que ser la MISMA que la del build.
#   2. Servicio systemd «helpdesk-reverb» (127.0.0.1:8080, se reinicia solo).
#   3. nginx: /app/ → Reverb dentro del bloque 443 (comprueba con nginx -t antes de recargar;
#      si falla, restaura la copia y no toca nada).
# Para volver atrás: BROADCAST_CONNECTION=log en .env, «systemctl disable --now
# helpdesk-reverb» y quitar el include de nginx. La app sigue funcionando por polling.

set -euo pipefail
cd "$(dirname "$0")/../.."
APP="$(pwd)"
: "${REVERB_KEY:?Falta REVERB_KEY (la clave pública del .env.production con el que se compiló)}"

ENV="$APP/.env"
cp -p "$ENV" "$ENV.antes-reverb.$(date +%Y%m%d%H%M%S)"

poner() {  # poner CLAVE VALOR  → sustituye o añade en .env
  if grep -qE "^$1=" "$ENV"; then sed -i "s|^$1=.*|$1=$2|" "$ENV"; else echo "$1=$2" >> "$ENV"; fi
}
echo "→ .env"
poner BROADCAST_CONNECTION reverb
poner REVERB_APP_ID helpdesk
poner REVERB_APP_KEY "$REVERB_KEY"
grep -qE '^REVERB_APP_SECRET=.+' "$ENV" || poner REVERB_APP_SECRET "$(openssl rand -hex 24)"
# El servidor publica eventos en Reverb directo por la red interna (sin TLS ni nginx).
poner REVERB_HOST 127.0.0.1
poner REVERB_PORT 8080
poner REVERB_SCHEME http
poner REVERB_SERVER_HOST 127.0.0.1
poner REVERB_SERVER_PORT 8080

# La config en caché aún no tiene las REVERB_* nuevas: regenerarla ANTES de arrancar
# Reverb (si no, rechaza la clave con «Application does not exist»).
php artisan optimize:clear >/dev/null && php artisan optimize >/dev/null

echo "→ servicio systemd"
install -m 644 "$APP/deploy/reverb/helpdesk-reverb.service" /etc/systemd/system/helpdesk-reverb.service
systemctl daemon-reload
systemctl enable --now helpdesk-reverb
systemctl restart helpdesk-reverb

echo "→ nginx"
# El archivo real (sites-enabled suele ser un enlace: «sed -i» lo convertiría en copia).
SITIO="$(readlink -f /etc/nginx/sites-enabled/helpdesk)"
SNIP=/etc/nginx/snippets/helpdesk-reverb.conf
COPIA="/root/nginx-helpdesk.antes-reverb.$(date +%Y%m%d%H%M%S)"
cp -p "$SITIO" "$COPIA"
install -m 644 "$APP/deploy/reverb/nginx-reverb.conf" "$SNIP"
if ! grep -q "helpdesk-reverb.conf" "$SITIO"; then
  # Dentro del bloque 443: justo antes de su «listen 443 ssl».
  sed -i "0,/^\s*listen 443 ssl/s||    include $SNIP;\n&|" "$SITIO"
fi
if nginx -t; then
  systemctl reload nginx
else
  echo "✗ nginx -t falló: se restaura la configuración anterior ($COPIA)" >&2
  cp -p "$COPIA" "$SITIO"
  rm -f "$SNIP"
  exit 1
fi

php artisan optimize:clear >/dev/null && php artisan optimize >/dev/null
systemctl reload php8.3-fpm
echo "✓ Reverb activo: $(systemctl is-active helpdesk-reverb) · escuchando en 127.0.0.1:8080"
