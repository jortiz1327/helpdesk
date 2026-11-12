# Ingredientes

## Para qué sirve
Permite mantener la lista de ingredientes del hotel y los alérgenos que contiene cada uno. Después, al crear un plato, se eligen ingredientes y los alérgenos del plato se calculan automáticamente a partir de ellos.

## Quién lo usa y dónde está
- Menú lateral: **Ingredientes** (ruta `/ingredients`).
- Solo aparece si el buffet tiene activada la función de ingredientes (DISH_INGREDIENTS) y el rol tiene el permiso `view-ingredients`.
- Crear, editar y eliminar requieren `create-ingredients`, `edit-ingredients` y `delete-ingredients` ([04-roles-permisos](04-roles-permisos.md)).
- **Importar ingredientes** es solo para el superadministrador.
- Los ingredientes pertenecen al hotel: los comparten los buffets del mismo hotel.

## Cómo se hace (paso a paso)

### Ver ingredientes
1. Entra en **Ingredientes**. La tabla muestra Nombre, Alérgenos y Acciones.
2. Usa **Buscar** para filtrar.
3. Si no hay buffet seleccionado: "Tienes que seleccionar un buffet para ver los ingredientes disponibles".

### Crear un ingrediente
1. Pulsa **Crear nuevo ingrediente**.
2. Escribe el **Nombre** (obligatorio).
3. Marca los **Alérgenos** que contiene.
4. Pulsa **Crear**.
También se puede crear desde el formulario de un plato (pestaña Ingredientes, **Crear nuevo ingrediente**) si se tiene `create-ingredients`.

### Editar un ingrediente
1. En la fila pulsa el botón de editar.
2. Cambia nombre o alérgenos y pulsa **Editar**.
3. Los platos que usan ese ingrediente (A confirmar si se recalculan sus alérgenos automáticamente).

### Eliminar un ingrediente
1. Pulsa eliminar en la fila.
2. Confirma "¿Estás seguro de que quieres eliminar este ingrediente?".

### Importar ingredientes (superadministrador)
1. Pulsa **Importar ingredientes** ("Importación de ingredientes").
2. **Descargar plantilla**: columnas Referencia, Nombre y una columna por alérgeno con Si/No.
3. Sube el archivo. Resultado: "Ingredientes importados correctamente".

## Problemas frecuentes y cómo resolverlos
- No veo el menú **Ingredientes** → La función no está activada en el buffet o tu rol no tiene `view-ingredients`. Contacta con soporte o con el administrador.
- "No se ha enviado ningún archivo" (importación) → Selecciona el archivo antes de importar.
- "Tienes que seleccionar un buffet para ver los ingredientes disponibles" → Selecciona un buffet en la parte superior.
- No puedo marcar los alérgenos de un plato → El plato tiene "Origen de alérgenos: Ingredientes"; los alérgenos salen de los ingredientes. Cambia el ingrediente o el origen a "Plato".
- No veo **Importar ingredientes** → Es solo para superadministradores.

## Preguntas típicas de clientes
- **¿Para qué sirven los ingredientes?** Para que los alérgenos de un plato se calculen solos.
- **¿Tengo que usarlos?** No, se puede indicar los alérgenos directamente en el plato (origen "Plato").
- **¿Puedo cargar muchos ingredientes?** Sí, por Excel, pero lo hace un superadministrador.
- **¿Se comparten entre buffets?** Pertenecen al hotel, así que sí entre buffets del mismo hotel.
- **¿Puedo poner precio al ingrediente?** No; el campo de precio no se muestra.
- **¿Por qué no me sale el menú?** Función desactivada o falta de permiso.

## Términos y relación con otros apartados
- **Origen de alérgenos**: Plato o Ingredientes (selector en el formulario de [12-platos](12-platos.md)).
- Los alérgenos disponibles se gestionan en [14-alergenos](14-alergenos.md).
- Permisos: [04-roles-permisos](04-roles-permisos.md).
