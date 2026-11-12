# Roles y permisos

## Para qué sirve
Define qué puede ver y hacer cada tipo de usuario. Cada rol es un conjunto de permisos y una "página de inicio predeterminada". Al asignar un rol a un usuario, este solo ve los menús y botones que su rol permite.

## Quién lo usa y dónde está
- Menú lateral: **Ajustes > Roles** (`/configuration/roles`). Permisos: ver (`view-roles`), crear (`create-roles`), editar (`edit-roles`) y eliminar (`delete-roles`).
- Los roles pertenecen a un grupo de hoteles. Al crear un grupo se crean automáticamente estos roles: Dirección (todos los permisos), Chef, Cocina, Metre, Técnico IT y Básico.
- Cambiar la página de inicio de un rol requiere el permiso de editar roles.

## Cómo se hace (paso a paso)

### Consultar los roles
1. Entra en **Ajustes > Roles**.
2. El listado muestra **Nombre**, **Página de inicio predeterminada** y **Acciones** (**Editar**, **Eliminar**).

### Crear un rol
1. Pulsa **Crear nuevo rol**.
2. Escribe el **Nombre** (obligatorio).
3. En **Permisos** (obligatorio) marca los permisos, agrupados por categoría (roles, usuarios, platos, isletas, ingredientes, ruedas de menú, menús, servicios, posiciones, idiomas, alérgenos, etiquetas, antenas, operativa, restaurante y general). Usa **Seleccionar todos** para marcar toda una categoría.
4. Pulsa **Guardar** (o **Cancelar** para salir).

### Editar un rol
1. Pulsa **Editar** en la fila, cambia nombre o permisos y pulsa **Guardar**.

### Elegir la página de inicio predeterminada
1. En la columna **Página de inicio predeterminada** abre el selector y elige una opción; se guarda al cambiarla.
2. Opciones: Inicio, Contenido actual, Usuarios, Servicios, Platos, Ingredientes (requiere la función de ingredientes en platos), Isletas, Posiciones, Menús, Conectividad, Menús de eventos (función de menús de evento), Planograma y Productividad (módulo Operativa) y Reservas del restaurante (módulo Restaurante).
3. Si al iniciar sesión el módulo elegido no está activo para el buffet, el usuario va a **Contenido actual**.

### Eliminar un rol
1. Pulsa **Eliminar** y confirma en la ventana.
2. Los usuarios que tengan ese rol pasan a no tener ningún rol asignado.

### Permisos con nombre especial
- **Ver estadísticas**: muestra Inicio ([Inicio](01-inicio-dashboard.md)). **Ver informes**: muestra Logs ([Logs](06-logs.md)).
- **Validar alérgenos**, **Validar traducciones**, **Cambiar platos**, **Forzar menús**, **Bloquear/Desbloquear servicios**, **Activar/Desactivar idiomas**, **Ordenar idiomas**, **Modificar iconos de alérgenos**, **Invertir etiquetas**, **Forzar etiquetas**, **Añadir etiquetas a eventos**.
- Umbral de alertas de rueda no seguida: permiso `configure-menu-wheel-threshold` (ver [Perfil](05-perfil.md)).

## Problemas frecuentes y cómo resolverlos
- **"El número máximo de roles ha sido alcanzado"** → hay un tope por grupo (configurado en 20). Elimina un rol que no uses. El texto del servidor menciona 10: A confirmar cuál es el límite real mostrado al cliente.
- **No puedo guardar el rol** → el nombre y al menos un permiso son obligatorios.
- **Un usuario no ve un menú** → su rol no tiene el permiso correspondiente; edítalo aquí.
- **Un usuario se quedó sin rol tras borrar uno** → asígnale otro en [Usuarios](03-usuarios.md). Sin rol no se puede habilitar.
- **Un usuario cae en Contenido actual al entrar** → el módulo de su página de inicio no está activo en el buffet.
- **La opción de página de inicio no aparece** → depende de las funciones activas del buffet (ingredientes, eventos, Operativa, Restaurante).
- **No puedo cambiar la página de inicio** → falta el permiso de editar roles.

## Preguntas típicas de clientes
- **¿Qué roles vienen por defecto?** Dirección, Chef, Cocina, Metre, Técnico IT y Básico.
- **¿Puedo crear un rol propio?** Sí, con Crear nuevo rol.
- **¿Qué pasa al borrar un rol?** Sus usuarios quedan sin rol.
- **¿Puedo hacer que un cocinero entre directamente a su pantalla?** Sí, con la página de inicio predeterminada del rol.
- **¿Los cambios de permisos son inmediatos?** A confirmar; la caché de permisos se limpia al modificar roles.
- **¿Los roles se comparten entre hoteles?** Se comparten dentro del mismo grupo.
- **¿Cuántos roles puedo tener?** Hay un máximo por grupo (A confirmar, 20 según configuración).

## Términos y relación con otros apartados
- **Permiso**: acción concreta autorizada; **función del buffet**: módulo activado por hotel que además condiciona qué aparece.
- Relacionado con [Usuarios](03-usuarios.md), [Inicio](01-inicio-dashboard.md), [Contenido actual](02-contenido-actual.md), [Contenedores, hoteles, buffets y grupos](33-contenedores-hoteles-buffets-grupos.md).
