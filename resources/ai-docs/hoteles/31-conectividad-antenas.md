# Conectividad y antenas

## Para qué sirve
Permite ver las antenas (AP, puntos de acceso) que dan servicio a las etiquetas del buffet: si están conectadas, cuántas etiquetas tienen y desde cuándo están sin conexión. Incluye una guía con las reglas de red que debe cumplir el hotel para que las antenas funcionen.

## Quién lo usa y dónde está
- Menú lateral: **Conectividad > Antenas**, con el permiso `view-antennas`.
- Cualquier usuario con acceso ve la lista y el botón **Detalles**.
- **Añadir nuevo AP** y **Eliminar** son solo para el administrador de la plataforma (superadmin).
- Es necesario tener un buffet activo. No aparece en restaurantes independientes.
- Hay una guía en vídeo ("Guía de Conectividad"), ver [Guía en vídeo](09-videoguia.md).

## Cómo se hace (paso a paso)

### Ver el estado de las antenas
1. Entra en **Conectividad > Antenas**. Al abrir se sincroniza el estado con el cloud.
2. Columnas: **MAC**, **Nombre**, **IP**, **Estado**, **Sin conexión desde** ("Nunca conectado" si jamás se conectó), **Número de etiquetas**, **Acciones**.
3. Las horas se muestran en la zona horaria del hotel.

### Ver detalles e historial de una antena
1. Pulsa **Detalles** en la fila.
2. Se abre "Detalles de AP" con: Estado, MAC, Nombre, IP, Hotel, Comentarios, Etiquetas vinculadas, Sin conexión desde e Id de tienda.
3. En "Historial de estado" elige Hoy, Últimos 7 días o Últimos 30 días para ver las desconexiones y el tiempo sin conexión.
4. Si todavía no hay datos aparece "Aún no hay historial: se registra a partir de ahora...".
5. Para cambiar el nombre pulsa el lápiz, escribe y pulsa **Guardar cambios** (o **Cancelar**). A confirmar: la ruta de guardado existe pero el controlador no la implementa, por lo que guardar el nombre puede fallar.

### Asegurar conectividad (reglas de red)
1. Pulsa **Asegurar conectividad**.
2. Se muestra lo que el departamento de informática del hotel debe permitir: resolución DNS, el puerto 1883 TCP (MQTT) hacia el cloud (el servidor/IP se muestra en la propia ventana) y DHCP.
3. Pulsa **Entendido** para cerrar.

### Añadir una antena (solo administrador de la plataforma)
1. Pulsa **Añadir nuevo AP**.
2. Rellena **Nombre del AP**, **MAC** (entre 10 y 15 caracteres, obligatoria), **Seleccionar un buffet** y **Comentarios**.
3. Pulsa **Añadir AP** (mientras trabaja muestra "Añadiendo...").

### Eliminar una antena (solo administrador de la plataforma)
1. Pulsa **Eliminar** en la fila.
2. Se avisa de que "todas las etiquetas vinculadas a ella serán desvinculadas".
3. Confirma con **Eliminar AP**. Se borra en el cloud y en la aplicación.

## Problemas frecuentes y cómo resolverlos
- **Todas las etiquetas aparecen Offline** → mira si la antena está Sin conexión; revisa alimentación y cable de red.
- **La antena aparece "Nunca conectado"** → no ha llegado a conectar: comprobar red, DHCP y el puerto 1883 TCP con **Asegurar conectividad**.
- **La antena se conecta y se cae** → mira el historial de estado (Desconexiones) para ver la frecuencia; revisa la red del hotel.
- **No puedo añadir ni eliminar antenas** → son acciones del administrador de la plataforma.
- **Eliminé una antena y las etiquetas dejaron de funcionar** → es lo esperado: las etiquetas quedan desvinculadas.
- **No puedo guardar el nombre de la antena** → A confirmar (posible fallo conocido).

## Preguntas típicas de clientes
- **¿Qué necesita mi red para las antenas?** DNS, DHCP y salida por TCP al puerto 1883 hacia el cloud; está en **Asegurar conectividad**.
- **¿Cómo sé desde cuándo está caída?** Columna "Sin conexión desde" y el historial en Detalles.
- **¿Cuántas etiquetas tiene cada antena?** Columna "Número de etiquetas".
- **¿Puedo colocar una antena en el plano?** Las antenas se pueden colocar en el plano, ver [Plano / Planner](22-plano-planner.md).
- **¿Quién puede dar de alta una antena nueva?** Solo el administrador de la plataforma; contacta con soporte.
- **¿Se avisa si una antena cae?** A confirmar. El panel de [Administración](35-administracion.md) muestra "Antenas Conectadas" y el [Inicio](01-inicio-dashboard.md) puede mostrar alertas.

## Términos y relación con otros apartados
- **AP / antena**: punto de acceso que comunica con las etiquetas.
- Etiquetas que cuelgan de cada antena: [Etiquetas](30-etiquetas.md).
- Gestión de buffets y hoteles: [Contenedores](33-contenedores-hoteles-buffets-grupos.md).
