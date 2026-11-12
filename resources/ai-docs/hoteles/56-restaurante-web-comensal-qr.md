# Restaurante: Web del comensal (QR)

## Para qué sirve
Es la web que el cliente abre en su móvil al escanear el QR del restaurante: ve la carta, el menú de hoy, los alérgenos, y puede reservar mesa y gestionar su reserva. El hotel no la maneja directamente: se configura desde el panel ([Carta y menús](52-restaurante-cartas.md), [Ajustes](54-restaurante-ajustes.md), [Idiomas y mensajes](53-restaurante-textos.md)). La web en sí es una aplicación aparte; este documento describe lo que ve el cliente según el sistema.

## Quién lo usa y dónde está
- Lo usa el cliente final (huésped o externo). El personal solo da el enlace/QR, que está en **Restaurante > Ajustes > Enlace y QR del restaurante**.
- No requiere iniciar sesión.
- Lo que muestra depende de qué cartas estén publicadas, qué idiomas estén activos y de las reglas de Ajustes.

## Cómo se hace (paso a paso)

### Ver la carta
1. El cliente escanea el QR y elige su idioma ("Elige tu idioma").
2. Ve "Carta y menús": **Menú de hoy**, **Próximos días** y **La carta**. También **Alérgenos** (tabla de alérgenos de los platos) y, si hay texto, **Conoce el concepto**.

### Reservar mesa
1. Pulsa **Reservar mesa**.
2. Elige **Fecha**, **Comensales** y un turno ("Elige un turno"). Cada turno indica "Quedan N plazas", "Completo" o "Cerrado". Si el restaurante reserva por hora, elige **Hora de llegada**.
3. En **Tus datos** rellena Nombre y apellidos, Correo electrónico, Teléfono, Número de habitación (obligatorio solo si el restaurante no admite clientes externos) y Observaciones.
4. Acepta la política de privacidad y pulsa **Enviar solicitud**.
5. Sale "Solicitud recibida": está pendiente de validación, llega un correo, y se muestran el **N.º de solicitud** y un **Código para el tótem**.

### Consultar, cambiar o cancelar
1. En **Mi reserva** / **Consultar mi reserva** el cliente introduce su correo y el código.
2. Puede pulsar **Cambiar**, **Cancelar la reserva** o **Retirar el cambio**.
3. Cambiar una reserva confirmada crea una petición que el restaurante debe aprobar; mientras tanto se mantiene la original.
4. Cerca del turno aparece "Ya no se puede cambiar ni cancelar esta reserva desde aquí. Contacta con el restaurante."

## Problemas frecuentes y cómo resolverlos
- **"No encontramos ninguna reserva con esos datos."** → revisar correo y código (el código del correo, de 6 caracteres).
- **"Para grupos tan grandes, contacta directamente con el restaurante."** → se superó el máximo de comensales por reserva.
- **"El servicio no está disponible en este momento..."** → el servicio de reservas está apagado temporalmente. A confirmar con soporte técnico.
- **No salen turnos** → no hay turnos, el día está cerrado, o fuera de antelación/plazo ([Ajustes](54-restaurante-ajustes.md)).
- **Turno "Completo"** → sin plazas; elegir otro.
- **No sale la carta** → no hay cartas publicadas.
- **No aparece el precio** → el restaurante lo oculta (carta o ajuste general).
- **No llega el correo** → revisar spam; el personal puede **Reenviar correo** desde [Reservas](50-restaurante-reservas.md).
- **Demasiados intentos** → hay límites contra abusos; esperar un poco. Un campo oculto bloquea a los robots.
- **El QR no abre** → se cambió el enlace público; hay que reimprimir el QR.

## Preguntas típicas de clientes
- **¿Necesito app o cuenta?** No, se abre en el navegador.
- **¿Mi reserva queda confirmada al enviarla?** No: queda pendiente hasta que el restaurante la valida; recibe un correo cuando se confirma.
- **¿Cómo cancelo?** En Mi reserva, con correo y código.
- **¿Puedo cambiar la hora o el número de personas?** Sí, con Cambiar; el restaurante debe aprobarlo.
- **¿Puedo reservar sin ser huésped?** Sí, si el hotel lo admite.
- **¿En qué idiomas está?** En los activos de [Idiomas y mensajes](53-restaurante-textos.md).
- **¿Cómo veo los alérgenos?** Pantalla Alérgenos ([Alérgenos](14-alergenos.md)).
- **¿Es obligatorio el teléfono?** Para el cliente sí en la web (6-30 caracteres).

## Términos y relación con otros apartados
- **Solicitud**: reserva pendiente de validación. **Código**: 6 caracteres para gestionar la reserva.
- Relacionado: [Reservas](50-restaurante-reservas.md), [Tótem](55-restaurante-dispositivos-totem.md), [Carta y menús](52-restaurante-cartas.md).
