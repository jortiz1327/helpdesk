import { useEffect, useState } from 'react'
import { api } from '../api.js'

/* -------------------------------------------------------------------------
 * Consumo del mes del WhatsApp de soporte frente a los 1.000 mensajes de servicio
 * gratuitos de Meta (desde octubre de 2026). Barra que va de verde a burdeos según
 * se gasta; al pasar el ratón, «x / 1.000». Se refresca cada minuto.
 * ---------------------------------------------------------------------- */

// Paradas de color (porcentaje → HSL): verde, amarillo, naranja, rojo y burdeos fuerte.
const PARADAS = [
  [0, [142, 62, 42]],
  [50, [48, 92, 48]],
  [75, [26, 90, 50]],
  [90, [0, 74, 50]],
  [100, [-15, 78, 30]],   // = 345 (burdeos); en negativo para no dar la vuelta por el cian
]

function colorPara(pct) {
  const p = Math.max(0, Math.min(100, pct))
  for (let i = 1; i < PARADAS.length; i++) {
    const [p1, c1] = PARADAS[i]
    if (p <= p1) {
      const [p0, c0] = PARADAS[i - 1]
      const t = (p - p0) / (p1 - p0)
      const [h, s, l] = c0.map((v, k) => v + (c1[k] - v) * t)
      return `hsl(${h.toFixed(0)} ${s.toFixed(0)}% ${l.toFixed(0)}%)`
    }
  }
  return 'hsl(345 78% 30%)'
}

// «1.000» y no «1000»: en español los números de 4 cifras no se agrupan por defecto.
const num = (n) => n.toLocaleString('es-ES', { useGrouping: 'always' })

export default function WaUsageMeter() {
  const [d, setD] = useState(null)

  useEffect(() => {
    let vivo = true
    const cargar = () => api.waUsage().then((r) => { if (vivo && r?.ok) setD(r) }).catch(() => {})
    cargar()
    const t = setInterval(cargar, 60000)
    return () => { vivo = false; clearInterval(t) }
  }, [])

  if (!d) return null
  const pct = d.limit ? (d.sent / d.limit) * 100 : 0
  const color = colorPara(pct)
  const mes = new Date().toLocaleString('es-ES', { month: 'long' })
  const pasado = d.sent > d.limit
  const tip = pasado
    ? `${num(d.sent)} / ${num(d.limit)} mensajes de WhatsApp en ${mes}. Superado el cupo gratuito: ${num(d.sent - d.limit)} ya se cobran.`
    : `${num(d.sent)} / ${num(d.limit)} mensajes gratuitos de WhatsApp en ${mes}. Quedan ${num(d.limit - d.sent)}. No cuenta lo enviado desde el móvil.`

  return (
    <div className={`wa-meter${pasado ? ' over' : ''}`} title={tip} aria-label={tip}>
      <span className="wa-meter-lbl">WhatsApp · {mes}</span>
      <span className="wa-meter-bar">
        <span className="wa-meter-fill" style={{ width: `${Math.min(100, pct)}%`, background: color }} />
      </span>
    </div>
  )
}
