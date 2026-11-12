# Desperdicio: Registro de desperdicios

## Para qué sirve
Anotar el peso de comida que se tira, por plato y servicio, para medir el desperdicio.

## Quién lo usa y dónde está
Personal de cocina y sala. Menú **Control desperdicios > Desperdicios**. Requiere WASTE_SYSTEM. También se puede registrar desde el programa de la báscula (que usa el servicio actual del buffet y puede enviar foto y marca de "reaprovechado").

## Cómo se hace (paso a paso)
1. Entrar en Desperdicios.
2. Elegir **Plato** y **Servicio** (obligatorios), **Peso** en kg (mínimo 0,01) y, si se quiere, la posición.
3. Registrar. El dato aparece en la tabla con Posición, Plato, Servicio, Peso (kg) y Fecha.

## Problemas frecuentes y cómo resolverlos
- **Error al guardar**: revisar que plato, servicio y peso están rellenos y que el peso es al menos 0,01.
- **No encuentro el menú**: falta WASTE_SYSTEM en el buffet.
- **El servicio no aparece**: debe existir en [Servicios](15-servicios.md).

## Preguntas típicas de clientes
- ¿Puedo registrar sin posición? Sí, es opcional.
- ¿Qué pasa si lo reaprovecho? Se registra como [Merma útil](40-desperdicio-merma-util.md).
- ¿Se puede adjuntar foto? Desde el programa de la báscula sí; en la web, a confirmar.
- ¿Dónde veo el total? En [Estadísticas](40-desperdicio-estadisticas.md).
- ¿Queda constancia de quién lo registró? Hay registro de actividad (log) de desperdicios.

## Términos y relación con otros apartados
- [Visión general](40-desperdicio.md), [Platos](12-platos.md), [Servicios](15-servicios.md).
