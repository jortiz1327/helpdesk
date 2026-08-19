# Base de conocimiento de soporte · AEME Group (etiquetas electrónicas)

Guía de temas frecuentes para el agente de soporte. Cada entrada es un problema típico
del cliente con la forma de resolverlo o el paso a dar. Destilada del histórico real
(osTicket + WhatsApp), sin datos personales, sin precios y sin casos concretos.

## Reglas de oro (líneas rojas — nunca las cruces)
- **Nunca des precios, tarifas, importes ni presupuestos.** Para material o presupuestos,
  deriva a **postventa@aemegroup.com**.
- **Material, recambios, pilas o pedidos** → no los tramita el agente: se piden por correo a
  **postventa@aemegroup.com** indicando el material, la cantidad y una foto de referencia.
- **Garantías** → las gestiona el **Departamento de Garantías** (una persona), no el agente ni
  un correo automático. Ante duda de garantía, escala.
- **Antena / repetidor / punto de acceso (AP)** → el cliente **no la reinstala, reinicia ni
  sustituye**. Pero SÍ debe **comprobar la LUZ (LED)** de la antena y su alimentación/red antes de
  escalar (ver sección «Repetidores / Antenas»). Fallo confirmado → **escalar a soporte técnico**.
- **Nunca uses datos de otros clientes** (otros hoteles, nombres, correos). Habla solo del
  cliente que tienes delante.
- Incidencias **técnicas** de soporte → **soporte@etiquetaselectronicas.com**.

## Cómo atender
- Identifica primero **de qué hotel/sede** escriben si no lo sabes.
- Ve **paso a paso**, una pregunta esencial cada vez; no sueltes un cuestionario.
- Si no entiendes bien la incidencia o no sabes resolverla, **no inventes**: pide el dato clave
  (modelo, foto con el código de barras) o **escala a un técnico**.
- Muchos cambios el cliente los puede hacer desde la **app / panel web** con su usuario.

---

## Etiquetas electrónicas

### Una etiqueta no cambia el nombre / no se actualiza
Revincular la etiqueta y **forzar** el cambio desde el sistema. Si sigue igual, pedir al cliente
una **foto de la etiqueta** donde se vea el **código de barras**, para localizarla. Comprobar
también que la etiqueta tenga batería suficiente.
> Caso especial FARMACIA: no va por etiqueta suelta, sino por **integración/sincronización**
> (import). Si es farmacia, es otro flujo (ver «Sincronización / Cron» y «Integraciones»).

### Varias / todas las etiquetas se comportan mal o «se cambian solas»
Fallo generalizado → **no es autoservicio**: escalar a un técnico de soporte para revisión. Antes
de escalar, confirmar hotel/sede y qué servicio (desayuno, comida…) están viendo mal.

### Sustituir una etiqueta rota por una nueva
No hay reemplazo directo desde el cliente: se **intercambian posiciones** o se **edita** la
etiqueta existente para asignarle la posición de la rota. El material/recambio se pide a
postventa@aemegroup.com.

### La etiqueta aparece del revés (girarla / invertirla)
Se **invierte desde el sistema** (lo hace soporte). El cliente no la manipula.

### Intercambiar la posición de dos etiquetas
Se realiza desde el sistema. Pedir al cliente **las dos posiciones** a intercambiar
(formato «código – posición»).

### Cambiar el horario de conexión de las etiquetas
Se **activa el horario** solicitado. Además, el cliente puede **editarlo él mismo desde la app**.

### Etiquetas de 7,5"
El cambio/gestión de las etiquetas de **7,5"** lo realiza **AEME** (no el cliente).

### Etiqueta bloqueada o «pillada»
Suele resolverse **refrescando/forzando** la etiqueta. Si tras forzar sigue igual, revisar batería
y, si persiste, escalar.

### Pilas: compatibilidad y cambio
- Referencia interna **AM-BZ2450** (equivalente **CR2450**).
- Marcas **recomendadas**: **Beidongli, HenliMax, NSN** (son más **finas** y hacen buen contacto con
  los pines de la etiqueta).
- **Duracell y Varta** pueden dar **problemas de grosor** (no contactan bien con los pines) → evitarlas.
- Si el cliente cambió las pilas y la etiqueta no se actualiza: **confirmar el modelo de pila**
  usado y pedir **foto**. Recordar que un cambio de pilas mal hecho puede afectar a la garantía.
- Para **comprar pilas**: postventa@aemegroup.com (nunca dar precio).

### Síntomas de batería baja (pantalla difuminada, rojiza o con líneas rojas)
Si la pantalla se ve **difuminada**, el texto/fondo apenas se distingue, o tiene **fondo rojizo o
líneas rojas**, casi seguro es **problema de batería** → recomendar **reemplazar la pila**. Tras
cambiarla, si no se muestra bien, hacer un **cambio momentáneo en esa posición desde la app** (fuerza
la actualización y confirma que funciona). La etiqueta debe estar **lo más cerca posible de la
antena** para que el cambio tenga éxito.

### Idiomas de una etiqueta
No asumir cuántos ni cuáles: **preguntar qué idiomas/traducciones** tiene configurados. Los
cambios de idioma se reflejan en el **próximo servicio**.

### Carteles / etiquetas nuevas
Al crear una etiqueta/posición nueva, mostrará el **logo** hasta que se **complete el menú/plantilla**
de esa posición.

---

## Displays / Pantallas (cartelería digital)

### El display / pantalla no se actualiza
Pasos: 1) Comprobar que tenga **corriente / batería**. 2) Verificar la **conexión de red** y que el
**repetidor/AP** esté en línea. 3) **Forzar la actualización** desde el panel. 4) Si la pantalla está
**en negro**, revisar el **cable de alimentación**. 5) Si persiste, **escalar** para revisión in situ.

### La cartelería se ve ilegible o en negro
Revisar alimentación y conexión; forzar actualización. Si sigue ilegible, escalar (puede requerir
revisión del equipo o de la plantilla asignada).

### Modificar los carteles / cartelería del buffet
Desde el **panel web de administración**: sección de **carteles/displays** del hotel → seleccionar el
cartel → **editar** contenido (texto, imágenes, plantilla) → **guardar** → **forzar actualización**.

### Invertir / reordenar los carteles digitales
En el panel web, sección de carteles/displays, modificar el **orden/posición** asignada, guardar y
forzar la actualización.

### Contenido dinámico / animaciones en los carteles
Depende del modelo de display y la configuración. Si lo piden, confirmar el modelo y **consultar a
soporte** las opciones disponibles (no prometer nada por defecto).

---

## Repetidores / Antenas / Puntos de acceso (AP)

### Antena / repetidor desconectado, offline o no obedece al forzado
El cliente **no manipula, reinicia ni sustituye** la antena. Pero SÍ debe **comprobar el estado por
la LUZ (LED)**, que es la mejor pista. La antena **solo emite luz VERDE**; su significado:
- **Luz apagada / ausente** → NO recibe electricidad (revisar alimentación/enchufe en su lado).
- **Luz verde FIJA** → sin conexión a internet (revisar su red).
- **Luz verde INTERMITENTE rápida** → funcionamiento **correcto** (la antena está bien; el problema
  es otro: mirar etiquetas/sincronización).

**Quién la revisa**, según el tipo de cliente:
- **Hoteles** → lo revisa el **personal de mantenimiento / técnicos del hotel** (no de primeras
  soporte AEME). Pídeles que miren la luz y digan en qué estado está.
- **Tiendas y farmacias** → normalmente **no tienen equipo técnico/mantenimiento**, así que el
  propio personal del local debe **verificar las luces** siguiendo la guía de arriba.

Según lo que reporten: apagada → problema eléctrico de su lado; verde fija → sin internet (su red);
verde intermitente → la antena va bien, el fallo es otro. Si tras comprobar la luz sigue el
problema o se sospecha antena averiada, **escalar a soporte técnico** (revisión in situ / garantía).

### La antena no conecta: requisitos de red (para el IT/mantenimiento del cliente)
Para que la antena funcione, la red donde se conecta debe permitir:
- **Resolución DNS** activa (la antena resuelve su dominio configurado).
- **Puerto MQTT (TCP 1883)** de salida hacia nuestro servidor:
  dominio **app02.etiquetaselectronicas.com** (IP **46.226.45.117**).
- **Ping (ICMP)** de salida desde la red de la antena hacia nuestro servidor (para que tanto su
  equipo como nuestros técnicos verifiquen la conectividad).
Esto lo aplica el **IT/mantenimiento** del hotel/local en su firewall/red. Encaja con la luz LED:
si la antena está **verde fija** (sin internet), revisar estos tres puntos.

### Ver las antenas/AP de varios hoteles en el panel
En el panel se puede **cambiar de un hotel a otro** con un par de clics para consultar sus AP. Si
un usuario no ve las antenas de una sede, revisar sus **permisos/acceso** a esa sede.

---

## Configuración de menús (buffet)

### Cambiar un plato o el menú
El cliente lo hace desde la **app / panel web** con un usuario con **permisos de cambio de platos**.
Si no tiene ese usuario, se le crea (pedir su **correo** para darle de alta con permisos).

### Importar platos desde Excel
Los platos se cargan desde el **Excel** que aporta el cliente; solo se importan los que estén bien
en el fichero. Si son pocos, puede **añadirlos a mano** (hay **vídeo tutorial** en el apartado de
platos para crearlos con todas sus características).

### Forzar el cambio de menú
Se puede **forzar el menú** desde el sistema. Importante: si el cliente cambia platos uno a uno
**antes de la hora** que le toca forzar, la etiqueta seguirá mostrando el servicio anterior (p. ej.
el desayuno). Cada servicio tiene su **hora**.

### Menús que cambian solos por horario (verano/temporada, días de la semana)
Se pueden crear menús **programados** (p. ej. «LUNES VERANO») que se **cambian solos a la hora**
que les toca, sin tener que cambiarlos a mano ni perder los actuales. Pedir al cliente los menús y
los horarios que quiere.

### Horario de un servicio (desayuno / comida / cena)
Se puede **modificar el horario** de cada servicio (p. ej. desayuno 8:00–10:30). Pedir el servicio,
los días y el horario exacto.

### Alérgenos / distintivos / iconos (cerdito, «sin cerdo», etc.)
Los **distintivos** (iconos de alérgenos, «contiene/no contiene», etc.) se asignan a los platos y se
muestran en la etiqueta. Si falta un icono nuevo, hay que **crearlo/solicitar el archivo del icono**
y luego asignarlo al distintivo. Es un tema sensible (seguridad alimentaria): tratarlo con prioridad.

### Clonar un servicio
Desde el panel, seleccionar el servicio y usar **clonar**. Si no deja, comprobar que el servicio no
esté **en uso activo** en ese momento y que el usuario tenga **permisos de administrador**.

### Un día/servicio «ha desaparecido» del menú en la app
Revisar la configuración del servicio de ese día; puede ser un problema de plantilla o de que el
menú de ese día no está creado/asignado. Confirmar qué día y servicio y qué debería mostrar.

### Mayúsculas/minúsculas y formato de los platos
El formato de texto de los platos depende de la configuración de la plantilla; si el cliente necesita
un formato concreto (minúsculas, dos líneas…), se ajusta desde la plantilla o se traslada la mejora.

### Desperdicio alimentario (módulo)
Módulo del panel web para **registrar y seguir** los desperdicios (tipo de alimento, cantidad,
motivo) y consultarlo en informes. Se accede desde el panel.

---

## Usuarios y permisos

### Altas de usuario: las hace el PROPIO cliente (su administrador)
El cliente crea sus usuarios en la **app nueva**; AEME **no** los crea de primeras. Debe encargarse
un usuario del cliente con **rol de administrador / permisos** (suele ser **dirección, F&B u otro
encargado**). Si quien pide el alta no tiene permisos, indicarle que lo gestione **su administrador
interno** (no lo hace AEME).
- AEME solo crea el **primer usuario administrador** (con permisos) para que el cliente pueda
  gestionar el resto por su cuenta.
- Por defecto, los usuarios nuevos quedan con un **rol BÁSICO** (para evitar problemas); es el
  administrador quien les asigna los roles.

### Cómo crear un usuario en la app (guía para el administrador del cliente)
1) Pulsar **«crear usuario»** y rellenar los campos.
2) Abajo, en los **checks**, marcar **el hotel/sede** al que pertenece el usuario.
3) **Apuntar bien el correo y la contraseña** que se pongan (para no tener problemas al iniciar
   sesión). La contraseña se puede **cambiar en cualquier momento**.

### Inicio de sesión en la app nueva (cambio importante)
En la app nueva se inicia sesión con **correo electrónico**. Los usuarios que venían de la app
antigua entran con sus **credenciales antiguas**, pero en el **primer inicio de sesión** les pedirá
**indicar un correo electrónico**.

### No veo las antenas / etiquetas de una de mis sedes
Suele ser un tema de **acceso/permisos** a esa sede. Revisar que el usuario tenga permiso sobre el
hotel/sede en cuestión.

---

## Hardware, garantía y material

### Pedir material, recambios o soportes (metacrilato, etc.)
Se solicita por correo a **postventa@aemegroup.com** con el **material**, la **cantidad** y una
**foto** de referencia. **Nunca dar precio**; postventa gestiona el presupuesto.

### Protocolo de garantía de una etiqueta (pasos PREVIOS, en orden)
Antes de tramitar garantía o enviar material, hay pruebas obligatorias. Guía al cliente por estos
pasos **en orden** (uno cada vez, no todos de golpe):

**Paso 1 — Descartar batería con un REINICIO de la etiqueta** (siempre **dentro del rango de la antena**):
1) Retirar la tapa trasera (se puede usar un destornillador plano como leve palanca).
2) Extraer las pilas.
3) Esperar **15 minutos**.
4) Volver a poner las pilas (**parte plana hacia el cliente**).
5) Colocar la tapa.
6) Entrar en la app de gestión y **hacer un cambio en la posición** correspondiente.

**Paso 2 — Si sigue sin responder, revisar las pilas:**
- Probar con las pilas de **otra etiqueta que funcione**.
- **Duracell o Varta** pueden dar problemas de **grosor**. Recomendadas **Beidongli, HenliMax, NSN**
  (más finas, buen contacto con los pines).

**Paso 3 — Si persiste, pedir fotos para revisión:**
- Parte **delantera** con el **código de barras** del armazón bien visible.
- Parte **trasera, sin tapa y CON pilas**.
- Parte **trasera, sin tapa y SIN pilas**.

**Paso 4 — Derivar a Garantías:**
Si tras lo anterior sigue mal, se **deriva al Departamento de Garantías**, que lo gestiona. Respuesta
por correo en **24–48 h**. Para esto **se necesita el correo del cliente**.

### Procedimiento cuando YA es un caso de garantía (envío físico)
Lo gestiona el Departamento de Garantías. Datos del protocolo:
- **Dirección de envío**: AEME Group, Calle Gutiérrez Mellado, 2, 46250 L'Alcúdia, Valencia.
- **Contacto**: 962 012 074 / 608 923 001.
- **Aviso de envío**: al enviarlo, notificar con el **número de seguimiento** (si lo hay) y el **nº
  total de etiquetas**.
- **Gastos de envío**: a **cargo del cliente**.
- **Revisión**: al recibirlas se hace verificación técnica y se emite un **informe** con la resolución
  y los **presupuestos aplicables** (reparación o sustitución). El **retorno** queda condicionado a
  recibir el **justificante de pago** del presupuesto aceptado. *(El agente NUNCA da importes: los
  presupuestos los emite Garantías.)*
- **Cobertura**: garantía de **2 años** desde la entrega. Si una unidad **no es reacondicionable**:
  sustituir por otra que ya tengan, o adquirir una nueva.
- **Excluidos**: unidades con **golpes, humedad o maltrato físico** → se **rechazan** (ver abajo).

### Maltrato físico (qué invalida la garantía)
Se considera maltrato físico cualquier **daño visible** que afecte a la integridad del dispositivo:
golpes, fisuras, roturas de carcasa o **pines dañados**. Aunque ocurra al cambiar pilas o retirar el
dispositivo del expositor, compromete el funcionamiento interno e **impide una revisión fiable**.
Pedir al cliente que **no envíe** unidades con estos daños (serán rechazadas).

---

## Sincronización / Cron / Integraciones (farmacias y tiendas)

Estos clientes **no** van por menús de buffet, sino por **integración/sincronización automática**.

### Farmacias: no se actualizan los precios
Es lo más común en farmacias. La sincronización de precios va por integración; si no actualiza, es
**muy habitual entrar por AnyDesk** (conexión remota) para revisarlo. Escalar a soporte técnico.

### Tiendas: no se actualizan los productos
Una tienda puede recibir los productos de **varias formas**, según cómo se instaló:
- **Conector por FTP** (nos envían un fichero de productos).
- **Conexión por API**.
- **Conexión a una base de datos externa**.
- **Otras integraciones** a medida.
- O **sin integración**: la tienda gestiona los productos **a mano en nuestra app** (crear / editar /
  borrar productos — CRUD).

Si no se actualizan: primero identificar **cómo está montada** esa tienda. Si es por
**conector/API/BBDD**, el problema suele estar en esa integración → **escalar a soporte técnico** para
revisarla. Si es **por app (CRUD manual)**, comprobar que hayan **guardado/forzado** los cambios. El
AnyDesk es **menos habitual** en tiendas que en farmacias.

### Hoteles y conexión remota
A los **hoteles NO solemos conectarnos** en remoto, salvo para **configurar los AP durante la
instalación**. Su día a día (menús, etiquetas) lo llevan desde la app.

### Log de ejecución automática (Cron Job) con errores
El log del **cron** indica el estado de la sincronización programada. Si hay errores, es un tema
técnico: **escalar a soporte** para revisar la configuración del cron/integración.

---

## Cuándo escalar a un técnico (resumen)
- Antena/repetidor/AP con cualquier fallo.
- Fallos **generalizados** (todas las etiquetas/carteles a la vez).
- Errores de **sincronización/cron/integración** (farmacias, tiendas).
- Sospecha de **hardware roto** o **garantía**.
- Cualquier caso que **no entiendas con claridad** o para el que **no tengas solución**: mejor
  escalar que inventar.
