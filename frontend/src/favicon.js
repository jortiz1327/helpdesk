// Badge de notificaciones sobre el FAVICON de la pestaña: dibuja el icono base en un
// canvas y, si hay avisos, le superpone un círculo rojo con el número. Sin ficheros:
// se genera al vuelo y se cambia el href del <link rel="icon">.

const BASE_SVG = '/favicon.svg'

function iconLink() {
  let link = document.querySelector("link[rel='icon']")
  if (!link) { link = document.createElement('link'); link.rel = 'icon'; document.head.appendChild(link) }
  return link
}

let ultimo = -1

/** Pinta (o quita) el badge con el número. count=0 → favicon original limpio. */
export async function setFaviconBadge(count) {
  count = Math.max(0, Number(count) || 0)
  if (count === ultimo) return   // sin cambios: no repintar
  ultimo = count

  const link = iconLink()
  if (count === 0) { link.type = 'image/svg+xml'; link.href = BASE_SVG; return }

  // A 16px un icono + badge diminuto no se lee: mejor un círculo rojo limpio que
  // OCUPA todo el favicon con el número centrado (cuando no hay avisos, el icono normal).
  const S = 64
  const canvas = document.createElement('canvas')
  canvas.width = S; canvas.height = S
  const cx = canvas.getContext('2d')
  cx.clearRect(0, 0, S, S)

  const c = S / 2
  cx.beginPath(); cx.arc(c, c, c - 2, 0, Math.PI * 2)
  cx.fillStyle = '#ef4444'; cx.fill()

  const label = count > 99 ? '99+' : String(count)
  const fs = label.length >= 3 ? 30 : label.length === 2 ? 40 : 46
  cx.fillStyle = '#ffffff'; cx.textAlign = 'center'; cx.textBaseline = 'middle'
  cx.font = `700 ${fs}px -apple-system, "Segoe UI", Roboto, Arial, sans-serif`
  cx.fillText(label, c, c + 3)

  link.type = 'image/png'
  link.href = canvas.toDataURL('image/png')
}
