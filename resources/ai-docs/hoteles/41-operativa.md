# Operativa (visión general)

## Para qué sirve
El módulo Operativa organiza el trabajo diario de cocina y buffet: planificar cuántas bandejas de cada plato se preparan (planograma), registrar lo servido, controlar asistencias de comensales, seguir la producción en un monitor de cocina y gestionar partidas y tareas recurrentes. Se activa con la función **OPERATIVA**, independiente del módulo de [Desperdicio](40-desperdicio.md).

## Quién lo usa y dónde está
Jefes de cocina, cocineros y dirección. Menú lateral **Operativa**. Lo que se ve depende de los permisos del rol (categoría "operativa"):

| Permiso | Para qué |
|---|---|
| `view-planogram` | Ver y planificar el planograma |
| `record-servings` | Registrar bandejas servidas y asistencias |
| `view-planogram-statistics` | Estadísticas del planograma |
| `view-productivity` | Pantalla Producción |

Pantallas:
- [Planograma](41-operativa-planograma.md) (Planificación, Visualización, Estadísticas)
- [Asistencias](41-operativa-asistencias.md)
- [Producción](41-operativa-produccion.md)
- [Partidas](41-operativa-partidas.md) (solo superadmin)
- [Configurar tareas](41-operativa-configurar-tareas.md) (solo superadmin)
- [Bandejas](40-desperdicio-bandejas.md) aparece aquí solo si el buffet no tiene WASTE_SYSTEM.

El superadmin lo ve todo.

## Cómo se hace (paso a paso)
1. El administrador activa OPERATIVA en el buffet y asigna los permisos a los roles ([Roles y permisos](04-roles-permisos.md)).
2. Crear bandejas ([Bandejas](40-desperdicio-bandejas.md)) y tener las ruedas de menú y las isletas listas ([Ruedas de menú](23-ruedas-de-menu.md), [Isletas](20-isletas.md)).
3. El superadmin crea las partidas y clasifica los platos.
4. Cada día: planificar, servir y registrar asistencias.

## Problemas frecuentes y cómo resolverlos
- **No veo "Operativa"**: falta la función o el rol no tiene ningún permiso del módulo.
- **Veo Planograma pero no Producción**: falta `view-productivity`.
- **No veo Partidas ni Configurar tareas**: solo el superadmin.

## Preguntas típicas de clientes
- ¿Qué permisos hacen falta? Los de la tabla anterior.
- ¿Operativa depende de Desperdicio? No, son independientes.
- ¿Dónde se gestionan las bandejas? En Desperdicio si hay WASTE_SYSTEM, si no en Operativa.
- ¿Quién crea las partidas? El superadmin.
- ¿Hay relación entre usuarios e isletas? Existe la estructura en base de datos, pero no hay pantalla para gestionarla (a confirmar).

## Términos y relación con otros apartados
- Partida: sección de cocina (fríos, grill...) a la que se asignan platos.
- Planograma: plan de bandejas por plato, isleta y servicio.
- [Platos](12-platos.md), [Servicios](15-servicios.md), [Isletas](20-isletas.md), [Posiciones](21-posiciones.md).
