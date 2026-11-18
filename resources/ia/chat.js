const CSRF = document.querySelector('meta[name="csrf-token"]').content
const $ = (id) => document.getElementById(id)
const ESPERA = 20 // segundos sin que el cliente escriba antes de que el agente conteste (como en WhatsApp)
let chatId = null, mensajes = [], ocupado = false, corrigiendo = null, resta = 0, reloj = null

async function api(url, method = 'GET', body) {
  const r = await fetch('/ia/api' + url, { method, headers: { 'X-CSRF-TOKEN': CSRF, 'Accept': 'application/json', ...(body ? { 'Content-Type': 'application/json' } : {}) }, body: body ? JSON.stringify(body) : undefined })
  if (!r.ok) { let m = 'Error ' + r.status; try { const j = await r.json(); m = j.error || j.message || m } catch (e) {} throw new Error(m) }
  return r.json()
}
const el = (tag, cls, text) => { const e = document.createElement(tag); if (cls) e.className = cls; if (text != null) e.textContent = text; return e }

async function cargarLista() {
  const lista = await api('/chats')
  const box = $('lista'); box.replaceChildren()
  lista.forEach((c) => {
    const b = el('button', 'item', c.title); b.setAttribute('role', 'listitem'); b.title = c.title
    if (c.id === chatId) b.setAttribute('aria-current', 'true')
    b.onclick = () => abrir(c.id)
    box.append(b)
  })
}

async function abrir(id, push = true) {
  const d = await api('/chats/' + id)
  clearInterval(reloj); resta = 0
  chatId = id; mensajes = d.messages; corrigiendo = null
  if (push) history.pushState({}, '', '/ia/' + id)
  $('titulo').textContent = d.chat.title
  $('meta').textContent = [d.chat.contact_name, d.chat.contact_email].filter(Boolean).join(' · ')
  $('borrar').hidden = false; $('composer').hidden = false
  pintar(); cargarLista(); $('texto').focus()
  if (mensajes.length && mensajes[mensajes.length - 1].role === 'user') esperar()
}

function pintar(escribiendo = false) {
  const box = $('msgs'); box.replaceChildren()
  if (!mensajes.length) box.append(el('div', 'empty', 'Escribe el primer mensaje del cliente abajo.'))
  const ultimo = [...mensajes].reverse().find((m) => m.role === 'assistant' && !m.superseded)
  mensajes.forEach((m) => {
    if (m.role === 'user') {
      const r = el('div', 'row user'); r.append(el('div', 'who', 'Cliente'), el('div', 'bubble', m.body)); box.append(r); return
    }
    const r = el('div', 'row bot' + (m.superseded ? ' old' : ''))
    r.append(el('div', 'who', 'Agente IA' + (m.superseded ? ' · sustituida' : '')))
    if (m.status === 'ready') r.append(el('div', 'bubble', m.body))
    else if (m.status === 'abstained') r.append(el('div', 'bubble warn', 'Derivó a una persona: no se atrevió a contestar con fiabilidad.'))
    else r.append(el('div', 'bubble bad', 'La IA no pudo contestar' + (m.error ? ': ' + m.error : '.')))
    if (!m.superseded) r.append(...valoracion(m, m === ultimo))
    else if (m.rating) r.append(el('div', 'verdict ' + m.rating, m.rating === 'up' ? '✔ Validada' : '✘ Corregida: ' + m.correction))   // la corrección sigue a la vista aunque ya se haya generado otra respuesta
    box.append(r)
  })
  if (resta > 0 && !escribiendo) { const r = el('div', 'row bot'); const w = el('div', 'bubble warn', 'El agente espera ' + resta + ' s por si el cliente sigue escribiendo… '); const b = el('button', 'btn sm', 'Responder ya'); b.onclick = () => responder(); w.append(b); r.append(w); box.append(r) }
  if (escribiendo) { const r = el('div', 'row bot'); const t = el('div', 'bubble'); t.innerHTML = '<span class="typing"><i></i><i></i><i></i></span>'; r.append(el('div', 'who', 'Agente IA'), t); box.append(r) }
  const s = $('scroll'); s.scrollTop = s.scrollHeight
}

function valoracion(m, esUltimo) {
  const out = []
  if (m.status === 'failed') { out.push(botones([['Reintentar', () => otra()]])); return out }
  if (m.rating) {
    out.push(el('div', 'verdict ' + m.rating, m.rating === 'up' ? '✔ Validada' : '✘ Corregida: ' + m.correction))
    if (esUltimo && m.rating === 'down') out.push(botones([['Generar de nuevo con esta indicación', () => otra(), true]]))
    return out
  }
  if (corrigiendo === m.id) {
    const f = el('div', 'fix'); const l = el('label', null, m.status === 'abstained' ? '¿Sabía contestar? Indica cómo debía responder.' : '¿Qué falló y cómo debe responder? Basta el criterio; se guarda como memoria de la IA.')
    const t = el('textarea'); t.rows = 3; t.setAttribute('aria-label', 'Corrección')
    const a = botones([['Guardar corrección', () => valorar(m.id, 'down', t.value), true], ['Cancelar', () => { corrigiendo = null; pintar() }]])
    f.append(l, t, a); out.push(f); setTimeout(() => t.focus(), 0); return out
  }
  const b = [[m.status === 'abstained' ? '✔ Bien derivado' : '✔ Bien', () => valorar(m.id, 'up'), true], [m.status === 'abstained' ? '✘ Sí sabía' : '✘ Corregir', () => { corrigiendo = m.id; pintar() }]]
  if (esUltimo) b.push(['Otra', () => otra()])
  out.push(botones(b)); return out
}
function botones(lista) {
  const a = el('div', 'acts')
  lista.forEach(([t, fn, p]) => { const b = el('button', 'btn sm' + (p ? ' primary' : ''), t); b.onclick = fn; a.append(b) })
  return a
}

async function valorar(id, rating, correction) {
  try { const d = await api('/messages/' + id + '/rate', 'POST', { rating, correction }); mensajes = d.messages; corrigiendo = null; pintar() }
  catch (e) { alert(e.message) }
}
async function pedir(url, body) {
  if (ocupado) return; ocupado = true; $('enviar').disabled = true; pintar(true)
  try { const d = await api(url, 'POST', body); mensajes = d.messages } catch (e) { alert(e.message) }
  ocupado = false; $('enviar').disabled = false; pintar(); cargarLista()
}
const otra = () => pedir('/chats/' + chatId + '/regenerate')
function esperar() {
  clearInterval(reloj); resta = ESPERA
  reloj = setInterval(() => { resta--; if (resta <= 0) { clearInterval(reloj); responder() } else pintar() }, 1000)
}
function responder() {
  clearInterval(reloj); resta = 0
  if (chatId && mensajes.length && mensajes[mensajes.length - 1].role === 'user') pedir('/chats/' + chatId + '/reply')
  else pintar()
}
async function enviar() {
  const t = $('texto'); const v = t.value.trim(); if (!v || !chatId || ocupado) return
  mensajes.push({ id: 0, role: 'user', body: v }); t.value = ''; t.style.height = 'auto'
  clearInterval(reloj); resta = 0; pintar()
  try { const d = await api('/chats/' + chatId + '/send', 'POST', { text: v }); mensajes = d.messages } catch (e) { alert(e.message); return }
  pintar(); cargarLista(); esperar()
}

$('enviar').onclick = enviar
$('texto').addEventListener('keydown', (e) => { if (e.key === 'Enter' && !e.shiftKey && !e.isComposing) { e.preventDefault(); enviar() } })
$('texto').addEventListener('input', (e) => { e.target.style.height = 'auto'; e.target.style.height = Math.min(e.target.scrollHeight, 180) + 'px' })
$('nuevo').onclick = () => { $('form').classList.toggle('open'); $('n-name').focus() }
$('form').onsubmit = async (e) => {
  e.preventDefault()
  try { const d = await api('/chats', 'POST', { name: $('n-name').value.trim() || null, email: $('n-email').value.trim() || null }); $('form').reset(); $('form').classList.remove('open'); await cargarLista(); abrir(d.id) }
  catch (err) { alert(err.message) }
}
$('borrar').onclick = async () => {
  if (!chatId || !confirm('¿Borrar esta conversación y sus correcciones guardadas?')) return
  await api('/chats/' + chatId, 'DELETE'); chatId = null; mensajes = []
  history.pushState({}, '', '/ia'); $('titulo').textContent = 'Elige o empieza una conversación'; $('meta').textContent = ''
  $('borrar').hidden = true; $('composer').hidden = true; $('msgs').replaceChildren(el('div', 'empty', 'Conversación borrada.')); cargarLista()
}
window.addEventListener('popstate', () => { const m = location.pathname.match(/\/ia\/(\d+)/); if (m) abrir(+m[1], false) })

cargarLista().then(() => { const m = location.pathname.match(/\/ia\/(\d+)/); if (m) abrir(+m[1], false) })
