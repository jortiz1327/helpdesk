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
const difiere = (c) => c.ia && c.decision !== 'descartar' && c.ia.obtenido !== c.decision

function visibles() {
  const f = $('filtro').value
  return casos.filter((c) => c.id === actual || (f === 'todos' || (f === 'pend' && !c.revisado) || (f === 'dif' && difiere(c)) || (f === 'desc' && c.decision === 'descartar')))
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
    b.append(dot, el('span', 't', (c.asunto || c.pregunta).slice(0, 60)), el('span', 'df', difiere(c) ? 'IA≠' : ''), el('span', 'ck', c.revisado ? '✓' : ''))
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
function pintarBotones(c) { document.querySelectorAll('.dbtn').forEach((b) => b.setAttribute('aria-pressed', String(!!c && b.dataset.k === c.decision))) }

function pintarCaso() {
  const c = porId(actual), col = $('caso'); col.replaceChildren()
  if (!c) { col.append(el('div', 'empty', 'No hay casos que mostrar con este filtro.')); $('titulo').textContent = 'Sin casos'; $('com').value = ''; pintarBotones(null); return }
  const pos = casos.indexOf(c) + 1
  $('titulo').textContent = c.asunto || 'Sin asunto'
  $('meta').textContent = 'Caso ' + pos + ' de ' + casos.length + ' · ' + c.canal
  col.append(bloque('client', 'El cliente escribió', c.pregunta, null, 'C'))
  col.append(bloque('human', 'Lo que respondió una persona', c.humana, null, 'H'))
  if (c.ia) col.append(bloque('ia', 'Lo que hizo la IA (última prueba) →', c.ia.texto || '(No escribió nada: dijo «no sé», es decir, lo deja a una persona.)', c.ia.obtenido, 'I'))
  pintarBotones(c)
  $('com').value = c.comentario || ''
  $('estado').textContent = c.revisado ? 'Revisado ✓' : ''
  $('read').scrollTop = 0   // solo al cambiar de caso
  $('ant').disabled = pos === 1
}

async function guardar(c, revisado = true) {
  if (revisado) c.revisado = true
  $('estado').textContent = 'Guardando…'
  try { await api('/' + c.id, 'POST', { decision: c.decision, comentario: c.comentario || '', revisado: c.revisado }); if (c.id === actual) $('estado').textContent = 'Guardado ✓' }
  catch (e) { $('estado').textContent = 'No se pudo guardar: ' + e.message }
  progreso(); pintarLista()
}

// El comentario se guarda solo (a los 0,6 s de dejar de escribir) y, antes de cambiar de caso, al momento.
function flushCom() {
  if (!tmCom) return
  clearTimeout(tmCom); tmCom = null
  const c = porId(actual); if (c) { c.comentario = $('com').value; guardar(c, false) }
}
$('com').oninput = () => { clearTimeout(tmCom); tmCom = setTimeout(flushCom, 600) }
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
  if (t === 'SELECT' || e.ctrlKey || e.metaKey || e.altKey) return
  const k = ORDEN.find((x) => DEC[x].k === e.key)
  if (k) { e.preventDefault(); marcar(k) }
  else if (e.key === 'Enter') { e.preventDefault(); confirmarYSeguir() }
  else if (e.key.toLowerCase() === 'n') { e.preventDefault(); $('com').focus() }
  else if (['c', 'h', 'i'].includes(e.key.toLowerCase())) {   // C / H / I: minimiza o abre ese bloque
    const cls = { c: 'client', h: 'human', i: 'ia' }[e.key.toLowerCase()]
    const b = document.querySelector('.blk.' + cls + ' .bh'); if (b) { e.preventDefault(); b.click() }
  }
  else if (e.key === 'ArrowRight') { e.preventDefault(); mover(1) }
  else if (e.key === 'ArrowLeft') { e.preventDefault(); mover(-1) }
})
$('sig').onclick = confirmarYSeguir
$('ant').onclick = () => mover(-1)
$('filtro').onchange = () => { flushCom(); const v = visibles(); if (!v.find((c) => c.id === actual) && v[0]) actual = v[0].id; pintarCaso(); pintarLista() }

;(async () => {
  crearBotones()
  try {
    casos = await api('/lista')
    const primero = casos.find((c) => !c.revisado) || casos[0]
    actual = primero ? primero.id : null
    if (!casos.some((c) => c.ia)) { const o = $('filtro').querySelector('[value=dif]'); o.disabled = true }
    progreso(); pintarLista(); pintarCaso()
  } catch (e) { $('titulo').textContent = 'No se pudo cargar: ' + e.message }
})()
