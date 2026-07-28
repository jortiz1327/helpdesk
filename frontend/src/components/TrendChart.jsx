import { useState, useRef } from 'react'

/*
 * Evolución diaria: tickets CREADOS vs RESUELTOS. SVG propio (sin librerías, como el
 * resto). Dos series con leyenda + etiqueta directa (la identidad no depende solo del
 * color) y tooltip con línea guía al pasar el ratón.
 */
const CREADOS = 'var(--primary)'   // azul de marca
const RESUELTOS = '#10b981'        // verde (legible en claro y oscuro)
const W = 760, H = 250, PADL = 34, PADR = 14, PADT = 14, PADB = 26

const fday = (iso) => { const d = new Date(iso + 'T00:00:00'); return `${d.getDate()}/${d.getMonth() + 1}` }
const fdayLong = (iso) => { try { return new Date(iso + 'T00:00:00').toLocaleDateString('es-ES', { weekday: 'short', day: 'numeric', month: 'short' }) } catch { return iso } }

export default function TrendChart({ data }) {
  const [hi, setHi] = useState(-1)
  const wrap = useRef(null)
  const n = data.length
  if (!n) return null

  const maxRaw = Math.max(1, ...data.map((d) => Math.max(d.creados, d.resueltos)))
  const maxY = niceMax(maxRaw)
  const x = (i) => PADL + (n <= 1 ? (W - PADL - PADR) / 2 : (i / (n - 1)) * (W - PADL - PADR))
  const y = (v) => H - PADB - (v / maxY) * (H - PADB - PADT)

  const path = (key) => data.map((d, i) => `${i ? 'L' : 'M'}${x(i).toFixed(1)},${y(d[key]).toFixed(1)}`).join(' ')
  const area = (key) => `${path(key)} L${x(n - 1).toFixed(1)},${y(0)} L${x(0).toFixed(1)},${y(0)} Z`

  const grid = [0, 0.5, 1].map((f) => Math.round(maxY * f))
  // Etiquetas del eje X: primera, última y una intermedia (sin amontonar).
  const xticks = n <= 2 ? data.map((_, i) => i) : [0, Math.floor((n - 1) / 2), n - 1]

  const onMove = (e) => {
    const r = wrap.current.getBoundingClientRect()
    const px = ((e.clientX - r.left) / r.width) * W       // a coordenadas del viewBox
    let idx = Math.round(((px - PADL) / (W - PADL - PADR)) * (n - 1))
    idx = Math.max(0, Math.min(n - 1, idx))
    setHi(idx)
  }

  const d = hi >= 0 ? data[hi] : null

  return (
    <div className="card trend" ref={wrap} onMouseMove={onMove} onMouseLeave={() => setHi(-1)}>
      <div className="trend-top">
        <span className="trend-h">Evolución</span>
        <span className="trend-leg"><i className="trend-dot" style={{ background: CREADOS }} />Creados</span>
        <span className="trend-leg"><i className="trend-dot" style={{ background: RESUELTOS }} />Resueltos</span>
      </div>
      <svg viewBox={`0 0 ${W} ${H}`} className="trend-svg" preserveAspectRatio="none">
        {/* Rejilla + eje Y recesivos */}
        {grid.map((g, i) => (
          <g key={i}>
            <line x1={PADL} x2={W - PADR} y1={y(g)} y2={y(g)} className="trend-grid" />
            <text x={PADL - 6} y={y(g) + 3} className="trend-yl" textAnchor="end">{g}</text>
          </g>
        ))}
        {/* Áreas + líneas */}
        <path d={area('creados')} fill={CREADOS} opacity="0.10" />
        <path d={area('resueltos')} fill={RESUELTOS} opacity="0.10" />
        <path d={path('creados')} fill="none" stroke={CREADOS} strokeWidth="2" strokeLinejoin="round" strokeLinecap="round" vectorEffect="non-scaling-stroke" />
        <path d={path('resueltos')} fill="none" stroke={RESUELTOS} strokeWidth="2" strokeLinejoin="round" strokeLinecap="round" vectorEffect="non-scaling-stroke" />
        {/* Etiquetas del eje X */}
        {xticks.map((i) => (
          <text key={i} x={x(i)} y={H - 8} className="trend-xl" textAnchor={i === 0 ? 'start' : i === n - 1 ? 'end' : 'middle'}>{fday(data[i].date)}</text>
        ))}
        {/* Guía + puntos al pasar el ratón */}
        {d && (
          <g>
            <line x1={x(hi)} x2={x(hi)} y1={PADT} y2={H - PADB} className="trend-cross" />
            <circle cx={x(hi)} cy={y(d.creados)} r="3.5" fill={CREADOS} stroke="var(--panel)" strokeWidth="1.5" vectorEffect="non-scaling-stroke" />
            <circle cx={x(hi)} cy={y(d.resueltos)} r="3.5" fill={RESUELTOS} stroke="var(--panel)" strokeWidth="1.5" vectorEffect="non-scaling-stroke" />
          </g>
        )}
      </svg>
      {d && (
        <div className="trend-tip" style={{ left: `${(x(hi) / W) * 100}%` }}>
          <b>{fdayLong(d.date)}</b>
          <span><i style={{ background: CREADOS }} />Creados <b>{d.creados}</b></span>
          <span><i style={{ background: RESUELTOS }} />Resueltos <b>{d.resueltos}</b></span>
        </div>
      )}
    </div>
  )
}

/* Redondea el máximo a un número «bonito» para el eje. */
function niceMax(v) {
  if (v <= 5) return 5
  const pow = Math.pow(10, Math.floor(Math.log10(v)))
  return Math.ceil(v / pow) * pow
}
