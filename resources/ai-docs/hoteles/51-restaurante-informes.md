# Restaurante: Informes

## Para qué sirve
Muestra cuántas reservas (o comensales) ha tenido el restaurante en un periodo, agrupadas por semana, mes o año. Sirve para ver de dónde vienen las reservas (tótem, móvil o personal) y qué parte son huéspedes del hotel frente a clientes externos.

## Quién lo usa y dónde está
- Menú: **Restaurante > Informes**. Título de la página: "Informe del restaurante".
- Lo ve quien tenga el permiso **View restaurant reports**.
- Requiere el módulo Restaurante activo ([Reservas](50-restaurante-reservas.md)).

## Cómo se hace (paso a paso)

### Consultar un informe
1. Entra en **Restaurante > Informes**.
2. Elige **Desde** y **Hasta** (por defecto, desde el inicio del mes de hace 5 meses hasta el final del mes actual). **Hasta** no puede ser anterior a **Desde**.
3. Elige **Agrupar por**: Semana, Mes (por defecto) o Año.
4. Elige **Estado**: por defecto **Confirmada**; puedes cambiarlo a otro estado o a "Todos los estados".
5. Alterna entre **Reservas** (número de reservas) y **Comensales** (personas).
6. Lee las dos tablas, cada una con filas por Periodo y una fila Total:
   - **Reservas por canal**: Tótem, Móvil (QR) y Personal.
   - **Reservas por origen del cliente**: "Con habitación del hotel" (la reserva lleva número de habitación) y "Externo" (sin habitación).

## Problemas frecuentes y cómo resolverlos
- **"No hay reservas en el rango elegido."** → amplía fechas o cambia el estado a "Todos los estados"; por defecto solo cuenta las confirmadas.
- **Error en Hasta** → debe ser igual o posterior a Desde.
- **No veo Informes en el menú** → falta el permiso "View restaurant reports" ([Roles y permisos](04-roles-permisos.md)).
- **Los números no cuadran con mis reservas pendientes** → el filtro por defecto es Confirmada.
- **Hay huéspedes contados como externos** → el origen se decide solo por si la reserva tiene habitación; si no se rellenó, cuenta como Externo.
- **Exportar a Excel/PDF** → no consta en el código. A confirmar.

## Preguntas típicas de clientes
- **¿Cuántas reservas hicimos el mes pasado?** Informes, Agrupar por Mes, estado Confirmada.
- **¿Cuántas vienen del tótem y cuántas del QR?** En la tabla "Reservas por canal".
- **¿Cuántos clientes no son del hotel?** Tabla de origen, fila "Externo".
- **¿Cuenta personas o reservas?** Lo que marque el interruptor Reservas / Comensales.
- **¿Se cuentan las canceladas?** Solo si cambias el estado a Cancelada o "Todos los estados".
- **¿Puedo ver el informe por semanas?** Sí, Agrupar por Semana.

## Términos y relación con otros apartados
- **Canal / origen de la reserva**: Tótem, Móvil (QR), Personal ([Reservas](50-restaurante-reservas.md), [Tótem](55-restaurante-dispositivos-totem.md), [Web del comensal](56-restaurante-web-comensal-qr.md)).
- **Comensales**: personas de la reserva. Permisos: [Permisos y avisos](59-restaurante-permisos-y-avisos.md).
