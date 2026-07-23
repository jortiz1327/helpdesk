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
