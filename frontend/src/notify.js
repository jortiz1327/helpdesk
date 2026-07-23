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
    if (getNotify().sound) { try { beep() } catch {} }
    n.onclick = () => { window.focus(); onClick && onClick(); n.close() }
  } catch {}
}

// Pitido corto opcional (sin archivos de audio)
function beep() {
  const ctx = new (window.AudioContext || window.webkitAudioContext)()
  const o = ctx.createOscillator(), g = ctx.createGain()
  o.connect(g); g.connect(ctx.destination)
  o.type = 'sine'; o.frequency.value = 880
  g.gain.setValueAtTime(0.001, ctx.currentTime)
  g.gain.exponentialRampToValueAtTime(0.15, ctx.currentTime + 0.02)
  g.gain.exponentialRampToValueAtTime(0.001, ctx.currentTime + 0.25)
  o.start(); o.stop(ctx.currentTime + 0.26)
}
