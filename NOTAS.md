# Notas y dudas para la próxima reunión

> Fichero de trabajo. Aquí se apunta todo lo que hay que **preguntar o decidir** con el
> cliente antes de implementarlo. Cuando algo se resuelve, se mueve a `ARQUITECTURA.md`
> y se marca aquí como ✅ RESUELTO con la fecha.

---

## ❓ Pendientes de preguntar

### 1. «Exportar» — ¿qué se espera exactamente?
En el diseño de *Gestión de Tickets* hay un botón **Exportar**, pero no está claro:
- **¿Formato?** CSV, Excel (.xlsx), PDF…
- **¿Alcance?** ¿Los tickets que se ven en pantalla (con los filtros aplicados) o **todos**?
- **¿Qué columnas?** ¿Las de la tabla, o también la conversación completa de cada ticket?
- **¿Para qué se usa?** (Informes a dirección, contabilidad, auditoría, migración…). Saber
  el uso real cambia bastante el diseño.
- **¿Quién puede exportar?** ¿Cualquier agente o solo encargados? *(Sacar tickets con datos
  de clientes a un Excel es una salida de datos personales: conviene limitarlo por permiso.)*

### 1bis. WhatsApp → ticket: ¿cuándo se crea uno nuevo y cuándo se añade? ⚠️ CLAVE
La decisión más delicada del sistema. **A pensar por el cliente** (15/07/2026).

**El problema de fondo:** WhatsApp es UN hilo continuo por número, SIN identificador de
ticket. A diferencia del correo (código `[TK-...]` en el asunto) o la web (el formulario),
un WhatsApp solo dice *de qué número viene*. Así que a qué ticket pertenece **no se sabe,
solo se deduce** por estado + tiempo. Eso genera dos problemas opuestos:
- **Sobre-fusionar**: si el cliente cambia de tema en el mismo hilo con un ticket abierto,
  el mensaje nuevo se pega al ticket equivocado (2 temas en 1 ticket).
- **Sobre-dividir**: si escribe justo después de cerrar («sigue sin funcionar»), se abre un
  ticket nuevo cuando es continuación.

**Regla actual ya construida** (`TicketService::routeIncoming`): hay ticket abierto en el
canal → se añade; si no → nuevo. Abierto = nuevo/abierto/en_progreso/esperando_respuesta.
Resuelto o cerrado → ticket nuevo.

**Palancas para afinarlo (a decidir):**
1. **Auto-cierre por inactividad** (tras X h/días sin actividad). ← falta el número.
2. **Ventana de reapertura**: mensaje dentro de X h de cerrar → reabre en vez de crear. ← falta el número.
3. La **ventana de 24h de WhatsApp** (límite natural de "sesión" de Meta) como frontera de ticket.
4. Bandeja "sin clasificar" + el agente decide (descartado antes, más control/más trabajo).
5. **IA (fase 2)**: detectar cambio de tema y separar tickets. Único que resuelve el sobre-fusionar.

**Recomendación para fase 1:** 1 ticket abierto por contacto + auto-cierre a 24-48h de
inactividad + ventana de reapertura corta. El "mezcla dos temas" se deja para la IA de fase 2.
**Las 2 preguntas que lo cierran todo: ¿cuántas horas para auto-cerrar? ¿cuánto dura la reapertura?**

### 2. «Departamentos» — ✅ RESUELTO reusando CATEGORÍAS (15/07/2026)
Primero se descartó por complejo. Luego surgió la necesidad real: **un agente no debe ver
todos los tickets, solo los de su área** (un programador los técnicos, garantías los suyos…).
Decisión del cliente: **NO crear departamentos aparte** (duplicarían las categorías), sino
**reusar las categorías como "área"**:
- Un agente se asigna a una o varias **categorías** (desde Usuarios) y **solo ve los tickets
  de esas** + los que tenga asignados personalmente. Ya construido.
- Roles con `tickets.view_all` (encargado/superadmin) siguen viéndolo todo.
- Se **quitó `tickets.view_all` al rol agente** (antes lo veía todo).
- Los tickets **sin categoría** solo los ve quien tiene view_all (los clasifica).
- **Pendiente si hace falta**: email propio por categoría/equipo (soporte@, facturacion@…),
  útil cuando montemos el CANAL CORREO para enrutar por buzón. Se decidió NO hacerlo ahora.

### 3. «Usuario Invitado»
En el diseño los tickets del formulario público salen con la etiqueta **«Invitado»**.
- ¿Significa *cliente que no está registrado / no identificado*?
- ¿Habrá entonces clientes **registrados** en el portal? Esto enlaza con la duda del
  **código de sede** (ver abajo).

### 4. Estados — ✅ RESUELTO (cliente, 15/07/2026)
- **«Pendiente» = «Nuevo»** (mismo estado, el cliente lo confirma).
- **Flujo:** ticket nuevo + un AGENTE responde → pasa a **«En progreso»** automáticamente;
  a partir de ahí el agente cambia a mano a cualquier estado. **HECHO**: `markFirstResponse`
  cableado en `ChatService::storeMessage` (solo salientes con autor humano, no el bot).
- Los 6 estados se mantienen. (Si en uso resulta que sobra «Abierto» o no se distingue
  Resuelto/Cerrado, se recorta entonces — sin urgencia.)

### 5. Prioridades — ✅ RESUELTO (cliente, 15/07/2026)
Es la misma prioridad. Se unifica a **«Normal»** en todos los sitios (era «Media»). Cambiada
la etiqueta en `TicketService::PRIORITIES` (clave interna sigue siendo `media`); se propaga a
tabla, formulario, filtros e historial porque todos leen de ahí.

### 6bis. Categorías definitivas — ✅ RESUELTO (cliente, 15/07/2026)
Las definitivas son **Soporte (24h) · Garantías (48h) · Pedidos y facturas (48h)** (las del
brief, no las del mockup). Aplicadas en BD y semilla. Editables desde Configuración de Soporte.

### 6. SLA: ¿los tickets se cierran solos al llegar a la hora marcada?
Las categorías tienen SLA (24h/48h/72h). **Pregunta para el cliente:** ¿el SLA solo *avisa*
(resaltar "vencido" / "vence en 2h") o además **cierra el ticket automáticamente** al cumplirse
el plazo? Son dos comportamientos muy distintos:
- Solo avisar → el agente ve la urgencia pero decide.
- Auto-cerrar al plazo → puede cerrar tickets sin resolver (¿es lo que se quiere?).
Enlaza con la duda del auto-cierre por inactividad de WhatsApp (§1bis): conviene que la regla
de cierre sea coherente entre canales.

---

## 🔜 Decisiones ya tomadas que conviene confirmar en reunión

### 6. Categorías definitivas
Sembradas con las del brief (**Soporte · Garantías · Pedidos y facturas**), pero el mockup
mostraba otras (*técnico / facturación / ventas*). **Son configurables** (tabla, no código),
así que no bloquea — pero hay que fijar las de producción.

### 7. Código de sede (acceso al portal público)
El mockup pide un **código de 8 caracteres** para crear un ticket. Falta confirmar:
- ¿Es el mecanismo **definitivo** de acceso?
- ¿El código **ya existe** en `adminhoteles` / `adminfarm`, o lo **generamos** nosotros?

### 8. Módulos del diseño que NO estaban en el brief
Ver `ARQUITECTURA.md` §14. Con ~7 semanas para la fase 1 **no caben todos**:
**Base de Conocimiento**, **Chat en Vivo**, **Departamentos**, **Respuestas predefinidas**.
Hay que decidir qué entra y qué se va a fase 2.

---

## 🛠️ Mejoras pendientes de lo ya construido (a hacer a futuro)

### Notas internas / comentarios en el ticket
Además de *responder* al cliente, el agente debe poder **añadir notas o comentarios que NO
se envían al cliente**, solo información interna para los agentes del sistema («llamé, no
contesta», «pendiente de confirmar con almacén»). La base ya está: la columna
`messages.is_internal_note` existe y el hilo ya las pinta distinto; **falta la forma de
escribirlas** (un modo "nota interna" en el editor). Alto valor, poco esfuerzo.

### Historial del cliente
En la ficha del ticket, mostrar los **tickets anteriores del mismo cliente** («ya abrió 3
antes») con acceso rápido — da mucho contexto al agente. El dato ya está en BD.
**Importante:** este historial también debe verse en la **VISTA PÚBLICA** — al cliente le
deben aparecer sus propios tickets (enlaza con el portal público, §portal).

## 🔌 WhatsApp — estado del número real (confirmado 15/07/2026)

- Número **+34 649 78 60 51** conectado y **funcionando** en la Cloud API (probado).
- Verificación de empresa ✅, token permanente ✅, método de pago ✅.
- **NO hace falta publicar la app** ni el flujo de "proveedor de tecnología" (eso es para
  enviar en nombre de OTRAS empresas). Los "requisitos pendientes" que muestra el panel de
  Meta son ruido de su interfaz, no afectan a la mensajería de la propia empresa.
- El **webhook apunta ahora al app-whatsapp VIEJO** (`whats-demo.etiquetaselectronicas.com`).
  Se deja así **de momento**: el número sigue alimentando al proyecto antiguo.
- **A FUTURO**, cuando el helpdesk esté desplegado, se **repunta el webhook** a
  `https://<dominio-helpdesk>/api/webhook.php` + el mismo verify token en Ajustes. Es un
  interruptor: el número va al viejo O al helpdesk, no a los dos. Hasta entonces el helpdesk
  se prueba con webhooks simulados (curl), sin depender del número real.

### 🔀 DOS números: campañas vs tickets (planteado por cliente, 15/07/2026) ⚠️ DECISIÓN
El cliente quiere **DOS números**: uno para **campañas** (el que ya funciona en whats-demo) y
otro para **mensajes/tickets** (el helpdesk nuevo). Pregunta: ¿se puede tener dos teléfonos en
una app de Facebook y en esta app? **Sí se puede**, con matices que hay que decidir:
- En la Cloud API: **un Negocio → varias WABA → cada WABA varios números**. Cada mensaje
  entrante trae `phone_number_id` + `display_phone_number`, así que **siempre se sabe a qué
  número llegó** y se puede enrutar.
- **CLAVE — el webhook es por APP (o por WABA), no por número.** Todos los números de una misma
  WABA comparten UN webhook. Por tanto, para que "campañas → whats-demo" y "tickets → helpdesk"
  vayan a URLs distintas hay dos caminos:
  - **(A) Recomendado — separados:** cada número en su propia WABA/app, cada uno con su webhook.
    Campañas sigue en whats-demo tal cual; el número de tickets apunta al helpdesk. Totalmente
    independientes, cero interferencia. (Meta permite override de callback por WABA:
    `POST /{waba-id}/subscribed_apps` con `override_callback_uri` + `verify_token`.)
  - **(B) Todo en el helpdesk:** los dos números bajo el helpdesk; el webhook único enruta por
    `phone_number_id` (número-campañas → módulo Campañas, número-tickets → motor de tickets).
    Encaja con el selector de área que acabamos de montar, pero exige **migrar campañas** de
    whats-demo al helpdesk. Es el destino final del brief, pero más trabajo ahora.
- **A decidir con el cliente:** ¿(A) separados ya y consolidar (B) más adelante, o (B) directo?
  De esto depende si el área de Campañas del helpdesk se llega a usar de verdad o si campañas se
  queda en whats-demo una temporada.
- **REQUISITO confirmado (cliente, 15/07/2026):** la configuración de WhatsApp —tanto las
  **respuestas (salientes)** como las **entradas de mensajes**— debe estar **acotada al módulo
  actual**. Es decir: el número/token/WABA de **Campañas** solo alimenta el Chat en vivo y los
  flujos de campañas; sus mensajes entrantes **NO** deben crear tickets. Y el (futuro) número de
  **Helpdesk** solo alimenta el motor de tickets. Implica: (1) una config de WhatsApp **por área**
  (no una global), y (2) el webhook enruta el entrante por `phone_number_id` al módulo dueño de
  ese número. Hoy hay UNA sola config global y el entrante va a tickets (`routeIncoming`) — hay que
  refactorizar a config+enrutado por módulo. Es el siguiente bloque de arquitectura de WhatsApp.
- La **config de WhatsApp** (token, WABA, número, verify token) es **solo del superadmin**
  (`settings.manage`), en cualquiera de los dos módulos.

**Nota sobre el error «message_templates does not exist»:** aparece en Plantillas porque la config
de WhatsApp del helpdesk **no tiene aún un token/WABA válidos** (el número real sigue apuntando a
whats-demo). No es un bug: en cuanto se decida el punto anterior y se ponga el token+WABA del número
de campañas en Configuración, las plantillas cargarán.

### 🔐 Login / vista pública / marca «app whatsapp» (planteado por cliente, 15/07/2026)
- El **login actual del helpdesk** es solo para AGENTES (interno, por email). La marca ya dice
  "HelpDesk" en título y login — **la marca «app whatsapp» que se ve es la del proyecto VIEJO**
  (whats-demo), no la de este. Cuando campañas se quede en whats-demo, ese seguirá con su marca
  antigua salvo que se le cambie aparte.
- **VISTA PÚBLICA (pendiente, futuro):** el portal donde el CLIENTE crea/consulta sus tickets
  necesita un **acceso distinto** al de agentes (enlaza con §7 «código de sede»: código de 8
  caracteres). Falta definir: ¿login con código de sede?, ¿el cliente ve su historial de tickets
  (§Historial del cliente)? Es un frente propio, aún sin empezar.

## 🔒 Seguridad (auditoría 15/07/2026 — de cara a hacerlo PÚBLICO)

**Bien defendido (comprobado en código):** SQL parametrizado (+ SQL dinámico del bot con
whitelist de tabla/columnas); sanitizador XSS de lista blanca; subidas con whitelist de
extensiones, nombre UUID y fuera de public/ (no ejecutables); token HMAC-SHA256 timing-safe;
RBAC en todas las rutas; login sin enumeración de usuarios; auth por cabecera (anti-CSRF).

**HECHO:**
- ✅ **Firma del webhook** (`X-Hub-Signature-256`). Se exige cuando hay `wa_app_secret`
  configurado (Ajustes → App Secret); sin él, permite pero marca "firma INACTIVA". Cierra el
  vector de mensajes falsos → tickets falsos + disparo del bot (envíos de pago). **Poner el App
  Secret antes de producción.**

- ✅ **Rate limiting en LOGIN** (`AuthController::login`): 7 intentos fallidos por cuenta+IP y
  25 por IP (spraying) → 429 con cuenta atrás; ventana 5 min; solo cuentan fallos, el éxito
  limpia. Persiste en caché de BD. Frena la fuerza bruta.

**PENDIENTE antes de producción (por prioridad):**
1. 🔴 **Rate limiting** en el resto: **webhook** y **API general** (DoS). El login ya está.
2. 🔴 **Endurecer entorno al desplegar:** `APP_ENV=production`, `APP_DEBUG=false` (hoy true →
   filtra trazas), `APP_URL` https + HTTPS real.
3. 🟡 Token en localStorage sin revocación (30 días) → bajar caducidad / lista de revocación.
4. 🟡 Cabeceras de seguridad (CSP, X-Frame-Options, HSTS).
5. 🟡 Contraseña mínima 6 → 8+.
6. ⚠️ **Portal público (futuro):** mayor superficie nueva — rate limiting propio, anti-bot
   (CAPTCHA/honeypot), validación estricta, y "código de sede" como control de acceso.

## 📥 Del Helpdesk actual (osTicket) — funcionalidades a portar

El helpdesk actual de AEME es **osTicket** (`soporte.etiquetaselectronicas.com`). Repasando su
UI con el cliente (15/07/2026), cosas a valorar/portar. Se hacen **de a poco**, apuntadas aquí.

**Concretas pedidas (por prioridad de arranque):**
- [x] **Notas internas en el ticket** ✅ HECHO (15/07/2026). Conmutador "Responder / Nota
  interna" en el compositor (`Composer.jsx`); la nota se guarda vía `tickets.php?action=note`
  (`TicketsController::note`) con `is_internal_note=1`, HTML saneado, **sin enviarse al cliente**
  (no toca WhatsApp/correo). Se ve **notoria**: centrada, badge ámbar "Nota interna" + autor,
  burbuja crema con borde ámbar. Verificado (endpoint + estilo). Nota: `channel` es
  `enum('whatsapp','email','web')` → la nota usa 'web' (no se muestra).
- [x] **Borrar tickets** ✅ HECHO (15/07/2026). Botón "Eliminar ticket" (rojo) en el panel de
  Acciones del modal, gateado por `tickets.delete`, con confirmación. Endpoint
  `tickets.php?action=delete` (`TicketsController::delete`): valida alcance, borra ficheros de
  adjuntos en disco y el ticket; la BD borra en **cascada** mensajes/eventos/adjuntos (FKs ON
  DELETE CASCADE). El contacto se conserva. Verificado end-to-end.
- [ ] **Fusionar tickets** (merge): unir dos tickets en un solo hilo. "Ver a ojo cómo hacerlo".
- [x] **Editor de respuesta más completo** ✅ HECHO (15-16/07/2026). Parte 1: tachado, cita,
  alineación izq/centro/der, quitar formato (sanitizador conserva solo `text-align`). Parte 2:
  **imágenes EN LÍNEA** (pegar/botón/soltar) — se suben a disco privado (`InlineImageController`,
  tabla `inline_uploads`), se sirven por **URL FIRMADA relativa** (`/api/inline/{id}?signature=`,
  ruta `inline.image` con middleware `signed:relative`, sin token en el HTML, con `nosniff`), y el
  sanitizador solo admite `<img>` de esa ruta (externas/`data:`/`onerror` fuera). Verificado
  end-to-end (sube, sirve firmada, persiste, renderiza y carga). **Redimensionar HECHO** (16/07):
  al clicar una imagen sale un popover con **100% / 50% / 25% + borrar** (como osTicket); el tamaño
  se guarda como clase whitelist `sz-100/50/25` en `<img>` (sanitizador la valida). Verificado.

**Acciones de ticket vistas en osTicket (valorar cuáles):** Editar · Asignar · **Generar PDF** ·
Crear tarea · Cambiar Estado · **Cambiar propietario del ticket** · Correos baneados (banlist).
- [x] **Generar PDF de la conversación** ✅ HECHO (16/07). Botón "Generar PDF" en el panel de
  Acciones → diálogo con opciones (incluir **notas internas** y/o **imágenes**) → descarga.
  Backend con **dompdf** (`tickets.php?action=pdf`, plantilla `resources/views/ticket-pdf.blade.php`):
  cabecera (código/cliente/estado/prioridad/categoría/fechas) + hilo con autor y hora (notas ámbar).
  Las imágenes en línea se **incrustan como data URI** (dompdf no pide URLs firmadas). Verificado
  (PDF válido, acentos OK, opciones funcionan). Nota: `dompdf/dompdf ^3.1` añadido a composer.

**Panel de admin de osTicket — hay MUCHO más que revisar con el cliente:** Temas de ayuda
(Help Topics), Colas (Queues/filtros guardados), Formularios (campos personalizados), Flujo de
trabajo (Workflow), Prioridad, Plantillas de correo, Listas de baneados, Diagnóstico,
Departamentos, Teams, Grupos, Configuración de correo, SLA plans. → sesión aparte para decidir
qué entra en fase 1 vs fase 2.

## ✅ Resueltos

*(vacío por ahora — al cerrar una duda, se mueve aquí con la fecha)*
