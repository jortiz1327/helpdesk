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

**Regla de oro: copia de seguridad ANTES de migrar.**

1. **Backup** — la BD (backup de Plesk o `mysqldump`) y el `.env`. Si algo sale mal,
   restauras y no ha pasado nada.

2. **Subir el código nuevo** — en local, regenera `public/` y empaqueta:
   ```bash
   cd frontend && npm run build      # deja public/ con la última versión
   ```
   Descomprime el zip **SOBRE** la instalación (o `git pull` si el server tiene el repo).
   ⚠️ **NO pises** el `.env` de producción, `storage/` ni `bootstrap/cache/`.
   El `public/` ya va compilado: **no se compila nada en el servidor**.

3. **Migrar** (por SSH, en la raíz del proyecto):
   ```bash
   php artisan migrate --force
   ```
   El `--force` es obligatorio en producción (si no, pregunta y aborta). Aplica lo
   pendiente. En esta tanda entran, entre otras:
   - `ticket_snooze` — posponer tickets (columnas nuevas en tickets y users).
   - `scheduled_replies` — respuestas programadas (tabla nueva).
   - `ticket_perf_indexes` — 2 índices compuestos (en una tabla grande tarda unos
     segundos; MariaDB los crea en línea, no bloquea).
   - `drop_ai_agent_results` — elimina la tabla del webhook experimental retirado.

4. **Sincronizar permisos** (idempotente, solo si hiciste cambios de roles):
   ```bash
   php artisan db:seed --class=RolesPermissionsSeeder --force
   ```
   ⚠️ NO corras `php artisan db:seed` a secas: ese recrea admin/categorías/ajustes.
   Solo el seeder de roles si lo necesitas. (En esta sesión no se añadieron permisos
   nuevos, así que puedes saltártelo.)

5. **Re-cachear la config** (¡importante! suele estar cacheada y no vería los cambios):
   ```bash
   php artisan config:cache
   php artisan route:cache
   php artisan cache:clear        # tira cachés de datos: informes y contadores
   ```
   (Alternativa sin SSH: borra `bootstrap/cache/config.php` por el Gestor de archivos.)

6. **Los crones nuevos entran solos** — `tickets:wake` (despierta pospuestos) y
   `replies:send` (respuestas programadas) ya están en el planificador. Con la tarea
   `schedule:run` cada minuto (paso 7) funcionan sin tocar nada. Verifica en
   *Agentes → Configuración → Tareas programadas* que el planificador está corriendo.

7. **Verificar la zona horaria** (pendiente de esta sesión). En SSH:
   ```bash
   php artisan tinker --execute="echo now().' | '.DB::selectOne('SELECT NOW() n')->n;"
   ```
   Si las **dos horas coinciden**, todo bien. Si **difieren**, el servidor está en otra
   zona que PHP (Europe/Madrid) y hay que alinear MySQL — avisa antes de tocar.

8. **Smoke test** — entra en `/agentes` y comprueba: abrir un ticket largo (botón
   «Ver mensajes anteriores»), *Configuración → Funciones* (mover un interruptor),
   posponer un ticket, y el portal `/` con las banderas de idioma.

> Nota: las claves de IA/soporteQA y el buzón de correo se configuran **dentro de la
> app** (Funciones + Correo), no en el `.env`. El App Secret del webhook de WhatsApp va
> en *Configuración → WhatsApp*.

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
- **Firma del webhook**: pon el **App Secret** del número en Configuración → WhatsApp para
  que solo se procesen eventos firmados por Meta.
- **Borra `install.php`** del servidor una vez instalado (deja de ser necesario y es una
  puerta abierta).
