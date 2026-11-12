# Operativa: Partidas

## Para qué sirve
Las partidas son las secciones de cocina (por ejemplo fríos, grill, postres). Se asignan a los platos para que cada monitor de [Producción](41-operativa-produccion.md) muestre solo los suyos.

## Quién lo usa y dónde está
Solo el **superadmin**, con OPERATIVA activa. Menú **Operativa > Partidas**. La clasificación de platos exige además el permiso `edit-dishes` y también se abre desde [Platos](12-platos.md) con el botón "Clasificar los platos en partidas".

## Cómo se hace (paso a paso)
1. Crear partida: **Nombre** (obligatorio), Descripción y Jefe (opcional). Hay chips "Partidas habituales" para rellenar rápido.
2. Editar con el lápiz; **Eliminar partida** pide confirmación y deja sus platos sin partida.
3. **Clasificar platos**: se asigna cada plato a una partida (una sola por plato). Opción "Sin partida" y buscador "Buscar platos...". Se guarda solo.

## Problemas frecuentes y cómo resolverlos
- **No veo Partidas**: no es superadmin o falta OPERATIVA.
- **Un plato no sale en el monitor**: sin partida o en otra.
- **No puedo clasificar**: falta `edit-dishes`.

## Preguntas típicas de clientes
- ¿Un plato puede estar en dos partidas? No.
- ¿Si borro una partida pierdo los platos? No, quedan sin partida.
- ¿Quién puede crearlas? El superadmin.
- ¿Hace falta guardar al clasificar? No, es automático.
- ¿Qué es el Jefe? Responsable de la partida (opcional).

## Términos y relación con otros apartados
- [Operativa](41-operativa.md), [Producción](41-operativa-produccion.md), [Platos](12-platos.md).
