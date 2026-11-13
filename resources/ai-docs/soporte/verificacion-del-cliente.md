# Verificar quién escribe antes de hablar de un hotel

Cuando una consulta depende de un hotel (etiquetas, antenas, usuarios, menús, accesos), no se habla de datos concretos de ese hotel hasta saber que quien escribe es de ese hotel. El orden es siempre el mismo:

1. **Hotel.** Se pregunta el nombre del hotel si el cliente no lo dice. El sistema lo busca en AdminHoteles (la aplicación donde están todos los hoteles).
2. **Correo.** Se pide el correo con el que accede a la aplicación y el sistema comprueba que figura como usuario activo.
3. **Coincidencia.** Si el correo figura y es de ese hotel, se sigue: ya se puede ver el estado de las antenas y de las etiquetas de ese hotel y ayudar con datos concretos.

## Como máximo dos preguntas por mensaje

Preguntar muchas cosas a la vez agobia al cliente y no ayuda a lo importante. Por eso:

- Si todavía no sabemos quién escribe, el mensaje pregunta **solo** el nombre del hotel (o sede) y el correo con el que accede a la aplicación. Nada más.
- Los demás detalles (código de la etiqueta, fotos, qué etiquetas fallan, en qué pantalla, desde cuándo) se piden **después**, cuando el cliente haya contestado.
- Si ya sabemos hotel y correo, se pregunta lo más importante, **como mucho dos cosas**, y lo demás queda para el siguiente mensaje.
- Nunca se envía una lista de tres o más datos.

## Si no coincide

No se corta al cliente de golpe ni se le hace sentir sospechoso: es normal que alguien escriba desde otro correo (un compañero, un correo personal, un cambio reciente).

- Se le explica con tacto que, con el correo que nos indica, no podemos asociarle a ese hotel en nuestro sistema.
- Se le pide que confirme el nombre del hotel y el correo con el que accede a la aplicación.
- Se termina diciendo que un compañero del equipo revisará su caso y le atenderá personalmente. No se sigue con el diagnóstico.
- No se da ningún dato del hotel ni de su estado.
- **Nunca** se dice a qué hotel pertenece el correo: sería revelar datos de otro cliente.

## Si no se puede comprobar

Si el sistema no consigue comprobar el correo en ese momento (la consulta falla o no responden todas las aplicaciones), **no se afirma que el correo no pertenece al hotel**: no es lo mismo «no figura» que «no hemos podido comprobarlo». Se dice que un compañero comprobará sus datos y le atenderá.

## Si el hotel no aparece en la búsqueda

Que un hotel no salga al buscarlo **no significa que no exista**. Puede ser un hotel recién dado de alta (la lista del sistema se actualiza cada pocos minutos), un hotel oculto o uno cuya aplicación aún no está conectada. Nunca se le dice al cliente que su hotel «no existe» ni que «no consta»: se le pide que confirme el nombre exacto y se explica que un compañero del equipo lo revisará.

## Qué se puede decir y qué no

- Nunca se dan claves, contraseñas, IP ni direcciones MAC de antenas.
- No se dice de dónde sale la información («según AdminHoteles»…): se habla como equipo de soporte.
- Con antenas sin conexión no se pide reiniciar ni tocar nada: se dice que el equipo de soporte lo revisará.
