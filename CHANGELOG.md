# Registro de cambios

Todas las versiones destacables del helpdesk. Formato: **Mejoras** (novedades y
cambios de comportamiento) y **Arreglos** (bugs corregidos).

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
