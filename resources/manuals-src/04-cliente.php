<?php
/*
 * Generador de «Guía del portal de soporte» (resources/manuals/04-cliente.pdf).
 *
 * Uso (desde la raíz del proyecto):   php resources/manuals-src/04-cliente.php
 *
 * Se vuelve a ejecutar cada vez que cambia el portal público (frontend/src/portal). El texto que ve el
 * cliente está en frontend/src/portal/i18n.js: la guía debe decir lo mismo que el portal.
 * Después de regenerar: «Actualizar conocimiento» y «Revisado hoy» en Ajustes › Asistente IA.
 */

require __DIR__ . '/../../vendor/autoload.php';

const VERSION = 'v1.1';
const FECHA = '24/11/2026';

$secciones = [
    ['1', 'Qué es el portal de soporte', 'Tu ventanilla para pedir ayuda a AEME, cuando quieras.'],
    ['2', 'Entrar (sin contraseña)', 'Con tu correo y un código. Sin contraseñas que recordar.'],
    ['3', 'Busca primero tu respuesta', 'Las preguntas frecuentes te resuelven muchas dudas al instante.'],
    ['4', 'Abrir una incidencia', 'Cuando necesitas ayuda de verdad: cómo contarla bien.'],
    ['5', 'Seguir y responder', 'Ver cómo va, contestar, adjuntar y descargar tu incidencia.'],
    ['6', 'Valorar la atención', 'Dinos qué tal te hemos atendido con unas estrellas.'],
    ['7', 'Preguntas frecuentes', 'Las dudas más habituales sobre el portal.'],
];

$e = fn (string $s) => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');

$indice = '';
foreach ($secciones as [$n, $t, $d]) {
    $indice .= '<div class="idx"><span class="num">' . $n . '</span><div class="idxt"><b>' . $e($t) . '</b><br><span class="muted">' . $e($d) . '</span></div></div>';
}

$cuerpo = <<<'HTML'
<h1 id="s1"><span class="n">1</span> Qué es el portal de soporte</h1>
<p>El portal de soporte es tu ventanilla para pedir ayuda a AEME. Desde aquí abres tus incidencias, ves cómo van y hablas con nuestro equipo, cuando quieras y desde donde quieras.</p>
<h2>Dónde está</h2>
<p>Se abre en el navegador, en la dirección de la web de soporte que te ha facilitado AEME. No hay que instalar nada. Está disponible en <b>español, inglés y portugués</b>: elige el idioma en el selector de la parte superior.</p>
<h2>Cuándo te atendemos</h2>
<p>Nuestro equipo atiende <b>de lunes a viernes, de 7:00 a 21:00</b>. En la cabecera del portal ves si ahora mismo estamos atendiendo o si estamos fuera de horario. Puedes escribirnos a cualquier hora: te leeremos en cuanto abramos y te contestaremos por correo.</p>
<div class="ej"><div class="et">Ejemplo</div>En vez de llamar por teléfono o mandar un correo y quedarte sin saber si ha llegado, abres una incidencia en el portal y ves en todo momento en qué punto está y qué te responde el equipo.</div>

<h1 id="s2" class="pb"><span class="n">2</span> Entrar (sin contraseña)</h1>
<p>No hay ninguna contraseña que recordar. Entras con tu correo y un código que te enviamos en el momento.</p>
<h2>Los tres pasos</h2>
<ol>
<li>Escribe tu correo electrónico.</li>
<li>Mira tu bandeja de entrada: te llega un código de <b>6 dígitos</b>.</li>
<li>Escribe ese código en el portal. ¡Ya estás dentro!</li>
</ol>
<div class="tip"><b>Consejo:</b> usa el mismo correo con el que trabajas habitualmente con AEME. Así verás todas tus incidencias reunidas.</div>
<h2>Si el código no llega</h2>
<p>Mira también en la carpeta de <b>spam</b> o «correo no deseado». El código <b>caduca a los 10 minutos</b>. Si no te llega o ha caducado, pide uno nuevo desde el propio portal (tendrás que esperar unos segundos entre un envío y otro).</p>
<h2>Salir</h2>
<p>Cuando termines, pulsa <b>Salir</b> en la parte superior: cierras tu sesión en ese dispositivo y la próxima vez te pediremos un código. Es útil si usas un ordenador que no es tuyo.</p>
<div class="ej"><div class="et">Ejemplo</div>Pones «tienda@micomercio.com», te llega un correo con un código de varios dígitos, lo escribes y entras. La próxima vez, el mismo proceso: es rápido y seguro.</div>

<h1 id="s3" class="pb"><span class="n">3</span> Busca primero tu respuesta</h1>
<p>Muchas dudas ya tienen respuesta. Antes de abrir una incidencia, echa un vistazo: puede que lo resuelvas en un minuto.</p>
<h2>Preguntas frecuentes y buscador</h2>
<p>En la página de inicio hay un buscador y una lista de «Dudas frecuentes». Escribe unas palabras sobre tu duda, lee la respuesta y, si quieres, dinos si te ha servido (Sí / No). Con eso mejoramos las respuestas para todos.</p>
<h2>¿No lo resuelve?</h2>
<p>Si la respuesta no te vale, hay un botón <b>«No me sirve, abrir incidencia»</b> que te lleva al formulario con el asunto y el tema ya rellenados, para no empezar de cero.</p>
<div class="ej"><div class="et">Ejemplo</div>Buscas «las etiquetas no cargan» y te sale una respuesta con los pasos para revisarlo. Si aun así sigue sin funcionar, pulsas «No me sirve, abrir incidencia» y pasas al equipo con un clic.</div>

<h1 id="s4" class="pb"><span class="n">4</span> Abrir una incidencia</h1>
<p>Cuando necesitas ayuda de verdad, abres una incidencia con <b>Crear una incidencia</b> (también puedes ir directamente a la dirección del portal terminada en <b>/create-ticket</b>). Sin registros ni contraseñas. Cuanto mejor la cuentes, antes te ayudamos.</p>
<h2>Qué te pedimos</h2>
<ul>
<li><b>Correo:</b> es donde te avisamos cuando un técnico responde.</li>
<li><b>Teléfono (opcional):</b> para poder llamarte si hiciera falta.</li>
<li><b>Asunto:</b> un resumen corto de lo que pasa.</li>
<li><b>Categoría:</b> a qué se refiere (facturación, un equipo, una etiqueta…).</li>
<li><b>Descripción:</b> cuéntanos qué ocurre, desde cuándo y qué has probado ya.</li>
<li><b>Adjuntos (opcional):</b> una foto o una captura ayudan muchísimo. Puedes arrastrarlos o elegirlos: imágenes, PDF o documentos, de hasta 10 MB cada uno.</li>
</ul>
<h2>Después de enviarla</h2>
<p>Verás tu <b>número de incidencia</b>. Guárdalo (puedes copiarlo con un botón): con él puedes consultar cómo va, y te avisamos por correo en cuanto un técnico responda.</p>
<h2>Si ya tienes incidencias abiertas</h2>
<p>El portal te avisa cuando ya hay incidencias abiertas con tu correo, por si es lo mismo: puedes responder en esa en vez de abrir otra. Y si tu correo ya está registrado, te pedimos entrar con tu código antes de abrir una nueva: es tu protección, así nadie abre incidencias en tu nombre.</p>
<div class="ej"><div class="et">Ejemplo</div>Asunto: «La etiqueta no cambia de precio». Categoría: «Etiquetas». Descripción: «Desde ayer, la etiqueta del pasillo 3 se quedó con el precio antiguo; el resto van bien». Y adjuntas una foto de la etiqueta. Con eso, el equipo va directo al problema.</div>

<h1 id="s5" class="pb"><span class="n">5</span> Seguir y responder</h1>
<p>Una vez abierta, tu incidencia no se pierde: la tienes siempre a la vista y puedes seguir la conversación con el equipo.</p>
<h2>Tu lista de incidencias</h2>
<p>En <b>Ver mis incidencias</b> están todas, separadas en <b>Abiertas</b> y <b>Resueltas</b> (también puedes filtrar). Cada una muestra en qué fase está:</p>
<ul>
<li><b>Recibida:</b> la hemos recibido y la revisaremos en breve.</li>
<li><b>En proceso:</b> nuestro equipo está trabajando en ella.</li>
<li><b>Resuelta:</b> se ha dado por resuelta. Si vuelve el problema, respóndenos.</li>
</ul>
<p>Si el equipo te ha contestado, lo verás marcado como <b>«Respuesta nueva»</b>. Entra en una incidencia para ver toda la conversación.</p>
<h2>Qué puedes hacer dentro</h2>
<ul>
<li>Responder al equipo y adjuntar más fotos o archivos.</li>
<li>Descargar un PDF con toda la conversación, por si quieres guardarla.</li>
<li>Marcarla como resuelta tú mismo con <b>«Ya está resuelto para mí»</b> cuando ya esté solucionado.</li>
</ul>
<div class="tip"><b>¿Vuelve el problema?</b> No pasa nada: responde a la incidencia y se reabre sola. No tienes que empezar una nueva.</div>
<h2>Consultar el estado sin entrar</h2>
<p>Si solo quieres saber cómo va, pulsa <b>«Consulta el estado»</b> en la página de inicio y escribe tu <b>número de incidencia</b>: te decimos en qué fase está, sin correo ni código. Por seguridad, ahí <b>solo se ve el estado</b>. Para leer la conversación o responder, hay que entrar con tu correo.</p>
<h2>¿Perdiste el acceso a una incidencia?</h2>
<p>Pide que te reenviemos el enlace: lo enviamos al correo con el que se abrió. Revisa tu bandeja y el spam.</p>
<div class="ej"><div class="et">Ejemplo</div>El equipo te responde con una solución. La pruebas, funciona, y marcas la incidencia como resuelta. Una semana después reaparece: entras, respondes «ha vuelto a pasar» y la incidencia se reabre para que la retomen.</div>

<h1 id="s6" class="pb"><span class="n">6</span> Valorar la atención</h1>
<p>Cuando resolvemos tu incidencia, nos encanta saber qué tal lo hemos hecho. Te lleva unos segundos y nos ayuda a mejorar.</p>
<h2>Cómo se valora</h2>
<p>Al resolverse tu incidencia, puedes puntuar la atención del 1 al 5 (en estrellas) y, si quieres, dejar un comentario. Puedes hacerlo desde el propio portal o desde el correo que te llega, pulsando directamente las estrellas.</p>
<div class="ej"><div class="et">Ejemplo</div>Te resolvemos una avería y te llega un correo: «¿Qué tal te hemos atendido?». Pulsas 5 estrellas y añades «muy rápidos, gracias». Nosotros lo vemos y sabemos que vamos bien.</div>

<h1 id="s7" class="pb"><span class="n">7</span> Preguntas frecuentes</h1>
<p>Lo que más se pregunta sobre el portal.</p>
<div class="faq"><b>¿Necesito crear una cuenta o recordar una contraseña?</b><br>No. Solo tu correo y el código de 6 dígitos que te enviamos cada vez. Nada más.</div>
<div class="faq"><b>No me llega el código.</b><br>Revisa la carpeta de spam o correo no deseado. Si sigue sin aparecer, pídelo otra vez desde el portal. Caduca a los 10 minutos.</div>
<div class="faq"><b>¿Puedo adjuntar fotos o archivos?</b><br>Sí, tanto al abrir la incidencia como al responder: imágenes, PDF o documentos de hasta 10 MB cada uno. Una foto suele ayudar mucho.</div>
<div class="faq"><b>¿Cómo sé si me habéis contestado?</b><br>Entrando al portal lo ves («Respuesta nueva»); además, te avisamos por correo cuando hay novedades en tu incidencia.</div>
<div class="faq"><b>¿Cuándo me responden?</b><br>El equipo atiende de lunes a viernes, de 7:00 a 21:00. Fuera de ese horario puedes escribirnos igual: te leeremos en cuanto abramos.</div>
<div class="faq"><b>¿Puedo cerrar yo una incidencia?</b><br>Sí, márcala como resuelta. Y si el problema vuelve, respóndela y se reabre sola.</div>
<div class="faq"><b>¿Veré mis incidencias antiguas?</b><br>Sí, siempre que entres con el mismo correo, tienes tu historial a mano.</div>
<div class="faq"><b>¿Puedo ver cómo va mi incidencia sin entrar?</b><br>Sí: con tu número de incidencia ves solo el estado. Para leer la conversación o responder, entra con tu correo.</div>
<div class="faq"><b>¿Hay que cerrar la sesión?</b><br>No es obligatorio, pero si usas un ordenador compartido pulsa «Salir» al terminar.</div>
HTML;

$css = <<<'CSS'
@page { margin: 70px 40px 60px 40px; }
body { font-family: 'DejaVu Sans', sans-serif; font-size: 10.5px; line-height: 1.55; color: #1f2733; }
.cover { background: #1d4ed7; border-radius: 12px; padding: 34px 28px 30px 28px; color: #fff; }
.brand { font-size: 9px; letter-spacing: 1.5px; color: #dbe6ff; font-weight: bold; }
.cover h1c { display: block; font-size: 30px; font-weight: bold; margin: 26px 0 6px 0; line-height: 1.2; }
.cover .sub { font-size: 13px; color: #dbe6ff; }
.meta { margin-top: 18px; font-size: 9px; color: #dbe6ff; }
.intro { margin: 18px 0 8px 0; font-size: 11.5px; color: #4a5462; }
.toc-title { font-size: 15px; font-weight: bold; color: #12336b; margin: 24px 0 10px 0; }
.idx { border-bottom: 1px solid #e1e6eb; padding: 7px 0; }
.num { display: inline-block; width: 22px; height: 22px; line-height: 22px; text-align: center; background: #edf3ff; color: #1d4ed7; border-radius: 11px; font-weight: bold; margin-right: 10px; font-size: 10px; }
.idxt { display: inline-block; vertical-align: top; width: 440px; }
.muted { color: #6b7180; }
h1 { font-size: 18px; color: #12336b; margin: 0 0 10px 0; padding-bottom: 8px; border-bottom: 2px solid #1d4ed7; }
h1 .n { display: inline-block; width: 26px; height: 26px; line-height: 26px; text-align: center; background: #1d4ed7; color: #fff; border-radius: 13px; font-size: 13px; margin-right: 8px; }
h2 { font-size: 12.5px; color: #1d4ed7; margin: 16px 0 4px 0; }
p { margin: 0 0 8px 0; }
ul, ol { margin: 4px 0 8px 0; padding-left: 20px; }
li { margin-bottom: 4px; }
.pb { page-break-before: always; }
.ej { background: #f5f8ff; border: 1px solid #d3dff7; border-left: 4px solid #1d4ed7; border-radius: 6px; padding: 9px 12px; margin: 12px 0; color: #4a5462; }
.et { font-size: 8px; letter-spacing: 1.2px; font-weight: bold; color: #1d4ed7; text-transform: uppercase; margin-bottom: 3px; }
.tip { background: #edf9f3; border: 1px solid #b7e3c6; border-radius: 6px; padding: 9px 12px; margin: 12px 0; }
.faq { border-bottom: 1px solid #e1e6eb; padding: 8px 0; }
.faq b { color: #12336b; }
CSS;

$portada = '<div class="cover"><div class="brand">AEME GROUP · SOPORTE</div>'
    . '<div style="font-size:30px;font-weight:bold;margin:26px 0 6px 0;line-height:1.2;">Guía del portal de soporte</div>'
    . '<div class="sub">Cómo pedir ayuda y seguir tus incidencias en AEME Group</div>'
    . '<div class="meta">GUÍA DEL CLIENTE · Versión: ' . VERSION . ' · Fecha: ' . FECHA . ' · Ámbito: Clientes de AEME Group</div></div>'
    . '<div class="intro">Una guía rápida para usar el portal de soporte de AEME Group: entrar, buscar soluciones y abrir y seguir tus incidencias.</div>'
    . '<div class="toc-title">Contenido</div>' . $indice;

$html = '<!doctype html><html><head><meta charset="utf-8"><style>' . $css . '</style></head><body>'
    . $portada . '<div style="page-break-after:always"></div>' . $cuerpo . '</body></html>';

$dompdf = new Dompdf\Dompdf(['isRemoteEnabled' => false, 'defaultFont' => 'DejaVu Sans']);
$dompdf->loadHtml($html, 'UTF-8');
$dompdf->setPaper('A4');
$dompdf->render();

$canvas = $dompdf->getCanvas();
$font = $dompdf->getFontMetrics()->getFont('DejaVu Sans');
$canvas->page_text(40, 812, 'Guía del portal de soporte · AEME Group', $font, 8, [0.42, 0.44, 0.5]);
$canvas->page_text(470, 812, 'Página {PAGE_NUM} de {PAGE_COUNT}', $font, 8, [0.42, 0.44, 0.5]);
$canvas->page_text(40, 28, 'SOPORTE · AEME GROUP', $font, 8, [0.07, 0.2, 0.42]);

$destino = __DIR__ . '/../manuals/04-cliente.pdf';
file_put_contents($destino, $dompdf->output());
echo "Generado {$destino} (" . filesize($destino) . " bytes)\n";
