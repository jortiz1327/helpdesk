# Arquitectura — Plataforma de Soporte (Helpdesk + Campañas)

> Documento vivo. Recoge las decisiones de arquitectura del proyecto que sustituye
> al Helpdesk actual, partiendo de la base ya desarrollada (app WhatsApp en Laravel).

---

## 1. Objetivo

Una plataforma **única e interna** para gestionar las comunicaciones de soporte, pedidos
y campañas, unificando los canales (WhatsApp, email y web) en una sola bandeja con
**estilo de chat**, más un **portal público** donde el cliente crea y consulta sus tickets.

Cada usuario ve **solo los módulos de su rol**: el superadministrador ve todo; el resto
accede únicamente a Helpdesk o a Campañas según sus permisos.

---

## 2. Punto de partida: qué se reaprovecha

El proyecto se construye **evolucionando** la aplicación Laravel ya desarrollada
(`helpdesk`), no desde cero.

| Se reaprovecha (≈40%) | Estado |
|---|---|
| **Módulo de Campañas** completo (plantillas, agendas, envío, estados, opt-out) | Hecho y probado |
| **Integración WhatsApp Cloud API** (envío, multimedia, plantillas) | Hecho y probado |
| **Webhook** de WhatsApp (mensajes entrantes, medias, estados de entrega) | Hecho y probado |
| **Motor de flujos** (`FlowEngine`) → base para la IA de fase 2 | Hecho y probado |
| Esqueleto Laravel: migraciones, servicios, autenticación por token, scheduler | Hecho y probado |
| Frontend React (SPA) y su pipeline de build | Hecho y probado |

| Se construye nuevo |
|---|
| Núcleo de **tickets** (modelo, estados, categorías, auditoría) |
| **Roles y permisos** reales (RBAC) + visibilidad de módulos |
| **Portal público** para clientes |
| **Canal email** (entrante y saliente) integrado en el mismo hilo |
| **Turnos** y asignación automática basada en ellos |
| Conexión (solo lectura) a las **BD de Hoteles y Farmacias** |
| **Notificaciones en tiempo real** |

---

## 3. Decisiones tomadas

| Decisión | Elección |
|---|---|
| **Infraestructura** | Servidor **Plesk con acceso de administrador** ✅ confirmado (SSH, Docker, PHP 8.2.32, Composer, Git, extensión Laravel) |
| **Diseño del frontend** | El portal de **Base44 (Álvaro)** como **referencia visual**; las pantallas se rehacen en nuestro React |
| **Auth del portal público** | **Pendiente** de definir en reuniones. El modelo se diseña *enchufable* para no bloquear |
| **Enrutado de mensajes entrantes** | Si el contacto tiene un **ticket abierto en ese canal → se añade al ticket**. Si no → **se crea ticket nuevo** |

---

## 4. El cambio de fondo: el ticket pasa a ser el núcleo

La aplicación actual gira en torno al **contacto/conversación**: 1 contacto = 1 hilo
infinito de mensajes.

Un sistema de tickets gira en torno al **ticket**: 1 contacto = **N tickets**, cada uno
con su propio hilo, estado, categoría, agente asignado e historial.

Esto no es un añadido: **cambia la entidad central** y, con ella, la bandeja de entrada,
la tabla de mensajes y el webhook.

---

## 5. Modelo de datos

### Tablas nuevas

**`tickets`** — la entidad central ✅ **IMPLEMENTADA**
```
id, code            -- referencia legible: TK-AAMM-NNNN (formato tomado del mockup
                    --   de Base44: TK-2607-0001; secuencial que reinicia cada mes)
subject             -- asunto (auto del 1er mensaje si viene por WhatsApp; editable)
category_id         -- FK a ticket_categories (CONFIGURABLES, no fijas en código)
status              -- nuevo | en_proceso | pendiente_cliente | resuelto | cerrado
priority            -- baja | media | alta | urgente
channel             -- whatsapp | email | web   (canal de origen)
contact_id          -- quién lo abre
assigned_to         -- usuario de soporte responsable
opened_at, first_response_at, resolved_at, closed_at   -- hitos para SLA
last_message_at     -- para ordenar la bandeja
```

**`ticket_categories`** — **editables desde Administración**, no una lista fija.
Sembradas con las 3 del brief (Soporte · Garantías · Pedidos y facturas). El mockup de
Base44 mostraba otras (técnico / facturación / ventas): **pendiente de confirmar cuáles
mandan** — al ser tabla, se cambian sin tocar código.

**`ticket_events`** — auditoría / historial
```
ticket_id, user_id, type (estado, asignación, categoría, prioridad),
from_value, to_value, created_at
```

**`companies`** — empresas (hoteles / farmacias)

**`contact_phones`** — N teléfonos por contacto (clave para identificar por WhatsApp)

**`shifts`** — turnos (sustituyen el Excel actual)
```
user_id, fecha / franja horaria, tipo de turno
```

### Tablas que evolucionan

**`messages`** (ya existe) — se le añade:
```
+ ticket_id         -- a qué ticket pertenece  ← el cambio clave
+ channel           -- whatsapp | email | web
+ author_user_id    -- si lo escribe un agente
+ is_internal_note  -- nota interna, no visible para el cliente
```
> Se **reutiliza** la tabla actual en lugar de crear una nueva: así todo el pipeline del
> webhook, multimedia y estados de entrega sigue funcionando.

**`users`** — pasa de un `role` simple (admin/agent) a RBAC completo (§7).

---

## 6. Los tres canales, un solo hilo

Los tres canales desembocan en la **misma interfaz de chat**. El agente trabaja siempre
igual, venga de donde venga el mensaje.

### WhatsApp — ✅ IMPLEMENTADO
El webhook enchufa el **router de tickets** (`TicketService::routeIncoming`):

```
Entra mensaje de un contacto
        │
        ├─ ¿Tiene ticket ABIERTO en canal WhatsApp?
        │        ├─ SÍ  → se añade el mensaje a ese ticket
        │        └─ NO  → se crea un ticket nuevo
```
Abierto = `nuevo | en_proceso | pendiente_cliente`. Un ticket **resuelto o cerrado ya no
recibe**: el siguiente mensaje abre uno nuevo. La consulta va en transacción con
`lockForUpdate` para que dos mensajes simultáneos no creen tickets duplicados.

Las respuestas del **bot** (`FlowEngine`) y las automáticas (consentimiento, BAJA/ALTA)
caen en el **mismo ticket** que las originó. El cron `flow:tick`, al reanudar un flujo
retrasado, busca el ticket abierto del contacto.

### Email
- **Entrante**: IMAP contra el buzón de soporte (worker permanente o cron cada minuto).
- **Saliente**: SMTP.
- **Hilado (threading)**: por cabeceras `Message-ID` / `References`, más el código del
  ticket en el asunto → `[SOP-2026-0001] Asunto original`.
- Buzón: el que hoy da servicio a `soporte.etiquetaselectronicas.com` *(pendiente de credenciales)*.

### Web
El portal público crea el ticket directamente. El cliente ve el hilo y responde desde ahí.

---

## 7. Roles, permisos y módulos — ✅ IMPLEMENTADO (bloque 1)

Se adopta **`spatie/laravel-permission`**. La antigua columna `users.role` (admin|agent)
**se ha eliminado**: los roles y permisos son ahora la única fuente de verdad.

### Fuente única: `config/rbac.php`
Módulos, permisos y roles se declaran en **un solo fichero**. Para añadir un permiso o un
rol nuevo se toca solo ese fichero y se relanza:

```bash
php artisan db:seed --class=RolesPermissionsSeeder   # idempotente
```

### Roles iniciales
> **Vocabulario**: se habla de **usuarios**, no de «agentes». Son usuarios internos con
> permisos de soporte. El término «agente» queda descartado en toda la plataforma.

| Rol | Qué puede hacer |
|---|---|
| **Superadministrador** | Acceso total. Tiene *bypass* (`Gate::before`): cualquier permiso que se añada en el futuro lo hereda automáticamente |
| **Responsable de soporte** | Ve **todos** los tickets, reparte el trabajo, gestiona turnos, ve analíticas |
| **Usuario de soporte** | Atiende **solo sus** tickets asignados (no tiene `tickets.view_all`) |
| **Usuario de campañas** | Plantillas, agendas y envío de campañas. **No accede al Helpdesk** |

### Módulos (`*.access`)
Helpdesk · Contactos · Campañas · Automatizaciones · Turnos · Administración.
El permiso `<módulo>.access` es el que decide qué se ve en el menú lateral.

### Doble validación
- El login devuelve `permissions[]` y `modules[]`; el frontend **pinta solo lo permitido**.
- **El backend valida igualmente cada ruta** (`->middleware('can:permiso')` en `routes/api.php`)
  y devuelve **403** si no. Ocultar en la UI no es seguridad: la seguridad está en el backend.

> Detalle técnico: como la autenticación es por token propio (no por sesión), el middleware
> `TokenAuth` hace `Auth::setUser($user)`. Sin eso, `Gate` y los middleware de permisos no
> encontrarían al usuario.

---

## 8. Turnos y asignación automática

El módulo de turnos sustituye el Excel. La asignación de un ticket nuevo elige entre los
**agentes que están de turno en ese momento**, repartiendo por carga (el reparto
"al menos cargado" ya existe en el motor actual; solo se filtra por turno).

---

## 9. Integración con Hoteles y Farmacias

Dos aplicaciones internas (`adminhoteles` y `adminfarm`) con las bases de datos de
hoteles y farmacias instaladas.

- Se conectan como **conexiones secundarias de solo lectura** en Laravel.
- Un servicio de *enriquecimiento* resuelve, a partir del teléfono o el email,
  **quién es el usuario y a qué empresa pertenece**, y lo muestra junto al ticket.
- *Pendiente*: esquema y credenciales. **No bloquea el arranque.**

---

## 10bis. Tiempo real — ✅ IMPLEMENTADO

Las vistas de tickets se actualizan **solas**, sin refrescar: nuevo ticket, respuesta
del cliente, cambio de estado o reasignación aparecen al instante y disparan un aviso.

**Cómo:** Laravel **Reverb** (websockets) + Laravel Echo en el front.
- Evento `App\Events\TicketActivity` (`ShouldBroadcastNow` → inmediato, sin cola) en el
  canal **privado** `tickets`. Se emite desde `TicketService::broadcast()` en: creación,
  mensaje entrante (webhook), cambio de estado y asignación.
- **Por el socket viaja solo la SEÑAL** (acción + id + código + asunto), NO los datos.
  Al recibirla, el cliente vuelve a pedir por la API, que ya filtra por permisos. Aunque
  alguien se colara en el canal, no se llevaría contenido de conversaciones ni de clientes.
- Canal privado autorizado en `routes/channels.php` (`helpdesk.access`). Como la auth es por
  token, la ruta `broadcasting/auth` se registró dentro del grupo `token` en `routes/api.php`.
- **Fallback a POLLING (10 s)** si el websocket no conecta (`frontend/src/realtime.js`): la
  app nunca se queda muerta esperando un socket. Verificado: cae a polling y se recupera solo.

**Config:** `.env` `REVERB_*` y `VITE_REVERB_*` (puerto **9080**; el 8080 estaba ocupado en
local). `vite.config.js` usa `envDir:'..'` para leer el `.env` de Laravel.

**⚠️ Despliegue (Plesk):** Reverb debe correr como **proceso permanente** —
`php artisan reverb:start`— vía la extensión Laravel de Plesk o supervisor, y nginx debe
hacer proxy del puerto del websocket. Sin el demonio corriendo, la app sigue funcionando
por el fallback de polling.

## 10. Infraestructura y tiempo real

### Servidor: Plesk con acceso de administrador ✅ (confirmado)

El panel dispone de: **SSH Terminal**, **Docker** + reglas de proxy, **PHP 8.2.32**,
**PHP Composer**, **Git**, **Tareas programadas** y la **extensión Laravel** (gestiona
artisan, el scheduler y los queue workers desde el propio panel).

**Consecuencia: no hay limitaciones de infraestructura.** Se descarta el plan B basado en
cron + polling.

| Capacidad | Solución |
|---|---|
| **Notificaciones en tiempo real** | Laravel **Reverb** (websockets) + proxy nginx |
| **Colas** (webhook, envíos, email) | **Queue workers** permanentes (extensión Laravel de Plesk) |
| **Redis** (colas y broadcasting) | Vía **Docker** |
| **Email entrante** | **IMAP** como proceso continuo (no cron) |
| **Scheduler** | Tarea programada: `php artisan schedule:run` cada minuto |

### Despliegue
Con Git + Composer + la extensión Laravel de Plesk: `git push` → `composer install`
→ `php artisan migrate`. Sustituye al despliegue por zip del proyecto anterior.

---

## 11. Identidad visual y marca

La aplicación actual está vestida como una app de WhatsApp: se llama **"App WhatsApp"**,
usa el icono de WhatsApp como logo y el verde de WhatsApp (`#00a884`) como color principal.
Eso deja de tener sentido: la plataforma es el **Helpdesk de AEME**, y WhatsApp pasa a ser
**solo uno de los tres canales**.

### Cambios de marca
| Elemento | Ahora | Debe ser |
|---|---|---|
| Logo | Icono de WhatsApp | **Logo de AEME GROUP** |
| Color principal | Verde WhatsApp `#00a884` | **Azul corporativo de AEME** |
| Favicon / título | Burbuja verde de WhatsApp | Favicon de AEME |
| Nombre visible | "App WhatsApp" | ⏸️ **EN ESPERA** — no se toca todavía |

> **Nombre: decisión aplazada.** El cliente aún no sabe cómo se llamará la **parte pública**
> (el portal del cliente), y ese nombre condiciona el de toda la plataforma. Hasta que se
> decida, **no se cambia el nombre visible**. El logo y los colores sí se pueden aplicar ya,
> son independientes.

### Lenguaje visual (extraído del mockup de Base44)
| Elemento | Cómo es |
|---|---|
| Color de acción | **Azul AEME** (botones, burbuja propia, iconos) |
| Fondo | Gris casi blanco; **tarjetas blancas**, radio ~12px, borde sutil, sombra muy suave |
| Tipografía | Sans (tipo Inter). Títulos gruesos y oscuros; secundario gris medio |
| Chips | Categoría (gris) y estado (color: *En Proceso* en ámbar) |
| Avatares | Círculo **azul** = cliente · Círculo **verde con auriculares** = soporte |
| Estados vacíos | Icono en círculo + título + frase de ayuda (muy cuidados) |
| Portal | Cabecera con logo + «Centro de Soporte» + indicador «En línea». Dos pestañas: **Nuevo Ticket** / **Consultar Ticket** |
| Ticket | Se despliega como **hilo de chat**; al pie, chips de **estado** y **prioridad** |

> El acceso para **crear** ticket en el mockup pide un **«código de sede» de 8 caracteres
> alfanuméricos** (pantalla «Acceso al Soporte»). Encaja con los *códigos de autenticación*
> de la fase 2. **Pendiente de confirmar** si es el mecanismo definitivo y de dónde sale el
> código (¿de `adminhoteles`/`adminfarm`, o lo generamos nosotros?).

### Requisito explícito del cliente: la interfaz debe ser **muy profesional y moderna**
No basta con que funcione. El acabado visual es un requisito, no un extra:
tipografía y espaciados cuidados, jerarquía visual clara, estados vacíos/carga/error bien
resueltos, transiciones sobrias, accesible y **responsive**. El portal público es la cara
que ven los clientes: es escaparate, no solo herramienta.
Referencia: el portal de **Base44** de Álvaro (§13 Pendientes).

### ⚠️ Detalle importante de diseño
Hoy un **mismo icono** (`Icon.logo`) se usa para dos cosas distintas:
1. Como **logo de marca** (barra lateral, login).
2. Como **marcador de canal** en la bandeja ("este mensaje vino de WhatsApp").

Al rebrandear hay que **separar los dos usos**:
- `Icon.brand` → el logo de **AEME**.
- `Icon.whatsapp` → se **mantiene**, porque con tres canales (WhatsApp / email / web) el
  icono de WhatsApp sigue siendo necesario para indicar el **origen** de cada ticket y mensaje.

> Es decir: no se trata de borrar el icono de WhatsApp, sino de **degradarlo de logo a
> indicador de canal**, junto a los iconos de email y web.

### Dónde vive el branding (ficheros a tocar)
- `frontend/index.html` → `<title>`, favicon (SVG embebido) y `theme-color`.
- `frontend/src/App.jsx` → logo y nombre en la barra lateral (cabecera y pie).
- `frontend/src/components/Login.jsx` → logo y nombre en la pantalla de acceso.
- `frontend/src/icons.jsx` → `Icon.logo` (dividir en `brand` + `whatsapp`).
- `frontend/src/styles.css` → variables `--primary`, `--primary-dark`, `--primary-soft`,
  `--primary-glow` (+ algunos `rgba(0,168,132,…)` sueltos). **El tema está centralizado en
  variables CSS**, así que cambiar la paleta es barato.

### ⏳ Pendiente: rediseñar el LOGIN por completo
El login actual es el heredado de la app de WhatsApp (panel lateral con carrusel de
mensajes de marketing). Se ha reciclado el texto y el color como parche, pero **hay que
rehacerlo entero**.

> **Decisión del cliente**: el login se aborda **cuando toquemos la vista pública**, no
> antes. Ambas cosas van juntas: la pantalla de acceso es lo primero que ve un cliente y
> debe encajar con el portal público (§6), no con una app de mensajería.

Estado actual (parche provisional):
- Textos: ya hablan de tickets y canales, no de WhatsApp Business.
- Color: azul AEME en lugar del degradado verde WhatsApp.
- Acceso: **por email** (ya no existe el nombre de usuario).

### Necesario del cliente
- **Fichero del logo de AEME GROUP** en **SVG** (preferible) o PNG a alta resolución.
  Hará falta también una **versión compacta/isotipo** (el símbolo sin el texto), porque la
  barra lateral plegada y el favicon son cuadrados y pequeños.
- **Colores corporativos** (hex exactos). Si llega el SVG, se leen de ahí.
- El **nombre visible**: aplazado hasta decidir el nombre de la parte pública.

---

## 12. Plan de la Fase 1

Objetivo: plataforma usable **antes de septiembre**. Orden propuesto por dependencias y riesgo:

| # | Bloque | Depende de | Notas |
|---|---|---|---|
| 1 | **RBAC**: roles, permisos, usuarios, visibilidad de módulos | — | ✅ **HECHO Y PROBADO** (ver §7) |
| 2 | **Núcleo de tickets** + UI de chat unificada (canal WhatsApp) | 1 | El corazón del sistema. El canal ya existe |
| 3 | **Canal email** (IMAP/SMTP + threading) | 2 | Credenciales del buzón |
| 4 | **Portal público** (crear/consultar tickets) | 2 | Auth del portal (sin cerrar) + diseño Base44 |
| 5 | **Turnos** + asignación automática | 1 | Autocontenido |
| 6 | **Integración Hoteles/Farmacias** | 2 | Accesos externos |
| 7 | **Notificaciones en tiempo real** | infra | Si aprieta el calendario, se mantiene el *polling* |
| — | **Campañas** | 1 | Ya está hecho: solo re-encajarlo en el nuevo RBAC |

---

## 13. Fase 2 (posterior)

- Códigos de autenticación en Hoteles/Farmacias e identificación automática por WhatsApp.
- **Primer nivel de atención con IA**: responde y deriva a un agente solo cuando hace falta
  (se apoya en el `FlowEngine` ya desarrollado).
- Integración con **Portal/Sesame** para el fichaje de tareas por ticket.
- Notificaciones en **Microsoft Teams**.
- **Módulo de compra** tipo e-commerce.

---

## 14. ⚠️ Alcance descubierto en el diseño interno (NO estaba en el brief)

El menú «Soporte» del diseño de referencia incluye módulos que **el brief de fase 1 no
menciona**. Hay que decidir si entran en fase 1, pasan a fase 2, o se descartan:

| Módulo del diseño | ¿Está en el brief? | Comentario |
|---|---|---|
| **Base de Conocimiento** | ❌ No | Módulo entero (artículos, búsqueda). Nada trivial |
| **Chat en Vivo / Live Chat** | ❌ No | Sería un **cuarto canal** (web en tiempo real), además de WhatsApp/email/web |
| **Departamentos** | ❌ No | Otra dimensión de organización además de categorías |
| **Respuestas predefinidas** | ❌ No | *Canned responses*. Barato y muy útil para el día a día |
| **Reportes** | Parcial | El brief habla de analíticas |
| **Guardias** | ✅ Sí | Son los **turnos** del brief |

> **Riesgo de calendario.** Estos cuatro módulos extra son trabajo real. Con ~7 semanas para
> la fase 1, meterlos todos **no cabe**. Recomendación: **Respuestas predefinidas** sí (barato,
> alto impacto); **Base de Conocimiento** y **Chat en Vivo** a fase 2; **Departamentos**, decidir
> si aportan sobre las categorías o duplican.

> Nota de vocabulario: el diseño dice «Agentes». En la plataforma se dice **usuarios** (§7).

---

## 15. Pendientes y riesgos

| Pendiente | Impacto | Bloquea |
|---|---|---|
| Acceso al diseño de Base44 | Fidelidad de las pantallas | Bloque 4 (portal) |
| **Logo y colores de AEME** (SVG + hex) | Identidad visual (§11) | Rebranding |
| Auth del portal público | Cómo entra el cliente | Bloque 4 |
| Credenciales del buzón de soporte | Canal email | Bloque 3 |
| Esquema/accesos Hoteles y Farmacias | Enriquecimiento de datos | Bloque 6 |
| ~~Acceso root/SSH en Plesk~~ | ~~Tiempo real, workers, IMAP~~ | ✅ **Resuelto**: acceso de administrador confirmado |

**Riesgo principal**: el calendario. ~7 semanas para RBAC + tickets + email + portal +
turnos es ajustado. Si hay que recortar, el candidato es la **integración con
Hoteles/Farmacias** (se puede añadir después sin rehacer nada).

> Nota: la **extracción/migración de datos del Helpdesk antiguo** la aborda otra persona
> del equipo. El modelo de tickets debe admitir la importación de histórico.
