# Platos

## Para qué sirve
Aquí se crean y se mantienen todos los platos del buffet: su nombre en cada idioma, su tipo, sus alérgenos e ingredientes, sus distintivos, y (en la edición avanzada) fotos, vídeos, pasos de preparación y valores nutricionales. Lo que se configura aquí es lo que después se coloca en las ruedas de menú, los menús del día y se muestra en las etiquetas electrónicas.

## Quién lo usa y dónde está
- Menú lateral: **Platos** (ruta `/dishes`). Requiere el permiso `view-dishes`.
- Crear, editar y eliminar requieren los permisos `create-dishes`, `edit-dishes` y `delete-dishes` (ver [04-roles-permisos](04-roles-permisos.md)).
- Validar alérgenos o traducciones requiere `validate-allergens` / `validate-translations`.
- Importar platos requiere `import-dishes` (el superadministrador siempre puede).
- Funciones que dependen de las funciones activadas en el buffet:
  - Alérgenos en el plato: función de alérgenos (DISH_ALLERGENS).
  - Ingredientes: DISH_INGREDIENTS (ver [13-ingredientes](13-ingredientes.md)).
  - Trazas: DISH_TRACES.
  - Muestras testigo: HEALTH_SAMPLE.
  - Validación de traducciones / de alérgenos: TRANSLATION_VALIDATION / ALLERGEN_VALIDATION.
  - Bandejas: WASTE_SYSTEM u OPERATIVA ([40-desperdicio](40-desperdicio.md), [41-operativa](41-operativa.md)).
  - Integraciones de ERP: FICHATEC, AVALON, DTOUCH.
- Si el buffet trabaja con **estructura de oferta**, aparece además el botón **Oferta** (ver [16-ofertas-hoja-oferta](16-ofertas-hoja-oferta.md)).
- Si no hay buffet seleccionado, la pantalla pide seleccionar uno (A confirmar el texto exacto).

## Cómo se hace (paso a paso)

### Ver y buscar platos
1. Entra en **Platos**. Si no hay ninguno verás "No hay platos aún".
2. Usa el cuadro **Buscar** para filtrar por nombre.
3. Abre **Filtros** para ver solo "Usados en menús", "No usados en menús", "Alérgenos por validar" o los de un idioma "por validar" (los dos últimos solo con validación activada).
4. La tabla muestra: icono de rueda de menú (plato usado en una rueda), Nombre, Tipo de plato ("Sin tipo" si no tiene), Alérgenos ("Sin alérgenos" si no tiene), Tipo (GENERAL / MODIFICADO / EXCLUSIVO, solo en estructura estándar), una columna de bandera por idioma (con validación de traducciones: aviso rojo = pendiente, guion = vacío, verde = correcto) y Acciones.

### Crear un plato (rápido)
1. Pulsa **Crear plato** (verde). Si el buffet está sincronizado con Fichatec este botón no aparece.
2. Elige el **Tipo de plato**. Si el que necesitas no existe, pulsa **Añadir nuevo tipo de plato** (ver [17-tipos-de-plato](17-tipos-de-plato.md)).
3. Escribe **Nombre en <idioma>** para cada idioma del buffet. El del idioma principal es obligatorio. Los nombres se pasan a MAYÚSCULAS salvo que el buffet lo tenga desactivado.
4. Opcional: **Sugerir traducciones** rellena con traducción automática los idiomas vacíos. Revísalas siempre.
5. Marca los **Distintivos** que correspondan.
6. En las pestañas **Alérgenos** e **Ingredientes** marca lo que corresponda. Si el buffet tiene alérgenos e ingredientes a la vez, aparece el selector **Origen de alérgenos** (Plato / Ingredientes): con "Ingredientes", los alérgenos se calculan solos a partir de los ingredientes y no se pueden marcar a mano ("Alérgenos totales de los ingredientes seleccionados").
7. Con la función de trazas activa, marca las **Trazas**.
8. Si el buffet usa bandejas, elige las **Bandejas** ("Seleccionar bandejas").
9. Pulsa **Crear plato**. (**Cancelar** cierra sin guardar.)

Si el buffet no tiene ni alérgenos ni ingredientes activados, la ventana indica: "Las funciones de alérgenos e ingredientes están deshabilitadas. Para habilitarlas, póngase en contacto con soporte."

### Editar un plato
1. En la fila del plato pulsa **Editar** (amarillo) para el editor rápido, o **Edición avanzada** (morado) para el editor completo.
2. Cambia lo necesario y pulsa **Editar plato** (rápido) o **Guardar** (avanzado).

### Edición avanzada (seis pasos)
Se navega con **Anterior** / **Siguiente** y se guarda con **Guardar**. **Regresar** vuelve al listado.
1. **Información básica**: tipo, nombres, distintivos.
2. **Alérgenos e ingredientes**.
3. **Información adicional** (texto enriquecido).
4. **Pasos de preparación**: "Descripción del paso" y "Duración (minutos)".
5. **Valores nutricionales** (por 100 g): Energía, Calorías, Grasas totales, Grasas saturadas, Carbohidratos, Azúcares, Fibra, Proteínas, Sal.
6. **Multimedia**: arrastra imágenes ("Arrastra y suelta o haz clic aquí para seleccionar", "Añadir más imágenes"). La primera es la principal; la estrella "Establecer como principal" cambia cuál es. Para vídeos pega el enlace (p. ej. YouTube) y pulsa **Añadir**. Las imágenes y vídeos ya guardados se pueden borrar (pide confirmación).
- **Descargar ficha técnica** genera un PDF del plato (el plato debe estar guardado: "Primero debes guardar el plato").
- Con la función de desperdicio activa aparece **Ver pesajes**.

### Ver la ficha de un plato
Pulsa **Ver** (aparece si el plato tiene datos avanzados o viene de un ERP). Desde ahí puedes **Descargar ficha técnica**.

### Eliminar un plato
1. Pulsa **Eliminar** (rojo) en la fila y confirma "¿Estás seguro de que quieres eliminar este plato?".
2. Los platos de tipo GENERAL no se pueden eliminar (el botón está desactivado y el servidor responde "No autorizado").
3. Al borrar un plato se quitan también sus posiciones en los menús especiales.

### Validar alérgenos y traducciones
(Solo con la validación activada en el buffet; las etiquetas no muestran alérgenos ni traducciones sin validar.)
1. Un plato pendiente muestra el botón **Validar** (verde). Se abre "Validación de plato" con las opciones **Validar alérgenos** / **Validar todo**.
2. Para revertir, **Quitar validación** (naranja, con confirmación). Requiere tener ambos permisos de validación.
3. Validación masiva (solo superadministrador): **Validación masiva** con **Validar todo** / **Quitar toda la validación**, **Validar solo alérgenos** / **Quitar solo alérgenos**, **Validar solo traducciones** / **Quitar solo traducciones**. Si no eliges nada: "Por favor, selecciona un tipo de validación". Los platos de ERP se omiten.

### Importar platos
Requiere el permiso `import-dishes`. Pulsa **Importar platos**; la ventana depende del buffet:
- **Fichatec**: "Importar platos desde Fichatec", sincroniza todos los platos.
- **Avalon**: "Importar platos desde Avalon". Los platos locales con el mismo nombre se enlazan y pasan a ser de solo lectura; las recetas que ya no estén en Avalon se conservan. Resultado: "Importación de Avalon terminada: N creados, N actualizados, N adoptados, N sin cambios".
- **Excel** (por defecto), "Importación de platos":
  1. **Descargar plantilla** y rellénala sin cambiar su formato.
  2. Selecciona el archivo (.xlsx o .xls, máximo 5 MB).
  3. Pulsa **Revisar**: verás cuántas filas son nuevas, actualizadas, sin cambios o con errores, y avisos y cambios de alérgenos.
  4. Si hay cambios de alérgenos, marca "He revisado los cambios de alérgenos".
  5. Pulsa **Importar N platos**. Resultado: "N platos nuevos, N actualizados, N sin cambios".
  - Si la oferta está bloqueada (etiquetas online): solo el superadministrador puede importar ("se desbloqueará para importar y se bloqueará de nuevo después"). Los demás reciben "La oferta está bloqueada".
  - Si la oferta se comparte con otros buffets se muestra un aviso.

### Exportar platos
1. Pulsa **Exportar platos** (o el botón de exportar).
2. Elige "Todos los platos" o "Platos por categoría" y pulsa **Exportar**.

### Asignar bandejas
(Requiere edit-dishes y desperdicio u Operativa.) Entra en **Asignar bandejas**: es una matriz plato por bandeja que se guarda sola. La casilla de la cabecera aplica la bandeja a todos los platos filtrados (pide "¿Estás seguro?"). Mensaje de éxito: "Bandejas asignadas correctamente".

### Muestras testigo
(Solo con HEALTH_SAMPLE.) En el editor de un plato pulsa **Guardar muestra** ("Muestra guardada correctamente"). El listado **Muestras testigo** (solo superadministrador) permite abrir "Detalle de muestra testigo" con Plato, Tipo de plato, Usuario, Fecha, Traducciones, Alérgenos y Distintivos.

### Clasificar platos
**Clasificar platos** requiere edit-dishes y la función Operativa (A confirmar el detalle de la pantalla; ver [41-operativa](41-operativa.md)).

## Platos de ERP (solo lectura)
Si el buffet tiene Fichatec, Avalon o dTouch activado, los platos que vienen de ese sistema son "platos de ERP": llevan una insignia de candado con el nombre del ERP ("Importado de <ERP>: solo lectura").
- No se pueden editar ni crear desde la app: "Este plato está importado de <ERP>: su contenido se gestiona allí y no se puede cambiar desde la app."
- Con Fichatec tampoco se pueden crear platos: "Este buffet sincroniza sus platos desde Fichatec: no se pueden crear platos desde la app."
- Sí se pueden cambiar sus **imágenes y vídeos** con el botón **Imágenes y videos** (morado). En la edición avanzada se avisa: "Aquí solo puedes cambiar sus imágenes y vídeos."
- Para cambiar nombre, alérgenos, etc. hay que hacerlo en el ERP y volver a sincronizar.

## Problemas frecuentes y cómo resolverlos
- "El plato ya existe" → Ya hay un plato con ese nombre principal. Búscalo en el listado o cambia el nombre.
- "El campo del nombre principal es obligatorio" / "El idioma principal no puede estar vacío" → Rellena el nombre en el idioma principal.
- "Emojis y caracteres especiales no están permitidos en los nombres de plato: ..." → Quita esos caracteres del nombre.
- "Este plato está importado de ..." → Es un plato de ERP: cámbialo en el ERP. Aquí solo puedes cambiar imágenes y vídeos.
- No veo el botón **Crear plato** → O el buffet está sincronizado con Fichatec, o tu rol no tiene `create-dishes`.
- No puedo eliminar un plato → Si es GENERAL no se puede; si no, revisa que tengas `delete-dishes`.
- "No tienes permiso para importar platos" → Pide al administrador el permiso `import-dishes`.
- "No se ha enviado ningún archivo" / "Tiene que ser un archivo Excel (.xlsx o .xls)" / "El archivo supera los 5 MB" → Selecciona un Excel válido de menos de 5 MB.
- "La oferta está bloqueada" → Las etiquetas están online; solo un superadministrador puede importar. Para un cambio puntual usa la edición del plato.
- "El enlace ya ha sido agregado" / "URL inválida" (vídeos) → El vídeo ya está añadido o el enlace no es válido.
- "Este buffet todavía no tiene bandejas" / "Este buffet no tiene acceso al sistema de bandejas" → Hay que crear bandejas o activar la función; contacta con soporte.
- No aparecen alérgenos ni ingredientes en el formulario → Las funciones están desactivadas para el buffet; contacta con soporte.

## Preguntas típicas de clientes
- **¿Por qué no puedo editar un plato?** Probablemente viene de un ERP (Fichatec, Avalon o dTouch) y es de solo lectura, o tu rol no tiene `edit-dishes`.
- **¿Cómo pongo fotos a un plato?** Edición avanzada, paso Multimedia. También funciona con platos de ERP.
- **¿Por qué mis alérgenos no salen en la etiqueta?** Si el buffet usa validación, hay que validarlos antes (botón **Validar**).
- **¿Puedo cargar muchos platos a la vez?** Sí, con **Importar platos** (Excel, Avalon o Fichatec según el buffet).
- **¿Qué pasa si borro un plato usado en una rueda?** Se quitan sus posiciones en menús especiales; para ruedas A confirmar.
- **¿Por qué mi nombre sale en mayúsculas?** El buffet convierte los nombres a mayúsculas salvo que lo tenga desactivado.
- **¿Cómo pongo el nombre en varios idiomas?** Rellena cada "Nombre en <idioma>" o usa **Sugerir traducciones** y revísalas.
- **¿Puedo descargar la ficha del plato?** Sí, **Descargar ficha técnica** (PDF).

## Términos y relación con otros apartados
- **Plato GENERAL / MODIFICADO / EXCLUSIVO**: tipo de origen del plato (estructura estándar).
- **Plato de ERP**: plato sincronizado desde Fichatec, Avalon o dTouch, solo lectura.
- Tipos de plato: [17-tipos-de-plato](17-tipos-de-plato.md). Ingredientes: [13-ingredientes](13-ingredientes.md). Alérgenos y distintivos: [14-alergenos](14-alergenos.md). Hoja de oferta: [16-ofertas-hoja-oferta](16-ofertas-hoja-oferta.md).
- Uso de los platos: [23-ruedas-de-menu](23-ruedas-de-menu.md), [25-menu-del-dia](25-menu-del-dia.md), [26-menus-especiales](26-menus-especiales.md), [24-tematicos](24-tematicos.md).
- Idiomas y traducciones: [10-idiomas](10-idiomas.md), [11-traducciones](11-traducciones.md). Permisos: [04-roles-permisos](04-roles-permisos.md). Etiquetas: [30-etiquetas](30-etiquetas.md).
