# Contenido actual

## Para qué sirve
Muestra qué plato tiene ahora mismo cada posición del buffet, agrupado por etiqueta electrónica. Permite ver una vista previa de lo que enseña la etiqueta y, con permiso, forzar que se refresque. También señala qué posiciones tienen un cambio inmediato o programado.

## Quién lo usa y dónde está
- Menú lateral: **Actual** (página "Contenido actual"). Se muestra a todos los usuarios, sin permiso específico, excepto a un restaurante independiente.
- **Forzar etiqueta** solo está disponible con el permiso **Forzar etiquetas** (`force-pricetags`).
- Es la página de inicio por defecto de los roles Metre y Básico (ver [Roles y permisos](04-roles-permisos.md)) y el destino cuando el módulo de la página de inicio del rol no está activo.

## Cómo se hace (paso a paso)

### Consultar el contenido de una posición
1. Entra en **Actual**.
2. Verás las posiciones agrupadas por etiqueta, con el nombre del plato que tienen ahora. Si no tiene plato aparece **Sin plato**.
3. Los elementos de wellness se reconocen por un icono de hoja (**Elemento de wellness**), ver [Wellness](43-wellness.md).
4. Usa el cuadro **Buscar** para filtrar por nombre de posición.

### Ver la vista previa de una etiqueta
1. Localiza la etiqueta.
2. Pulsa **Vista previa**.
3. Se abre una ventana con la imagen de cómo se ve la etiqueta.

### Forzar el refresco de una etiqueta
1. Pulsa **Forzar etiqueta** en la etiqueta deseada (solo si tienes el permiso).
2. Si todo va bien aparece **Etiqueta forzada con éxito**.
3. Solo se puede forzar una misma etiqueta una vez cada 5 minutos.

### Entender las marcas de color
1. **Cambio inmediato** (ámbar): se ha hecho un cambio temporal en el servicio actual; en el siguiente servicio la etiqueta vuelve al plato de la rueda.
2. **Cambio programado** (morado): hay un cambio programado para hoy en este servicio.
3. Pasa el ratón por la marca para ver la explicación.
4. Más información en [Cambios programados y temporales](27-cambios-programados-y-temporales.md).

## Problemas frecuentes y cómo resolverlos
- **Sale "Solo se permite refrescar cada 5 minutos"** → espera 5 minutos antes de volver a forzar esa etiqueta.
- **No veo el botón "Forzar etiqueta"** → tu rol no tiene "Forzar etiquetas". Pídelo a un administrador.
- **Aparece "No hay posiciones aún"** → el buffet no tiene posiciones creadas; créalas en [Posiciones](21-posiciones.md).
- **Una posición pone "Sin plato"** → no hay plato asignado en ese servicio (revisa [Ruedas de menú](23-ruedas-de-menu.md) o el [Menú del día](25-menu-del-dia.md)).
- **La etiqueta física no muestra lo mismo que la pantalla** → usa **Forzar etiqueta** y revisa [Etiquetas](30-etiquetas.md) y [Conectividad y antenas](31-conectividad-antenas.md).
- **Un plato cambió "solo hoy"** → es un cambio inmediato; en el siguiente servicio vuelve al de la rueda.

## Preguntas típicas de clientes
- **¿Qué plato tiene ahora cada etiqueta?** Lo ves en esta pantalla, agrupado por etiqueta.
- **¿Puedo ver cómo se ve la etiqueta sin ir al buffet?** Sí, con **Vista previa**.
- **¿Por qué no puedo forzar la etiqueta de nuevo?** Hay un límite de una vez cada 5 minutos por etiqueta.
- **¿Qué significa la marca ámbar?** Cambio inmediato en el servicio actual.
- **¿Qué significa la marca morada?** Cambio programado para hoy.
- **¿Se puede cambiar un plato desde aquí?** No; esta pantalla es de consulta. Los cambios se hacen en Posiciones, Menús o Cambios temporales.
- **¿Quién puede ver esta pantalla?** Cualquier usuario del buffet.

## Términos y relación con otros apartados
- **Posición**: lugar del buffet donde va un plato ([Posiciones](21-posiciones.md)); **etiqueta**: pantalla electrónica asociada ([Etiquetas](30-etiquetas.md)).
- Relacionado con [Cambios programados y temporales](27-cambios-programados-y-temporales.md), [Wellness](43-wellness.md), [Inicio](01-inicio-dashboard.md).
