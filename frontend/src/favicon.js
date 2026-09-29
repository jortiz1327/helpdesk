// Badge de notificaciones sobre el FAVICON de la pestaña: dibuja el icono base en un
// canvas y, si hay avisos, le superpone un círculo rojo con el número. Sin ficheros:
// se genera al vuelo y se cambia el href del <link rel="icon">.

const BASE_SVG = '/favicon.svg'
let baseImg = null

function cargarBase() {
  return new Promise((resolve) => {
    if (baseImg) return resolve(baseImg)
    const img = new Image()
    img.onload = () => { baseImg = img; resolve(img) }
    img.onerror = () => resolve(null)
    img.src = BASE_SVG
  })
}

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

  const S = 64
  const canvas = document.createElement('canvas')
  canvas.width = S; canvas.height = S
  const cx = canvas.getContext('2d')
  const img = await cargarBase()
  cx.clearRect(0, 0, S, S)
  if (img) cx.drawImage(img, 0, 0, S, S)

  const label = count > 99 ? '99+' : String(count)
  const r = label.length > 2 ? 24 : 21
  const bx = S - r, by = r
  // Aro blanco + círculo rojo, para que resalte sobre cualquier icono.
  cx.beginPath(); cx.arc(bx, by, r, 0, Math.PI * 2)
  cx.fillStyle = '#ef4444'; cx.fill()
  cx.lineWidth = 4; cx.strokeStyle = '#ffffff'; cx.stroke()
  cx.fillStyle = '#ffffff'; cx.textAlign = 'center'; cx.textBaseline = 'middle'
  cx.font = `700 ${label.length > 2 ? 22 : 30}px -apple-system, "Segoe UI", Arial, sans-serif`
  cx.fillText(label, bx, by + 1)

  link.type = 'image/png'
  link.href = canvas.toDataURL('image/png')
}
