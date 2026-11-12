# Isletas

## Para qué sirve
Las isletas son las zonas o estaciones del buffet (calientes, fríos, postres, show cooking...). Agrupan las posiciones, que son los huecos donde va una etiqueta electrónica con un plato. Cada isleta tiene un nombre y una abreviatura que se usa para nombrar sus posiciones (por ejemplo CL-01, CL-02).

## Quién lo usa y dónde está
- Menú lateral: **Isletas**. Requiere el permiso `view-islets`.
- Crear una isleta: `create-islets`. Editarla y activarla/desactivarla: `edit-islets`.
- Eliminar una isleta desde esta pantalla y el botón **Eliminar todas las isletas**: solo el superadministrador.
- Si no hay buffet seleccionado, la pantalla avisa de que hay que elegir uno.
- Por defecto, los roles Chef, Cocina y Técnico IT pueden ver, crear y editar isletas; Metre solo verlas. Los roles pueden cambiarse en [04-roles-permisos](04-roles-permisos.md).
- Si el buffet tiene activada la función **Wellness**, aparece la opción de crear una isleta de tipo wellness (ver [43-wellness](43-wellness.md)).

## Cómo se hace (paso a paso)

### Ver las isletas
La tabla muestra **Nombre**, **Abreviatura**, **Estado** (Activa/Inactiva) y **Acciones**. Si no hay ninguna se ve "No hay isletas aún".

### Crear una isleta
1. Pulsa **Crear nueva isleta** (arriba a la derecha).
2. Escribe el **Nombre** y la **Abreviatura**. Hay sugerencias rápidas que rellenan ambos campos: CALIENTES/CL, FRÍOS/FR, HELADOS/HL, SHOW COOKING/SC, ENSALADAS/EN, POSTRES/PS.
3. Si tu buffet tiene Wellness, puedes marcar "Isleta wellness" (solo al crear; luego no se puede cambiar el tipo).
4. Pulsa **Guardar**.
El nombre y la abreviatura se guardan siempre en mayúsculas. No puede haber dos isletas con el mismo nombre o la misma abreviatura en el buffet.

### Editar una isleta
1. Pulsa el lápiz de la fila (`edit-islets`).
2. Cambia el nombre o la abreviatura (la abreviatura no puede llevar espacios) y guarda.
3. Si cambias la abreviatura, las posiciones de la isleta se renombran con la nueva abreviatura y sus etiquetas se vuelven a enlazar con el cloud (la etiqueta mostrará el nombre nuevo tras unos instantes).

### Activar o desactivar una isleta
Pulsa sobre la etiqueta de **Estado** (Activa/Inactiva) de la fila (necesita `edit-islets`). Una isleta inactiva no cambia sus etiquetas al aplicar un menú o forzar un cambio; las pantallas de Ruedas de menús avisan de qué isletas están inactivas.

### Eliminar una isleta (superadministrador)
1. Pulsa el icono rojo de papelera de la fila.
2. Lee el aviso: "¿Estás seguro de que quieres eliminar esta isleta?". Se eliminan también sus posiciones, se desvinculan sus etiquetas y se quitan los platos asignados a esas posiciones. No se puede deshacer.
3. Confirma.

### Eliminar todas las isletas (superadministrador)
Botón rojo **Eliminar todas las isletas**. Pide confirmación ("Esta acción desvinculará todas las etiquetas del cloud") y se acepta con **Sí, eliminar**. Si no eres superadministrador el sistema responde con un mensaje de acción no autorizada.

### Fotos de montaje de la isleta
Desde la pantalla de edición de un menú (en [23-ruedas-de-menu](23-ruedas-de-menu.md)), cada isleta tiene un icono de "Fotos de montaje": permite subir una foto de cómo debe montarse la isleta en cada servicio, como referencia para el equipo. Se puede cambiar o eliminar la foto.

## Problemas frecuentes y cómo resolverlos
- "La isleta ya existe" → Ya hay una isleta con ese nombre o esa abreviatura. Usa otro valor.
- "La abreviatura no puede contener espacios." → Quita los espacios de la abreviatura.
- "Islet type cannot be changed" (el tipo de isleta no se puede cambiar) → Si te equivocaste con el tipo wellness, hay que crear otra isleta.
- "This buffet does not have the wellness feature enabled" → El buffet no tiene la función Wellness; pide su activación a soporte. A confirmar: este mensaje puede verse en inglés.
- No veo el botón de eliminar → Solo el superadministrador puede eliminar isletas en esta pantalla.
- Cambié la abreviatura y las etiquetas siguen con el nombre viejo → Espera unos instantes; si sigue, revisa la conexión de antenas ([31-conectividad-antenas](31-conectividad-antenas.md)).
- Una isleta no cambia al forzar el menú → Mira si está **Inactiva** y actívala.
- No veo la opción Isletas en el menú → Falta el permiso `view-islets`.

## Preguntas típicas de clientes
- **¿Qué es una isleta?** Una zona del buffet (por ejemplo "Postres") que agrupa varias posiciones con etiqueta.
- **¿Qué diferencia hay entre isleta y posición?** La isleta es la zona; la posición es cada etiqueta/hueco dentro de ella ([21-posiciones](21-posiciones.md)).
- **¿Puedo cambiar el nombre de una isleta?** Sí, con el lápiz; el cambio de abreviatura renombra sus posiciones.
- **¿Qué pasa si borro una isleta?** Se borran sus posiciones y los platos asignados, y las etiquetas quedan desvinculadas. Solo lo puede hacer el superadministrador.
- **¿Para qué sirve desactivarla?** Para que sus etiquetas no se actualicen (por ejemplo, si cierra una zona) sin borrar nada.
- **¿Puedo ver la isleta dibujada?** Sí, en el plano del buffet ([22-plano-planner](22-plano-planner.md)).
- **¿Cuántas isletas puedo tener?** A confirmar: el código no muestra un límite.
- **¿Para qué son las fotos de montaje?** Como referencia para el personal sobre cómo montar cada isleta en cada servicio.

## Términos y relación con otros apartados
- **Isleta**: zona del buffet. **Abreviatura**: texto corto que prefija las posiciones.
- Las posiciones se crean dentro de una isleta: [21-posiciones](21-posiciones.md).
- El plano dibuja las isletas y permite crearlas arrastrando: [22-plano-planner](22-plano-planner.md).
- Los platos se asignan por posición dentro de cada menú: [23-ruedas-de-menu](23-ruedas-de-menu.md).
- Etiquetas y antenas: [30-etiquetas](30-etiquetas.md), [31-conectividad-antenas](31-conectividad-antenas.md).
