# Administración (panel de administración)

## Para qué sirve
Es un panel aparte, pensado para el administrador de la plataforma y para los administradores de grupo, desde donde se ve el estado general del grupo (antenas, etiquetas, usuarios, cambios temporales) y se gestionan usuarios, platos generales del grupo y motivos de cambio.

## Quién lo usa y dónde está
- Acceso: menú de perfil (arriba a la derecha) > **Panel Administración**.
- Pueden entrar: el administrador de la plataforma (superadmin) y los usuarios marcados como administradores de grupo (con acceso a los contenedores del grupo). Estos últimos solo ven su propio grupo.
- Si no se tiene acceso aparece "No tiene permisos para acceder a esta sección.".
- Si hay más de un grupo (superadmin) aparece un selector de grupo; un grupo que se está mudando no se puede abrir.
- Menú del panel: **Panel**, **Gestión de usuarios**, **Gestión de platos**, **Motivos de cambio** y **Volver a la aplicación**.
- Otras opciones técnicas de este panel (ofertas, bloqueos de oferta, traducciones, pruebas) son solo del administrador de la plataforma y no están pensadas para clientes.

## Cómo se hace (paso a paso)

### Ver el panel (resumen)
1. Entra en **Panel Administración**, pestaña **Panel**.
2. Tarjetas: **Antenas Conectadas**, **Etiquetas Conectadas** y **Usuarios Activos** (Activo / Inactivo). Los datos recogen todos los buffets del grupo activo.
3. **Top 10 - Hoteles con más cambios temporales** ("Ranking por número total de cambios temporales"): elige **Desde** y **Hasta** y pulsa **Filtrar**. Si no hay datos: "No hay datos disponibles".

### Gestionar usuarios
1. Entra en **Gestión de usuarios**.
2. La tabla muestra **Nombre**, **Email**, **Rol** ("No se ha asignado un rol"), **Estado** y **Acciones**. Usa "Filtrar por hoteles" para acotar.
3. Para crear, pulsa **Crear nuevo usuario**.
4. En **Acciones**: **Editar**, **Ver detalles** y **Eliminar**. Se puede cambiar el estado (activo/inactivo).
5. Eliminar pide tu contraseña: "Una vez que elimine este usuario, todos sus datos y registros se eliminarán permanentemente. Ingrese su contraseña si desea eliminar permanentemente al usuario." y se confirma con **Eliminar usuario**.
6. Más información en [Usuarios](03-usuarios.md).

### Gestionar platos generales del grupo
1. Entra en **Gestión de platos**.
2. La tabla muestra **Nombre**, **Tipo de plato** ("Sin tipo de plato"), **Alérgenos** ("Sin alérgenos"), **Tipo** (GENERAL, MODIFICADO o EXCLUSIVO) y **Acciones** (Editar). Usa "Filtrar por tipo".
3. Para crear, pulsa **Crear nuevo plato general**.
4. El formulario tiene varios pasos: Información básica, Información adicional, Multimedia, Pasos de preparación y Alérgenos/Distintivos. Usa **Siguiente** / **Anterior** y al final **Guardar**. "Sugerir traducciones" ayuda con las traducciones.
5. Si el nombre ya existe: "El plato ya existe".
6. Detalle de platos en [Platos](12-platos.md).

### Gestionar motivos de cambio
1. Entra en **Motivos de cambio**.
2. Pulsa **Crear nuevo motivo de cambio**, escribe el **Motivo** (obligatorio, máximo 255 caracteres) y pulsa **Guardar**.
3. Para modificarlo, edítalo y pulsa **Guardar cambios**.
4. Para eliminarlo, confirma "¿Estás seguro de que quieres eliminar este motivo de cambio?".
5. Los motivos se piden al hacer cambios programados o temporales (ver [Cambios programados y temporales](27-cambios-programados-y-temporales.md)). A confirmar el momento exacto en que se solicitan.

## Problemas frecuentes y cómo resolverlos
- **No veo "Panel Administración"** → tu usuario no es administrador de grupo ni de la plataforma; pídelo a soporte.
- **"No tiene permisos para acceder a esta sección."** → mismo caso.
- **No puedo abrir un grupo** → se está mudando de aplicación; espera.
- **La tabla de usuarios no muestra a alguien** → revisa el filtro por hoteles.
- **No puedo eliminar un usuario** → se pide tu contraseña; si no coincide, no se elimina.
- **"El plato ya existe"** → ya hay un plato con ese nombre en el grupo; edítalo o cambia el nombre.
- **El ranking sale vacío** → no hay cambios temporales en el rango de fechas; amplía Desde/Hasta.

## Preguntas típicas de clientes
- **¿Qué diferencia hay entre este panel y la aplicación normal?** El panel trabaja a nivel de todo el grupo; la aplicación trabaja con un buffet.
- **¿Quién puede crear usuarios?** Administradores con acceso; desde aquí o desde Usuarios.
- **¿Qué es un plato GENERAL, MODIFICADO o EXCLUSIVO?** A confirmar con precisión; el nombre indica si es común a todo el grupo, adaptado en un buffet o propio de uno.
- **¿Puedo ver cuántas antenas están caídas?** Sí, en la tarjeta Antenas Conectadas (detalle en [Conectividad y antenas](31-conectividad-antenas.md)).
- **¿Cómo vuelvo a la aplicación?** Con **Volver a la aplicación**.
- **¿Se pueden recuperar los usuarios eliminados?** No: se eliminan de forma permanente.
- **¿Se registran los cambios?** Hay un registro de actividad, ver [Logs](06-logs.md).

## Términos y relación con otros apartados
- **Administrador de la plataforma (superadmin)**: rol de soporte con acceso total.
- **Administrador de grupo**: usuario con acceso a todos los contenedores de su grupo.
- Estructura de grupos y hoteles: [Contenedores](33-contenedores-hoteles-buffets-grupos.md).
- [Usuarios](03-usuarios.md), [Roles y permisos](04-roles-permisos.md), [Platos](12-platos.md), [Cambios programados y temporales](27-cambios-programados-y-temporales.md), [Inicio](01-inicio-dashboard.md).
