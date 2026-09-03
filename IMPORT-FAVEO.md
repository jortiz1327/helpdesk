# Importar el histórico de Faveo

Trae los tickets, contactos, historial de cambios y crones del helpdesk antiguo
(Faveo) al nuevo. Incluye extras opcionales (FAQs y respuestas predefinidas).

Toda la lógica vive en dos comandos artisan; **no hace falta SSH ni una BD aparte**.

---

## Qué hace la importación

- **Borra** todos los tickets y contactos actuales (`--wipe`) y carga el histórico de Faveo.
- **Agentes**: se emparejan por correo; los que no existan se crean (rol `agente`, sin login).
- **Tickets reales de cliente** → tickets de canal `email` con su hilo de mensajes.
  - Los **abiertos** quedan abiertos; los resueltos/cerrados, igual.
- **Modificaciones** de Faveo (asignaciones, cierres, reaperturas, fusiones) → **Historial**
  del ticket (`ticket_events`), **no** como notas internas.
- **Notas internas reales** de agente → se conservan como notas.
- **Contactos sin duplicar agentes**: si un solicitante tenía correo de agente, el ticket
  cuelga de un contacto interno único (nunca un contacto con el mismo correo que un agente).
- **Crones** (correos automáticos: «Cron Job Execution Log», etc.), estén cerrados o en la
  papelera → apartado **Crones**, agrupados y cerrados.
- **Papelera** de Faveo que NO es cron y **rebotes** (MAILER-DAEMON) → se descartan.
- **Extras** (`--extras`, sin borrar los que ya haya):
  - `kb_article` → **FAQs** del portal (publicadas si lo estaban en Faveo).
  - `canned_response` → **respuestas predefinidas** («/»).

Cifras de referencia (dump de sep-2026, 7.256 tickets): **2.818 reales**, **4.293 crones**,
51 papelera, 91 rebotes, 3 sin correo. Historial: ~2.800 creados, ~2.800 estados, ~2.170
asignaciones, ~170 fusiones. FAQs +11, respuestas +18.

---

## En el SERVIDOR (Plesk) — paso a paso

> Usa un **export FRESCO** de Faveo del momento del go-live (no uno viejo).

1. **Desplegar** el código (git pull en el panel de Plesk + `composer install --no-dev`
   + `php artisan migrate --force` + `php artisan optimize:clear`).

2. **Subir** el `faveo.sql` con el **Administrador de archivos** de Plesk (p. ej. a
   `/httpdocs/storage/app/faveo.sql`). No uses phpMyAdmin: 763 MB no entran por web.

3. **Cargar** el dump en la misma BD, prefijado como `fav_*` (salta adjuntos gigantes y
   reconecta solo si hace falta):
   ```bash
   php artisan faveo:load /ruta/al/faveo.sql
   ```

4. **Importar** con todas las reglas:
   ```bash
   php artisan faveo:import --prefix=fav_ --apply --wipe --extras
   ```
   > Consejo: primero SIN `--apply` para ver el recuento (dry-run), y luego con `--apply`.

5. **Adjuntos** (ver la sección siguiente). Se hace ANTES de limpiar (necesita las tablas
   `fav_*` cargadas).

6. **Verificar** (ver abajo).

7. **Limpiar**: quita las tablas temporales y el fichero.
   ```bash
   php artisan faveo:load --drop        # borra las tablas fav_*
   ```
   Y borra el `faveo.sql` subido desde el Administrador de archivos.

---

## Adjuntos (solo SSH al servidor de Faveo)

Solo ~260 adjuntos van como blob dentro del `.sql`; los **~8.500 restantes viven en el
DISCO** de Faveo, todos en **una sola carpeta**: `/var/www/faveo/storage/app/attachments/`
(cada fichero se llama por su `name`, p. ej. `4369_foto.png`). De ellos, **~8.160 son de
tickets que sí se importan**.

**1) En el servidor de Faveo (por SSH), empaquetar la carpeta:**
```bash
du -sh /var/www/faveo/storage/app/attachments                  # ver tamaño
tar czf ~/faveo_attachments.tgz -C /var/www/faveo/storage/app attachments
```

**2) Llevar el `.tgz` al servidor del helpdesk:**
- Si tienes SSH también al helpdesk: directo, sin pasar por tu PC:
  ```bash
  scp ~/faveo_attachments.tgz USUARIO@HOST_HELPDESK:/ruta/
  ```
- Si NO: descarga el `.tgz` a tu PC (`scp` desde Faveo) y súbelo al helpdesk con el
  Administrador de archivos de Plesk.

**3) En el servidor del helpdesk, extraer y colgar los adjuntos** (después de `faveo:import`,
y con las tablas `fav_*` aún cargadas):
```bash
tar xzf faveo_attachments.tgz                                  # → carpeta attachments/
php artisan faveo:attachments --prefix=fav_ --dir=/ruta/attachments --apply
rm -rf attachments faveo_attachments.tgz                       # limpiar
```

El comando lee de la carpeta el fichero de cada adjunto (`{dir}/{name}`), lo cuelga del
mensaje importado (enganche `wamid='fav:{thread_id}'`) y usa el blob de la BD si existe.
Los adjuntos de crones/papelera se ignoran solos (su hilo no se importó).

### Alternativa con BD aparte (si prefieres no meter fav_* en la BD de producción)

Si tienes acceso a crear una BD y a la línea de comandos de MySQL:
```bash
mysql -u<user> -p -e "CREATE DATABASE faveo_old CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
mysql -u<user> -p --max_allowed_packet=1G faveo_old < faveo.sql
php artisan faveo:import --db=faveo_old --apply --wipe --extras
```
(El usuario de la app debe tener permiso de lectura sobre `faveo_old`.)

---

## Verificación (consultas)

```sql
-- Tickets por estado y canal (los 'cron' son el apartado Crones)
SELECT status, channel, COUNT(*) FROM tickets GROUP BY status, channel;

-- Historial: debe haber eventos, no notas de sistema
SELECT type, COUNT(*) FROM ticket_events GROUP BY type;

-- Ningún aviso de sistema colado como nota interna (debe ser 0)
SELECT COUNT(*) FROM messages WHERE is_internal_note=1
  AND (body LIKE '%ha sido asignado a%' OR body LIKE '%have been Closed%'
       OR body LIKE '%have been Resolved%' OR body LIKE '%assigned to%');

-- Ningún contacto con correo de agente (debe ser 0)
SELECT COUNT(*) FROM contacts c JOIN users u ON LOWER(u.email)=LOWER(c.email);

-- Crones y extras
SELECT COUNT(*) FROM cron_alerts;
SELECT (SELECT COUNT(*) FROM faqs) faqs, (SELECT COUNT(*) FROM canned_responses) canned;
```

En la app: abre un ticket importado → pestaña **Historial**: deben verse las asignaciones
y los cambios de estado (no como notas en la conversación).

---

## Revertir

- `php artisan faveo:import --apply --fresh` borra solo lo importado con `source=import-faveo`
  (sin tocar lo que hayas creado a mano). Ojo: `--wipe` sí borra TODO.
- Los adjuntos de los tickets van en un segundo paso (`faveo:attachments`), aún no cubierto aquí.
