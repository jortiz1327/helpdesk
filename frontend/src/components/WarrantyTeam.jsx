import { useState, useEffect } from 'react'
import { api } from '../api.js'
import { csvCell } from '../csv.js'
import { Icon } from '../icons.jsx'
import Select from './Select.jsx'
import DateRange from './DateRange.jsx'

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
// Lo abierto es siempre el ahora; cerradas, días de cierre y revisados son del periodo.
const PERIODOS = [
  ['mes', 'Este mes', () => { const n = new Date(); return [iso(new Date(n.getFullYear(), n.getMonth(), 1)), hoy()] }],
  ['prev', 'Mes anterior', () => { const n = new Date(); return [iso(new Date(n.getFullYear(), n.getMonth() - 1, 1)), iso(new Date(n.getFullYear(), n.getMonth(), 0))] }],
  ['3m', 'Últimos 3 meses', () => { const n = new Date(); return [iso(new Date(n.getFullYear(), n.getMonth() - 2, 1)), hoy()] }],
  ['anio', `Año ${new Date().getFullYear()}`, () => [`${new Date().getFullYear()}-01-01`, hoy()]],
]
// [título, qué cuenta, ¿del periodo?]
const CABECERAS = [
  ['Garantías abiertas', 'Garantías asignadas que aún no están cerradas', false],
  ['Dispositivos por revisar', 'Etiquetas y dispositivos de sus garantías abiertas que todavía no tienen resultado de revisión', false],
  ['Esperando al cliente', 'Garantías en estado «Pendiente cliente»: falta algo del cliente para seguir', false],
  ['Esperando a ZKONG', 'Garantías en estado «Pendiente ZKONG»: enviadas a ZKONG, a la espera de su respuesta', false],
  ['Necesitan atención', 'Garantías con alguna alerta (llevan demasiado en un estado, sin moverse…). En rojo si alguna es crítica. Las reglas están en Configuración', false],
  ['Garantías cerradas', 'Garantías que cerró en el periodo elegido', true],
  ['Tiempo medio en cerrarlas', 'Días de media desde que se recibió la garantía hasta que se cerró', true],
  ['Dispositivos revisados', 'Dispositivos a los que puso resultado de revisión en el periodo elegido', true],
]
const iniciales = (n) => String(n || '').trim().split(/\s+/).slice(0, 2).map((p) => p[0]).join('').toUpperCase()
const dec = (n) => (n == null ? '—' : String(n).replace('.', ','))

export function WarrantyTeam({ onFilter }) {
  const [periodo, setPeriodo] = useState('mes')
  const [q, setQ] = useState('')
  const [d, setD] = useState(null)
  const [cargando, setCargando] = useState(true)
  useEffect(() => {
    const [from, to] = PERIODOS.find(([k]) => k === periodo)[2]()
    setCargando(true)
    api.warrantyTeam({ from, to }).then((r) => { setCargando(false); setD(r.ok ? r : { rows: [], error: r.error }) })
  }, [periodo])

  const todas = d?.rows || []
  const busca = q.trim().toLowerCase()
  const filas = busca ? todas.filter((r) => (r.id === 'none' ? 'sin responsable' : (r.name || '').toLowerCase()).includes(busca)) : todas
  const suma = (k) => todas.reduce((s, r) => s + (r[k] || 0), 0)
  const abiertas = suma('open')
  const cerradas = suma('closed')
  const mediaCierre = cerradas ? Math.round((todas.reduce((s, r) => s + (r.avg_days || 0) * r.closed, 0) / cerradas) * 10) / 10 : null
  const nombrePeriodo = PERIODOS.find(([k]) => k === periodo)[1]
  const abrir = (r, chip) => onFilter({ responsible: r.id, name: r.name, chip })
  const n = (r, k, chip, tono = '') => (r[k]
    ? <button className={`wt-n ${tono}`} title={`Ver ${r[k]} en la lista`} onClick={() => abrir(r, chip)}>{num(r[k])}</button>
    : <span className="wt-0">0</span>)

  const exportar = () => {
    const esc = csvCell
    const cab = ['Responsable', 'Abiertas', 'Dispositivos sin revisar', 'Pte. cliente', 'Pte. ZKONG', 'Con alertas', 'Críticas', 'Urgentes', 'Sin actividad', 'Cerradas', 'Días medios de cierre', 'Dispositivos revisados']
    const lineas = todas.map((r) => [r.id === 'none' ? 'Sin responsable' : r.name, r.open, r.unreviewed, r.pte_cliente, r.pte_zkong, r.alerts, r.critical, r.urgent, r.stale, r.closed, r.avg_days == null ? '' : dec(r.avg_days), r.reviewed])
    const blob = new Blob(['﻿' + [cab, ...lineas].map((l) => l.map(esc).join(';')).join('\r\n')], { type: 'text/csv;charset=utf-8' })
    const a = document.createElement('a')
    a.href = URL.createObjectURL(blob)
    a.download = `equipo-garantias-${d.from}-${d.to}.csv`
    a.click()
    setTimeout(() => URL.revokeObjectURL(a.href), 1000)
  }

  return (
    <>
      <Cabecera icon={Icon.user} title="Equipo" sub="Carga de cada responsable y lo hecho en el periodo" />
      <div className="page-scroll"><div className="page dev-page wr-page">
        <div className="wt-filtros">
          <div className="dev-search wl-search">
            <Icon.search />
            <input value={q} onChange={(e) => setQ(e.target.value)} placeholder="Buscar responsable…" />
            {q ? <button className="dev-x" onClick={() => setQ('')} title="Borrar">×</button> : null}
          </div>
          <div className="dc-seg">{PERIODOS.map(([k, l]) => <button key={k} className={periodo === k ? 'on' : ''} onClick={() => setPeriodo(k)}>{l}</button>)}</div>
          <span className="spacer" />
          {d && !d.error ? (
            <span className="wt-resumen">
              <b>{num(abiertas)}</b> {abiertas === 1 ? 'abierta' : 'abiertas'} · <b>{num(cerradas)}</b> {cerradas === 1 ? 'cerrada' : 'cerradas'}{mediaCierre != null ? <> · cierre medio <b>{dec(mediaCierre)} días</b></> : null}
            </span>
          ) : null}
          <button className="dev-btn" disabled={!todas.length} onClick={exportar} title="Exportar la tabla a CSV"><Icon.download /> Exportar</button>
        </div>
        {!d ? <div className="center-load"><div className="spinner" /></div> : d.error ? <div className="dev-error"><Icon.warn /> {d.error}</div> : (
          <div className="dev-card wt-card">
            {cargando && <div className="dev-progress" />}
            <div className="dev-table-wrap keep wt-wrap">
              <table className="dev-table wt-table wt-eq">
                <thead>
                  <tr className="wt-grp">
                    <th /><th colSpan={5}>Lo que tiene pendiente hoy</th><th colSpan={3} className="per">Lo que ha hecho · {nombrePeriodo.toLowerCase()}</th><th />
                  </tr>
                  <tr>
                    <th>Responsable</th>
                    {CABECERAS.map(([l, t, per]) => <th key={l} className={`c ${per ? 'per' : ''}`} title={t}>{l}</th>)}
                    <th />
                  </tr>
                </thead>
                <tbody>
                  {filas.map((r) => {
                    const avisos = [
                      r.urgent ? <button key="u" className="wf-link" onClick={() => abrir(r, 'urgent')}>{r.urgent} {r.urgent === 1 ? 'urgente' : 'urgentes'}</button> : null,
                      r.stale ? <button key="s" className="wf-link" onClick={() => abrir(r, 'stale')}>{r.stale} sin actividad</button> : null,
                    ].filter(Boolean)
                    return (
                      <tr key={r.id} className={r.id === 'none' ? 'wt-none' : ''}>
                        <td>
                          <div className="wt-who">
                            <i>{r.id === 'none' ? '?' : iniciales(r.name)}</i>
                            <div>
                              <b>{r.id === 'none' ? 'Sin responsable' : r.name}</b>
                              <small>{avisos.length ? avisos.flatMap((x, i) => (i ? [' · ', x] : [x])) : <span className="dev-mute">{r.open ? 'Al día' : 'Sin garantías abiertas'}</span>}</small>
                            </div>
                          </div>
                        </td>
                        <td className="c">{n(r, 'open', 'open', 'main')}</td>
                        <td className="c">{n(r, 'unreviewed', 'unreviewed')}</td>
                        <td className="c">{n(r, 'pte_cliente', 'pte_cliente')}</td>
                        <td className="c">{n(r, 'pte_zkong', 'pte_zkong')}</td>
                        <td className="c">
                          {n(r, 'alerts', r.critical ? 'critical' : 'alerts', r.critical ? 'crit' : 'warn')}
                          {r.critical ? <small className="wt-sub crit">{r.critical === r.alerts ? 'todas críticas' : `${r.critical} ${r.critical === 1 ? 'crítica' : 'críticas'}`}</small> : null}
                        </td>
                        <td className="c per">{r.closed ? <b className="wt-num">{num(r.closed)}</b> : <span className="wt-0">0</span>}</td>
                        <td className="c per">{r.avg_days == null ? <span className="wt-0">—</span> : <span className="wt-num">{dec(r.avg_days)} días</span>}</td>
                        <td className="c per">{r.reviewed ? <span className="wt-num">{num(r.reviewed)}</span> : <span className="wt-0">0</span>}</td>
                        <td className="wt-ver">{r.open ? <button className="wf-link" onClick={() => abrir(r, 'open')}>Ver sus garantías <Icon.arrowRight /></button> : null}</td>
                      </tr>
                    )
                  })}
                </tbody>
                {todas.length > 1 && !busca ? (
                  <tfoot>
                    <tr>
                      <td>Total del equipo</td>
                      {['open', 'unreviewed', 'pte_cliente', 'pte_zkong', 'alerts'].map((k) => <td key={k} className="c">{num(suma(k))}</td>)}
                      <td className="c per">{num(cerradas)}</td><td className="c per">{mediaCierre == null ? '—' : `${dec(mediaCierre)} días`}</td><td className="c per">{num(suma('reviewed'))}</td>
                      <td />
                    </tr>
                  </tfoot>
                ) : null}
              </table>
              {!filas.length ? <p className="dev-mute" style={{ padding: 14 }}>{todas.length ? `Nadie coincide con «${q}».` : 'No hay garantías abiertas ni actividad en el periodo.'}</p> : null}
            </div>
          </div>
        )}
        <p className="dev-mute wt-help">Pulsa cualquier número para ver esas garantías en la lista (desde allí se reasignan). Pasa el ratón por el título de una columna para ver qué cuenta. «Sin actividad»: más de {d?.stale_days ?? '—'} días sin ningún movimiento.</p>
      </div></div>
    </>
  )
}

/* --- Informes --- */
const GRUPOS = [['status', 'Estado'], ['urgency', 'Urgencia'], ['category', 'Categoría'], ['responsible', 'Responsable'], ['client', 'Cliente'], ['month', 'Mes de recepción'], ['close_reason', 'Motivo de cierre']]
const RANGOS = [
  ['mes', 'Este mes', () => { const n = new Date(); return [iso(new Date(n.getFullYear(), n.getMonth(), 1)), hoy()] }],
  ['prev', 'Mes anterior', () => { const n = new Date(); return [iso(new Date(n.getFullYear(), n.getMonth() - 1, 1)), iso(new Date(n.getFullYear(), n.getMonth(), 0))] }],
  ['3m', 'Últimos 3 meses', () => { const n = new Date(); return [iso(new Date(n.getFullYear(), n.getMonth() - 2, 1)), hoy()] }],
  ['anio', `Año ${new Date().getFullYear()}`, () => [`${new Date().getFullYear()}-01-01`, hoy()]],
  ['todo', 'Todo', () => ['', '']],
]
// Colores de las soluciones (los mismos que en la revisión) y de las respuestas de ZKONG.
const RES_COLOR = { pendiente: '#94a3b8', correcta: '#16a34a', bateria: '#d97706', recuperada: '#2563eb', reparada: '#6366f1', no_reproduce: '#8b5cf6', defectuosa: '#dc2626', danada: '#ea580c', desechada: '#64748b' }
const ZK_COLOR = { reposicion: '#16a34a', abono: '#2563eb', rechazada: '#dc2626', sent: '#d97706', to_send: '#94a3b8' }
const fechaCorta = (s) => (s ? new Date(`${s}T00:00:00`).toLocaleDateString('es-ES', { day: 'numeric', month: 'short', year: 'numeric' }) : '')
const pctTxt = (n, t) => (t ? `${Math.round((n / t) * 100)} %` : '—')

// Tabla de reparto: una fila por valor, con unidades, % y barra.
function Reparto({ title, sub, filas, total, pie, empty }) {
  return (
    <section className="dev-card wi-rep">
      <header className="wi-rep-h"><div><h3>{title}</h3><p className="dev-mute">{sub}</p></div></header>
      {!filas.length ? <p className="dev-mute wi-vacio">{empty}</p> : (
        <table className="dev-table wi-rep-t">
          <thead><tr><th /><th className="r">Dispositivos</th><th className="r">%</th><th>Reparto</th></tr></thead>
          <tbody>
            {filas.map((f) => (
              <tr key={f.key} className={f.muted ? 'muted' : ''}>
                <td><span className="wt-rep-l"><i style={{ background: f.color }} />{f.label}</span></td>
                <td className="r wt-num"><b>{num(f.n)}</b></td>
                <td className="r wt-num dev-mute">{pctTxt(f.n, total)}</td>
                <td className="wi-barcell"><span className="wt-bar"><i style={{ width: `${total ? (f.n / total) * 100 : 0}%`, background: f.color }} /></span></td>
              </tr>
            ))}
          </tbody>
        </table>
      )}
      {pie ? <footer className="wi-rep-f">{pie}</footer> : null}
    </section>
  )
}

// Recibidas y cerradas por mes (12 meses). Pulsar un mes lo elige como periodo.
function Meses({ data, onMes, activo }) {
  const [hover, setHover] = useState(null)
  if (!data?.length) return null
  const max = Math.max(1, ...data.map((m) => Math.max(m.received, m.closed)))
  const paso = max <= 5 ? 1 : max <= 10 ? 2 : Math.ceil(max / 4 / 5) * 5
  const tope = Math.ceil(max / paso) * paso
  const W = 1200, H = 230, L = 34, B = 26, T = 14, ancho = (W - L) / data.length, bar = Math.min(22, ancho / 3.2)
  const y = (v) => T + (H - T - B) * (1 - v / tope)
  const ticks = []; for (let v = 0; v <= tope; v += paso) ticks.push(v)
  const totalRec = data.reduce((s, m) => s + m.received, 0)
  return (
    <section className="dev-card wi-meses">
      <header className="wi-rep-h">
        <div><h3>Garantías por mes</h3><p className="dev-mute">Últimos 12 meses · pulsa un mes para verlo en el informe</p></div>
        <div className="wi-leyenda"><span><i className="rec" />Recibidas</span><span><i className="cer" />Cerradas</span></div>
      </header>
      <div className="wi-chart">
        <svg viewBox={`0 0 ${W} ${H}`} role="img" aria-label={`Garantías recibidas y cerradas por mes: ${totalRec} recibidas en 12 meses`}>
          {ticks.map((v) => (
            <g key={v}>
              <line x1={L} x2={W} y1={y(v)} y2={y(v)} className="grid" />
              <text x={L - 8} y={y(v) + 4} textAnchor="end" className="ax">{v}</text>
            </g>
          ))}
          {data.map((m, i) => {
            const cx = L + ancho * i + ancho / 2
            const sel = activo === m.key
            return (
              <g key={m.key} className={`mes ${sel ? 'on' : ''} ${hover === i ? 'hov' : ''}`} onMouseEnter={() => setHover(i)} onMouseLeave={() => setHover(null)} onClick={() => onMes(m.key)}>
                <rect x={L + ancho * i} y={T} width={ancho} height={H - T - B} className="hit" />
                {m.received ? <path d={barra(cx - bar - 1, y(m.received), bar, y(0) - y(m.received))} className="rec" /> : null}
                {m.closed ? <path d={barra(cx + 1, y(m.closed), bar, y(0) - y(m.closed))} className="cer" /> : null}
                <text x={cx} y={H - 8} textAnchor="middle" className={`ax ${sel ? 'b' : ''}`}>{m.label}</text>
              </g>
            )
          })}
          <line x1={L} x2={W} y1={y(0)} y2={y(0)} className="base" />
        </svg>
        {hover != null ? (
          <div className="wi-tip" style={{ left: `${((L + ((W - L) / data.length) * (hover + 0.5)) / W) * 100}%` }}>
            <b>{data[hover].label}</b>
            <span><i className="rec" />{num(data[hover].received)} recibidas</span>
            <span><i className="cer" />{num(data[hover].closed)} cerradas</span>
          </div>
        ) : null}
      </div>
    </section>
  )
}
// Barra con las esquinas de arriba redondeadas (4px) y la base recta.
function barra(x, y, w, h) {
  const r = Math.min(4, w / 2, h)
  return `M${x},${y + h}V${y + r}Q${x},${y} ${x + r},${y}H${x + w - r}Q${x + w},${y} ${x + w},${y + r}V${y + h}Z`
}

export function WarrantyReports({ head = null }) {
  const [rango, setRango] = useState('anio')
  const [[desde, hasta], setFechas] = useState(RANGOS[3][2]())
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
  const elegirMes = (k) => {
    const [a, m] = k.split('-').map(Number)
    setRango(''); setFechas([iso(new Date(a, m - 1, 1)), iso(new Date(a, m, 0))])
  }
  const mesActivo = desde && hasta && desde.endsWith('-01') && desde.slice(0, 7) === hasta.slice(0, 7) ? desde.slice(0, 7) : null

  const t = d?.total || {}
  const max = Math.max(1, ...(d?.rows || []).map((r) => r.total))
  const nombreGrupo = GRUPOS.find(([k]) => k === grupo)?.[1]
  const periodo = desde || hasta ? `del ${fechaCorta(desde) || 'principio'} al ${fechaCorta(hasta) || 'hoy'}` : 'desde el principio'

  // Soluciones: lo revisado primero, «pendiente de revisar» al final y en gris.
  const etRes = Object.fromEntries(Object.entries(rv?.resolutions || {}).map(([k, v]) => [k, v.label]))
  const res = Object.entries(d?.resolutions || {}).map(([k, n]) => ({ key: k, n, label: etRes[k] || k, color: RES_COLOR[k] || '#94a3b8', muted: k === 'pendiente' }))
    .sort((a, b) => (a.muted - b.muted) || b.n - a.n)
  const totalDisp = res.reduce((s, f) => s + f.n, 0)
  const revisados = res.filter((f) => !f.muted).reduce((s, f) => s + f.n, 0)

  // ZKONG: lo respondido y, debajo, lo que sigue sin respuesta.
  const zr = d?.zkong_results || {}
  const zp = d?.zkong_pending || {}
  const zk = [
    ...Object.entries(rv?.zkong_results || { reposicion: 'Reposición', abono: 'Abono', rechazada: 'Rechazada' }).map(([k, l]) => ({ key: k, label: l, n: zr[k] || 0, color: ZK_COLOR[k] })),
    { key: 'sent', label: 'Enviados, sin respuesta aún', n: zp.sent || 0, color: ZK_COLOR.sent, muted: true },
    { key: 'to_send', label: 'Pendientes de enviar', n: zp.to_send || 0, color: ZK_COLOR.to_send, muted: true },
  ].filter((f) => f.n)
  const respondidas = (zr.reposicion || 0) + (zr.abono || 0) + (zr.rechazada || 0)
  const aceptadas = (zr.reposicion || 0) + (zr.abono || 0)
  const totalZk = zk.reduce((s, f) => s + f.n, 0)

  const dif = d?.previous == null ? null : (t.total || 0) - d.previous

  const exportar = () => {
    const esc = csvCell
    const cab = [nombreGrupo, 'Recibidas', 'Siguen abiertas', 'Cerradas', 'Días de media en cerrarlas', 'Dispositivos', 'Dispositivos revisados']
    const fila = (r, l) => [l, r.total, r.open, r.closed, r.avg_days == null ? '' : dec(r.avg_days), r.devices, r.reviewed]
    const lineas = [...d.rows.map((r) => fila(r, r.label)), fila(t, 'Total')]
    const blob = new Blob(['﻿' + [cab, ...lineas].map((l) => l.map(esc).join(';')).join('\r\n')], { type: 'text/csv;charset=utf-8' })
    const a = document.createElement('a')
    a.href = URL.createObjectURL(blob)
    a.download = `informe-garantias-${grupo}-${desde || 'inicio'}-${hasta || hoy()}.csv`
    a.click()
    setTimeout(() => URL.revokeObjectURL(a.href), 1000)
  }

  return (
    <>
      {head || <Cabecera icon={Icon.chart} title="Informes" sub={`Garantías recibidas ${periodo}`} />}
      <div className="page-scroll"><div className="page dev-page wr-page wi-page">
        <div className="wt-filtros">
          <div className="dc-seg">{RANGOS.map((r) => <button key={r[0]} className={rango === r[0] ? 'on' : ''} onClick={() => elegirRango(r)}>{r[1]}</button>)}</div>
          <DateRange from={desde} to={hasta} onChange={(a, b) => { setRango(''); setFechas([a, b]) }} />
          <span className="spacer" />
          <button className="dev-btn" disabled={!d?.rows?.length} onClick={exportar} title="Exportar el desglose a CSV"><Icon.download /> Exportar CSV</button>
        </div>
        {d?.error ? <div className="dev-error"><Icon.warn /> {d.error}</div> : null}

        <div className={`wi-kpis ${cargando ? 'busy' : ''}`}>
          <div className="wi-kpi">
            <div className="v">{num(t.total)}{dif != null ? <span className={`wi-dif ${dif > 0 ? 'up' : dif < 0 ? 'down' : ''}`} title={`${num(d.previous)} en el periodo anterior de la misma duración`}>{dif > 0 ? '+' : ''}{num(dif)} vs periodo anterior</span> : null}</div>
            <div className="k">Garantías recibidas</div>
            <div className="h">{num(t.devices)} dispositivos en total</div>
          </div>
          <div className="wi-kpi">
            <div className="v">{num(t.closed)}<span className="wi-de">de {num(t.total)}</span></div>
            <div className="k">Ya cerradas</div>
            <div className="h">{t.total ? `${pctTxt(t.closed, t.total)} · ${num(t.open)} siguen abiertas` : '—'}</div>
          </div>
          <div className="wi-kpi">
            <div className="v">{t.avg_days == null ? '—' : dec(t.avg_days)}{t.avg_days == null ? null : <span className="wi-de">días</span>}</div>
            <div className="k">Tiempo medio en cerrarlas</div>
            <div className="h">Desde que se recibe hasta que se cierra</div>
          </div>
          <div className="wi-kpi">
            <div className="v">{respondidas ? pctTxt(aceptadas, respondidas) : '—'}</div>
            <div className="k">Aceptadas por ZKONG</div>
            <div className="h">{respondidas ? `${num(aceptadas)} de ${num(respondidas)} respondidas (reposición o abono)` : 'ZKONG aún no ha respondido nada de este periodo'}</div>
          </div>
        </div>

        <div className="wi-dos">
          <Reparto title="Soluciones de los dispositivos" sub="Qué se hizo con cada dispositivo al revisarlo" filas={res} total={totalDisp}
            empty="Ningún dispositivo en las garantías del periodo."
            pie={totalDisp ? <><span>Revisados</span><b>{num(revisados)} de {num(totalDisp)} ({pctTxt(revisados, totalDisp)})</b></> : null} />
          <Reparto title="Respuestas de ZKONG" sub="Dispositivos enviados a ZKONG y qué ha contestado" filas={zk} total={totalZk}
            empty="Ningún dispositivo de este periodo se ha enviado a ZKONG."
            pie={totalZk ? <><span>Con respuesta</span><b>{num(respondidas)} de {num(totalZk)}</b></> : null} />
        </div>

        <Meses data={d?.monthly} onMes={elegirMes} activo={mesActivo} />

        <section className={`dev-card wi-desglose ${cargando ? 'busy' : ''}`}>
          {cargando && <div className="dev-progress" />}
          <header className="wi-rep-h">
            <div><h3>Desglose</h3><p className="dev-mute">Las garantías recibidas en el periodo, agrupadas</p></div>
            <label className="wi-agr">Agrupar por <Select sm value={grupo} onChange={setGrupo} options={GRUPOS.map(([value, label]) => ({ value, label }))} /></label>
          </header>
          <div className="dev-table-wrap keep wt-wrap">
            <table className="dev-table wt-table">
              <thead><tr>
                <th>{nombreGrupo}</th><th>Recibidas</th><th className="c">Siguen abiertas</th><th className="c">Cerradas</th>
                <th className="c" title="Días de media desde la recepción hasta el cierre">Días en cerrarlas</th><th className="c">Dispositivos</th><th className="c">Revisados</th>
              </tr></thead>
              <tbody>
                {(d?.rows || []).map((r) => (
                  <tr key={r.key || '—'}>
                    <td className="wr-ell"><b>{r.label}</b></td>
                    <td><span className="wt-tot" title={`${r.label}: ${num(r.total)}`}><span className="wt-bar"><i style={{ width: `${(r.total / max) * 100}%` }} /></span>{num(r.total)}</span></td>
                    <td className="c">{r.open ? num(r.open) : <span className="wt-0">0</span>}</td>
                    <td className="c">{r.closed ? num(r.closed) : <span className="wt-0">0</span>}</td>
                    <td className="c">{r.avg_days == null ? <span className="wt-0">—</span> : `${dec(r.avg_days)} días`}</td>
                    <td className="c">{num(r.devices)}</td>
                    <td className="c">{r.devices ? <>{num(r.reviewed)} <span className="dev-mute">({pctTxt(r.reviewed, r.devices)})</span></> : <span className="wt-0">—</span>}</td>
                  </tr>
                ))}
              </tbody>
              {d?.rows?.length > 1 && (
                <tfoot><tr>
                  <td>Total</td><td>{num(t.total)}</td><td className="c">{num(t.open)}</td><td className="c">{num(t.closed)}</td>
                  <td className="c">{t.avg_days == null ? '—' : `${dec(t.avg_days)} días`}</td><td className="c">{num(t.devices)}</td><td className="c">{num(t.reviewed)}</td>
                </tr></tfoot>
              )}
            </table>
            {d && !d.rows?.length && <div className="act-empty"><Icon.chart style={{ width: 30, height: 30, fill: 'var(--ink-3)' }} /><p>No hay garantías recibidas en este periodo.</p></div>}
          </div>
        </section>
      </div></div>
    </>
  )
}
