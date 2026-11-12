# Etiquetas

## Para qué sirve
Muestra todas las etiquetas electrónicas (ESL) del buffet activo con su estado real: si están conectadas, qué posición ocupan, su batería, su señal y la antena a la que están unidas. Desde aquí se puede forzar el refresco de una etiqueta, ver cómo se ve en pantalla y, solo el administrador de la plataforma, dar de alta o desvincular etiquetas.

## Quién lo usa y dónde está
- Menú lateral: **Conectividad > Etiquetas**. Hay que tener el permiso `view-pricetags` (si no, el menú no aparece).
- Hay que tener un buffet seleccionado. Si no, la página muestra "Tienes que seleccionar un buffet para ver las etiquetas disponibles".
- El botón "Forzar etiqueta" requiere el permiso `force-pricetags`.
- "Añadir etiqueta" y "Desvincular etiqueta" son solo para el administrador de la plataforma (superadmin).
- El menú Conectividad no se muestra en los restaurantes independientes (no tienen etiquetas).

## Cómo se hace (paso a paso)

### Ver el estado de las etiquetas
1. Entra en **Conectividad > Etiquetas**. Al abrir la página se sincronizan las etiquetas con el cloud.
2. Revisa las columnas: **Etiqueta** (MAC), **Posición**, **Estado** (Online / Offline), **Número de refrescos**, **Batería**, **Señal**, **Antena**, **Acciones**.
3. Usa el buscador o los filtros de las columnas para localizar una etiqueta.

Cómo leer la tabla:
- **Posición**: nombre de la posición donde está la etiqueta. Si la usa otro módulo se indica cuál (Eventos, Temáticos, Comunicaciones, Menú del día). "—" significa etiqueta libre.
- **Batería** (se calcula por el número de refrescos): verde hasta 1250; naranja hasta 3750; rojo "baja" hasta 5000; rojo "vacía" por encima de 5000; gris si no hay dato.
- **Señal**: verde a partir del 70 %; naranja entre 60 y 69 %; rojo por debajo. "Sin señal" si no hay dato.

### Forzar (refrescar) una etiqueta
1. En la fila de la etiqueta pulsa **Forzar etiqueta**.
2. La etiqueta vuelve a pedir su contenido al cloud.
3. Solo se permite una vez cada 5 minutos por etiqueta. Si no, aparece "Solo se permite refrescar cada 5 minutos".
4. El botón está desactivado si la etiqueta está Offline.

### Ver la vista previa
1. Pulsa **Vista previa** en la fila de la etiqueta.
2. Se abre una ventana con la imagen que muestra la etiqueta.

### Añadir etiquetas nuevas (solo administrador de la plataforma)
1. Pulsa **Añadir etiqueta**.
2. Escribe la **MAC** (la que figura en la etiqueta o en su código de barras; se pasa a mayúsculas; solo letras y números, máximo 12 caracteres). Con un lector de códigos, cada lectura abre otra fila.
3. Con "+" ("Añadir más") se añaden más filas.
4. Guarda. Cada MAC se vincula al cloud. Mensajes: "Etiquetas vinculadas exitosamente" / "Etiqueta vinculada exitosamente".

### Desvincular una etiqueta (solo administrador de la plataforma)
1. Pulsa **Desvincular etiqueta** en la fila.
2. Confirma en la ventana "¿Estás seguro de que quieres eliminar este etiqueta?" con **Eliminar etiqueta**.
3. Atención: se libera en el cloud, se borra la etiqueta y **se borran las posiciones del buffet que usaban esa MAC**.

## Problemas frecuentes y cómo resolverlos
- **No me aparece Conectividad > Etiquetas** → falta el permiso de ver etiquetas; pídeselo al administrador del hotel (ver [Roles y permisos](04-roles-permisos.md)).
- **Veo "Tienes que seleccionar un buffet..."** → elige un buffet activo (ver [Contenedores](33-contenedores-hoteles-buffets-grupos.md)).
- **"Solo se permite refrescar cada 5 minutos"** → espera 5 minutos desde el último refresco de esa etiqueta.
- **El botón Forzar etiqueta está gris** → la etiqueta está Offline. Revisa que la antena esté conectada (ver [Antenas](31-conectividad-antenas.md)) y la batería.
- **Una etiqueta sale Offline o "Sin señal"** → comprueba la antena y la distancia; si la batería está en rojo "vacía", hay que cambiar la etiqueta.
- **Tras añadir una etiqueta dice "La etiqueta se ha vinculado, pero el cloud todavía no la devuelve. Espera unos segundos y vuelve a pulsar Añadir."** → espera unos segundos y repite.
- **Una etiqueta muestra el logo en vez del plato** → no tiene plato asignado en su posición (ver [Posiciones](21-posiciones.md)).
- **Quiero cambiar la MAC de una posición** → se hace en la pantalla Reestructurar de posiciones (ver [Posiciones](21-posiciones.md)), no aquí.

## Preguntas típicas de clientes
- **¿Cómo sé si una etiqueta funciona bien?** Mira su Estado (Online), la señal y la batería en Conectividad > Etiquetas.
- **¿Cuándo hay que cambiar la batería?** Cuando la columna Batería esté en rojo; se estima por el número de refrescos (por encima de 5000 se considera vacía).
- **¿Por qué no puedo añadir etiquetas?** Esa función es solo del administrador de la plataforma; pídelo a soporte.
- **¿Puedo forzar todas las etiquetas a la vez?** En esta pantalla se fuerza una por una. A confirmar si existe un forzado masivo en otra pantalla.
- **¿Por qué no puedo refrescar otra vez?** Hay un límite de una vez cada 5 minutos por etiqueta.
- **¿Qué pasa si desvinculo una etiqueta?** Se libera y se borran las posiciones que la usaban.
- **¿Puedo importar etiquetas con un CSV?** Aparece el enlace "Subir archivo CSV", pero A confirmar: en el código está sin completar y no hace nada.
- **¿Puedo invertir los colores de una etiqueta?** Existe el permiso "invert-pricetags" y la acción en el sistema, pero no hay botón visible en esta pantalla (A confirmar).

## Términos y relación con otros apartados
- **MAC**: identificador de la etiqueta (ej. 806334335).
- **Posición**: lugar del buffet donde está una etiqueta, ver [Posiciones](21-posiciones.md) y [Isletas](20-isletas.md).
- **Antena (AP)**: ver [Conectividad y antenas](31-conectividad-antenas.md).
- Etiquetas de módulos: [Comunicaciones](32-comunicaciones.md), [Temáticos](24-tematicos.md), [Menú del día](25-menu-del-dia.md).
- Idiomas que se muestran en la etiqueta: [Configuración](34-configuracion.md) y [Idiomas](10-idiomas.md).
- Plano con las etiquetas: [Plano / Planner](22-plano-planner.md).
