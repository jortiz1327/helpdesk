import { useState, useEffect, useCallback, useRef } from 'react'
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
          <th title="Nota media de satisfacción (1-5★) y nº de valoraciones del portal">CSAT</th>
        </tr></thead>
        <tbody>
          {rows.map((r, i) => (
            <tr key={r.id ?? r.name ?? i} className={r.active === false ? 'rep-row-off' : ''}>
              <td className="org-rep-name">
                {colorDot && <span className="rep-dot" style={{ background: r.color || 'var(--ink-3)' }} />}
                {r.name}
                {r.active === false && <span className="rep-off-tag">deshabilitado</span>}
              </td>
              <td><b>{r.total}</b></td>
              <td>{r.resueltos}</td>
              <td>{fmtH(r.resp_h)}</td>
              <td>{fmtH(r.resol_h)}</td>
              <td>{r.csat_n > 0
                ? <span className="rep-csat" title={`${r.csat_n} valoración(es)`}>{r.csat}<span className="rep-star">★</span> <small className="muted">· {r.csat_n}</small></span>
                : <span className="muted">—</span>}</td>
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

  const reqSeq = useRef(0)
  const load = useCallback(() => {
    const seq = ++reqSeq.current   // guarda de vigencia: al cambiar de periodo rápido, gana la última
    setD(null); setErr(false)
    api.reports(period).then((r) => {
      if (seq !== reqSeq.current) return
      r && r.ok ? setD(r) : setErr(true)
    }).catch(() => { if (seq === reqSeq.current) setErr(true) })
  }, [period])
  useEffect(() => { load() }, [load])

  const k = d?.kpis || {}
  const sla = d?.sla_activo
  const canales = Object.entries(d?.by_channel || {})
  const totalCanal = canales.reduce((a, [, n]) => a + Number(n), 0) || 1
  const maxStatus = Math.max(1, ...Object.values(d?.by_status || { x: 1 }))
  // Por agente: primero los habilitados; los deshabilitados al final (se pintan en gris).
  const agentes = [...(d?.by_agent || [])].sort((a, b) => (a.active === false) - (b.active === false))

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
      </header>

      <div className="page-scroll"><div className="page" style={{ maxWidth: 1000 }}>
        {/* Selector de periodo, bien visible (antes quedaba escondido arriba a la derecha). */}
        <div className="rep-periodbar">
          <span className="rep-periodbar-lbl"><Icon.clock /> Periodo del informe</span>
          <div className="rep-periods">
            {PERIODS.map(([kk, l]) => (
              <button key={kk} className={period === kk ? 'on' : ''} onClick={() => setPeriod(kk)}>{l}</button>
            ))}
          </div>
        </div>

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

            {d.by_status && (
              <div className="card rep-status">
                <div className="rep-ch-h"><Icon.chart /> Tickets por estado <span className="rep-hint">· en el periodo</span></div>
                <div className="rep-status-bars">
                  {Object.entries(d.by_status).map(([k, n]) => {
                    const m = d.status_meta?.[k] || {}
                    return (
                      <div key={k} className="rep-status-row">
                        <span className="rep-status-lb" style={{ color: m.color }}>{m.name || k}</span>
                        <span className="rep-status-n">{n} {n === 1 ? 'ticket' : 'tickets'}</span>
                        <span className="rep-status-bar"><i style={{ width: `${(n / maxStatus) * 100}%`, background: m.color || 'var(--primary)' }} /></span>
                      </div>
                    )
                  })}
                </div>
              </div>
            )}

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
            <Tabla rows={agentes} primeraCol="Agente" />

            <h3 className="rep-sec">Por categoría</h3>
            <Tabla rows={d.by_category} primeraCol="Categoría" colorDot />
          </>
        )}
      </div></div>
    </>
  )
}
