# Menú del día

## Para qué sirve
Permite mostrar el menú de un servicio en etiquetas grandes mediante plantillas: grupos de posiciones que se muestran juntos, con opción de alérgenos y segundo idioma.

## Quién lo usa y dónde está
- Ruedas de menús > **Menú del día** > "Plantillas".
- Depende de la función del buffet `MENU_DAY`; sin ella la página no existe (404).
- Los permisos exactos son A confirmar (los controladores no muestran restricción); vincular etiqueta es solo superadministrador.

## Cómo se hace (paso a paso)

### Crear una plantilla
1. **Crear nueva plantilla**.
2. **Nombre**.
3. "Seleccione para que servicios desea usar esta plantilla" (solo servicios aún no usados).
4. Opcional: **Mostrar alérgenos** y **Mostrar en dos idiomas** (con **Idioma** obligatorio).
5. Guardar. Cada tarjeta tiene **Mostrar detalles** e iconos de alérgenos y segundo idioma.

### Configurar plantilla
1. **Configurar plantilla**.
2. **Añadir grupo**: nombre (obligatorio, máx. 192) y posiciones (obligatorio). Se ve "Posiciones restantes:".
3. **Editar grupo** / **Eliminar grupo**.

### Etiquetas
- Superadministrador: "Vincula una etiqueta grande nueva en la que se mostrará el menú del día" > **Añadir etiqueta** (MAC).
- La lista muestra MAC, tamaño y estado online/offline.
- **Refrescar** reenvía el contenido actual. Error: "No hay servicio en curso con plantilla de menú del día".

## Problemas frecuentes y cómo resolverlos
- Página 404 → El buffet no tiene MENU_DAY.
- No hay servicios para elegir → Ya están usados por otras plantillas.
- Refrescar da error → No hay servicio en curso con plantilla.
- Etiqueta offline → [31-conectividad-antenas](31-conectividad-antenas.md).
- No veo vincular etiqueta → Solo superadministrador.

## Preguntas típicas de clientes
- **¿Qué es?** El menú de un servicio en una etiqueta grande.
- **¿Puede salir en dos idiomas?** Sí.
- **¿Puede mostrar alérgenos?** Sí.
- **¿Puedo usar una plantilla en varios servicios?** Sí; cada servicio solo puede tener una.
- **¿Cómo lo actualizo ya?** **Refrescar**.
- **¿Quién añade la etiqueta?** El superadministrador.

## Términos y relación con otros apartados
- Servicios: [15-servicios](15-servicios.md). Idiomas: [10-idiomas](10-idiomas.md). Alérgenos: [14-alergenos](14-alergenos.md). Posiciones: [21-posiciones](21-posiciones.md). Ruedas: [23-ruedas-de-menu](23-ruedas-de-menu.md).
