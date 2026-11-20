const CSRF = document.querySelector('meta[name="csrf-token"]').content
const $ = (id) => document.getElementById(id)
const el = (tag, cls, text) => { const e = document.createElement(tag); if (cls) e.className = cls; if (text != null) e.textContent = text; return e }
const DEC = {
  responder: { n: 'Responder', d: 'La IA puede contestar sola', k: '1' },
  pedir_info: { n: 'Pedir dato', d: 'Falta hotel, código, foto…', k: '2' },
  derivar: { n: 'Derivar', d: 'Lo debe llevar una persona', k: '3' },
  descartar: { n: 'Descartar', d: 'No sirve para la prueba', k: '4' },
}
const ORDEN = ['responder', 'pedir_info', 'derivar', 'descartar']
let casos = [], actual = null, tmCom = null

async function api(url, method = 'GET', body) {
  const r = await fetch('/ia/casos/api' + url, { method, headers: { 'X-CSRF-TOKEN': CSRF, 'Accept': 'application/json', ...(body ? { 'Content-Type': 'application/json' } : {}) }, body: body ? JSON.stringify(body) : undefined })
  if (!r.ok) throw new Error('Error ' + r.status)
  return r.json()
}

const porId = (id) => casos.find((c) => c.id === id)
const necesitaApi = (c) => /\[\s*API\s*\]/i.test(c.comentario || '')
// [VERIF] en el comentario: «responder, pero solo tras verificar hotel y correo»; si la IA los pide, coincide.
const verif = (c) => /\[\s*VERIF\s*\]/i.test(c.comentario || '')
const difiere = (c) => c.ia && c.decision !== 'descartar' && c.ia.obtenido !== c.decision && !(verif(c) && c.decision === 'responder' && c.ia.obtenido === 'pedir_info')

// Filtros: cuatro grupos que SE COMBINAN (un caso tiene que cumplir uno de cada grupo). Cada botón enseña cuántos casos
// quedarían si lo eliges, teniendo en cuenta lo que ya has elegido en los otros grupos.
const FILTROS = [
  { k: 'conj', t: 'Conjunto de casos', ops: [
    ['todos', 'Todos', () => true, ''],
    ['fase2e', 'Fase 2e (para medir)', (c) => c.lote === 'fase2e', '20 casos reales nuevos (10 correo, 10 WhatsApp). Revísalos SIN ajustar reglas después: confirman si el prompt final llega al 70 % antes de subir la 1.6.1.'],
    ['fase2d', 'Fase 2d', (c) => c.lote === 'fase2d', 'Medido el 20-nov (final 65 %, original 66 %). Ya NO vale para medir: se miraron sus fallos para documentar stock de farmacias, guías por título, contraseñas y servicios.'],
    ['fase2c', 'Fase 2c', (c) => c.lote === 'fase2c', 'Medido el 20-nov. Ya NO vale para medir: se miraron sus fallos para arreglar el orden de decisión (garantías).'],
    ['fase2b', 'Fase 2b', (c) => c.lote === 'fase2b', 'Medido el 19-nov (prompt nuevo 51/76 = 67 %, original 50/76 = 66 %). Ya NO vale para medir si se ajustan reglas mirándolo.'],
    ['fase2', 'Fase 2', (c) => c.lote === 'fase2', 'Medido el 19-nov (59 % → 67 %). Ya NO vale para medir: se ajustaron 2 reglas mirando sus casos.'],
    ['seg4', 'Seguimientos 4', (c) => c.lote === 'seg4-20261118', '20 casos nuevos del 18-nov (2.º o 3.º mensaje del cliente). Para MEDIR: revísalos sin ajustar reglas después.'],
    ['seg3', 'Seguimientos 3', (c) => c.lote === 'seg3-20261118', 'Medido el 18-nov (77 %). Ya NO vale para medir: de él salieron las reglas del orden de decisión.'],
    ['seg2', 'Seguimientos 2', (c) => c.lote === 'seg2-20261118', 'Medido el 18-nov (50 %). Ya NO vale para medir: después se cambiaron reglas de material y presupuestos.'],
    ['seg', 'Seguimientos 1', (c) => c.lote === 'seg-20261117', 'Ya NO vale para medir: se ajustaron reglas mirando sus casos.'],
    ['lote5', 'Lote 5', (c) => c.lote === 'lote5-20261117', 'Ya NO vale para medir: se arreglaron a mano varios de sus casos.'],
    ['lote4', 'Lote 4', (c) => c.lote === 'lote4-20261117', 'Ya usado para ajustar las reglas: no vale para medir.'],
    ['lote3', 'Lote 3', (c) => c.lote === 'lote3-20261117', '20 casos del 17-nov, ya medidos.'],
    ['nuevos', 'Lote 2', (c) => c.lote === 'lote2-20261116', '30 casos del 16-nov.'],
  ] },
  { k: 'rev', t: 'Revisión', ops: [
    ['todos', 'Todos', () => true, ''],
    ['pend', 'Sin revisar', (c) => !c.revisado, ''],
    ['dud', '🚩 Dudosos', (c) => c.dudoso, 'Los que marcaste con «Dudoso: lo miro luego».'],
  ] },
  { k: 'dec', t: 'Tu decisión', ops: [
    ['todos', 'Todas', () => true, ''],
    ['responder', 'Responder', (c) => c.decision === 'responder' && c.revisado, ''],
    ['pedir_info', 'Pedir dato', (c) => c.decision === 'pedir_info' && c.revisado, ''],
    ['derivar', 'Derivar', (c) => c.decision === 'derivar' && c.revisado, ''],
    ['descartar', 'Descartados', (c) => c.decision === 'descartar', ''],
  ] },
  { k: 'ia', t: 'Qué hizo la IA', ops: [
    ['todos', 'Todo', () => true, ''],
    ['ind', '⚠ Contestó y no debía', (c) => c.ia && c.ia.obtenido === 'responder' && (c.decision === 'pedir_info' || c.decision === 'derivar'), 'Respuestas indebidas: lo más grave.'],
    ['dif', 'No coincide', (c) => difiere(c), ''],
    ['ok', 'Coincide', (c) => c.ia && c.decision !== 'descartar' && c.ia.obtenido === c.decision, ''],
    ['sinprobar', 'Sin probar', (c) => !c.ia, 'La IA aún no los ha probado.'],
  ] },
  { k: 'ext', t: 'Otros', ops: [
    ['todos', 'Todos', () => true, ''],
    ['api', 'Necesitan [API]', (c) => necesitaApi(c), ''],
    ['claude', 'Revalidados por Claude', (c) => !!c.nota, ''],
  ] },
]
const estadoFiltro = { conj: 'todos', rev: 'todos', dec: 'todos', ia: 'todos', ext: 'todos' }
const predicado = (g, v) => (g.ops.find((o) => o[0] === v) || g.ops[0])[2]
const cumple = (c, estado) => FILTROS.every((g) => predicado(g, estado[g.k])(c))
const hayFiltros = () => FILTROS.some((g) => estadoFiltro[g.k] !== 'todos')

function pintarFiltro() {
  const box = $('filtros'); if (!box) return
  const n = casos.filter((c) => cumple(c, estadoFiltro)).length
  const activos = FILTROS.filter((g) => estadoFiltro[g.k] !== 'todos').map((g) => g.ops.find((o) => o[0] === estadoFiltro[g.k])[1].replace(/^⚠ |^🚩 /, ''))
  $('fsum').textContent = (activos.length ? activos.join(' · ') : 'Todos los casos') + ' — ' + n + (n === 1 ? ' caso' : ' casos')
  const panel = $('fpanel'); panel.replaceChildren()
  FILTROS.forEach((g) => {
    const fila = el('div', 'frow'), grupo = el('div', 'fgrp'); grupo.setAttribute('role', 'group'); grupo.setAttribute('aria-label', g.t)
    fila.append(el('div', 'ftit', g.t))
    g.ops.forEach(([v, nombre, , pista]) => {
      const cuantos = casos.filter((c) => cumple(c, { ...estadoFiltro, [g.k]: v })).length
      const b = el('button', 'chip'); b.type = 'button'
      b.append(el('span', null, nombre), el('small', null, String(cuantos)))
      b.setAttribute('aria-pressed', String(estadoFiltro[g.k] === v))
      if (pista) b.title = pista
      if (cuantos === 0 && estadoFiltro[g.k] !== v) b.classList.add('vacio')
      b.onclick = () => { estadoFiltro[g.k] = v; cambioFiltro() }
      grupo.append(b)
    })
    fila.append(grupo)
    const pista = g.ops.find((o) => o[0] === estadoFiltro[g.k])[3]
    if (pista) fila.append(el('div', 'fhint', pista))
    panel.append(fila)
  })
  if (hayFiltros()) {
    const q = el('button', 'fquitar', 'Quitar todos los filtros'); q.type = 'button'
    q.onclick = () => { FILTROS.forEach((g) => { estadoFiltro[g.k] = 'todos' }); cambioFiltro() }
    panel.append(q)
  }
}

function cambioFiltro() {
  flushCom(); const v = visibles(); if (!v.find((c) => c.id === actual) && v[0]) actual = v[0].id
  pintarFiltro(); pintarCaso(); pintarLista()
}

function visibles() {
  // Buscador: por número de caso, código del ticket o palabras del asunto/mensaje.
  const q = ($('buscar') ? $('buscar').value : '').trim().toLowerCase().replace(/^#/, '')
  if (q) return casos.filter((c) => String(c.id).includes(q) || (c.code || '').toLowerCase().includes(q) || ((c.asunto || '') + ' ' + (c.pregunta || '')).toLowerCase().includes(q))
  return casos.filter((c) => c.id === actual || cumple(c, estadoFiltro))
}

function progreso() {
  const hechos = casos.filter((c) => c.revisado).length
  $('barra').style.width = (casos.length ? hechos / casos.length * 100 : 0) + '%'
  $('prog').textContent = hechos + ' de ' + casos.length + ' revisados'
}

function pintarLista() {
  const box = $('lista'); box.replaceChildren()
  visibles().forEach((c) => {
    const b = el('button', 'item'); b.setAttribute('role', 'listitem')
    if (c.id === actual) b.setAttribute('aria-current', 'true')
    const dot = el('span', 'dot'); dot.style.background = 'var(--c-' + c.decision + ')'
    const tag = el('span', 'tag', { responder: 'R', pedir_info: 'P', derivar: 'D', descartar: '✕' }[c.decision]); tag.style.color = 'var(--c-' + c.decision + ')'
    b.append(dot, tag, el('span', 'num', '#' + c.id), el('span', 't', (c.asunto || c.pregunta).slice(0, 60)), el('span', 'df', (necesitaApi(c) ? 'API ' : '') + (difiere(c) ? 'IA≠' : '')), el('span', 'ck', c.dudoso ? '🚩' : c.revisado ? '✓' : ''))
    b.title = DEC[c.decision].n + (c.revisado ? ' · revisado' : '')
    b.onclick = () => ir(c.id)
    box.append(b)
  })
  const sel = box.querySelector('[aria-current="true"]'); if (sel) sel.scrollIntoView({ block: 'nearest' })
}

// Cada bloque se puede minimizar; la preferencia es por tipo (cliente / persona / IA) y vale para todos los casos.
const minimizado = (k) => { try { return localStorage.getItem('casos-min-' + k) === '1' } catch (e) { return false } }
const guardarMin = (k, v) => { try { localStorage.setItem('casos-min-' + k, v ? '1' : '0') } catch (e) {} }

function bloque(cls, titulo, texto, badge, tecla) {
  // Sin renglones vacíos de relleno (los correos traen \r\n y líneas con solo espacios).
  texto = String(texto).replace(/\r/g, '').replace(/[ \t ]+\n/g, '\n').replace(/\n{3,}/g, '\n\n').trim()
  const b = el('div', 'blk ' + cls), h = el('button', 'bh'), chev = el('span', 'chev')
  h.type = 'button'
  h.append(chev, el('span', null, titulo))
  if (badge) { const s = el('span', 'badge', DEC[badge].n); s.style.background = 'var(--c-' + badge + ')'; h.append(s) }
  if (tecla) h.append(el('kbd', null, tecla))
  const prev = el('div', 'prev', texto.replace(/\s+/g, ' ').trim())
  const aplicar = (min) => { b.classList.toggle('min', min); h.setAttribute('aria-expanded', String(!min)); chev.textContent = min ? '▸' : '▾' }
  h.onclick = () => { const min = !b.classList.contains('min'); guardarMin(cls, min); aplicar(min) }
  aplicar(minimizado(cls))
  b.append(h, prev, el('div', 'txt', texto)); return b
}

// Los botones de decisión se crean UNA vez: al decidir solo cambian de estado (no se repinta nada ni se mueve el scroll).
function crearBotones() {
  const g = $('botones')
  ORDEN.forEach((k) => {
    const b = el('button', 'dbtn'); b.type = 'button'; b.dataset.k = k; b.style.setProperty('--k', 'var(--c-' + k + ')')
    b.append(el('i'), el('b', null, DEC[k].n), el('kbd', null, DEC[k].k), el('small', null, DEC[k].d))
    b.onclick = () => marcar(k)
    g.append(b)
  })
}
function pintarBotones(c) {
  document.querySelectorAll('.dbtn').forEach((b) => b.setAttribute('aria-pressed', String(!!c && b.dataset.k === c.decision)))
  $('dudoso').setAttribute('aria-pressed', String(!!c && !!c.dudoso))
}
function alternarDudoso() { const c = porId(actual); if (!c) return; c.dudoso = !c.dudoso; pintarBotones(c); guardar(c, false) }

// Propuesta de la IA (ai:prerrellenar) para un caso aún sin revisar: no cuenta como revisado hasta que se acepta o se corrige.
function pintarProp(c) {
  const box = $('prop'); box.replaceChildren()
  const p = c && !c.revisado ? c.propuesta : null
  box.hidden = !p
  if (!p) return
  const cab = el('div', 'ph', 'Propuesta de la IA'), s = el('span', 'badge', DEC[p.decision].n)
  s.style.background = 'var(--c-' + p.decision + ')'; cab.append(s, el('kbd', null, 'P'))
  cab.lastChild.style.cssText = 'margin-left:auto;font:11px ui-monospace,monospace;border:1px solid var(--line);border-radius:5px;padding:0 6px;text-transform:none'
  const b = el('button', 'btn', 'Usar esta propuesta'); b.type = 'button'; b.onclick = usarPropuesta
  box.append(cab, el('div', 'pt', p.comentario || '(sin comentario)'), b)
}
function usarPropuesta() {
  const c = porId(actual); if (!c || !c.propuesta) return
  c.decision = c.propuesta.decision; c.comentario = c.propuesta.comentario || ''
  $('com').value = c.comentario; pintarBotones(c); guardar(c); pintarProp(c)
}

function etiquetarHistorial(t) {
  return String(t).split(/\n\n(?=CLIENTE: |EQUIPO: )/)
    .map((x) => x.replace(/^CLIENTE: /, '▸ CLIENTE\n').replace(/^EQUIPO: /, '▸ EQUIPO\n'))
    .join('\n\n')
}

function pintarCaso() {
  const c = porId(actual), izq = $('colL'), der = $('colR'); izq.replaceChildren(); der.replaceChildren()
  if (!c) { izq.append(el('div', 'empty', 'No hay casos que mostrar con este filtro.')); $('titulo').textContent = 'Sin casos'; $('com').value = ''; pintarBotones(null); return }
  const pos = casos.indexOf(c) + 1
  $('titulo').textContent = c.asunto || 'Sin asunto'
  $('meta').textContent = 'Caso ' + pos + ' de ' + casos.length + ' · ' + c.canal + ' · nº ' + c.id + (c.code ? ' · ' + c.code : '') + (c.lote ? ' · ' + (c.lote === 'fase2e' ? 'FASE 2e (para medir)' : c.lote === 'fase2d' ? 'fase 2d (ya usado)' : c.lote === 'fase2c' ? 'fase 2c (ya usado)' : c.lote === 'fase2b' ? 'fase 2b (ya usado)' : c.lote === 'fase2' ? 'fase 2 (ya usado)' : c.lote.startsWith('lote5') ? 'lote 5 (para medir)' : c.lote.startsWith('lote4') ? 'lote 4 (ya usado)' : c.lote.startsWith('lote3') ? 'lote 3' : 'lote 2') : '')
  if (c.historial) izq.append(bloque('hist', 'Antes de este mensaje (solo contexto)', etiquetarHistorial(c.historial), null, null))
  izq.append(bloque('client', c.historial ? 'El cliente escribe ahora' : 'El cliente escribió', c.pregunta, null, 'C'))
  if (c.humana) izq.append(el('div', 'aviso-hum', 'Ojo: puede ser de antes de migrar a la app nueva (entonces solo el equipo podía hacer muchas cosas). Si es así, marca [ANTIGUO] y decide por lo que hoy puede hacer el hotel.'))
  izq.append(bloque('human', c.historial ? 'Lo que respondió una persona A ESTE MENSAJE' : 'Lo que respondió una persona', c.humana, null, 'H'))
  if (c.ia) der.append(bloque('ia', 'Lo que hizo la IA (última prueba' + (c.ia.cuando ? ' · ' + c.ia.cuando : '') + ')', c.ia.texto || '(No escribió nada: dijo «no sé», es decir, lo deja a una persona.)', c.ia.obtenido, 'I'))
  if (c.nota) der.append(bloque('nota', 'Nota de Claude', c.nota, null, null))
  else der.append(el('div', 'empty', 'La IA aún no ha probado este caso.'))
  pintarBotones(c); pintarProp(c)
  $('com').value = c.comentario || ''; pintarEtiquetas()
  $('estado').textContent = c.revisado ? 'Revisado ✓' : ''
  $('read').scrollTop = 0; izq.scrollTop = 0; der.scrollTop = 0   // solo al cambiar de caso
  $('ant').disabled = pos === 1
}

async function guardar(c, revisado = true) {
  if (revisado) c.revisado = true
  $('estado').textContent = 'Guardando…'
  try { await api('/' + c.id, 'POST', { decision: c.decision, comentario: c.comentario || '', revisado: c.revisado, dudoso: !!c.dudoso }); if (c.id === actual) $('estado').textContent = 'Guardado ✓' }
  catch (e) { $('estado').textContent = 'No se pudo guardar: ' + e.message }
  progreso(); pintarFiltro(); pintarLista()
  if (c.id === actual) pintarProp(c)   // una vez revisado, la propuesta desaparece
}

// El comentario se guarda solo (a los 0,6 s de dejar de escribir) y, antes de cambiar de caso, al momento.
function flushCom() {
  if (!tmCom) return
  clearTimeout(tmCom); tmCom = null
  const c = porId(actual); if (c) { c.comentario = $('com').value; guardar(c, false) }
}
// Etiquetas rápidas: pulsar añade (o quita) [API] / [VERIF] al principio del comentario, sin escribirlas.
function pintarEtiquetas() {
  const t = $('com').value
  document.querySelectorAll('.etq').forEach((b) => b.setAttribute('aria-pressed', new RegExp('\\[\\s*' + b.dataset.etq + '\\s*\\]', 'i').test(t) ? 'true' : 'false'))
}
document.querySelectorAll('.etq').forEach((b) => {
  b.onclick = () => {
    const re = new RegExp('\\[\\s*' + b.dataset.etq + '\\s*\\]\\s?', 'ig')
    const t = $('com').value
    $('com').value = re.test(t) ? t.replace(re, '').trim() : '[' + b.dataset.etq + '] ' + t
    pintarEtiquetas(); clearTimeout(tmCom); tmCom = setTimeout(flushCom, 0)
  }
})
$('com').oninput = () => { pintarEtiquetas(); clearTimeout(tmCom); tmCom = setTimeout(flushCom, 600) }
$('com').onblur = flushCom

function marcar(k) { const c = porId(actual); if (!c) return; c.decision = k; pintarBotones(c); guardar(c) }
function ir(id) { flushCom(); actual = id; pintarCaso(); pintarLista() }
function mover(d) {
  const v = visibles(), i = v.findIndex((c) => c.id === actual), n = v[i + d]
  if (n) ir(n.id)
}
function confirmarYSeguir() {
  flushCom()
  const c = porId(actual); if (!c) return
  if (!c.revisado) guardar(c)
  const v = visibles(), i = v.findIndex((x) => x.id === actual)
  const sig = v[i + 1] || v.find((x) => !x.revisado && x.id !== actual)
  if (sig) ir(sig.id)
}

document.addEventListener('keydown', (e) => {
  const t = e.target.tagName
  if (t === 'TEXTAREA') {
    if (e.key === 'Escape') { e.preventDefault(); e.target.blur() }
    else if (e.key === 'Enter' && (e.ctrlKey || e.metaKey)) { e.preventDefault(); confirmarYSeguir() }
    return
  }
  if (t === 'INPUT') { if (e.key === 'Escape') e.target.blur(); return }   // el buscador: escribir no debe disparar atajos
  if (t === 'SELECT' || e.ctrlKey || e.metaKey || e.altKey) return
  const k = ORDEN.find((x) => DEC[x].k === e.key)
  if (k) { e.preventDefault(); marcar(k) }
  else if (e.key === 'Enter') { e.preventDefault(); confirmarYSeguir() }
  else if (e.key.toLowerCase() === 'p') { e.preventDefault(); usarPropuesta() }
  else if (e.key.toLowerCase() === 'd') { e.preventDefault(); alternarDudoso() }
  else if (e.key.toLowerCase() === 'n') { e.preventDefault(); $('com').focus() }
  else if (['c', 'h', 'i'].includes(e.key.toLowerCase())) {   // C / H / I: minimiza o abre ese bloque
    const cls = { c: 'client', h: 'human', i: 'ia' }[e.key.toLowerCase()]
    const b = document.querySelector('.blk.' + cls + ' .bh'); if (b) { e.preventDefault(); b.click() }
  }
  else if (e.key === 'ArrowRight') { e.preventDefault(); mover(1) }
  else if (e.key === 'ArrowLeft') { e.preventDefault(); mover(-1) }
})
$('sig').onclick = confirmarYSeguir
$('dudoso').onclick = alternarDudoso
$('ant').onclick = () => mover(-1)
$('buscar').oninput = () => { const v = visibles(); if (v.length && !v.find((c) => c.id === actual)) { flushCom(); actual = v[0].id; pintarCaso() } pintarLista() }

;(async () => {
  crearBotones()
  try {
    casos = await api('/lista')
    const primero = casos.find((c) => !c.revisado) || casos[0]
    actual = primero ? primero.id : null
    pintarFiltro(); progreso(); pintarLista(); pintarCaso()
  } catch (e) { $('titulo').textContent = 'No se pudo cargar: ' + e.message }
})()
