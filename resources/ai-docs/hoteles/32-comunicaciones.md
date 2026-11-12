# Comunicaciones

## Para qué sirve
Permite enviar un documento (por ejemplo un aviso o un cartel en PDF) a una etiqueta electrónica de gran tamaño del buffet. La primera página del PDF se convierte en imagen y se adapta a los colores de la pantalla de tinta electrónica (negro, blanco y rojo).

## Quién lo usa y dónde está
- Se entra desde **Menús**, con el botón **Comunicaciones**. Ese botón solo aparece si el buffet tiene activada la función Comunicaciones (la activa el administrador de la plataforma, ver [Contenedores](33-contenedores-hoteles-buffets-grupos.md)).
- Ruta de pantalla: Inicio > Menús > Comunicaciones. El botón **Regresar** vuelve atrás.
- Subir documentos: cualquier usuario con acceso a Menús.
- **Gestionar etiquetas** y **Añadir etiqueta**: solo administrador de la plataforma (superadmin).
- A confirmar: la dirección de la página no se bloquea por función, solo se oculta el botón.

## Cómo se hace (paso a paso)

### Enviar un documento a una etiqueta
1. Entra en **Menús** y pulsa **Comunicaciones**.
2. Verás una tarjeta por etiqueta con su nombre, MAC, la última imagen enviada y "Última vez enviada". Si no hay imagen aparece "Sin imagen aún".
3. Pulsa **Subir documento** y elige el archivo. Solo se admiten PDF ("Solo se pueden subir archivos PDF.") de hasta 10 MB.
4. Espera: aparece "Convirtiendo...". Al terminar sale "Imagen actualizada correctamente." y la etiqueta se refresca.
5. Se usa solo la primera página del PDF.

### Dar de alta una etiqueta de comunicaciones (solo administrador de la plataforma)
1. Pulsa **Gestionar etiquetas** o **Añadir etiqueta** (si la lista está vacía se lee "Añade una etiqueta para empezar a enviarle documentos.").
2. Indica **Nombre** (por ejemplo "Etiqueta grande") y la **MAC**. Con "¿Cómo encontrar la MAC?" se explica que está impresa en la etiqueta o se lee con el código de barras (tipo 806334335).
3. Pulsa **Crear**. Mensaje: "Etiqueta vinculada correctamente."

### Eliminar una etiqueta de comunicaciones (solo administrador de la plataforma)
1. En **Gestionar etiquetas**, elige eliminar.
2. Confirma: "La última imagen enviada a esta etiqueta se eliminará."
3. Mensaje: "Etiqueta eliminada correctamente." La etiqueta queda libre.

## Problemas frecuentes y cómo resolverlos
- **No veo el botón Comunicaciones en Menús** → el buffet no tiene la función; solicítala a soporte o al administrador de la plataforma.
- **No me deja subir el archivo** → debe ser PDF y pesar menos de 10 MB.
- **Se queda en "Convirtiendo..."** → espera unos segundos; si no acaba, recarga y vuelve a subir. A confirmar la causa si persiste.
- **La imagen se ve distinta al PDF** → la pantalla solo muestra negro, blanco y rojo y se ajusta al tamaño de la etiqueta (tamaños admitidos: 7.5, 10.2, 11.6 y 13.3 pulgadas); usa documentos sencillos y de alto contraste.
- **Mi PDF tiene varias páginas y solo sale una** → solo se envía la primera página.
- **No hay etiquetas para elegir** → las da de alta el administrador de la plataforma.

## Preguntas típicas de clientes
- **¿Puedo mandar un PDF de varias páginas?** Solo se muestra la primera.
- **¿Qué tamaño máximo tiene el archivo?** 10 MB.
- **¿Por qué los colores salen diferentes?** Las etiquetas son de tinta electrónica con tres colores.
- **¿Cuánto tarda en actualizarse?** Se refresca al terminar la conversión; la tinta electrónica puede tardar unos segundos. A confirmar tiempos exactos.
- **¿Puedo enviar una imagen en vez de un PDF?** No, solo PDF.
- **¿Quién puede añadir etiquetas de comunicaciones?** El administrador de la plataforma.
- **¿Se ve la fecha del último envío?** Sí: "Última vez enviada".

## Términos y relación con otros apartados
- Estado y batería de la etiqueta: [Etiquetas](30-etiquetas.md) (en la columna Posición aparece "Comunicaciones").
- Antenas: [Conectividad y antenas](31-conectividad-antenas.md).
- Activar la función por buffet: [Contenedores](33-contenedores-hoteles-buffets-grupos.md).
- Contenido de otras etiquetas: [Temáticos](24-tematicos.md).
