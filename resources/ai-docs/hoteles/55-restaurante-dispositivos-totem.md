# Restaurante: Dispositivos (tótem nativo)

## Para qué sirve
Permite emparejar tabletas o tótems del hotel con el restaurante para que los clientes reserven en una pantalla física. Desde el panel se ve qué tótems hay, si están conectados, y se pueden renovar o revocar. Estado: en desarrollo (plan 0020); lo que sigue es lo que hace hoy el panel.

## Quién lo usa y dónde está
- Tarjeta **Dispositivos (tótem nativo)** dentro de **Restaurante > Ajustes** ([Ajustes](54-restaurante-ajustes.md)). No tiene entrada propia en el menú.
- Requiere el permiso **Edit restaurant settings**.
- La aplicación del tótem en sí se instala en el dispositivo; cómo se instala o distribuye: A confirmar.

## Cómo se hace (paso a paso)

### Emparejar un tótem
1. En la tarjeta pulsa **Emparejar tótem**.
2. Aparece un QR y un código. Valen 10 minutos y sirven una sola vez.
3. En la aplicación del tótem, escanea el QR o escribe el código.
4. El tótem aparece en la lista con su nombre y versión.

### Revisar el estado
La lista muestra por tótem: nombre, versión de la aplicación y "visto hace N min" o "sin señales todavía". Se marca en rojo si lleva más de 15 minutos sin avisar o nunca lo ha hecho. También puede mostrar "Revocado" o "token pendiente de renovar".

### Renovar el acceso
Pulsa **Renovar**: la renovación llega al tótem la próxima vez que avise. Mientras está pendiente, el botón está desactivado.

### Revocar un tótem
Pulsa **Revocar** (no aparece en los ya revocados). El tótem deja de funcionar y habrá que emparejarlo de nuevo.

### Cómo se comporta el tótem
Tras los segundos de inactividad configurados en Ajustes (60 por defecto) vuelve a la pantalla de inicio y borra los datos del cliente. Sus reservas cuentan como origen Tótem en los [Informes](51-restaurante-informes.md) y entran como solicitudes pendientes.

## Problemas frecuentes y cómo resolverlos
- **"El código no es válido o ha caducado."** → genera uno nuevo con Emparejar tótem (10 min, un uso).
- **Tótem en rojo / "sin señales todavía"** → sin conexión, apagado o sin emparejar bien. Revisa red y reinicia la app.
- **"Token pendiente de renovar"** → esperar a que el tótem se conecte.
- **El tótem dejó de funcionar tras cambiar el enlace público** → "Cambiar el enlace" invalida los tótems; hay que emparejarlos de nuevo.
- **Un tótem perdido o robado** → Revocar.
- **No veo la tarjeta** → falta el permiso Edit restaurant settings o el módulo no está activo.
- **"Todavía no hay ningún tótem emparejado."** → es el estado vacío.

## Preguntas típicas de clientes
- **¿Cómo conecto una tableta?** Emparejar tótem y escanear el código en la app.
- **¿Cuánto dura el código?** 10 minutos, un solo uso.
- **¿Cómo sé si el tótem funciona?** Mira "visto hace...".
- **¿Dónde llegan las reservas del tótem?** Al panel de [Reservas](50-restaurante-reservas.md), pendientes de validar.
- **¿Puedo quitar un tótem?** Revocar.
- **¿Se guardan los datos del cliente en pantalla?** Se borran al volver al inicio por inactividad.
- **¿Hay límite de tótems?** A confirmar.

## Términos y relación con otros apartados
- **Emparejar**: vincular un dispositivo. **Revocar**: retirarle el acceso. **Renovar**: cambiar su credencial.
- Relacionado: [Ajustes](54-restaurante-ajustes.md), [Reservas](50-restaurante-reservas.md), [Web del comensal](56-restaurante-web-comensal-qr.md).
