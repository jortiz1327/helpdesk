# Servicios

## Para qué sirve
Los servicios son las franjas del buffet (por ejemplo desayuno, comida o cena). Cada servicio tiene un horario y una hora de ejecución automática: a esa hora el sistema aplica solo el menú del servicio en las etiquetas. Opcionalmente, al terminar el servicio se muestra el logo del hotel.

## Quién lo usa y dónde está
- Menú lateral: **Servicios** (ruta `/services`). Requiere `view-services`. No aparece en contenedores de tipo restaurante.
- Activar/desactivar un servicio y editarlo: `edit-services`.
- Crear y eliminar servicios: solo superadministrador.
- Bloquear un servicio de un menú: `block-services` (ver [23-ruedas-de-menu](23-ruedas-de-menu.md)).
- Si no hay buffet seleccionado: "Tienes que seleccionar un buffet para ver los servicios disponibles".

## Cómo se hace (paso a paso)

### Ver los servicios
Cada servicio se muestra como tarjeta con su nombre, el horario (inicio - fin), la "Hora de ejecución del servicio automático", un icono si al final se mostrará el logo del hotel y su estado (activo/inactivo). Sin servicios: "No hay servicios aún".

### Crear un servicio (superadministrador)
1. Pulsa **Añadir nuevo servicio**.
2. Rellena **Nombre del servicio**, **Hora de inicio de servicio**, **Hora de fin de servicio** y **Hora de ejecución del servicio automático** (todos obligatorios).
3. Opcional: marca **Mostrar el logo del hotel al finalizar el servicio**.
4. Pulsa **Guardar**.
Reglas: la hora de fin debe ser posterior a la de inicio y la de ejecución automática anterior a la de inicio. Máximo 6 servicios por buffet ("No se pueden crear más de 6 servicios").

### Editar un servicio
1. Pulsa el icono de editar de la tarjeta (`edit-services`).
2. Cambia los datos y pulsa **Guardar**.

### Activar o desactivar un servicio
Usa el interruptor de la tarjeta. Al desactivarlo se pausa su ejecución automática (y la del logo final); al activarlo se reanuda.

### Eliminar un servicio (superadministrador)
Icono rojo de papelera, confirma "¿Estás seguro de que quieres eliminar este servicio?" (**Eliminar**).

## Problemas frecuentes y cómo resolverlos
- "No se pueden crear más de 6 servicios" → Se alcanzó el límite; elimina uno o contacta con soporte.
- Error de hora ("fecha anterior/posterior") → Revisa: ejecución antes del inicio; fin después del inicio.
- "La aplicación está desactivada desde AdminHoteles: no se pueden activar servicios." / "Este buffet o su hotel está desactivado desde AdminHoteles: no se pueden activar servicios." → La cuenta está desactivada; contacta con soporte.
- No veo el botón de crear/eliminar → Solo superadministrador.
- No veo el menú Servicios → Falta `view-services` o es un restaurante.
- El menú no cambia a la hora esperada → Comprueba que el servicio esté activo y la hora de ejecución; zona horaria del hotel (A confirmar más detalle, ver [34-configuracion](34-configuracion.md)).

## Preguntas típicas de clientes
- **¿Qué hace la hora de ejecución automática?** Es cuando el sistema aplica el menú del servicio.
- **¿Puedo tener más de 6 servicios?** No.
- **¿Cómo pauso un servicio?** Con el interruptor de su tarjeta.
- **¿Qué es mostrar el logo al finalizar?** Al acabar el servicio las etiquetas muestran el logo del hotel.
- **¿Puedo crear servicios yo?** Solo el superadministrador.
- **¿Se queda registrado?** Sí, hay registro de acciones ([06-logs](06-logs.md)).

## Términos y relación con otros apartados
- Los servicios se usan en las ruedas ([23-ruedas-de-menu](23-ruedas-de-menu.md)), el menú del día ([25-menu-del-dia](25-menu-del-dia.md)) y menús especiales ([26-menus-especiales](26-menus-especiales.md)).
- Programaciones: [27-cambios-programados-y-temporales](27-cambios-programados-y-temporales.md). Etiquetas: [30-etiquetas](30-etiquetas.md).
