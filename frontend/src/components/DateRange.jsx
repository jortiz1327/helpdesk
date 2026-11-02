import { useState, useRef, useEffect } from 'react'
import { Icon } from '../icons.jsx'

/**
 * Selector de rango de fechas propio (sustituye a los <input type="date"> nativos).
 * Un botón con el rango («1 ene 2026 – 2 nov 2026») que abre un calendario de dos meses:
 * primer clic, el inicio; segundo clic, el final. Valores en 'YYYY-MM-DD' ('' = sin límite).
 */
const MESES = ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre']
const DIAS = ['L', 'M', 'X', 'J', 'V', 'S', 'D']
const aIso = (d) => `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`
const deIso = (s) => (s ? new Date(`${s}T00:00:00`) : null)
const corta = (s) => (s ? deIso(s).toLocaleDateString('es-ES', { day: 'numeric', month: 'short', year: 'numeric' }).replace('.', '') : '')

function Mes({ y, m, desde, hasta, previo, onDia, onHover }) {
  const primero = new Date(y, m, 1)
  const huecos = (primero.getDay() + 6) % 7
  const dias = new Date(y, m + 1, 0).getDate()
  const hoy = aIso(new Date())
  const fin = hasta || previo
  return (
    <div className="dr-mes">
      <div className="dr-dias">{DIAS.map((d) => <span key={d}>{d}</span>)}</div>
      <div className="dr-grid">
        {Array.from({ length: huecos }, (_, i) => <span key={`h${i}`} />)}
        {Array.from({ length: dias }, (_, i) => {
          const k = aIso(new Date(y, m, i + 1))
          const [a, b] = desde && fin && fin < desde ? [fin, desde] : [desde, fin]
          const cls = [
            k === hoy ? 'hoy' : '',
            a && b && k > a && k < b ? 'en' : '',
            k === a ? 'ini' : '',
            k === b ? 'fin' : '',
          ].join(' ')
          return <button type="button" key={k} className={cls} onClick={() => onDia(k)} onMouseEnter={() => onHover(k)}>{i + 1}</button>
        })}
      </div>
    </div>
  )
}

export default function DateRange({ from, to, onChange, placeholder = 'Elegir fechas' }) {
  const [open, setOpen] = useState(false)
  const [ini, setIni] = useState(null)        // inicio elegido, a falta del final
  const [previo, setPrevio] = useState(null)   // día bajo el ratón mientras se elige el final
  const base = deIso(to) || new Date()
  const [vista, setVista] = useState([base.getFullYear(), base.getMonth() - 1])
  const ref = useRef(null)

  useEffect(() => {
    if (!open) return
    const fuera = (e) => { if (ref.current && !ref.current.contains(e.target)) setOpen(false) }
    const esc = (e) => { if (e.key === 'Escape') setOpen(false) }
    document.addEventListener('mousedown', fuera)
    document.addEventListener('keydown', esc)
    return () => { document.removeEventListener('mousedown', fuera); document.removeEventListener('keydown', esc) }
  }, [open])

  const abrir = () => {
    const b = deIso(to) || new Date()
    setVista([b.getFullYear(), b.getMonth() - 1])
    setIni(null); setPrevio(null); setOpen((o) => !o)
  }
  const dia = (k) => {
    if (!ini) { setIni(k); setPrevio(k); return }
    const [a, b] = k < ini ? [k, ini] : [ini, k]
    onChange(a, b); setIni(null); setOpen(false)
  }
  const mover = (n) => setVista(([y, m]) => { const d = new Date(y, m + n, 1); return [d.getFullYear(), d.getMonth()] })
  const meses = [0, 1].map((i) => { const d = new Date(vista[0], vista[1] + i, 1); return [d.getFullYear(), d.getMonth()] })
  const texto = from || to ? `${corta(from) || 'Desde el principio'} – ${corta(to) || 'hoy'}` : placeholder

  return (
    <div className="dr" ref={ref}>
      <button type="button" className={`dr-btn ${open ? 'on' : ''}`} onClick={abrir}>
        <Icon.calendar /><span>{texto}</span>
      </button>
      {open ? (
        <div className="dr-pop" role="dialog" aria-label="Elegir rango de fechas">
          <div className="dr-head">
            <button type="button" className="dr-nav" onClick={() => mover(-1)} aria-label="Mes anterior">‹</button>
            {meses.map(([y, m]) => <b key={`${y}-${m}`}>{MESES[m]} {y}</b>)}
            <button type="button" className="dr-nav" onClick={() => mover(1)} aria-label="Mes siguiente">›</button>
          </div>
          <div className="dr-meses" onMouseLeave={() => ini && setPrevio(ini)}>
            {meses.map(([y, m]) => (
              <Mes key={`${y}-${m}`} y={y} m={m} desde={ini || from} hasta={ini ? null : to} previo={ini ? previo : null}
                onDia={dia} onHover={(k) => ini && setPrevio(k)} />
            ))}
          </div>
          <div className="dr-pie">
            <span className="dev-mute">{ini ? `Desde el ${corta(ini)}: elige el último día` : 'Elige el primer día y luego el último'}</span>
            <button type="button" className="dev-btn" onClick={() => { onChange('', ''); setOpen(false) }}>Sin límite de fechas</button>
          </div>
        </div>
      ) : null}
    </div>
  )
}
