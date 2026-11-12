# Novedades y versiones

## Para qué sirve
Permite saber qué cambios trae cada versión de la aplicación. Hay dos piezas: la ventana de **Novedades** (resumen de la última versión que aparece al entrar) y el **Historial de versiones** (lista completa de todos los cambios por versión).

## Quién lo usa y dónde está
- Todos los usuarios con sesión iniciada. No requiere permiso específico.
- **Novedades**: ventana emergente "Novedades de esta versión" que aparece automáticamente al entrar mientras el usuario no la haya cerrado.
- **Historial de versiones**: pulsa el número de versión (por ejemplo `v3.6.0`) al pie del menú lateral (ruta `/changelog`; el texto al pasar el ratón es "See version history").
- El botón de añadir una versión nuevo solo lo ve el superadministrador.

## Cómo se hace (paso a paso)

### Leer las novedades al entrar
1. Al iniciar sesión aparece la ventana con la versión y "Novedades de esta versión".
2. Léela: está organizada por bloques (plano de sala, módulo Operativa, posiciones y etiquetas, ruedas de menú, platos, más mejoras).
3. Pulsa **Entendido** para cerrarla; no volverá a salir para ese usuario.
4. O pulsa **Ver el historial completo de versiones** para ir al historial (también la marca como vista).

### Consultar el historial de versiones
1. Pulsa el número de versión al pie del menú.
2. Se abre **Historial de versiones**, con las versiones de la más reciente a la más antigua.
3. Cada entrada muestra la versión, el título, la descripción y la fecha. El color y el icono indican el tipo: añadido, cambiado, corregido, eliminado, obsoleto o seguridad.
4. Si no hay datos aparece "No hay versiones disponibles".

### Añadir una versión (solo superadministrador)
1. En el historial pulsa **Añadir nueva versión**.
2. Rellena versión, **Título**, **Tipo de modificación** y **Descripción de la versión**.
3. Guarda. El título y la versión son obligatorios; la descripción admite hasta 1000 caracteres.

## Problemas frecuentes y cómo resolverlos
- **La ventana de novedades no vuelve a salir** → se muestra una sola vez por usuario hasta que se reactiva con una versión nueva.
- **No sé qué versión tengo** → mira el número al pie del menú lateral.
- **No encuentro un cambio concreto** → búscalo en el historial; está ordenado por fecha.
- **No veo el botón de añadir versión** → solo el superadministrador.
- **No cierra la ventana de novedades** → pulsa **Entendido** (no tiene botón de cierre).
- **Veo textos de novedades en inglés** → algunas frases pueden no estar traducidas aún (A confirmar).

## Preguntas típicas de clientes
- **¿Dónde veo las novedades de la última versión?** En la ventana al entrar o en el historial.
- **¿Cómo sé si hay una versión nueva?** El número del pie del menú cambia; la ventana de novedades vuelve a aparecer.
- **¿Se puede volver a ver la ventana de novedades?** No desde pantalla; sí el historial completo.
- **¿Las novedades son iguales para todos los hoteles?** El historial sí; qué funciones puedes usar depende de las funciones activas de tu buffet.
- **¿Puedo sugerir mejoras?** Sí, con la bombilla ([Sugerencias](08-sugerencias.md)).
- **¿Qué trae la versión 3.6.0?** Plano de sala, módulo Operativa, copia de seguridad de posiciones con deshacer, mejoras en ruedas de menú y platos.
- **¿Hay vídeos de cómo usar lo nuevo?** Mira la [Guía de vídeo](09-videoguia.md).

## Términos y relación con otros apartados
- **Versión**: número de la aplicación (mostrado al pie del menú).
- Relacionado con [Sugerencias](08-sugerencias.md), [Guía de vídeo](09-videoguia.md), [Inicio](01-inicio-dashboard.md), [Plano / Planner](22-plano-planner.md), [Operativa](41-operativa.md).
