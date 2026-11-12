# Operativa: Asistencias

## Para qué sirve
Registrar los **comensales reales** de cada servicio y comparar con lo previsto. Alimenta el cálculo automático del planograma y las estadísticas de merma.

## Quién lo usa y dónde está
Menú **Operativa > Planograma > Asistencias**. Necesita `record-servings` o `view-planogram-statistics`; la previsión necesita `record-servings`.

## Cómo se hace (paso a paso)
1. Navegar con "Día anterior", Hoy o "Día siguiente".
2. Ver los "Servicios del día" (uno "En curso" se marca).
3. En un servicio, pulsar **Insertar comensales** (o Editar) e indicar los **Comensales reales**.
4. Para hoy solo se puede guardar cuando el servicio termina ("El servicio aún no ha terminado"). Días pasados: abiertos.
5. **Previsión de comensales**: calendario con sugerencia basada en el mismo día de la semana.
6. **Historial de previsión**: Semana / Mes / Rango y Aplicar. Muestra "Comensales previstos vs reales", "Kg producidos", "Kg sobrante est.", "% merma real", "Desglose diario" y "Columnas por servicio".

## Problemas frecuentes y cómo resolverlos
- **No me deja guardar hoy**: el servicio no ha acabado.
- **No veo la previsión**: falta `record-servings`.
- **Datos vacíos en el historial**: no hay asistencias en el rango.

## Preguntas típicas de clientes
- ¿Puedo corregir un día pasado? Sí.
- ¿Qué es "Kg sobrante est."? Estimación del sobrante en kilos.
- ¿La previsión es automática? Es una sugerencia por día de la semana; se puede aplicar.
- ¿Por qué no puedo meter los comensales de hoy? Hasta que termine el servicio.
- ¿Afecta al planograma? Sí, mejora las estimaciones.

## Términos y relación con otros apartados
- [Planograma](41-operativa-planograma.md), [Operativa](41-operativa.md), [Servicios](15-servicios.md).
