# Perfil

## Para qué sirve
Es la ficha personal de cada usuario: foto, nombre, contraseña y preferencias de notificaciones. Cada persona gestiona aquí sus propios datos sin depender de un administrador.

## Quién lo usa y dónde está
- Cualquier usuario con sesión iniciada, desde el menú de usuario de la cabecera (ruta `/profile`). No requiere permiso específico.
- Algunas notificaciones dependen del permiso o de la función: "Reservas del restaurante" solo si el usuario puede ver reservas; el umbral de rueda no seguida exige un permiso especial.

## Cómo se hace (paso a paso)

### Cambiar la foto
1. Pulsa el icono del lápiz sobre la foto.
2. Elige una imagen (jpeg, png, jpg, gif o svg, máximo 2 MB).

### Cambiar el nombre
1. Edita **Nombre**.
2. Pulsa **Guardar**; aparece "Guardado.".
3. El **correo electrónico** y el **Rol** se muestran pero no se pueden editar desde aquí (los cambia un administrador en [Usuarios](03-usuarios.md)).
4. **PIN de báscula** es de solo lectura: "Este PIN se genera automáticamente y no se puede modificar."

### Cambiar la contraseña
1. En **Actualizar contraseña** escribe **Contraseña actual**, **Nueva Contraseña** y **Confirmar contraseña** (el icono del ojo muestra el texto).
2. Guarda. La nueva contraseña debe cumplir las reglas de seguridad de la aplicación (A confirmar los requisitos exactos).

### Configurar las notificaciones
1. Abre el **Centro de notificaciones**.
2. Activa o desactiva el interruptor de cada tipo: **Antenas desconectadas**, "Menu wheel not followed" (rueda de menú no seguida) y **Reservas del restaurante** (si procede).
3. Pulsa **Configurar alertas de correo** para elegir los buffets de los que quieres recibir el aviso; marca los buffets y pulsa **Guardar cambios** (o **Cancelar**).
4. En "Menu wheel not followed" aparece una tabla **Buffet** / "Maximum changes per service" (mínimo 1): es el número de cambios por servicio a partir del cual se avisa. Solo se edita con el permiso correspondiente; si no, aparece bloqueado.

### Modo oscuro
1. En el menú de usuario de la cabecera pulsa **Modo oscuro** / **Modo claro**. La preferencia se guarda.

## Problemas frecuentes y cómo resolverlos
- **No puedo cambiar mi correo ni mi rol** → es normal; pídeselo a un administrador.
- **La foto no se sube** → comprueba que sea jpeg, png, jpg, gif o svg y que no supere 2 MB.
- **Al cambiar la contraseña da error** → revisa que la contraseña actual sea correcta, que la nueva y su confirmación coincidan y que cumpla las reglas.
- **No recibo avisos de antenas** → comprueba que el interruptor está activo y que el buffet está marcado en **Configurar alertas de correo**.
- **No puedo editar el máximo de cambios por servicio** → falta el permiso de configurar el umbral ([Roles y permisos](04-roles-permisos.md)).
- **No veo "Reservas del restaurante"** → solo aparece si tu rol puede ver reservas.
- **Quiero borrar mi cuenta** → no está disponible en pantalla; contacta con un administrador.

## Preguntas típicas de clientes
- **¿Cómo cambio mi contraseña?** En Perfil > Actualizar contraseña.
- **¿Qué es el PIN de báscula?** Un código que se genera solo, para usar en la báscula (A confirmar su uso exacto, ver [Operativa](41-operativa.md)).
- **¿Puedo cambiar el idioma de la aplicación?** A confirmar: no hay selector de idioma en el perfil.
- **¿Cómo dejo de recibir correos de antenas?** Desactiva el interruptor en el Centro de notificaciones.
- **¿Puedo elegir de qué hoteles recibo avisos?** Sí, en Configurar alertas de correo.
- **¿Puedo cambiar mi nombre?** Sí, el correo no.
- **¿Cómo activo el modo oscuro?** Desde el menú de usuario.

## Términos y relación con otros apartados
- **Centro de notificaciones**: avisos por correo configurables por usuario.
- Relacionado con [Usuarios](03-usuarios.md), [Roles y permisos](04-roles-permisos.md), [Conectividad y antenas](31-conectividad-antenas.md), [Ruedas de menú](23-ruedas-de-menu.md), [Restaurante y reservas](50-restaurante-reservas.md).
