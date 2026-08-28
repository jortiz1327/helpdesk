# Despliegue del Helpdesk en Plesk

App = Laravel 12 (API + motor) + SPA React ya **compilada dentro de `public/`**
(portal del cliente en `/`, app de agentes en `/agentes`). No hay que compilar nada
en el servidor: el `public/` ya lleva `index.html`, `assets/` y las fuentes Lato.

Objetivo de esta guía: dejarlo funcionando para que **un responsable lo pruebe**, con
una **base de datos nueva** (sin datos de prueba) y usando el **instalador web**
(sin tocar la consola).

---

## 0. Antes de empezar (en local)

```bash
cd frontend && npm run build      # regenera public/ con la última versión
```

Empaqueta el proyecto en un zip **INCLUYENDO** `vendor/` y `public/`, y **EXCLUYENDO**:
`node_modules/`, `frontend/node_modules/`, `.git/`, `.env` (el de local), `storage/logs/*`.

> Se sube `vendor/` para no necesitar Composer en el servidor.

---

## 1. Crear el dominio/subdominio en Plesk

- **PHP 8.2 o 8.3** (FPM): *Sitios web y dominios → PHP → versión y "FPM".*
- Activa las extensiones: `pdo_mysql`, `mbstring`, `openssl`, `fileinfo`, `bcmath`,
  `curl`, `gd` y **`imap`** (esta última es imprescindible para RECIBIR correos).
- **SSL** con Let's Encrypt (el portal manda enlaces firmados con `APP_URL`, tiene que
  ser el dominio `https`).
- Deja el servidor web en **Apache** (por defecto): el `public/.htaccess` (rewrite +
  cabeceras anti-caché) lo necesita. Si el dominio está en «solo nginx», hay que
  traducir esas reglas a nginx.

## 2. Subir el código y apuntar el «document root» ⚠️

Sube y descomprime el zip (p. ej. en `httpdocs/`).

**LA TRAMPA Nº 1 de Laravel en Plesk:** el *document root* NO es la carpeta del
proyecto, es **`public/`**.

*Sitios web y dominios → [dominio] → Configuración de hosting → Raíz de documentos* →
ponla en `httpdocs/public` (o donde esté el `public/` del proyecto).

> Alternativa: usar el toolkit de Laravel de Plesk (que ya deja el doc root en `public`).

## 3. Crear la base de datos

*Bases de datos → Añadir base de datos* → crea la BD y un usuario. Apunta nombre,
usuario y contraseña. (No importes nada: el instalador crea las tablas.)

## 4. Crear el `.env`

Copia **`.env.production.example`** a **`.env`** (por el Gestor de archivos de Plesk) y
rellena:

- `APP_URL=https://TU-DOMINIO`
- `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` (los del paso 3)

Deja `APP_KEY=` vacío: lo genera el instalador.

## 5. Permisos

`storage/` y `bootstrap/cache/` con **escritura** (en Plesk normalmente ya lo están,
porque PHP-FPM corre como el usuario de la suscripción). Si el instalador se queja,
dales permiso desde el Gestor de archivos.

## 6. Instalador web 🚀

Entra a **`https://TU-DOMINIO/install.php`**.

Verás una lista de comprobaciones (verde/rojo). Si todo está en verde, pulsa
**«Instalar ahora»**. Ejecuta por ti:

- `key:generate` (genera la `APP_KEY`)
- `migrate` (crea las tablas **y siembra** FAQ + Centro de atención)
- `db:seed` (roles, categorías, ajustes y el **usuario administrador**)
- `config:cache`

Al terminar, **entra con `admin@aemegroup.com` / `admin1234`** en `/agentes` y **cambia
la contraseña** (Agentes → tu cuenta).

> ⚠️ **BORRA `install.php`** en cuanto acabe (el propio instalador tiene un botón para
> autoeliminarse). Se auto-bloquea con `storage/installed.lock`, pero no lo dejes ahí.

## 7. Cron (cada minuto)

Para que funcionen el **correo entrante**, el **SLA**, los **cierres automáticos** y el
**reparto por turnos**, añade una tarea programada:

*Sitios web y dominios → [dominio] → Tareas programadas → Añadir*:

```
Comando:   php /var/www/vhosts/TU-DOMINIO/httpdocs/artisan schedule:run
Frecuencia: cada minuto  (* * * * *)
```

(Ajusta la ruta a donde hayas subido el proyecto.)

## 8. Configurar el buzón de correo (dentro de la app)

Agentes → **Configuración → «Buzón y envío»**: pon el IMAP/SMTP del correo de soporte y
pulsa «Probar conexión». Esto es lo que envía los **códigos** del portal y los avisos, y
lo que convierte los **correos entrantes en tickets**.

---

## Actualizar una instalación YA en marcha (redeploy con datos reales)

> Esto es para subir cambios a un Plesk que ya está funcionando **sin perder datos**.
> (Si es la PRIMERA instalación, sigue los pasos 1-8 de arriba: el instalador ya hace
> `migrate` y `db:seed` por ti.)

Con el **despliegue Git de Plesk** (Sitios web y dominios → Git) NO hace falta zip ni
SSH: Plesk hace el `git pull`, el `composer install` y ejecuta un **script de despliegue**
con las tareas de Laravel. `vendor/` va por `composer install` (no está en el repo) y el
frontend ya va COMPILADO en `public/assets` (sí está en el repo) → no se compila nada.

**Regla de oro: copia de seguridad ANTES de migrar.**

1. **Backup** — la BD (backup de Plesk o `mysqldump`) y el `.env`. Si algo sale mal,
   restauras y no ha pasado nada.

2. **Configurar los «Pasos de despliegue» de Plesk** (setup de UNA vez):
   - ✅ **1. Activar modo mantenimiento** · ✅ **2-3. Recuperar/desplegar el código de Git**
   - ✅ **4. Instalar dependencias `composer.json`** (trae `vendor/`, imprescindible).
   - ☐ **5. Instalar dependencias `package.json`** → **DESMARCADO** (el `public/` ya va
     compilado en el repo; no hay que tocar npm en el servidor).
   - ✅ **6. Ejecutar script de despliegue** → **MÁRCALO** y en «Editar script» pon:
     ```bash
     php artisan migrate --force
     php artisan config:cache
     php artisan route:cache
     php artisan cache:clear
     ```
     > ⚠️ Si el paso 6 está desmarcado, Plesk sube el código pero **NO migra** → la app
     > peta al buscar tablas/columnas nuevas. Es el error más fácil de cometer.
     > Si «php» no se encuentra, usa la ruta del PHP del dominio (PHP settings), p. ej.
     > `/opt/plesk/php/8.2/bin/php artisan migrate --force`.
   - ✅ **7. Desactivar modo mantenimiento**.

3. **Desplegar** — en modo Manual, pulsa **«Desplegar»** (o push a la rama si está en
   Automático). Plesk hace pull + composer + el script → migra y limpia caché solo.

   **Tanda actual (edición correo/web, sin IA) — SOLO código, sin migraciones ni
   cambios de BD.** Basta el pull + `config:cache`/`cache:clear` del script; `migrate`
   corre pero no encuentra nada nuevo. Qué entra:
   - Limpieza de la variante (sin IA, WhatsApp solo en Campañas): fix del webhook
     (`$ticketId` indefinido) y código muerto retirado.
   - **Configuración**: menú reorganizado (título neutro «Configuración», grupo
     «Plataforma» = Funciones + Seguridad + Tareas). La config de **WhatsApp deja de
     estar en este hub** y vive en **Campañas → Ajustes**.
   - Nuevo indicador **«Recogida de correo»** en *Configuración → Funciones* (semáforo
     por `last_check_at`: verde recién recogido / rojo parada / ámbar sin recoger aún).

   > Para futuras tandas CON migraciones, aquí se listan. Ejemplos ya desplegados:
   > `ticket_snooze`, `scheduled_replies`, `ticket_perf_indexes`, `drop_ai_agent_results`.

4. **Permisos** (solo si cambiaste roles; idempotente): añade al script, si lo necesitas,
   `php artisan db:seed --class=RolesPermissionsSeeder --force`. ⚠️ NUNCA `db:seed` a
   secas (recrea admin/categorías). En esta sesión no se añadieron permisos: puedes omitirlo.

5. **Los crones nuevos entran solos** — `tickets:wake` (despierta pospuestos) y
   `replies:send` (respuestas programadas) ya están en el planificador. Con la tarea
   `schedule:run` cada minuto (paso 7 del install) funcionan sin tocar nada. Verifica en
   *Agentes → Configuración → Tareas programadas* que el planificador está corriendo.

6. **Verificar la zona horaria** (pendiente de esta sesión). Por SSH (o un script puntual):
   ```bash
   php artisan tinker --execute="echo now().' | '.DB::selectOne('SELECT NOW() n')->n;"
   ```
   Si las **dos horas coinciden**, todo bien. Si **difieren**, el servidor está en otra
   zona que PHP (Europe/Madrid) y hay que alinear MySQL — avisa antes de tocar.

7. **Smoke test** — entra en `/agentes` y comprueba: abrir un ticket largo (botón
   «Ver mensajes anteriores»), *Configuración → Funciones* (mover un interruptor y ver el
   semáforo **«Recogida de correo»** en el grupo Correo — debería estar 🟢 si el cron
   corre), posponer un ticket, y el portal `/` con las banderas de idioma.

> Nota: el buzón de correo se configura **dentro de la app** (*Configuración → Correo*),
> no en el `.env`. En esta edición **no hay IA**; el WhatsApp (solo Campañas) y su App
> Secret se configuran en **Campañas → Ajustes**, no en el hub de Configuración.

---

## Comprobación rápida tras instalar

- `https://TU-DOMINIO/` → **portal del cliente** (buscador, FAQ, Centro de atención).
- Crear una incidencia sin código → se abre el ticket al instante.
- `https://TU-DOMINIO/agentes` → **login de agentes** (admin@aemegroup.com / admin1234).

## Notas

- **`APP_KEY`**: si algún día reinstalas o migras datos, mantén la MISMA `APP_KEY`, o las
  contraseñas del buzón (guardadas cifradas) dejarán de descifrarse.
- **Enlaces firmados** (adjuntos, imágenes del correo): dependen de `APP_URL`. Si cambias
  de dominio, actualiza `APP_URL` y repite el `config:cache` (o borra `bootstrap/cache/config.php`).
- **Zona horaria** fijada a `Europe/Madrid` (el motor usa horarios para SLA y turnos).
- Si tocas el `.env` después de instalar, vuelve a cachear: borra `bootstrap/cache/config.php`
  o entra por SSH y `php artisan config:cache`.

## Seguridad (verificar antes de abrir al público)

- **`APP_DEBUG=false`** y **`APP_ENV=production`** en el `.env` (nunca dejar `debug` en
  producción: expone trazas y datos). El `.env.production.example` ya viene así.
- **`LOG_LEVEL=error`**, **`SESSION_ENCRYPT=true`**, **`SESSION_SECURE_COOKIE=true`**
  (ya en el ejemplo de producción).
- **HTTPS obligatorio**: con el dominio en `https`, las cabeceras de seguridad activan el
  HSTS automáticamente (`SecurityHeaders`).
- **Cabeceras de seguridad**: van solas en toda respuesta (middleware `SecurityHeaders`):
  X-Frame-Options, X-Content-Type-Options, Referrer-Policy, Permissions-Policy, HSTS y CSP.
  Si algún día se añade un recurso externo (otra fuente, un CDN…), hay que permitirlo en la
  CSP del middleware o el navegador lo bloqueará.
- **Rate-limiting**: el login tiene freno por cuenta+IP; el webhook de WhatsApp y el portal
  van limitados por ruta. No hace falta tocar nada.
- **Contraseñas**: mínimo 8 caracteres (alta, edición y cambio propio).
- **Firma del webhook**: pon el **App Secret** del número en **Campañas → Ajustes** para
  que solo se procesen eventos firmados por Meta.
- **Borra `install.php`** del servidor una vez instalado (deja de ser necesario y es una
  puerta abierta).
