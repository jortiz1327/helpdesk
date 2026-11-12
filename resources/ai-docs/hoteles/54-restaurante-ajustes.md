# Restaurante: Ajustes (turnos, cierres, reglas y QR)

## Para qué sirve
Es donde se configura cómo se reserva en el restaurante: turnos y aforo, días cerrados, reglas de reserva, y el enlace y QR públicos. Sin turnos no se puede reservar. Los dispositivos tótem se emparejan en esta misma pantalla ([Tótem](55-restaurante-dispositivos-totem.md)).

## Quién lo usa y dónde está
- Menú: **Restaurante > Ajustes** (página "Ajustes del restaurante").
- Lo ve quien tenga el permiso **Edit restaurant settings**. Las horas se interpretan en la zona horaria del hotel.
- Requiere el módulo Restaurante activo ([Contenedores](33-contenedores-hoteles-buffets-grupos.md)).

## Cómo se hace (paso a paso)

### Turnos y aforo
1. En la tarjeta **Turnos y aforo** pulsa **Añadir turno** ("Nuevo turno").
2. Rellena **Nombre del turno**, **Servicio** ([Servicios](15-servicios.md)), **Empieza**, **Termina**, **Aforo** (comensales, 1-5000) y, opcional, **Aforo por hora de llegada**.
3. Marca los **Días en que se ofrece** (al menos uno).
4. Guarda. Cada turno tiene interruptor de activo, lápiz (editar) y papelera.
5. Si no hay turnos: "Todavía no hay turnos. Sin turnos no se puede reservar."

### Enlace y QR del restaurante
1. En **Enlace y QR del restaurante** ves la dirección pública y su QR. La web muestra el menú del día, las cartas publicadas y las reservas.
2. **Copiar enlace** y **Imprimir QR** para colocar el QR en las mesas.
3. **Sincronizar con la web de cartas** aparece solo si el servicio está configurado.
4. **Cambiar el enlace** genera una dirección nueva: el enlace anterior, el QR impreso y los tótems emparejados con él dejan de funcionar (se pide confirmación). Úsalo solo si es necesario.

### Reglas de las reservas
En **Reglas de las reservas** (valores por defecto entre paréntesis):
- **Correo que recibe los avisos** (vacío): dónde llegan las solicitudes nuevas.
- **Minutos que una solicitud se rechaza automáticamente** (0 = desactivado; hasta 10080).
- **Máximo de comensales por reserva** (12).
- **Días de antelación** (30; 0-730).
- **Minutos antes del turno en que se cierra** (60; 0-10080), solo afecta a clientes.
- **Cómo se reserva**: Por servicio o Por hora; con Por hora aparece **Una hora de llegada cada** (5, 10, 15, 20, 30, 45 o 60 min; por defecto 30).
- **Segundos de inactividad del tótem** (60; 15-3600).
- **Admitir clientes que no se alojan en el hotel** (sí por defecto; la habitación pasa a ser opcional).
- **Mostrar los precios en la carta** (sí).
Pulsa guardar.

### Días cerrados
1. En **Días cerrados** elige la fecha, el turno (por defecto "Todo el día") y un **Motivo (opcional)**.
2. Pulsa **Añadir cierre**. Para quitarlo, usa la papelera y confirma en "Quitar cierre".
3. Las reservas ya existentes ese día no se cancelan solas.

### Avisos por correo al personal
Además del correo de Ajustes, cada usuario puede activar "Reservas del restaurante" en sus notificaciones de perfil. Ruta exacta en el menú: A confirmar. Ver [Permisos y avisos](59-restaurante-permisos-y-avisos.md).

## Problemas frecuentes y cómo resolverlos
- **"Este turno tiene N reservas pendientes o confirmadas de hoy en adelante. Muévelas o cancélalas antes."** → no se puede borrar un turno con reservas futuras.
- **"El turno tiene reservas pasadas: se ha desactivado en lugar de borrarse."** → es normal; queda desactivado.
- **El cliente no puede reservar** → no hay turnos, el turno está inactivo, no se ofrece ese día, hay un cierre, o se pasó el plazo/antelación.
- **Reservan más gente de la que cabe** → el personal con permiso puede superar aforo; revisa el aforo del turno.
- **El QR impreso ya no funciona** → se cambió el enlace; hay que reimprimir y volver a emparejar tótems.
- **Los avisos no llegan** → revisa el correo de avisos y las notificaciones del usuario.
- **Las horas parecen desplazadas** → se usa la zona horaria del hotel ([Configuración](34-configuracion.md)).

## Preguntas típicas de clientes
- **¿Cómo cierro el restaurante un día?** Días cerrados > Añadir cierre.
- **¿Cómo limito los comensales por turno?** Aforo del turno.
- **¿Cómo pido que se valide antes de confirmar?** Es el comportamiento normal: las solicitudes llegan pendientes.
- **¿Puedo reservar por hora en vez de por servicio?** Sí, "Cómo se reserva" = Por hora.
- **¿Pueden reservar clientes que no son del hotel?** Sí, con "Admitir clientes que no se alojan en el hotel".
- **¿Cuánta antelación tienen?** "Días de antelación" (30 por defecto).
- **¿Puedo cambiar el QR?** Con Cambiar el enlace, sabiendo que el anterior deja de valer.

## Términos y relación con otros apartados
- **Turno**: franja con aforo. **Aforo**: máximo de comensales. **Cierre**: día o turno sin reservas.
- Relacionado: [Reservas](50-restaurante-reservas.md), [Series](58-restaurante-series-reservas.md), [Carta y menús](52-restaurante-cartas.md), [Tótem](55-restaurante-dispositivos-totem.md), [Servicios](15-servicios.md).
