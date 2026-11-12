# Restaurante independiente

## Para qué sirve
Un restaurante independiente es un restaurante que cuelga de un hotel pero funciona por su cuenta, sin ser un buffet: solo gestiona reservas, carta y menús. Es la alternativa a activar el módulo Restaurante dentro de un buffet. Estado: en desarrollo (plan 0022).

## Quién lo usa y dónde está
- Se gestiona desde **Contenedores** ([Contenedores](33-contenedores-hoteles-buffets-grupos.md)): tiene su propia ficha. Tipo de contenedor propio ("restaurante"), siempre dentro de un hotel.
- Lo crean y borran los usuarios con permisos de gestión de contenedores; el alta también puede hacerla el proveedor de la plataforma. Qué rol exacto puede crearlo: A confirmar.
- Para su uso diario: el menú **Restaurante** de [Reservas](50-restaurante-reservas.md), [Informes](51-restaurante-informes.md), [Carta y menús](52-restaurante-cartas.md), [Idiomas y mensajes](53-restaurante-textos.md) y [Ajustes](54-restaurante-ajustes.md).

## Diferencias con un buffet que activa Restaurante
- El módulo Restaurante siempre está activo (no se activa ni desactiva).
- No tiene funciones de buffet: comunicaciones/etiquetas, temáticos, eventos, partidas, planograma, bandejas, wellness, etc. Si alguien entra por enlace directo a esas pantallas, se le lleva a Reservas.
- No tiene tienda ni etiquetas electrónicas.
- Su pantalla de inicio es **Reservas**, no el panel del buffet ([Inicio](01-inicio-dashboard.md)).
- Usa **Platos y oferta** como un buffet ([Platos](12-platos.md), [Ofertas](16-ofertas-hoja-oferta.md)); los platos se gestionan a mano, con ingredientes y alérgenos activos.

## Cómo se hace (paso a paso)

### Crear un restaurante
1. En **Contenedores** pulsa crear y elige restaurante (el nombre exacto de la opción: A confirmar).
2. Rellena **Nombre del restaurante**, **Pertenece a** (el hotel), idiomas, fuente de alérgenos, **estructura de platos** (Standard u Offers) y, si es Offers, la **oferta**.
3. Guarda. Se crean los roles «Restaurante · Estándar» y «Restaurante · Administrador» y se asignan los usuarios de grupo.
4. Configura turnos, reglas y QR en [Ajustes](54-restaurante-ajustes.md).

### Ver la ficha
En Contenedores, abre el restaurante: muestra Nombre, Pertenece a, Estructura de platos, Oferta actual y Fecha de creación. Hay botón de volver y **Eliminar restaurante**.

### Eliminar un restaurante
1. En la ficha pulsa **Eliminar restaurante**.
2. En "¿Seguro que quieres eliminar este restaurante?" confirma. Se borran para siempre el restaurante, sus reservas, menús, textos y ajustes. Al borrar un hotel se borran también sus restaurantes.

## Problemas frecuentes y cómo resolverlos
- **No veo el menú de buffet (comunicaciones, etc.)** → es normal en un restaurante independiente.
- **Me lleva a Reservas al abrir una pantalla** → esa pantalla es solo de buffet.
- **"No se ha creado el rol...: el grupo ya tiene el máximo de roles."** → el grupo llegó a 20 roles; borra uno y reintenta ([Roles y permisos](04-roles-permisos.md)).
- **No puedo crear el restaurante** → falta permiso o hotel padre.
- **Se eligió "Offers" pero no hay oferta** → la oferta es obligatoria con esa estructura.
- **Quiero pasar de buffet a restaurante** → no consta migración; A confirmar.

## Preguntas típicas de clientes
- **¿Qué diferencia hay entre buffet con Restaurante y restaurante independiente?** El independiente no tiene funciones de buffet.
- **¿Necesito un buffet?** No, solo el hotel.
- **¿Puedo tener varios restaurantes en el mismo hotel?** Parece que sí; A confirmar límite.
- **¿Tiene etiquetas electrónicas?** No.
- **¿Se pueden cambiar los usuarios?** Sí, en [Usuarios](03-usuarios.md).
- **¿Se puede recuperar tras eliminarlo?** No, se borra definitivamente.

## Términos y relación con otros apartados
- **Restaurante independiente** frente a **Buffet con funcionalidad RESTAURANT**. **Hotel padre**: contenedor del que cuelga.
- Relacionado: [Contenedores](33-contenedores-hoteles-buffets-grupos.md), [Reservas](50-restaurante-reservas.md), [Permisos y avisos](59-restaurante-permisos-y-avisos.md).
