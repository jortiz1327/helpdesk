/*
 * Cliente del PORTAL público. Nada de token de agente: la identidad es el «pase»
 * que se guarda tras acertar el código, y viaja en la cabecera X-Portal-Token.
 */
const TOKEN_KEY = 'portal_token'
export const getPass = () => localStorage.getItem(TOKEN_KEY) || ''
export const setPass = (t) => (t ? localStorage.setItem(TOKEN_KEY, t) : localStorage.removeItem(TOKEN_KEY))

/*
 * Tokens de UN SOLO ticket: los que devuelve «crear» para poder ver y responder ESE
 * ticket sin código. Se guardan por código (mapa code→token). Caducan en el servidor
 * (24 h); aquí solo se recuerdan para no volver a pedir el código nada más crear.
 */
const TT_KEY = 'portal_ttokens'
const ttMap = () => { try { return JSON.parse(localStorage.getItem(TT_KEY) || '{}') } catch { return {} } }
export const getTicketToken = (code) => ttMap()[code] || ''
export const setTicketToken = (code, token) => { const m = ttMap(); m[code] = token; localStorage.setItem(TT_KEY, JSON.stringify(m)) }
export const dropTicketToken = (code) => { const m = ttMap(); delete m[code]; localStorage.setItem(TT_KEY, JSON.stringify(m)) }

/*
 * «Último visto» por ticket: la fecha del mensaje más reciente que el cliente ya ha
 * visto (se guarda al abrir el detalle). Sirve para marcar «respuesta nueva» en la
 * lista cuando soporte contesta y el cliente aún no lo ha abierto.
 */
const SEEN_KEY = 'portal_seen'
const seenMap = () => { try { return JSON.parse(localStorage.getItem(SEEN_KEY) || '{}') } catch { return {} } }
export const getSeen = (code) => seenMap()[code] || ''
export const markSeen = (code, iso) => {
  const m = seenMap()
  if (iso && (!m[code] || new Date(iso) > new Date(m[code]))) { m[code] = iso; localStorage.setItem(SEEN_KEY, JSON.stringify(m)) }
}

async function call(action, { method = 'GET', body, form, query, ttoken } = {}) {
  const qs = new URLSearchParams({ action, ...(query || {}) }).toString()
  const headers = {}
  const pass = getPass()
  if (pass) headers['X-Portal-Token'] = pass
  if (ttoken) headers['X-Ticket-Token'] = ttoken
  // JSON o multipart (con archivos). Con FormData NO se pone Content-Type: el
  // navegador añade el «boundary» solo; ponerlo a mano rompe la subida.
  let payload
  if (form) payload = form
  else if (body) { headers['Content-Type'] = 'application/json'; payload = JSON.stringify(body) }

  let res
  try {
    res = await fetch(`/api/portal.php?${qs}`, { method, headers, body: payload })
  } catch {
    return { ok: false, error: 'Sin conexión. Revisa tu internet e inténtalo de nuevo.' }
  }
  let data = {}
  try { data = await res.json() } catch { /* respuesta no-JSON */ }

  // Pase caducado: se limpia para que la UI vuelva a pedir el código.
  if (res.status === 401 && data.reauth) { setPass(''); data.reauth = true }
  return data
}

export const portal = {
  requestCode: (email) => call('request-code', { method: 'POST', body: { email } }),
  verifyCode: (email, code) => call('verify-code', { method: 'POST', body: { email, code } }),
  categories: () => call('categories'),
  // FAQ del portal: listar publicadas, sumar vista, votar utilidad (todo público).
  faqs: () => call('faqs'),
  info: () => call('info'),   // Centro de atención (horario, correos, teléfonos)
  estado: (code) => call('ticket-status', { query: { code } }),   // estado por número (solo lectura)
  faqView: (id) => call('faq-view', { method: 'POST', body: { id } }),
  faqVote: (id, helpful) => call('faq-vote', { method: 'POST', body: { id, helpful } }),
  me: () => call('me'),
  tickets: () => call('tickets'),
  ticket: (code) => call('ticket', { query: { code }, ttoken: getTicketToken(code) }),
  resolve: (code) => call('resolve', { method: 'POST', body: { code }, ttoken: getTicketToken(code) }),
  // Crear es público (sin código): manda el correo en el formulario. Al crearse,
  // guarda el token que abre ese ticket, para verlo/responderlo sin pedir el código.
  create: async ({ files = [], ...fields }) => {
    let r
    if (!files.length) r = await call('create', { method: 'POST', body: fields })
    else {
      const fd = new FormData()
      Object.entries(fields).forEach(([k, v]) => v != null && fd.append(k, v))
      files.forEach((f) => fd.append('files[]', f))
      r = await call('create', { method: 'POST', form: fd })
    }
    if (r.ok && r.code && r.token) setTicketToken(r.code, r.token)
    return r
  },
  reply: async (code, body, files = []) => {
    let r
    if (!files.length) r = await call('reply', { method: 'POST', body: { code, body }, ttoken: getTicketToken(code) })
    else {
      const fd = new FormData()
      fd.append('code', code); fd.append('body', body)
      files.forEach((f) => fd.append('files[]', f))
      r = await call('reply', { method: 'POST', form: fd, ttoken: getTicketToken(code) })
    }
    // Token caducado: se olvida para que la UI pida el código.
    if (r.reauth) dropTicketToken(code)
    return r
  },
}
