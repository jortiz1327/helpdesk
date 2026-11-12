# Restaurante: Reservas

## Para qué sirve
Es el panel donde el personal del restaurante ve, valida, rechaza, crea, modifica y cancela las reservas de mesa. Aquí llegan las solicitudes que hacen los clientes desde el móvil (QR) o desde el tótem, y también se apuntan las reservas que se toman por teléfono o en recepción. Incluye vista por día, calendario del mes y una bandeja de "pendientes de validar".

## Quién lo usa y dónde está
- Ruta en el menú: **Restaurante > Reservas** (es también la pantalla de inicio de los roles del módulo).
- Hace falta que el módulo esté activo: en un buffet/hotel, la funcionalidad **RESTAURANT** activada para ese buffet; en un **restaurante independiente** ([Restaurante independiente](57-restaurante-independiente.md)) el módulo viene siempre incluido. Si no, el menú "Restaurante" no aparece.
- El menú Reservas lo ve quien tenga el permiso **View reservations**. Junto al grupo "Restaurante" aparece una **bolita con el número de solicitudes pendientes**.
- Qué puede hacer cada persona depende de sus permisos (se dan en [Roles y permisos](04-roles-permisos.md), categoría "restaurant"; resumen en [Permisos y avisos](59-restaurante-permisos-y-avisos.md)):
  - **Create reservations (pending validation)**: crear reservas a mano (quedan pendientes), crear series, reenviar correo.
  - **Edit or cancel pending reservations**: modificar o cancelar reservas pendientes.
  - **Validate or reject reservations**: validar/rechazar solicitudes y aprobar/rechazar cambios pedidos por el cliente.
  - **Create, edit or cancel confirmed reservations**: crear reservas ya confirmadas y tocar o cancelar las confirmadas.
  - **Confirm reservations over capacity**: confirmar aunque se supere el aforo (tras un aviso).
- Se trabaja siempre sobre el buffet/restaurante activo (selector de arriba a la izquierda).

## Cómo se hace (paso a paso)

### Ver las reservas de un día
1. Entra en **Restaurante > Reservas**; se abre la vista **Día** con la fecha de hoy.
2. Cambia de día con las flechas, el selector de fecha o el botón **Hoy**.
3. Arriba ves una tarjeta por turno con ocupación (comensales tomados / aforo, y libres). Sale en rojo si se ha superado el aforo y "Cerrado" si el turno está cerrado.
4. Filtra por estado con el desplegable de estados (Pendiente de validación, Confirmada, Rechazada, Cancelada).
5. Para buscar, escribe en el cuadro "Nombre, correo, habitación o n.º" (el número es del tipo R-000123). Devuelve como máximo 500 reservas.
6. La tabla muestra: N.º de reserva, Fecha, Turno, Nombre y apellidos (con correo, teléfono y notas), Comensales, Habitación, Estado y Acciones.

### Ver el mes en calendario
1. Pulsa **Calendario**.
2. Cada día muestra ocupación y las pendientes por resolver. Colores: Libre, Casi lleno, Lleno y Cerrado.
3. Navega entre meses con las flechas o **Hoy**. Al pulsar un día se abre su vista Día.

### Validar o rechazar solicitudes (bandeja)
1. Pulsa **Validar** (lleva un contador). Muestra las solicitudes pendientes y las reservas confirmadas con un cambio pedido, de hoy en adelante.
2. En la fila pulsa el icono ✓ (**Validar**) para confirmarla; el cliente recibe un correo.
3. Para rechazarla pulsa ✕ (**Rechazar**), escribe un **Motivo (opcional)** (máx. 300 caracteres; va en el correo al cliente) y confirma.
4. Si la reserva muestra **Cambio solicitado**, pulsa **Aprobar** o **Rechazar el cambio**. Mientras no se resuelva sigue valiendo la reserva original y conserva sus plazas.
5. Si al confirmar no caben los comensales aparece **Se supera el aforo**. Solo quien tenga el permiso de superar aforo puede pulsar **Sí, superar el aforo**; el resto ve "No tienes permiso para superar el aforo".

### Crear una reserva a mano
1. Pulsa **Nueva reserva** (arriba a la derecha; solo si tienes permiso de crear o gestionar).
2. Rellena **Fecha**, **Comensales** (1 a 500) y elige el **Turno** (cada turno indica plazas libres). Si el restaurante reserva por hora, aparece **Hora de llegada**.
3. Rellena **Nombre y apellidos** y **Correo electrónico** (obligatorios); opcionalmente **Teléfono**, **Habitación** (máx. 20) y **Notas** (máx. 500).
4. Con permiso de gestionar confirmadas verás la casilla **Crearla ya confirmada**; si no, aparece "La reserva se creará pendiente de validación".
5. Pulsa **Guardar**.

### Modificar o cancelar una reserva
1. En la fila: icono lápiz (**Editar**) para cambiar fecha, turno, comensales o datos; icono **Cancelar la reserva** (con **Motivo (opcional)** para el cliente).
2. Si está confirmada hace falta el permiso de gestionar confirmadas; si está pendiente basta el de editar pendientes.
3. El icono **Historial** (o pulsar el número) abre el detalle con todo lo ocurrido.

### Reenviar el correo al cliente
1. Abre el detalle de la reserva.
2. Pulsa **Reenviar correo** (requiere permiso de crear reservas). Se manda el correo que corresponde a su estado actual.

### Reservas de varios días
Ver [Reservas de varios días (series)](58-restaurante-series-reservas.md).

## Problemas frecuentes y cómo resolverlos
- **No veo el menú Restaurante** → el módulo no está activo en ese buffet o tu rol no tiene "View reservations". Pide a un administrador que lo revise ([Roles y permisos](04-roles-permisos.md), [Contenedores](33-contenedores-hoteles-buffets-grupos.md)).
- **No hay plazas suficientes** → el turno no tiene hueco. Elige otro turno o día, o, si tienes permiso de superar aforo, confirma el aviso. Los aforos se cambian en [Ajustes](54-restaurante-ajustes.md).
- **El restaurante está cerrado ese día o turno** → hay un cierre programado (Ajustes > Días cerrados).
- **Ese turno no se ofrece ese día** → el turno no tiene marcado ese día de la semana.
- **Ya ha pasado el plazo para ese turno** → al cliente se le cierra el turno unos minutos antes (ajuste "Minutos antes del turno en que se cierra"); al personal no le afecta.
- **Solo se puede reservar con N días de antelación / El máximo por reserva es de N comensales / El número de habitación es obligatorio** → reglas de Ajustes (días de antelación, máximo de comensales, admitir clientes que no se alojan en el hotel).
- **La hora de llegada no es válida** → el restaurante reserva por hora y la hora elegida no es una de las del turno.
- **La reserva ya no permite esa acción** → ya estaba cancelada o rechazada, o alguien la resolvió antes. Recarga.
- **Ya tiene un cambio pendiente / el cambio no modifica nada** → resuelve primero el cambio pendiente.
- **Un botón no aparece (Validar, Editar, Cancelar...)** → falta el permiso o la reserva no está en el estado adecuado. La etiqueta "Ya no guarda plazas" indica una solicitud pendiente cuyo plazo caducó.
- **El cliente dice que no le llega el correo** → mira el historial: puede constar "Fallo al enviar un correo". Usa **Reenviar correo**; si persiste, A confirmar con soporte técnico (configuración de correo).

## Preguntas típicas de clientes
- **¿Qué diferencia hay entre pendiente y confirmada?** Pendiente de validación: el cliente ha solicitado pero el restaurante aún no la ha aceptado. Confirmada: el restaurante la ha validado.
- **¿Por qué una solicitud se rechaza sola?** Solo si en Ajustes se configura "Minutos que una solicitud se rechaza automáticamente" (por defecto 0 = desactivado).
- **¿Puedo apuntar una reserva que me piden por teléfono?** Sí, con **Nueva reserva**.
- **¿Puedo superar el aforo?** Solo con el permiso correspondiente y tras un aviso.
- **¿Recibo aviso cuando entra una solicitud?** Sí, por correo, si está configurado. Ver [Ajustes](54-restaurante-ajustes.md) y [Permisos y avisos](59-restaurante-permisos-y-avisos.md).
- **Un cliente quiere cambiar su reserva confirmada, ¿qué pasa?** Genera un "Cambio solicitado" que hay que aprobar o rechazar.
- **¿Dónde veo lo que ha pasado con una reserva?** En el **Historial** de la fila.
- **¿Qué es el origen Tótem / Móvil (QR) / Personal?** De dónde entró la reserva; se usa en [Informes](51-restaurante-informes.md).

## Términos y relación con otros apartados
- **Turno**: franja con horario y aforo (ver [Ajustes](54-restaurante-ajustes.md)). **Aforo**: comensales máximos del turno.
- **Origen**: Tótem, Móvil (QR), Personal. **Cambio solicitado**: petición del cliente sobre una reserva confirmada.
- Textos de correos: [Idiomas y mensajes](53-restaurante-textos.md). Lo que ve el cliente: [Web del comensal](56-restaurante-web-comensal-qr.md) y [Tótem](55-restaurante-dispositivos-totem.md).
- Usuarios y permisos: [Usuarios](03-usuarios.md), [Roles y permisos](04-roles-permisos.md). Pantalla de inicio general: [Inicio](01-inicio-dashboard.md).
