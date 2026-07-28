import { useState, useEffect } from 'react'
import { api } from '../api.js'
import { Icon } from '../icons.jsx'

/* -------------------------------------------------------------------------
 * INFORMES del helpdesk (rendimiento): KPIs + por agente / categoría / canal,
 * en un periodo. Datos de reports.php (analytics.view).
 * ---------------------------------------------------------------------- */

const PERIODS = [['today', 'Hoy'], ['7d', '7 días'], ['30d', '30 días'], ['all', 'Todo']]
const CHANNEL = { email: 'Correo', whatsapp: 'WhatsApp', web: 'Portal web' }
const fmtH = (h) => h == null ? '—' : (h < 1 ? Math.round(h * 60) + ' min' : (h % 1 === 0 ? h : h.toFixed(1)) + ' h')

function KPI({ n, label, color, hint }) {
  return (
    <div className="stat-card">
      <div className="stat-num" style={{ marginTop: 0, ...(color ? { color } : {}) }}>{n ?? 0}</div>
      <div className="stat-sub">{label}{hint && <span className="rep-hint"> · {hint}</span>}</div>
    </div>
  )
}

function Tabla({ rows, primeraCol, colorDot }) {
  if (!rows.length) return <div className="rep-empty">Sin tickets en este periodo.</div>
  return (
    <div className="card" style={{ padding: 0, overflowX: 'auto' }}>
      <table className="org-rep">
        <thead><tr>
          <th>{primeraCol}</th><th>Tickets</th><th>Resueltos</th>
          <th title="Tiempo medio hasta la primera respuesta">T. 1ª resp.</th>
          <th title="Tiempo medio hasta resolver">T. resolución</th>
        </tr></thead>
        <tbody>
          {rows.map((r, i) => (
            <tr key={r.id ?? r.name ?? i}>
              <td className="org-rep-name">
                {colorDot && <span className="rep-dot" style={{ background: r.color || 'var(--ink-3)' }} />}
                {r.name}
              </td>
              <td><b>{r.total}</b></td>
              <td>{r.resueltos}</td>
              <td>{fmtH(r.resp_h)}</td>
              <td>{fmtH(r.resol_h)}</td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  )
}

export default function Reports() {
  const [period, setPeriod] = useState('30d')
  const [d, setD] = useState(null)

  useEffect(() => {
    setD(null)
    api.reports(period).then((r) => setD(r.ok ? r : { kpis: {}, by_agent: [], by_category: [], by_channel: {} }))
  }, [period])

  const k = d?.kpis || {}
  const sla = d?.sla_activo
  const canales = Object.entries(d?.by_channel || {})
  const totalCanal = canales.reduce((a, [, n]) => a + Number(n), 0) || 1

  return (
    <>
      <header className="page-head">
        <span className="sc-ic"><Icon.chart style={{ width: 18, height: 18, fill: 'var(--primary)' }} /></span>
        <div><h1>Informes</h1></div>
        <span className="sub">· Rendimiento del helpdesk</span>
        <div className="spacer" />
        <div className="kb-seg">
          {PERIODS.map(([kk, l]) => (
            <button key={kk} className={period === kk ? 'on' : ''} onClick={() => setPeriod(kk)}>{l}</button>
          ))}
        </div>
      </header>

      <div className="page-scroll"><div className="page" style={{ maxWidth: 1000 }}>
        {d === null ? <div className="center-load"><div className="spinner" /></div> : (
          <>
            <div className="stat-grid rep-kpis">
              <KPI n={k.total} label="Tickets" />
              <KPI n={k.abiertos} label="Abiertos" color="#2563eb" />
              <KPI n={k.resueltos} label="Resueltos" color="#12925a" />
              {sla && <KPI n={k.vencidos} label="SLA vencido" color={k.vencidos ? '#dc2626' : undefined} />}
              <KPI n={fmtH(k.resp_h)} label="1ª respuesta" hint="media" />
              <KPI n={fmtH(k.resol_h)} label="Resolución" hint="media" />
            </div>

            {canales.length > 0 && (
              <div className="rep-channels card">
                <div className="rep-ch-h">Por canal</div>
                <div className="rep-ch-bar">
                  {canales.map(([ch, n]) => (
                    <span key={ch} className={`rep-ch-seg ch-${ch}`} style={{ width: `${(Number(n) / totalCanal) * 100}%` }} title={`${CHANNEL[ch] || ch}: ${n}`} />
                  ))}
                </div>
                <div className="rep-ch-legend">
                  {canales.map(([ch, n]) => (
                    <span key={ch}><span className={`rep-ch-dot ch-${ch}`} />{CHANNEL[ch] || ch} <b>{n}</b></span>
                  ))}
                </div>
              </div>
            )}

            <h3 className="rep-sec">Por agente</h3>
            <Tabla rows={d.by_agent} primeraCol="Agente" />

            <h3 className="rep-sec">Por categoría</h3>
            <Tabla rows={d.by_category} primeraCol="Categoría" colorDot />
          </>
        )}
      </div></div>
    </>
  )
}
