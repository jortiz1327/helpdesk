# Control de desperdicios (visión general)

## Para qué sirve
El módulo "Control desperdicios" permite medir cuánta comida sale de la cocina y cuánta se tira o se aprovecha después, para calcular el porcentaje de desperdicio y reducirlo. Tiene dos funciones independientes que se activan por buffet:

- **Desperdicio (WASTE_SYSTEM)**: registro de comida desechada, merma útil (comida reaprovechada), bandejas y básculas.
- **Salida de cocina (KITCHEN_OUTPUT)**: registro de lo que sale de cocina ("Escandallo platos") y estadísticas que cruzan salida y desperdicio. Necesita que WASTE_SYSTEM esté activa.

## Quién lo usa y dónde está
Lo usa el personal de cocina y de dirección. En el menú lateral aparece el apartado **Control desperdicios** solo si el buffet tiene activada la función. Contiene:

| Opción del menú | Qué es | Archivo |
|---|---|---|
| Estadísticas | Salida de cocina frente a desperdicio | [Estadísticas](40-desperdicio-estadisticas.md) |
| Escandallo platos | Registro de salida de cocina | [Salida de cocina](40-desperdicio-salida-cocina.md) |
| Desperdicios | Registro de comida desechada | [Registro de desperdicios](40-desperdicio-registro.md) |
| Merma útil | Comida reaprovechada | [Merma útil](40-desperdicio-merma-util.md) |
| Bandejas | Tipos y bandejas | [Bandejas](40-desperdicio-bandejas.md) |
| Básculas | Centros de consumo (solo superadmin) | [Básculas](40-desperdicio-basculas.md) |

Si el buffet no tiene la función activada, las pantallas redirigen a Inicio con un mensaje de error. La activación la hace el administrador en la ficha del buffet (ver [Contenedores](33-contenedores-hoteles-buffets-grupos.md)).

## Cómo se hace (paso a paso)
1. Pedir al administrador que active WASTE_SYSTEM (y KITCHEN_OUTPUT si se quiere registrar la salida de cocina).
2. Crear las bandejas que se usan en el buffet ([Bandejas](40-desperdicio-bandejas.md)).
3. Dar de alta las básculas / centros de consumo (solo superadmin) ([Básculas](40-desperdicio-basculas.md)).
4. Registrar peso de salida y de desperdicio, bien a mano en pantalla, bien desde el programa de la báscula.
5. Consultar las estadísticas.

### Programa de la báscula
Además de la web, existe un programa de báscula que se conecta por API. Solo responde en buffets con WASTE_SYSTEM o KITCHEN_OUTPUT. El operario se identifica con un **PIN** personal: se genera automáticamente para cada usuario y se ve (solo lectura) en el Perfil como "Scale PIN".

## Problemas frecuentes y cómo resolverlos
- **No veo el menú "Control desperdicios"**: el buffet no tiene la función activada. Pedir su activación.
- **Falta "Estadísticas" y "Escandallo platos"**: solo salen con KITCHEN_OUTPUT.
- **No veo "Básculas"**: solo lo ve el superadmin.
- **El programa de la báscula no deja entrar**: comprobar que el buffet tiene la función y que el PIN es el del perfil del usuario.

## Preguntas típicas de clientes
- ¿Cómo activo el control de desperdicios? Lo activa el administrador por buffet.
- ¿Qué diferencia hay entre "Desperdicios" y "Merma útil"? Desperdicios es lo que se tira; merma útil es lo que se reaprovecha.
- ¿Dónde veo mi PIN de la báscula? En Perfil, campo "Scale PIN".
- ¿Puedo usar solo la salida de cocina sin desperdicios? No: KITCHEN_OUTPUT depende de WASTE_SYSTEM.
- ¿Quién puede crear básculas? Solo el superadmin.
- ¿Se guarda un historial de los cambios? Sí, hay registros de actividad para bandejas, básculas, desperdicios y salida de cocina.

## Términos y relación con otros apartados
- Platos: [Platos](12-platos.md). Servicios: [Servicios](15-servicios.md).
- La separación entre Desperdicio y Operativa: las bandejas se gestionan aquí si hay WASTE_SYSTEM y en [Operativa](41-operativa.md) si no.
- Permisos: [Roles y permisos](04-roles-permisos.md).
- A confirmar: la etiqueta exacta de los textos del botón "Register" en los formularios (la traducción al español es "Registrarse").
