# Restaurante: Carta y menús

## Para qué sirve
Aquí se preparan las cartas y los menús del día que el cliente ve en el móvil al escanear el QR del restaurante. Se eligen platos de la oferta, se organizan en secciones, se les pone precio y se personaliza el aspecto. Hasta que no se publica, el cliente no lo ve.

## Quién lo usa y dónde está
- Menú: **Restaurante > Carta y menús**.
- Lo ve quien tenga el permiso **Edit restaurant menus**.
- Los platos salen de la oferta del buffet/restaurante ([Platos](12-platos.md), [Ofertas](16-ofertas-hoja-oferta.md)). Los alérgenos que se muestran son los de los platos ([Alérgenos](14-alergenos.md)).
- Requiere el módulo Restaurante activo. En un restaurante independiente funciona igual ([Restaurante independiente](57-restaurante-independiente.md)).

## Cómo se hace (paso a paso)

### Crear una carta o un menú del día
1. Entra en **Carta y menús**. A la izquierda aparece la lista agrupada en **Cartas** y **Menús del día** (Hoy, Próximos días), cada una con su estado **Publicada** o **Borrador**.
2. Pulsa **Nueva carta** o **Nuevo menú del día** (en el menú del día se pide también la **Fecha**).
3. Escribe el nombre en cada idioma activo; el del idioma principal es obligatorio ("Pon el nombre de la carta en el idioma principal.").
4. Pulsa **Save**.

### Configurar los datos de la carta
- **Precio cerrado**: precio único del menú.
- **Enseñar precios**: "Lo que diga el restaurante" (sigue el ajuste general), "Sí" o "No".
- **Icono de la pestaña**: un emoji.
- **Producto destacado**: hasta 10, con **Añadir producto destacado** y **Quitar**.
- **Publicada: visible para los clientes**: activa o desactiva su publicación.
- Debajo se muestra la dirección de la carta con **Copiar enlace**.

### Secciones y platos
1. Pulsa **Añadir sección** (máx. 60) y ponle nombre por idioma. Puedes subirla, bajarla o borrarla.
2. En la sección pulsa **Añadir platos** (máx. 300 por sección), escribe en "Escribe para buscar un plato..." (muestra los 50 primeros), marca los que quieras y pulsa **Añadir (n)**.
3. A cada plato puedes ponerle precio, ocultarlo con el icono del ojo, reordenarlo o quitarlo.
4. Pulsa **Save**.

### Publicar
1. Pulsa **Publicar** (o marca "Publicada: visible para los clientes" y guarda).
2. Para retirarla, desmárcala. Queda en Borrador.

### Duplicar o borrar
- **Duplicar** hace una copia sin publicar (en un menú del día pide la fecha).
- **Delete** pide confirmación y avisa de que el QR de esa carta dejará de funcionar y que los platos no se tocan.

### Cambiar el aspecto
1. **Aspecto del restaurante** (arriba) cambia cabecera, pestañas, pie y la web de reservas. **Aspecto** dentro de una carta cambia cómo se ven sus secciones y platos.
2. En el editor elige una plantilla en "Plantillas de partida" o una de "Mis aspectos guardados" (máx. 20).
3. Ajusta los grupos Colores, Tipografía, Composición y Marca e imágenes mirando la vista previa.
4. Imágenes: png, jpg, jpeg o webp de hasta 4 MB (no SVG).
5. Pulsa **Guardar este aspecto**; **Volver al aspecto de serie** restaura el de fábrica; **Volver** sale.

## Problemas frecuentes y cómo resolverlos
- **"Este buffet no tiene platos en su oferta."** → primero hay que cargar platos en la oferta ([Ofertas](16-ofertas-hoja-oferta.md)).
- **Un plato aparece como "Plato que ya no está en la oferta"** → se quitó de la oferta; quítalo de la sección o devuélvelo a la oferta. Solo se aceptan platos de la oferta.
- **"Pon el nombre de la carta en el idioma principal."** → rellena el nombre en el idioma principal ([Idiomas y mensajes](53-restaurante-textos.md)).
- **"Ya hay 20 aspectos guardados: borra alguno para guardar otro."** → elimina un aspecto guardado.
- **El cliente no ve la carta** → comprueba que está Publicada. Los menús del día solo se ven en su fecha (y los próximos días).
- **El nombre de un plato sale en otro idioma** → los nombres de plato siguen las traducciones de la oferta; el editor de platos traducidos del restaurante es solo para superadmin ([Idiomas y mensajes](53-restaurante-textos.md)).
- **Los precios no salen** → mira "Enseñar precios" de la carta y "Mostrar los precios en la carta" en [Ajustes](54-restaurante-ajustes.md).
- **No se sube la imagen** → formato no admitido (SVG) o más de 4 MB.

## Preguntas típicas de clientes
- **¿Cómo cambio el plato del día?** Edita el menú del día o crea uno nuevo con su fecha.
- **¿Puedo tener varias cartas?** Sí (carta de comidas, bebidas, etc.).
- **¿Dónde está el enlace/QR?** El enlace de cada carta se copia en su editor; el QR general del restaurante está en [Ajustes](54-restaurante-ajustes.md).
- **¿Se muestran los alérgenos?** Sí, la web tiene una pantalla de alérgenos con los de los platos.
- **¿Puedo cambiar colores y logo?** Sí, en Aspecto.
- **¿Se puede hacer una carta sin precios?** Sí, con Enseñar precios = No.
- **¿Borrar una carta borra los platos?** No, solo la carta; el QR de esa carta deja de funcionar.
- **¿La carta está completa?** Parece completa; cualquier carencia concreta: A confirmar.

## Términos y relación con otros apartados
- **Carta** (permanente) frente a **menú del día** (por fecha). **Sección**: grupo de platos. **Producto destacado**: plato resaltado. **Aspecto**: diseño visual.
- Relacionado: [Platos](12-platos.md), [Alérgenos](14-alergenos.md), [Ofertas](16-ofertas-hoja-oferta.md), [Idiomas](10-idiomas.md), [Web del comensal](56-restaurante-web-comensal-qr.md).
