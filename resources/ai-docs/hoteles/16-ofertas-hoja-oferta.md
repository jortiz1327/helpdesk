# Ofertas y Hoja de oferta

## Para qué sirve
En los buffets con "estructura de oferta" los platos se gestionan como un conjunto único (la oferta). La **Hoja de oferta** es un editor tipo hoja de cálculo (como Excel) para crear y modificar todos los platos de golpe: nombres en cada idioma, tipo, alérgenos y distintivos. Existe además un listado de **Ofertas** (catálogo de nombres de oferta) de uso administrativo.

## Quién lo usa y dónde está
- **Hoja de oferta**: botón **Oferta** en [12-platos](12-platos.md) (ruta `/offer-sheet`). Requiere `view-dishes` y que el buffet tenga estructura de oferta; si no: "La hoja de oferta solo está disponible para buffets con estructura de oferta." y vuelve al listado de platos.
- Bloquear/desbloquear la oferta: solo superadministrador.
- **Ofertas** (`/offers`): solo superadministrador, sin entrada visible en el menú lateral; permite crear, editar y eliminar nombres de oferta (A confirmar su uso exacto). Las ofertas de wellness son otro apartado ([43-wellness](43-wellness.md)).

## Cómo se hace (paso a paso)

### Editar la oferta
1. En **Platos** pulsa **Oferta**.
2. Edita las celdas como en una hoja de cálculo. El guardado es automático ("Guardando...", "Guardado"; si falla "Error al guardar"). Al salir con cambios pendientes: "Guardando los cambios antes de salir...".
3. Barra de herramientas: **Buscar**, **Deshacer**, **Rehacer**, **Añadir plato**, **Eliminar plato** (pide confirmar), **Limpiar columna**, **Limpiar todo**, **Añadir idioma**, **Exportar**, **Ayuda**.
4. Si algunos platos no se guardan: "Algunos platos no se han guardado".

### Revisar la oferta
Pulsa **Revisar** ("Revisar la oferta: duplicados y campos vacíos"). Informa de platos duplicados, filas sin nombre en el idioma principal (no se guardan) y platos sin todas las traducciones (se guardan, pero las etiquetas mostrarán esos idiomas vacíos). **Quitar marcas** limpia el resaltado.

### Traducir
1. **Traducir plato** (el seleccionado) o **Traducir todos los platos**; luego **Traducir**.
2. Solo se rellenan nombres vacíos. La traducción es automática con IA: revísala siempre.

### Añadir un idioma
**Añadir idioma**, elige uno (si no hay: "No hay idiomas disponibles") y **Añadir**.

### Exportar
Pulsa **Exportar** para descargar la oferta ("Exportando...").

### Bloqueo de la oferta
- Cuando las etiquetas electrónicas ya están instaladas la oferta se bloquea y pasa a "Solo lectura": "La oferta está bloqueada y es de solo lectura... Para cambiar un plato concreto, usa el apartado de platos."
- Superadministrador: **Bloquear oferta** ("La oferta se ha bloqueado.") y **Desbloquear oferta** ("La oferta se ha desbloqueado.", queda registrado en los logs). Desbloquear puede hacer que las etiquetas muestren información incorrecta.
- Todo lo que se hace en la hoja queda registrado.

## Problemas frecuentes y cómo resolverlos
- "La oferta está bloqueada..." → Edita el plato desde Platos; si hay que editar la hoja, pide a un superadministrador que la desbloquee.
- "La hoja de oferta solo está disponible para buffets con estructura de oferta." → Este buffet usa la estructura estándar.
- Algún plato no se guarda → Revisa duplicados (dos platos no pueden llamarse igual) y que tenga nombre en el idioma principal.
- No veo el botón **Oferta** → El buffet no tiene estructura de oferta, o está activo Fichatec (A confirmar).
- Traducción incorrecta → Es automática; corrige a mano.
- Al importar platos con la oferta bloqueada → ver [12-platos](12-platos.md): solo superadministrador.

## Preguntas típicas de clientes
- **¿Qué es la hoja de oferta?** Un editor tipo Excel para todos los platos del buffet.
- **¿Por qué está en solo lectura?** Las etiquetas ya están instaladas.
- **¿Cómo cambio un plato si está bloqueada?** Desde Platos.
- **¿Puedo traducir automáticamente?** Sí, con **Traducir** (IA, revisa el resultado).
- **¿Quién desbloquea?** Solo el superadministrador.
- **¿Se guarda solo?** Sí.
- **¿Se puede exportar?** Sí, con **Exportar**.

## Términos y relación con otros apartados
- **Estructura de oferta / estándar**: forma de organizar los platos del buffet.
- Platos: [12-platos](12-platos.md). Tipos: [17-tipos-de-plato](17-tipos-de-plato.md). Alérgenos: [14-alergenos](14-alergenos.md). Idiomas: [10-idiomas](10-idiomas.md). Etiquetas: [30-etiquetas](30-etiquetas.md). Logs: [06-logs](06-logs.md).
