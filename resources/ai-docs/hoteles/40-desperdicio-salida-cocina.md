# Desperdicio: Salida de cocina ("Escandallo platos")

## Para qué sirve
Registrar el peso de cada plato que sale de cocina hacia el buffet. Es la base para calcular el porcentaje de desperdicio.

## Quién lo usa y dónde está
Personal de cocina. Menú **Control desperdicios > Escandallo platos** (la página se titula "Salida de cocina"). Requiere KITCHEN_OUTPUT. También se puede registrar desde el programa de la báscula.

## Cómo se hace (paso a paso)
1. Entrar en Escandallo platos.
2. Rellenar el formulario: **Plato** y **Servicio** (obligatorios), **Peso** en kg (mínimo 0,01), posición (opcional) y **Bandeja** (opcional).
3. Pulsar el botón de registrar. La fila aparece en la tabla (columnas Posición, Plato, Servicio, Peso (kg), Fecha, Bandeja).

## Problemas frecuentes y cómo resolverlos
- **No me deja guardar**: faltan plato o servicio, o el peso es menor de 0,01.
- **No aparece el plato**: debe estar en el catálogo ([Platos](12-platos.md)).
- **El menú no aparece**: el buffet no tiene KITCHEN_OUTPUT.
- **El botón dice "Registrarse"**: es la traducción actual del texto "Register"; funciona igual.

## Preguntas típicas de clientes
- ¿En qué unidad se mete el peso? Kilogramos.
- ¿Puedo corregir un registro? A confirmar si la tabla permite editar o borrar filas.
- ¿Hace falta indicar la bandeja? No, es opcional.
- ¿Se puede registrar desde la báscula? Sí, con el PIN del usuario.
- ¿Para qué sirve? Para las [Estadísticas](40-desperdicio-estadisticas.md).

## Términos y relación con otros apartados
- [Visión general](40-desperdicio.md), [Bandejas](40-desperdicio-bandejas.md), [Servicios](15-servicios.md), [Posiciones](21-posiciones.md).
