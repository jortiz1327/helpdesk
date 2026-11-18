// Genera el SVG del flujo completo de la IA de soporte y lo pega en resources/views/ia-flujo.blade.php
// (entre <!--LIENZO--> y <!--/LIENZO-->). Uso desde la raíz del proyecto:  node resources/ia/flujo-gen.cjs
// Edita los nodos AQUÍ, no a mano en la vista.
const fs = require('fs');
const path = require('path');

const W = 1520;
const NUM = { '①': '1', '②': '2', '③': '3', '④': '4', '⑤': '5', '⑥': '6', '⑦': '7', '⑧': '8', '⑨': '9', '⑩': '10' };
const esc = (s) => s.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
const out = [], lines = [], labels = [], secciones = [];
const N = {};

/* ---------- iconos dibujados (rejilla 24, trazo 2) ---------- */
const ICONOS = {
  up: '<path d="M7 10v12"/><path d="M15 5.88 14 10h5.83a2 2 0 0 1 1.92 2.56l-2.33 8A2 2 0 0 1 17.5 22H4a2 2 0 0 1-2-2v-8a2 2 0 0 1 2-2h2.76a2 2 0 0 0 1.79-1.11L12 2a3.13 3.13 0 0 1 3 3.88Z"/>',
  down: '<g transform="translate(0,24) scale(1,-1)"><path d="M7 10v12"/><path d="M15 5.88 14 10h5.83a2 2 0 0 1 1.92 2.56l-2.33 8A2 2 0 0 1 17.5 22H4a2 2 0 0 1-2-2v-8a2 2 0 0 1 2-2h2.76a2 2 0 0 0 1.79-1.11L12 2a3.13 3.13 0 0 1 3 3.88Z"/></g>',
  mail: '<rect x="2" y="4" width="20" height="16" rx="2"/><path d="m22 7-8.97 5.7a1.94 1.94 0 0 1-2.06 0L2 7"/>',
};
const icono = (n, x, y, s = 0.8) => `<g class="ic" transform="translate(${x},${y}) scale(${s})">${ICONOS[n]}</g>`;

/* ---------- primitivas ---------- */
function anchors(id, cx, y, w, h) {
  N[id] = { cx, y, w, h, t: [cx, y], b: [cx, y + h], l: [cx - w / 2, y + h / 2], r: [cx + w / 2, y + h / 2] };
}
function texto(cx, y0, title, ls, dx = 0) {
  let s = '', y = y0;
  if (title) { s += `<text x="${cx + dx}" y="${y}" class="t">${esc(title)}</text>`; y += 22; }
  for (const l of ls) { s += `<text x="${cx}" y="${y}" class="s">${esc(l)}</text>`; y += 18; }
  return s;
}
function insignia(x, y, n) {
  return `<circle class="bd" cx="${x}" cy="${y}" r="${n.length > 1 ? 14 : 13}"/><text x="${x}" y="${y + 4.5}" class="b">${n}</text>`;
}
function box(id, cx, y, w, kind, title, ls, o = {}) {
  const mm = /^([①-⑩])\s+(.*)$/.exec(title || '');
  if (mm) { o = { ...o, badge: NUM[mm[1]] }; title = mm[2]; }
  const h = o.h || ((title ? 44 : 22) + ls.length * 18 + 8);
  anchors(id, cx, y, w, h);
  let ic = '', dx = 0;
  if (o.icon) { dx = 13; ic = icono(o.icon, cx - (title.length * 8.4) / 2 - 12, y + 9); }
  out.push(`<g style="--k:var(--k-${kind})" class="n${o.cls ? ' ' + o.cls : ''}"><rect x="${cx - w / 2}" y="${y}" width="${w}" height="${h}" rx="12"/>${ic}${texto(cx, y + 28, title, ls, dx)}${o.badge ? insignia(cx - w / 2, y, o.badge) : ''}</g>`);
  return N[id];
}
function dia(id, cx, y, w, kind, ls, o = {}) {
  const h = o.h || Math.max(96, ls.length * 18 + 60);
  anchors(id, cx, y, w, h);
  const pts = `${cx},${y} ${cx + w / 2},${y + h / 2} ${cx},${y + h} ${cx - w / 2},${y + h / 2}`;
  const y0 = y + h / 2 - (ls.length - 1) * 9 + 4.5;
  out.push(`<g style="--k:var(--k-${kind})" class="n q"><polygon points="${pts}"/>${ls.map((l, i) => `<text x="${cx}" y="${y0 + i * 18}" class="s q">${esc(l)}</text>`).join('')}${o.badge ? insignia(cx - w / 2 + 26, y + h / 2 - 24, o.badge) : ''}</g>`);
  return N[id];
}
const P = (pts) => 'M' + pts.map((p) => p.join(',')).join(' L');
function arr(pts, o = {}) {
  lines.push(`<path ${o.vuelta ? `class="vuelta" style="--k:var(--k-${o.vuelta})" ` : ''}${o.noarrow ? 'marker-end="none" ' : ''}d="${P(pts)}"/>`);
}
// «Sí» / «No»: pastilla; textos largos: nota con halo
function lab(t, x, y, anchor = 'start', cls = 'et') {
  if (t === 'Sí' || t === 'No') {
    const w = 30, x0 = anchor === 'end' ? x - w + 4 : x - 4;
    labels.push(`<g class="pill ${t === 'Sí' ? 'si' : 'no'}"><rect x="${x0}" y="${y - 13}" width="${w}" height="19" rx="9.5"/><text x="${x0 + w / 2}" y="${y + 0.5}">${t}</text></g>`);
  } else {
    labels.push(`<text x="${x}" y="${y}" class="${cls}" text-anchor="${anchor}">${esc(t)}</text>`);
  }
}
function seccion(letra, titulo, desc, yTop) { secciones.push({ letra, titulo, desc, y: yTop }); return yTop + 100; }

const C = 640, L = 215, A = 1000, T = 1340, R = 1120, BUS = 1485;
const GAP = 44;
let y;

/* ===================== A. PUERTAS ===================== */
y = seccion('A', 'Puertas de entrada', 'Antes de gastar nada, el sistema comprueba si procede llamar a la IA', 20);
box('n1', C, y, 400, 'cli', '① Cliente escribe', ['correo · WhatsApp · formulario · portal', 'se crea o se actualiza el ticket']);
y = N.n1.y + N.n1.h + GAP;
box('n2', C, y, 400, 'ag', '② El agente pide la sugerencia', ['abre el ticket y pulsa «Sugerir»', 'la IA no actúa sola y no envía nada']);
arr([N.n1.b, N.n2.t]);
y = N.n2.y + N.n2.h + GAP;

const puertas = [
  ['d1', ['¿Asistente encendido y con', 'la clave del proveedor puesta?'], 'no', 'Sin botón de sugerir', ['no sale ningún dato hacia fuera']],
  ['d2', ['¿Ya hay una sugerencia para', 'el último mensaje del cliente?'], 'mem', 'Se enseña la que ya estaba', ['no se llama a la IA y no gasta', '«Regenerar» salta esta puerta', '→ sigue en el paso 9']],
  ['d3', ['¿Se ha llegado al tope', 'mensual de gasto? (50 USD)'], 'no', 'Falla con aviso al agente', ['«Contesta tú; vuelve el día 1»', 'no se llama al proveedor', '→ lo contesta el agente sin IA']],
  ['d4', ['¿El cliente solo ha enviado', 'audios de WhatsApp?'], 'sys', 'Texto fijo, sin IA', ['«No podemos escuchar audios…»', 'no gasta nada', '→ sigue en el paso 9']],
];
let prev = N.n2;
for (const [id, ls, kind, tt, tl] of puertas) {
  dia(id, C, y, 380, 'sys', ls);
  arr([prev.b, N[id].t]);
  const bid = id + 'x';
  const h = 44 + tl.length * 18 + 8;
  box(bid, R, N[id].y + N[id].h / 2 - h / 2, 390, kind, tt, tl);
  arr([N[id].r, N[bid].l]);
  lab('Sí', N[id].r[0] + 12, N[id].r[1] - 8);
  lab('No', C + 14, N[id].b[1] + 22);
  prev = N[id];
  y = N[id].y + N[id].h + GAP;
}
const bottomA = prev.b[1] + 40;

/* ===================== B. CONTEXTO ===================== */
y = seccion('B', 'Contexto', 'Se reúne en paralelo lo que la IA necesita saber: memoria, fotos y datos del hotel', bottomA + 22);
box('b1', C, y, 440, 'sys', '③ Prepara la conversación', ['lee los mensajes del cliente y las respuestas del equipo', '(sin notas internas) y localiza la última pregunta']);
arr([prev.b, N.b1.t]);
const yl = N.b1.y + N.b1.h + 36;
const yTop = yl + 32;

box('m1', L, yTop, 290, 'mem', '④ Memoria y guía', ['preguntas frecuentes', 'respuestas de ejemplo del equipo', 'tickets parecidos y manuales', 'correcciones validadas']);
box('m2', L, N.m1.y + N.m1.h + 40, 290, 'mem', 'Cómo se limpia', ['sin firmas, correos ni teléfonos', 'contraseñas tapadas', 'lo antiguo se marca ANTIGUO', '(sirve para entender, no copiar)']);
arr([N.m1.b, N.m2.t]);

dia('p1', C, yTop, 340, 'sys', ['¿El cliente ha enviado', 'fotos?'], { badge: '4' });
box('p2', C, N.p1.y + N.p1.h + 40, 320, 'sys', 'Lee el código de barras', ['en local (la foto no sale)', 'saca los códigos de etiqueta']);
arr([N.p1.b, N.p2.t]);
lab('Sí', C + 14, N.p1.b[1] + 26);

dia('h1', A, yTop, 350, 'api', ['¿AdminHoteles está', 'configurado? (solo lectura)'], { badge: '4' });
box('h1x', T, N.h1.y + N.h1.h / 2 - 30, 230, 'no', 'Sin datos del hotel', ['la IA pregunta por él']);
arr([N.h1.r, N.h1x.l]); lab('No', N.h1.r[0] + 12, N.h1.r[1] - 8);
box('h2', A, N.h1.y + N.h1.h + 40, 350, 'api', 'Busca el hotel', ['por el correo del ticket, el nombre en', 'el mensaje o la firma, o los códigos']);
arr([N.h1.b, N.h2.t]); lab('Sí', A + 14, N.h1.b[1] + 26);
dia('h3', A, N.h2.y + N.h2.h + 40, 350, 'api', ['¿El correo está dado de alta', 'en la aplicación?']);
arr([N.h2.b, N.h3.t]);
box('h3x', T, N.h3.y + N.h3.h / 2 - 52, 230, 'no', 'Correo sin verificar', ['no se dan datos de hoteles', 'si el hotel viene nombrado:', 'solo se cuentan las antenas']);
arr([N.h3.r, N.h3x.l]); lab('No', N.h3.r[0] + 12, N.h3.r[1] - 8);
dia('h4', A, N.h3.y + N.h3.h + 40, 350, 'api', ['¿El hotel que dice el cliente', 'coincide con el del correo?']);
arr([N.h3.b, N.h4.t]); lab('Sí', A + 14, N.h3.b[1] + 26);
box('h4x', T, N.h4.y + N.h4.h / 2 - 42, 230, 'no', 'No coincide', ['se explica con tacto', 'y se deriva a una persona']);
arr([N.h4.r, N.h4x.l]); lab('No', N.h4.r[0] + 12, N.h4.r[1] - 8);
box('h5', A, N.h4.y + N.h4.h + 40, 350, 'api', 'Datos del hotel para la IA', ['rol y usuario activo del correo', 'antenas conectadas y desconectadas', 'estado de la etiqueta']);
arr([N.h4.b, N.h5.t]); lab('Sí', A + 14, N.h4.b[1] + 26);

arr([N.b1.b, [C, yl]], { noarrow: true });
arr([[L, yl], [A, yl]], { noarrow: true });
arr([[L, yl], N.m1.t]);
arr([[C, yl], N.p1.t]);
arr([[A, yl], N.h1.t]);

const yBottomAll = Math.max(N.m2.y + N.m2.h, N.p2.y + N.p2.h, N.h5.y + N.h5.h);
const yMerge = yBottomAll + 44;
arr([N.p1.l, [C - 230, N.p1.l[1]], [C - 230, N.p2.y + N.p2.h + 22], [C, N.p2.y + N.p2.h + 22]], { noarrow: true });
lab('No', N.p1.l[0] - 14, N.p1.l[1] - 8, 'end');
arr([N.m2.b, [L, yMerge]], { noarrow: true });
arr([N.p2.b, [C, yMerge]], { noarrow: true });
arr([N.h5.b, [A, yMerge]], { noarrow: true });
arr([[L, yMerge], [A, yMerge]], { noarrow: true });
const bottomB = yMerge + 26;

/* ===================== C. DECISIÓN EN CÓDIGO ===================== */
y = seccion('C', 'Decisión en código', 'Lo claro se fija sin preguntar al modelo; después se compone la instrucción', bottomB + 22);
dia('d5', C, y, 380, 'sys', ['¿Es un tema de los que', 'fija el código?']);
arr([[C, yMerge], N.d5.t]);
box('c1', L, N.d5.y + N.d5.h / 2 - 52, 290, 'der', 'Derivar, sin preguntar nada', ['alérgenos · tótem', 'garantía (si no es de farmacia)']);
arr([N.d5.l, N.c1.r]); lab('Sí', N.d5.l[0] - 12, N.d5.l[1] - 8, 'end');
box('c2', 1060, N.d5.y + N.d5.h / 2 - 52, 340, 'cli', 'Pedir hotel y correo', ['problema de uso, primer mensaje,', 'sin hotel ni correo verificado']);
arr([N.d5.r, N.c2.l]); lab('Sí', N.d5.r[0] + 12, N.d5.r[1] - 8);
const yBar = Math.max(N.c1.y + N.c1.h, N.c2.y + N.c2.h, N.d5.y + N.d5.h) + 30;
box('pr', C, yBar + 40, 500, 'sys', '⑤ Compone la instrucción para la IA', ['reglas del equipo + orden de decisión', '+ memoria + datos del hotel + códigos de las fotos', '+ «DECISIÓN YA TOMADA» si el código decidió']);
arr([N.c1.b, [L, yBar], [1060, yBar]], { noarrow: true });
arr([N.c2.b, [1060, yBar]], { noarrow: true });
arr([N.d5.b, N.pr.t]);
lab('No: decide el modelo', C + 14, N.d5.b[1] + 18);
box('ia', C, N.pr.y + N.pr.h + 50, 460, 'ai', '⑥ IA · Claude Haiku 5.5', ['recibe la conversación y la instrucción', 'aplica el orden de decisión (7 puntos)', 'y redacta el borrador'], { cls: 'ia' });
arr([N.pr.b, N.ia.t]); lab('una llamada: se pagan los tokens', C + 14, N.pr.b[1] + 30);
const bottomC = N.ia.y + N.ia.h + 30;

/* ===================== D. ORDEN DE DECISIÓN ===================== */
y = seccion('D', 'Orden de decisión', 'El modelo recorre estos puntos en orden; el primero que encaja manda', bottomC + 22);
const D = [
  ['o1', ['¿Alérgenos, contenido erróneo en', 'las etiquetas, tótem, formación o', 'cliente que no es hotel ni tienda?'], 'der', 'DERIVAR', ['sin preguntar nada', 'nota «Para el equipo» → Soporte']],
  ['o2', ['¿Es material o dinero?', '(pedido, presupuesto,', 'garantía o cambio de precio)'], 'der', 'Según qué sea', ['pedido de material → escribir a postventa', '   (material, cantidad y foto)', 'presupuesto → «Asignar al departamento de', '   Pedidos y facturas.»', 'garantía → DERIVAR a Garantías (nunca correo)', 'garantía de farmacia → «lo gestiona Expofarm»', 'cambio de precio en tienda → DERIVAR']],
  ['o3', ['¿Lo ejecuta el equipo?', '(rueda de menú, logos, plantillas,', 'alta de etiquetas en la plataforma)'], 'der', 'DERIVAR', ['si el hotel ya consta no se pide', 'hotel ni correo para derivarlo']],
  ['o4', ['¿Lo hace el propio hotel?', '(usuarios, roles y permisos, posiciones,', '«Sin plato», forzar menú, horarios)'], 'ag', 'EXPLICAR los pasos', ['Ajustes › Roles, Usuarios, Posiciones…', 'solo con el correo verificado', 'si nadie del hotel puede → DERIVAR']],
  ['o5', ['¿Es un problema de uso y no se', 'sabe quién escribe?'], 'cli', 'PEDIR hotel y correo', ['como máximo esas dos preguntas', 'los demás datos se piden después']],
  ['o6', ['¿Hay antenas desconectadas?', '(dato de AdminHoteles)'], 'ag', 'Avisar a su personal técnico', ['que lo revise su mantenimiento', 'si ya se le dijo y sigue igual → DERIVAR']],
  ['o7', ['¿El contexto trae los pasos', 'o la respuesta?'], 'ag', 'RESPONDER', ['solo con lo que dice el contexto', 'nunca inventa pasos, datos ni plazos']],
];
let prevD = N.ia, firstD = true;
const stubs = [];
prevD = N.ia;
for (const [id, ls, kind, tt, tl] of D) {
  dia(id, C, y, 440, 'sys', ls, { badge: String(D.findIndex((d) => d[0] === id) + 1) });
  arr([prevD.b, N[id].t]);
  if (!firstD) lab('No', C + 14, N[id].y - 8);
  firstD = false;
  const rid = id + 'x';
  const h = 44 + tl.length * 18 + 8;
  box(rid, 1135, N[id].y + N[id].h / 2 - h / 2, 430, kind, tt, tl);
  arr([N[id].r, N[rid].l]); lab('Sí', N[id].r[0] + 12, N[id].r[1] - 8);
  stubs.push(N[rid]);
  prevD = N[id];
  y = Math.max(N[id].y + N[id].h, N[rid].y + N[rid].h) + 40;
}
box('o8', C, y, 400, 'no', 'Si no encaja o hay duda', ['DERIVAR (o «no sé»: sin borrador)', 'es preferible derivar a una respuesta dudosa']);
arr([prevD.b, N.o8.t]); lab('No', C + 14, prevD.b[1] + 22);
stubs.push(N.o8);
const yBusD = N.o8.y + N.o8.h + 40;
for (const s of stubs.slice(0, -1)) arr([s.r, [BUS, s.r[1]]], { noarrow: true });
arr([[BUS, stubs[0].r[1]], [BUS, yBusD]], { noarrow: true });
arr([N.o8.b, [C, yBusD]], { noarrow: true });
arr([[BUS, yBusD], [C, yBusD]], { noarrow: true });
const bottomD = yBusD + 20;

/* ===================== E. SALIDA ===================== */
y = seccion('E', 'Salida', 'Qué se hace con lo que contesta la IA, y cuánto cuesta', bottomD + 22);
dia('e1', C, y, 400, 'ai', ['¿La respuesta llegó completa', 'y el proveedor contestó?'], { badge: '7' });
arr([[C, yBusD], N.e1.t]);
box('e1x', R, N.e1.y + N.e1.h / 2 - 48, 410, 'no', 'No pudo preparar la respuesta', ['botón «Reintentar»', 'si ya se pagó, los tokens se cuentan']);
arr([N.e1.r, N.e1x.l]); lab('No', N.e1.r[0] + 12, N.e1.r[1] - 8);
dia('e2', C, N.e1.y + N.e1.h + 40, 400, 'ai', ['¿La IA se atreve a contestar?', '(si no, responde «NO SÉ»)']);
arr([N.e1.b, N.e2.t]); lab('Sí', C + 14, N.e1.b[1] + 26);
box('e2x', R, N.e2.y + N.e2.h / 2 - 48, 410, 'no', 'No sabe: no hay borrador', ['lo debe llevar una persona', 'queda como hueco en Ajustes › Asistente IA']);
arr([N.e2.r, N.e2x.l]); lab('No', N.e2.r[0] + 12, N.e2.r[1] - 8);
dia('e3', C, N.e2.y + N.e2.h + 40, 400, 'ai', ['¿Lleva el marcador interno', '[[DERIVAR: categoría | motivo]]?']);
arr([N.e2.b, N.e3.t]); lab('Sí', C + 14, N.e2.b[1] + 26);
box('e3x', R, N.e3.y + N.e3.h / 2 - 64, 410, 'der', 'Derivar (sugerido)', ['nota «Para el equipo»:', '«Llévalo a Soporte · Garantías · Pedidos» + motivo', 'más un mensaje para el cliente (opcional)', 'el marcador no se enseña al agente']);
arr([N.e3.r, N.e3x.l]); lab('Sí', N.e3.r[0] + 12, N.e3.r[1] - 8);
box('e4', C, N.e3.y + N.e3.h + 40, 420, 'ag', 'Borrador sugerido', ['responder · pedir datos · indicar el canal', 'se enseña en el panel: no se envía solo']);
arr([N.e3.b, N.e4.t]); lab('No', C + 14, N.e3.b[1] + 26);
box('s1', C, N.e4.y + N.e4.h + 46, 500, 'sys', '⑧ Se guarda la sugerencia', ['con los tokens gastados → coste estimado del mes', 'aviso al 80 % y al 100 % del tope (50 USD, mes natural)', 'el panel de Ajustes muestra el gasto por día']);
arr([N.e4.b, N.s1.t]);
const yb = N.s1.y - 24;
for (const s of [N.e1x, N.e2x, N.e3x]) arr([s.r, [BUS, s.r[1]]], { noarrow: true });
arr([[BUS, N.e1x.r[1]], [BUS, yb]], { noarrow: true });
arr([[BUS, yb], [C, yb]], { noarrow: true });
const bottomE = N.s1.y + N.s1.h + 22;

/* ===================== F. AGENTE Y APRENDIZAJE ===================== */
y = seccion('F', 'Agente y aprendizaje', 'La persona decide; lo validado vuelve a la memoria y mejora las siguientes', bottomE + 22);
box('g1', C, y, 460, 'ag', '⑨ El agente ve la sugerencia', ['lo decide todo él: la IA no envía nada', 'también llegan aquí las puertas de A']);
arr([N.s1.b, N.g1.t]);
const yf = N.g1.y + N.g1.h + 34;
const fx = [215, 555, 895, 1235];
const fs2 = [
  ['f1', 'ag', 'Útil', ['cuenta para el acierto', 'de la IA en el panel'], 'up'],
  ['f2', 'ag', 'Copia y envía él', ['lo edita si quiere', 'se marca como usada'], 'mail'],
  ['f3', 'sys', 'Lo contesta sin la IA', ['si no hay borrador o no sirve', 'el cliente recibe su respuesta'], null],
  ['f4', 'no', 'No sirve', ['escribe qué estaba mal y', 'cómo había que responder'], 'down'],
];
arr([N.g1.b, [C, yf]], { noarrow: true });
arr([[fx[0], yf], [fx[3], yf]], { noarrow: true });
fs2.forEach(([id, k, t, l, ic], i) => { box(id, fx[i], yf + 32, 300, k, t, l, { icon: ic }); arr([[fx[i], yf], N[id].t]); });
const yfEnd = Math.max(...fs2.map(([id]) => N[id].y + N[id].h));

dia('v1', 1235, yfEnd + 44, 340, 'mem', ['¿Un responsable valida', 'la corrección?'], { badge: '10' });
arr([N.f4.b, N.v1.t]);
box('v1x', 895, N.v1.y + N.v1.h / 2 - 34, 300, 'no', 'Se descarta', ['no llega nunca a la IA']);
arr([N.v1.l, N.v1x.r]); lab('No', N.v1.l[0] - 12, N.v1.l[1] + 24, 'end');
box('v2', 1235, N.v1.y + N.v1.h + 42, 300, 'mem', 'Entra en la memoria', ['como corrección validada', 'la IA la lee en la próxima consulta']);
arr([N.v1.b, N.v2.t]); lab('Sí', 1249, N.v1.b[1] + 26);

const yRet = Math.max(N.v2.y + N.v2.h, N.v1x.y + N.v1x.h) + 46;
arr([N.f2.b, [N.f2.cx, yRet], [14, yRet], [14, N.n1.y + N.n1.h / 2], N.n1.l], { vuelta: 'cli' });
lab('el cliente contesta → vuelve al paso 1', 32, yRet - 12);
arr([N.v2.l, [38, N.v2.l[1]], [38, N.m1.y + N.m1.h / 2], N.m1.l], { vuelta: 'mem' });
lab('bucle: lo validado vuelve a la memoria', 660, N.v2.l[1] - 12, 'start', 'et vu');
const bottomF = yRet + 30;
const H = bottomF + 20;

/* ---------- bandas de capítulo (detrás de todo) ---------- */
const bandas = secciones.map((s, i) => {
  const fin = i < secciones.length - 1 ? secciones[i + 1].y - 22 : bottomF;
  const ancho = s.titulo.length * 10.6;
  return `<g class="banda"><rect x="44" y="${s.y}" width="${W - 74}" height="${fin - s.y}" rx="20"/><circle cx="82" cy="${s.y + 38}" r="16"/><text x="82" y="${s.y + 44}" class="bl">${s.letra}</text><text x="110" y="${s.y + 44}" class="bt">${esc(s.titulo)}</text><text x="${110 + ancho + 16}" y="${s.y + 44}" class="bs">${esc(s.desc)}</text></g>`;
}).join('\n');

const svg = `<svg viewBox="0 0 ${W} ${H}" role="img" aria-label="Flujo completo de la IA de soporte, de que el cliente escribe a que el responsable valida la corrección">
<defs><marker id="p" viewBox="0 0 10 10" refX="8.5" refY="5" markerWidth="6.5" markerHeight="6.5" orient="auto-start-reverse"><path d="M0,0.5 L10,5 L0,9.5 z" class="punta"/></marker></defs>
${bandas}
<g class="fl" marker-end="url(#p)">${lines.join('')}</g>
${out.join('\n')}
${labels.join('\n')}
</svg>`;

const anclas = secciones.map((s) => `<span class="ancla" id="cap-${s.letra.toLowerCase()}" style="top:${(s.y / H * 100).toFixed(3)}%"></span>`).join('');
const html = `<!--LIENZO-->${svg}${anclas}<!--/LIENZO-->`;

const vista = path.join(__dirname, '..', 'views', 'ia-flujo.blade.php');
const src = fs.readFileSync(vista, 'utf8');
const a = src.indexOf('<!--LIENZO-->'), b = src.indexOf('<!--/LIENZO-->') + '<!--/LIENZO-->'.length;
if (a < 0 || b < a) { console.error('Faltan los marcadores <!--LIENZO--> en la vista'); process.exit(1); }
fs.writeFileSync(vista, src.slice(0, a) + html + src.slice(b));
console.log('ia-flujo.blade.php actualizado (' + html.length + ' bytes, alto ' + H + ')');
