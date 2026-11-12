# Operativa: Planograma

## Para qué sirve
Planificar cuántas bandejas de cada plato se preparan por servicio, mostrar a sala qué servir y ver estadísticas de lo planificado frente a lo real. Tiene un panel con tarjetas: **Planificación**, **Visualización**, **Estadísticas** y **Asistencias** (ver [Asistencias](41-operativa-asistencias.md)).

## Quién lo usa y dónde está
Menú **Operativa > Planograma**. Permisos:
- Planificación: `view-planogram`.
- Visualización (servir): `record-servings`.
- Estadísticas: `view-planogram-statistics`.

## Cómo se hace (paso a paso)

### Planificación
1. Elegir **Rueda de menús** y **Menú** (día).
2. Opcional: **Moneda** ("Usar la del hotel" o la que se elija).
3. Pulsar **Comensales esperados** e indicar el "Número de clientes".
4. Hay pestañas por servicio, con el coste del servicio y el "Total del día".
5. Cada isleta es un acordeón con la tabla: Posición, Plato, Bandeja, **Bandejas a planificar** (botones − y +), Coste por bandeja, Peso de bandeja y Coste total.
6. La varita mágica "Generar automáticamente" calcula las bandejas de una posición a partir del histórico (se muestra "~N · ratio/pers"). Usa el mismo día de años anteriores, mismo día del mes, día de la semana parecido, misma temporada y lo más reciente.
7. Puede aparecer el aviso "Recomendación de rueda": "Activar rueda" o "Ahora no".
8. Se guarda solo: indicador Guardando... / Guardado / Error al guardar.

### Visualización
1. Elegir "Versión completa" o "Versión móvil/tablet".
2. Pestañas Actual y Cambios temporales; selector de hora.
3. Por plato: pulsar **Servir** para sumar una bandeja servida, "Servir todas las bandejas planificadas", "Servir una bandeja" o **Deshacer**. También hay reposiciones extra y **Reciclado**.
4. Se ven Bandejas planificadas, servidas, Faltan y Total servido. Si falta precio: "Establece primero un precio de bandeja".
5. Si no hay menú: "Hoy no hay ningún menú activo".

### Estadísticas
Bandejas salidas de cocina, servidas, reposiciones, consumidas (servidas + reposiciones − recicladas), recicladas, coste total y planificado, coste por servicio e isleta, precisión de planificación, resumen diario y los 10 platos más servidos y más reciclados.

## Problemas frecuentes y cómo resolverlos
- **"Enter the expected pax first"**: poner antes los comensales esperados para usar el cálculo automático.
- **No genera nada ("no history")**: no hay histórico suficiente.
- **"Establece primero un precio de bandeja"**: falta el precio de la bandeja ([Bandejas](40-desperdicio-bandejas.md)).
- **Error al guardar**: recargar y reintentar.
- **"Hoy no hay ningún menú activo"**: no hay rueda/menú asignado hoy ([Ruedas de menú](23-ruedas-de-menu.md)).

## Preguntas típicas de clientes
- ¿Cómo se calcula el número de bandejas? Por histórico y comensales esperados.
- ¿Hay que guardar? No, autoguardado.
- ¿Puedo deshacer un servido? Sí, botón Deshacer.
- ¿Qué es reciclar? Una bandeja que vuelve a cocina y se descuenta del consumo.
- ¿Puedo cambiar de moneda? Sí.
- ¿Funciona en tablet? Sí, versión móvil/tablet.
- ¿Se puede generar todo el servicio de una vez? No hay botón; es posición a posición.

## Términos y relación con otros apartados
- [Operativa](41-operativa.md), [Asistencias](41-operativa-asistencias.md), [Producción](41-operativa-produccion.md), [Isletas](20-isletas.md), [Ruedas de menú](23-ruedas-de-menu.md), [Servicios](15-servicios.md).
