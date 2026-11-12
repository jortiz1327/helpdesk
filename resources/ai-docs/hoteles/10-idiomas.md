# Idiomas

## Para qué sirve
Define en qué idiomas se escriben los platos y qué idiomas (y en qué orden) se muestran en las etiquetas electrónicas. Hay dos configuraciones separadas: **Idiomas del sistema** (para fichas de plato) y **Etiquetas** (idiomas que enseña cada plantilla de etiqueta).

## Quién lo usa y dónde está
- Menú lateral: **Ajustes > Idiomas** (`/configuration/languages`), pantalla "Configuración de idioma" con dos tarjetas.
- Permisos: ver idiomas (`view-languages`), **Activar/Desactivar idiomas** (`toggle-languages`), **Ordenar idiomas** (`sort-languages`) y crear idiomas (`create-languages`).
- Se configura por buffet.

## Cómo se hace (paso a paso)

### Idiomas del sistema
1. Entra en **Ajustes > Idiomas** y pulsa la tarjeta **Idiomas del sistema**.
2. Cada idioma aparece como **Activo** o **Inactivo**; el interruptor lo activa o desactiva para el buffet.
3. Para ordenar, arrastra desde los puntos de la izquierda del número de orden. El orden se guarda solo ("Orden cambiado exitosamente"). El orden afecta a las fichas de plato y a cómo se muestra el sistema.
4. Para añadir un idioma que no existe, pulsa el botón de añadir nuevo idioma, escribe **Nombre** y **Código** y pulsa **Añadir** (el nombre no puede repetirse).

### Idiomas de las etiquetas
1. Pulsa la tarjeta **Etiquetas** (Pricetags configuration).
2. Elige primero "qué etiqueta desea configurar" (tamaño), después "de cuántos artículos" es (platos por etiqueta).
3. Elige la plantilla: la que está en uso aparece como **En uso**; otras opciones pueden aparecer como **No disponible**. Para usar otra, confírmalo en la ventana ("Esta acción tendrá un efecto inmediato sobre el contenido de todas las etiquetas de este tamaño. ¿Está seguro?" > **Sí, habilitar**).
4. "Seleccione los idiomas que desea mostrar en la etiqueta": elige un idioma por cada hueco de la plantilla y pulsa **Guardar** o **Sí, guardar cambios**.
5. Usa **Vista previa** para ver cómo queda.
6. **Configurar fondo oscuro** permite activar o desactivar el fondo oscuro de una plantilla (hay que confirmar; afecta de inmediato a todas las etiquetas de ese tamaño).
7. **Regenerar plantillas** vuelve a crear las plantillas del buffet ("Plantillas regeneradas con éxito").

## Problemas frecuentes y cómo resolverlos
- **No puedo ordenar ni activar idiomas** → faltan los permisos de ordenar/activar ([Roles y permisos](04-roles-permisos.md)).
- **"Todos los campos son obligatorios!!"** al guardar idiomas de etiqueta → debes elegir un idioma en cada hueco de la plantilla.
- **"Los idiomas no pueden repetirse!!"** → elige idiomas distintos en cada hueco.
- **"La plantilla ya está en uso"** → esa plantilla ya está activa; no hace falta activarla.
- **Plantilla no encontrada o error al regenerar plantillas** → pulsa Regenerar plantillas de nuevo; si persiste contacta con soporte.
- **Falta el idioma que quiero** → créalo en Idiomas del sistema con su nombre y código (A confirmar el formato de código exigido).
- **Un idioma desactivado desapareció de la ficha del plato** → solo se ofrecen los idiomas activos del buffet.
- **Aviso pidiendo seleccionar un buffet** → elige un buffet en el selector.

## Preguntas típicas de clientes
- **¿Cómo añado otro idioma a mis platos?** Idiomas del sistema > activarlo.
- **¿Cómo cambio el idioma que sale en la etiqueta?** Idiomas > Etiquetas > selecciona el idioma por hueco.
- **¿El orden de idiomas importa?** Sí, define el orden de presentación en fichas y etiquetas.
- **¿Puedo tener más de un idioma por etiqueta?** Sí, depende de la plantilla elegida (número de idiomas).
- **¿Cambiar la plantilla afecta a todas las etiquetas?** Sí, a todas las del mismo tamaño y de forma inmediata.
- **¿Se traducen solos los platos?** Hay una traducción automática en el editor de platos; ver [Platos](12-platos.md) y [Traducciones](11-traducciones.md).
- **¿Cuántos idiomas hay disponibles?** A confirmar; depende de los creados en el sistema.

## Términos y relación con otros apartados
- **Idioma del sistema**: idioma activo del buffet para los datos de plato. **Idioma de etiqueta**: idioma mostrado en una plantilla de etiqueta.
- **Plantilla**: diseño de etiqueta con un número de artículos e idiomas.
- Relacionado con [Traducciones](11-traducciones.md), [Platos](12-platos.md), [Etiquetas](30-etiquetas.md), [Roles y permisos](04-roles-permisos.md), [Logs](06-logs.md).
