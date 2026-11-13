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

function bloque(cls, titulo, texto, badge) {
  const b = el('div', 'blk ' + cls), h = el('h3', null, titulo)
  if (badge) { const s = el('span', 'badge', DEC[badge].n); s.style.background = 'var(--c-' + badge + ')'; h.append(s) }
  b.append(h, el('div', 'txt', texto)); return b
}

function pintarCaso() {
  const c = porId(actual), col = $('caso'); col.replaceChildren()
  if (!c) { col.append(el('div', 'empty', 'No hay casos que mostrar con este filtro.')); $('titulo').textContent = 'Sin casos'; return }
  const pos = casos.indexOf(c) + 1
  $('titulo').textContent = (c.asunto || 'Sin asunto')
  $('meta').textContent = 'Caso ' + pos + ' de ' + casos.length + ' · ' + c.canal
  col.append(bloque('client', 'El cliente escribió', c.pregunta))
  col.append(bloque('human', 'Lo que respondió una persona', c.humana))
  if (c.ia) col.append(bloque('ia', 'Lo que hizo la IA (última prueba) →', c.ia.texto || '(No escribió nada: dijo «no sé», es decir, lo deja a una persona.)', c.ia.obtenido))

  const q = el('div', 'blk'); q.style.borderColor = 'var(--line)'
  q.append(el('h3', null, '¿Qué debería hacer la IA en este caso?'))
  const g = el('div', 'decide')
  ORDEN.forEach((k) => {
    const b = el('button', 'dbtn'); b.style.setProperty('--k', 'var(--c-' + k + ')'); b.setAttribute('aria-pressed', String(c.decision === k))
    const kb = el('kbd', null, DEC[k].k); b.append(kb, el('b', null, DEC[k].n), el('small', null, DEC[k].d))
    b.onclick = () => marcar(k)
    g.append(b)
  })
  q.append(g)
  const t = el('textarea'); t.rows = 2; t.placeholder = 'Comentario (opcional): por qué, o qué cambiarías'; t.value = c.comentario || ''; t.setAttribute('aria-label', 'Comentario')
  t.oninput = () => { clearTimeout(tmCom); tmCom = setTimeout(() => { c.comentario = t.value; guardar(c, false) }, 600) }
  t.onblur = () => { clearTimeout(tmCom); if (t.value !== (c.comentario || '')) { c.comentario = t.value; guardar(c, false) } }
  q.append(el('div', null, '')); q.lastChild.style.height = '10px'; q.append(t)
  col.append(q)
  $('scroll').scrollTop = 0
  $('ant').disabled = casos.indexOf(c) === 0
}

async function guardar(c, revisado = true) {
  if (revisado) c.revisado = true
  $('estado').textContent = 'Guardando…'
  try { await api('/' + c.id, 'POST', { decision: c.decision, comentario: c.comentario || '', revisado: c.revisado }); $('estado').textContent = 'Guardado ✓' }
  catch (e) { $('estado').textContent = 'No se pudo guardar: ' + e.message }
  progreso(); pintarLista()
}

function marcar(k) { const c = porId(actual); if (!c) return; c.decision = k; guardar(c); pintarCaso() }
function ir(id) { actual = id; pintarCaso(); pintarLista() }
function mover(d) {
  const v = visibles(), i = v.findIndex((c) => c.id === actual), n = v[i + d]
  if (n) ir(n.id)
}
function confirmarYSeguir() {
  const c = porId(actual); if (!c) return
  if (!c.revisado) guardar(c)
  const v = visibles(), i = v.findIndex((x) => x.id === actual)
  const sig = v[i + 1] || v.find((x) => !x.revisado && x.id !== actual)
  if (sig) ir(sig.id)
}

document.addEventListener('keydown', (e) => {
  const t = e.target.tagName
  if (t === 'TEXTAREA' || t === 'SELECT' || e.ctrlKey || e.metaKey || e.altKey) return
  const k = ORDEN.find((x) => DEC[x].k === e.key)
  if (k) { e.preventDefault(); marcar(k) }
  else if (e.key === 'Enter') { e.preventDefault(); confirmarYSeguir() }
  else if (e.key === 'ArrowRight') { e.preventDefault(); mover(1) }
  else if (e.key === 'ArrowLeft') { e.preventDefault(); mover(-1) }
})
$('sig').onclick = confirmarYSeguir
$('ant').onclick = () => mover(-1)
$('filtro').onchange = () => { const v = visibles(); if (!v.find((c) => c.id === actual) && v[0]) actual = v[0].id; pintarCaso(); pintarLista() }

;(async () => {
  try {
    casos = await api('/lista')
    const primero = casos.find((c) => !c.revisado) || casos[0]
    actual = primero ? primero.id : null
    if (!casos.some((c) => c.ia)) { const o = $('filtro').querySelector('[value=dif]'); o.disabled = true }
    progreso(); pintarLista(); pintarCaso()
  } catch (e) { $('titulo').textContent = 'No se pudo cargar: ' + e.message }
})()
