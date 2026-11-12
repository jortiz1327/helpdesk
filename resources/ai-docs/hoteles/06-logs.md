# Logs (informes de actividad)

## Para qué sirve
Registra qué acciones han hecho los usuarios en la aplicación (crear, modificar, eliminar, validar, forzar, importar...) para poder saber quién cambió qué y cuándo. Sirve para auditar cambios y resolver dudas del tipo "¿quién cambió este plato?".

## Quién lo usa y dónde está
- Menú lateral: **Logs** (`/logs`). Requiere el permiso **Ver informes** (`view-logs`).
- Muestra los registros del buffet activo.
- El botón **Login Report** (informe de inicios de sesión) solo lo ve el superadministrador.

## Cómo se hace (paso a paso)

### Consultar los registros
1. Entra en **Logs**.
2. La tabla tiene las columnas **Acción** (tipo y categoría), **Usuario**, **Creado en** y **Acciones**.
3. Usa el buscador para filtrar. Las horas se muestran en la zona horaria del hotel.
4. Se listan como máximo los últimos 20.000 registros del buffet; no se incluyen los de categoría API ni los inicios de sesión.

### Ver el detalle de un registro
1. Pulsa **Ver** en la fila.
2. Se abre un panel lateral **Detalles del informe** con lo que cambió.

### Ver el informe de inicios de sesión (superadministrador)
1. Pulsa **Login Report**.
2. Se muestra usuario, correo, IP, navegador y fecha (máximo 20.000 registros).
3. Pulsa **Regresar** para volver.

### Ver la actividad de un usuario concreto
1. En [Usuarios](03-usuarios.md) abre **Ver detalles** y entra en la pestaña **Actividad**.

## Problemas frecuentes y cómo resolverlos
- **No veo "Logs" en el menú** → tu rol no tiene "Ver informes" ([Roles y permisos](04-roles-permisos.md)).
- **Aparece "No hay informes aún"** → todavía no hay actividad registrada en este buffet.
- **No encuentro un cambio antiguo** → solo se muestran los últimos 20.000 registros.
- **La hora no coincide con la mía** → se muestra en la zona horaria del hotel.
- **No veo quién entró en la aplicación** → los inicios de sesión están en el Login Report, solo para superadministrador.
- **Un registro se ve con poco detalle** → algunos registros usan un formato genérico (resumen); es normal.

## Preguntas típicas de clientes
- **¿Quién cambió un plato?** Búscalo en Logs por el nombre y abre Ver.
- **¿Cuánto tiempo se guardan los registros?** A confirmar; la pantalla muestra los últimos 20.000.
- **¿Puedo borrar registros?** No hay opción en pantalla.
- **¿Puedo ver los accesos de los usuarios?** Solo el superadministrador, con Login Report.
- **¿Qué registra?** Altas, cambios, borrados, validaciones, importaciones, forzados de etiquetas, activación de idiomas, traducciones de la app, etc.
- **¿Hay una guía en vídeo?** Sí, el botón de guía de vídeo en esta página ([Guía de vídeo](09-videoguia.md)).
- **¿Se puede exportar?** A confirmar.

## Términos y relación con otros apartados
- **Categoría**: módulo al que pertenece (platos, usuarios, roles, idiomas, etiquetas, etc.); **tipo**: la acción (store, update, destroy, validate, force...).
- Relacionado con [Usuarios](03-usuarios.md), [Roles y permisos](04-roles-permisos.md), [Idiomas](10-idiomas.md), [Traducciones](11-traducciones.md).
