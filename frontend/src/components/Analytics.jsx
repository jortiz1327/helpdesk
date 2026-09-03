import { useState, useEffect, useCallback } from 'react'
import { api } from '../api.js'
import { Icon } from '../icons.jsx'
import { fmtDateShort } from '../util.js'
import LoadError from './LoadError.jsx'

const eur = (n) => '€' + Number(n || 0).toFixed(2).replace('.', ',')
const catColor = (c) => ({ MARKETING: 'var(--warn)', UTILITY: 'var(--primary)', AUTHENTICATION: 'var(--info)' }[c] || 'var(--secondary)')
const ESTADO = { scheduled: 'Programada', sending: 'Enviando', sent: 'Enviada', done: 'Enviada', completed: 'Enviada', canceled: 'Cancelada' }

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
  const [err, setErr] = useState(false)
  const [tab, setTab] = useState('resumen')          // 'resumen' | 'historial'
  const [allLabels, setAllLabels] = useState(false) // ver todas las etiquetas o solo el top
  const load = useCallback(() => { setErr(false); api.analytics().then((r) => r.ok ? setD(r) : setErr(true)).catch(() => setErr(true)) }, [])
  useEffect(() => { load() }, [load])

  if (err && !d) return <LoadError onRetry={load} msg="No se pudieron cargar las analíticas" />
  if (!d) return <div className="center-load"><div className="spinner" /></div>

  const c = d.campaigns
  // Con muchas etiquetas (200 de sedes) esto sería un listado interminable: se ordena
  // por nº de conversaciones y se muestra el top; el resto detrás de "Ver todas".
  const LABELS_TOP = 10
  const labelsSorted = [...(d.by_label || [])].sort((a, b) => Number(b.n) - Number(a.n))
  const labelsShown = allLabels ? labelsSorted : labelsSorted.slice(0, LABELS_TOP)
  const cards = [
    { label: 'Tiempo 1ª respuesta', value: fmtDur(d.first_response.avg_seconds), sub: `media de ${d.first_response.count} conversaciones`, color: 'var(--primary)' },
    { label: 'Conversaciones', value: d.funnel[1]?.v ?? 0, sub: `de ${d.funnel[0]?.v ?? 0} contactos`, color: 'var(--info)' },
    { label: 'Tasa de lectura', value: pct(c.read, c.sent) + '%', sub: `${c.read} leídos de ${c.sent} enviados`, color: 'var(--secondary)' },
    { label: 'Tasa de entrega', value: pct(c.delivered, c.sent) + '%', sub: `${c.delivered} entregados`, color: 'var(--warn)' },
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
          <div className="kb-seg" style={{ marginBottom: 16 }}>
            <button className={tab === 'resumen' ? 'on' : ''} onClick={() => setTab('resumen')}>Resumen</button>
            <button className={tab === 'historial' ? 'on' : ''} onClick={() => setTab('historial')}>Historial de campañas</button>
          </div>

          {tab === 'historial' ? <CampaignHistory /> : (<>
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
                  { k: 'Enviados', v: c.sent, c: 'var(--primary)' },
                  { k: 'Entregados', v: c.delivered, c: 'var(--info)' },
                  { k: 'Leídos', v: c.read, c: 'var(--secondary)' },
                  { k: 'Fallidos', v: c.failed, c: 'var(--danger)' },
                ]} />
              )}
              {c.recipients > 0 && <div className="muted" style={{ fontSize: 12, marginTop: 10 }}>{c.total} campañas · {c.recipients} destinatarios · entrega {pct(c.delivered, c.sent)}% · lectura {pct(c.read, c.sent)}%</div>}
            </div>

            {/* Mensajes por operador */}
            <div className="card an-card">
              <div className="an-h"><Icon.user /> Mensajes por operador</div>
              <Bars items={d.by_agent.map((a) => ({ k: a.name, v: Number(a.n) }))} color="var(--primary)" />
            </div>
          </div>
          </>)}
        </div>
      </div>
    </>
  )
}

/* -------------------- Historial / trazabilidad de campañas --------------------
 * Cada campaña: quién la lanzó, cuándo, destino, plantilla, categoría, resultados
 * (enviados/entregados/leídos/fallidos) y coste real (entregados × tarifa).
 * ---------------------------------------------------------------------------- */
const TD = { padding: '9px 12px', borderBottom: '1px solid var(--line)', fontSize: 13, whiteSpace: 'nowrap' }
const TH = { ...TD, textAlign: 'left', fontSize: 11.5, letterSpacing: '.04em', textTransform: 'uppercase', color: 'var(--ink-3, #8a94a3)', fontWeight: 700, position: 'sticky', top: 0, background: 'var(--surface)' }

function CampaignHistory() {
  const [d, setD] = useState(null)
  const [err, setErr] = useState(false)
  const load = useCallback(() => { setErr(false); api.campaignHistory().then((r) => r.ok ? setD(r) : setErr(true)).catch(() => setErr(true)) }, [])
  useEffect(() => { load() }, [load])

  if (err && !d) return <LoadError onRetry={load} msg="No se pudo cargar el historial" />
  if (!d) return <div className="center-load"><div className="spinner" /></div>

  const t = d.totals
  const cards = d.show_cost ? [
    { label: 'Gasto este mes', value: eur(t.month_cost), sub: `${t.month_count} campaña${t.month_count === 1 ? '' : 's'}`, color: 'var(--warn)' },
    { label: 'Gasto (histórico)', value: eur(t.all_cost), sub: `${t.all_count} campañas`, color: 'var(--primary)' },
  ] : [
    { label: 'Campañas este mes', value: t.month_count, sub: 'lanzadas este mes', color: 'var(--primary)' },
    { label: 'Campañas (histórico)', value: t.all_count, sub: 'en total', color: 'var(--info)' },
  ]

  return (
    <>
      <div className="stat-grid">
        {cards.map((c) => (
          <div className="stat-card" key={c.label}>
            <div className="stat-num" style={{ color: c.color, marginTop: 0 }}>{c.value}</div>
            <div className="stat-sub">{c.label}</div>
            <div className="muted" style={{ fontSize: 11.5, marginTop: 4 }}>{c.sub}</div>
          </div>
        ))}
      </div>

      {d.campaigns.length === 0 ? (
        <div className="empty"><div className="ico"><Icon.send /></div><p>Aún no hay campañas.</p></div>
      ) : (
        <div className="card" style={{ padding: 0, overflowX: 'auto', marginTop: 16 }}>
          <table style={{ borderCollapse: 'collapse', width: '100%', minWidth: 980 }}>
            <thead>
              <tr>
                <th style={TH}>Fecha</th><th style={TH}>Campaña</th><th style={TH}>Canal</th>
                <th style={TH}>Destino</th><th style={TH}>Plantilla</th><th style={TH}>Categoría</th>
                <th style={TH}>Lanzó</th><th style={{ ...TH, textAlign: 'right' }}>Dest.</th>
                <th style={{ ...TH, textAlign: 'right' }}>Entreg.</th><th style={{ ...TH, textAlign: 'right' }}>Leídos</th>
                <th style={{ ...TH, textAlign: 'right' }}>Fallidos</th><th style={TH}>Estado</th>
                {d.show_cost && <th style={{ ...TH, textAlign: 'right' }}>Coste</th>}
              </tr>
            </thead>
            <tbody>
              {d.campaigns.map((c) => (
                <tr key={c.id}>
                  <td style={TD}>{fmtDateShort(c.created_at)}</td>
                  <td style={{ ...TD, fontWeight: 600, whiteSpace: 'normal', maxWidth: 200 }}>{c.title}</td>
                  <td style={TD}>{c.channel === 'email' ? 'Correo' : 'WhatsApp'}</td>
                  <td style={{ ...TD, whiteSpace: 'normal', maxWidth: 160 }}>{c.source_name || '—'}</td>
                  <td style={TD}>{c.template_name || '—'}</td>
                  <td style={TD}>{c.category ? <span className="pill gray sm" style={{ color: catColor(c.category) }}>{c.category}</span> : '—'}</td>
                  <td style={TD}>{c.by_name || <span className="muted">—</span>}</td>
                  <td style={{ ...TD, textAlign: 'right', fontVariantNumeric: 'tabular-nums' }}>{c.total}</td>
                  <td style={{ ...TD, textAlign: 'right', fontVariantNumeric: 'tabular-nums' }}>{c.delivered}</td>
                  <td style={{ ...TD, textAlign: 'right', fontVariantNumeric: 'tabular-nums' }}>{c.read_count}</td>
                  <td style={{ ...TD, textAlign: 'right', fontVariantNumeric: 'tabular-nums', color: c.failed ? 'var(--danger)' : 'inherit' }}>{c.failed}</td>
                  <td style={TD}><span className="pill gray sm">{ESTADO[c.status] || c.status}</span></td>
                  {d.show_cost && <td style={{ ...TD, textAlign: 'right', fontWeight: 700, fontVariantNumeric: 'tabular-nums' }}>{c.channel === 'email' ? '—' : (c.cost > 0 ? eur(c.cost) : 'Gratis')}</td>}
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
      {d.show_cost && <p className="muted" style={{ fontSize: 12, marginTop: 10 }}>Coste real = entregados × tarifa aplicada al lanzar. Las campañas anteriores a esta función no tienen tarifa guardada (coste €0,00).</p>}
    </>
  )
}
