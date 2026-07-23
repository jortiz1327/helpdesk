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

export function parseDate(s) {
  if (!s) return null
  return new Date(s.replace(' ', 'T'))
}

export function relTime(s) {
  const d = parseDate(s)
  if (!d) return ''
  const now = new Date()
  if (d.toDateString() === now.toDateString())
    return d.toLocaleTimeString('es-ES', { hour: '2-digit', minute: '2-digit' })
  const y = new Date(now); y.setDate(now.getDate() - 1)
  if (d.toDateString() === y.toDateString()) return 'Ayer'
  return d.toLocaleDateString('es-ES', { day: '2-digit', month: '2-digit' })
}

export function clockTime(s) {
  const d = parseDate(s)
  return d ? d.toLocaleTimeString('es-ES', { hour: '2-digit', minute: '2-digit' }) : ''
}

export function dayLabel(s) {
  const d = parseDate(s); if (!d) return ''
  const now = new Date()
  if (d.toDateString() === now.toDateString()) return 'Hoy'
  const y = new Date(now); y.setDate(now.getDate() - 1)
  if (d.toDateString() === y.toDateString()) return 'Ayer'
  return d.toLocaleDateString('es-ES', { day: '2-digit', month: 'long', year: 'numeric' })
}
