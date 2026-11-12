# Desperdicio y Operativa: Bandejas

## Para qué sirve
Definir las bandejas (contenedores gastronorm) que se usan en el buffet: su tamaño, peso e imagen. Se usan para registrar salidas, para planificar en el planograma y para calcular costes por bandeja.

## Quién lo usa y dónde está
Gestores del buffet. La pantalla es la misma, pero el menú cambia:
- Con **WASTE_SYSTEM**: **Control desperdicios > Bandejas**.
- Sin WASTE_SYSTEM pero con **OPERATIVA**: **Operativa > Bandejas**.
Si el buffet no tiene ninguna de las dos, sale el error "This buffet does not have access to the tray system".

## Cómo se hace (paso a paso)
1. Entrar en Bandejas.
2. Crear una bandeja: **nombre** (obligatorio), dimensiones y peso (cero o más), **cantidad** (entero de al menos 1) e imagen (hasta 5 MB).
3. Opcional: crear un **tipo de bandeja** ("Create new tray type") con un nombre.
4. Editar o borrar desde la tabla; el borrado pide confirmación.

## Problemas frecuentes y cómo resolverlos
- **No se sube la imagen**: pesa más de 5 MB.
- **Error de cantidad**: debe ser entero y mínimo 1.
- **No veo Bandejas**: el buffet no tiene WASTE_SYSTEM ni OPERATIVA.
- **Borré una bandeja y falla un registro**: a confirmar el efecto sobre registros anteriores.

## Preguntas típicas de clientes
- ¿Dónde están las bandejas si no tengo desperdicios? En Operativa.
- ¿Para qué sirve el tipo de bandeja? Para clasificarlas.
- ¿Qué tamaño de imagen admite? 5 MB.
- ¿Puedo poner el peso de la bandeja vacía? Sí, hay campo de peso.
- ¿Se usa en el planograma? Sí: [Planograma](41-operativa-planograma.md).

## Términos y relación con otros apartados
- [Visión general](40-desperdicio.md), [Operativa](41-operativa.md), [Salida de cocina](40-desperdicio-salida-cocina.md).
