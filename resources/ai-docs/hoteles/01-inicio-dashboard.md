# Inicio (panel de estadísticas)

## Para qué sirve
Es la pantalla de resumen del buffet activo. Muestra de un vistazo cuántos platos hay, cuántos usuarios están activos, el estado de las antenas y etiquetas, los cambios temporales realizados y los últimos platos creados. Sirve para detectar rápidamente qué está pendiente (revisiones, traducciones por validar, errores de conectividad).

## Quién lo usa y dónde está
- Menú lateral: **Inicio** (ruta `/home`). Solo aparece si el rol tiene el permiso **Ver estadísticas** (`view-dashboard`).
- Si el usuario tiene acceso a varios buffets, el selector de buffet está arriba del menú; el panel siempre muestra el buffet seleccionado.
- Si no hay ningún buffet seleccionado aparece un aviso para seleccionar uno.
- Un **restaurante independiente** no ve este panel: al entrar se le redirige a Reservas (si tiene permiso para verlas), si no a Platos y, en último caso, a su Perfil.
- Al iniciar sesión, cada rol puede tener una "página de inicio predeterminada" distinta (ver [Roles y permisos](04-roles-permisos.md)). Si no la tiene, se abre la última página visitada o Inicio.

## Cómo se hace (paso a paso)

### Consultar los totales
1. Entra en **Inicio** en el menú lateral.
2. Revisa las tarjetas superiores: **Platos Registrados**, **Categorías Registradas** (tipos de plato) y **Usuarios Activos**.
3. **Platos revisados** solo aparece si el buffet tiene activada la validación de alérgenos (función `ALLERGEN_VALIDATION`).
4. Pulsa **Ver Usuarios** en la tarjeta de usuarios para ir al listado ([Usuarios](03-usuarios.md)).

### Revisar antenas y etiquetas
1. Mira las tarjetas **Antenas Conectadas** y **Etiquetas Conectadas**.
2. Pulsa **Ver antenas** o **Ver Etiquetas** para ir al detalle ([Conectividad y antenas](31-conectividad-antenas.md), [Etiquetas](30-etiquetas.md)).
3. En el bloque **Alertas** / **Errores de Conectividad de Antenas** se ven los **Errores totales** del último mes.

### Ver el estado de revisión de platos y traducciones
1. Busca la tarjeta **Platos y alérgenos** (**Pendiente de revisión** / **Revisados**). Solo existe con la función de validación de alérgenos.
2. Busca la tarjeta **Traducciones de platos** (**Pendiente de validación** / **Traducciones validadas**). Solo existe con la función de validación de traducciones (`TRANSLATION_VALIDATION`).
3. Más información en [Platos](12-platos.md) y [Alérgenos](14-alergenos.md).

### Consultar los cambios temporales
1. En el gráfico **Cambios Temporales** pulsa **Seleccionar una fecha** y elige un rango (máximo 30 días).
2. Debajo hay tarjetas con los cambios de hoy, de esta semana y de este mes.
3. **Ranking de platos** / **Platos más cambiados** indica qué platos se han modificado más (columna *Modificaciones*).
4. **Ranking de usuarios** muestra Rango, Nombre y Cambios temporales por usuario.
5. Más información en [Cambios programados y temporales](27-cambios-programados-y-temporales.md).

### Ver los últimos platos creados
1. Baja hasta **Últimos platos creados**.
2. Pulsa **Ver Platos** para abrir el listado completo.
3. La tarjeta **Creación de platos** muestra los usuarios que más platos han creado.

### Rellenar la oferta al entrar (solo buffets con estructura por ofertas)
1. Tras iniciar sesión, si el buffet usa oferta y la oferta está vacía, aparece un aviso "Fill your offer" con los botones **Later** y **Fill offer**.
2. **Fill offer** lleva a la hoja de oferta ([Ofertas y hoja de oferta](16-ofertas-hoja-oferta.md)); **Later** lo cierra.

## Problemas frecuentes y cómo resolverlos
- **No veo "Inicio" en el menú** → tu rol no tiene "Ver estadísticas". Pide a un administrador que lo active en [Roles y permisos](04-roles-permisos.md).
- **Sale un aviso pidiendo seleccionar un buffet** → elige un buffet en el selector del menú. Si no aparece ninguno, no tienes buffets asignados: contacta con un administrador ([Usuarios](03-usuarios.md)).
- **No veo las tarjetas de "Platos y alérgenos" o "Traducciones de platos"** → esas funciones no están activadas para tu buffet. Se activan desde la configuración del buffet ([Contenedores](33-contenedores-hoteles-buffets-grupos.md)).
- **El selector de fecha no me deja elegir más de un mes** → el rango máximo es de 30 días.
- **Las antenas o etiquetas aparecen desconectadas** → revisa [Conectividad y antenas](31-conectividad-antenas.md).
- **Al iniciar sesión no llego a Inicio** → tu rol tiene otra página de inicio configurada, o el módulo de esa página no está activo y se te lleva a Contenido actual ([Contenido actual](02-contenido-actual.md)).

## Preguntas típicas de clientes
- **¿Por qué no veo el panel de Inicio?** Porque tu rol no tiene el permiso "Ver estadísticas".
- **¿Qué significa "Usuarios Activos"?** Los usuarios del buffet que están habilitados.
- **¿Cada cuánto se actualizan los datos?** A confirmar: se cargan al abrir la pantalla.
- **¿Puedo ver los cambios de un mes concreto?** Sí, con **Seleccionar una fecha**, hasta 30 días de rango.
- **¿Por qué mi hotel no muestra "Platos revisados"?** Es una función opcional (validación de alérgenos) que debe estar activada en el buffet.
- **¿Qué es el aviso de rellenar la oferta?** Aparece en buffets con estructura de ofertas cuando la oferta está vacía.
- **¿Qué ve un restaurante independiente?** No tiene panel de Inicio; entra directamente en Reservas.

## Términos y relación con otros apartados
- **Buffet activo**: el buffet seleccionado en el menú; todo el panel se refiere a él.
- **Cambio temporal**: cambio de plato fuera de la rueda de menús ([Cambios programados y temporales](27-cambios-programados-y-temporales.md)).
- Relacionado con [Usuarios](03-usuarios.md), [Platos](12-platos.md), [Conectividad y antenas](31-conectividad-antenas.md), [Etiquetas](30-etiquetas.md), [Contenido actual](02-contenido-actual.md), [Roles y permisos](04-roles-permisos.md), [Restaurante y reservas](50-restaurante-reservas.md).
