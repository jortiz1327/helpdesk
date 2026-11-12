# Alérgenos

## Para qué sirve
Permite elegir qué alérgenos y distintivos (por ejemplo, vegano o sin gluten) se muestran en la aplicación y en las etiquetas, y qué juego de iconos (fuente) se usa para dibujarlos en las etiquetas electrónicas.

## Quién lo usa y dónde está
- Menú lateral: dentro de **Configuración**, entrada **Alérgenos** (ruta `/allergens`, "Gestión de Alérgenos"). Requiere `view-allergens`.
- Activar/desactivar un alérgeno: `toggle-allergens`.
- Cambiar el juego de iconos: `change-allergens`.
- Solo el superadministrador puede: crear alérgenos ("Nuevo alérgeno"), cambiar el "Color de los iconos", y añadir, editar o eliminar fuentes.
- Que el plato lleve alérgenos depende de la función DISH_ALLERGENS del buffet; las trazas, de DISH_TRACES ([12-platos](12-platos.md)).

## Cómo se hace (paso a paso)

### Activar o desactivar alérgenos y distintivos
1. Entra en **Alérgenos**. Verás dos listas: "Alérgenos" y "Distintivos".
2. Cada tarjeta tiene un interruptor. Apágalo para que ese alérgeno no se use en el buffet, enciéndelo para volver a usarlo.
3. Solo se listan los alérgenos que soporta la fuente de iconos actual del buffet.

### Cambiar el juego de iconos de las etiquetas
1. Pulsa **Haz click aquí para gestionar las fuentes** (página "Iconos de alérgenos").
2. Haz clic en una fuente para ver su vista previa ("Haz clic para ver la fuente").
3. Pulsa **Usar estos iconos**.
4. En la confirmación "¿Estás seguro de que quieres utilizar estos iconos?" decide el interruptor **Actualizar plantillas del cloud** (si está activado, todas las plantillas de etiquetas del cloud se actualizan con la nueva fuente).
5. Pulsa **Sí, estoy seguro** (o **No, cancelar**).
6. **Regresar** vuelve a la página de alérgenos.

### Descargar los iconos
Pulsa **Descargar archivo ZIP con iconos** (A confirmar si está disponible en todos los entornos).

### Colores (solo web)
Opciones "Color del aro" y "Fondo de las fichas": solo afectan a la web, no a las etiquetas. El "Color de los iconos" (rojo, negro, blanco, amarillo) solo lo cambia el superadministrador.

### Crear un alérgeno (superadministrador)
1. Pulsa **Nuevo alérgeno**.
2. Rellena **Nombre**, **Tipo** (Alérgeno o Distintivo), **Carácter** (un solo carácter, único) y las fuentes soportadas. Recuerda añadir la traducción al español en los archivos de idioma.

### Gestionar fuentes (superadministrador)
- **Añadir fuente de alérgenos**: "Nombre de la fuente" (debe coincidir con el del cloud), "Alias de la fuente", "Tamaño de la fuente", "Tamaño máximo", "Tamaño mínimo", "Color de la fuente" y "Archivo de fuente" (TTF). La fuente debe estar ya en el cloud.
- **Editar fuente**: cambia datos y "Alérgenos soportados".
- **Eliminar**: "Esta acción solo eliminará la fuente de la base de datos, no afecta al cloud".

## Problemas frecuentes y cómo resolverlos
- No encuentro un alérgeno en la lista → La fuente actual no lo soporta, o lo ha desactivado alguien. Revisa la fuente en la página de iconos.
- No puedo cambiar los iconos → Falta el permiso `change-allergens`.
- No veo **Nuevo alérgeno** ni **Color de los iconos** → Son solo del superadministrador.
- Error de "Carácter" ya usado → Cada alérgeno necesita un carácter único; elige otro.
- Las etiquetas siguen con los iconos antiguos → Al cambiar de fuente activa **Actualizar plantillas del cloud** y comprueba la sincronización con [30-etiquetas](30-etiquetas.md) (A confirmar el tiempo de actualización).

## Preguntas típicas de clientes
- **¿Cómo oculto un alérgeno que no uso?** Apaga su interruptor en **Alérgenos**.
- **¿Qué diferencia hay entre alérgeno y distintivo?** El alérgeno avisa de un riesgo; el distintivo marca una característica (p. ej. vegano). Se gestionan en listas separadas.
- **¿Cómo cambio el dibujo de los iconos de las etiquetas?** Con **Usar estos iconos** en la página de fuentes.
- **¿Puedo añadir un alérgeno nuevo?** Solo el superadministrador.
- **¿El color del aro cambia las etiquetas?** No, solo la web.
- **¿Se muestran las trazas?** Solo si el buffet tiene la función de trazas.

## Términos y relación con otros apartados
- **Distintivo**: marca no alergénica del plato.
- **Fuente de iconos**: tipografía que dibuja los alérgenos en las etiquetas.
- Se asignan a los platos en [12-platos](12-platos.md) y a los ingredientes en [13-ingredientes](13-ingredientes.md).
- Traducciones de nombres: [11-traducciones](11-traducciones.md). Permisos: [04-roles-permisos](04-roles-permisos.md).
