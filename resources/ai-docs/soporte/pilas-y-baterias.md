# Pilas y baterías de las etiquetas

Manual de la IA sobre pilas: qué pila usan las etiquetas, qué marcas valen, cuándo es un pedido de material, cuándo es una garantía y cómo saber que la pila está baja. La IA solo informa y orienta: nunca tramita pilas, ni da precios o plazos, ni decide si algo está en garantía.

## Qué pila usan las etiquetas

- Referencia interna: `AM-BZ2450`. Equivalente en el mercado: `CR2450` (pila de botón de litio).
- Modelos de etiqueta individuales: `ZKC42V`. Etiquetas de 4 y 7,5 pulgadas: `ZKC75V-NL`.
- Antes de recomendar una referencia hay que saber el modelo de la etiqueta; si no se sabe, pedir una foto del frontal con el código de barras.

## Marcas: cuáles sirven y cuáles no

- **Compatibles:** Beidongli, HenliMax y NSN. Son más finas y hacen buen contacto con los pines de la etiqueta.
- **No compatibles: VARTA.** Es más gruesa y no hace buen contacto. Duracell puede dar el mismo problema de grosor. Se explica con tacto, sin culpar al cliente.
- Una etiqueta que no responde aunque tenga **pilas nuevas** obliga a mirar la marca de esas pilas. Si el cliente no la dice y no se ve en una foto, preguntar solo la marca.
- La pila no se vende por la IA ni se compra «en cualquier sitio»: si el cliente no encuentra proveedor, las pilas las suministra la empresa y se piden por correo (ver más abajo).

## Qué responder según lo que cuente el cliente

1. **Quiere pilas** (comprar, reponer, «cómo las pido»): es un pedido de material, no se deriva.
   - Respuesta: «Para solicitar material, escriba a `postventa@aemegroup.com` indicando el material, la cantidad y adjuntando una foto de referencia.»
   - Si pregunta qué pila es, se le da la referencia `CR2450` para el correo. No se pide hotel ni correo del cliente, no se tramita el pedido, no se dan precios ni existencias.
2. **Pregunta qué pila es o qué marca vale:** referencia `CR2450` (interna `AM-BZ2450`), marcas Beidongli, HenliMax o NSN, VARTA no. Si pide comprarlas, es el caso 1.
3. **La etiqueta no responde, no cambia o se ha quedado en blanco y no menciona pilas:** protocolo de reinicio de la etiqueta (ver «Protocolo de garantías: reinicio de la etiqueta»). En un hotel se verifica antes hotel y correo.
4. **Cambió las pilas y sigue sin funcionar:**
   1. Preguntar la marca de las pilas nuevas. Si son VARTA o Duracell, recomendar cambiar a Beidongli, HenliMax o NSN (y, si no las tiene, el correo de postventa para pedirlas).
   2. Si la marca es la correcta, explicar el reinicio (pilas fuera 15 minutos, dentro del rango de las antenas) y probar con las pilas de otra etiqueta que funcione.
   3. Si tampoco así, pedir las tres fotos por etiqueta (ver más abajo) y, cuando lleguen, derivar a Garantías.
5. **Pide que le enviemos o sustituyamos etiquetas por avería, o un lote grande que no funciona:** es garantía; se deriva sin dar ningún correo (la IA no dictamina cobertura).
6. **Etiqueta con el LED rojo fijo tras cambiar la pila** («fallo interno»): no es un problema de pila; se deriva a Garantías.
7. **Etiquetas de farmacia:** las garantías las gestiona Expofarm; no se deriva ni se da correo.

## Pasos antes de pasar un caso a Garantías

Es la prueba previa del protocolo de garantías. Se le va pidiendo al cliente lo que le falte, sin repetir lo que ya ha hecho:

1. Reiniciar la etiqueta: quitar la tapa trasera, sacar las pilas, esperar 15 minutos, volver a ponerlas (parte plana hacia el cliente), cerrar y hacer un cambio en esa posición desde la aplicación, siempre dentro del rango de las antenas.
2. Si sigue sin responder: probar con las pilas de otra etiqueta que funcione y revisar la marca (VARTA y Duracell pueden dar problemas de grosor).
3. Si ni así funciona: pedir **tres fotos de cada etiqueta**:
   - de frente (con el código de barras visible),
   - la parte trasera con la tapa abierta y las pilas puestas,
   - la parte trasera con la tapa abierta y sin las pilas.
4. **Cuando el cliente ya ha enviado las fotos, se deriva a Garantías.** No se deriva antes: si dice que ya hizo los pasos pero aún no hay fotos, se piden y se deriva cuando lleguen. Si manda menos de tres por etiqueta, se piden las que faltan.

## Señales de pila baja

- Las etiquetas se van apagando poco a poco hasta quedarse en blanco, o dejan de cambiar de contenido aunque se fuerce.
- La etiqueta sale Offline o «Sin señal» estando cerca de una antena en línea.
- En los hoteles, la columna **Batería** de Conectividad > Etiquetas se estima por el número de refrescos: verde hasta 1250, naranja hasta 3750, rojo «baja» hasta 5000 y rojo «vacía» por encima de 5000. Con la batería en rojo toca cambiar la pila.
- Si hay antena desconectada o poca señal, el síntoma es parecido: se revisa primero la antena (ver «Antena o repetidor desconectado»).

## Vida útil de la pila

- No hay una duración oficial en meses o años: depende de cuántas veces cambia la etiqueta de contenido. Lo que más gasta es **cambiar de contenido**; comprobar la señal cada minuto gasta poco.
- La vida útil se mide en **ciclos**, una estimación propia del equipo (no una medida del fabricante) que es más fiable que la que da el proveedor de la etiqueta o su cloud. Cada refresco de la etiqueta suma 1 ciclo y, si pasó tiempo desde el anterior, suma 1 por cada semana transcurrida (el gasto en reposo).
- Orden de magnitud: la pila aguanta del orden de **4000 a 5000 ciclos**. En las tiendas el nivel de batería sube cada 1000 ciclos (media, baja, muy baja y agotada pasados los 4000); en los hoteles la aplicación marca naranja pasados 1250, roja «baja» pasados 3750 y roja «vacía» pasados 5000. Es orientativo: cada etiqueta aguanta distinto.
- Si el cliente pregunta cuánto dura, la IA puede decir que es orientativo y que depende de cuánto cambie el contenido (no promete fechas ni meses); en un hotel le indica dónde ver la batería. Si insiste en una cifra exacta, se deriva a una persona.
- En un hotel con el buffet pausado (cierre de temporada) las etiquetas apenas gastan pila.
- Las etiquetas antiguas pueden estar fuera del periodo de garantía: eso lo valora una persona (según la fecha de instalación); la IA nunca dice si está cubierta ni propone sustituciones o presupuestos por su cuenta.

## Línea roja

- No tramites pilas, recambios ni envíos; no confirmes existencias; no des precios ni plazos.
- No recomiendes otras marcas ni otras referencias que las de este manual.
- Para garantías no des nunca un correo: las gestiona el responsable desde la aplicación.
