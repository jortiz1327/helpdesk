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
 * Aviso sonoro tipo CAMPANA (sin ficheros de audio, se sintetiza): fundamental + un
 * armónico agudo con cola larga (~1,1 s). Dos variantes:
 *  · normal (ventana visible): una campanada, fuerte.
 *  · fuerte (ventana oculta):  campana REPETIDA x2 y aún más alta (con compresor para
 *    ganar volumen sin distorsionar), para que se oiga estando en otra pestaña o app.
 */
function beep(strong = false) {
  const ctx = audioCtx(); if (!ctx) return
  const now = ctx.currentTime
  const master = ctx.createGain()
  // El compresor sube el volumen percibido sin que reviente cuando suenan a la vez.
  const comp = ctx.createDynamicsCompressor()
  master.connect(comp); comp.connect(ctx.destination)
  master.gain.value = strong ? 0.85 : 0.6   // antes 0.15/0.34: ahora mucho más fuerte

  // Una campanada en el instante t0: fundamental (largo) + armónico agudo (más corto).
  const campanada = (t0) => {
    for (const [freq, dur, vol] of [[1318.5, 1.15, 1], [2637, 0.85, 0.34]]) {
      const o = ctx.createOscillator(), g = ctx.createGain()
      o.type = 'sine'; o.frequency.value = freq
      o.connect(g); g.connect(master)
      const s = now + t0
      g.gain.setValueAtTime(0.0001, s)
      g.gain.exponentialRampToValueAtTime(vol, s + 0.008)
      g.gain.exponentialRampToValueAtTime(0.0001, s + dur)
      o.start(s); o.stop(s + dur + 0.05)
    }
  }
  campanada(0)
  if (strong) campanada(0.55)   // ventana oculta: suena dos veces
}
