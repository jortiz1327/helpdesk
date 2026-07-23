/*
 * Base de la API: ABSOLUTA (`/api`), no relativa.
 *
 * La app de agentes vive bajo /agentes/* (tickets, shifts…). Con una base relativa
 * («api»), `fetch('api/auth.php')` desde /agentes/tickets se resolvía a
 * `/agentes/api/auth.php` → caía en el fallback, devolvía el HTML de la SPA en vez
 * de JSON, y la app creía que no había sesión (login) y no cargaba tickets. Con
 * `/api` el endpoint es siempre el mismo, mire la URL que mire.
 */
const BASE = '/api'

// --- Token de autenticación (en localStorage, enviado por cabecera) ---
const TOKEN_KEY = 'app_token'
export const getToken = () => localStorage.getItem(TOKEN_KEY) || ''
export const setToken = (t) => { t ? localStorage.setItem(TOKEN_KEY, t) : localStorage.removeItem(TOKEN_KEY) }

let unauthorizedHandler = null
export function onUnauthorized(fn) { unauthorizedHandler = fn }

async function req(path, opts = {}) {
  const headers = { ...(opts.headers || {}) }
  const tok = getToken()
  if (tok) headers['X-App-Token'] = tok
  const r = await fetch(`${BASE}/${path}`, { ...opts, headers })
  const text = await r.text()
  let json
  try {
    json = JSON.parse(text)
  } catch {
    /*
     * La respuesta NO es JSON (una página de error del servidor, un HTML del
     * fallback…). NUNCA se devuelve el cuerpo crudo como mensaje: acababa pintado
     * en pantalla —la página de error entera vomitada en el login—. Se da un
     * mensaje limpio con el código, y el HTML se deja solo en la consola para
     * depurar.
     */
    if (text && !import.meta.env.PROD) console.error(`Respuesta no-JSON de ${path} [${r.status}]:`, text.slice(0, 500))
    json = { ok: false, error: `El servidor devolvió una respuesta inesperada (${r.status}). Inténtalo de nuevo.` }
  }
  // Token ausente o caducado: avisar a la app (salvo en las propias rutas de auth)
  if (r.status === 401 && !path.startsWith('auth.php') && unauthorizedHandler) {
    unauthorizedHandler()
  }
  return json
}

export const api = {
  // Conversaciones
  listConversations: (q = '', assigned = '') => req(`conversations.php?action=list&q=${encodeURIComponent(q)}&assigned=${assigned}`),
  listAgents: () => req('conversations.php?action=agents'),
  getMessages: (id) => req(`conversations.php?action=messages&contact_id=${id}`),
  pollMessages: (id, after) => req(`conversations.php?action=poll&contact_id=${id}&after=${after}`),
  markConversation: (contact_id, read) => req('conversations.php?action=mark', {
    method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ contact_id, read }),
  }),
  deleteConversation: (contact_id) => req('conversations.php?action=delete', {
    method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ contact_id }),
  }),

  // Enviar
  send: (payload) => req('send.php', {
    method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(payload),
  }),
  // Enviar un medio (imagen/vídeo/audio/documento) por el chat
  sendMedia: ({ file, to, contact_id, type, caption = '' }) => {
    const fd = new FormData()
    fd.append('file', file)
    fd.append('to', to)
    fd.append('contact_id', contact_id)
    fd.append('type', type)
    fd.append('caption', caption)
    return req('send_media.php', { method: 'POST', body: fd })
  },

  // Dashboard
  stats: () => req('stats.php'),
  analytics: () => req('analytics.php'),

  // Candados de funciones bloqueadas por Meta (verificación)
  gating: () => req('gating.php'),

  // --- Tickets (núcleo del helpdesk) ---
  listTickets: (f = {}) => req('tickets.php?action=list&' + new URLSearchParams(f)),
  ticketStats: () => req('tickets.php?action=stats'),
  ticketMeta: () => req('tickets.php?action=meta'),
  getTicket: (id) => req(`tickets.php?action=detail&id=${id}`),
  // Se envía como multipart porque puede llevar adjuntos (JSON no transporta ficheros).
  createTicket: ({ files = [], ...payload }) => {
    const fd = new FormData()
    Object.entries(payload).forEach(([k, v]) => fd.append(k, v ?? ''))
    files.forEach((f) => fd.append('files[]', f))
    return req('tickets.php?action=create', { method: 'POST', body: fd })
  },
  attachmentUrl: (id) => `${BASE}/attachment.php?id=${id}&token=${encodeURIComponent(getToken())}`,
  listTicketAgents: () => req('tickets.php?action=agents'),
  agentHistory: (userId) => req(`tickets.php?action=history&user_id=${userId}`),
  cannedForComposer: () => req('tickets.php?action=canned'),

  // --- Configuración de Soporte (categorías + respuestas predefinidas) ---
  supCategories: () => req('support_settings.php?section=categories'),
  supSaveCategory: (payload) => req('support_settings.php?section=categories', {
    method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(payload),
  }),
  supDeleteCategory: (id) => req(`support_settings.php?section=categories&id=${id}`, { method: 'DELETE' }),
  supCanned: () => req('support_settings.php?section=canned'),
  supSaveCanned: (payload) => req('support_settings.php?section=canned', {
    method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(payload),
  }),
  supDeleteCanned: (id) => req(`support_settings.php?section=canned&id=${id}`, { method: 'DELETE' }),
  // Canal correo: config del buzón (IMAP/SMTP) + probar conexión
  getEmailAccount: () => req('email.php'),
  saveEmailAccount: (payload) => req('email.php', {
    method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(payload),
  }),
  testEmailAccount: (payload) => req('email.php?action=test', {
    method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(payload),
  }),
  // Suelta el bloqueo del ticket al cerrarlo (si no, caduca solo)
  unlockTicket: (id) => req('tickets.php?action=unlock', {
    method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ id }),
  }),
  // Horario de atención y festivos (base del SLA)
  getBusinessHours: () => req('business_hours.php'),
  saveBusinessHours: (hours) => req('business_hours.php?action=save', {
    method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ hours }),
  }),
  addHoliday: (date, name) => req('business_hours.php?action=add_holiday', {
    method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ date, name }),
  }),
  // Interruptor general del SLA (no borra las horas de las categorías)
  toggleSla: (active) => req('business_hours.php?action=toggle_sla', {
    method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ active }),
  }),
  delHoliday: (id) => req('business_hours.php?action=del_holiday', {
    method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ id }),
  }),
  // Avisos de crones fallidos (apartado propio, agrupados por cron)
  listCronAlerts: (f = {}) => req('cron_alerts.php?' + new URLSearchParams(f)),
  getCronAlert: (id) => req(`cron_alerts.php?action=detail&id=${id}`),
  cronAlertCounts: () => req('cron_alerts.php?action=counts'),
  // Uno o varios de golpe: acepta un id suelto o una lista.
  resolveCronAlerts: (ids, reopen = false) => req('cron_alerts.php?action=resolve', {
    method: 'POST', headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ ids: [].concat(ids), reopen }),
  }),
  // Cuadrante de turnos: el mes día a día (vista principal)
  getShiftMonth: (month) => req(`shifts.php?action=month&month=${encodeURIComponent(month || '')}`),
  saveShiftNote: (date, note) => req('shifts.php?action=note', {
    method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ date, note }),
  }),
  getShifts: (from, weeks = 8) => req(`shifts.php?from=${encodeURIComponent(from || '')}&weeks=${weeks}`),
  assignShift: (payload) => req('shifts.php?action=assign', {
    method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(payload),
  }),
  saveShiftOverride: (payload) => req('shifts.php?action=override', {
    method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(payload),
  }),
  rotateShifts: (payload) => req('shifts.php?action=rotate', {
    method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(payload),
  }),
  // Cómo quedaría y qué semanas se pisarían, sin escribir nada
  previewRotation: (payload) => req('shifts.php?action=rotate_preview', {
    method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(payload),
  }),
  // Estado del planificador (cron)
  getCronStatus: () => req('cron_status.php'),
  // Ajustes generales del ticket (estado por defecto, bloqueo)
  getTicketSettings: () => req('ticket_settings.php'),
  saveTicketSettings: (payload) => req('ticket_settings.php', {
    method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(payload),
  }),
  // Prioridades configurables
  listPriorities: () => req('ticket_priorities.php'),
  savePriority: (payload) => req('ticket_priorities.php?action=save', {
    method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(payload),
  }),
  deletePriority: (id) => req('ticket_priorities.php?action=delete', {
    method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ id }),
  }),
  // Preguntas frecuentes del portal
  listFaqs: () => req('faqs.php'),
  saveFaq: (payload) => req('faqs.php?action=save', {
    method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(payload),
  }),
  deleteFaq: (id) => req('faqs.php?action=delete', {
    method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ id }),
  }),
  reorderFaqs: (ids) => req('faqs.php?action=reorder', {
    method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ ids }),
  }),
  // Reglas automáticas de tickets (flujo de trabajo)
  listTicketRules: () => req('ticket_rules.php'),
  saveTicketRule: (payload) => req('ticket_rules.php?action=save', {
    method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(payload),
  }),
  deleteTicketRule: (id) => req('ticket_rules.php?action=delete', {
    method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ id }),
  }),
  // Plantillas de aviso (ticket creado / cerrado / asignado)
  listEmailTemplates: () => req('email_templates.php'),
  saveEmailTemplate: (payload) => req('email_templates.php', {
    method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(payload),
  }),
  // Diagnóstico: envía un correo real para comprobar la salida
  sendTestEmail: (payload) => req('email.php?action=send_test', {
    method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(payload),
  }),
  // Correos bloqueados (banlist)
  listEmailBans: () => req('email_bans.php'),
  saveEmailBan: (payload) => req('email_bans.php?action=save', {
    method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(payload),
  }),
  deleteEmailBan: (id) => req('email_bans.php?action=delete', {
    method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ id }),
  }),
  // Fusionar: candidatos del MISMO cliente, y la fusión en sí.
  mergeableTickets: (id) => req(`tickets.php?action=mergeable&id=${id}`),
  mergeTickets: (into, from, reason) => req('tickets.php?action=merge', {
    method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ into, from, reason }),
  }),

  setTicketStatus: (id, status) => req('tickets.php?action=status', {
    method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ id, status }),
  }),
  assignTicket: (id, user_id) => req('tickets.php?action=assign', {
    method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ id, user_id }),
  }),
  // Nota interna (no se envía al cliente): body en HTML
  ticketNote: (id, body, sla = false) => req('tickets.php?action=note', {
    method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ id, body, sla }),
  }),
  // Responder al cliente (canal correo → SMTP). Multipart: HTML + adjuntos.
  ticketReply: (id, body, files = [], cc = [], bcc = []) => {
    const fd = new FormData()
    fd.append('id', id)
    fd.append('body', body)
    files.forEach((f) => fd.append('files[]', f))
    // Copias: quien venía en el hilo sigue en la conversación.
    cc.forEach((d) => fd.append('cc[]', d))
    bcc.forEach((d) => fd.append('bcc[]', d))
    return req('tickets.php?action=reply', { method: 'POST', body: fd })
  },
  // Borrar ticket entero (requiere tickets.delete)
  deleteTicket: (id) => req('tickets.php?action=delete', {
    method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ id }),
  }),
  // Subir imagen EN LÍNEA del editor -> devuelve { ok, url } (URL firmada). Sin Content-Type: multipart lo pone el navegador.
  uploadInlineImage: (file) => { const fd = new FormData(); fd.append('file', file); return req('inline_media.php', { method: 'POST', body: fd }) },
  // PDF del hilo del ticket. Devuelve { ok, blob }. opts: { notes, images }
  ticketPdf: async (id, opts = {}) => {
    const r = await fetch(`${BASE}/tickets.php?action=pdf`, {
      method: 'POST', headers: { 'X-App-Token': getToken(), 'Content-Type': 'application/json' },
      body: JSON.stringify({ id, notes: opts.notes ? 1 : 0, images: opts.images ? 1 : 0 }),
    })
    return r.ok ? { ok: true, blob: await r.blob() } : { ok: false }
  },
  // Acciones en lote: { op:'status', status } o { op:'assign', user_id }
  bulkTickets: (ids, payload) => req('tickets.php?action=bulk', {
    method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ ids, ...payload }),
  }),

  // Roles y permisos disponibles (catálogo del RBAC)
  listRoles: () => req('roles.php'),
  saveRole: (payload) => req('roles.php', {
    method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(payload),
  }),
  deleteRole: (name) => req(`roles.php?name=${encodeURIComponent(name)}`, { method: 'DELETE' }),

  // Usuarios / agentes (requiere permiso users.manage)
  listUsers: () => req('users.php'),
  saveUser: (payload) => req('users.php', {
    method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(payload),
  }),
  deleteUser: (id) => req(`users.php?id=${id}`, { method: 'DELETE' }),

  // Asignar conversación a un agente
  assignConversation: (contact_id, user_id) => req('conversations.php?action=assign', {
    method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ contact_id, user_id }),
  }),

  // Formularios de WhatsApp
  listForms: () => req('forms.php'),
  getForm: (id) => req(`forms.php?id=${id}`),
  formsStats: () => req('forms.php?action=stats'),
  formSubmissions: () => req('forms.php?action=submissions'),
  syncForms: () => req('forms.php?action=sync'),
  saveForm: (payload) => req('forms.php', {
    method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(payload),
  }),
  deleteForm: (id) => req(`forms.php?id=${id}`, { method: 'DELETE' }),
  publishFormToMeta: (id) => req('forms.php?action=publish', {
    method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ id }),
  }),
  sendFormFlow: (payload) => req('forms.php?action=send', {
    method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(payload),
  }),

  // Respuestas capturadas por el bot
  listBotResponses: (q = '') => req(`responses.php?q=${encodeURIComponent(q)}`),
  botResponsesCsvUrl: (q = '') => `${BASE}/responses.php?action=csv&q=${encodeURIComponent(q)}&token=${encodeURIComponent(getToken())}`,

  // Flujos de automatización
  listFlows: () => req('flows.php'),
  dbSchema: () => req('flows.php?action=schema'),
  getFlow: (id) => req(`flows.php?id=${id}`),
  saveFlow: (payload) => req('flows.php', {
    method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(payload),
  }),
  deleteFlow: (id) => req(`flows.php?id=${id}`, { method: 'DELETE' }),

  // Contacto (nombre / nota / etiquetas)
  saveContact: (contact_id, fields) => req('contact.php?action=save', {
    method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ contact_id, ...fields }),
  }),
  setContactLabels: (contact_id, label_ids) => req('contact.php?action=labels', {
    method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ contact_id, label_ids }),
  }),

  // Contactos (gestión + acciones en lote)
  // `area` separa por actividad: 'campaigns' (con WhatsApp) | 'helpdesk' (con tickets)
  listContacts: (q = '', label = 0, optout = '', area = '') => req(`contacts.php?q=${encodeURIComponent(q)}&label=${label}&optout=${optout}&area=${area}`),
  setOptout: (contact_ids, value) => req('contacts.php?action=set_optout', {
    method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ contact_ids, value }),
  }),
  bulkLabel: (contact_ids, label_id, mode = 'add') => req('contacts.php?action=bulk_label', {
    method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ contact_ids, label_id, mode }),
  }),
  // Fusionar dos contactos duplicados (el mismo cliente por WhatsApp y por correo)
  mergeContacts: (keep_id, merge_id) => req('contacts.php?action=merge', {
    method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ keep_id, merge_id }),
  }),
  bulkAddToPhonebook: (contact_ids, phonebook_id) => req('contacts.php?action=bulk_phonebook', {
    method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ contact_ids, phonebook_id }),
  }),

  // Etiquetas
  listLabels: () => req('labels.php'),
  createLabel: (name, color) => req('labels.php', {
    method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ name, color }),
  }),
  deleteLabel: (id) => req(`labels.php?id=${id}`, { method: 'DELETE' }),
  reorderLabels: (ids) => req('labels.php?action=reorder', {
    method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ ids }),
  }),

  // Plantillas
  listTemplates: () => req('templates.php'),
  createTemplate: (payload) => req('templates.php', {
    method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(payload),
  }),
  editTemplate: (id, payload) => req(`templates.php?id=${encodeURIComponent(id)}`, {
    method: 'PUT', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(payload),
  }),
  deleteTemplate: (name) => req(`templates.php?name=${encodeURIComponent(name)}`, { method: 'DELETE' }),
  uploadMedia: (file) => {
    const fd = new FormData()
    fd.append('file', file)
    return req('upload_media.php', { method: 'POST', body: fd })
  },

  // Agendas de contactos (phonebooks)
  listPhonebooks: () => req('phonebooks.php'),
  getPhonebook: (id) => req(`phonebooks.php?id=${id}`),
  savePhonebook: (payload) => req('phonebooks.php', {
    method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(payload),
  }),
  addPhonebookContacts: (payload) => req('phonebooks.php?action=add', {
    method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(payload),
  }),
  deletePhonebook: (id) => req(`phonebooks.php?id=${id}`, { method: 'DELETE' }),
  deletePhonebookContact: (contactId) => req(`phonebooks.php?contact_id=${contactId}`, { method: 'DELETE' }),

  // Campañas de difusión
  listCampaigns: () => req('campaigns.php'),
  getCampaign: (id) => req(`campaigns.php?id=${id}`),
  createCampaign: (payload) => req('campaigns.php', {
    method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(payload),
  }),
  runCampaign: (id) => req(`campaigns.php?action=run&id=${id}`, { method: 'POST' }),
  cancelCampaign: (id) => req(`campaigns.php?action=cancel&id=${id}`, { method: 'POST' }),
  deleteCampaign: (id) => req(`campaigns.php?id=${id}`, { method: 'DELETE' }),

  // Configuración
  saveSettings: (payload) => req('settings.php?action=save', {
    method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(payload),
  }),
  testConnection: () => req('settings.php?action=test'),
  getSettings: () => req('settings.php?action=get'),

  // Autenticación
  me: () => req('auth.php?action=me'),
  login: async (email, password) => {
    const res = await req('auth.php?action=login', {
      method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ email, password }),
    })
    if (res.ok && res.token) setToken(res.token)
    return res
  },
  logout: async () => { setToken(''); return req('auth.php?action=logout') },
  changeAccount: async (payload) => {
    const res = await req('auth.php?action=change', {
      method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(payload),
    })
    if (res.ok && res.token) setToken(res.token)
    return res
  },
}

export const mediaUrl = (id) => `${BASE}/media.php?id=${encodeURIComponent(id)}&token=${encodeURIComponent(getToken())}`
