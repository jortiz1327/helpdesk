# Posiciones

## Para qué sirve
Una posición es el hueco concreto del buffet donde va un plato y que está enlazado a una etiqueta electrónica (por ejemplo CL-01). Desde aquí se crean y se gestionan las posiciones, se cambia el número de platos que muestra cada etiqueta, se invierte la etiqueta, se intercambian posiciones, se reestructura la distribución del buffet y se puede deshacer lo hecho.

## Quién lo usa y dónde está
- Menú lateral: **Posiciones**. Requiere el permiso `view-positions`.
- Crear posiciones: `create-positions`.
- Editar, activar/desactivar, cambiar etiqueta o número de platos, **Intercambiar posiciones**, **Reestructurar**, **Revertir cambios** y el botón **Plano**: `edit-positions`.
- Invertir etiqueta: `invert-pricetags`.
- Eliminar posición, **Revincular posiciones** e **Importar posiciones** (Excel): solo superadministrador.
- Por defecto, Chef, Cocina y Metre solo pueden ver las posiciones; Técnico IT puede además crearlas y editarlas. Se ajusta en [04-roles-permisos](04-roles-permisos.md).

## Cómo se hace (paso a paso)

### Ver las posiciones
La tabla tiene: **Nombre**, **Isleta** (con un icono rojo "Isleta inactiva" si la isleta está desactivada), **Estado** (Activa/Inactiva), **Etiqueta** (la MAC, o "—" si no tiene), **Invertida** (Sí/No) y **Acciones**.

### Crear posiciones
1. Antes debe existir al menos una isleta; si no, se ve "No hay isletas aún" con un enlace para crearlas ([20-isletas](20-isletas.md)).
2. Pulsa **Crear nueva posición**.
3. Elige la **Isleta**.
4. Elige la **Etiqueta** de la lista de etiquetas libres. Si no aparece, pulsa **No encuentro la etiqueta** e introduce su código/MAC (9 dígitos, por ejemplo 806334335).
5. Elige cuántos platos mostrará la etiqueta (tarjetas "N Platos"). Las opciones dependen del tamaño: 2,6": 1; 4,2": 1 o 2; 7,5", 11,6" y 13,3": 1, 2, 4 o 6.
6. Revisa "Se crearán las siguientes posiciones" (nombres como CL-01, o CL-75-01 si el nombre incluye el tamaño) y pulsa **Guardar**.

### Editar una posición
1. Pulsa el lápiz de la fila. Nombre, isleta y etiqueta aparecen bloqueados.
2. Si la etiqueta comparte varias posiciones, un aviso ámbar lista las que cambiarán a la vez.
3. **Cambiar el número de platos**: pulsa "Cambiar el número de platos" e indica cuántos platos mostrará. Si reduces, elige qué posiciones se conservan; las demás se eliminan junto a sus platos de la rueda (se puede deshacer en **Revertir cambios**). Elige **Guardar: mantener su sitio** o **Guardar: añadir al final** según cómo quieras numerar.
4. **Modificar etiqueta**: pulsa "Modificar etiqueta", escribe la **Nueva etiqueta** (MAC) y, si la anterior está rota, marca "Eliminar etiqueta actual al asignar una nueva". Pulsa **Guardar**.
5. Mover una posición a otra isleta no se ofrece en esta pantalla; se hace desde **Reestructurar** o desde el **Plano** (A confirmar si existe otra vía).

### Activar o desactivar una posición
Pulsa en el estado (Activa/Inactiva) de la fila. Una posición inactiva no se muestra en la rueda ni actualiza su etiqueta.

### Invertir la etiqueta
Botón **Invertir etiqueta** de la fila (solo si tiene etiqueta y tienes `invert-pricetags`). Sirve si la etiqueta está colocada al revés. Mensaje: "Etiqueta invertida con éxito".

### Intercambiar posiciones
1. Pulsa **Intercambiar posiciones**.
2. Elige "Posición antigua" y "Nueva posición" (distintas y con etiqueta ambas).
3. Pulsa **Intercambiar**.

### Eliminar una posición (superadministrador)
Papelera roja de la fila. El aviso indica que la posición dejará de mostrarse en la rueda y la etiqueta no actualizará su contenido. Botón **Eliminar posición**.

### Reestructurar
Pantalla visual para reorganizar etiquetas entre isletas (`edit-positions`).
1. Pulsa **Reestructurar**.
2. Arrastra etiquetas a una isleta, o al panel de etiquetas disponibles para liberarlas. Los cambios pendientes salen resaltados.
3. Puedes crear posiciones, isletas o etiquetas desde la misma pantalla.
4. Pulsa **Aplicar cambios** (o **Descartar**). Una barra de progreso muestra las fases hasta "Terminado".
5. Los platos de la rueda acompañan a su posición. Si sales con cambios pendientes, aparece "Hay cambios pendientes sin aplicar" y la opción **Salir sin aplicar**. **Regresar** vuelve a Posiciones.

### Revertir cambios (historial)
1. Pulsa **Revertir cambios** (botón amarillo; `edit-positions`).
2. Se listan los últimos 25 cambios. Solo se puede revertir el más reciente con **Revertir**; para volver varios pasos usa **Revertir hasta aquí** y confirma.
3. Los ya revertidos llevan la marca "revertido". Sin cambios: "Aún no hay cambios registrados."
Los cambios hechos en el plano y los eventos no entran en este historial.

### Importar posiciones desde Excel (superadministrador)
1. **Importar posiciones**. Descarga la plantilla con **Descargar plantilla** (columnas: Nº POSICIONES, MAC, Tamaño etiqueta, ISLETA, ABREVIATURA, INVERTIDA).
2. Sube el .xlsx con **Subir**. Las filas incompletas se saltan, y las MAC que ya tienen posiciones también. Si la isleta no existe se crea.

### Revincular posiciones (superadministrador)
**Revincular posiciones** reenlaza las etiquetas con el cloud. Aviso: las etiquetas que estén en el cloud pero no en la aplicación se desvincularán.

### Plano
El botón **Plano** abre el dibujo del buffet ([22-plano-planner](22-plano-planner.md)).

## Problemas frecuentes y cómo resolverlos
- "No puede haber mas de una posición con el mismo nombre" / "La posición ya existe" → Cambia la isleta o la numeración.
- La etiqueta estaba en otra posición → Es normal: una etiqueta solo puede estar en un sitio; la posición anterior queda sin etiqueta.
- "No encuentro la etiqueta" → Pulsa ese enlace y escribe la MAC (9 dígitos). Si el cloud no la encuentra o es de otra tienda, contacta con soporte.
- "Ambas posiciones deben tener etiqueta para intercambiarse" → Asigna etiqueta primero.
- "La etiqueta ya muestra ese número de platos" / "Debes elegir exactamente N posiciones a conservar" → Elige otro número o marca exactamente las posiciones que se conservan.
- "Opción no disponible. Para activarla, contacta con soporte" al cambiar el número de platos → No hay plantilla para ese tamaño; contacta con soporte.
- "Aplica o descarta los cambios pendientes..." (Reestructurar) → Pulsa **Aplicar cambios** o **Descartar** primero.
- "Solo se puede revertir el cambio más reciente. Revierte antes los posteriores." → Usa **Revertir hasta aquí**.
- "El cloud no se pudo sincronizar en: ..." → El cambio se revirtió en la aplicación pero alguna etiqueta no se actualizó; revisa su conexión ([31-conectividad-antenas](31-conectividad-antenas.md)) y reintenta.
- No veo Importar/Revincular/Eliminar → Solo superadministrador.
- La posición aparece con icono rojo "Isleta inactiva" → Activa la isleta en [20-isletas](20-isletas.md).

## Preguntas típicas de clientes
- **¿Qué es una posición?** Cada hueco con etiqueta donde se coloca un plato.
- **¿Por qué una etiqueta tiene varias posiciones?** Porque la etiqueta grande muestra varios platos; cada uno es una posición.
- **¿Cómo cambio cuántos platos muestra una etiqueta?** Editar la posición > "Cambiar el número de platos".
- **¿Puedo deshacer un cambio?** Sí, con **Revertir cambios**, empezando por el más reciente (últimos 25).
- **¿Cómo muevo etiquetas de una isleta a otra?** Con **Reestructurar** o en el **Plano**.
- **Mi etiqueta se ve del revés.** Usa **Invertir etiqueta**.
- **He cambiado una etiqueta rota por otra.** Editar > "Modificar etiqueta" con la nueva MAC.
- **¿Puedo cargar muchas posiciones a la vez?** Solo el superadministrador, por Excel.
- **¿Qué pasa con los platos al reestructurar?** Los platos de la rueda viajan con la etiqueta.

## Términos y relación con otros apartados
- **Posición**: hueco con etiqueta dentro de una isleta. **MAC / código**: identificador de la etiqueta ([30-etiquetas](30-etiquetas.md)).
- Pertenecen a una isleta: [20-isletas](20-isletas.md). Se dibujan en el plano: [22-plano-planner](22-plano-planner.md).
- Los platos se asignan por posición en cada menú: [23-ruedas-de-menu](23-ruedas-de-menu.md).
- Estado de las antenas: [31-conectividad-antenas](31-conectividad-antenas.md).
