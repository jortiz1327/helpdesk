# Traducciones

## Para qué sirve
Gestiona los textos de la propia aplicación (botones, títulos, mensajes) en español, inglés y portugués. Incluye una ayuda de traducción automática (DeepL) para proponer el texto en los otros idiomas. No es el sitio donde se traducen los platos de un hotel.

## Quién lo usa y dónde está
- Es una herramienta del **panel de administración** (`/admin/translations`), no aparece en el menú normal del hotel. La usan administradores de la plataforma (acceso al panel: superadministrador o usuarios con acceso a todos los contenedores del grupo; A confirmar quién exactamente puede abrir esta pantalla).
- Los cambios afectan a la aplicación completa, no a un solo buffet.
- Las traducciones de los platos se hacen en la ficha de cada plato (ver [Platos](12-platos.md)); la validación de traducciones de platos depende de la función `TRANSLATION_VALIDATION` y del permiso **Validar traducciones**.

## Cómo se hace (paso a paso)

### Consultar las traducciones
1. Entra en **Traducciones** en el panel de administración.
2. La tabla muestra **Clave**, **Inglés**, **Portugués** y **Español**, con acciones por fila; usa el buscador para localizar un texto.

### Añadir una traducción
1. Pulsa **Añadir traducción**.
2. Escribe la **Clave** (el texto original de la aplicación) y el nombre en español, inglés y portugués (todos obligatorios).
3. Opcional: pulsa **Sugerir traducciones**; mientras trabaja pone "Generando sugerencias..." y rellena los otros idiomas a partir del que hayas escrito.
4. Pulsa **Guardar**. Aparece "Traducción añadida correctamente."

### Editar o renombrar una traducción
1. Pulsa editar en la fila.
2. Cambia los textos o la clave (si cambias la clave, la antigua se elimina) y pulsa **Guardar**: "Traducción actualizada correctamente."

### Eliminar una traducción
1. Pulsa eliminar en la fila y confirma: "Traducción eliminada correctamente."

## Problemas frecuentes y cómo resolverlos
- **No me deja guardar** → clave, español, inglés y portugués son obligatorios.
- **Las sugerencias salen vacías** → el servicio de traducción automática puede estar sin conexión o sin configurar; escribe el texto a mano.
- **Sale un texto en inglés en pantalla** → falta su traducción al español; añádela aquí con su clave.
- **Cambié una clave y el texto desapareció** → renombrar la clave elimina la antigua; el texto debe existir con la clave que usa la aplicación.
- **No veo "Traducciones" en mi menú** → solo está en el panel de administración.
- **Los cambios no se ven** → recarga la página; si sigue igual, A confirmar si hace falta limpiar la caché del servidor.

## Preguntas típicas de clientes
- **¿Puedo cambiar el texto de un botón?** Solo un administrador de la plataforma, desde esta herramienta.
- **¿Se traduce automáticamente?** Hay sugerencias con DeepL para ayudar; se revisan y guardan a mano.
- **¿Qué idiomas admite?** Español, inglés y portugués.
- **¿Cómo traduzco mis platos?** En la ficha del plato, con sus idiomas activos ([Idiomas](10-idiomas.md), [Platos](12-platos.md)).
- **¿Dónde se registran estos cambios?** En [Logs](06-logs.md), categoría de traducciones de la app.
- **¿Afectan a todos los hoteles?** Sí.
- **¿Se valida la traducción de un plato?** Con la función de validación de traducciones activa, desde Platos.

## Términos y relación con otros apartados
- **Clave**: texto original usado en la aplicación, que se traduce a cada idioma.
- **DeepL**: servicio externo de traducción automática usado solo para sugerir.
- Relacionado con [Idiomas](10-idiomas.md), [Platos](12-platos.md), [Logs](06-logs.md), [Administración](35-administracion.md), [Ofertas y hoja de oferta](16-ofertas-hoja-oferta.md).
