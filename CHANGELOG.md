# Registro de cambios

Todas las versiones destacables del helpdesk. Formato: **Mejoras** (novedades y
cambios de comportamiento) y **Arreglos** (bugs corregidos).

## [Sin publicar]

### Arreglos

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
