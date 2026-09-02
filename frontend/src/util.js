export function initials(c) {
  const n = (c?.name || c?.wa_id || '?').trim()
  const parts = n.replace(/[^\p{L}\p{N} ]/gu, '').split(/\s+/).filter(Boolean)
  return (parts.slice(0, 2).map((w) => w[0]).join('') || '#').toUpperCase()
}

const PALETTE = [
  ['#00a884', '#128c7e'], ['#6a5cff', '#8e7bff'], ['#ff7a59', '#ff5e7e'],
  ['#0ea5e9', '#2563eb'], ['#f59e0b', '#f97316'], ['#10b981', '#14b8a6'],
  ['#ec4899', '#d946ef'], ['#64748b', '#475569'],
]
// A prueba de nulos: un contacto de web o correo no tiene teléfono (wa_id = null).
export function avatarBg(seed) {
  const s = String(seed ?? '')
  let h = 0
  for (let i = 0; i < s.length; i++) h = (h * 31 + s.charCodeAt(i)) >>> 0
  const [a, b] = PALETTE[h % PALETTE.length]
  return `linear-gradient(135deg, ${a}, ${b})`
}

/*
 * ZONA HORARIA. La BD guarda la hora de pared de MADRID como texto sin zona
 * («2026-09-01 14:00:00»). Para que se vea IGUAL en cualquier zona del navegador:
 *   · parseDate() convierte ese texto al INSTANTE real (para duraciones y orden),
 *   · y TODO se formatea/compara en Europe/Madrid (no en la zona del navegador).
 */
const TZ = 'Europe/Madrid'
const ES = 'es-ES'

// Offset (ms) UTC↔Madrid en ese instante (respeta el horario de verano).
function madridOffset(date) {
  const utc = new Date(date.toLocaleString('en-US', { timeZone: 'UTC' }))
  const mad = new Date(date.toLocaleString('en-US', { timeZone: TZ }))
  return mad - utc
}

export function parseDate(s) {
  if (!s) return null
  const asUtc = new Date(String(s).replace(' ', 'T') + 'Z')   // provisional: como si fuera UTC
  if (isNaN(asUtc.getTime())) return null
  return new Date(asUtc.getTime() - madridOffset(asUtc))       // corrige: el texto era Madrid
}

// YYYY-MM-DD de un instante, EN MADRID (para comparar días sin depender del navegador).
const madDay = (d) => new Intl.DateTimeFormat('en-CA', { timeZone: TZ, year: 'numeric', month: '2-digit', day: '2-digit' }).format(d)
const esHoy = (d) => madDay(d) === madDay(new Date())
const esAyer = (d) => madDay(d) === madDay(new Date(Date.now() - 86400000))

export function relTime(s) {
  const d = parseDate(s); if (!d) return ''
  if (esHoy(d)) return d.toLocaleTimeString(ES, { timeZone: TZ, hour: '2-digit', minute: '2-digit' })
  if (esAyer(d)) return 'Ayer'
  return d.toLocaleDateString(ES, { timeZone: TZ, day: '2-digit', month: '2-digit' })
}

export function clockTime(s) {
  const d = parseDate(s)
  return d ? d.toLocaleTimeString(ES, { timeZone: TZ, hour: '2-digit', minute: '2-digit' }) : ''
}

export function dayLabel(s) {
  const d = parseDate(s); if (!d) return ''
  if (esHoy(d)) return 'Hoy'
  if (esAyer(d)) return 'Ayer'
  return d.toLocaleDateString(ES, { timeZone: TZ, day: '2-digit', month: 'long', year: 'numeric' })
}

/* Formateadores reutilizables, SIEMPRE en horario de Madrid (no el del navegador). */
export function fmtTime(s) {
  const d = parseDate(s)
  return d ? d.toLocaleTimeString(ES, { timeZone: TZ, hour: '2-digit', minute: '2-digit' }) : ''
}
export function fmtDate(s, opts = { day: '2-digit', month: '2-digit', year: '2-digit', hour: '2-digit', minute: '2-digit' }) {
  const d = parseDate(s)
  return d ? d.toLocaleString(ES, { timeZone: TZ, ...opts }) : '—'
}
export function fmtDateShort(s) {
  const d = parseDate(s)
  return d ? d.toLocaleDateString(ES, { timeZone: TZ, day: '2-digit', month: '2-digit', year: 'numeric' }) : '—'
}
