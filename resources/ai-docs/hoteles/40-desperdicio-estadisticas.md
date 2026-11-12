# Desperdicio: Estadísticas

## Para qué sirve
Cruza lo que sale de cocina con lo que se desperdicia, y muestra el **porcentaje de desperdicio** por plato, servicio y periodo. También hay estadísticas propias de desperdicio y de merma útil.

## Quién lo usa y dónde está
Dirección y jefes de cocina. Menú **Control desperdicios > Estadísticas**. Solo aparece si el buffet tiene KITCHEN_OUTPUT (que a su vez necesita WASTE_SYSTEM).

## Cómo se hace (paso a paso)
1. Entrar en Control desperdicios > Estadísticas.
2. Elegir el rango de fechas. Por defecto se muestran los **últimos 30 días**.
3. Revisar los datos de salida frente a desperdicio y el % de desperdicio.
4. Las estadísticas de desperdicio, de merma útil y de planograma muestran por defecto el **mes actual**.

## Problemas frecuentes y cómo resolverlos
- **No salen datos**: no se han registrado salidas ni desperdicios en el rango. Ampliar fechas.
- **El % de desperdicio es raro**: falta registrar la salida de cocina del plato ([Salida de cocina](40-desperdicio-salida-cocina.md)); sin salida no se puede comparar.
- **No aparece la opción**: el buffet no tiene KITCHEN_OUTPUT.

## Preguntas típicas de clientes
- ¿Qué periodo se ve por defecto? 30 días en la combinada; el mes actual en las demás.
- ¿Cómo se calcula el % de desperdicio? Comparando el peso desechado con el que salió de cocina.
- ¿Puedo ver solo un servicio o plato? A confirmar los filtros exactos de la pantalla.
- ¿Por qué no veo las estadísticas? Falta la función KITCHEN_OUTPUT o el permiso.
- ¿Incluye la merma útil? Tiene su propia estadística ([Merma útil](40-desperdicio-merma-util.md)).

## Términos y relación con otros apartados
- [Visión general](40-desperdicio.md), [Registro de desperdicios](40-desperdicio-registro.md), [Salida de cocina](40-desperdicio-salida-cocina.md).
- Estadísticas del planograma: [Planograma](41-operativa-planograma.md).
