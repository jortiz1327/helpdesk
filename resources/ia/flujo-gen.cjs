// Genera el SVG del flujo completo de la IA de soporte y lo pega en resources/views/ia-flujo.blade.php
// (entre <!--LIENZO--> y <!--/LIENZO-->). Uso desde la raíz del proyecto:  node resources/ia/flujo-gen.cjs
// Edita los nodos AQUÍ, no a mano en la vista.
const fs = require('fs');
const path = require('path');

const W = 1400;
const NUM = { '①': '1', '②': '2', '③': '3', '④': '4', '⑤': '5', '⑥': '6', '⑦': '7', '⑧': '8', '⑨': '9', '⑩': '10' };
const esc = (s) => s.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
const out = [], lines = [], labels = [], secciones = [];
const N = {};

/* ---------- iconos dibujados (rejilla 24, trazo 2) ---------- */
const ICONOS = {
  up: '<path d="M7 10v12"/><path d="M15 5.88 14 10h5.83a2 2 0 0 1 1.92 2.56l-2.33 8A2 2 0 0 1 17.5 22H4a2 2 0 0 1-2-2v-8a2 2 0 0 1 2-2h2.76a2 2 0 0 0 1.79-1.11L12 2a3.13 3.13 0 0 1 3 3.88Z"/>',
  down: '<g transform="translate(0,24) scale(1,-1)"><path d="M7 10v12"/><path d="M15 5.88 14 10h5.83a2 2 0 0 1 1.92 2.56l-2.33 8A2 2 0 0 1 17.5 22H4a2 2 0 0 1-2-2v-8a2 2 0 0 1 2-2h2.76a2 2 0 0 0 1.79-1.11L12 2a3.13 3.13 0 0 1 3 3.88Z"/></g>',
  mail: '<rect x="2" y="4" width="20" height="16" rx="2"/><path d="m22 7-8.97 5.7a1.94 1.94 0 0 1-2.06 0L2 7"/>',
  loop: '<path d="M17 2l4 4-4 4"/><path d="M3 11V9a3 3 0 0 1 3-3h15"/><path d="M7 22l-4-4 4-4"/><path d="M21 13v2a3 3 0 0 1-3 3H3"/>',
};
const icono = (n, x, y, s = 0.72) => `<g class="ic" transform="translate(${x},${y}) scale(${s})">${ICONOS[n]}</g>`;

/* ---------- primitivas ---------- */
const TS = 17;   // alto de línea
function anchors(id, cx, y, w, h) {
  N[id] = { cx, y, w, h, t: [cx, y], b: [cx, y + h], l: [cx - w / 2, y + h / 2], r: [cx + w / 2, y + h / 2] };
}
function texto(cx, y0, title, ls, dx = 0) {
  let s = '', y = y0;
  if (title) { s += `<text x="${cx + dx}" y="${y}" class="t">${esc(title)}</text>`; y += 21; }
  for (const l of ls) { s += `<text x="${cx}" y="${y}" class="s">${esc(l)}</text>`; y += TS; }
  return s;
}
function insignia(x, y, n) {
  return `<circle class="bd" cx="${x}" cy="${y}" r="${n.length > 1 ? 13 : 12}"/><text x="${x}" y="${y + 4.2}" class="b">${n}</text>`;
}
// Nodo normal (una acción o un resultado)
function box(id, cx, y, w, kind, title, ls, o = {}) {
  const mm = /^([①-⑩])\s+(.*)$/.exec(title || '');
  if (mm) { o = { ...o, badge: NUM[mm[1]] }; title = mm[2]; }
  const h = o.h || ((title ? 40 : 20) + ls.length * TS + 8);
  anchors(id, cx, y, w, h);
  let ic = '', dx = 0;
  if (o.icon) { dx = 12; ic = icono(o.icon, cx - (title.length * 7.6) / 2 - 12, y + 10); }
  out.push(`<g data-n="${id}" tabindex="0" style="--k:var(--k-${kind})" class="n${o.cls ? ' ' + o.cls : ''}"><rect x="${cx - w / 2}" y="${y}" width="${w}" height="${h}" rx="11"/>${ic}${texto(cx, y + 26, title, ls, dx)}${o.badge ? insignia(cx - w / 2, y, o.badge) : ''}</g>`);
  return N[id];
}
// Pregunta (decisión): cápsula con un «?» a la izquierda
function dia(id, cx, y, w, kind, ls, o = {}) {
  const h = o.h || Math.max(56, ls.length * TS + 26);
  anchors(id, cx, y, w, h);
  const y0 = y + h / 2 - (ls.length - 1) * TS / 2 + 4.2;
  const qx = cx - w / 2 + 24;
  out.push(`<g data-n="${id}" tabindex="0" style="--k:var(--k-${kind})" class="n q"><rect x="${cx - w / 2}" y="${y}" width="${w}" height="${h}" rx="${h / 2}"/><circle class="qm" cx="${qx}" cy="${y + h / 2}" r="11"/><text x="${qx}" y="${y + h / 2 + 4.6}" class="qt">?</text>${ls.map((l, i) => `<text x="${cx + 14}" y="${y0 + i * TS}" class="s q">${esc(l)}</text>`).join('')}${o.badge ? insignia(cx - w / 2 + 6, y + 4, o.badge) : ''}</g>`);
  return N[id];
}
// Conector: «viene de / vuelve a»
function chip(id, cx, y, w, kind, texto_, sinIcono) {
  const h = 32;
  anchors(id, cx, y, w, h);
  out.push(`<g data-n="${id}" tabindex="0" style="--k:var(--k-${kind})" class="chip"><rect x="${cx - w / 2}" y="${y}" width="${w}" height="${h}" rx="16"/>${sinIcono ? '' : icono('loop', cx - w / 2 + 12, y + 8, 0.66)}<text x="${cx + (sinIcono ? 0 : 10)}" y="${y + 20}" >${esc(texto_)}</text></g>`);
  return N[id];
}
const P = (pts) => 'M' + pts.map((p) => p.join(',')).join(' L');
function arr(pts, o = {}) {
  lines.push(`<path ${o.noarrow ? 'marker-end="none" ' : ''}d="${P(pts)}"/>`);
}
// «Sí» / «No»: pastilla; textos largos: nota con halo
function lab(t, x, y, anchor = 'start', cls = 'et') {
  if (t === 'Sí' || t === 'No') {
    const w = 28, x0 = anchor === 'end' ? x - w + 4 : x - 4;
    labels.push(`<g class="pill ${t === 'Sí' ? 'si' : 'no'}"><rect x="${x0}" y="${y - 12}" width="${w}" height="18" rx="9"/><text x="${x0 + w / 2}" y="${y + 0.5}">${t}</text></g>`);
  } else {
    labels.push(`<text x="${x}" y="${y}" class="${cls}" text-anchor="${anchor}">${esc(t)}</text>`);
  }
}
function seccion(letra, titulo, desc, yTop) { secciones.push({ letra, titulo, desc, y: yTop }); return yTop + 78; }

const C = 590, L = 185, A = 900, T = 1215, R = 1000, RD = 1060, BUS = 1338;
const GAP = 34, IN = 32;
let y;

/* ===================== A. PUERTAS ===================== */
y = seccion('A', 'Puertas de entrada', 'Antes de gastar nada, se comprueba si toca consultar a la IA', 16);
box('n1', C, y, 340, 'cli', '① Cliente escribe', ['por correo, WhatsApp, formulario o portal', 'el mensaje llega al ticket']);
chip('c9', 255, N.n1.y + N.n1.h / 2 - 16, 210, 'cli', 'Vuelve aquí desde el paso 9');
arr([N.c9.r, N.n1.l]);
y = N.n1.y + N.n1.h + GAP;
box('n2', C, y, 340, 'ag', '② El agente pide la sugerencia', ['abre el ticket y pulsa «Sugerir»', 'la IA no actúa por su cuenta']);
arr([N.n1.b, N.n2.t]);
y = N.n2.y + N.n2.h + GAP;

const puertas = [
  ['d1', ['¿La IA está activada', 'en Ajustes?'], 'no', 'No aparece el botón', ['no sale ningún dato hacia fuera']],
  ['d2', ['¿Ya se sugirió algo para', 'este mensaje del cliente?'], 'mem', 'Se enseña la que ya estaba', ['no se vuelve a consultar a la IA', '«Regenerar» pide una nueva', '→ sigue en el paso 9']],
  ['d3', ['¿Se ha gastado ya lo previsto', 'para este mes? (50 USD)'], 'no', 'Se avisa al agente', ['«Contesta tú; la IA vuelve el día 1»', 'no se consulta a la IA', '→ lo contesta el agente']],
  ['d4', ['¿El cliente solo ha mandado', 'audios de WhatsApp?'], 'sys', 'Respuesta fija, sin IA', ['«Ahora mismo no podemos escuchar', 'audios…» · no cuesta nada', '→ sigue en el paso 9']],
];
let prev = N.n2;
for (const [id, ls, kind, tt, tl] of puertas) {
  dia(id, C, y, 310, 'sys', ls);
  arr([prev.b, N[id].t]);
  const bid = id + 'x';
  const h = 40 + tl.length * TS + 8;
  box(bid, R, N[id].y + N[id].h / 2 - h / 2, 330, kind, tt, tl);
  arr([N[id].r, N[bid].l]);
  lab('Sí', N[id].r[0] + 10, N[id].r[1] - 7);
  lab('No', C + 12, N[id].b[1] + 20);
  prev = N[id];
  y = Math.max(N[id].y + N[id].h, N[bid].y + N[bid].h) + GAP;
}
const bottomA = prev.b[1] + 30;

/* ===================== B. CONTEXTO ===================== */
y = seccion('B', 'Contexto', 'Lo que la IA necesita saber: memoria, fotos y datos del hotel', bottomA + 18);
box('b1', C, y, 400, 'sys', '③ Prepara la conversación', ['lee lo que ha escrito el cliente y lo que se le contestó', '(sin notas internas) y busca su última pregunta']);
arr([prev.b, N.b1.t]);
const yl = N.b1.y + N.b1.h + 30;
const yTop = yl + 28;

box('m1', L, yTop, 250, 'mem', '④ Memoria y guía', ['preguntas frecuentes', 'respuestas de ejemplo del equipo', 'plantillas de respuesta del equipo', 'casos parecidos y manuales', 'correcciones de los agentes (paso 10)']);
box('m2', L, N.m1.y + N.m1.h + IN, 250, 'mem', 'Memoria limpia', ['sin firmas, correos ni teléfonos', 'contraseñas tapadas', 'lo antiguo se marca como ANTIGUO', '(sirve para entender, no para copiar)']);
arr([N.m1.b, N.m2.t]);

dia('p1', C, yTop, 260, 'sys', ['¿El cliente ha enviado', 'fotos?'], { badge: '4' });
box('p2', C, N.p1.y + N.p1.h + IN, 270, 'sys', 'Lee el código de barras', ['en el propio servidor (la foto no sale)', 'para saber de qué etiqueta se trata']);
arr([N.p1.b, N.p2.t]);
lab('Sí', C + 12, N.p1.b[1] + 22);

dia('h1', A, yTop, 280, 'api', ['¿Podemos consultar el panel', 'de hoteles? (solo lectura)'], { badge: '4' });
box('h1x', T, N.h1.y + N.h1.h / 2 - 28, 200, 'no', 'Sin datos del hotel', ['la IA se los pregunta', 'al cliente']);
arr([N.h1.r, N.h1x.l]); lab('No', N.h1.r[0] + 10, N.h1.r[1] - 7);
box('h2', A, N.h1.y + N.h1.h + IN, 280, 'api', 'Busca el hotel', ['por el correo del ticket, el nombre', 'del mensaje o la firma, o la etiqueta', '(listado en caché; si no consta,', 'quizá no sea un hotel)']);
arr([N.h1.b, N.h2.t]); lab('Sí', A + 12, N.h1.b[1] + 22);
dia('h3', A, N.h2.y + N.h2.h + IN, 280, 'api', ['¿Reconocemos el correo como', 'usuario de la aplicación?']);
arr([N.h2.b, N.h3.t]);
box('h3x', T, N.h3.y + N.h3.h / 2 - 46, 200, 'no', 'Correo no reconocido', ['no se da información de', 'hoteles; si el cliente nombra', 'el hotel: solo sus antenas']);
arr([N.h3.r, N.h3x.l]); lab('No', N.h3.r[0] + 10, N.h3.r[1] - 7);
dia('h4', A, N.h3.y + N.h3.h + IN, 280, 'api', ['¿El hotel que dice el cliente', 'es el de ese correo?']);
arr([N.h3.b, N.h4.t]); lab('Sí', A + 12, N.h3.b[1] + 22);
box('h4x', T, N.h4.y + N.h4.h / 2 - 36, 200, 'no', 'No es el mismo', ['se le explica con tacto', 'y lo atiende una persona']);
arr([N.h4.r, N.h4x.l]); lab('No', N.h4.r[0] + 10, N.h4.r[1] - 7);
box('h5', A, N.h4.y + N.h4.h + IN, 280, 'api', 'Datos del hotel para la IA', ['su rol y si el usuario está activo', 'antenas conectadas y desconectadas', 'estado de la etiqueta']);
arr([N.h4.b, N.h5.t]); lab('Sí', A + 12, N.h4.b[1] + 22);

arr([N.b1.b, [C, yl]], { noarrow: true });
arr([[L, yl], [A, yl]], { noarrow: true });
arr([[L, yl], N.m1.t]);
arr([[C, yl], N.p1.t]);
arr([[A, yl], N.h1.t]);

const yBottomAll = Math.max(N.m2.y + N.m2.h, N.p2.y + N.p2.h, N.h5.y + N.h5.h);
const yMerge = yBottomAll + 34;
chip('sf', N.p1.l[0] - 85, N.p1.l[1] - 16, 120, 'sys', 'Sin fotos: sigue', true);
arr([N.p1.l, N.sf.r]);
lab('No', N.p1.l[0] - 5, N.p1.l[1] - 22, 'end');
arr([N.m2.b, [L, yMerge]], { noarrow: true });
arr([N.p2.b, [C, yMerge]], { noarrow: true });
arr([N.h5.b, [A, yMerge]], { noarrow: true });
arr([[L, yMerge], [A, yMerge]], { noarrow: true });
const bottomB = yMerge + 20;

/* ===================== C. DECISIÓN EN CÓDIGO ===================== */
y = seccion('C', 'Decisión en código', 'Lo evidente se decide sin la IA; luego se le dan instrucciones', bottomB + 18);
dia('d5', C, y, 290, 'sys', ['¿Es un caso tan claro que', 'no hace falta pensarlo?']);
arr([[C, yMerge], N.d5.t]);
box('c1', L, N.d5.y + N.d5.h / 2 - 40, 250, 'der', 'Pasa a una persona', ['con el cliente verificado: alérgenos,', 'tótems y garantías (salvo farmacias)']);
arr([N.d5.l, N.c1.r]); lab('Sí', N.d5.l[0] - 10, N.d5.l[1] - 7, 'end');
box('c2', 960, N.d5.y + N.d5.h / 2 - 40, 290, 'cli', 'Pedir hotel y correo', ['norma de la dirección: en un hotel sin correo', 'verificado, se verifica ANTES de todo', '(salvo material, presupuestos, farmacias,', 'tiendas y sitios que no son hoteles)']);
arr([N.d5.r, N.c2.l]); lab('Sí', N.d5.r[0] + 10, N.d5.r[1] - 7);
const yBar = Math.max(N.c1.y + N.c1.h, N.c2.y + N.c2.h, N.d5.y + N.d5.h) + 26;
box('pr', C, yBar + 34, 440, 'sys', '⑤ Prepara las instrucciones para la IA', ['las reglas del equipo + el orden de decisión', '+ lo que sabemos del cliente y del hotel', '+ «decisión ya tomada» si el caso era claro']);
arr([N.c1.b, [L, yBar], [960, yBar]], { noarrow: true });
arr([N.c2.b, [960, yBar]], { noarrow: true });
arr([N.d5.b, N.pr.t]);
lab('No: decide la IA', C + 12, N.d5.b[1] + 16);
box('ia', C, N.pr.y + N.pr.h + 44, 400, 'ai', '⑥ IA · Claude Haiku 5.5', ['lee la conversación y las instrucciones', 'sigue el orden de decisión (7 puntos)', 'y escribe una propuesta de respuesta'], { cls: 'ia' });
arr([N.pr.b, N.ia.t]); lab('una sola consulta (tiene un pequeño coste)', C + 12, N.pr.b[1] + 26);
const bottomC = N.ia.y + N.ia.h + 24;

/* ===================== D. ORDEN DE DECISIÓN ===================== */
y = seccion('D', 'Orden de decisión', 'La IA repasa estos puntos en orden: el primero que encaja manda', bottomC + 18);
const D = [
  ['o1', ['¿Es algo que siempre atiende', 'una persona? (alérgenos, tótems,', 'formaciones, fallos del contenido)'], 'der', 'Pasa a una persona', ['sin hacer preguntas al cliente', 'nota «Para el equipo» → Soporte']],
  ['o2', ['¿Es de material o de dinero?', '(pedido, presupuesto,', 'garantía o cambio de precio)'], 'der', 'Según el caso', ['material (pilas, soportes…) → escribir a postventa', '   con material, cantidad y foto', 'presupuesto que preparamos → «Asignar al depto.', '   de Pedidos y facturas.»; si pide el correo → postventa', 'garantía → la lleva el equipo de Garantías', 'garantía de farmacia → «la gestiona Expofarm»', 'cambio de precio en tienda → lo hace soporte']],
  ['o3', ['¿Lo tiene que hacer el equipo?', '(logo, tipos de letra,', 'importar platos de Excel, altas)'], 'der', 'Pasa a una persona', ['si ya sabemos el hotel, no se piden', 'más datos para pasarlo']],
  ['o4', ['¿Lo puede hacer el propio hotel?', '(usuarios, permisos, posiciones,', 'cambiar un plato, forzar el menú)'], 'ag', 'Se le explica cómo', ['con los pasos de la aplicación', '(Ajustes › Roles, Usuarios, Posiciones…)', 'si nadie del hotel puede → una persona']],
  ['o5', ['¿Es un problema de uso y aún', 'no sabemos quién escribe?'], 'cli', 'Se pide hotel y correo', ['como mucho esas dos preguntas', 'lo demás se pregunta después']],
  ['o6', ['¿Hay antenas sin conexión?', '(dato del panel de hoteles)'], 'ag', 'Se avisa a su personal técnico', ['que revise sus antenas', 'si ya se le dijo y sigue igual → una persona']],
  ['o7', ['¿Lo que sabemos trae la', 'respuesta o los pasos?'], 'ag', 'La IA responde', ['solo con lo que consta en la memoria', 'nunca inventa pasos, datos ni plazos']],
];
let prevD = N.ia, firstD = true;
const stubs = [];
for (const [id, ls, kind, tt, tl] of D) {
  const h = 40 + tl.length * TS + 8;
  dia(id, C, y, 330, 'sys', ls, { badge: String(D.findIndex((d) => d[0] === id) + 1), h: Math.max(ls.length * TS + 26, h) });
  arr([prevD.b, N[id].t]);
  if (!firstD) lab('No', C + 12, N[id].y - 7);
  firstD = false;
  const rid = id + 'x';
  box(rid, RD, N[id].y + N[id].h / 2 - h / 2, 370, kind, tt, tl);
  arr([N[id].r, N[rid].l]); lab('Sí', N[id].r[0] + 10, N[id].r[1] - 7);
  stubs.push(N[rid]);
  prevD = N[id];
  y = Math.max(N[id].y + N[id].h, N[rid].y + N[rid].h) + GAP;
}
box('o8', C, y, 340, 'no', 'Si no encaja o hay duda', ['pasa a una persona (o «no sé»)', 'mejor derivar que responder con dudas']);
arr([prevD.b, N.o8.t]); lab('No', C + 12, prevD.b[1] + 20);
const yBusD = N.o8.y + N.o8.h + 30;
for (const s of stubs) arr([s.r, [BUS, s.r[1]]], { noarrow: true });
arr([[BUS, stubs[0].r[1]], [BUS, yBusD]], { noarrow: true });
arr([N.o8.b, [C, yBusD]], { noarrow: true });
arr([[BUS, yBusD], [C, yBusD]], { noarrow: true });
const bottomD = yBusD + 16;

/* ===================== E. SALIDA ===================== */
y = seccion('E', 'Salida', 'Qué pasa con la respuesta de la IA, y cuánto cuesta', bottomD + 18);
dia('e1', C, y, 290, 'ai', ['¿La IA ha podido contestar', 'sin fallos?'], { badge: '7', h: 82 });
arr([[C, yBusD], N.e1.t]);
box('e1x', R, N.e1.y + N.e1.h / 2 - 40, 340, 'no', 'No ha podido responder', ['aparece el botón «Reintentar»', 'si ya costó algo, se cuenta']);
arr([N.e1.r, N.e1x.l]); lab('No', N.e1.r[0] + 10, N.e1.r[1] - 7);
dia('e2', C, N.e1.y + N.e1.h + IN, 290, 'ai', ['¿La IA se atreve a contestar?', '(si no, dice «no sé»)'], { h: 82 });
arr([N.e1.b, N.e2.t]); lab('Sí', C + 12, N.e1.b[1] + 22);
box('e2x', R, N.e2.y + N.e2.h / 2 - 40, 340, 'no', 'La IA no sabe', ['no hay propuesta: lo lleva una persona', 'queda apuntado en Ajustes para documentarlo']);
arr([N.e2.r, N.e2x.l]); lab('No', N.e2.r[0] + 10, N.e2.r[1] - 7);
dia('e3', C, N.e2.y + N.e2.h + IN, 290, 'ai', ['¿La IA propone pasarlo', 'a una persona?'], { h: 112 });
arr([N.e2.b, N.e3.t]); lab('Sí', C + 12, N.e2.b[1] + 22);
box('e3x', R, N.e3.y + N.e3.h / 2 - 56, 340, 'der', 'Pasa a una persona', ['aviso «Para el equipo»:', '«Llévalo a Soporte, Garantías o Pedidos»', 'y un mensaje corto para el cliente', 'lo ve el agente; el cliente no']);
arr([N.e3.r, N.e3x.l]); lab('Sí', N.e3.r[0] + 10, N.e3.r[1] - 7);
box('e4', C, N.e3.y + N.e3.h + IN, 360, 'ag', 'Propuesta de respuesta', ['contesta, pide datos o indica dónde escribir', 'se muestra en el panel: no se envía sola']);
arr([N.e3.b, N.e4.t]); lab('No', C + 12, N.e3.b[1] + 22);
box('s1', C, N.e4.y + N.e4.h + 40, 440, 'sys', '⑧ Se guarda la sugerencia', ['y lo que ha costado: se suma al gasto del mes', 'aviso al llegar al 80 % y al 100 % de 50 USD', 'el panel de Ajustes muestra el gasto por día']);
arr([N.e4.b, N.s1.t]);
const yb = N.s1.y - 20;
for (const s of [N.e1x, N.e2x, N.e3x]) arr([s.r, [BUS, s.r[1]]], { noarrow: true });
arr([[BUS, N.e1x.r[1]], [BUS, yb]], { noarrow: true });
arr([[BUS, yb], [C, yb]], { noarrow: true });
const bottomE = N.s1.y + N.s1.h + 18;

/* ===================== F. AGENTE Y APRENDIZAJE ===================== */
y = seccion('F', 'Agente y aprendizaje', 'La persona decide; lo que corrige mejora al momento a la IA', bottomE + 18);
box('g1', C, y, 400, 'ag', '⑨ El agente ve la sugerencia', ['decide él: la IA nunca envía nada', 'aquí también llegan los casos de la sección A']);
arr([N.s1.b, N.g1.t]);
const yf = N.g1.y + N.g1.h + 28;
const fx = [185, 485, 785, 1085];
const fs2 = [
  ['f3', 'sys', 'Contesta sin la IA', ['si no hay propuesta o no le sirve', 'el cliente recibe su respuesta'], null],
  ['f2', 'ag', 'Copia y envía él', ['puede retocarla antes', 'se apunta como usada'], 'mail'],
  ['f1', 'ag', 'Útil', ['queda en el historial', 'para medir cuánto acierta la IA'], 'up'],
  ['f4', 'no', 'No sirve', ['escribe qué falló y cómo había', 'que responder · queda en el historial'], 'down'],
];
arr([N.g1.b, [C, yf]], { noarrow: true });
arr([[fx[0], yf], [fx[3], yf]], { noarrow: true });
fs2.forEach(([id, k, t, l, ic], i) => { box(id, fx[i], yf + 26, 260, k, t, l, { icon: ic }); arr([[fx[i], yf], N[id].t]); });
const yfEnd = Math.max(...fs2.map(([id]) => N[id].y + N[id].h));

// El cliente contesta: se vuelve al paso 1
chip('r1', 335, yfEnd + 30, 350, 'cli', 'El cliente contesta: se vuelve al paso 1');
arr([N.f3.b, [N.f3.cx, N.r1.y]]);
arr([N.f2.b, [N.f2.cx, N.r1.y]]);

// Valoración: la corrección pasa directa a la memoria (sin paso de validación)
box('v2', 1085, N.f4.y + N.f4.h + 40, 260, 'mem', '⑩ Pasa directo a la memoria', ['sin revisión previa; queda apuntado', 'quién, cuándo y qué corrigió']);
arr([N.f4.b, N.v2.t]);
chip('r2', 1085, N.v2.y + N.v2.h + 28, 260, 'mem', 'Vuelve a la memoria (paso 4)');
arr([N.v2.b, N.r2.t]);

const bottomF = Math.max(N.r2.y + N.r2.h, N.r1.y + N.r1.h) + 24;
const H = bottomF + 16;

/* ---------- bandas de capítulo (detrás de todo) ---------- */
const bandas = secciones.map((s, i) => {
  // Bandas contiguas (sin hueco): las flechas que bajan de un capítulo al siguiente ya no se cortan. Solo se redondean las esquinas de fuera.
  const ultima = i === secciones.length - 1, fin = ultima ? bottomF : secciones[i + 1].y;
  const x0 = 40, x1 = W - 30, r = 18, rt = i === 0 ? r : 0, rb = ultima ? r : 0;
  const d = `M${x0 + rt} ${s.y}H${x1 - rt}` + (rt ? `Q${x1} ${s.y} ${x1} ${s.y + rt}` : "") + `V${fin - rb}` + (rb ? `Q${x1} ${fin} ${x1 - rb} ${fin}` : "") + `H${x0 + rb}` + (rb ? `Q${x0} ${fin} ${x0} ${fin - rb}` : "") + `V${s.y + rt}` + (rt ? `Q${x0} ${s.y} ${x0 + rt} ${s.y}` : "") + "Z";
  return `<g class="banda"><path d="${d}"/>${i > 0 ? `<line class="sep" x1="${x0 + 24}" x2="${x1 - 24}" y1="${s.y}" y2="${s.y}"/>` : ""}<circle cx="72" cy="${s.y + 32}" r="15"/><text x="72" y="${s.y + 37.5}" class="bl">${s.letra}</text><text x="98" y="${s.y + 31}" class="bt">${esc(s.titulo)}</text><text x="98" y="${s.y + 52}" class="bs">${esc(s.desc)}</text></g>`;
}).join('\n');

const svg = `<svg viewBox="0 0 ${W} ${H}" role="img" aria-label="Flujo completo de la IA de soporte, de que el cliente escribe a que la corrección del agente pasa a la memoria">
<defs><marker id="p" viewBox="0 0 10 10" refX="8.5" refY="5" markerWidth="6" markerHeight="6" orient="auto-start-reverse"><path d="M0,0.5 L10,5 L0,9.5 z" class="punta"/></marker></defs>
${bandas}
<g class="fl" marker-end="url(#p)">${lines.join('')}</g>
${out.join('\n')}
${labels.join('\n')}
</svg>`;

const anclas = secciones.map((s) => `<span class="ancla" id="cap-${s.letra.toLowerCase()}" style="top:${(s.y / H * 100).toFixed(3)}%"></span>`).join('');
const html = `<!--LIENZO-->${svg}${anclas}<!--/LIENZO-->`;

const INFO = require('./flujo-info.cjs');
const infoJson = JSON.stringify(INFO).replace(/</g, '\\u003c');
for (const nombre of ['ia-flujo.blade.php', 'ia-flujo-agentes.blade.php']) {
  const vista = path.join(__dirname, '..', 'views', nombre);
  if (!fs.existsSync(vista)) continue;
  const src = fs.readFileSync(vista, 'utf8');
  const a = src.indexOf('<!--LIENZO-->'), b = src.indexOf('<!--/LIENZO-->') + '<!--/LIENZO-->'.length;
  if (a < 0 || b < a) { console.error('Faltan los marcadores <!--LIENZO--> en ' + nombre); process.exit(1); }
  let nuevo = src.slice(0, a) + html + src.slice(b);
  nuevo = nuevo.replace(/<!--INFO-->[\s\S]*?<!--\/INFO-->/, () => '<!--INFO--><script type="application/json" id="flujo-info">' + infoJson + '</script><!--/INFO-->');
  fs.writeFileSync(vista, nuevo);
  console.log(nombre + ' actualizado (' + html.length + ' bytes, alto ' + H + ')');
}
if (process.env.IDS) { for (const k of Object.keys(N)) console.log(k); }
