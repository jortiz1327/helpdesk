# Configuración

## Para qué sirve
Agrupa los ajustes generales de la aplicación: los roles de los usuarios, los alérgenos y los idiomas (tanto los del sistema como los que se muestran en las etiquetas y en la ficha de datos). Es la zona donde se decide cómo se presenta la información al cliente final.

## Quién lo usa y dónde está
- Menú lateral: **Configuración**, con tres entradas, cada una con su permiso:
  - **Roles** (permiso `view-roles`).
  - **Alérgenos** (permiso `view-allergens`).
  - **Idiomas** (permiso `view-languages`).
- Si no tienes el permiso, esa entrada no aparece. Los permisos los reparte el administrador del hotel mediante roles (ver [Roles y permisos](04-roles-permisos.md)).

## Cómo se hace (paso a paso)

### Gestionar roles
1. Entra en **Configuración > Roles**.
2. Desde aquí se crean roles, se editan, se elige la pantalla de inicio de cada rol y se eliminan. Detalle completo en [Roles y permisos](04-roles-permisos.md).

### Gestionar alérgenos
1. Entra en **Configuración > Alérgenos**.
2. Aquí se administran los alérgenos y su estilo de iconos ("fuente" de alérgenos). Detalle en [Alérgenos](14-alergenos.md).

### Idiomas: pantalla principal
1. Entra en **Configuración > Idiomas**. Título "Configuración de idioma": "Seleccione una de las opciones a continuación para gestionar y configurar los idiomas disponibles en el sistema."
2. Hay dos tarjetas: **Idiomas del sistema** (y ficha de datos) e **Idiomas de las etiquetas**. El botón **Regresar** vuelve atrás.

### Idiomas de las etiquetas
1. Elige **Idiomas de las etiquetas** ("Define el orden de visualización de los idiomas en las etiquetas, permitiendo una presentación personalizada independiente de la configuración de los platos.").
2. Activa o desactiva los idiomas que se mostrarán en las etiquetas y reordénalos arrastrándolos para fijar el orden de visualización.
3. Los cambios afectan a cómo se compone el texto de cada etiqueta (ver [Etiquetas](30-etiquetas.md)). Las plantillas de etiqueta se pueden regenerar y previsualizar desde esta misma zona. A confirmar el detalle de los ajustes de número de idiomas y tema.

### Idiomas de la ficha de datos / del sistema
1. Elige **Idiomas del sistema**.
2. Se pueden activar o desactivar idiomas, añadir un idioma y cambiar su orden. Detalle en [Idiomas](10-idiomas.md) y [Traducciones](11-traducciones.md).

## Problemas frecuentes y cómo resolverlos
- **No veo Configuración o alguna de sus entradas** → falta el permiso correspondiente; pídelo al administrador (ver [Roles y permisos](04-roles-permisos.md)).
- **Un idioma no sale en la etiqueta** → comprueba que esté activado en **Idiomas de las etiquetas** y que el plato tenga su traducción (ver [Traducciones](11-traducciones.md)).
- **El orden de idiomas en la etiqueta no es el que quiero** → reordénalo en **Idiomas de las etiquetas**; es independiente del orden de los platos.
- **No puedo añadir un idioma nuevo** → el buffet solo muestra los idiomas habilitados; A confirmar quién puede habilitar idiomas nuevos (probablemente el administrador de la plataforma, ver [Contenedores](33-contenedores-hoteles-buffets-grupos.md)).
- **Cambié los idiomas y la etiqueta no se actualiza** → puede tardar; fuerza la etiqueta (ver [Etiquetas](30-etiquetas.md)).

## Preguntas típicas de clientes
- **¿Cómo cambio el orden de los idiomas en las etiquetas?** En Configuración > Idiomas > Idiomas de las etiquetas.
- **¿Puedo quitar un idioma de la etiqueta pero mantenerlo en la carta?** Sí, los idiomas de etiquetas son independientes.
- **¿Dónde creo un rol para mi personal de cocina?** En Configuración > Roles.
- **¿Dónde cambio los iconos de alérgenos?** En Configuración > Alérgenos (A confirmar según el buffet).
- **¿Por qué mi empleado no ve Configuración?** Su rol no tiene los permisos de roles, alérgenos o idiomas.
- **¿Hay más ajustes (colores, logo...)?** El logo del hotel y la moneda están en la ficha del hotel (ver [Contenedores](33-contenedores-hoteles-buffets-grupos.md)). Los ajustes de preferencias del buffet los activa el administrador de la plataforma.

## Términos y relación con otros apartados
- **Rol**: conjunto de permisos, ver [Roles y permisos](04-roles-permisos.md) y [Usuarios](03-usuarios.md).
- **Idiomas**: [Idiomas](10-idiomas.md), [Traducciones](11-traducciones.md).
- **Alérgenos**: [Alérgenos](14-alergenos.md).
- Resultado visible en [Etiquetas](30-etiquetas.md).
- Preferencias por buffet (minúsculas, alérgenos por línea): [Contenedores](33-contenedores-hoteles-buffets-grupos.md).
