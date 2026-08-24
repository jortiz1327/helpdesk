import { useState, useEffect, useCallback } from 'react'
import { api } from '../api.js'
import { Icon } from '../icons.jsx'
import TrendChart from './TrendChart.jsx'
import LoadError from './LoadError.jsx'

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

export default function Reports({ onGo }) {
  const [period, setPeriod] = useState('30d')
  const [d, setD] = useState(null)
  const [err, setErr] = useState(false)

  const load = useCallback(() => {
    setD(null); setErr(false)
    api.reports(period).then((r) => r && r.ok ? setD(r) : setErr(true))
      .catch(() => setErr(true))
  }, [period])
  useEffect(() => { load() }, [load])

  const k = d?.kpis || {}
  const sla = d?.sla_activo
  const canales = Object.entries(d?.by_channel || {})
  const totalCanal = canales.reduce((a, [, n]) => a + Number(n), 0) || 1

  return (
    <>
      <header className="page-head">
        <span className="sc-ic"><Icon.headset style={{ width: 18, height: 18, fill: 'var(--primary)' }} /></span>
        <div><h1>Centro de Soporte</h1></div>
        {/* Misma pestaña que en el Centro de Soporte: aquí «Informes» está activo. */}
        <div className="kb-seg" style={{ margin: '0 0 0 18px' }}>
          <button onClick={() => onGo?.('support')}>Resumen</button>
          <button className="on"><Icon.chart /> Informes</button>
        </div>
        <div className="spacer" />
        <div className="kb-seg">
          {PERIODS.map(([kk, l]) => (
            <button key={kk} className={period === kk ? 'on' : ''} onClick={() => setPeriod(kk)}>{l}</button>
          ))}
        </div>
      </header>

      <div className="page-scroll"><div className="page" style={{ maxWidth: 1000 }}>
        {err && d === null ? (
          <LoadError onRetry={load} msg="No se pudieron cargar los informes" />
        ) : d === null ? (
          <div className="stat-grid rep-kpis" aria-hidden="true">
            {Array.from({ length: 6 }).map((_, i) => (
              <div className="stat-card" key={i}>
                <div className="stat-num"><span className="sk" style={{ width: 54, height: 26 }} /></div>
                <div className="stat-sub"><span className="sk" style={{ width: 72, height: 10 }} /></div>
              </div>
            ))}
          </div>
        ) : (
          <>
            <div className="stat-grid rep-kpis">
              <KPI n={k.total} label="Tickets" />
              <KPI n={k.abiertos} label="Abiertos" color="#2563eb" />
              <KPI n={k.resueltos} label="Resueltos" color="#12925a" />
              {sla && <KPI n={k.vencidos} label="SLA vencido" color={k.vencidos ? '#dc2626' : undefined} />}
              <KPI n={fmtH(k.resp_h)} label="1ª respuesta" hint="media" />
              <KPI n={fmtH(k.resol_h)} label="Resolución" hint="media" />
            </div>

            {d.daily?.length > 0 && <TrendChart data={d.daily} />}

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

            {d.csat?.respuestas > 0 && (
              <div className="rep-csat card">
                <div className="rep-ch-h">Satisfacción del cliente <span className="rep-hint">· incidencias del portal</span></div>
                <div className="csat-rep">
                  <div className="csat-rep-nums">
                    <div className="csat-big">
                      <span className="csat-big-n">{d.csat.media}<Icon.star className="on" /></span>
                      <small>nota media</small>
                    </div>
                    <div className="csat-big">
                      <span className="csat-big-n" style={{ color: d.csat.satisfechos_pct >= 80 ? '#12925a' : d.csat.satisfechos_pct < 50 ? '#dc2626' : undefined }}>{d.csat.satisfechos_pct}%</span>
                      <small>satisfechos · 4-5★</small>
                    </div>
                    <div className="csat-big">
                      <span className="csat-big-n">{d.csat.respuestas}</span>
                      <small>{d.csat.respuestas === 1 ? 'respuesta' : 'respuestas'}</small>
                    </div>
                  </div>
                  <div className="csat-dist">
                    {[5, 4, 3, 2, 1].map((s) => {
                      const n = d.csat.dist[s - 1]
                      const pct = Math.round((100 * n) / d.csat.respuestas)
                      return (
                        <div className="csat-dist-row" key={s}>
                          <span className="csat-dist-lb">{s}<Icon.star className="on" /></span>
                          <span className="csat-dist-bar"><span style={{ width: `${pct}%` }} /></span>
                          <span className="csat-dist-n">{n}</span>
                        </div>
                      )
                    })}
                  </div>
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
