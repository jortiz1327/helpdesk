# Eventos (visión general)

## Para qué sirve
El módulo Eventos permite montar menús puntuales (bodas, convenciones, cenas de gala) y mostrarlos en etiquetas electrónicas reservadas para eventos, sin tocar la carta normal del buffet.

El esquema es: **Etiquetas** de eventos -> se agrupan en **Kits** (conjuntos de posiciones) -> los kits forman un **Menú de evento** -> se asignan platos a cada posición -> se **fuerza** el menú para enviarlo a las etiquetas.

## Quién lo usa y dónde está
Dirección y personal de banquetes. Menú lateral **Eventos**, con tres opciones: Menús, Kits y Etiquetas. Aparece si el hotel tiene la función EVENT_MENUS; si no, las pantallas devuelven 404. Un restaurante independiente no accede a estas pantallas.

- [Etiquetas de eventos](42-eventos-etiquetas.md)
- [Kits](42-eventos-kits.md)
- [Menús de eventos](42-eventos-menus.md)

## Cómo se hace (paso a paso)
1. Añadir las etiquetas que se dedicarán a eventos ([Etiquetas](42-eventos-etiquetas.md)).
2. Crear uno o varios kits con esas etiquetas ([Kits](42-eventos-kits.md)).
3. Crear el menú con sus kits y asignar platos ([Menús](42-eventos-menus.md)).
4. Forzar el menú el día del evento.

## Problemas frecuentes y cómo resolverlos
- **No veo el menú Eventos**: el hotel no tiene EVENT_MENUS.
- **Da 404**: misma causa.
- **No puedo añadir etiquetas**: hace falta el permiso `add-event-pricetags` ([Roles y permisos](04-roles-permisos.md)).

## Preguntas típicas de clientes
- ¿Para qué sirven los eventos? Para menús especiales en etiquetas aparte.
- ¿Se mezcla con la carta normal? No, usa etiquetas dedicadas.
- ¿Cuántos menús puedo crear? Los que se quieran; cada kit solo puede estar en un menú.
- ¿Quién puede añadir etiquetas? Roles con `add-event-pricetags` o superadmin.
- ¿Cómo se activa? Lo activa el administrador.

## Términos y relación con otros apartados
- Kit: grupo de posiciones de etiquetas. Menú de evento: conjunto de kits con platos.
- [Etiquetas](30-etiquetas.md), [Posiciones](21-posiciones.md), [Platos](12-platos.md), [Contenedores](33-contenedores-hoteles-buffets-grupos.md).
