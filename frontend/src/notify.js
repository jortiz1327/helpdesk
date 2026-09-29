// Notificaciones web (Nivel 1): avisos del navegador con la app abierta.
// Ajustes por dispositivo (localStorage). Sin backend.
const KEY = 'web_notify'

export function getNotify() {
  try { return JSON.parse(localStorage.getItem(KEY)) || {} } catch { return {} }
}
export function setNotify(patch) {
  const next = { ...getNotify(), ...patch }
  localStorage.setItem(KEY, JSON.stringify(next))
  window.dispatchEvent(new Event('notify-change'))
  return next
}

export const notifySupported = () => typeof window !== 'undefined' && 'Notification' in window
export const notifyPermission = () => (notifySupported() ? Notification.permission : 'unsupported')

// ¿Deben dispararse avisos ahora mismo?
export function notifyActive() {
  const s = getNotify()
  return !!s.enabled && notifySupported() && Notification.permission === 'granted'
}

export async function requestNotifyPermission() {
  if (!notifySupported()) return 'unsupported'
  if (Notification.permission === 'granted') return 'granted'
  try { return await Notification.requestPermission() } catch { return Notification.permission }
}

// Lanza un aviso del navegador (si procede). onClick enfoca la app.
export function fireNotification(title, body, onClick) {
  if (!notifyActive()) return
  try {
    const n = new Notification(title, { body, tag: 'wa-' + title, renotify: true })
    // Con la ventana OCULTA (otra pestaña / minimizada), sonido más fuerte e insistente.
    if (getNotify().sound) { try { beep(typeof document !== 'undefined' && document.hidden) } catch {} }
    n.onclick = () => { window.focus(); onClick && onClick(); n.close() }
  } catch {}
}

// Un solo AudioContext reutilizado (crear uno nuevo en una pestaña en segundo plano
// suele nacer «suspended»; reutilizarlo y reanudarlo suena mejor de fondo).
let _actx = null
function audioCtx() {
  const AC = window.AudioContext || window.webkitAudioContext
  if (!AC) return null
  if (!_actx) { try { _actx = new AC() } catch { return null } }
  if (_actx.state === 'suspended') _actx.resume().catch(() => {})
  return _actx
}

/*
 * Aviso sonoro (sin ficheros de audio, se sintetiza). Dos variantes:
 *  · normal (ventana visible): «din-don» corto de dos notas.
 *  · fuerte (ventana oculta):  patrón doble, más largo y a más volumen, para que se
 *    oiga aunque estés en otra pestaña o app.
 */
function beep(strong = false) {
  const ctx = audioCtx(); if (!ctx) return
  const now = ctx.currentTime
  const master = ctx.createGain()
  master.connect(ctx.destination)
  master.gain.value = strong ? 0.55 : 0.34   // antes 0.15: ahora bastante más alto

  // [frecuencia Hz, inicio s, duración s]
  const notas = strong
    ? [[988, 0, 0.14], [1319, 0.14, 0.16], [988, 0.36, 0.14], [1319, 0.5, 0.22]]
    : [[880, 0, 0.12], [1174, 0.12, 0.18]]

  for (const [freq, t0, dur] of notas) {
    const o = ctx.createOscillator(), g = ctx.createGain()
    o.type = 'triangle'; o.frequency.value = freq
    o.connect(g); g.connect(master)
    const s = now + t0
    g.gain.setValueAtTime(0.0001, s)
    g.gain.exponentialRampToValueAtTime(1, s + 0.015)
    g.gain.exponentialRampToValueAtTime(0.0001, s + dur)
    o.start(s); o.stop(s + dur + 0.03)
  }
}
