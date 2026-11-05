/**
 * Esqueletos de carga: la forma de lo que viene (ficha, tabla, estadísticas, lista) en vez
 * de un spinner. Para el lector de pantalla, «Cargando…» una sola vez.
 */
const B = ({ w = '100%', h = 10, r, className = '' }) => (
  <i className={`sk ${className}`} style={{ width: w, height: h, ...(r != null ? { borderRadius: r } : null) }} />
)
const Caja = ({ className = '', children, label = 'Cargando…' }) => (
  <div className={`sk-wrap ${className}`} aria-busy="true" aria-live="polite">
    <span className="sr-only">{label}</span>
    <div aria-hidden="true">{children}</div>
  </div>
)
// Anchos que varían para que no parezca una rejilla perfecta.
const ANCHOS = ['72%', '54%', '86%', '63%', '78%', '48%', '69%', '58%']

/** Ficha de una garantía: cabecera, datos, recorrido y dos tarjetas. */
export function SkFicha() {
  return (
    <Caja className="sk-ficha" label="Cargando la garantía…">
      <B w={90} h={12} className="sk-back" />
      <div className="sk-card">
        <div className="sk-head">
          <div className="sk-col">
            <div className="sk-row"><B w={96} h={20} r={6} /><B w={84} h={20} r={99} /><B w={56} h={20} r={99} /></div>
            <B w={260} h={22} /><B w={150} h={12} />
          </div>
          <div className="sk-row"><B w={150} h={36} r={10} /><B w={150} h={36} r={10} /><B w={34} h={34} r={9} /></div>
        </div>
        <div className="sk-facts">{Array.from({ length: 6 }, (_, i) => <div key={i}><B w={70} h={9} /><B w={ANCHOS[i]} h={14} /></div>)}</div>
        <div className="sk-steps">{Array.from({ length: 9 }, (_, i) => <div key={i}><B h={6} r={99} /><B w={ANCHOS[i % 8]} h={9} /></div>)}</div>
      </div>
      <div className="sk-card sk-pad"><B w={180} h={14} /><B h={44} r={10} /><div className="sk-row"><B w={56} h={56} r={8} /><B w={56} h={56} r={8} /><B w={56} h={56} r={8} /></div></div>
      <div className="sk-card sk-pad"><div className="sk-row"><B w={110} h={14} /><B w={90} h={14} /></div>
        {Array.from({ length: 3 }, (_, i) => <div key={i} className="sk-row sk-line"><B w={90} h={12} /><B w={ANCHOS[i + 2]} h={12} /><B w={80} h={22} r={99} /></div>)}</div>
    </Caja>
  )
}

/** Tabla: cabecera y filas con columnas de anchos distintos. */
export function SkTabla({ rows = 8, cols = 6, className = '', label }) {
  return (
    <Caja className={`sk-tabla ${className}`} label={label}>
      <div className="sk-tr sk-th">{Array.from({ length: cols }, (_, j) => <B key={j} w={j === 0 ? '60%' : '45%'} h={9} />)}</div>
      {Array.from({ length: rows }, (_, i) => (
        <div key={i} className="sk-tr">{Array.from({ length: cols }, (_, j) => (
          j === 1 ? <div key={j} className="sk-col"><B w={ANCHOS[i % 8]} h={12} /><B w="40%" h={9} /></div> : <B key={j} w={ANCHOS[(i + j) % 8]} h={12} />
        ))}</div>
      ))}
    </Caja>
  )
}

/** Página de estadísticas: cifras arriba y dos gráficos. */
export function SkStats({ kpis = 4, label = 'Cargando las estadísticas…' }) {
  return (
    <Caja className="sk-stats" label={label}>
      <div className="sk-kpis">{Array.from({ length: kpis }, (_, i) => <div key={i} className="sk-card sk-pad"><B w="55%" h={9} /><B w="40%" h={26} /><B w="70%" h={9} /></div>)}</div>
      <div className="sk-grid2">
        <div className="sk-card sk-pad"><B w={160} h={14} /><div className="sk-bars">{[62, 84, 45, 70, 38, 92, 55, 66, 48, 76].map((h, i) => <B key={i} h={`${h}%`} r={4} />)}</div></div>
        <div className="sk-card sk-pad"><B w={140} h={14} />{ANCHOS.slice(0, 6).map((w, i) => <div key={i} className="sk-row sk-line"><B w={90} h={10} /><B w={w} h={10} r={99} /></div>)}</div>
      </div>
    </Caja>
  )
}

/** Lista de tarjetas o bloques (documentación, ajustes, bandeja). */
export function SkLista({ rows = 5, label }) {
  return (
    <Caja className="sk-lista" label={label}>
      {Array.from({ length: rows }, (_, i) => (
        <div key={i} className="sk-card sk-pad sk-item"><B w={36} h={36} r={9} /><div className="sk-col sk-grow"><B w={ANCHOS[i % 8]} h={13} /><B w="38%" h={9} /></div><B w={72} h={26} r={8} /></div>
      ))}
    </Caja>
  )
}
