# Desperdicio: Básculas (centros de consumo)

## Para qué sirve
Dar de alta los puntos donde hay una báscula conectada al programa de pesaje. Cada báscula se identifica con un **Centro de consumo** (su nombre).

## Quién lo usa y dónde está
Solo el **superadmin**. Menú **Control desperdicios > Básculas**. Requiere WASTE_SYSTEM.

## Cómo se hace (paso a paso)
1. Entrar en Básculas.
2. Crear una nueva: el único campo es **Centro de consumo** (nombre).
3. Editar o borrar desde la tabla.
4. En el programa de la báscula, el operario entra con su PIN (Perfil > "Scale PIN") y elige la báscula.

## Problemas frecuentes y cómo resolverlos
- **No veo Básculas**: el usuario no es superadmin o falta WASTE_SYSTEM.
- **El programa no lista la báscula**: comprobar que el buffet es el correcto.

## Preguntas típicas de clientes
- ¿Qué es un centro de consumo? El nombre del punto donde está la báscula (por ejemplo, un restaurante o sala).
- ¿Puedo crear una báscula yo mismo? Solo con perfil superadmin; si no, pedirlo.
- ¿Cuántos campos tiene? Solo el nombre.
- ¿Cómo entra el cocinero a la báscula? Con su PIN personal.
- ¿Se registran los cambios? Sí, hay log de básculas.

## Términos y relación con otros apartados
- [Visión general](40-desperdicio.md), [Usuarios](03-usuarios.md), [Roles y permisos](04-roles-permisos.md).
