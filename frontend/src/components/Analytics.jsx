import { useState, useEffect, useCallback } from 'react'
import { api } from '../api.js'
import { Icon } from '../icons.jsx'

const fmtDur = (s) => {
  if (s == null) return '—'
  if (s < 60) return s + 's'
  const m = Math.floor(s / 60)
  if (m < 60) return `${m}m ${s % 60}s`
  const h = Math.floor(m / 60)
  return `${h}h ${m % 60}m`
}
const pct = (a, b) => (b > 0 ? Math.round((a / b) * 100) : 0)

// Barras horizontales genéricas
function Bars({ items, color }) {
  const max = Math.max(1, ...items.map((i) => i.v))
  if (!items.length) return <p className="muted" style={{ fontSize: 12.5 }}>Sin datos todavía.</p>
  return (
    <div className="an-bars">
      {items.map((it, i) => (
        <div className="an-bar-row" key={i}>
          <span className="an-bar-label" title={it.k}>{it.k}</span>
          <div className="an-bar-track"><span className="an-bar-fill" style={{ width: Math.max(2, (it.v / max) * 100) + '%', background: it.c || color || 'var(--primary)' }} /></div>
          <span className="an-bar-val">{it.v}</span>
        </div>
      ))}
    </div>
  )
}

export default function Analytics() {
  const [d, setD] = useState(null)
  const [allLabels, setAllLabels] = useState(false) // ver todas las etiquetas o solo el top
  const load = useCallback(() => { api.analytics().then((r) => setD(r.ok ? r : null)) }, [])
  useEffect(() => { load() }, [load])

  if (!d) return <div className="center-load"><div className="spinner" /></div>

  const c = d.campaigns
  // Con muchas etiquetas (200 de sedes) esto sería un listado interminable: se ordena
  // por nº de conversaciones y se muestra el top; el resto detrás de "Ver todas".
  const LABELS_TOP = 10
  const labelsSorted = [...(d.by_label || [])].sort((a, b) => Number(b.n) - Number(a.n))
  const labelsShown = allLabels ? labelsSorted : labelsSorted.slice(0, LABELS_TOP)
  const cards = [
    { label: 'Tiempo 1ª respuesta', value: fmtDur(d.first_response.avg_seconds), sub: `media de ${d.first_response.count} conversaciones`, color: '#4a9bff' },
    { label: 'Conversaciones', value: d.funnel[1]?.v ?? 0, sub: `de ${d.funnel[0]?.v ?? 0} contactos`, color: '#00a884' },
    { label: 'Tasa de lectura', value: pct(c.read, c.sent) + '%', sub: `${c.read} leídos de ${c.sent} enviados`, color: '#25d366' },
    { label: 'Tasa de entrega', value: pct(c.delivered, c.sent) + '%', sub: `${c.delivered} entregados`, color: '#f4b740' },
  ]
  const funnelMax = Math.max(1, ...d.funnel.map((f) => f.v))

  return (
    <>
      <header className="page-head">
        <span className="ic" style={{ width: 30, height: 30, borderRadius: 9, background: 'var(--primary-soft)', display: 'grid', placeItems: 'center' }}><Icon.bolt style={{ width: 17, height: 17, fill: 'var(--primary)' }} /></span>
        <div><h1>Analíticas</h1></div>
        <span className="sub">· Métricas del equipo y las campañas</span>
        <div className="spacer" />
        <button className="icon-btn" title="Actualizar" onClick={load}><Icon.refresh /></button>
      </header>

      <div className="page-scroll">
        <div className="page" style={{ maxWidth: 1180 }}>
          <div className="stat-grid">
            {cards.map((c) => (
              <div className="stat-card" key={c.label}>
                <div className="stat-num" style={{ color: c.color, marginTop: 0 }}>{c.value}</div>
                <div className="stat-sub">{c.label}</div>
                <div className="muted" style={{ fontSize: 11.5, marginTop: 4 }}>{c.sub}</div>
              </div>
            ))}
          </div>

          <div className="an-grid">
            {/* Embudo */}
            <div className="card an-card">
              <div className="an-h"><Icon.kanban /> Embudo de contactos</div>
              <div className="an-funnel">
                {d.funnel.map((f, i) => (
                  <div className="an-funnel-row" key={i}>
                    <span className="an-funnel-bar" style={{ width: Math.max(8, (f.v / funnelMax) * 100) + '%' }}>{f.v}</span>
                    <span className="an-funnel-k">{f.k}{i > 0 && <span className="an-funnel-pct"> · {pct(f.v, d.funnel[0].v)}%</span>}</span>
                  </div>
                ))}
              </div>
            </div>

            {/* Conversaciones por etiqueta (top + "ver todas" con scroll) */}
            <div className="card an-card">
              <div className="an-h"><Icon.tag /> Conversaciones por etiqueta</div>
              <div className={allLabels ? 'an-bars-scroll' : ''}>
                <Bars items={labelsShown.map((l) => ({ k: l.name, v: Number(l.n), c: l.color }))} />
              </div>
              {labelsSorted.length > LABELS_TOP && (
                <button className="link-btn" style={{ marginTop: 10 }} onClick={() => setAllLabels((v) => !v)}>
                  {allLabels ? 'Ver menos' : `Ver todas (${labelsSorted.length})`}
                </button>
              )}
            </div>

            {/* Rendimiento de campañas */}
            <div className="card an-card">
              <div className="an-h"><Icon.send /> Rendimiento de campañas</div>
              {c.recipients === 0 ? <p className="muted" style={{ fontSize: 12.5 }}>Aún no has lanzado campañas.</p> : (
                <Bars items={[
                  { k: 'Enviados', v: c.sent, c: '#25d366' },
                  { k: 'Entregados', v: c.delivered, c: '#4a9bff' },
                  { k: 'Leídos', v: c.read, c: '#00c39a' },
                  { k: 'Fallidos', v: c.failed, c: 'var(--danger)' },
                ]} />
              )}
              {c.recipients > 0 && <div className="muted" style={{ fontSize: 12, marginTop: 10 }}>{c.total} campañas · {c.recipients} destinatarios · entrega {pct(c.delivered, c.sent)}% · lectura {pct(c.read, c.sent)}%</div>}
            </div>

            {/* Mensajes por operador */}
            <div className="card an-card">
              <div className="an-h"><Icon.user /> Mensajes por operador</div>
              <Bars items={d.by_agent.map((a) => ({ k: a.name, v: Number(a.n) }))} color="#9b6dff" />
            </div>
          </div>
        </div>
      </div>
    </>
  )
}
