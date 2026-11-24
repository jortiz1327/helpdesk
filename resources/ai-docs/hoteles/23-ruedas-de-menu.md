# Ruedas de menús

## Para qué sirve
Una rueda de menús es el ciclo de menús (por ejemplo 7 o 14 días) que el buffet repite. Cada menú define qué plato va en cada posición y servicio. Desde esta pantalla se consultan y editan los menús, se fuerza un menú en las etiquetas, se clonan servicios, se bloquean servicios y se deshacen cambios.

## Quién lo usa y dónde está
- Menú lateral: **Ruedas de menús** (`view-menus`). Configurar ruedas: `view-menu-wheels`.
- Por defecto Chef y Cocina tienen casi todo; Metre puede ver, cambiar platos y forzar menús. Se ajusta en [04-roles-permisos](04-roles-permisos.md).
- Dependen de una función del buffet: el botón dTouch (`DTOUCH`), el enlace **Menú del día** (`MENU_DAY`), **Gestionar temáticos** (`THEMATICS` para gestionarlos) y **Comunicaciones** (`COMMUNICATIONS`).

## Cómo se hace (paso a paso)

### La pantalla principal
- Barra superior: "Hoy se esta usando el menú: X".
- Pestañas **Menús** y **Menús especiales** ([26-menus-especiales](26-menus-especiales.md)).
- Si hay más de una rueda, aparecen botones por rueda, con la insignia "Rueda activa".
- Tarjetas de acción: **Cambio temporal de plato** (`change-dishes`), **Forzar menú** (`force-menus`), **Clonar servicio**, **Cambio temporal de alérgenos** (`change-dishes`) y **Cambio de menú** (superadministrador).
- Enlaces: **Configurar en el plano** (`edit-positions`), **Menú del día**, **Gestionar temáticos**, **Comunicaciones**, **Configurar ruedas de menús**.
- La tabla de menús muestra Nombre, un porcentaje por servicio (rojo si es 50% o menos, verde si más, gris "Bloqueado" con candado) y Acciones: lápiz (editar, `edit-menus`) y candado (`block-services`).

### Editar un menú
1. Pulsa el lápiz o una celda de la tabla (`edit-menus`).
2. Abre la isleta (acordeón) y elige el plato de cada posición y servicio. **Crear plato rápido** permite crear uno sin salir. "Sin plato" quita el plato.
3. "Seleccione los servicios que desea ocultar" oculta servicios; hay avisos "Isleta inactiva" y "Posición inactiva" y se ven las **Fotos de montaje** de la isleta.
4. Un servicio bloqueado no se puede editar ("Servicio bloqueado"). Ver [27-cambios-programados-y-temporales](27-cambios-programados-y-temporales.md).

### Forzar menú (`force-menus`)
1. Pulsa **Forzar menú**.
2. Elige **Menú** (de la rueda activa o un menú especial) y **Servicio**. Para una sola isleta activa "Forzar solo una isleta" y elígela.
3. Confirma. Éxito: "Menú forzado con éxito" / "Isleta forzada con éxito". Las etiquetas se actualizan.
4. Si hay isletas inactivas, sus etiquetas no cambian (se avisa). Si hay antenas desconectadas aparece "Fallo de conexión de antena" (**Entendido** / **Ver antenas**).
5. Hay un límite de frecuencia: "El menú ha sido forzado recientemente. Porfavor, espere N antes de volver a forzar el menú."
6. Los cambios programados para hoy tienen prioridad sobre el plato de la rueda.

### Clonar servicio
1. Pulsa **Clonar servicio**.
2. Elige **Menú** y **Servicio** (o "Todos los servicios"), opcionalmente "Clonar solo una isleta", y marca los días destino.
3. Aviso: los platos del destino se reemplazan; se puede deshacer con **Revertir cambios**. Éxito: "Servicios clonados con éxito".

### Cambio de menú (superadministrador)
Cambia el menú que la rueda usa hoy: "Menú cambiado con éxito".

### Configurar ruedas (`/menus/wheels`)
1. Pulsa **Configurar ruedas de menús**. Tabla: Nombre, Estado (Activa/Inactiva) y Acciones (ver, editar, eliminar).
2. **Crear nueva rueda de menú** (`create-menu-wheels`) abre el formulario: **Nombre**, **Fecha de inicio/fin**, "Activar rueda automáticamente", "Comensales mínimos/máximos" (la aplicación sugiere cambiar de rueda según los comensales), "Días de la rueda de menú" (7 días, 14 días o Personalizado hasta 21), "Temporada de menú" (Sin temporada, Invierno, Primavera, Verano, Otoño, Imserso) y "Nombre de los menús" (doble clic para editar).
3. Activar una rueda desactiva las demás. La primera rueda creada queda activa. Editar/activar: `edit-menu-wheels`. Eliminar: solo superadministrador; borra sus menús y platos (se puede deshacer).
4. En la edición, **Añadir nuevo menú** (`create-menus`) añade un menú al día indicado.
5. El icono de ojo abre la vista general (doble clic en un plato para editarlo; naranja marca alérgenos).

### Cambiar de rueda para unas fechas (por ejemplo, buffet reducido en temporada baja)
Lo hace el propio hotel desde **Configurar ruedas de menús** (`/menus/wheels`), sin pedirlo al equipo, si su usuario tiene los permisos de ruedas (**crear** y **editar ruedas de menú**):
1. Crear la rueda para ese periodo con **Crear nueva rueda de menú**: **Nombre**, **Fecha de inicio** y **Fecha de fin**, y marcar «Activar rueda automáticamente».
2. Para volver a la rueda normal, esa rueda debe estar activa a partir de la fecha en que se quiere volver (se activa con la edición de la rueda, o con su fecha de inicio si se creó con «Activar rueda automáticamente»). Activar una rueda desactiva las demás.
3. Si el usuario no ve esas opciones, le falta el permiso: lo asigna un administrador de su hotel o el equipo (ver [04-roles-permisos](04-roles-permisos.md)).

### Revertir cambios
**Revertir cambios** (`edit-menu-wheels`) lista los últimos cambios en ruedas, menús, platos y bloqueos. Solo se revierte el más reciente con **Revertir**; para varios pasos, **Revertir hasta aquí**.

### dTouch
Con la función `DTOUCH` y `force-menus` se ve el botón "Publicaciones de dTouch de los últimos 7 días".

## Problemas frecuentes y cómo resolverlos
- "Ya existe una rueda con la misma fecha de inicio en este buffet." → Cambia la fecha.
- "La lista de menús no puede estar vacía" → Añade al menos un menú.
- Forzar da "espere N" → Espera ese tiempo.
- Las etiquetas de una isleta no cambian al forzar → La isleta está inactiva ([20-isletas](20-isletas.md)).
- "Fallo de conexión de antena" → Revisa [31-conectividad-antenas](31-conectividad-antenas.md).
- "Servicio bloqueado" al guardar → Desbloquéalo.
- No veo un botón → Falta permiso o función del buffet.
- "The buffet does not have the dTouch integration enabled" → El buffet no tiene dTouch.
- "Este buffet no tiene una rueda de menú activa" → Activa una rueda.

## Preguntas típicas de clientes
- **¿Qué es una rueda?** El ciclo de menús que se repite.
- **¿Cómo cambio el plato de una posición solo hoy?** **Cambio temporal de plato**.
- **¿Cómo vuelvo a la rueda original?** **Forzar menú**.
- **¿Puedo tener varias ruedas?** Sí; solo una está activa.
- **¿Puedo copiar un servicio a otros días?** **Clonar servicio**.
- **Me equivoqué al editar.** **Revertir cambios**.
- **¿Por qué algunos servicios salen en gris?** Están bloqueados.
- **¿Quién puede eliminar una rueda?** Solo el superadministrador.

## Términos y relación con otros apartados
- **Menú**: un día de la rueda. **Servicio**: desayuno, comida, etc. ([15-servicios](15-servicios.md)). **Posición**: [21-posiciones](21-posiciones.md).
- Platos: [12-platos](12-platos.md). Plano: [22-plano-planner](22-plano-planner.md). Temáticos: [24-tematicos](24-tematicos.md). Menú del día: [25-menu-del-dia](25-menu-del-dia.md). Especiales: [26-menus-especiales](26-menus-especiales.md). Cambios: [27-cambios-programados-y-temporales](27-cambios-programados-y-temporales.md). Contenido actual: [02-contenido-actual](02-contenido-actual.md). Comunicaciones: [32-comunicaciones](32-comunicaciones.md).
