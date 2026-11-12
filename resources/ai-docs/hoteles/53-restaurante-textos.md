# Restaurante: Idiomas y mensajes

## Para qué sirve
Permite elegir en qué idiomas se muestra la web del restaurante y editar los textos que recibe el cliente, sobre todo los correos de la reserva (solicitud, confirmación, rechazo...). Cada idioma tiene sus propios textos.

## Quién lo usa y dónde está
- Menú: **Restaurante > Idiomas y mensajes**.
- Lo ve quien tenga el permiso **Edit restaurant settings**.
- El personal del restaurante edita solo los grupos **Correos** y **Concepto**. El superadmin edita además Pantallas, Alérgenos y Platos y es el único con **Traducir lo que falta**.
- Los idiomas parten de los del buffet ([Idiomas](10-idiomas.md)).

## Cómo se hace (paso a paso)

### Añadir un idioma
1. Pulsa **Añadir idioma** y elige uno de la lista, o **Otro idioma...** y rellena **Código** (2-3 letras) y **Nombre que verá el cliente**.
2. Aparece en la lista; el primero es **Principal**.

### Cambiar idioma principal, activar o quitar
- **Hacer principal** convierte otro idioma en el principal (debe estar activo).
- El interruptor **Activo** muestra u oculta el idioma al cliente.
- El icono de borrar abre "Eliminar el idioma". El principal no se puede eliminar.

### Editar los textos
1. Elige el idioma y el grupo (Correos o Concepto).
2. Filtra con **Solo sin traducir** si quieres ver lo que falta. Las etiquetas indican **Sin traducir**, **Cambiado** o **Traducción automática: revísala**.
3. Cambia el texto. **Volver al texto de serie** restaura el original.
4. Pulsa **Save (n)** (n = cambios pendientes). Guarda antes de cambiar de idioma.

### Mensajes de correo
- Mensajes: solicitud recibida, confirmada, rechazada, modificada, cancelada, cambio solicitado, cambio aprobado y cambio rechazado.
- Se pueden usar marcadores que se sustituyen por los datos reales: {nombre} {restaurante} {numero} {fecha} {turno} {hora} {comensales} {habitacion} {codigo} {enlace} {motivo}.
- El mensaje de solicitud recibida debe llevar {enlace}.
- **Enviarme una prueba** manda ese correo al usuario conectado.

### Concepto
Si hay texto en **Concepto**, la web del cliente muestra el botón "Conoce el concepto". Sin texto, el botón no aparece.

### Traducir lo que falta (solo superadmin)
Pulsa **Traducir lo que falta**: la traducción automática se propone, se revisa y se guarda (tiene un límite de peticiones por minuto).

## Problemas frecuentes y cómo resolverlos
- **"Este mensaje tiene que llevar el marcador {enlace}."** → vuelve a poner el marcador en el texto.
- **"Ese idioma ya está en la lista." / "El código del idioma no es válido."** → comprueba el código.
- **"El idioma principal tiene que estar activo." / "El idioma principal no se puede eliminar. Haz principal otro antes."** → cambia primero el principal.
- **"Hay textos sin guardar. Guárdalos antes de cambiar de idioma."** → pulsa Save.
- **"No se ha podido enviar el correo de prueba."** → revisa tu correo; si persiste, A confirmar con soporte técnico.
- **No veo grupos como Pantallas o Platos** → son solo para superadmin.
- **El cliente ve un texto en inglés en lugar de su idioma** → falta traducir ese texto o el idioma está inactivo.
- **No aparece "Conoce el concepto"** → no hay texto de Concepto.

## Preguntas típicas de clientes
- **¿Cómo cambio el texto del correo de confirmación?** Idioma, grupo Correos, mensaje "confirmada".
- **¿Puedo añadir el idioma ruso?** Sí, con Añadir idioma (o "Otro idioma..." si no está).
- **¿Puedo ver cómo queda el correo?** Con **Enviarme una prueba**.
- **¿Qué significan las llaves como {nombre}?** Se sustituyen por los datos de la reserva.
- **¿Por qué algunos textos están en gris/"Sin traducir"?** Falta la traducción en ese idioma.
- **¿Quién traduce los nombres de los platos?** Siguen las traducciones de los platos ([Platos](12-platos.md)).
- **¿Cómo desactivo un idioma?** Interruptor Activo.

## Términos y relación con otros apartados
- **Marcador**: palabra entre llaves que se sustituye. **Idioma principal**: el de referencia.
- Relacionado: [Idiomas](10-idiomas.md), [Reservas](50-restaurante-reservas.md), [Web del comensal](56-restaurante-web-comensal-qr.md), [Carta y menús](52-restaurante-cartas.md).
