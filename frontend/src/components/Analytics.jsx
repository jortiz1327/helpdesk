import { useState, useEffect, useCallback } from 'react'
import { api } from '../api.js'
import { Icon } from '../icons.jsx'
import { fmtDateShort } from '../util.js'
import LoadError from './LoadError.jsx'

const eur = (n) => '€' + Number(n || 0).toFixed(2).replace('.', ',')
const nf = (n) => Number(n || 0).toLocaleString('es-ES')
const pct = (a, b) => (b > 0 ? Math.round((a / b) * 100) : 0)
const catColor = (c) => ({ MARKETING: 'var(--warn)', UTILITY: 'var(--primary)', AUTHENTICATION: 'var(--info)' }[c] || 'var(--secondary)')
const ESTADO = { scheduled: 'Programada', sending: 'Enviando', sent: 'Enviada', done: 'Enviada', completed: 'Enviada', canceled: 'Cancelada' }

const MROW = { display: 'flex', justifyContent: 'space-between', alignItems: 'baseline', fontSize: 13.5, padding: '7px 0', borderBottom: '1px solid var(--line)' }
const TD = { padding: '9px 12px', borderBottom: '1px solid var(--line)', fontSize: 13, whiteSpace: 'nowrap' }
const TH = { ...TD, textAlign: 'left', fontSize: 11.5, letterSpacing: '.04em', textTransform: 'uppercase', color: 'var(--ink-3, #8a94a3)', fontWeight: 700 }

// Barras horizontales genéricas (para «contactos por etiqueta»)
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

// Mini-panel de un canal (WhatsApp / Correo) con sus tasas.
function ChanBox({ label, dot, c }) {
  const line = { ...MROW, padding: '5px 0', border: 0, fontSize: 12.5, color: 'var(--ink-2)' }
  return (
    <div style={{ border: '1px solid var(--line)', borderRadius: 11, padding: '12px 13px', background: 'var(--panel-2)' }}>
      <div style={{ display: 'flex', alignItems: 'center', gap: 7, fontWeight: 700, fontSize: 13, marginBottom: 8 }}><span style={{ width: 9, height: 9, borderRadius: '50%', background: dot }} />{label}</div>
      <div style={{ fontSize: 22, fontWeight: 800 }}>{nf(c.sent)}</div>
      <div className="muted" style={{ fontSize: 12 }}>enviados</div>
      <div style={line}><span>Entregados</span><b style={{ color: 'var(--secondary)' }}>{pct(c.delivered, c.sent)}%</b></div>
      {label === 'WhatsApp'
        ? <div style={line}><span>Leídos</span><b>{pct(c.read, c.sent)}%</b></div>
        : <div style={line}><span>Fallidos</span><b style={{ color: c.failed ? 'var(--danger)' : 'inherit' }}>{c.failed}</b></div>}
    </div>
  )
}

export default function Analytics() {
  const [d, setD] = useState(null)
  const [err, setErr] = useState(false)
  const [tab, setTab] = useState('resumen')          // 'resumen' | 'historial'
  const load = useCallback(() => { setErr(false); api.analytics().then((r) => r.ok ? setD(r) : setErr(true)).catch(() => setErr(true)) }, [])
  useEffect(() => { load() }, [load])

  const tabs = (
    <div className="kb-seg" style={{ marginBottom: 16 }}>
      <button className={tab === 'resumen' ? 'on' : ''} onClick={() => setTab('resumen')}>Resumen</button>
      <button className={tab === 'historial' ? 'on' : ''} onClick={() => setTab('historial')}>Historial de campañas</button>
    </div>
  )

  return (
    <>
      <header className="page-head">
        <span className="ic" style={{ width: 30, height: 30, borderRadius: 9, background: 'var(--primary-soft)', display: 'grid', placeItems: 'center' }}><Icon.chart style={{ width: 17, height: 17, fill: 'var(--primary)' }} /></span>
        <div><h1>Analíticas</h1></div>
        <span className="sub">· Campañas, difusiones y formularios</span>
        <div className="spacer" />
        <button className="icon-btn" title="Actualizar" onClick={load}><Icon.refresh /></button>
      </header>

      <div className="page-scroll">
        <div className="page" style={{ maxWidth: 1180 }}>
          {tabs}

          {tab === 'historial' ? <CampaignHistory /> : (
            err && !d ? <LoadError onRetry={load} msg="No se pudieron cargar las analíticas" />
              : !d ? <div className="center-load"><div className="spinner" /></div>
                : <Resumen d={d} />
          )}
        </div>
      </div>
    </>
  )
}

/* --------------------------- Resumen (campañas) --------------------------- */
function Resumen({ d }) {
  const k = d.kpi
  const cards = [
    { label: 'Campañas lanzadas', value: nf(k.campaigns_total), sub: `${k.campaigns_month} este mes`, color: 'var(--primary)' },
    { label: 'Mensajes enviados', value: nf(k.msgs_sent), sub: `${nf(k.msgs_delivered)} entregados`, color: 'var(--info)' },
    { label: 'Tasa de entrega', value: k.delivery_rate + '%', sub: `${k.read_rate}% leídos (WhatsApp)`, color: 'var(--secondary)' },
    { label: 'Bajas (opt-out)', value: nf(k.optout_total), sub: `+${k.optout_month} este mes`, color: 'var(--danger)' },
  ]
  const f = d.forms
  const c = d.contacts
  const labelsTop = [...(d.by_label || [])].sort((a, b) => Number(b.n) - Number(a.n)).slice(0, 8)

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

      <div className="an-grid">
        {/* Rendimiento por canal */}
        <div className="card an-card">
          <div className="an-h"><Icon.send /> Rendimiento por canal</div>
          <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 12 }}>
            <ChanBox label="WhatsApp" dot="var(--secondary)" c={d.channels.whatsapp} />
            <ChanBox label="Correo" dot="var(--info)" c={d.channels.email} />
          </div>
        </div>

        {/* Formularios */}
        <div className="card an-card">
          <div className="an-h"><Icon.forms /> Formularios</div>
          <div style={MROW}><span>Formularios publicados</span><b>{f.published}</b></div>
          <div style={MROW}><span>Veces enviado</span><b>{nf(f.sends)}</b></div>
          <div style={MROW}><span>Respuestas recibidas</span><b style={{ color: 'var(--secondary)' }}>{nf(f.submissions)}</b></div>
          <div style={{ ...MROW, borderBottom: 0 }}><span>Tasa de respuesta</span><b style={{ color: 'var(--secondary)' }}>{f.response_rate}%</b></div>
        </div>

        {/* Base de contactos */}
        <div className="card an-card">
          <div className="an-h"><Icon.user /> Base de contactos</div>
          <div style={MROW}><span>Alcanzables por WhatsApp</span><b>{nf(c.whatsapp)}</b></div>
          <div style={MROW}><span>Alcanzables por correo</span><b>{nf(c.email)}</b></div>
          <div style={{ ...MROW, borderBottom: 0 }}><span>Dados de baja</span><b style={{ color: c.optout ? 'var(--danger)' : 'inherit' }}>{nf(c.optout)}</b></div>
          <p className="muted" style={{ fontSize: 12, marginTop: 8 }}>+{nf(c.new_month)} contactos nuevos este mes · {nf(c.total)} en total</p>
        </div>

        {/* Contactos por etiqueta */}
        <div className="card an-card">
          <div className="an-h"><Icon.tag /> Contactos por etiqueta</div>
          <Bars items={labelsTop.map((l) => ({ k: l.name, v: Number(l.n), c: l.color }))} />
        </div>
      </div>

      {/* Últimas campañas */}
      <div className="card" style={{ padding: 0, marginTop: 14, overflowX: 'auto' }}>
        <div className="an-h" style={{ padding: '14px 16px 0' }}><Icon.send /> Últimas campañas</div>
        {(!d.recent || d.recent.length === 0) ? (
          <p className="muted" style={{ padding: '10px 16px 16px', fontSize: 12.5 }}>Aún no has lanzado campañas.</p>
        ) : (
          <table style={{ borderCollapse: 'collapse', width: '100%', minWidth: 640, marginTop: 8 }}>
            <thead><tr>
              <th style={TH}>Campaña</th><th style={TH}>Canal</th>
              <th style={{ ...TH, textAlign: 'right' }}>Enviados</th>
              <th style={{ ...TH, textAlign: 'right' }}>Entrega</th>
              <th style={{ ...TH, textAlign: 'right' }}>Lectura</th>
            </tr></thead>
            <tbody>
              {d.recent.map((c) => (
                <tr key={c.id}>
                  <td style={{ ...TD, fontWeight: 600, whiteSpace: 'normal', maxWidth: 240 }}>{c.title}</td>
                  <td style={TD}><span className="pill gray sm" style={{ color: c.channel === 'email' ? 'var(--info)' : 'var(--secondary)' }}>{c.channel === 'email' ? 'Correo' : 'WhatsApp'}</span></td>
                  <td style={{ ...TD, textAlign: 'right', fontVariantNumeric: 'tabular-nums' }}>{nf(c.sent)}</td>
                  <td style={{ ...TD, textAlign: 'right', fontVariantNumeric: 'tabular-nums' }}>{pct(c.delivered, c.sent)}%</td>
                  <td style={{ ...TD, textAlign: 'right', fontVariantNumeric: 'tabular-nums' }}>{c.channel === 'email' ? '—' : pct(c.read, c.sent) + '%'}</td>
                </tr>
              ))}
            </tbody>
          </table>
        )}
      </div>
    </>
  )
}

/* -------------------- Historial / trazabilidad de campañas --------------------
 * Cada campaña: quién la lanzó, cuándo, destino, plantilla, categoría, resultados
 * (enviados/entregados/leídos/fallidos) y coste real (entregados × tarifa).
 * Coste solo si el backend lo autoriza (show_cost = superadmin).
 * ---------------------------------------------------------------------------- */
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
