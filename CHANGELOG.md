# Registro de cambios

Todas las versiones destacables del helpdesk. Formato: **Mejoras** (novedades y
cambios de comportamiento) y **Arreglos** (bugs corregidos).

## [1.4.3] — 2026-10-26

### Seguridad (pendientes de la auditoría del 26/10)

- **Portal: botón «Salir».** Anula el pase en el servidor (aunque alguien lo hubiera
  copiado, deja de valer) y borra de ese navegador el pase, los enlaces de tickets y el
  «último visto». Solo aparece cuando hay algo del cliente guardado. Los demás
  dispositivos del cliente no se ven afectados.
- **Cuenta del agente: «Cerrar sesión en todos los dispositivos».** Sube la versión del
  token: todas las sesiones abiertas (también esta) caen al instante. Queda anotado en
  Actividad.

### Arreglos

- La pista de «Nueva contraseña» decía «mín. 6 caracteres»; el mínimo real es 8.

## [1.4.2] — 2026-10-26

### Mejoras

- **«¿A quién se lo envías?»** con tarjetas al crear una campaña (WhatsApp y correo): por
  segmento (Interés + Tipo: dentro de un grupo vale cualquiera, entre grupos todos), por
  etiquetas (cualquiera o todas a la vez), por agenda (solo WhatsApp), por provincia o a
  todos los contactos de Campañas. Recuento en vivo con los excluidos por baja. Nunca
  incluye clientes que solo son del Helpdesk.
- **Audiencias guardadas**: la regla se guarda con nombre y se recalcula en cada envío.
  El historial de campañas muestra a quién se envió.
- **Filtros en el Chat en vivo de Campañas**: Interés, Tipo y otras etiquetas (mismo criterio
  que las audiencias), provincia, «ventana de 24 h abierta» (se les puede responder sin
  plantilla), «sin clasificar» y «ocultar dados de baja». Se recuerdan en el navegador.
- **Registro de consentimientos y bajas (RGPD)**: cada aceptación, baja o vuelta a
  suscribirse queda anotada con fecha y origen (WhatsApp, enlace del correo o un agente).
  Se descarga en CSV desde «Seguridad de envíos», solo con acceso a Campañas y permiso de
  ajustes. Las bajas que ya había entran como «anterior al registro».

### Arreglos

- La baja desde el enlace del correo no guardaba la fecha.

## [1.4.1] — 2026-10-26

### Mejoras

- **Campañas en tramos de días:** «Repartir en varios días» al crear la campaña (X
  contactos al día, o durante X días), con la fecha estimada del último envío. Cada día
  sigue a la misma hora a la que se programó.
- **Campañas solo en días laborables** (nuevo interruptor en «Seguridad de envíos»,
  encendido por defecto): nada sale en sábado, domingo ni en los festivos de Horarios /
  Turnos. Lo pendiente sigue el siguiente día laborable.

### Arreglos

- Al llegar al tope diario, la campaña seguía a medianoche; ahora continúa al día
  siguiente a la hora a la que se programó.

## [1.4.0] — 2026-10-26

### Mejoras

- **Etiquetas de Campañas excluyentes por grupo:** Interés (alto, medio, bajo) y Tipo
  (cliente, no cliente, excliente). Al poner una se quitan las de su grupo, desde la
  ficha, en bloque, el Kanban, el bot, la importación o la fusión.
- **Contactos › Campañas** solo muestra contactos de Campañas, no los clientes del
  Helpdesk que tienen teléfono.
- **«Puede recibir tickets asignados»** por usuario. Apagado, nadie le asigna tickets.
- **Un cliente = una ficha.** Teléfono en un formato único con prefijo (34…) en todas las
  entradas, con una migración que normaliza y fusiona los ya existentes. Fusión automática
  cuando el teléfono lo confirma, y aviso de «posibles duplicados» en Contactos con
  fusionar o «no es la misma persona».
- **Número 51 de Campañas migrado a Coexistence** (nuevo Phone Number ID y WABA).

### Seguridad (auditoría del 26/10)

- Las cuentas deshabilitadas ya no pueden entrar, y deshabilitar o cambiar la contraseña
  cierra sus sesiones.
- Un remitente externo ya no puede sacar adjuntos de otros clientes con el PDF del
  portal.
- Visibilidad de tickets en asignar, categorizar, estado, prioridad, bloqueo y
  programadas.
- Separación entre Campañas y Helpdesk: chat y envío del 51, contactos, etiquetas,
  agendas, stats, analíticas e informes.
- «Eliminar conversación» ya no borra los tickets del cliente.
- Las contraseñas de los buzones ya no se pueden extraer con «Probar conexión».
- Sin escalada a superadmin con `users.manage`. El nodo SQL de los flujos tiene lista
  blanca de tablas.
- Variables escapadas en los correos automáticos. Las respuestas efectivas respetan la
  visibilidad.
- Las URL de descarga llevan un token de medios de 24 h, nunca el de sesión. Los
  adjuntos toman su tipo de la extensión y nunca se abre un SVG en línea.
- Límites en los códigos del portal y en la consulta pública de estado.
- Dependencias PHP actualizadas (`composer audit` sin avisos).

### Arreglos

- Crear un ticket a mano con un teléfono que ya tenía otro contacto daba error 500.
- Los interruptores de la ficha de usuario se veían blancos cuando estaban activados.

## [1.1.3] — 2026-10-08

### Mejoras

- **Iniciar conversación con un número que no es contacto.** Nuevo botón «＋ Nueva
  conversación» en el Chat en vivo de Campañas: se teclea país + número (+ nombre
  opcional), se crea/reutiliza el contacto y se abre el hilo. Como a un número «frío»
  (fuera de la ventana de 24 h) WhatsApp solo deja arrancar con una **plantilla**
  aprobada, al abrir el chat se ofrece directamente el selector de plantillas. Cuando la
  persona responde, se abre la ventana y ya se chatea con normalidad. Respeta el candado
  de WhatsApp (si no está configurado, el botón queda deshabilitado) y el permiso
  `inbox.reply`. Nuevo endpoint `conversations.php?action=start`.

## [1.1.2] — 2026-10-01

### Mejoras

- **Descripción por prioridad.** Cada prioridad puede llevar una frase corta que explica
  cuándo usarla; se muestra bajo el nombre (en cursiva) en el selector de prioridad —en la
  ficha y al crear un ticket— y es editable en **Configuración → Prioridades**. Las cuatro
  de serie vienen con texto por defecto (Urgente: «Servicio completamente caído o impacto
  grave», etc.).

## [1.1.1] — 2026-10-01

### Mejoras

- **Nuevos colores de estado:** Nuevo (rosa), Abierto (púrpura), Planificado (cian),
  Resuelto (verde) y Cerrado (azul). El color sigue viniendo del backend (fuente única),
  así que se aplica a la vez en la bandeja, la ficha y los informes.

### Arreglos

- **Un ticket cerrado ya no se marca como «sin responder».** Si el cliente escribió lo
  último antes de cerrarlo, ya no sale el punto naranja junto al asunto ni el indicador
  amarillo: un ticket resuelto/cerrado nunca está pendiente de nuestra respuesta.

## [1.1.0] — 2026-09-29

### Mejoras

- **Estados nuevos: Nuevo · Abierto · Planificado · Resuelto · Cerrado.** «Planificado»
  para peticiones que se gestionan a días/semanas (activo, pero sin que corra el SLA).
  Se retira «En progreso» (se fusiona en «Abierto»). «Esperando respuesta» deja de ser
  estado y pasa a un indicador redondo en la columna de estado (amarillo «!» sin
  responder · verde «✓» respondido; punto de color en vista compacta). El reloj del SLA
  se **pausa solo** mientras esperas al cliente, y cuando el cliente responde el ticket
  vuelve a «Abierto».

- **Triaje antes de trabajar.** Los tickets entran con prioridad «sin asignar» y no se
  pueden responder, anotar ni asignar hasta ponerles una prioridad.

- **Un agente solo responde sus tickets.** No se puede contestar un ticket asignado a
  otro agente (sale un botón «Asignármelo»); si está sin asignar, se autoasigna al
  responder. Superadmin y encargados se saltan la regla.

- **Al añadir una nota, se ofrece cambiar el estado** (Resuelto, Planificado o Cerrado)
  — p. ej. una nota «gestionado por teléfono».

- **Coherencia entre categoría y agente.** Las «áreas» de un agente son las categorías
  que atiende. Al asignar a un agente de varias áreas cuya categoría no coincide, se
  pregunta a cuál mover; al cambiar la categoría a una que el asignado no lleva, se
  ofrece reasignar o quitar la asignación. Y al responder un ticket sin categoría, toma
  la del área del agente.

- **Enlace directo a un ticket.** Cada ticket tiene una URL que lo abre y un botón
  «Enlace» para copiarla; con un ticket abierto la barra del navegador ya lo refleja.

- **Avisos personalizables por usuario.** Nuevo aviso «Respuesta del cliente» (correo al
  agente cuando el cliente contesta, con botón «Abrir el ticket»), y cada agente elige
  desde Notificaciones qué avisos por correo quiere recibir.

- **Notificaciones más visibles.** El número de avisos aparece sobre el favicon de la
  pestaña, y el sonido de aviso es una campana más fuerte (aún más y repetida con la
  ventana oculta).

- **Bandeja: filtros y columnas.** Filtro de categoría **múltiple** (y siempre muestra
  los «sin categoría»); las columnas de tiempos nacen ocultas (botón «Tiempos»); los
  contadores de arriba cuadran con la lista al usar una vista/categoría; y se puede
  **salir de una vista** pulsándola otra vez.

- **Ficha del ticket más clara.** Se ve el **asunto** arriba de la conversación y el
  **teléfono** del cliente (clicable). El cliente puede apuntar su teléfono también
  desde el portal.

- **Turnos: se distinguen mejor pasado y futuro** (los días por venir resaltan), en
  claro y oscuro. Etiquetas de cliente visibles también en la vista compacta.

- **Fecha en cada mensaje del chat** («19:58 - lunes 14/09/26»), hora y estrella más
  legibles, y vista previa del texto completo en «Parecidas».

- **Contactos.** La lista de Campañas muestra solo contactos de WhatsApp y enseña la
  empresa/tienda. La plantilla de muestra «hello_world» de WhatsApp ya no aparece.

### Arreglos

- **El estado «tomado» no se soltaba al cerrar un ticket.** Al resolver/cerrar, el ticket
  quedaba bloqueado («lo está atendiendo X») para el resto hasta que caducaba solo. Ahora
  se libera al instante, y abrir un ticket cerrado ya no lo vuelve a bloquear.

- **Las fotos grandes de los clientes no llegaban a la incidencia.**
  Un correo con una foto de móvil (15-20 MB) creaba el ticket pero **sin la
  imagen**: se descartaba en silencio por el límite de 10 MB por adjunto. Ahora el
  correo entrante admite hasta 30 MB por archivo (la subida del agente sigue en 10
  MB). Además, las imágenes incrustadas que llegan sin nombre de fichero ya no se
  pierden (se reconoce el tipo por su MIME).

- **El bloqueo de un ticket no se liberaba si el agente se iba.**
  Al abrir un ticket se bloquea para que dos agentes no contesten a la vez, y
  debe caducar solo si el agente deja de estar activo. Pero el refresco de fondo
  (sondeo / mensaje entrante) volvía a tomar el candado en cada ciclo, así que un
  agente con el modal abierto —aunque estuviera ausente— lo mantenía bloqueado
  para todos hasta cerrarlo. Ahora el refresco de fondo solo informa del estado y
  únicamente el «latido» por actividad real renueva el candado: si el agente se va
  ~2 min sin tocar nada, el ticket se libera y otro puede contestar.

- **Nombres de remitente con acentos ilegibles en la bandeja.**
  Los tickets de correo mostraban el contacto como
  `=?UTF-8?Q?Fusi=C3=B3n_Ribera?=` en vez de «Fusión Ribera»: el nombre llegaba
  codificado en MIME y se guardaba sin decodificar. Ahora se decodifica al entrar
  el correo (también en la cuarentena), y una migración corrige los contactos que
  ya estaban guardados así. Tras desplegar hay que ejecutar `php artisan migrate`.

## [1.0.1] — 2026-09-11

### Mejoras

- **Respuestas efectivas · la estrella es ahora un interruptor.**
  El botón ⭐ de una respuesta enviada guarda **y quita** de la memoria de
  respuestas efectivas. Antes solo guardaba y no había forma de deshacerlo
  (un segundo clic solo decía «ya estaba guardada»). Además:
  - La estrella se ve **rellena y siempre visible** cuando esa respuesta está
    guardada, para saber de un vistazo cuáles están en la memoria.
  - El estado **se mantiene al recargar**: la ficha del ticket indica qué
    mensajes están marcados.
  - Cada respuesta efectiva queda enlazada al mensaje del que salió.

- **Aviso «Te han mencionado» en la bandeja.**
  Si te @mencionan en una nota interna de un ticket y **aún no lo has abierto**,
  su fila muestra un chip **«@ Te han mencionado»** junto al asunto. Al abrir el
  ticket, esas menciones quedan vistas: el chip desaparece y baja el contador de
  la campana. Se resuelve con una sola consulta por página (sin coste por fila).

- **Los avisos del navegador abren el ticket concreto.**
  Al pulsar una notificación de escritorio (respuesta nueva, ticket nuevo o
  asignación) ahora se abre **directamente ese ticket**, en vez de llevar solo a
  la lista de tickets. (La campana ya lo hacía.)

- **Los agentes pueden editar contactos.**
  El rol **Agente** incluye ahora el permiso `contacts.edit`, así que puede
  editar la ficha de un contacto (nombre, correo, teléfono, empresa, etc.).
  Antes solo podían los encargados y el superadministrador.

### Arreglos

- **Las notificaciones no abrían el ticket si ya estabas en la bandeja.**
  Si estabas en «Gestión de tickets» y pulsabas una notificación (campana o
  aviso del navegador), el ticket no se abría, porque el id a abrir solo se leía
  al montar la pantalla. Ahora se sincroniza y el ticket se abre siempre.

- **Orden por última actividad en la importación de Faveo.**
  Los tickets importados tomaban una «última actividad» desfasada (el campo del
  ticket de Faveo, no su último mensaje real), así que un ticket antiguo con una
  respuesta reciente no subía en la bandeja. Ahora se calcula del último mensaje
  real del hilo. (Los tickets ya importados se recalcularon.)

- **Nombres de contacto en código MIME.**
  Algunos nombres llegaban de Faveo sin decodificar (p. ej. `=?utf-8?B?...?=`).
  Ahora se decodifican al importar, y los que ya estaban guardados así se
  corrigieron.

## [1.0.0]

- Primera versión del helpdesk: bandeja de tickets, portal público del cliente,
  canal de correo (IMAP/SMTP), campañas, SLA, turnos, informes, roles y
  permisos, importación del histórico de Faveo, y despliegue en servidor propio.
