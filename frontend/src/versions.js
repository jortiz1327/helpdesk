// Historial de versiones (la primera es la actual). Frases CORTAS y claras, sin tecnicismos.
// Mantener en paralelo con CHANGELOG.md al cerrar cada versión. Formato de cada una:
//   { version: "1.6.4", date: "24 nov 2026", resumen: "…", mejoras: ["…"], arreglos: ["…"] }
// Las series (1.6, 1.5…) y las subversiones (1.6.4, 1.6.3…) las agrupa solas la ventana de Novedades.
export const APP_VERSION = "1.6.8";
export const VERSIONS = [
    {
        version: "1.6.8",
        date: "24 nov 2026",
        resumen: "La IA distingue el correo de quien escribe del de otra persona que se menciona, y la búsqueda de manuales entiende mejor cómo habla el cliente.",
        mejoras: [
            "La sugerencia comprueba SIEMPRE el correo de quien envía el ticket. Si en el mensaje aparece otro correo (por ejemplo, alguien a quien dar de alta), también se comprueba y se cuenta aparte: si ya es usuario, la IA lo sabe y no explica cómo crearlo.",
            "Si el cliente dice que el correo es suyo («mi usuario es…», «accedo con…»), se usa ese. En el panel aparece la fila «Otro correo».",
            "La búsqueda de manuales reconoce más formas de decir lo mismo (antenas y conectividad, posiciones, ruedas y buffet, isletas): el manual correcto sale en más casos.",
        ],
        arreglos: [],
    },
    {
        version: "1.6.7",
        date: "24 nov 2026",
        resumen: "Arreglo urgente: Ajustes › Asistente IA vuelve a abrirse y «Sugerir» ya no falla en conversaciones de WhatsApp con solo fotos.",
        mejoras: [],
        arreglos: [
            "Ajustes › Asistente IA fallaba al entrar («Algo ha fallado»): vuelven el tope mensual de gasto y la lista de hoteles o aplicaciones que no gestionamos.",
            "«Sugerir» daba error en conversaciones de WhatsApp donde el cliente solo había mandado fotos o archivos sin texto.",
        ],
    },
    {
        version: "1.6.6",
        date: "24 nov 2026",
        resumen: "La IA conoce mejor las pilas y las garantías, sabe a qué departamento pasa cada caso y los manuales tienen un proceso claro para mantenerse al día.",
        mejoras: [
            "Pilas y baterías: manual propio con la pila que usan las etiquetas (CR2450), qué marcas valen (Beidongli, HenliMax o NSN; VARTA no), cuándo es un pedido de material y cómo saber que la pila está baja.",
            "Garantías según el contexto: pedir pilas o reponer material no es una garantía; un seguimiento, un lote grande o una etiqueta dañada sí pasa a Garantías. Antes de pasar el caso, la IA pide tres fotos por etiqueta (de frente, y la parte trasera con y sin pilas).",
            "El reinicio de la etiqueta es de 30 minutos con las pilas fuera, en todas las respuestas, plantillas y manuales.",
            "Vida útil de la pila medida en ciclos (orientativa, nunca en meses), con lo que significa cada color de la batería.",
            "Al pasar un caso a una persona, la IA dice a qué departamento va (Garantías, Postventa, Soporte o Proyectos).",
            "La IA entiende que «tarjetitas» o «carteles» son etiquetas, y distingue las pantallas grandes (LCD, televisores, tótems) de las etiquetas.",
            "La IA consulta por su cuenta los manuales y los casos anteriores al redactar la respuesta.",
            "Ajustes › Asistente IA: tarjeta «Manuales por área» (qué manuales tiene la IA, si están al día y cuándo se revisaron) y tarjeta «Revisión del conocimiento», que cada lunes busca manuales, reglas o plantillas que se contradigan y marca como antiguas las respuestas que ya no valen.",
            "Ajustes › Asistente IA: estado de cada servicio que usa la IA (llamadas, fallos y tiempos).",
            "Guía del portal del cliente actualizada (v1.1): código de 6 dígitos, consulta del estado sin entrar, teléfono opcional, salir, horario, idiomas y fases de la incidencia.",
        ],
        arreglos: [
            "Pedir pilas, tapas o soportes («quería encargar 10 pilas») ya no se confunde con una incidencia.",
        ],
    },
    {
        version: "1.6.5",
        date: "24 nov 2026",
        resumen: "Ajuste de limpieza: se retiran dos ayudas del ticket que no se van a usar.",
        mejoras: [
            "Se quita el botón «Resumen del ticket» del editor de respuestas.",
            "Se quita el aviso de tickets parecidos o del mismo cliente con su botón para fusionar. Fusionar sigue disponible desde el propio ticket y desde la lista.",
        ],
        arreglos: [],
    },
    {
        version: "1.6.4",
        date: "24 nov 2026",
        resumen: "La IA conoce mejor al cliente: localiza el hotel con más facilidad, comprueba mejor quién escribe y tiene en cuenta sus tickets anteriores. Y estas novedades estrenan diseño.",
        mejoras: [
            "Historial del cliente: la sugerencia tiene en cuenta los últimos tickets anteriores de ese cliente o de su hotel (solo cuando su correo está verificado), para no repetir lo que ya se le explicó.",
            "Localiza el hotel aunque el cliente escriba palabras de más o solo un nombre propio («en Islantilla»).",
            "Verificación más fiable: el correo se comprueba también directamente en el hotel, y quien tiene acceso a una cadena se reconoce en cualquiera de sus hoteles.",
            "Los hoteles de aplicaciones que aún no están conectadas (Poseidón, Hoteles03, Fira Barcelona, Lavaka) se pasan a Soporte sin pedir más datos.",
            "Si el cliente nombra una posición (por ejemplo CL02-05), la IA comprueba si existe, si está activa y qué etiqueta y plato tiene.",
            "Reglas más claras: los platos sueltos, las ruedas de menú, los idiomas y la plantilla de etiqueta los gestiona el hotel; el equipo se ocupa del logo y de importar platos desde Excel. Las etiquetas nuevas se vinculan desde Posiciones.",
            "Se pasan a una persona los seguimientos de presupuestos y pedidos, los plazos de envío y las PDA; sustituir material ya comprado se explica en la aplicación.",
            "WhatsApp: si el cliente da su correo y está verificado, se guarda en su ficha; y el contacto se enlaza a su hotel (o a su marca) para no volver a preguntarlo.",
            "Novedades con diseño nuevo: lees cada versión por series (1.6, 1.5…) y por subversión (1.6.4, 1.6.3…), con las mejoras y los arreglos bien separados.",
        ],
        arreglos: [
            "Modificar los platos de un menú ya no se pasa al equipo por error: lo hace el propio hotel.",
            "Quien tiene acceso a más de 4 hoteles ya no sale como «no asociado» a un hotel que sí es suyo.",
        ],
    },
    {
        version: "1.6.3",
        date: "22 nov 2026",
        resumen: "La sugerencia de IA se inserta en tu respuesta con un clic, te dice en qué se basa y ahora hay una página con el flujo completo de la IA.",
        mejoras: [
            "Nuevo botón «Insertar en la respuesta»: pasa la sugerencia al editor con un clic (revísala antes de enviar; si ya habías escrito algo, te pregunta).",
            "Nuevo apartado «En qué se basa»: ves qué manuales, tickets parecidos y plantillas ha usado la IA, para fiarte o descartarla.",
            "Ayuda › Manuales › «Flujo de la IA»: página aparte, a pantalla completa, que explica paso a paso cómo piensa la IA. Pasa el ratón por cada recuadro para ver el detalle.",
            "La IA verifica siempre hotel y correo antes de explicar, pedir otros datos o pasar el caso a una persona (salvo pedidos de material, presupuestos, farmacias y tiendas).",
            "Si el sitio que nombra el cliente no está en el listado de hoteles, no lo da por hotel: pide el nombre exacto y, si sigue sin constar, lo pasa a una persona.",
        ],
    },
    {
        version: "1.6.2",
        date: "21 nov 2026",
        resumen: "El asistente de IA se equivoca menos al decidir cuándo pedir datos, cuándo explicar y cuándo pasar el caso a una persona.",
        mejoras: [
            "Cuando una etiqueta no cambia o se bloquea, primero propone el reinicio de las pilas, y solo pasa el caso a una persona si el cliente ya lo hizo y sigue igual.",
            "Cambiar una etiqueta rota por otra que el hotel ya tiene, o poner pilas nuevas y forzar, ya no se trata como garantía: pide hotel y correo y lo explica.",
            "Con problemas de antenas revisa su estado antes de decidir, y ya no deriva solo porque el cliente diga que son muchos o que es urgente.",
            "Sabe que cambiar de menú por fecha (por ejemplo, pasar al menú de invierno) o de buffet estándar a premium lo hace el propio hotel.",
            "Alérgenos: explica cómo activar o desactivar los que se muestran, y sigue pasando a una persona cualquier duda sobre lo que lleva un plato.",
            "No da por hecho que el cliente es un hotel, no se presenta de más y, si el correo no consta, puede orientarse por el nombre del dominio para preguntar «¿se refiere a…?».",
            "Sabe explicar por qué una farmacia no puede mostrar dos productos en una misma etiqueta pequeña.",
        ],
    },
    {
        version: "1.6.1",
        date: "20 nov 2026",
        resumen: "El asistente de IA acierta más: conoce mejor la aplicación de hoteles y aprovecha las plantillas de respuesta del equipo.",
        mejoras: [
            "La IA tiene siempre a mano el mapa de la aplicación de hoteles, así que ya no dice que el equipo hace lo que el propio hotel puede hacer (servicios, idiomas, posiciones, usuarios…).",
            "Si una plantilla de respuesta del equipo encaja con lo que pide el cliente, la IA la usa como base en su sugerencia, adaptada al caso.",
            "Encuentra mejor la guía que toca (por ejemplo, la de Servicios o la de Idiomas) aunque el cliente no use esa palabra.",
            "Distingue mejor entre pedir un correo para un presupuesto (se le da el de postventa) y pedir que se lo preparemos (pasa a Pedidos y facturas).",
            "Los problemas de usuario o contraseña y las garantías se derivan a una persona sin hacer preguntas.",
            "Sabe explicar qué hacer cuando una farmacia deja de ver el stock en las etiquetas.",
            "Contesta también en catalán y valenciano, en la lengua que use el cliente.",
        ],
    },
    {
        version: "1.6.0",
        date: "18 nov 2026",
        resumen: "Llega el asistente de IA: propone una respuesta en cada ticket y la persona decide si la usa.",
        mejoras: [
            "En la ficha del ticket aparece una sugerencia de respuesta. La IA nunca envía nada: tú la revisas, la editas o la ignoras.",
            "Puedes valorarla con 👍 o 👎 y, si no te vale, escribir cómo debía ser. Así la IA aprende de lo que corriges.",
            "En Ajustes, el responsable enciende o apaga el asistente, fija el tope de gasto mensual, ve el historial de valoraciones y valida las correcciones.",
            "Antes de enviar datos a la IA se tapan contraseñas, IBAN, tarjetas y teléfonos.",
        ],
    },
    {
        version: "1.5.22",
        date: "13 nov 2026",
        resumen: "En los chats de WhatsApp se ve a qué mensaje responde cada respuesta citada.",
        mejoras: [
            "Cuando el cliente (o el móvil) responde citando un mensaje, encima aparece la cita con el nombre de quién lo escribió y un extracto, como en WhatsApp.",
            "Si el mensaje citado no está guardado en el helpdesk, se avisa en vez de dejar el texto sin contexto.",
        ],
    },
    {
        version: "1.5.21",
        date: "10 nov 2026",
        resumen: "Quien coge un ticket al liberarse ya no lo pierde, y vuelve la marca de «falta respuesta».",
        mejoras: [
            "En la lista de tickets, los que esperan nuestra respuesta llevan a la izquierda un degradado en el azul de Helpdesk; al pasar el ratón pone «Esperando respuesta».",
            "Los tickets internos vuelven a llevar su franja a la izquierda.",
        ],
        arreglos: [
            "Al terminar la cuenta atrás de un ticket ocupado, quien lo esperaba lo coge en el acto y ya no se lo quita el compañero anterior al mover el ratón.",
            "El ticket queda libre justo al llegar la cuenta a 0, no unos segundos después.",
        ],
    },
    {
        version: "1.5.20",
        date: "6 nov 2026",
        resumen: "Diseño renovado: Helpdesk y Campañas se ven iguales, cada una con su color.",
        mejoras: [
            "Un color por área: Helpdesk en azul y Campañas en verde. Lo demás se ve igual en las dos.",
            "Los indicadores de arriba tienen la misma forma en todas las pantallas; solo llevan color cuando avisan de algo.",
            "El menú lateral va en gris y marca en color la pantalla en la que estás. Plegado queda centrado y sin barra de scroll.",
            "Toda la app usa la tipografía de la casa, la misma del portal.",
            "Portal del cliente más limpio: títulos más claros, preguntas frecuentes sin adornos y mejor en el móvil.",
        ],
        arreglos: [],
    },
    {
        version: "1.5.19",
        date: "6 nov 2026",
        resumen: "Los WhatsApp que llegan tarde salen en su sitio de la conversación.",
        mejoras: [
            "Si un mensaje llega con retraso (el móvil del cliente estaba sin conexión), junto a su hora pone cuándo llegó.",
        ],
        arreglos: [
            "Un WhatsApp escrito sin conexión ya no sale al final con una hora anterior: va donde se escribió, como en el móvil.",
        ],
    },
    {
        version: "1.5.18",
        date: "5 nov 2026",
        resumen: "Un ticket abierto ya no se queda bloqueado si te vas o cierras la web.",
        mejoras: [
            "Cuenta atrás en el ticket: ves cuánto falta para que se libere si nadie lo toca.",
        ],
        arreglos: [
            "Si cierras la pestaña o el navegador con un ticket abierto, los demás pueden entrar al momento.",
            "Si lo dejas abierto sin tocar nada, se libera solo pasados los minutos configurados.",
        ],
    },
    {
        version: "1.5.17",
        date: "4 nov 2026",
        resumen: "Los correos muy largos ya entran y no se repite el aviso de ticket asignado.",
        mejoras: [],
        arreglos: [
            "Un correo muy largo (con todo el hilo de respuestas) no llegaba a convertirse en ticket. Ahora entra entero.",
            "Mientras fallaba, el agente recibía el aviso «Se te ha asignado» varias veces. Ahora solo llega uno, cuando el ticket ya está creado.",
        ],
    },
    {
        version: "1.5.16",
        date: "4 nov 2026",
        resumen: "Ya se puede enviar solo una foto o un archivo, sin escribir texto.",
        mejoras: [],
        arreglos: [
            "Al responder, con solo un adjunto el botón de enviar se quedaba apagado hasta escribir algo. Ahora se puede enviar la foto sola (WhatsApp y correo).",
        ],
    },
    {
        version: "1.5.15",
        date: "2 nov 2026",
        resumen: "Aviso antes de cerrar un ticket sin respuesta y más cuidado con los correos internos.",
        mejoras: [
            "El agente asignado recibe un aviso 24 horas antes de que un ticket se cierre por falta de respuesta del cliente.",
            "Si quien escribe es de @aemegroup.com, el ticket se resalta y se pide confirmación antes de responder.",
            "«Próximos envíos a clientes», en Tareas programadas, solo muestra lo que sale en los próximos 4 días.",
        ],
        arreglos: [
            "Seguridad: actualizadas las librerías de Excel (importar contactos) y de correo.",
        ],
    },
    {
        version: "1.5.14",
        date: "28 oct 2026",
        resumen: "Cada ficha de contacto sabe si es de Soporte, de Campañas o de las dos.",
        mejoras: [
            "Cada área ve y edita solo sus contactos; el correo y el teléfono de un cliente de soporte solo se cambian desde el Helpdesk.",
            "El Chat en vivo de Campañas ya no abre clientes que solo son de soporte.",
        ],
        arreglos: [
            "Borrar el último ticket de un cliente ya no lo pasa a la lista de Campañas.",
        ],
    },
    {
        version: "1.5.13",
        date: "28 oct 2026",
        resumen: "Más seguridad en las fichas de cliente y separación entre Campañas y Soporte.",
        mejoras: [
            "Al crear un ticket con un teléfono que ya es de otra ficha, se avisa en vez de unirlas: la unión se hace desde Contactos.",
            "Importar contactos ya no modifica a clientes con tickets de soporte (el resumen dice cuántos se han omitido).",
        ],
        arreglos: [
            "El formulario público ya no une fichas por el teléfono que se escriba.",
            "Los mensajes automáticos de Campañas ya no aparecen dentro de tickets de soporte.",
            "Convertir un chat de Campañas en ticket no cambia el correo de un cliente de soporte.",
            "Una campaña larga ya no puede enviarse dos veces al mismo destinatario.",
        ],
    },
    {
        version: "1.5.12",
        date: "28 oct 2026",
        resumen: "Próximos envíos automáticos a clientes, en Configuración y en la ficha del ticket.",
        mejoras: [
            "Configuración › Tareas programadas: «Próximos envíos a clientes» con lo que saldrá solo en los próximos 4 días (cierres por falta de respuesta, respuestas programadas…), separando las próximas 24 h.",
            "En la ficha del ticket, un aviso de cuándo se cerrará automáticamente y cómo evitarlo.",
        ],
        arreglos: [],
    },
    {
        version: "1.5.11",
        date: "28 oct 2026",
        resumen: "Los tickets sin respuesta del cliente se cierran directamente a los 8 días.",
        mejoras: [
            "Tickets de correo y web de Soporte: si el cliente no contesta en 8 días, recibe el aviso de cierre y el ticket pasa a Cerrado en ese momento (antes era un recordatorio y se resolvía 24 h después). Si luego contesta, se reabre solo.",
        ],
        arreglos: [],
    },
    {
        version: "1.5.10",
        date: "28 oct 2026",
        resumen: "Correo diario al turno con los tickets que llevan días sin contestar.",
        mejoras: [
            "Cada día laborable, quien está de turno recibe un correo con los tickets en los que el cliente lleva más de 4 días esperando respuesta, para contestarlos, resolverlos o pasarlos a Planificado. Ni fines de semana ni festivos, y si no hay ninguno no llega nada.",
            "Configuración › Comportamiento: activar el aviso, los días y la hora del envío.",
        ],
        arreglos: [],
    },
    {
        version: "1.5.9",
        date: "28 oct 2026",
        resumen: "Barra de consumo de WhatsApp en Tickets y tooltips propios en toda la web.",
        mejoras: [
            "Tickets: en la cabecera, una barra con los mensajes de WhatsApp enviados este mes frente a los 1.000 gratuitos de Meta. Va de verde a burdeos; al pasar el ratón dice cuántos van y cuántos quedan.",
            "Tooltips propios en toda la plataforma en lugar de los del navegador.",
        ],
        arreglos: [
            "Respuestas por correo: las direcciones en copia (Cc) ya se leen bien sobre la burbuja azul.",
        ],
    },
    {
        version: "1.5.8",
        date: "28 oct 2026",
        resumen: "WhatsApp: badge ámbar cuando han pasado 24 h y el nombre de quién contesta en cada respuesta.",
        mejoras: [
            "Tickets de WhatsApp: el badge del canal se pone ámbar cuando el cliente lleva más de 24 h sin escribir (ya no se le puede contestar desde aquí). El tooltip dice desde cuándo y vuelve a verde en cuanto el cliente escribe.",
        ],
        arreglos: [
            "Tickets de WhatsApp: el nombre del agente sale al principio de cada respuesta, no solo cuando cambia quién contesta.",
            "Chat en vivo (Campañas): lo que envías desde la web sale con tu nombre al momento, no como «Automático» hasta recargar.",
        ],
    },
    {
        version: "1.5.7",
        date: "27 oct 2026",
        resumen: "Notificaciones: activa o desactiva cada canal y elige la pausa con un deslizador.",
        mejoras: [
            "Notificaciones › Avisos por canal: un interruptor por canal (WhatsApp, correo, web) para recibir o no avisos de sus tickets nuevos y respuestas del cliente.",
            "Con el canal activo, un deslizador fija los minutos de pausa entre avisos del mismo ticket.",
        ],
        arreglos: [],
    },
    {
        version: "1.5.6",
        date: "27 oct 2026",
        resumen: "Pausa entre avisos del mismo ticket, por canal: tres mensajes seguidos por WhatsApp ya no son tres correos y tres pitidos.",
        mejoras: [
            "Notificaciones: cada agente elige, para WhatsApp, correo y web, cuántos minutos esperar antes de volver a avisarle del mismo ticket (correo y aviso del navegador).",
            "Configuración › Comportamiento: el administrador fija el rango que pueden elegir (por defecto de 1 a 30 minutos).",
        ],
        arreglos: [],
    },
    {
        version: "1.5.5",
        date: "27 oct 2026",
        resumen: "Chat en vivo: candado de 24 h como en Helpdesk y el reproductor de audio de soporte.",
        mejoras: [
            "Chat en vivo (Campañas): las notas de voz usan el mismo reproductor que los tickets de soporte.",
        ],
        arreglos: [
            "Chat en vivo: con la ventana de 24 h cerrada ya no se puede escribir texto, adjuntar ni mandar botones o formularios (WhatsApp los daba por fallidos). Solo plantillas, o escribir desde el móvil.",
        ],
    },
    {
        version: "1.5.4",
        date: "27 oct 2026",
        resumen: "En el Chat en vivo se ve de un vistazo si un mensaje salió del móvil, de la web o del bot.",
        mejoras: [
            "Chat en vivo (Campañas): cada mensaje nuestro dice de dónde salió: «Desde el móvil» (burbuja ámbar), «Nombre · web» o «Automático» (bot y campañas).",
        ],
        arreglos: [],
    },
    {
        version: "1.5.3",
        date: "27 oct 2026",
        resumen: "El Chat en vivo de Campañas muestra también lo que contestas desde el móvil.",
        mejoras: [
            "Chat en vivo (Campañas): los mensajes que envías desde la app de WhatsApp Business del móvil aparecen en la conversación, marcados «Desde el móvil».",
        ],
        arreglos: [],
    },
    {
        version: "1.5.2",
        date: "27 oct 2026",
        resumen: "Unir fichas de un mismo cliente es más claro y seguro.",
        mejoras: [
            "Posibles duplicados: cada ficha muestra sus últimos tickets (con enlace) para comprobar que es la misma persona antes de unirlas.",
            "«Unir fichas» enseña cómo queda la ficha: con su teléfono y su correo, y todos los tickets juntos. Solo pregunta cuál se queda si los datos chocan (dos nombres, dos correos o dos teléfonos distintos).",
        ],
        arreglos: [],
    },
    {
        version: "1.5.1",
        date: "27 oct 2026",
        resumen: "WhatsApp: reparto como correo y web, nombre del cliente desde el ticket y fotos con texto bien. El seguimiento automático, solo en Soporte.",
        mejoras: [
            "WhatsApp: si el cliente escribe en un ticket que no tiene responsable (por ejemplo, los del historial importado), se asigna en ese momento al agente de guardia, igual que un ticket nuevo.",
            "WhatsApp: el lápiz junto al cliente ahora sirve para ponerle nombre (y, si quieres, correo) en vez de «Cambiar solicitante».",
            "El recordatorio a los 8 días y el cierre 24 h después solo se aplican a tickets de la categoría Soporte.",
        ],
        arreglos: [
            "WhatsApp: el texto de una foto salía al lado de la imagen, apretado contra el borde; ahora va debajo, como en el móvil.",
        ],
    },
    {
        version: "1.5.0",
        date: "27 oct 2026",
        resumen: "Los WhatsApp de soporte llegan como tickets y los avisos aparecen al instante.",
        mejoras: [
            "Los mensajes al WhatsApp de soporte crean tickets; lo que se contesta desde el móvil sale como «Desde el móvil».",
            "Se puede responder por WhatsApp desde el ticket (dentro de las 24 h; después, solo desde el móvil).",
            "Las fotos, audios y documentos de WhatsApp se guardan 6 meses.",
            "Avisos al instante: un ticket nuevo o la respuesta de un cliente aparecen sin esperar.",
            "Nuevo filtro por canal en Tickets (correo, WhatsApp, web).",
            "Seguimiento automático: si el cliente no contesta en unos días, se le recuerda y luego se resuelve (configurable).",
        ],
        arreglos: [
            "Las conversaciones anteriores de WhatsApp solo muestran las que el agente puede ver.",
        ],
    },
    {
        version: "1.4.3",
        date: "26 oct 2026",
        resumen: "Botón «Salir» en el portal y cerrar sesión en todos los dispositivos.",
        mejoras: [
            "Portal de clientes: botón «Salir» que cierra la sesión y borra los datos del cliente de ese navegador.",
            "Cuenta: «Cerrar sesión en todos los dispositivos», por si se pierde un móvil o se entra en un ordenador ajeno.",
        ],
        arreglos: [
            "La pista de la contraseña nueva decía 6 caracteres; son 8.",
        ],
    },
    {
        version: "1.4.2",
        date: "26 oct 2026",
        resumen: "Elegir a quién enviar una campaña es mucho más fácil, y registro de bajas para el RGPD.",
        mejoras: [
            "Nuevo «¿A quién se lo envías?» con tarjetas: por segmento (interés + tipo), etiquetas, agenda, provincia o todos.",
            "Audiencias guardadas que se actualizan solas, p. ej. «Interés alto + No cliente».",
            "Se ve al momento cuántos contactos recibirán la campaña.",
            "Chat en vivo de Campañas con filtros: interés, tipo, etiquetas, provincia, ventana de 24 h, sin clasificar y sin bajas.",
            "Registro de consentimientos y bajas descargable (RGPD), solo en Campañas.",
        ],
        arreglos: [
            "La baja desde el enlace del correo ahora guarda también la fecha.",
        ],
    },
    {
        version: "1.4.1",
        date: "26 oct 2026",
        resumen: "Campañas en tramos de días y sin envíos en fin de semana ni festivos.",
        mejoras: [
            "Campañas en tramos: enviar a X contactos al día durante varios días, de lunes a viernes.",
            "Las campañas no salen en fin de semana ni en festivo (se puede desactivar en «Seguridad de envíos»).",
        ],
        arreglos: [
            "Al llegar al tope diario, la campaña ya no sigue a medianoche: continúa al día siguiente a su hora.",
        ],
    },
    {
        version: "1.4.0",
        date: "26 oct 2026",
        resumen: "Revisión de seguridad completa, Campañas más ordenado, nuevas etiquetas y un cliente = una ficha.",
        mejoras: [
            "Etiquetas de Campañas para clasificar: interés (alto, medio, bajo) y tipo (cliente, no cliente, excliente), excluyentes.",
            "Contactos de Campañas muestra solo contactos de Campañas, no los clientes de soporte.",
            "Cada usuario puede marcarse como «no recibe tickets asignados».",
            "Teléfonos siempre con prefijo y aviso de «posibles duplicados» en Contactos para unir fichas de la misma persona.",
            "Revisión de seguridad: permisos más estrictos entre Helpdesk y Campañas, y las cuentas deshabilitadas ya no pueden entrar.",
        ],
        arreglos: [
            "Varios fallos de seguridad corregidos (adjuntos, contraseñas de buzones, enlaces de descarga, portal).",
            "Los interruptores de la ficha de usuario ya se ven en verde cuando están activados.",
            "Crear un ticket con un teléfono que ya tenía otro contacto ya no da error: se une a ese cliente.",
        ],
    },
    {
        version: "1.1.3",
        date: "8 oct 2026",
        resumen: "Puedes iniciar un chat de WhatsApp con un número que aún no es contacto.",
        mejoras: [
            "Nuevo botón «＋ Nueva conversación» en el Chat en vivo: escribes el número y abres el hilo.",
            "Si el número no te ha escrito en 24 h, la conversación arranca con una plantilla (como manda WhatsApp).",
        ],
        arreglos: [],
    },
    {
        version: "1.1.2",
        date: "1 oct 2026",
        resumen: "Las prioridades ahora explican cuándo usar cada una.",
        mejoras: [
            "Cada prioridad lleva una descripción corta que se ve en el selector (p. ej. «Urgente — servicio caído o impacto grave»).",
            "Las descripciones se editan en Configuración → Prioridades.",
        ],
        arreglos: [],
    },
    {
        version: "1.1.1",
        date: "1 oct 2026",
        resumen: "Estados con colores nuevos y un detalle menos en los tickets cerrados.",
        mejoras: [
            "Nuevos colores de estado: Nuevo rosa, Abierto púrpura, Planificado cian, Resuelto verde, Cerrado azul.",
        ],
        arreglos: [
            "Un ticket cerrado ya no se marca como «sin responder».",
        ],
    },
    {
        version: "1.1.0",
        date: "29 sep 2026",
        resumen: "Nuevos estados, reglas de trabajo, avisos a tu gusto y una bandeja más cómoda.",
        mejoras: [
            "Estados más claros, con «Planificado» para lo que va a días o semanas.",
            "Cada agente responde solo sus tickets; los libres se cogen al contestar.",
            "Al dejar una nota, puedes cerrar o resolver el ticket de paso.",
            "Enlace para compartir un ticket y abrirlo directo.",
            "Elige qué avisos por correo quieres; el nº de avisos sale en la pestaña y suena una campana.",
            "Filtros de la bandeja más potentes (varias categorías a la vez) y más limpios.",
            "En la ficha se ve el asunto y el teléfono del cliente; también puede dejarlo desde el portal.",
        ],
        arreglos: [
            "El ticket se libera para el resto en cuanto lo cierras.",
            "Las fotos grandes del correo ya llegan a la incidencia.",
            "Los turnos distinguen mejor los días pasados de los futuros.",
        ],
    },
    {
        version: "1.0.1",
        date: "11 sep 2026",
        resumen: "Mejoras en la bandeja y en las notificaciones.",
        mejoras: [
            "La ⭐ de respuestas efectivas funciona como interruptor.",
            "Aviso «te han mencionado» en la bandeja.",
            "Los avisos del navegador abren el ticket directamente.",
            "Los agentes ya pueden editar contactos.",
        ],
        arreglos: [
            "Notificaciones que no abrían el ticket.",
            "Orden por última actividad en el histórico.",
            "Nombres de contacto que salían en código raro.",
        ],
    },
];
