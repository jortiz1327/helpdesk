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
y = seccion('A', 'Puertas de entrada', 'Antes de gastar nada, se comprueba si toca consultar a la IA', 20);
box('n1', C, y, 400, 'cli', '① Cliente escribe', ['por correo, WhatsApp, formulario o portal', 'el mensaje llega al ticket']);
y = N.n1.y + N.n1.h + GAP;
box('n2', C, y, 400, 'ag', '② El agente pide la sugerencia', ['abre el ticket y pulsa «Sugerir»', 'la IA no actúa por su cuenta']);
arr([N.n1.b, N.n2.t]);
y = N.n2.y + N.n2.h + GAP;

const puertas = [
  ['d1', ['¿La IA está activada', 'en Ajustes?'], 'no', 'No aparece el botón', ['no sale ningún dato hacia fuera']],
  ['d2', ['¿Ya se sugirió algo para', 'este mensaje del cliente?'], 'mem', 'Se enseña la que ya estaba', ['no se vuelve a consultar a la IA', '«Regenerar» pide una nueva', '→ sigue en el paso 9']],
  ['d3', ['¿Se ha gastado ya lo previsto', 'para este mes? (50 USD)'], 'no', 'Se avisa al agente', ['«Contesta tú; la IA vuelve el día 1»', 'no se consulta a la IA', '→ lo contesta el agente']],
  ['d4', ['¿El cliente solo ha mandado', 'audios de WhatsApp?'], 'sys', 'Respuesta fija, sin IA', ['«Ahora mismo no podemos escuchar audios…»', 'no cuesta nada', '→ sigue en el paso 9']],
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
y = seccion('B', 'Contexto', 'Se reúne lo que la IA necesita saber: lo que ya sabemos, las fotos y los datos del hotel', bottomA + 22);
box('b1', C, y, 440, 'sys', '③ Prepara la conversación', ['lee lo que ha escrito el cliente y lo que se le contestó', '(sin notas internas) y busca su última pregunta']);
arr([prev.b, N.b1.t]);
const yl = N.b1.y + N.b1.h + 36;
const yTop = yl + 32;

box('m1', L, yTop, 290, 'mem', '④ Memoria y guía', ['preguntas frecuentes', 'respuestas de ejemplo del equipo', 'casos parecidos y manuales', 'correcciones ya validadas']);
box('m2', L, N.m1.y + N.m1.h + 40, 290, 'mem', 'Memoria limpia', ['sin firmas, correos ni teléfonos', 'contraseñas tapadas', 'lo antiguo se marca como ANTIGUO', '(sirve para entender, no para copiar)']);
arr([N.m1.b, N.m2.t]);

dia('p1', C, yTop, 340, 'sys', ['¿El cliente ha enviado', 'fotos?'], { badge: '4' });
box('p2', C, N.p1.y + N.p1.h + 40, 320, 'sys', 'Lee el código de barras', ['en el propio servidor (la foto no sale)', 'para saber de qué etiqueta se trata']);
arr([N.p1.b, N.p2.t]);
lab('Sí', C + 14, N.p1.b[1] + 26);

dia('h1', A, yTop, 350, 'api', ['¿Podemos consultar el panel', 'de hoteles? (solo lectura)'], { badge: '4' });
box('h1x', T, N.h1.y + N.h1.h / 2 - 30, 230, 'no', 'Sin datos del hotel', ['la IA se los pregunta al cliente']);
arr([N.h1.r, N.h1x.l]); lab('No', N.h1.r[0] + 12, N.h1.r[1] - 8);
box('h2', A, N.h1.y + N.h1.h + 40, 350, 'api', 'Busca el hotel', ['por el correo del ticket, el nombre que', 'sale en el mensaje o la firma, o la etiqueta']);
arr([N.h1.b, N.h2.t]); lab('Sí', A + 14, N.h1.b[1] + 26);
dia('h3', A, N.h2.y + N.h2.h + 40, 350, 'api', ['¿Reconocemos el correo como', 'usuario de la aplicación?']);
arr([N.h2.b, N.h3.t]);
box('h3x', T, N.h3.y + N.h3.h / 2 - 52, 230, 'no', 'Correo no reconocido', ['no se da información de hoteles', 'si el cliente nombra el hotel:', 'solo cuántas antenas tiene']);
arr([N.h3.r, N.h3x.l]); lab('No', N.h3.r[0] + 12, N.h3.r[1] - 8);
dia('h4', A, N.h3.y + N.h3.h + 40, 350, 'api', ['¿El hotel que dice el cliente', 'es el de ese correo?']);
arr([N.h3.b, N.h4.t]); lab('Sí', A + 14, N.h3.b[1] + 26);
box('h4x', T, N.h4.y + N.h4.h / 2 - 42, 230, 'no', 'No es el mismo', ['se le explica con tacto', 'y lo atiende una persona']);
arr([N.h4.r, N.h4x.l]); lab('No', N.h4.r[0] + 12, N.h4.r[1] - 8);
box('h5', A, N.h4.y + N.h4.h + 40, 350, 'api', 'Datos del hotel para la IA', ['su rol y si el usuario está activo', 'antenas conectadas y desconectadas', 'estado de la etiqueta']);
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
y = seccion('C', 'Decisión en código', 'Lo evidente se decide sin la IA; después se le preparan las instrucciones', bottomB + 22);
dia('d5', C, y, 380, 'sys', ['¿Es un caso tan claro que', 'no hace falta pensarlo?']);
arr([[C, yMerge], N.d5.t]);
box('c1', L, N.d5.y + N.d5.h / 2 - 52, 290, 'der', 'Pasa a una persona', ['alérgenos, tótems y', 'garantías (salvo farmacias)']);
arr([N.d5.l, N.c1.r]); lab('Sí', N.d5.l[0] - 12, N.d5.l[1] - 8, 'end');
box('c2', 1060, N.d5.y + N.d5.h / 2 - 52, 340, 'cli', 'Pedir hotel y correo', ['primer mensaje con un problema de uso', 'y aún no sabemos quién escribe']);
arr([N.d5.r, N.c2.l]); lab('Sí', N.d5.r[0] + 12, N.d5.r[1] - 8);
const yBar = Math.max(N.c1.y + N.c1.h, N.c2.y + N.c2.h, N.d5.y + N.d5.h) + 30;
box('pr', C, yBar + 40, 500, 'sys', '⑤ Prepara las instrucciones para la IA', ['las reglas del equipo + el orden de decisión', '+ lo que sabemos del cliente y del hotel', '+ «decisión ya tomada» si el caso era claro']);
arr([N.c1.b, [L, yBar], [1060, yBar]], { noarrow: true });
arr([N.c2.b, [1060, yBar]], { noarrow: true });
arr([N.d5.b, N.pr.t]);
lab('No: decide la IA', C + 14, N.d5.b[1] + 18);
box('ia', C, N.pr.y + N.pr.h + 50, 460, 'ai', '⑥ IA · Claude Haiku 5.5', ['lee la conversación y las instrucciones', 'sigue el orden de decisión (7 puntos)', 'y escribe una propuesta de respuesta'], { cls: 'ia' });
arr([N.pr.b, N.ia.t]); lab('una sola consulta (tiene un pequeño coste)', C + 14, N.pr.b[1] + 30);
const bottomC = N.ia.y + N.ia.h + 30;

/* ===================== D. ORDEN DE DECISIÓN ===================== */
y = seccion('D', 'Orden de decisión', 'La IA repasa estos puntos en orden: el primero que encaja manda', bottomC + 22);
const D = [
  ['o1', ['¿Es algo que siempre atiende', 'una persona? (alérgenos, tótems,', 'formaciones, fallos del contenido)'], 'der', 'Pasa a una persona', ['sin hacer preguntas al cliente', 'nota «Para el equipo» → Soporte']],
  ['o2', ['¿Es de material o de dinero?', '(pedido, presupuesto,', 'garantía o cambio de precio)'], 'der', 'Según el caso', ['material (pilas, soportes…) → escribir a postventa', '   con material, cantidad y foto', 'presupuesto → «Asignar al departamento de', '   Pedidos y facturas.»', 'garantía → la lleva el equipo de Garantías', 'garantía de farmacia → «la gestiona Expofarm»', 'cambio de precio en tienda → lo hace soporte']],
  ['o3', ['¿Lo tiene que hacer el equipo?', '(menú de un buffet, logos,', 'plantillas, altas de etiquetas)'], 'der', 'Pasa a una persona', ['si ya sabemos el hotel, no se piden', 'más datos para pasarlo']],
  ['o4', ['¿Lo puede hacer el propio hotel?', '(usuarios, permisos, posiciones,', 'cambiar un plato, forzar el menú)'], 'ag', 'Se le explica cómo', ['con los pasos de la aplicación', '(Ajustes › Roles, Usuarios, Posiciones…)', 'si nadie del hotel puede → una persona']],
  ['o5', ['¿Es un problema de uso y aún', 'no sabemos quién escribe?'], 'cli', 'Se pide hotel y correo', ['como mucho esas dos preguntas', 'lo demás se pregunta después']],
  ['o6', ['¿Hay antenas sin conexión?', '(dato del panel de hoteles)'], 'ag', 'Se avisa a su personal técnico', ['que revise sus antenas', 'si ya se le dijo y sigue igual → una persona']],
  ['o7', ['¿Lo que sabemos trae la', 'respuesta o los pasos?'], 'ag', 'La IA responde', ['solo con lo que consta en la memoria', 'nunca inventa pasos, datos ni plazos']],
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
box('o8', C, y, 400, 'no', 'Si no encaja o hay duda', ['pasa a una persona (o «no sé»)', 'mejor derivar que responder con dudas']);
arr([prevD.b, N.o8.t]); lab('No', C + 14, prevD.b[1] + 22);
stubs.push(N.o8);
const yBusD = N.o8.y + N.o8.h + 40;
for (const s of stubs.slice(0, -1)) arr([s.r, [BUS, s.r[1]]], { noarrow: true });
arr([[BUS, stubs[0].r[1]], [BUS, yBusD]], { noarrow: true });
arr([N.o8.b, [C, yBusD]], { noarrow: true });
arr([[BUS, yBusD], [C, yBusD]], { noarrow: true });
const bottomD = yBusD + 20;

/* ===================== E. SALIDA ===================== */
y = seccion('E', 'Salida', 'Qué pasa con la respuesta de la IA, y cuánto cuesta', bottomD + 22);
dia('e1', C, y, 400, 'ai', ['¿La IA ha podido contestar', 'sin fallos?'], { badge: '7' });
arr([[C, yBusD], N.e1.t]);
box('e1x', R, N.e1.y + N.e1.h / 2 - 48, 410, 'no', 'No ha podido responder', ['aparece el botón «Reintentar»', 'si ya costó algo, se cuenta']);
arr([N.e1.r, N.e1x.l]); lab('No', N.e1.r[0] + 12, N.e1.r[1] - 8);
dia('e2', C, N.e1.y + N.e1.h + 40, 400, 'ai', ['¿La IA se atreve a contestar?', '(si no, dice «no sé»)']);
arr([N.e1.b, N.e2.t]); lab('Sí', C + 14, N.e1.b[1] + 26);
box('e2x', R, N.e2.y + N.e2.h / 2 - 48, 410, 'no', 'La IA no sabe', ['no hay propuesta: lo lleva una persona', 'queda apuntado en Ajustes para documentarlo']);
arr([N.e2.r, N.e2x.l]); lab('No', N.e2.r[0] + 12, N.e2.r[1] - 8);
dia('e3', C, N.e2.y + N.e2.h + 40, 400, 'ai', ['¿La IA propone pasarlo', 'a una persona?']);
arr([N.e2.b, N.e3.t]); lab('Sí', C + 14, N.e2.b[1] + 26);
box('e3x', R, N.e3.y + N.e3.h / 2 - 64, 410, 'der', 'Pasa a una persona', ['aviso «Para el equipo»:', '«Llévalo a Soporte, Garantías o Pedidos» + motivo', 'y un mensaje corto para el cliente', 'lo ve el agente; el cliente no']);
arr([N.e3.r, N.e3x.l]); lab('Sí', N.e3.r[0] + 12, N.e3.r[1] - 8);
box('e4', C, N.e3.y + N.e3.h + 40, 420, 'ag', 'Propuesta de respuesta', ['contesta, pide datos o indica dónde escribir', 'se muestra en el panel: no se envía sola']);
arr([N.e3.b, N.e4.t]); lab('No', C + 14, N.e3.b[1] + 26);
box('s1', C, N.e4.y + N.e4.h + 46, 500, 'sys', '⑧ Se guarda la sugerencia', ['y lo que ha costado: se suma al gasto del mes', 'aviso al llegar al 80 % y al 100 % de 50 USD', 'el panel de Ajustes muestra el gasto por día']);
arr([N.e4.b, N.s1.t]);
const yb = N.s1.y - 24;
for (const s of [N.e1x, N.e2x, N.e3x]) arr([s.r, [BUS, s.r[1]]], { noarrow: true });
arr([[BUS, N.e1x.r[1]], [BUS, yb]], { noarrow: true });
arr([[BUS, yb], [C, yb]], { noarrow: true });
const bottomE = N.s1.y + N.s1.h + 22;

/* ===================== F. AGENTE Y APRENDIZAJE ===================== */
y = seccion('F', 'Agente y aprendizaje', 'La persona decide; lo que se valida hace que la IA acierte más', bottomE + 22);
box('g1', C, y, 460, 'ag', '⑨ El agente ve la sugerencia', ['decide él: la IA nunca envía nada', 'aquí también llegan los casos de la sección A']);
arr([N.s1.b, N.g1.t]);
const yf = N.g1.y + N.g1.h + 34;
const fx = [215, 555, 895, 1235];
const fs2 = [
  ['f1', 'ag', 'Útil', ['se apunta para medir cuánto', 'acierta la IA'], 'up'],
  ['f2', 'ag', 'Copia y envía él', ['puede retocarla antes', 'se apunta como usada'], 'mail'],
  ['f3', 'sys', 'Contesta sin la IA', ['si no hay propuesta o no le sirve', 'el cliente recibe su respuesta'], null],
  ['f4', 'no', 'No sirve', ['escribe qué falló y cómo', 'había que responder'], 'down'],
];
arr([N.g1.b, [C, yf]], { noarrow: true });
arr([[fx[0], yf], [fx[3], yf]], { noarrow: true });
fs2.forEach(([id, k, t, l, ic], i) => { box(id, fx[i], yf + 32, 300, k, t, l, { icon: ic }); arr([[fx[i], yf], N[id].t]); });
const yfEnd = Math.max(...fs2.map(([id]) => N[id].y + N[id].h));

dia('v1', 1235, yfEnd + 44, 340, 'mem', ['¿Un responsable da por', 'buena la corrección?'], { badge: '10' });
arr([N.f4.b, N.v1.t]);
box('v1x', 895, N.v1.y + N.v1.h / 2 - 34, 300, 'no', 'Se descarta', ['la IA no la verá nunca']);
arr([N.v1.l, N.v1x.r]); lab('No', N.v1.l[0] - 12, N.v1.l[1] + 24, 'end');
box('v2', 1235, N.v1.y + N.v1.h + 42, 300, 'mem', 'Pasa a la memoria', ['como corrección validada', 'la IA la usará en las próximas consultas']);
arr([N.v1.b, N.v2.t]); lab('Sí', 1249, N.v1.b[1] + 26);

const yRet = Math.max(N.v2.y + N.v2.h, N.v1x.y + N.v1x.h) + 46;
arr([N.f2.b, [N.f2.cx, yRet], [14, yRet], [14, N.n1.y + N.n1.h / 2], N.n1.l], { vuelta: 'cli' });
lab('el cliente contesta → vuelve al paso 1', 32, yRet - 12);
arr([N.v2.l, [38, N.v2.l[1]], [38, N.m1.y + N.m1.h / 2], N.m1.l], { vuelta: 'mem' });
lab('lo validado vuelve a la memoria de la IA', 660, N.v2.l[1] - 12, 'start', 'et vu');
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
