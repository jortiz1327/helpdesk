# Contenedores: hoteles, buffets y grupos

## Para qué sirve
Los "contenedores" son la estructura en la que se organiza todo: un **Grupo** (la cadena o cuenta) contiene **Hoteles**, y cada hotel contiene **Buffets** (o **Restaurantes** independientes). Desde aquí el administrador de la plataforma crea y configura cada nivel, activa las funciones de cada buffet, conecta integraciones externas y elige con qué buffet se trabaja.

## Quién lo usa y dónde está
- Menú lateral: **Hoteles**. Es solo para el administrador de la plataforma (superadmin); el resto de usuarios recibe "No tiene permisos para acceder a esta sección.".
- **Selector de buffet activo**: cualquier usuario puede cambiar de buffet, pero solo entre los que tiene asignados (si no, error de acceso). A confirmar dónde se muestra el selector en pantalla (en el menú lateral está oculto).
- Activar funciones e integraciones: solo administrador de la plataforma.
- Si el grupo se está mudando de aplicación aparece "Tu hotel se está mudando de aplicación. Podrás entrar en un rato." y no se puede entrar.

## Cómo se hace (paso a paso)

### Crear un contenedor nuevo (solo administrador de la plataforma)
1. En **Hoteles** pulsa **Añadir nuevo contenedor**.
2. Elige el **Tipo de contenedor** (Grupo, Hotel, Buffet, Restaurante u otros) y pulsa **Siguiente**.
3. Rellena el formulario del tipo elegido y pulsa **Crear** (muestra "Creando..."):
   - **Grupo**: nombre, usuario y contraseña de la cuenta cloud (la contraseña se guarda cifrada).
   - **Hotel**: nombre, grupo al que pertenece, zona horaria y logo.
   - **Buffet**: nombre, hotel, idiomas, fuente de iconos de alérgenos, estructura de platos (estándar u **ofertas**, y en ese caso la oferta) y, si ya existe la tienda en el cloud, su storeId y externalId.
   - **Restaurante**: igual que un buffet pero sin tienda en el cloud (no tiene etiquetas).
4. Si la configuración falla, el contenedor se elimina y se muestra el error.
5. También se pueden crear nuevos tipos de contenedor por nivel (nombre único).

### Editar un grupo
1. En **Hoteles** abre el grupo.
2. Cambia el nombre, el usuario cloud o la contraseña cloud. Si dejas la contraseña en blanco se mantiene la actual. Las credenciales se comprueban contra el cloud antes de guardar.

### Editar un hotel
1. Abre el hotel. Puedes cambiar nombre, logo (hasta 2 MB; jpeg, png, jpg, gif o svg), moneda (la heredan sus buffets), datos de facturación y avisos de impago.

### Editar un buffet
1. Abre el buffet. Datos: nombre, storeId, externalId, oferta actual, oferta wellness y **Formato de envío del plato** (estándar u ofertas).
2. En **Preferencias** hay interruptores, por ejemplo **Nombres de platos en minúsculas** (permite escribir sin conversión automática a mayúsculas), **Máximo de alérgenos por línea** (en la etiqueta) y **Activar menú del día**.

### Activar o desactivar funciones del buffet (solo administrador de la plataforma)
1. En la ficha del buffet, usa los interruptores de funciones.
2. Funciones disponibles: Menús de evento (nivel hotel), Validación de traducciones, Validación de alérgenos, Menú del día, Alérgenos de platos, Ingredientes de platos, Sistema de desperdicio, Salida de cocina, Planograma, Operativa, Wellness, Trazas de platos, Muestra testigo, Temáticos, Comunicaciones, Fichatec, dTouch, Avalon y Restaurante. El plano (Planner) y la hoja de oferta vienen incluidos de serie.
3. Algunas dependen de otras (por ejemplo Salida de cocina necesita Sistema de desperdicio); el sistema muestra el motivo si no deja activarla.
4. Al activar una función aparece su menú o botón (por ejemplo **Comunicaciones** en Menús, ver [Comunicaciones](32-comunicaciones.md)).

### Conectar una integración (solo administrador de la plataforma)
1. En la ficha del buffet, bloque **Integraciones** ("Conexiones con sistemas externos. Cada una se activa con sus credenciales y se configura desde su engranaje.").
2. Activa el interruptor de **Fichatec**, **dTouch** o **Avalon**; se abre la ventana de credenciales.
3. Introduce usuario y contraseña (nunca se muestran en esta documentación) y guarda. Se comprueba la conexión ("Conexión establecida" / "Conexión correcta: N platos disponibles").
4. dTouch: pulsa **Cargar** para traer los espacios de trabajo de la cuenta y elige el del buffet.
5. Avalon: pulsa **Cargar grupos** y marca al menos un grupo de recetas; elige la hora de **Importar cada día a las**.
6. Fichatec: elige cada cuánto **Sincronizar**.
7. Para cambiarlo luego, usa el engranaje de esa integración.
8. Los platos que llegan de una integración externa quedan bloqueados para editarlos a mano (A confirmar el alcance exacto por integración).

### Cambiar de buffet activo
1. Selecciona el buffet en el selector de buffet.
2. La aplicación guarda el buffet y el grupo en tu sesión y todas las pantallas pasan a trabajar con él.

### Eliminar un contenedor (solo administrador de la plataforma)
1. En la ficha, pulsa eliminar.
2. Para un grupo hay que escribir el nombre del grupo para confirmar.
3. Al borrar un buffet se puede elegir borrar también sus platos. Al borrar un hotel se borran sus restaurantes.
4. Pide confirmación porque es destructivo.

## Problemas frecuentes y cómo resolverlos
- **No veo el menú Hoteles** → solo lo ve el administrador de la plataforma.
- **No puedo entrar en un buffet** → no lo tienes asignado; pide acceso (ver [Usuarios](03-usuarios.md)).
- **"Tu hotel se está mudando de aplicación..."** → espera unos minutos y vuelve a intentarlo.
- **Un menú o botón no aparece (Comunicaciones, Temáticos, Menú del día...)** → la función no está activada en ese buffet.
- **No deja activar una función** → depende de otra; actívala primero (mensaje en pantalla).
- **Error al guardar credenciales de grupo, Fichatec, dTouch o Avalon** → las credenciales no son válidas o el servicio externo no responde; revísalas.
- **Al crear un buffet falla y desaparece** → si la configuración inicial falla se elimina; repite y revisa storeId/externalId y la oferta.
- **En Avalon "Elige al menos un grupo"** → pulsa Cargar grupos y marca uno.

## Preguntas típicas de clientes
- **¿Qué diferencia hay entre grupo, hotel y buffet?** El grupo agrupa hoteles, el hotel agrupa buffets y el buffet es donde están platos, menús y etiquetas.
- **¿Cómo activo una función nueva?** Solo el administrador de la plataforma; contacta con soporte.
- **¿Puedo tener varios buffets en un hotel?** Sí; usa el selector para cambiar entre ellos.
- **¿Puedo ver los buffets de otro hotel?** Solo si tu usuario los tiene asignados.
- **¿Qué es un restaurante?** Un contenedor independiente sin etiquetas, con carta y reservas; ver [Restaurante y reservas](50-restaurante-reservas.md).
- **¿Dónde cambio el logo del hotel?** En la ficha del hotel (hasta 2 MB).
- **¿Cómo conecto mi sistema de recetas (Fichatec, dTouch, Avalon)?** Lo configura el administrador de la plataforma desde el bloque Integraciones.
- **¿Se pueden cambiar las credenciales de la cuenta cloud?** En la ficha del grupo; se prueban antes de guardar.

## Términos y relación con otros apartados
- **Contenedor**: nombre genérico de grupo, hotel, buffet o restaurante.
- **Función (feature)**: opción que se enciende por buffet.
- **Oferta**: catálogo de platos compartido, ver [Ofertas y hoja de oferta](16-ofertas-hoja-oferta.md); idiomas: [Idiomas](10-idiomas.md).
- Usuarios y acceso a buffets: [Usuarios](03-usuarios.md) y [Roles y permisos](04-roles-permisos.md).
- Funciones que habilitan módulos: [Comunicaciones](32-comunicaciones.md), [Temáticos](24-tematicos.md), [Menú del día](25-menu-del-dia.md), [Desperdicio](40-desperdicio.md), [Operativa](41-operativa.md), [Eventos](42-eventos.md), [Wellness](43-wellness.md).
- Zona de administración del grupo: [Administración](35-administracion.md).
