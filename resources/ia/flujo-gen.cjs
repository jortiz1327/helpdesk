// Genera el SVG del flujo completo de la IA de soporte y lo pega en resources/views/ia-flujo.blade.php.
// Uso (desde la raíz del proyecto): node resources/ia/flujo-gen.cjs   (edita los nodos aquí, no a mano en la vista)
const W = 1520;
let out = [], lines = [], labels = [];
const esc = (s) => s.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
const NUM = {'①':'1','②':'2','③':'3','④':'4','⑤':'5','⑥':'6','⑦':'7','⑧':'8','⑨':'9','⑩':'10'};
const N = {}; // anclas

function anchors(id, cx, y, w, h) {
  N[id] = { cx, y, w, h, t: [cx, y], b: [cx, y + h], l: [cx - w / 2, y + h / 2], r: [cx + w / 2, y + h / 2] };
}
function texto(cx, y0, title, ls) {
  let s = '', y = y0;
  if (title) { s += `<text x="${cx}" y="${y}" class="t">${esc(title)}</text>`; y += 20; }
  for (const l of ls) { s += `<text x="${cx}" y="${y}" class="s">${esc(l)}</text>`; y += 17; }
  return s;
}
// caja: alto automático
function box(id, cx, y, w, kind, title, ls, o = {}) {
  const mm = /^([①-⑩])\s+(.*)$/.exec(title || '');
  if (mm) { o = { ...o, badge: NUM[mm[1]] }; title = mm[2]; }
  const h = o.h || ((title ? 40 : 20) + ls.length * 17 + 6);
  anchors(id, cx, y, w, h);
  out.push(`<g style="--k:var(--k-${kind})" class="n${o.cls ? ' ' + o.cls : ''}"><rect x="${cx - w / 2}" y="${y}" width="${w}" height="${h}"/>${texto(cx, y + 25, title, ls)}${o.badge ? `<circle cx="${cx - w / 2}" cy="${y}" r="13"/><text x="${cx - w / 2}" y="${y + 4.5}" class="b">${o.badge}</text>` : ''}</g>`);
  return N[id];
}
// rombo de decisión
function dia(id, cx, y, w, kind, ls, o = {}) {
  const h = o.h || Math.max(92, ls.length * 17 + 58);
  anchors(id, cx, y, w, h);
  const pts = `${cx},${y} ${cx + w / 2},${y + h / 2} ${cx},${y + h} ${cx - w / 2},${y + h / 2}`;
  const y0 = y + h / 2 - (ls.length - 1) * 8.5 + 4;
  out.push(`<g style="--k:var(--k-${kind})" class="n q"><polygon points="${pts}"/>${ls.map((l, i) => `<text x="${cx}" y="${y0 + i * 17}" class="s q">${esc(l)}</text>`).join('')}${o.badge ? `<circle cx="${cx - w / 2 + 22}" cy="${y + h / 2 - 22}" r="13"/><text x="${cx - w / 2 + 22}" y="${y + h / 2 - 17.5}" class="b">${o.badge}</text>` : ''}</g>`);
  return N[id];
}
const P = (pts) => 'M' + pts.map((p) => p.join(',')).join(' L');
function arr(pts, o = {}) {
  lines.push(`<path ${o.vuelta ? `class="vuelta" style="stroke:var(--k-${o.vuelta})" ` : ''}${o.noarrow ? 'marker-end="none" ' : ''}d="${P(pts)}"/>`);
}
function lab(t, x, y, anchor = 'start', cls = 'et') { labels.push(`<text x="${x}" y="${y}" class="${cls}" text-anchor="${anchor}">${esc(t)}</text>`); }
function seccion(t, y) { labels.push(`<text x="${W / 2}" y="${y}" class="sec" text-anchor="middle">${esc(t)}</text><path class="secl" d="M30,${y + 10} L${W - 30},${y + 10}"/>`); }

const C = 640, L = 200, A = 1000, T = 1340, R = 1120, BUS = 1485;
const GAP = 42;
let y;

/* ===================== A. ENTRADA Y PUERTAS ===================== */
seccion('A · Desde que el cliente escribe hasta que se decide llamar a la IA', 24);
y = 52;
box('n1', C, y, 380, 'cli', '① Cliente escribe', ['correo · WhatsApp · formulario · portal', 'se crea o se actualiza el ticket']);
y = N.n1.y + N.n1.h + GAP;
box('n2', C, y, 380, 'ag', '② El agente pide la sugerencia', ['abre el ticket y pulsa «Sugerir»', 'la IA no actúa sola y no envía nada']);
arr([N.n1.b, N.n2.t]);
y = N.n2.y + N.n2.h + GAP;

const puertas = [
  ['d1', ['¿Asistente encendido y con', 'la clave del proveedor puesta?'], 'no', 'Sin botón de sugerir', ['no sale ningún dato hacia fuera'], 'Se enciende en Ajustes › Asistente IA'],
  ['d2', ['¿Ya hay una sugerencia para', 'el último mensaje del cliente?'], 'mem', 'Se enseña la que ya estaba', ['no se llama a la IA y no gasta', '«Regenerar» salta esta puerta', '→ sigue en el paso 9'], null],
  ['d3', ['¿Se ha llegado al tope', 'mensual de gasto? (50 USD)'], 'no', 'Falla con aviso al agente', ['«Contesta tú; vuelve el día 1»', 'no se llama al proveedor', '→ lo contesta el agente sin IA'], null],
  ['d4', ['¿El cliente solo ha enviado', 'audios de WhatsApp?'], 'sys', 'Texto fijo, sin IA', ['«No podemos escuchar audios…»', 'no gasta nada', '→ sigue en el paso 9'], null],
];
let prev = N.n2;
for (const [id, ls, kind, tt, tl] of puertas) {
  dia(id, C, y, 360, 'sys', ls);
  arr([prev.b, N[id].t]);
  const bid = id + 'x';
  const h = 40 + tl.length * 17 + 6;
  box(bid, R, N[id].y + N[id].h / 2 - h / 2, 380, kind, tt, tl);
  arr([N[id].r, N[bid].l]);
  lab('Sí', N[id].r[0] + 8, N[id].r[1] - 8);
  lab('No', C + 10, N[id].b[1] + 17);
  prev = N[id];
  y = N[id].y + N[id].h + GAP;
}

/* ===================== B. CONTEXTO ===================== */
seccion('B · La conversación y todo lo que se consulta antes de llamar a la IA', y + 6);
y += 40;
box('b1', C, y, 420, 'sys', '③ Prepara la conversación', ['lee los mensajes del cliente y las respuestas del equipo', '(sin notas internas) y localiza la última pregunta']);
arr([prev.b, N.b1.t]);
const yl = N.b1.y + N.b1.h + 34; // altura de reparto
const yTop = yl + 30;

// Memoria (izquierda)
box('m1', L, yTop, 300, 'mem', '④ Memoria y guía', ['preguntas frecuentes', 'respuestas de ejemplo del equipo', 'tickets parecidos y manuales', 'correcciones validadas por un responsable']);
box('m2', L, N.m1.y + N.m1.h + 36, 300, 'mem', 'Cómo se limpia lo que se guarda', ['sin firmas, correos ni teléfonos', 'contraseñas tapadas', 'lo antiguo se marca ANTIGUO', '(sirve para entender, no para copiar)']);
arr([N.m1.b, N.m2.t]);

// Fotos (centro)
dia('p1', C, yTop, 320, 'sys', ['¿El cliente ha enviado', 'fotos?'], { badge: '4' });
box('p2', C, N.p1.y + N.p1.h + 36, 320, 'sys', 'Lee el código de barras', ['en local (la foto no sale)', 'saca los códigos de etiqueta']);
arr([N.p1.b, N.p2.t]);
lab('Sí', C + 10, N.p1.b[1] + 24);

// AdminHoteles (derecha)
dia('h1', A, yTop, 340, 'api', ['¿AdminHoteles está', 'configurado? (solo lectura)'], { badge: '4' });
box('h1x', T, N.h1.y + N.h1.h / 2 - 28, 230, 'no', 'Sin datos del hotel', ['la IA pregunta por él']);
arr([N.h1.r, N.h1x.l]); lab('No', N.h1.r[0] + 8, N.h1.r[1] - 8);
box('h2', A, N.h1.y + N.h1.h + 36, 340, 'api', 'Busca el hotel', ['por el correo del ticket, el nombre en', 'el mensaje o la firma, o los códigos']);
arr([N.h1.b, N.h2.t]); lab('Sí', A + 10, N.h1.b[1] + 24);
dia('h3', A, N.h2.y + N.h2.h + 36, 340, 'api', ['¿El correo está dado de alta', 'en la aplicación?']);
arr([N.h2.b, N.h3.t]);
box('h3x', T, N.h3.y + N.h3.h / 2 - 48, 230, 'no', 'Correo sin verificar', ['no se dan datos de hoteles', 'si el hotel viene nombrado:', 'solo se cuentan las antenas']);
arr([N.h3.r, N.h3x.l]); lab('No', N.h3.r[0] + 8, N.h3.r[1] - 8);
dia('h4', A, N.h3.y + N.h3.h + 36, 340, 'api', ['¿El hotel que dice el cliente', 'coincide con el del correo?']);
arr([N.h3.b, N.h4.t]); lab('Sí: rol, usuario activo, hoteles', A + 10, N.h3.b[1] + 24);
box('h4x', T, N.h4.y + N.h4.h / 2 - 40, 230, 'no', 'No coincide', ['se explica con tacto', 'y se deriva a una persona']);
arr([N.h4.r, N.h4x.l]); lab('No', N.h4.r[0] + 8, N.h4.r[1] - 8);
box('h5', A, N.h4.y + N.h4.h + 36, 340, 'api', 'Datos del hotel para la IA', ['antenas conectadas y desconectadas', 'estado de la etiqueta']);
arr([N.h4.b, N.h5.t]); lab('Sí', A + 10, N.h4.b[1] + 24);

// reparto desde b1
arr([N.b1.b, [C, yl]], { noarrow: true });
arr([[L, yl], [A, yl]], { noarrow: true });
arr([[L, yl], N.m1.t]);
arr([[C, yl], N.p1.t]);
arr([[A, yl], N.h1.t]);

// El «No» de fotos rodea la caja p2
const yBottomAll = Math.max(N.m2.y + N.m2.h, N.p2.y + N.p2.h, N.h5.y + N.h5.h);
const yMerge = yBottomAll + 40;
arr([N.p1.l, [C - 215, N.p1.l[1]], [C - 215, N.p2.y + N.p2.h + 20], [C, N.p2.y + N.p2.h + 20]], { noarrow: true });
lab('No', N.p1.l[0] - 22, N.p1.l[1] - 8, 'end');
// unión
arr([N.m2.b, [L, yMerge]], { noarrow: true });
arr([N.p2.b, [C, yMerge]], { noarrow: true });
arr([N.h5.b, [A, yMerge]], { noarrow: true });
arr([[L, yMerge], [A, yMerge]], { noarrow: true });

/* ===================== C. DECISIÓN EN CÓDIGO Y PROMPT ===================== */
y = yMerge + 54;
seccion('C · Lo que decide el código antes de preguntar a la IA, y la instrucción que se le envía', yMerge + 34);
dia('d5', C, y, 360, 'sys', ['¿Es un tema de los que', 'fija el código?'], { badge: '5' });
arr([[C, yMerge], N.d5.t]);
box('c1', L, N.d5.y + N.d5.h / 2 - 50, 300, 'api', 'Derivar, sin preguntar nada', ['alérgenos · tótem', 'garantía (si no es de farmacia)']);
arr([N.d5.l, N.c1.r]); lab('Sí', N.d5.l[0] - 8, N.d5.l[1] - 8, 'end');
box('c2', 1040, N.d5.y + N.d5.h / 2 - 50, 340, 'cli', 'Pedir hotel y correo', ['problema de uso, primer mensaje,', 'sin hotel ni correo verificado']);
arr([N.d5.r, N.c2.l]); lab('Sí', N.d5.r[0] + 8, N.d5.r[1] - 8);
const yBar = Math.max(N.c1.y + N.c1.h, N.c2.y + N.c2.h, N.d5.y + N.d5.h) + 28;
box('pr', C, yBar + 36, 470, 'sys', '⑤ Compone la instrucción para la IA', ['reglas del equipo + orden de decisión', '+ memoria + datos del hotel + códigos de las fotos', '+ «DECISIÓN YA TOMADA» si el código decidió']);
arr([N.c1.b, [L, yBar], [1040, yBar]], { noarrow: true });
arr([N.c2.b, [1040, yBar]], { noarrow: true });
arr([N.d5.b, N.pr.t]);
lab('No: decide el modelo', C + 10, N.d5.b[1] + 15);
box('ia', C, N.pr.y + N.pr.h + 46, 440, 'ai', '⑥ IA · Claude Haiku 5.5', ['recibe la conversación y la instrucción', 'aplica el orden de decisión (7 puntos)', 'y redacta el borrador'], { cls: 'ia' });
arr([N.pr.b, N.ia.t]); lab('una llamada: se pagan los tokens', C + 10, N.pr.b[1] + 28);

/* ===================== D. ORDEN DE DECISIÓN ===================== */
y = N.ia.y + N.ia.h + 54;
seccion('D · Cómo decide el modelo: el primer punto que encaja manda', y - 14);
const D = [
  ['o1', ['¿Alérgenos, contenido erróneo en', 'las etiquetas, tótem, formación o', 'cliente que no es hotel ni tienda?'], 'api', 'DERIVAR', ['sin preguntar nada', 'nota «Para el equipo» → Soporte']],
  ['o2', ['¿Es material o dinero?', '(pedido, presupuesto,', 'garantía o cambio de precio)'], 'api', 'Según qué sea', ['pedido de material → escribir a postventa', '   (material, cantidad y foto)', 'presupuesto → «Asignar al departamento de', '   Pedidos y facturas.»', 'garantía → DERIVAR a Garantías (nunca correo)', 'garantía de farmacia → «lo gestiona Expofarm»', 'cambio de precio en tienda → DERIVAR']],
  ['o3', ['¿Lo ejecuta el equipo?', '(rueda de menú, logos, plantillas,', 'alta de etiquetas en la plataforma)'], 'api', 'DERIVAR', ['si el hotel ya consta no se pide', 'hotel ni correo para derivarlo']],
  ['o4', ['¿Lo hace el propio hotel?', '(usuarios, roles y permisos, posiciones,', '«Sin plato», forzar menú, horarios)'], 'ag', 'EXPLICAR los pasos', ['Ajustes › Roles, Usuarios, Posiciones…', 'solo con el correo verificado', 'si nadie del hotel puede → DERIVAR']],
  ['o5', ['¿Es un problema de uso y no se', 'sabe quién escribe?'], 'cli', 'PEDIR hotel y correo', ['como máximo esas dos preguntas', 'los demás datos se piden después']],
  ['o6', ['¿Hay antenas desconectadas?', '(dato de AdminHoteles)'], 'ag', 'Avisar a su personal técnico', ['que lo revise su mantenimiento', 'si ya se le dijo y sigue igual → DERIVAR']],
  ['o7', ['¿El contexto trae los pasos', 'o la respuesta?'], 'ag', 'RESPONDER', ['solo con lo que dice el contexto', 'nunca inventa pasos, datos ni plazos']],
];
let prevD = N.ia, firstD = true;
const stubs = [];
y += 10;
for (const [id, ls, kind, tt, tl] of D) {
  dia(id, C, y, 420, 'sys', ls, { badge: String(D.findIndex((d) => d[0] === id) + 1) });
  arr([prevD.b, N[id].t]);
  if (!firstD) lab('No', C + 10, N[id].y - 6);
  firstD = false;
  const rid = id + 'x';
  const h = 40 + tl.length * 17 + 6;
  box(rid, 1130, N[id].y + N[id].h / 2 - h / 2, 420, kind, tt, tl);
  arr([N[id].r, N[rid].l]); lab('Sí', N[id].r[0] + 8, N[id].r[1] - 8);
  stubs.push(N[rid]);
  prevD = N[id];
  y = Math.max(N[id].y + N[id].h, N[rid].y + N[rid].h) + 36;
}
box('o8', C, y, 380, 'no', '⑧ Si no encaja o hay duda', ['DERIVAR (o «no sé»: sin borrador)', 'es preferible derivar a dar una respuesta dudosa']);
arr([prevD.b, N.o8.t]); lab('No', C + 10, prevD.b[1] + 17);
stubs.push(N.o8);
y = N.o8.y + N.o8.h + 38;
// bus de salida
for (const s of stubs.slice(0, -1)) arr([s.r, [BUS, s.r[1]]], { noarrow: true });
arr([[BUS, stubs[0].r[1]], [BUS, y]], { noarrow: true });
arr([N.o8.b, [C, y]], { noarrow: true });
arr([[BUS, y], [C, y]], { noarrow: true });

/* ===================== E. SALIDA ===================== */
seccion('E · Qué se hace con lo que contesta la IA', y + 24);
y += 56;
dia('e1', C, y, 380, 'ai', ['¿La respuesta llegó completa', 'y el proveedor contestó?'], { badge: '7' });
arr([[C, y - 20], N.e1.t]);
box('e1x', R, N.e1.y + N.e1.h / 2 - 44, 400, 'no', 'No pudo preparar la respuesta', ['botón «Reintentar»', 'si ya se pagó, los tokens se cuentan']);
arr([N.e1.r, N.e1x.l]); lab('No', N.e1.r[0] + 8, N.e1.r[1] - 8);
dia('e2', C, N.e1.y + N.e1.h + 38, 380, 'ai', ['¿La IA se atreve a contestar?', '(si no, responde «NO SÉ»)']);
arr([N.e1.b, N.e2.t]); lab('Sí', C + 10, N.e1.b[1] + 25);
box('e2x', R, N.e2.y + N.e2.h / 2 - 44, 400, 'no', 'No sabe: no hay borrador', ['lo debe llevar una persona', 'queda como hueco en Ajustes › Asistente IA']);
arr([N.e2.r, N.e2x.l]); lab('No', N.e2.r[0] + 8, N.e2.r[1] - 8);
dia('e3', C, N.e2.y + N.e2.h + 38, 380, 'ai', ['¿Lleva el marcador interno', '[[DERIVAR: categoría | motivo]]?']);
arr([N.e2.b, N.e3.t]); lab('Sí', C + 10, N.e2.b[1] + 25);
box('e3x', R, N.e3.y + N.e3.h / 2 - 58, 400, 'api', 'Derivar (sugerido)', ['nota «Para el equipo»:', '«Llévalo a Soporte · Garantías · Pedidos» + motivo', 'más un mensaje para el cliente (opcional)', 'el marcador no se enseña al agente']);
arr([N.e3.r, N.e3x.l]); lab('Sí', N.e3.r[0] + 8, N.e3.r[1] - 8);
box('e4', C, N.e3.y + N.e3.h + 38, 400, 'ag', 'Borrador sugerido', ['responder · pedir datos · indicar el canal', 'se enseña en el panel: no se envía solo']);
arr([N.e3.b, N.e4.t]); lab('No', C + 10, N.e3.b[1] + 25);

// Guardado y coste
box('s1', C, N.e4.y + N.e4.h + 44, 470, 'sys', '⑧ Se guarda la sugerencia', ['con los tokens gastados → coste estimado del mes', 'aviso al 80 % y al 100 % del tope (50 USD, mes natural)', 'el panel de Ajustes muestra el gasto por día']);
arr([N.e4.b, N.s1.t]);
const yb = N.s1.y - 22;
for (const s of [N.e1x, N.e2x, N.e3x]) arr([s.r, [BUS, s.r[1]]], { noarrow: true });
arr([[BUS, N.e1x.r[1]], [BUS, yb]], { noarrow: true });
arr([[BUS, yb], [C, yb]], { noarrow: true });

/* ===================== F. EL AGENTE Y EL APRENDIZAJE ===================== */
y = N.s1.y + N.s1.h + 70;
seccion('F · El agente decide, valora, y lo validado vuelve a la memoria', y - 16);
y += 14;
box('g1', C, y, 440, 'ag', '⑨ El agente ve la sugerencia', ['lo decide todo él: la IA no envía nada', 'lo que llegó de las puertas de A también se ve aquí']);
arr([N.s1.b, N.g1.t]);
const yf = N.g1.y + N.g1.h + 32;
const fx = [190, 540, 890, 1240];
const fs = [
  ['f1', 'ag', '👍 Útil', ['cuenta para el acierto', 'de la IA en el panel']],
  ['f2', 'ag', '✉️ Copia y envía él', ['lo edita si quiere', 'se marca como usada']],
  ['f3', 'sys', 'Lo contesta sin la IA', ['si no hay borrador o no sirve', 'el cliente recibe su respuesta']],
  ['f4', 'no', '👎 No sirve', ['escribe qué estaba mal y', 'cómo había que responder']],
];
arr([N.g1.b, [C, yf]], { noarrow: true });
arr([[fx[0], yf], [fx[3], yf]], { noarrow: true });
fs.forEach(([id, k, t, l], i) => { box(id, fx[i], yf + 30, 300, k, t, l); arr([[fx[i], yf], N[id].t]); });
const yfEnd = Math.max(...fs.map(([id]) => N[id].y + N[id].h));

// validación
dia('v1', 1240, yfEnd + 40, 330, 'mem', ['¿Un responsable valida', 'la corrección?'], { badge: '10' });
arr([N.f4.b, N.v1.t]);
box('v1x', 890, N.v1.y + N.v1.h / 2 - 32, 300, 'no', 'Se descarta', ['no llega nunca a la IA']);
arr([N.v1.l, N.v1x.r]); lab('No', N.v1.l[0] - 8, N.v1.l[1] - 8, 'end');
box('v2', 1240, N.v1.y + N.v1.h + 38, 300, 'mem', 'Entra en la memoria', ['como corrección validada', 'la IA la lee en la próxima consulta']);
arr([N.v1.b, N.v2.t]); lab('Sí', 1250, N.v1.b[1] + 25);

// bucles
const yRet = Math.max(N.v2.y + N.v2.h, N.v1x.y + N.v1x.h) + 40;
arr([N.f2.b, [N.f2.cx, yRet], [18, yRet], [18, N.n1.y + N.n1.h / 2], N.n1.l], { vuelta: 'cli' });
lab('el cliente contesta → vuelve al paso 1', 30, yRet - 8);
arr([N.v2.l, [46, N.v2.l[1]], [46, N.m1.y + N.m1.h / 2], N.m1.l], { vuelta: 'mem' });
lab('bucle: lo validado vuelve a la memoria', 640, N.v2.l[1] - 8, 'start', 'et vu');
const H = yRet + 30;

const svg = `<svg viewBox="0 0 ${W} ${H}" role="img" aria-label="Flujo completo de la IA de soporte, de que el cliente escribe a que el responsable valida la corrección">
<defs><marker id="p" viewBox="0 0 10 10" refX="9" refY="5" markerWidth="7" markerHeight="7" orient="auto-start-reverse"><path d="M0,0 L10,5 L0,10 z" class="punta"/></marker></defs>
<g class="fl" marker-end="url(#p)">${lines.join('')}</g>
${labels.join('')}
${out.join('\n')}
</svg>`;
const vista = require('path').join(__dirname, '..', 'views', 'ia-flujo.blade.php');
const html = require('fs').readFileSync(vista, 'utf8');
const a = html.indexOf('<div class="lienzo">'), b = html.indexOf('</svg>', a) + 6;
require('fs').writeFileSync(vista, html.slice(0, a) + '<div class="lienzo">' + svg + html.slice(b));
console.log('ia-flujo.blade.php actualizado (' + svg.length + ' bytes)');
