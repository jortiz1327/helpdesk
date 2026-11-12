# Plano del buffet (Planner)

## Para qué sirve
El plano es un dibujo del buffet donde se colocan isletas, etiquetas y antenas. Permite ver y mover posiciones de forma visual y, en modo rueda, asignar platos a las posiciones arrastrándolos. Es una herramienta estándar: no depende de ninguna función opcional del buffet.

## Quién lo usa y dónde está
- Se abre desde **Posiciones** > **Plano** (modo buffet) o desde **Ruedas de menús** > **Configurar en el plano** (modo rueda). Ambos requieren el permiso `edit-positions`.
- El modo rueda además requiere `view-menu-wheels` y `edit-menus`.
- **Sincronizar** e **Importar** (archivo .json): solo superadministrador.
- El botón **Volver** regresa a la pantalla de origen.
- Si abres el plano y cambias de buffet en otra pestaña del navegador, la aplicación devuelve un error de conflicto (409); recarga y vuelve a entrar.

## Cómo se hace (paso a paso)

### Barra del modo buffet
Notas, **Sincronizar**, **Bloquear**, **Intercambiar**, **Etiquetas**, **+ Isleta**, **+ AP**, **Elementos**, **Exportar**, **Importar** y controles de zoom.

### Crear una isleta
1. Pulsa **+ Isleta**.
2. En "Nueva Isleta" indica nombre, color y forma (Rect., Redonda, L, U, Octog., Hexág., Triáng., Escalón) y las "Etiquetas a colocar".
3. Pulsa **Crear isleta**. Si el nombre ya existe se numera solo (por ejemplo ENSALADAS 2).

### Mover, añadir y quitar elementos
- Arrastra una posición o etiqueta para moverla ("Posición movida").
- **Añadir etiqueta** / **Añadir varias etiquetas** / **Modificar etiqueta** abren los modales correspondientes.
- Se pueden eliminar etiquetas ("Etiqueta eliminada"), antenas ("Antena eliminada") y posiciones ("Posición eliminada").
- **Intercambiar** cambia dos posiciones ("Posiciones intercambiadas"). También se puede invertir una etiqueta ("Etiqueta invertida").
- **Etiquetas** abre el pool de etiquetas libres (MAC y tamaño), con **Importar Excel** y **Vaciar**. Al vincular se ve "Etiqueta vinculada" o "La etiqueta ya estaba vinculada".
- **Bloquear** impide mover elementos accidentalmente. **+ AP** coloca una antena.
- **Sincronizar** (superadministrador) revincula las etiquetas a la antena más cercana: "Revinculadas N etiqueta(s) al AP más cercano".

### Exportar e importar
**Exportar** descarga el plano en .json; **Importar** (superadministrador) lo carga. El formato exacto es A confirmar.

### Modo rueda
1. Desde Ruedas de menús pulsa **Configurar en el plano**.
2. Elige **Rueda**, **Servicio** y **Día**.
3. Busca un plato y arrástralo sobre una posición.
4. En la vista "Actual" el cambio pide un "Motivo del cambio" (es un cambio temporal; ver [27-cambios-programados-y-temporales](27-cambios-programados-y-temporales.md)).

## Problemas frecuentes y cómo resolverlos
- "Este buffet no tiene una rueda de menú activa..." → Activa una rueda en [23-ruedas-de-menu](23-ruedas-de-menu.md).
- "La etiqueta pertenece a otra tienda" → La MAC está dada de alta en otro buffet; contacta con soporte.
- "Isleta no encontrada" → Recarga el plano; alguien pudo borrarla.
- Error de conflicto al abrir (409) → Has cambiado de buffet en otra pestaña; recarga.
- No veo Sincronizar/Importar → Solo superadministrador.
- Los cambios hechos en el plano no aparecen en **Revertir cambios** de Posiciones (no entran en ese historial).

## Preguntas típicas de clientes
- **¿Para qué sirve el plano?** Para ver y organizar visualmente isletas, etiquetas y antenas.
- **¿Puedo cambiar platos desde el plano?** Sí, en modo rueda, arrastrando el plato a la posición.
- **¿Cómo creo una isleta nueva?** **+ Isleta**.
- **¿Cómo coloco una antena?** **+ AP**.
- **¿Puedo guardar una copia del plano?** **Exportar** (.json).
- **¿Quién puede importar un plano?** Solo el superadministrador.
- **¿Por qué no puedo entrar al plano?** Falta el permiso `edit-positions`.

## Términos y relación con otros apartados
- **AP**: punto de acceso/antena ([31-conectividad-antenas](31-conectividad-antenas.md)).
- Isletas: [20-isletas](20-isletas.md). Posiciones: [21-posiciones](21-posiciones.md). Etiquetas: [30-etiquetas](30-etiquetas.md). Ruedas: [23-ruedas-de-menu](23-ruedas-de-menu.md).
