# Cambios programados y temporales

## Para qué sirve
Permiten cambiar un plato (o sus alérgenos) sin tocar la rueda: ahora mismo durante el servicio en curso (temporal) o para un día y servicio futuros (programado). También incluyen el bloqueo de servicios, los motivos de cambio y el aviso por exceso de cambios.

## Quién lo usa y dónde está
- Ruedas de menús > tarjetas **Cambio temporal de plato** y **Cambio temporal de alérgenos** (`change-dishes`).
- Bloqueo: icono de candado (`block-services`).
- Umbral de alertas: perfil del usuario; guardarlo requiere `configure-menu-wheel-threshold`.
- Motivos de cambio: crear desde el modal; página de administración para superadministrador/administrador.

## Cómo se hace (paso a paso)

### Cambio temporal de plato
1. **Cambio temporal de plato**; elige **Cambio instantáneo** o **Cambio programado**.
2. **Motivo del cambio** (o **Crear nuevo motivo**), **Posición** y **Plato** (incluye "Sin plato"; **Añadir** más filas solo en instantáneo).
3. Instantáneo: se confirma en "Confirmar cambio temporal". Se aplica ahora, se ve marcado en [02-contenido-actual](02-contenido-actual.md) durante el servicio en curso y en el siguiente vuelve la rueda. Las isletas inactivas no cambian.
4. Programado: elige además **Servicio** y **Fecha**. Solo una posición, un día y un servicio.

### Consultar y editar programados
**Consultar cambios programados** (solo hoy o posteriores): editar plato, servicio, motivo y fecha ("Cambio programado actualizado correctamente."), o **Eliminar cambio programado** ("Excepción eliminada correctamente"). Un cambio programado de hoy tiene prioridad sobre la rueda al forzar o al cambiar de servicio.

### Cambio temporal de alérgenos
Cambia plato y alérgenos de una posición durante el servicio en curso y se ve en Contenido actual.

### Bloquear servicios
1. Candado del menú > "Bloqueo y desbloqueo de servicios".
2. **Bloquear** / **Desbloquear**. Mensaje: "Servicio bloqueado/actualizado correctamente".
3. Un servicio bloqueado no admite cambios de platos ("Servicio bloqueado"). Entra en el historial de **Revertir cambios**.

### Motivos de cambio
Se crean con **Crear nuevo motivo** (campo "Motivo"). En la página "Motivos de cambio" se crean, editan y eliminan ("¿Estás seguro de que quieres eliminar este motivo de cambio?"). Son compartidos por el grupo.

### Umbral de cambios
El máximo recomendado es 15-20 cambios por servicio (por defecto 10). En la práctica se configura en el perfil ([05-perfil](05-perfil.md)): notificación "Alerta de rueda de menú no seguida" > "Configurar alertas de correo". Al superarse se envía un correo a quienes tienen la alerta activa (el disparo exacto es A confirmar). Sin permiso: "No tienes permiso para configurar umbrales. Contacta con un administrador."

## Problemas frecuentes y cómo resolverlos
- "Scheduled change already exists for the same position and service" (sale en inglés) → Ya hay un cambio para esa posición, servicio y día; edítalo.
- El cambio no se ve en la etiqueta → Isleta inactiva o antena desconectada ([31-conectividad-antenas](31-conectividad-antenas.md)).
- "Servicio bloqueado" → Desbloquea.
- El plato vuelve a la rueda → Es normal: el temporal dura el servicio en curso.
- Faltan opciones → Revisa permisos.
- Borrar temporales desde pantalla: la pestaña "Excepciones" está oculta (A confirmar).

## Preguntas típicas de clientes
- **¿Diferencia entre instantáneo y programado?** Ahora vs. día futuro.
- **¿Cuánto dura un cambio temporal?** El servicio en curso.
- **¿Puedo programar varios días?** Uno por cambio.
- **¿Cómo cancelo uno programado?** **Eliminar cambio programado**.
- **¿Quién ve los motivos?** Todo el grupo.
- **¿Puedo bloquear un servicio?** Con `block-services`.
- **¿Cómo recibo avisos de muchos cambios?** Perfil > alertas.

## Términos y relación con otros apartados
- Ruedas: [23-ruedas-de-menu](23-ruedas-de-menu.md). Contenido actual: [02-contenido-actual](02-contenido-actual.md). Perfil: [05-perfil](05-perfil.md). Permisos: [04-roles-permisos](04-roles-permisos.md). Servicios: [15-servicios](15-servicios.md).
