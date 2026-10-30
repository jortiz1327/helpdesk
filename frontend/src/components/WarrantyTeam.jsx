import { useState, useEffect } from 'react'
import { api } from '../api.js'
import { Icon } from '../icons.jsx'

/* -------------------------------------------------------------------------
 * EQUIPO e INFORMES de Gestión de garantías.
 * Equipo: carga de cada responsable sobre las garantías abiertas; cada número abre la
 * lista de garantías con ese filtro. Informes: garantías recibidas en un rango,
 * agrupadas por estado, urgencia, categoría, responsable, cliente o mes.
 * ---------------------------------------------------------------------- */

const num = (n) => Number(n || 0).toLocaleString('es-ES')
const hoy = () => new Date().toISOString().slice(0, 10)
const iso = (d) => new Date(d.getTime() - d.getTimezoneOffset() * 60000).toISOString().slice(0, 10)

function Cabecera({ icon: Ic, title, sub }) {
  return (
    <header className="page-head">
      <span className="sc-ic"><Ic style={{ width: 18, height: 18, fill: 'var(--primary)' }} /></span>
      <div><h1>{title}</h1></div>
      <span className="sub">· {sub}</span>
    </header>
  )
}

/* --- Equipo --- */
const COLS = [
  ['open', 'Abiertas', 'open', ''],
  ['critical', 'Críticas', 'critical', 'crit'],
  ['alerts', 'Con alertas', 'alerts', 'warn'],
  ['urgent', 'Urgentes', 'urgent', ''],
  ['stale', 'Sin actividad', 'stale', ''],
  ['pte_cliente', 'Pte. cliente', 'pte_cliente', ''],
  ['pte_zkong', 'Pte. ZKONG', 'pte_zkong', ''],
  ['unreviewed', 'Dispositivos sin revisar', 'unreviewed', ''],
]

export function WarrantyTeam({ onFilter }) {
  const [d, setD] = useState(null)
  useEffect(() => { api.warrantyTeam().then((r) => setD(r.ok ? r : { rows: [], error: r.error })) }, [])

  const total = Object.fromEntries(COLS.map(([k]) => [k, (d?.rows || []).reduce((s, r) => s + r[k], 0)]))
  const abrir = (r, chip) => onFilter({ responsible: r.id, name: r.name, chip: chip === 'unreviewed' ? 'unreviewed' : chip })
  const celda = (r, [k, , chip, tono]) => (
    <td key={k} className="c">
      {r[k] ? <button className={`wt-n ${tono}`} title={`Ver ${r[k]} en la lista`} onClick={() => abrir(r, chip)}>{num(r[k])}</button> : <span className="wt-0">0</span>}
    </td>
  )

  return (
    <>
      <Cabecera icon={Icon.user} title="Equipo" sub="Carga de cada responsable en las garantías abiertas" />
      <div className="page-scroll"><div className="page dev-page wr-page">
        {!d ? <div className="center-load"><div className="spinner" /></div> : (
          <>
            <p className="dev-mute wt-help">Pulsa un número para ver esas garantías. «Sin actividad»: más de {d.stale_days} días sin movimiento. Las alertas siguen las reglas de Configuración.</p>
            <div className="dev-card">
              <div className="dev-table-wrap keep wt-wrap">
                <table className="dev-table wt-table">
                  <thead><tr><th>Responsable</th>{COLS.map(([k, l]) => <th key={k} className="c">{l}</th>)}</tr></thead>
                  <tbody>
                    {d.rows.map((r) => (
                      <tr key={r.id} className={r.id === 'none' ? 'wt-none' : ''}>
                        <td><b>{r.id === 'none' ? 'Sin responsable' : r.name}</b></td>
                        {COLS.map((c) => celda(r, c))}
                      </tr>
                    ))}
                  </tbody>
                  {d.rows.length > 1 && (
                    <tfoot><tr><td>Total</td>{COLS.map(([k]) => <td key={k} className="c">{num(total[k])}</td>)}</tr></tfoot>
                  )}
                </table>
              </div>
            </div>
          </>
        )}
      </div></div>
    </>
  )
}

/* --- Informes --- */
const GRUPOS = [['status', 'Estado'], ['urgency', 'Urgencia'], ['category', 'Categoría'], ['responsible', 'Responsable'], ['client', 'Cliente'], ['month', 'Mes']]
const RANGOS = [
  ['mes', 'Este mes', () => { const n = new Date(); return [iso(new Date(n.getFullYear(), n.getMonth(), 1)), hoy()] }],
  ['3m', 'Últimos 3 meses', () => { const n = new Date(); return [iso(new Date(n.getFullYear(), n.getMonth() - 2, 1)), hoy()] }],
  ['anio', 'Este año', () => [`${new Date().getFullYear()}-01-01`, hoy()]],
  ['todo', 'Todo', () => ['', '']],
]
// Colores de las soluciones (los mismos que en la revisión) y de las respuestas de ZKONG.
const RES_COLOR = { pendiente: '#94a3b8', correcta: '#16a34a', bateria: '#d97706', recuperada: '#2563eb', reparada: '#6366f1', no_reproduce: '#8b5cf6', defectuosa: '#dc2626', danada: '#ea580c', desechada: '#64748b' }
const ZK_COLOR = { reposicion: '#16a34a', abono: '#6366f1', rechazada: '#dc2626' }

function Reparto({ title, data, labels, colors, empty }) {
  const filas = Object.entries(data || {}).sort((a, b) => b[1] - a[1])
  const total = filas.reduce((s, [, n]) => s + n, 0)
  return (
    <section className="ws-card">
      <div className="ws-card-h"><h3>{title}</h3><span className="dev-mute">{num(total)}</span></div>
      {!filas.length ? <p className="dev-mute">{empty}</p> : (
        <ul className="wt-rep">
          {filas.map(([k, n]) => (
            <li key={k} title={`${labels?.[k] || k}: ${num(n)} (${Math.round((n / total) * 100)} %)`}>
              <span className="wt-rep-l"><i style={{ background: colors[k] || '#94a3b8' }} />{labels?.[k] || k}</span>
              <span className="wt-bar"><i style={{ width: `${(n / total) * 100}%` }} /></span>
              <b>{num(n)}</b><span className="dev-mute">{Math.round((n / total) * 100)} %</span>
            </li>
          ))}
        </ul>
      )}
    </section>
  )
}

export function WarrantyReports() {
  const [rango, setRango] = useState('anio')
  const [[desde, hasta], setFechas] = useState(RANGOS[2][2]())
  const [grupo, setGrupo] = useState('status')
  const [d, setD] = useState(null)
  const [cargando, setCargando] = useState(true)
  const [rv, setRv] = useState(null)

  useEffect(() => { api.warrantyOptions().then((r) => r.ok && setRv(r.review)) }, [])
  useEffect(() => {
    setCargando(true)
    api.warrantyReport({ from: desde, to: hasta, group: grupo }).then((r) => { setCargando(false); setD(r.ok ? r : { rows: [], total: {}, error: r.error }) })
  }, [desde, hasta, grupo])

  const elegirRango = ([k, , f]) => { setRango(k); setFechas(f()) }
  const fecha = (i) => (e) => { setRango(''); setFechas((x) => (i ? [x[0], e.target.value] : [e.target.value, x[1]])) }
  const t = d?.total || {}
  const max = Math.max(1, ...(d?.rows || []).map((r) => r.total))
  const nombreGrupo = GRUPOS.find(([k]) => k === grupo)?.[1]

  const exportar = () => {
    const esc = (v) => `"${String(v ?? '').replace(/"/g, '""')}"`
    const cab = [nombreGrupo, 'Total', 'Abiertas', 'Cerradas', 'Días medios hasta el cierre', 'Dispositivos', 'Revisados']
    const fila = (r, l) => [l, r.total, r.open, r.closed, r.avg_days == null ? '' : String(r.avg_days).replace('.', ','), r.devices, r.reviewed]
    const lineas = [...d.rows.map((r) => fila(r, r.label)), fila(t, 'Total')]
    const blob = new Blob(['﻿' + [cab.map(esc).join(';'), ...lineas.map((l) => l.map(esc).join(';'))].join('\r\n')], { type: 'text/csv;charset=utf-8' })
    const a = document.createElement('a')
    a.href = URL.createObjectURL(blob); a.download = `informe-garantias-${grupo}-${hoy()}.csv`; a.click()
    setTimeout(() => URL.revokeObjectURL(a.href), 1000)
  }

  return (
    <>
      <Cabecera icon={Icon.chart} title="Informes" sub="Garantías recibidas en un periodo" />
      <div className="page-scroll"><div className="page dev-page wr-page">
        <div className="wt-filtros">
          <div className="wr-chips">{RANGOS.map((r) => <button key={r[0]} className={rango === r[0] ? 'on' : ''} onClick={() => elegirRango(r)}>{r[1]}</button>)}</div>
          <label>Desde <input type="date" value={desde} max={hasta || undefined} onChange={fecha(0)} /></label>
          <label>Hasta <input type="date" value={hasta} min={desde || undefined} onChange={fecha(1)} /></label>
          <span className="spacer" />
          <button className="dev-btn" disabled={!d?.rows?.length} onClick={exportar}><Icon.download /> Exportar CSV</button>
        </div>

        <div className="dev-kpis wt-kpis">
          <div className="dev-kpi k-total"><span className="k">Garantías</span><span className="v">{num(t.total)}</span><span className="h">recibidas en el periodo</span></div>
          <div className="dev-kpi k-total"><span className="k">Abiertas / cerradas</span><span className="v">{num(t.open)} <small>/ {num(t.closed)}</small></span><span className="h">{t.total ? `${Math.round((t.closed / t.total) * 100)} % cerradas` : '—'}</span></div>
          <div className="dev-kpi k-total"><span className="k">Tiempo medio de cierre</span><span className="v">{t.avg_days == null ? '—' : String(t.avg_days).replace('.', ',')} <small>{t.avg_days == null ? '' : 'días'}</small></span><span className="h">de la recepción al cierre</span></div>
          <div className="dev-kpi k-total"><span className="k">Dispositivos revisados</span><span className="v">{num(t.reviewed)} <small>/ {num(t.devices)}</small></span><span className="h">{t.devices ? `${Math.round((t.reviewed / t.devices) * 100)} % de los recibidos` : '—'}</span></div>
        </div>

        <div className={`dev-card ${cargando ? 'busy' : ''}`}>
          {cargando && <div className="dev-progress" />}
          <div className="dev-tools">
            <span className="dev-mute wt-agr">Agrupar por</span>
            <div className="wr-chips sm" style={{ margin: 0 }}>{GRUPOS.map(([k, l]) => <button key={k} className={grupo === k ? 'on' : ''} onClick={() => setGrupo(k)}>{l}</button>)}</div>
          </div>
          <div className="dev-table-wrap keep wt-wrap">
            <table className="dev-table wt-table">
              <thead><tr><th>{nombreGrupo}</th><th>Total</th><th className="c">Abiertas</th><th className="c">Cerradas</th><th className="c">Días hasta el cierre</th><th className="c">Dispositivos</th><th className="c">Revisados</th></tr></thead>
              <tbody>
                {(d?.rows || []).map((r) => (
                  <tr key={r.key || '—'}>
                    <td className="wr-ell"><b>{r.label}</b></td>
                    <td><span className="wt-tot" title={`${r.label}: ${num(r.total)}`}><span className="wt-bar"><i style={{ width: `${(r.total / max) * 100}%` }} /></span>{num(r.total)}</span></td>
                    <td className="c">{num(r.open)}</td>
                    <td className="c">{num(r.closed)}</td>
                    <td className="c">{r.avg_days == null ? <span className="wt-0">—</span> : String(r.avg_days).replace('.', ',')}</td>
                    <td className="c">{num(r.devices)}</td>
                    <td className="c">{r.devices ? `${num(r.reviewed)} (${Math.round((r.reviewed / r.devices) * 100)} %)` : <span className="wt-0">—</span>}</td>
                  </tr>
                ))}
              </tbody>
              {d?.rows?.length > 1 && (
                <tfoot><tr><td>Total</td><td>{num(t.total)}</td><td className="c">{num(t.open)}</td><td className="c">{num(t.closed)}</td><td className="c">{t.avg_days == null ? '—' : String(t.avg_days).replace('.', ',')}</td><td className="c">{num(t.devices)}</td><td className="c">{num(t.reviewed)}</td></tr></tfoot>
              )}
            </table>
            {d && !d.rows.length && <div className="act-empty"><Icon.chart style={{ width: 30, height: 30, fill: 'var(--ink-3)' }} /><p>No hay garantías recibidas en este periodo.</p></div>}
          </div>
        </div>

        <div className="ws-grid">
          <Reparto title="Soluciones de los dispositivos" data={d?.resolutions} colors={RES_COLOR}
            labels={Object.fromEntries(Object.entries(rv?.resolutions || {}).map(([k, v]) => [k, v.label]))} empty="Sin dispositivos en el periodo." />
          <Reparto title="Respuestas de ZKONG" data={d?.zkong_results} colors={ZK_COLOR} labels={rv?.zkong_results} empty="Todavía sin respuestas de ZKONG en el periodo." />
        </div>
      </div></div>
    </>
  )
}
