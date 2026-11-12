/* ---------------------------------------------------------------------------
 * Piezas del PANEL DE ÁREA (ds-*), compartidas por Centro de Soporte, Panel de
 * campañas y Analíticas: el mismo lenguaje que Estadísticas de Garantías.
 * Estilos en styles.css («PANEL DE ÁREA»).
 * ------------------------------------------------------------------------- */

export const nf = (n) => (n == null ? '…' : Number(n).toLocaleString('es-ES'))

// Minigráfica de tendencia. Solo forma: la cifra ya está escrita al lado.
export function Spark({ data }) {
  if (!data || data.length < 2) return null
  const top = Math.max(1, ...data)
  const pts = data.map((v, i) => `${(i / (data.length - 1)) * 100},${28 - (v / top) * 24}`).join(' ')
  return (
    <svg className="ds-spark" viewBox="0 0 100 30" preserveAspectRatio="none" aria-hidden="true">
      <polyline points={pts} fill="none" vectorEffect="non-scaling-stroke" />
    </svg>
  )
}

/* KPI: etiqueta, cifra, contexto y, si hay, la comparación con el periodo anterior
   (`vs`: «la semana anterior», «el mes anterior») y su minigráfica.
   `good`: subir es bueno (se pinta en verde; bajar, en rojo). `fmt`: cómo escribir la cifra. */
export function DsKpi({ k, v, prev, good, h, tone, spark, vs = 'la semana anterior', fmt = nf }) {
  const diff = typeof v === 'number' && typeof prev === 'number' ? v - prev : null
  return (
    <div className={`ds-kpi${tone ? ` t-${tone}` : ''}`}>
      <span className="k">{k}</span>
      <b className="v">{v == null ? '…' : fmt(v)}</b>
      {diff != null && diff !== 0 && (
        <span className={`ds-diff ${good ? (diff > 0 ? 'good' : 'bad') : ''}`}>
          {diff > 0 ? '+' : '−'}{nf(Math.abs(diff))} frente a {vs}
        </span>
      )}
      {h ? <span className="h">{h}</span> : null}
      <Spark data={spark} />
    </div>
  )
}

/* Cabecera común: icono, título y subtítulo; a la derecha lo que se pase como hijos
   (pestañas de la sección, acciones). */
export function DsHead({ icon: Ic, title, sub, children }) {
  return (
    <header className="page-head ds-head">
      {Ic ? <span className="ds-head-ic"><Ic /></span> : null}
      <div className="ds-head-tx">
        <h1>{title}</h1>
        {sub ? <span className="sub">{sub}</span> : null}
      </div>
      <div className="spacer" />
      {children}
    </header>
  )
}

/* Pestañas de la cabecera: [[clave, etiqueta, icono?]]. */
export function DsTabs({ value, onChange, items }) {
  return (
    <div className="ds-tabs" role="tablist">
      {items.map(([k, label, Ic]) => (
        <button key={k} role="tab" aria-selected={value === k} className={value === k ? 'on' : ''} onClick={() => onChange(k)}>
          {Ic ? <Ic /> : null}{label}
        </button>
      ))}
    </div>
  )
}
