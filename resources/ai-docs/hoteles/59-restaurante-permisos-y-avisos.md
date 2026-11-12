# Restaurante: Roles, permisos y avisos

## Para qué sirve
Explica quién puede hacer qué en el módulo Restaurante y cómo se avisa al personal de las reservas nuevas. Útil para responder "por qué no veo este botón" o "quién recibe los correos".

## Quién lo usa y dónde está
- Los permisos se asignan a un rol en [Roles y permisos](04-roles-permisos.md) (categoría "restaurant") y el rol a los usuarios en [Usuarios](03-usuarios.md).
- Al activar el módulo en un buffet (o crear un restaurante independiente) se crean dos roles dentro del grupo:
  - **«Restaurante · Estándar»**: ver reservas, crear (pendientes) y editar pendientes.
  - **«Restaurante · Administrador»**: todos los permisos del restaurante y gestión de usuarios (ver, crear, editar, borrar).
  Ambos tienen **Reservas** como pantalla de inicio.
- Si el grupo ya tiene 20 roles sale el aviso "No se ha creado el rol...: el grupo ya tiene el máximo de roles."

## Cómo se hace (paso a paso)

### Qué permiso da cada cosa
| Permiso | Permite |
|---|---|
| View reservations | Ver Reservas y series |
| Create reservations (pending validation) | Nueva reserva (pendiente), Nueva serie, Reenviar correo |
| Edit or cancel pending reservations | Editar/cancelar pendientes, cancelar serie |
| Validate or reject reservations | Validar/rechazar y aprobar/rechazar cambios, Aprobar todo |
| Create, edit or cancel confirmed reservations | Crear ya confirmada, tocar/cancelar confirmadas |
| Confirm reservations over capacity | Superar aforo |
| Edit restaurant menus | Carta y menús |
| View restaurant reports | Informes |
| Edit restaurant settings | Idiomas y mensajes, Ajustes, turnos, cierres, tótems |

### Dar acceso a una persona
1. Crea o edita un rol con los permisos necesarios.
2. Asigna el rol al usuario ([Usuarios](03-usuarios.md)).
3. Comprueba que el usuario tiene el buffet/restaurante en su alcance.

### Avisos por correo al personal
- **Correo que recibe los avisos** en [Ajustes](54-restaurante-ajustes.md): recibe solicitudes nuevas, cancelaciones del cliente y peticiones de cambio.
- Cada usuario puede activar **Reservas del restaurante** en las notificaciones de su perfil. Ruta exacta: A confirmar.
- El cliente recibe sus propios correos (solicitud, confirmación, rechazo, cambios), editables en [Idiomas y mensajes](53-restaurante-textos.md).

## Problemas frecuentes y cómo resolverlos
- **No veo el menú Restaurante** → módulo inactivo o sin permiso View reservations.
- **Veo Reservas pero no Ajustes** → falta Edit restaurant settings.
- **No puedo validar** → falta Validate or reject reservations.
- **No puedo confirmar por aforo** → falta el permiso de superar aforo.
- **No llegan avisos** → revisa correo de Ajustes y notificaciones del perfil.
- **No se crearon los roles** → límite de 20 roles por grupo.
- **Un usuario nuevo entra en otra pantalla** → su rol no tiene como inicio Reservas.

## Preguntas típicas de clientes
- **¿Qué rol doy a recepción?** Estándar suele bastar; si debe validar, añade Validate.
- **¿Quién puede cancelar confirmadas?** Con el permiso de gestionar confirmadas.
- **¿Puedo crear un rol propio?** Sí, en Roles y permisos.
- **¿Quién ve los informes?** Quien tenga View restaurant reports.
- **¿Cómo dejo de recibir correos?** Desactívalo en tu perfil o cambia el correo de Ajustes.
- **¿Cada restaurante tiene sus usuarios?** Los roles se crean en el grupo; el alcance se asigna por usuario.

## Términos y relación con otros apartados
- **Rol**, **permiso**, **grupo**. Relacionado: [Usuarios](03-usuarios.md), [Roles y permisos](04-roles-permisos.md), [Reservas](50-restaurante-reservas.md), [Ajustes](54-restaurante-ajustes.md), [Restaurante independiente](57-restaurante-independiente.md).
