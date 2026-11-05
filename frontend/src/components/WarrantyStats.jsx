import { useState, useEffect, useMemo, useRef } from 'react'
import { api } from '../api.js'
import { csvCell } from '../csv.js'
import { Icon } from '../icons.jsx'
import Select from './Select.jsx'
import DateRange from './DateRange.jsx'
import { WarrantyReports } from './WarrantyTeam.jsx'

/* -------------------------------------------------------------------------
 * ESTADÍSTICAS de Gestión de garantías, en cinco pestañas:
 *   · Fallos de dispositivos: lo repuesto en los albaranes de los proyectos de garantía del
 *     portal, por modelo, año de venta (el lote aproximado), cliente y mes, frente a lo
 *     vendido en sus facturas.
 *   · Presupuestos y envíos: los documentos de los proyectos de garantía del portal.
 *   · Tiempo por fase: días de cada garantía en su estado, media por fase y atascadas.
 *   · Satisfacción: la encuesta de 1 a 5 del correo de resolución.
 *   · Expedientes: el informe de garantías de siempre.
 * Gráficos en SVG/HTML propios; el color dice la categoría y siempre va con su texto.
 * ---------------------------------------------------------------------- */

const num = (n) => (n == null ? '—' : Number(n).toLocaleString('es-ES', { maximumFractionDigits: 1, useGrouping: 'always' }))
const eur = (n) => (n == null ? '—' : Number(n).toLocaleString('es-ES', { style: 'currency', currency: 'EUR', maximumFractionDigits: 0, useGrouping: 'always' }))
const pct = (r, dec = 0) => (r == null ? '—' : `${(r * 100).toLocaleString('es-ES', { maximumFractionDigits: dec })} %`)
const iso = (d) => new Date(d.getTime() - d.getTimezoneOffset() * 60000).toISOString().slice(0, 10)
const hoy = () => iso(new Date())
const MESES = ['ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sep', 'oct', 'nov', 'dic']
const mesCorto = (m) => { const [a, n] = String(m).split('-'); return `${MESES[Number(n) - 1] || '?'} ${String(a).slice(2)}` }

const RANGOS = [
  ['todo', 'Todo', () => ['', '']],
  ['12m', '12 meses', () => { const n = new Date(); return [iso(new Date(n.getFullYear() - 1, n.getMonth(), n.getDate() + 1)), hoy()] }],
  ['anio', `Año ${new Date().getFullYear()}`, () => [`${new Date().getFullYear()}-01-01`, hoy()]],
  ['prev', `Año ${new Date().getFullYear() - 1}`, () => [`${new Date().getFullYear() - 1}-01-01`, `${new Date().getFullYear() - 1}-12-31`]],
]

// Tipos de presupuesto del portal.
const TIPO = {
  garantia:  ['En garantía', '#2563eb'],
  pilas:     ['Pilas', '#d97706'],
  cliente:   ['Fuera de garantía', '#8b5cf6'],
  mano_obra: ['Mano de obra', '#0891b2'],
  otro:      ['Otro', '#94a3b8'],
}

/* ---------- Tooltip: uno para toda la página, sigue al ratón ---------- */
function useTip() {
  const [tip, setTip] = useState(null)
  const bind = (contenido) => ({
    onMouseMove: (e) => setTip({ x: e.clientX, y: e.clientY, c: contenido }),
    onMouseLeave: () => setTip(null),
  })
  const nodo = tip ? (
    <div className="st-tip" style={{ left: Math.min(tip.x + 14, window.innerWidth - 270), top: tip.y + 16 }}>{tip.c}</div>
  ) : null
  return [bind, nodo]
}

/* ---------- Piezas ---------- */
function Tarjeta({ title, sub, right, children, className = '' }) {
  return (
    <section className={`st-card ${className}`}>
      {title ? <header><div><h3>{title}</h3>{sub ? <p>{sub}</p> : null}</div>{right}</header> : null}
      {children}
    </section>
  )
}

function Kpi({ k, v, de, h, tone, spark }) {
  return (
    <div className={`st-kpi${tone ? ` t-${tone}` : ''}`}>
      <span className="k">{k}</span>
      <b className="v">{v}{de ? <small>{de}</small> : null}</b>
      {h ? <span className="h">{h}</span> : null}
      {spark?.length > 1 ? <Spark data={spark} /> : null}
    </div>
  )
}

// Línea mínima (sin ejes) con área suave.
function Spark({ data }) {
  const W = 120, H = 30
  const max = Math.max(1, ...data)
  const pts = data.map((v, i) => [(i / (data.length - 1)) * W, H - 2 - (v / max) * (H - 6)])
  const d = pts.map((p, i) => `${i ? 'L' : 'M'}${p[0].toFixed(1)},${p[1].toFixed(1)}`).join('')
  return (
    <svg className="st-spark" viewBox={`0 0 ${W} ${H}`} preserveAspectRatio="none" aria-hidden="true">
      <path d={`${d}L${W},${H}L0,${H}Z`} className="a" />
      <path d={d} className="l" />
    </svg>
  )
}

function Leyenda({ items }) {
  return <div className="st-leg">{items.map(([l, c]) => <span key={l}><i style={{ background: c }} />{l}</span>)}</div>
}

// Columnas apiladas por mes. series: [[clave, etiqueta, color]].
function Columnas({ data, series, tip, extra }) {
  const max = Math.max(1, ...data.map((d) => d.n))
  if (!data.length) return <Vacio />
  return (
    <div className="st-cols" style={{ '--n': data.length }}>
      <div className="st-cols-y"><span>{num(max)}</span><span>{num(Math.round(max / 2))}</span><span>0</span></div>
      <div className="st-cols-p">
        {data.map((d) => (
          <div key={d.month} className="st-col" {...tip(
            <><b>{mesCorto(d.month)} · {num(d.n)}</b>
              {series.filter(([k]) => d.by?.[k]).map(([k, l, c]) => <span key={k}><i style={{ background: c }} />{l}<em>{num(d.by[k])}</em></span>)}
              {extra ? extra(d) : null}</>
          )}>
            <div className="st-col-s" style={{ height: `${(d.n / max) * 100}%` }}>
              {series.filter(([k]) => d.by?.[k]).map(([k, , c]) => <i key={k} style={{ flexGrow: d.by[k], background: c }} />)}
            </div>
            <span className="st-col-x">{mesCorto(d.month)}</span>
          </div>
        ))}
      </div>
    </div>
  )
}

// Barras horizontales: [{label, n, sub?}].
function Barras({ data, color = 'var(--primary)', fmt = num, tip, unidad = '' }) {
  const max = Math.max(1, ...data.map((d) => d.n))
  if (!data.length || !data.some((d) => d.n)) return <Vacio />
  return (
    <ul className="st-bars">
      {data.map((d) => (
        <li key={d.label} {...(tip ? tip(<><b>{d.label}</b><span>{fmt(d.n)}{unidad}</span>{d.sub ? <span>{d.sub}</span> : null}</>) : {})}>
          <span className="l" title={d.label}>{d.label}</span>
          <span className="b"><i style={{ width: `${(d.n / max) * 100}%`, background: d.color || color }} /></span>
          <span className="n">{fmt(d.n)}</span>
        </li>
      ))}
    </ul>
  )
}

// Histograma vertical con resaltado opcional (p. ej., cuántas de cada tramo son defectuosas).
function Histo({ data, tip, sub }) {
  const max = Math.max(1, ...data.map((d) => d.n))
  if (!data.some((d) => d.n)) return <Vacio />
  return (
    <div className="st-histo">
      {data.map((d) => (
        <div key={d.label} className="st-h" {...tip(<><b>{d.label}</b><span>{num(d.n)} en total</span>{d.hi != null ? <span>{num(d.hi)} {sub}</span> : null}</>)}>
          <span className="v">{d.n ? num(d.n) : ''}</span>
          <div className="t"><div className="f" style={{ height: `${(d.n / max) * 100}%` }}>{d.hi ? <i style={{ height: `${(d.hi / Math.max(1, d.n)) * 100}%` }} /> : null}</div></div>
          <span className="x">{d.label}</span>
        </div>
      ))}
    </div>
  )
}

// Donut con el total en el centro. data: [{key, label, n, color, sub}].
function Donut({ data, centro, sub, tip }) {
  const total = data.reduce((a, d) => a + d.n, 0)
  const R = 54, C = 2 * Math.PI * R
  let acc = 0
  return (
    <div className="st-donut">
      <svg viewBox="0 0 140 140" role="img" aria-label={`${centro} ${sub}`}>
        <circle cx="70" cy="70" r={R} className="bg" />
        {total ? data.map((d) => {
          const len = (d.n / total) * C
          const el = <circle key={d.key} cx="70" cy="70" r={R} stroke={d.color} strokeDasharray={`${Math.max(0, len - 2)} ${C}`} strokeDashoffset={-acc} {...tip(<><b>{d.label}</b><span>{num(d.n)} · {pct(d.n / total)}</span>{d.sub ? <span>{d.sub}</span> : null}</>)} />
          acc += len
          return el
        }) : null}
      </svg>
      <div className="st-donut-c"><b>{centro}</b><span>{sub}</span></div>
      <ul className="st-donut-l">
        {data.map((d) => <li key={d.key}><i style={{ background: d.color }} /><span>{d.label}</span><b>{num(d.n)}</b>{d.sub ? <small>{d.sub}</small> : null}</li>)}
      </ul>
    </div>
  )
}

function Vacio({ text = 'Sin datos en este periodo.' }) {
  return <p className="st-empty">{text}</p>
}

function descargar(nombre, filas) {
  const blob = new Blob(['﻿' + filas.map((l) => l.map(csvCell).join(';')).join('\r\n')], { type: 'text/csv;charset=utf-8' })
  const a = document.createElement('a')
  a.href = URL.createObjectURL(blob); a.download = nombre; a.click()
  setTimeout(() => URL.revokeObjectURL(a.href), 1000)
}

/* ---------- Mapa de calor: modelo × año de venta ---------- */
const METRICAS = [
  ['rate', '% de vuelta', (c) => c.rate, (v) => pct(v)],
  ['units', 'Repuestas', (c) => c.units, num],
  ['sold', 'Vendidas', (c) => c.sold, num],
]
const QUIEN = [['ours', 'A nuestro cargo (0 €)', '#2563eb'], ['paid', 'Pagadas por el cliente', '#16a34a']]

function Calor({ hm, conTasa, tip }) {
  const [m, setM] = useState(conTasa ? 'rate' : 'units')
  const metrica = METRICAS.find((x) => x[0] === m) || METRICAS[1]
  const [, label, get, fmt] = metrica
  const celdas = useMemo(() => new Map(hm.cells.map((c) => [`${c.ref}|${c.year ?? ''}`, c])), [hm])
  const cols = [...hm.years, ...(hm.cells.some((c) => c.year == null) ? [null] : [])]
  const vals = hm.cells.map(get).filter((v) => v != null)
  const max = Math.max(...vals, m === 'rate' ? 0.3 : 1)
  if (!hm.models.length) return <Vacio />
  return (
    <>
      <div className="st-seg sm">{METRICAS.filter(([k]) => k !== 'rate' || conTasa).map(([k, l]) => <button key={k} className={m === k ? 'on' : ''} onClick={() => setM(k)}>{l}</button>)}</div>
      <div className="st-heat-w">
        <table className="st-heat">
          <thead><tr><th>Modelo</th>{cols.map((y) => <th key={y ?? 'x'}>{y ?? 'Sin venta'}</th>)}</tr></thead>
          <tbody>
            {hm.models.map((ref) => (
              <tr key={ref}>
                <th>{ref}</th>
                {cols.map((y) => {
                  const c = celdas.get(`${ref}|${y ?? ''}`)
                  const v = c ? get(c) : null
                  const t = v == null ? 0 : Math.min(1, v / max)
                  return (
                    <td key={y ?? 'x'} className={v == null ? 'nil' : t > 0.55 ? 'hot' : ''} style={v == null ? null : { '--t': `${Math.round(8 + t * 82)}%` }}
                      {...(c ? tip(<><b>{ref} · {y ? `vendidas en ${y}` : 'sin venta en el portal'}</b><span>{num(c.units)} repuestas en garantía</span>
                        {c.sold != null ? <span>{num(c.sold)} vendidas ese año{c.rate != null ? ` · vuelve el ${pct(c.rate, 1)}` : ''}</span> : null}</>) : {})}>
                      {v == null ? '' : fmt(v)}
                    </td>
                  )
                })}
              </tr>
            ))}
          </tbody>
        </table>
      </div>
      <div className="st-heat-k"><span>Menos</span><i /><span>Más {label.toLowerCase()}</span>{hm.no_year ? <em>{num(hm.no_year)} uds sin una venta previa en el portal</em> : null}</div>
    </>
  )
}

/* ---------- Pestaña: fallos de dispositivos (albaranes de garantía del portal) ---------- */
function Fallos({ desde, hasta }) {
  const [d, setD] = useState(null)
  const [kind, setKind] = useState('')
  const [cliente, setCliente] = useState('')
  const [clientes, setClientes] = useState([])
  const [tip, nodoTip] = useTip()

  useEffect(() => {
    let vivo = true
    setD((x) => (x && !x.error ? { ...x, busy: true } : null))
    api.warrantyFailures({ from: desde, to: hasta, kind, client: cliente }).then((r) => {
      if (!vivo) return
      setD(r.ok ? r : { error: r.error || 'No se pudo cargar' })
      if (r.ok && !clientes.length) setClientes(r.client_list || [])
    })
    return () => { vivo = false }
  }, [desde, hasta, kind, cliente])

  if (!d) return <div className="center-load"><div className="spinner" /></div>
  if (d.error) return <div className="dev-error"><Icon.warn /> {d.error}</div>
  const k = d.kpis
  const unCliente = !!cliente
  const quien = QUIEN.filter(([q]) => k[q])
  const porTasa = d.models.filter((m) => m.rate != null && m.sold >= 100).sort((a, b) => b.rate - a.rate).slice(0, 8)

  const csvModelos = () => descargar('repuestas-por-modelo.csv', [['Modelo', 'Producto', 'Repuestas', 'A nuestro cargo', 'Pagadas por el cliente', unCliente ? 'Compradas por el cliente' : 'Vendidas', '% de vuelta', 'Albaranes', 'Clientes'],
    ...d.models.map((m) => [m.ref, m.name || '', m.units, m.ours, m.paid, m.sold ?? '', m.rate == null ? '' : Math.round(m.rate * 1000) / 10, m.shipments, m.clients])])

  return (
    <div className={`st-body${d.busy ? ' busy' : ''}`}>
      <div className="st-filters">
        <div className="st-seg">{[['', 'Todo'], ['esl', 'Etiquetas'], ['ap', 'Antenas']].map(([v, l]) => <button key={v} className={kind === v ? 'on' : ''} onClick={() => setKind(v)}>{l}</button>)}</div>
        <div className="st-cli"><Select sm searchable value={cliente} placeholder="Todos los clientes" onChange={setCliente}
          options={[{ value: '', label: 'Todos los clientes' }, ...clientes.map((c) => ({ value: String(c.id), label: c.name }))]} /></div>
        <span className="st-src"><Icon.globe /> Albaranes de los proyectos de garantía del portal AEME, frente a sus facturas · solo lectura</span>
      </div>

      <div className="st-hero">
        <div className="st-hero-main">
          <span className="k">Vuelven en garantía</span>
          <b className="v">{k.per_1000 != null ? num(k.per_1000) : num(k.units)}</b>
          <span className="h">{k.per_1000 != null
            ? <>de cada <b>1.000</b> {unCliente ? 'que compró' : 'vendidas'} · {num(k.units)} repuestas de {num(k.sold)} {unCliente ? 'compradas' : 'facturadas'} de esos modelos</>
            : 'unidades repuestas en el periodo'}</span>
        </div>
        <div className="st-kpis">
          <Kpi k="Unidades repuestas" v={num(k.units)} h={`${num(k.shipments)} albaranes · ${num(k.projects)} garantías · ${num(k.clients)} clientes`} spark={d.months.map((m) => m.n)} />
          <Kpi k="A nuestro cargo" v={k.units ? pct(k.ours / k.units) : '—'} h={`${num(k.ours)} uds a 0 € · ${num(k.paid)} pagadas por el cliente`} />
          <Kpi k="Edad al volver" v={k.median_age == null ? '—' : num(k.median_age)} de={k.median_age == null ? null : 'meses'} h="Mediana desde la factura de venta" />
          <Kpi k="Etiquetas · antenas" v={`${num(k.esl)} · ${num(k.ap)}`} h={`${num(k.models)} modelos distintos`} />
        </div>
      </div>

      <div className="st-grid g-21">
        <Tarjeta title="Mapa de calor: modelo × año de venta" sub="Cada unidad repuesta se reparte entre las facturas de ese modelo a ese cliente anteriores al envío: el año de venta aproxima el lote.">
          <Calor hm={d.heatmap} conTasa={!unCliente} tip={tip} />
        </Tarjeta>
        <Tarjeta title="Quién paga lo repuesto" sub="Por el presupuesto del albarán: a 0 € es a nuestro cargo">
          <Donut tip={tip} centro={num(k.units)} sub="unidades" data={quien.map(([key, label, color]) => ({ key, label, color, n: k[key] }))} />
          {porTasa.length ? (
            <div className="st-cob">
              <span>Los que más vuelven sobre lo vendido</span>
              <Barras data={porTasa.map((m) => ({ label: m.ref, n: m.rate, sub: `${num(m.units)} de ${num(m.sold)}`, color: m.rate >= d.threshold ? '#e11d48' : '#2563eb' }))} fmt={(v) => pct(v, 1)} tip={tip} />
            </div>
          ) : null}
        </Tarjeta>
      </div>

      <Tarjeta title="Modelos que más vuelven" sub={unCliente ? 'Frente a lo que compró este cliente' : 'Frente a lo vendido según las facturas del portal'}
        right={<button className="dev-btn sm" onClick={csvModelos} disabled={!d.models.length}><Icon.download /> CSV</button>}>
        {d.models.length ? (
          <div className="st-models">
            <div className="st-models-h"><span>Modelo</span><span>Quién paga</span><span>Repuestas</span><span>{unCliente ? 'Compradas' : 'Vendidas'}</span><span>% de vuelta</span><span>Clientes</span></div>
            {d.models.map((m) => (
              <div key={m.ref} className="st-models-r">
                <span className="m"><b>{m.ref}</b><small title={m.name || ''}>{m.kind === 'ap' ? 'Antena · ' : ''}{m.name || ''}</small></span>
                <span className="st-stack">{QUIEN.filter(([q]) => m[q]).map(([q, l, c]) => <i key={q} style={{ flexGrow: m[q], background: c }} {...tip(<><b>{m.ref} · {l}</b><span>{num(m[q])} de {num(m.units)}</span></>)} />)}</span>
                <span className="n">{num(m.units)}</span>
                <span className="n dim">{m.sold ? num(m.sold) : '—'}</span>
                <span className={`n${m.rate >= d.threshold ? ' bad' : ''}`}><b>{m.rate == null ? '—' : m.rate > 1 ? '>100 %' : pct(m.rate, 1)}</b></span>
                <span className="n dim">{num(m.clients)}</span>
              </div>
            ))}
            <Leyenda items={quien.map(([, l, c]) => [l, c])} />
          </div>
        ) : <Vacio />}
      </Tarjeta>

      <div className="st-grid g-21">
        <Tarjeta title="Unidades repuestas por mes" sub="Por la fecha de envío del albarán">
          <Columnas data={d.months} series={QUIEN} tip={tip} extra={(m) => <span className="st-tip-tot">Albaranes <em>{num(m.shipments)}</em></span>} />
          <Leyenda items={quien.map(([, l, c]) => [l, c])} />
        </Tarjeta>
        <Tarjeta title="Edad al volver" sub="Meses desde la factura de venta hasta el envío">
          <Histo data={d.ages} tip={tip} />
        </Tarjeta>
      </div>

      <Tarjeta title="Clientes que más reciben en garantía" sub="Con lo que compraron de esos mismos modelos">
        {d.clients.length ? (
          <div className="dev-table-wrap keep"><table className="dev-table st-table">
            <thead><tr><th>Cliente</th><th className="c">Garantías</th><th className="c">Albaranes</th><th>Repuestas</th><th className="c">Compradas</th><th className="c">% de vuelta</th><th>Modelos</th></tr></thead>
            <tbody>{d.clients.map((c) => {
              const max = d.clients[0].units || 1
              return (
                <tr key={c.id}>
                  <td className="wr-ell"><b>{c.client}</b></td>
                  <td className="c">{num(c.projects)}</td>
                  <td className="c">{num(c.shipments)}</td>
                  <td><span className="st-inl"><i style={{ width: `${(c.units / max) * 100}%` }} /></span>{num(c.units)}</td>
                  <td className="c">{c.sold ? num(c.sold) : '—'}</td>
                  <td className="c">{c.rate != null ? <b>{pct(c.rate, 1)}</b> : <span className="dev-mute" title="Recibe más de lo que compró en el portal: compró con otra razón social o antes de 2023">Compró fuera</span>}</td>
                  <td className="dev-mute">{c.models.join(', ')}</td>
                </tr>
              )
            })}</tbody>
          </table></div>
        ) : <Vacio />}
      </Tarjeta>
      {nodoTip}
    </div>
  )
}

/* ---------- Pestaña: tiempo en cada fase ---------- */
const dias = (n) => (n == null ? '—' : `${num(n)} ${Number(n) === 1 ? 'día' : 'días'}`)
const NIVEL = { crit: ['Crítica', 'crit'], warn: ['Aviso', 'warn'] }

function Fases({ desde, hasta, onOpenWarranty }) {
  const [d, setD] = useState(null)
  const [tip, nodoTip] = useTip()

  useEffect(() => {
    let vivo = true
    setD((x) => (x && !x.error ? { ...x, busy: true } : null))
    api.warrantyPhases({ from: desde, to: hasta }).then((r) => { if (vivo) setD(r.ok ? r : { error: r.error || 'No se pudo cargar' }) })
    return () => { vivo = false }
  }, [desde, hasta])

  if (!d) return <div className="center-load"><div className="spinner" /></div>
  if (d.error) return <div className="dev-error"><Icon.warn /> {d.error}</div>
  const k = d.kpis
  const atascadas = k.warn + k.crit
  // Escala común: lo más largo entre medias, lo que llevan ahora y los límites.
  const tope = Math.max(5, ...d.phases.flatMap((f) => [f.avg, f.now_max, f.limit.crit].filter((v) => v != null)))
  const x = (v) => `${Math.min(100, (v / tope) * 100)}%`

  return (
    <div className={`st-body${d.busy ? ' busy' : ''}`}>
      <div className="st-hero">
        <div className={`st-hero-main${k.crit ? ' bad' : ''}`}>
          <span className="k">Garantías atascadas</span>
          <b className="v">{num(atascadas)}</b>
          <span className="h">{atascadas ? <><b>{num(k.crit)}</b> críticas · {num(k.warn)} en aviso · de {num(k.open)} abiertas</> : (k.open === 0 ? 'No hay garantías abiertas' : k.open === 1 ? 'La única abierta va dentro de sus días' : `Ninguna de las ${num(k.open)} abiertas pasa de sus días`)}</span>
        </div>
        <div className="st-kpis">
          <Kpi k="Abiertas" v={num(k.open)} h={`Llevan ${dias(k.median_now)} en su estado (mediana)`} />
          <Kpi k="Del alta al cierre" v={k.cycle_avg == null ? '—' : num(k.cycle_avg)} de={k.cycle_avg == null ? null : 'días'} h={k.closed ? `Media de ${num(k.closed)} cerradas · mediana ${dias(k.cycle_median)}` : 'Ninguna cerrada en el periodo'} />
          <Kpi k="Críticas" v={num(k.crit)} tone={k.crit ? 'bad' : 'ok'} h="Pasan de los días de crítico" />
          <Kpi k="En aviso" v={num(k.warn)} tone={k.warn ? 'warn' : 'ok'} h="Pasan de los días de aviso" />
        </div>
      </div>

      <Tarjeta title="Días en cada fase" sub="La barra es la media de lo que duró cada estado; el punto, lo que llevan de media las que están ahí ahora. Las marcas son los días de aviso y de crítico (se cambian en Configuración → Alertas).">
        <div className="st-fases">
          <div className="st-fase h"><span>Fase</span><span /><span className="n">Media</span><span className="n">Ahora</span><span>Atascadas</span></div>
          {d.phases.map((f) => (
            <div key={f.key} className="st-fase">
              <span className="l"><i style={{ background: f.color || '#94a3b8' }} />{f.label}</span>
              <span className="t" {...tip(<><b>{f.label}</b>
                <span>Media {dias(f.avg)} · mediana {dias(f.median)} ({num(f.stays)} {f.stays === 1 ? 'vez' : 'veces'})</span>
                <span>Ahora {num(f.now)} {f.now === 1 ? 'garantía' : 'garantías'}{f.now ? `: media ${dias(f.now_avg)}, la que más ${dias(f.now_max)}` : ''}</span>
                <span>{f.limit.warn != null ? `Aviso a los ${f.limit.warn} días · crítico a los ${f.limit.crit}` : 'Sin alerta para este estado'}</span></>)}>
                {f.avg != null ? <i className="b" style={{ width: x(f.avg), background: f.color || '#94a3b8' }} /> : null}
                {f.limit.warn != null ? <i className="m warn" style={{ left: x(f.limit.warn) }} /> : null}
                {f.limit.crit != null ? <i className="m crit" style={{ left: x(f.limit.crit) }} /> : null}
                {f.now ? <i className={`p${f.crit ? ' crit' : f.warn ? ' warn' : ''}`} style={{ left: x(f.now_avg) }} /> : null}
              </span>
              <span className="n">{dias(f.avg)}</span>
              <span className="n">{f.now ? <><b>{num(f.now)}</b> · {dias(f.now_avg)}</> : <span className="dev-mute">—</span>}</span>
              <span className="c">
                {f.crit ? <em className="st-lvl crit">{num(f.crit)} {f.crit === 1 ? 'crítica' : 'críticas'}</em> : null}
                {f.warn ? <em className="st-lvl warn">{num(f.warn)} aviso</em> : null}
                {!f.crit && !f.warn && f.now ? <span className="dev-mute">Al día</span> : null}
              </span>
            </div>
          ))}
          <div className="st-fase-k"><span><i className="m warn" /> Días de aviso</span><span><i className="m crit" /> Días de crítico</span><span><i className="p" /> Media de las que están ahora</span></div>
        </div>
      </Tarjeta>

      <Tarjeta title="Las que más llevan en su estado" sub="Garantías abiertas, de la que más días lleva a la que menos">
        {d.oldest.length ? (
          <div className="dev-table-wrap keep"><table className="dev-table st-table">
            <thead><tr><th>Garantía</th><th>Cliente</th><th>Estado</th><th>Responsable</th><th className="c">En el estado</th></tr></thead>
            <tbody>{d.oldest.map((w) => (
              <tr key={w.id}>
                <td>{onOpenWarranty ? <button className="st-link" onClick={() => onOpenWarranty(w.id)}>{w.code}</button> : <b>{w.code}</b>}</td>
                <td className="wr-ell">{w.client || '—'}</td>
                <td><span className="st-dot"><i style={{ background: w.color || '#94a3b8' }} />{w.status_label}</span></td>
                <td className={w.responsible ? '' : 'dev-mute'}>{w.responsible || 'Sin responsable'}</td>
                <td className="c"><b>{dias(w.days)}</b>{w.level ? <em className={`st-lvl ${NIVEL[w.level][1]}`}>{NIVEL[w.level][0]}</em> : null}</td>
              </tr>
            ))}</tbody>
          </table></div>
        ) : <Vacio text="No hay garantías abiertas." />}
      </Tarjeta>
      {nodoTip}
    </div>
  )
}

/* ---------- Pestaña: satisfacción (la encuesta del correo de resolución) ---------- */
function Satisfaccion({ desde, hasta, onOpenWarranty }) {
  const [d, setD] = useState(null)
  const [tip, nodoTip] = useTip()

  useEffect(() => {
    let vivo = true
    setD((x) => (x && !x.error ? { ...x, busy: true } : null))
    api.warrantySurveyStats({ from: desde, to: hasta }).then((r) => { if (vivo) setD(r.ok ? r : { error: r.error || 'No se pudo cargar' }) })
    return () => { vivo = false }
  }, [desde, hasta])

  if (!d) return <div className="center-load"><div className="spinner" /></div>
  if (d.error) return <div className="dev-error"><Icon.warn /> {d.error}</div>
  const k = d.kpis
  const series = d.scores.map((s) => [`s${s.score}`, `${s.score} · ${s.label}`, s.color]).reverse()

  return (
    <div className={`st-body${d.busy ? ' busy' : ''}`}>
      <div className="st-hero">
        <div className="st-hero-main ok">
          <span className="k">Nota media</span>
          <b className="v">{k.avg == null ? '—' : k.avg.toLocaleString('es-ES', { minimumFractionDigits: 1, maximumFractionDigits: 1 })}<small> / 5</small></b>
          <span className="h">{k.answers ? <>de <b>{num(k.answers)}</b> {k.answers === 1 ? 'respuesta' : 'respuestas'} a la encuesta del correo de resolución</> : 'Aún no ha contestado nadie la encuesta'}</span>
        </div>
        <div className="st-kpis">
          <Kpi k="Contentos" v={pct(k.happy)} tone={k.happy >= 0.8 ? 'ok' : ''} h="Ponen un 4 o un 5" />
          <Kpi k="Respuestas" v={num(k.answers)} h={k.sent ? `De ${num(k.sent)} correos con la encuesta · ${pct(k.rate)} contesta` : 'Sin correos con la encuesta en el periodo'} spark={d.months.map((m) => m.n)} />
          <Kpi k="Descontentos" v={num(k.unhappy)} tone={k.unhappy ? 'bad' : 'ok'} h="Ponen un 1 o un 2: merece la pena llamarles" />
        </div>
      </div>

      <div className="st-grid g-21">
        <Tarjeta title="Respuestas por mes" sub="Cuántos contestan y qué nota ponen">
          <Columnas data={d.months} series={series} tip={tip} extra={(m) => <span className="st-tip-tot">Media <em>{num(m.avg)}</em></span>} />
          <Leyenda items={series.map(([, l, c]) => [l, c]).reverse()} />
        </Tarjeta>
        <Tarjeta title="Reparto de notas" sub="De 1 (nada contento) a 5 (encantado)">
          <Barras data={[...d.scores].reverse().map((s) => ({ label: `${s.score} · ${s.label}`, n: s.n, color: s.color, sub: k.answers ? pct(s.n / k.answers) : null }))} tip={tip} />
        </Tarjeta>
      </div>

      <Tarjeta title="Lo que nos dicen" sub="Los comentarios de la encuesta, del más reciente al más antiguo">
        {d.comments.length ? (
          <ul className="st-quotes">
            {d.comments.map((c) => (
              <li key={`${c.id}${c.at}`}>
                <i className={`st-q-n r${c.score}`}>{c.score}</i>
                <div>
                  <p>“{c.comment}”</p>
                  <small>{onOpenWarranty ? <button className="st-link" onClick={() => onOpenWarranty(c.id)}>{c.code}</button> : <b>{c.code}</b>}
                    {c.client ? ` · ${c.client}` : ''} · {c.at.split('-').reverse().join('/')}</small>
                </div>
              </li>
            ))}
          </ul>
        ) : <Vacio text="Todavía no hay comentarios." />}
      </Tarjeta>
      {nodoTip}
    </div>
  )
}

/* ---------- Pestaña: presupuestos y envíos del portal ---------- */
function Portal({ desde, hasta }) {
  const [d, setD] = useState(null)
  const [cliente, setCliente] = useState('')
  const [clientes, setClientes] = useState([])
  const [tip, nodoTip] = useTip()

  useEffect(() => {
    let vivo = true
    setD((x) => (x ? { ...x, busy: true } : null))
    api.warrantyPortalStats({ from: desde, to: hasta, client: cliente }).then((r) => {
      if (!vivo) return
      setD(r.ok ? r : { error: r.error || 'No se pudo cargar' })
      if (r.ok && !cliente) setClientes(r.client_list || [])
    })
    return () => { vivo = false }
  }, [desde, hasta, cliente])

  if (!d) return <div className="center-load"><div className="spinner" /></div>
  if (d.error) return <div className="dev-error"><Icon.warn /> {d.error}</div>
  const k = d.kpis
  const series = Object.entries(TIPO).map(([key, [l, c]]) => [key, l, c])
  const f0 = d.funnel[0]?.n || 1
  const dn = d.done

  return (
    <div className={`st-body${d.busy ? ' busy' : ''}`}>
      <div className="st-filters">
        <span className="st-src"><Icon.globe /> Proyectos de garantía del portal AEME · solo lectura</span>
        <div className="st-cli"><Select sm searchable value={cliente ? String(cliente) : ''} placeholder="Todos los clientes" onChange={(v) => setCliente(v)}
          options={[{ value: '', label: 'Todos los clientes' }, ...clientes.map((c) => ({ value: String(c.id), label: c.name }))]} /></div>
      </div>

      <div className="st-hero">
        <div className="st-hero-main">
          <span className="k">Garantías hechas</span>
          <b className="v">{num(dn.projects)}</b>
          <span className="h">proyectos de garantía con el pedido o el albarán ya enviado · {num(k.estimates)} presupuestos en {num(k.projects)} proyectos</span>
        </div>
        <div className="st-kpis">
          <Kpi k="A nuestro cargo" v={num(dn.ours)} de="pptos a 0 €" h={`${num(dn.ours_projects)} garantías · ${num(dn.ours_units)} etiquetas y antenas`} spark={dn.months.map((m) => m.by_type.ours || 0)} />
          <Kpi k="Pagadas por el cliente" v={eur(dn.paid_amount)} h={`${num(dn.paid)} presupuestos aceptados · ${num(dn.paid_units)} uds`} />
          <Kpi k="Presupuestado al cliente" v={eur(k.quoted)} h={`${eur(k.accepted_amount)} aceptado`} />
          <Kpi k="Aceptación" v={pct(k.accept_rate)} tone={k.accept_rate != null && k.accept_rate < 0.5 ? 'warn' : 'ok'} h={`de ${num(k.decided)} con respuesta (aceptados, rechazados o caducados)`} />
          <Kpi k="Facturado" v={eur(k.invoiced)} h={`${num(k.invoices)} facturas · ${eur(k.paid)} cobrado`} />
          <Kpi k="Hasta el albarán" v={k.median_days == null ? '—' : num(k.median_days)} de={k.median_days == null ? null : 'días'} h="Mediana desde el presupuesto" />
        </div>
      </div>

      <div className="st-grid g-21">
        <Tarjeta title="Del presupuesto a la garantía hecha" sub="Cuántos presupuestos llegan a cada paso. Con importe cuentan si están aceptados; los de 0 € (a nuestro cargo), con que el pedido o el albarán salga">
          <div className="st-funnel">
            {d.funnel.map((s, i) => (
              <div key={s.key} className="st-fun" {...tip(<><b>{s.label}</b><span>{num(s.n)} · {pct(s.n / f0)} de los presupuestos</span>
                {i ? <span>{pct(s.n / Math.max(1, d.funnel[i - 1].n))} del paso anterior</span> : null}</>)}>
                <span className="l">{s.label}</span>
                <span className="b"><i style={{ width: `${(s.n / f0) * 100}%`, opacity: 1 - i * 0.16 }} /></span>
                <span className="n"><b>{num(s.n)}</b><small>{pct(s.n / f0)}</small></span>
              </div>
            ))}
          </div>
          <h4 className="st-sub">Tiempo hasta que sale el albarán</h4>
          <Histo data={d.lead.map((l) => ({ label: l.label, n: l.n }))} tip={tip} />
        </Tarjeta>
        <Tarjeta title="Tipos de presupuesto" sub="Como los clasifica la app de garantías">
          <Donut tip={tip} centro={num(k.estimates)} sub="presupuestos"
            data={d.types.map((t) => ({ key: t.key, label: t.label, n: t.n, color: TIPO[t.key]?.[1] || '#94a3b8', sub: t.amount ? eur(t.amount) : '0 €' }))} />
          <h4 className="st-sub">Estados</h4>
          <Barras data={d.statuses.map((s) => ({ label: s.label, n: s.n, sub: eur(s.amount), color: s.key === 'accepted' ? '#16a34a' : s.key === 'declined' ? '#dc2626' : s.key === 'expired' ? '#94a3b8' : 'var(--primary)' }))} tip={tip} />
        </Tarjeta>
      </div>

      <Tarjeta title="Garantías hechas por mes" sub="Presupuestos con el pedido o el albarán enviado: a nuestro cargo (0 €) o pagados por el cliente"
        right={dn.ours + dn.paid ? (
          <div className="st-split" {...tip(<><b>Quién paga lo hecho</b><span>{num(dn.ours)} a nuestro cargo · {pct(dn.ours / (dn.ours + dn.paid))}</span><span>{num(dn.paid)} el cliente · {eur(dn.paid_amount)}</span></>)}>
            <span><b>{pct(dn.ours / (dn.ours + dn.paid))}</b> a nuestro cargo</span>
            <div><i style={{ flexGrow: dn.ours, background: '#2563eb' }} /><i style={{ flexGrow: dn.paid, background: '#16a34a' }} /></div>
          </div>
        ) : null}>
        <Columnas data={dn.months.map((m) => ({ ...m, by: m.by_type }))} series={[['ours', 'A nuestro cargo (0 €)', '#2563eb'], ['paid', 'Pagadas por el cliente', '#16a34a']]} tip={tip}
          extra={(m) => <span className="st-tip-tot">Cobrado <em>{eur(m.amount)}</em></span>} />
        <Leyenda items={[['A nuestro cargo (0 €)', '#2563eb'], ['Pagadas por el cliente', '#16a34a']]} />
      </Tarjeta>

      <Tarjeta title="Presupuestos por mes" sub="Todos, hechos o no, por tipo; en la ventanita, el importe del mes">
        <Columnas data={d.months.map((m) => ({ ...m, by: m.by_type }))} series={series} tip={tip}
          extra={(m) => <span className="st-tip-tot">Importe <em>{eur(m.amount)}</em></span>} />
        <Leyenda items={series.filter(([key]) => d.months.some((m) => m.by_type?.[key])).map(([, l, c]) => [l, c])} />
      </Tarjeta>

      <div className="st-grid g-12">
        <Tarjeta title="Modelos repuestos" sub="Unidades en los presupuestos: en garantía y pagadas por el cliente">
          {d.models.length ? (
            <ul className="st-rep">
              {d.models.map((m) => {
                const max = d.models[0].warranty + d.models[0].paid || 1
                return (
                  <li key={m.ref} {...tip(<><b>{m.ref}</b><span>{m.name}</span><span>{num(m.warranty)} en garantía · {num(m.paid)} pagadas</span></>)}>
                    <span className="l"><b>{m.ref}</b><small>{m.kind === 'ap' ? 'Antena' : m.name}</small></span>
                    <span className="b"><i className="w" style={{ width: `${(m.warranty / max) * 100}%` }} /><i className="p" style={{ width: `${(m.paid / max) * 100}%` }} /></span>
                    <span className="n">{num(m.warranty + m.paid)}</span>
                  </li>
                )
              })}
            </ul>
          ) : <Vacio />}
          <Leyenda items={[['En garantía', '#2563eb'], ['Pagadas por el cliente', '#8b5cf6']]} />
        </Tarjeta>
        <Tarjeta title="Clientes" sub="Los que más presupuestos de garantía tienen">
          {d.clients.length ? (
            <div className="dev-table-wrap keep"><table className="dev-table st-table">
              <thead><tr><th>Cliente</th><th className="c">Proyectos</th><th>Presupuestos</th><th className="c">Unidades</th><th className="c">Presupuestado</th><th className="c">Facturado</th></tr></thead>
              <tbody>{d.clients.map((c) => (
                <tr key={c.client}>
                  <td className="wr-ell"><b>{c.client}</b></td>
                  <td className="c">{num(c.projects)}</td>
                  <td><span className="st-inl"><i style={{ width: `${(c.estimates / (d.clients[0].estimates || 1)) * 100}%` }} /></span>{num(c.estimates)} <small className="dev-mute">({num(c.warranty)} en garantía)</small></td>
                  <td className="c">{num(c.units)}</td>
                  <td className="c">{eur(c.amount)}</td>
                  <td className="c">{eur(c.invoiced)}</td>
                </tr>
              ))}</tbody>
            </table></div>
          ) : <Vacio />}
        </Tarjeta>
      </div>
      {nodoTip}
    </div>
  )
}

const TABS = [
  ['fallos', 'Fallos de dispositivos', Icon.warn],
  ['portal', 'Presupuestos y envíos', Icon.truck],
  ['fases', 'Tiempo por fase', Icon.clock],
  ['encuesta', 'Satisfacción', Icon.star],
  ['expedientes', 'Expedientes', Icon.shield],
]
const TAB_KEY = 'st_tab'

export default function WarrantyStats({ onOpenWarranty }) {
  const [tab, setTabSt] = useState(() => { try { return localStorage.getItem(TAB_KEY) || 'fallos' } catch { return 'fallos' } })
  const setTab = (t) => { setTabSt(t); try { localStorage.setItem(TAB_KEY, t) } catch { /* sin almacenamiento */ } }
  const [rango, setRango] = useState('todo')
  const [[desde, hasta], setFechas] = useState(['', ''])
  const scroll = useRef(null)

  const pestañas = (
    <div className="st-tabs" role="tablist">
      {TABS.map(([k, l, Ic]) => <button key={k} role="tab" aria-selected={tab === k} className={tab === k ? 'on' : ''} onClick={() => setTab(k)}><Ic /> {l}</button>)}
    </div>
  )
  const cabecera = (
    <header className="page-head st-head">
      <span className="sc-ic"><Icon.chart style={{ width: 18, height: 18, fill: 'var(--primary)' }} /></span>
      <div><h1>Estadísticas</h1></div>
      <span className="sub">· Garantías, fallos y documentos</span>
      <span className="spacer" />
      {pestañas}
    </header>
  )

  if (tab === 'expedientes') return <WarrantyReports head={cabecera} />

  return (
    <>
      {cabecera}
      <div className="page-scroll" ref={scroll}><div className="page dev-page st-page">
        <div className="st-period">
          <div className="st-seg">{RANGOS.map((r) => <button key={r[0]} className={rango === r[0] ? 'on' : ''} onClick={() => { setRango(r[0]); setFechas(r[2]()) }}>{r[1]}</button>)}</div>
          <DateRange from={desde} to={hasta} onChange={(a, b) => { setRango(''); setFechas([a, b]) }} />
          <span className="st-period-t">{tab === 'fallos' ? 'Por la fecha de envío del albarán' : tab === 'fases' ? 'Medias por la fecha en que terminó cada estado; «ahora», siempre hoy' : tab === 'encuesta' ? 'Por la fecha de la respuesta' : 'Por la fecha del presupuesto'}</span>
        </div>
        {tab === 'fallos' ? <Fallos desde={desde} hasta={hasta} />
          : tab === 'fases' ? <Fases desde={desde} hasta={hasta} onOpenWarranty={onOpenWarranty} />
          : tab === 'encuesta' ? <Satisfaccion desde={desde} hasta={hasta} onOpenWarranty={onOpenWarranty} />
          : <Portal desde={desde} hasta={hasta} />}
      </div></div>
    </>
  )
}
