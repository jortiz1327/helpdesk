# Usuarios

## Para qué sirve
Permite crear y gestionar las personas que acceden a la aplicación: su rol, los hoteles/buffets a los que acceden y si están activas o no. También permite generar un enlace de registro para que alguien se dé de alta por sí mismo (solo superadministrador).

## Quién lo usa y dónde está
- Menú lateral: **Usuarios** (`/users`). Permisos: ver (`view-users`), crear (`create-users`), editar (`edit-users`) y eliminar (`delete-users`).
- Los usuarios que no son superadministrador no pueden ver ni editar a los usuarios con rol de administrador.
- La casilla **Tiene acceso a todos los contenedores** y el botón **Generar código de registro** solo los ve el superadministrador.
- El panel de administración tiene además su propio listado de usuarios (A confirmar los detalles; ver [Administración](35-administracion.md)).

## Cómo se hace (paso a paso)

### Consultar usuarios
1. Entra en **Usuarios**.
2. El listado muestra: **Nombre**, **Correo electrónico** (aparece "Sin email" si no tiene), **Rol**, **Estado** y **Acciones**.
3. Usa el buscador para filtrar.
4. En **Acciones** hay **Editar**, **Ver detalles** y **Eliminar**.
5. En **Ver detalles** hay dos pestañas: **Detalles** y **Actividad** (registro de acciones del usuario, ver [Logs](06-logs.md)).

### Crear un usuario
1. Pulsa **Crear nuevo usuario**.
2. Rellena **Nombre** (mínimo 3 caracteres), **Correo electrónico** (no puede estar repetido), **Número de teléfono**, **Rol** (obligatorio), **Contraseña** y **Confirmar contraseña**.
3. En "Seleccione a que hoteles tiene acceso este usuario" marca los buffets/hoteles. Puedes activar **Agrupar por país** para localizarlos mejor.
4. Pulsa **Crear usuario**.
5. Debes marcar al menos un buffet, salvo que el rol sea administrador o (superadministrador) actives **Tiene acceso a todos los contenedores**.

### Editar un usuario
1. En el listado pulsa **Editar**.
2. Cambia los datos necesarios (la contraseña no se cambia aquí) y pulsa **Editar usuario**.

### Cambiar la contraseña de un usuario
1. Dentro de la edición pulsa **Cambiar contraseña**.
2. Escribe **Nueva contraseña** y **Confirmar nueva contraseña** (mínimo 6 caracteres).
3. Pulsa **Guardar cambios**.

### Activar o desactivar un usuario
1. En la columna **Estado** pulsa sobre **Activo/Inactivo** (el tooltip dice "Toque para desactivar/activar").
2. Un usuario inactivo no puede entrar (A confirmar el mensaje que ve).

### Eliminar un usuario
1. Pulsa **Eliminar** en la fila.
2. En la ventana escribe tu propia contraseña actual y confirma con **Eliminar usuario**.

### Generar un código de registro (solo superadministrador)
1. Pulsa **Generar código de registro**.
2. Se muestra un QR y una URL; usa **Copiar URL al portapapeles** para compartirla. "La URL solo será válida por 24 horas".
3. La persona se registra desde ese enlace; su cuenta queda **desactivada y sin rol**, con acceso a los buffets del hotel. Debe esperar a que un administrador la habilite.
4. Para habilitarla: asigna un rol en **Editar** y actívala en **Estado**.

## Problemas frecuentes y cómo resolverlos
- **"Por favor, seleccione al menos un buffet."** → marca algún buffet en el selector de acceso.
- **"No puedes deshabilitar tu propia cuenta."** → pide a otro administrador que lo haga.
- **"Antes de habilitar un usuario, debe asignarle un rol."** → edita el usuario, asígnale un rol y vuelve a activarlo.
- **El correo ya existe** → cada usuario necesita un correo distinto.
- **No me deja eliminar** → la contraseña que pide es la tuya (de quien elimina); si es incorrecta falla la validación.
- **Una persona se registró con el enlace y no puede entrar** → su cuenta está pendiente: asígnale rol y actívala.
- **El enlace de registro caducó** → genera uno nuevo (válido 24 horas).
- **No veo usuarios administradores** → solo los ve el superadministrador.

## Preguntas típicas de clientes
- **¿Cómo doy acceso a un empleado nuevo?** Con Crear nuevo usuario (rol y buffets) o con el enlace de registro (lo genera el superadministrador).
- **¿Puedo limitar a qué hotel accede?** Sí, en el selector de hoteles al crear/editar.
- **¿Cómo restablezco la contraseña de alguien?** Editar > Cambiar contraseña.
- **¿Puedo desactivar a alguien sin borrarlo?** Sí, con el interruptor de Estado.
- **¿Dónde veo qué ha hecho un usuario?** En Ver detalles > Actividad.
- **¿Cuánto dura el enlace de registro?** 24 horas.
- **¿Cada usuario puede cambiar su propia contraseña?** Sí, desde su [Perfil](05-perfil.md).

## Términos y relación con otros apartados
- **Rol**: conjunto de permisos ([Roles y permisos](04-roles-permisos.md)).
- Relacionado con [Perfil](05-perfil.md), [Logs](06-logs.md), [Contenedores, hoteles, buffets y grupos](33-contenedores-hoteles-buffets-grupos.md), [Administración](35-administracion.md).
