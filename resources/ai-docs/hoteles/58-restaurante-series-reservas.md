# Restaurante: Reservas de varios días (series)

## Para qué sirve
Permite pedir de una sola vez las reservas de varios días (una semana, un mes...) para el mismo cliente, turno y número de comensales, por ejemplo un grupo o un huésped de larga estancia. Cada día se convierte en una reserva con su propio estado.

## Quién lo usa y dónde está
- Menú: **Restaurante > Reservas**, botón **Nueva serie** (arriba a la derecha, junto a **Nueva reserva**).
- Lo ve quien pueda crear reservas o gestionar confirmadas. Comprobar días y crear la serie piden permiso de crear reservas; crearla ya confirmada y cancelar días confirmados pide "Create, edit or cancel confirmed reservations"; **Aprobar todo** pide "Validate or reject reservations"; **Cancelar serie** pide permiso de editar pendientes o de gestionar confirmadas.
- Requiere el módulo Restaurante activo (ver [Reservas](50-restaurante-reservas.md)).

## Cómo se hace (paso a paso)

### Crear una serie
1. En Reservas pulsa **Nueva serie**; se abre "Reservas de varios días".
2. Usa los atajos **Esta semana**, **Próxima semana**, **Este mes**, **Próximo mes**, o rellena **Desde** y **Hasta**. El máximo son 31 días.
3. Marca los **Días de la semana** que quieres.
4. Elige **Turno**, **Hora de llegada** (si el restaurante reserva por hora), **Comensales**, **Nombre y apellidos**, **Correo electrónico**, y opcionalmente **Teléfono** y **Habitación**.
5. Pulsa **Comprobar días**. Cada día sale como **Cabe**, **Hora llena** (se puede elegir otra hora o **Saltar este día**) o **No disponible** (queda fuera).
6. Pulsa **Crear serie**. Mensajes: "Serie creada." y, si procede, "N días no disponibles se han dejado fuera."

### Gestionar una serie existente
1. Pulsa la etiqueta de serie de una reserva; se abre "Serie de reservas".
2. **Aprobar todo** confirma los días pendientes. Resultado tipo "X confirmados, Y saltados, Z por resolver".
3. **Cancelar serie** cancela todos los días que sigan abiertos.
4. Los días sin resolver aparecen "por resolver" en el calendario.

## Problemas frecuentes y cómo resolverlos
- **No aparece Nueva serie** → faltan permisos de crear reservas.
- **Error de que el rango es demasiado largo** → la serie admite como máximo 31 días y respeta los días de antelación de [Ajustes](54-restaurante-ajustes.md). Acorta las fechas.
- **Un día sale "No disponible"** → está cerrado, el turno no se ofrece ese día de la semana o supera la antelación. No se crea.
- **Un día sale "Hora llena"** → elige otra hora del turno o sáltalo.
- **No puedo cancelar días confirmados** → hace falta el permiso de gestionar confirmadas.
- **Correos por serie** → qué correo exacto recibe el cliente por una serie: A confirmar.

## Preguntas típicas de clientes
- **¿Puedo reservar toda la semana de una vez?** Sí, con Nueva serie > Esta semana.
- **¿Cada día es una reserva distinta?** Sí, con su estado propio.
- **¿Cuántos días puedo pedir?** Hasta 31 por serie.
- **¿Qué pasa si un día está lleno?** Se propone otra hora del turno o se salta ese día.
- **¿Puede el cliente crear series desde el móvil?** No consta en el código; las series se crean desde el panel. A confirmar.

## Términos y relación con otros apartados
- **Serie**: conjunto de reservas diarias con los mismos datos. **Por resolver**: días de una serie con la hora llena.
- Ver [Reservas](50-restaurante-reservas.md), [Ajustes](54-restaurante-ajustes.md) y [Permisos y avisos](59-restaurante-permisos-y-avisos.md).
