import { useState, useEffect } from 'react'
import { api } from '../api.js'
import { csvCell } from '../csv.js'
import { Icon } from '../icons.jsx'
import Select from './Select.jsx'
import { filaClic } from './a11y.js'
import Vacio from './Vacio.jsx'
import { SkTabla, SkStats } from './Esqueleto.jsx'

/* -------------------------------------------------------------------------
 * CLIENTES Y PROYECTOS de Gestión de garantías. Solo consulta.
 * Lista: cada cliente con garantías aquí o con proyectos de garantía en el portal AEME.
 * Ficha: sus hoteles o tiendas, sus garantías, las garantías y presupuestos del portal
 * (con pedido, expedición y albarán) y las remesas a ZKONG en las que han ido.
 * ---------------------------------------------------------------------- */

const num = (n) => Number(n || 0).toLocaleString('es-ES')
const fecha = (v) => { if (!v) return '—'; const d = new Date(String(v).replace(' ', 'T')); return isNaN(d) ? '—' : d.toLocaleDateString('es-ES', { day: '2-digit', month: '2-digit', year: 'numeric' }) }
const plural = (n, uno, varios) => `${num(n)} ${n === 1 ? uno : varios}`
const POR_PAGINA = 25

function Pill({ cls, children }) { return <span className={`wr-pill ${cls}`}><i />{children}</span> }

function Cabecera() {
  return (
    <header className="page-head">
      <span className="sc-ic"><Icon.building style={{ width: 18, height: 18, fill: 'var(--primary)' }} /></span>
      <div><h1>Clientes y proyectos</h1></div>
      <span className="sub">· Solo consulta · datos del portal AEME y de las garantías</span>
    </header>
  )
}

const descargarCsv = (nombre, cab, lineas) => {
  const esc = csvCell
  const blob = new Blob(['﻿' + [cab, ...lineas].map((l) => l.map(esc).join(';')).join('\r\n')], { type: 'text/csv;charset=utf-8' })
  const a = document.createElement('a')
  a.href = URL.createObjectURL(blob)
  a.download = nombre
  a.click()
  setTimeout(() => URL.revokeObjectURL(a.href), 1000)
}

export default function WarrantyClients({ onOpenWarranty }) {
  const [abierto, setAbierto] = useState(null)
  return (
    <>
      <Cabecera />
      <div className="page-scroll"><div className="page dev-page wr-page">
        {abierto
          ? <Ficha k={abierto} onBack={() => setAbierto(null)} onOpenWarranty={onOpenWarranty} />
          : <Lista onOpen={setAbierto} />}
      </div></div>
    </>
  )
}

/* --- Lista --- */
const ORDENES = [
  { value: 'reciente', label: 'Última garantía primero' },
  { value: 'abiertas', label: 'Más garantías abiertas' },
  { value: 'total', label: 'Más garantías en total' },
  { value: 'nombre', label: 'Nombre (A-Z)' },
]

function Lista({ onOpen }) {
  const [d, setD] = useState(null)
  const [q, setQ] = useState('')
  const [filtro, setFiltro] = useState('open')
  const [orden, setOrden] = useState('reciente')
  const [pag, setPag] = useState(0)

  useEffect(() => {
    api.warrantyClients().then((r) => setD(r.ok ? r : { error: r.error || 'No se pudo cargar' })).catch(() => setD({ error: 'No se pudo cargar' }))
  }, [])
  useEffect(() => setPag(0), [q, filtro, orden])

  if (!d) return <div className="dev-card"><SkTabla rows={8} cols={6} label="Cargando los clientes…" /></div>
  if (d.error) return <div className="dev-error"><Icon.warn /> {d.error}</div>

  const todas = d.rows || []
  const busca = q.trim().toLowerCase()
  const coincide = (r) => !busca || [r.name, r.commercial, r.vat, r.city, r.last?.code, r.portal_last?.code].some((v) => (v || '').toLowerCase().includes(busca))
  const pestañas = [
    ['open', 'Con garantías abiertas', todas.filter((r) => r.open > 0).length],
    ['unlinked', 'Sin cliente del portal', todas.filter((r) => !r.portal_id).length],
    ['', 'Todos', todas.length],
  ]
  const lista = todas
    .filter((r) => (filtro === 'open' ? r.open > 0 : filtro === 'unlinked' ? !r.portal_id : true))
    .filter(coincide)
    .sort((a, b) => orden === 'nombre' ? a.name.localeCompare(b.name, 'es')
      : orden === 'abiertas' ? (b.open - a.open) || (b.total - a.total)
      : orden === 'total' ? (b.total + b.portal_warranties) - (a.total + a.portal_warranties)
      : (b.last_at || '').localeCompare(a.last_at || ''))
  const paginas = Math.ceil(lista.length / POR_PAGINA)
  const visibles = lista.slice(pag * POR_PAGINA, (pag + 1) * POR_PAGINA)
  const k = d.kpis

  const exportar = () => descargarCsv('clientes-garantias.csv',
    ['Cliente', 'Nombre comercial', 'CIF', 'Ciudad', 'Cliente del portal', 'Garantías abiertas', 'Garantías aquí', 'Garantías en el portal', 'Última garantía', 'Fecha', 'Estado'],
    lista.map((r) => [r.name, r.commercial, r.vat, r.city, r.portal_id || 'Sin enlazar', r.open, r.total, r.portal_warranties,
      r.last?.code || r.portal_last?.code, r.last?.date || (r.portal_last?.date || '').slice(0, 10), r.last?.status || (r.portal_last ? 'En el portal' : '')]))

  return (
    <>
      {!d.portal ? <div className="dev-error"><Icon.warn /> El portal AEME no responde: solo se ven los clientes de las garantías de aquí.</div> : null}
      <div className="wi-kpis">
        <div className="wi-kpi">
          <div className="v">{num(k.clients)}</div>
          <div className="k">Clientes con garantías</div>
          <div className="h">Con alguna garantía aquí o un proyecto de garantía en el portal</div>
        </div>
        <div className="wi-kpi">
          <div className="v">{num(k.with_open)}<span className="wi-de">{k.with_open === 1 ? 'cliente' : 'clientes'}</span></div>
          <div className="k">Con garantías abiertas</div>
          <div className="h">{k.with_open ? 'Tienen algo pendiente ahora mismo' : 'Nadie tiene garantías abiertas'}</div>
        </div>
        <div className="wi-kpi">
          <div className="v">{num(k.open)}</div>
          <div className="k">Garantías abiertas</div>
          <div className="h">Entre todos los clientes</div>
        </div>
        <div className="wi-kpi">
          <div className="v">{num(k.unlinked)}<span className="wi-de">{k.unlinked === 1 ? 'garantía' : 'garantías'}</span></div>
          <div className="k">Sin cliente del portal</div>
          <div className="h">{k.unlinked ? <span className="zk-warn-t">No se podrán crear sus presupuestos hasta enlazarlas</span> : 'Todas enlazadas con su cliente'}</div>
        </div>
      </div>

      <div className="dev-card zk-bandeja">
        <div className="zk-bar">
          <div className="dev-search wl-search">
            <Icon.search />
            <input value={q} onChange={(e) => setQ(e.target.value)} placeholder="Buscar cliente, nombre comercial, CIF, ciudad o garantía…" />
            {q ? <button className="dev-x" onClick={() => setQ('')} title="Borrar"><Icon.x className="ic-x" aria-hidden="true" /></button> : null}
          </div>
          <div className="dc-seg zk-seg">{pestañas.map(([key, l, n]) => <button key={key || 'todos'} className={filtro === key ? 'on' : ''} onClick={() => setFiltro(key)}>{l}<em>{n}</em></button>)}</div>
          <Select sm value={orden} onChange={setOrden} options={ORDENES} />
          <span className="spacer" />
          <button className="dev-btn" disabled={!lista.length} onClick={exportar} title="Descargar lo que se ve en CSV"><Icon.download /> Exportar</button>
        </div>

        <div className="dev-table-wrap keep">
          <table className="dev-table cl-t">
            <thead><tr>
              <th>Cliente</th>
              <th className="c" title="Garantías de aquí que aún no están cerradas">Abiertas</th>
              <th className="c" title="Todas las garantías registradas aquí, abiertas y cerradas">Garantías aquí</th>
              <th className="c" title="Proyectos de garantía del cliente en el portal AEME (incluye los anteriores a esta aplicación)">En el portal</th>
              <th>Última garantía</th>
              <th />
            </tr></thead>
            <tbody>
              {visibles.map((r) => (
                <tr key={r.key} className="wr-row" {...filaClic(() => onOpen(r.key))}>
                  <td>
                    <b className="cl-name">{r.name}</b>
                    <small className="zk-sub">
                      {r.portal_id
                        ? [r.commercial && r.commercial.toLowerCase() !== r.name.toLowerCase() ? r.commercial : null, r.vat, r.city].filter(Boolean).join(' · ') || 'Cliente del portal'
                        : <span className="zk-warn-t">Sin enlazar con un cliente del portal</span>}
                    </small>
                  </td>
                  <td className="c">{r.open ? <span className="cl-open">{r.open}</span> : <span className="wt-0">0</span>}</td>
                  <td className="c">{r.total ? <b className="wt-num">{num(r.total)}</b> : <span className="wt-0">0</span>}</td>
                  <td className="c">{r.portal_warranties ? <b className="wt-num">{num(r.portal_warranties)}</b> : <span className="wt-0">{r.portal_id ? 0 : '—'}</span>}</td>
                  <td>
                    {r.last ? (
                      <><span className="wr-code">{r.last.code}</span> <Pill cls={r.last.closed ? 'green' : 'blue'}>{r.last.status}</Pill><small className="zk-sub">Recibida el {fecha(r.last.date)}</small></>
                    ) : r.portal_last ? (
                      <><span className="wr-code">{r.portal_last.code || 'Proyecto de garantía'}</span><small className="zk-sub">En el portal · {fecha(r.portal_last.date)}</small></>
                    ) : <span className="dev-mute">—</span>}
                  </td>
                  <td className="wt-ver"><span className="wf-link">Ver ficha <Icon.arrowRight /></span></td>
                </tr>
              ))}
            </tbody>
          </table>
          {!lista.length && (
            filtro === 'open' && !busca
              ? <Vacio icon={Icon.check} title="Ningún cliente tiene garantías abiertas"
                  actions={todas.length ? <button className="dev-btn" onClick={() => { setFiltro(''); setPag(0) }}>Ver todos ({todas.length})</button> : null}>
                  Todo al día.</Vacio>
              : <Vacio icon={Icon.search} title="Ningún cliente coincide"
                  actions={<button className="dev-btn" onClick={() => { setQ(''); setFiltro(''); setPag(0) }}><Icon.x /> Quitar los filtros</button>} />
          )}
        </div>
        {lista.length ? (
          <div className="zk-pie">
            <span>Se ven <b>{visibles.length}</b> de <b>{plural(lista.length, 'cliente', 'clientes')}</b></span>
            {paginas > 1 ? (
              <span className="zk-pag">
                <button className="dev-btn" disabled={!pag} onClick={() => setPag(pag - 1)}>‹ Anterior</button>
                <span className="dev-mute">Página {pag + 1} de {paginas}</span>
                <button className="dev-btn" disabled={pag >= paginas - 1} onClick={() => setPag(pag + 1)}>Siguiente ›</button>
              </span>
            ) : null}
          </div>
        ) : null}
      </div>
    </>
  )
}

/* --- Ficha --- */
const ETAPAS = { none: ['Sin pedido', 'gray'], ordered: ['Pedido hecho', 'blue'], shipping: ['En envío', 'indigo'], done: ['Entregado', 'green'] }
const TIPOS = { garantia: 'En garantía', pilas: 'Pilas', cliente: 'Fuera de garantía', mano_obra: 'Mano de obra', otro: 'Otro' }
const BATCH_CLS = { borrador: 'gray', enviada: 'blue', recibida: 'indigo', respondida: 'purple', cerrada: 'green', anulada: 'red' }
const Ext = ({ href, children }) => <a className="link cl-ext" href={href} target="_blank" rel="noreferrer" onClick={(e) => e.stopPropagation()}>{children} ↗</a>

function Ficha({ k, onBack, onOpenWarranty }) {
  const [d, setD] = useState(null)
  const [tab, setTab] = useState('garantias')
  const [lugar, setLugar] = useState(null)
  const [q, setQ] = useState('')
  const [estado, setEstado] = useState('')
  const [recarga, setRecarga] = useState(false)

  const cargar = (fresh = false) => {
    setRecarga(fresh)
    api.warrantyClient(k, fresh).then((r) => setD(r.ok ? r : { error: r.error || 'No se pudo cargar' })).catch(() => setD({ error: 'No se pudo cargar' })).finally(() => setRecarga(false))
  }
  useEffect(() => { cargar() }, [k])

  const volver = <button className="wr-back" onClick={onBack}><Icon.chevron style={{ transform: 'rotate(90deg)' }} /> Clientes</button>
  if (!d) return <SkStats label="Cargando el cliente…" />
  if (d.error) return <>{volver}<div className="dev-error"><Icon.warn /> {d.error}</div></>

  const c = d.client
  const kp = d.kpis
  const proyectos = d.portal_projects || []
  const presupuestos = proyectos.flatMap((p) => p.estimates.map((e) => ({ ...e, project: p })))
  const deLugar = (g) => !lugar || (lugar.id && g.portal_project_id === lugar.id) || (g.project && g.project.toLowerCase() === lugar.name.toLowerCase())
  const busca = q.trim().toLowerCase()
  const gs = d.warranties.filter(deLugar)
    .filter((g) => (estado === 'open' ? !g.closed : estado === 'closed' ? g.closed : true))
    .filter((g) => !busca || [g.code, g.project, g.responsible, g.status].some((v) => (v || '').toLowerCase().includes(busca)))
  const TABS = [
    ['garantias', 'Garantías', d.warranties.length],
    ['portal', 'Garantías en el portal', d.portal_projects ? proyectos.length : null],
    ['presupuestos', 'Presupuestos y albaranes', d.portal_projects ? presupuestos.length : null],
    ['zkong', 'Envíos a ZKONG', d.batches.length],
  ]

  return (
    <>
      {volver}
      <div className="dev-card cl-head">
        <div className="cl-id">
          <span className="cl-av"><Icon.building /></span>
          <div>
            <h2>{c.name}{c.active === false ? <Pill cls="gray">Inactivo en el portal</Pill> : null}</h2>
            <p className="dev-mute">
              {[c.commercial && c.commercial.toLowerCase() !== c.name.toLowerCase() ? c.commercial : null, c.vat ? `CIF ${c.vat}` : null, c.address, c.phone].filter(Boolean).join(' · ')
                || (c.id ? 'Cliente del portal' : 'Estas garantías no están enlazadas con un cliente del portal. Edítalas y elige el cliente para ver aquí sus documentos.')}
            </p>
          </div>
        </div>
        <span className="spacer" />
        {c.id ? <button className="dev-btn" disabled={recarga} onClick={() => cargar(true)} title="Volver a leer el portal (se guarda 5 minutos)"><Icon.refresh /> {recarga ? 'Actualizando…' : 'Actualizar'}</button> : null}
        {c.url ? <a className="btn" href={c.url} target="_blank" rel="noreferrer">Ver en el portal AEME ↗</a> : null}
      </div>

      <div className="wi-kpis">
        <div className="wi-kpi">
          <div className="v">{num(kp.open)}<span className="wi-de">de {num(kp.open + kp.closed)}</span></div>
          <div className="k">Garantías abiertas</div>
          <div className="h">{kp.closed ? `${plural(kp.closed, 'cerrada', 'cerradas')}` : 'Ninguna cerrada todavía'}</div>
        </div>
        <div className="wi-kpi">
          <div className="v">{num(kp.items)}</div>
          <div className="k">Dispositivos en garantía</div>
          <div className="h">Etiquetas y antenas de todas sus garantías</div>
        </div>
        <div className="wi-kpi">
          <div className="v">{kp.answered ? `${Math.round((kp.accepted / kp.answered) * 100)} %` : '—'}</div>
          <div className="k">Aceptadas por ZKONG</div>
          <div className="h">{kp.answered ? `${num(kp.accepted)} de ${num(kp.answered)} respondidas (reposición o abono)` : d.batches.length ? 'ZKONG aún no ha respondido' : 'No se ha enviado nada a ZKONG'}</div>
        </div>
        <div className="wi-kpi">
          <div className="v">{d.portal_projects ? num(proyectos.length) : '—'}</div>
          <div className="k">Garantías en el portal</div>
          <div className="h">{d.portal_projects ? `${plural(presupuestos.length, 'presupuesto', 'presupuestos')} desde julio de 2024` : c.id ? 'El portal no responde' : 'Sin cliente del portal'}</div>
        </div>
      </div>

      <div className="cl-cols">
        <aside className="dev-card cl-side">
          <div className="cl-side-h">Hoteles y tiendas</div>
          <button className={`cl-place ${!lugar ? 'zk-on' : ''}`} onClick={() => setLugar(null)}>
            <span>Todos</span><em>{d.warranties.length}</em>
          </button>
          {d.places.map((p) => (
            <button key={`${p.id}-${p.name}`} className={`cl-place ${lugar && lugar.name === p.name ? 'zk-on' : ''}`} onClick={() => { setLugar(p); setTab('garantias') }}>
              <span>{p.name}{p.open ? <small className="cl-open-t">{plural(p.open, 'abierta', 'abiertas')}</small> : null}</span>
              <em className={p.total ? '' : 'z'}>{p.total}</em>
            </button>
          ))}
          {!d.places.length ? <p className="dev-mute cl-side-e">{c.id ? 'El portal no tiene hoteles o tiendas de este cliente.' : 'Sin hoteles o tiendas.'}</p> : null}
          <p className="cl-side-f">El número es cuántas garantías tiene aquí cada uno.</p>
        </aside>

        <div className="dev-card zk-bandeja cl-main">
          <div className="dc-seg cl-tabs">
            {TABS.map(([key, l, n]) => <button key={key} className={tab === key ? 'on' : ''} onClick={() => setTab(key)}>{l}{n != null ? <em>{n}</em> : null}</button>)}
          </div>

          {tab === 'garantias' && (
            <>
              <div className="zk-bar">
                <div className="dev-search wl-search">
                  <Icon.search />
                  <input value={q} onChange={(e) => setQ(e.target.value)} placeholder="Buscar garantía, hotel, responsable o estado…" />
                  {q ? <button className="dev-x" onClick={() => setQ('')} title="Borrar"><Icon.x className="ic-x" aria-hidden="true" /></button> : null}
                </div>
                <div className="dc-seg zk-seg">
                  {[['', 'Todas'], ['open', 'Abiertas'], ['closed', 'Cerradas']].map(([key, l]) => <button key={key || 'all'} className={estado === key ? 'on' : ''} onClick={() => setEstado(key)}>{l}</button>)}
                </div>
                {lugar ? <span className="cl-filtro">{lugar.name} <button className="dev-x" onClick={() => setLugar(null)} title="Quitar"><Icon.x className="ic-x" aria-hidden="true" /></button></span> : null}
              </div>
              <div className="dev-table-wrap keep">
                <table className="dev-table cl-t">
                  <thead><tr><th>Garantía</th><th>Hotel o tienda</th><th>Estado</th><th className="c" title="Dispositivos revisados de los que tiene la garantía">Revisados</th><th>Responsable</th><th /></tr></thead>
                  <tbody>
                    {gs.map((g) => (
                      <tr key={g.id} className="wr-row" {...filaClic(() => onOpenWarranty(g.id))}>
                        <td><span className="wr-code">{g.code}</span><small className="zk-sub">{g.received_at ? `Recibida el ${fecha(g.received_at)}` : 'Pendiente de recibir'}{g.closed_at ? ` · cerrada el ${fecha(g.closed_at)}` : ''}</small></td>
                        <td>{g.project || <span className="dev-mute">—</span>}</td>
                        <td><Pill cls={g.closed ? 'green' : 'blue'}>{g.status}</Pill></td>
                        <td className="c">{g.items ? <span className="zk-prog"><span><i style={{ width: `${(g.reviewed / g.items) * 100}%` }} /></span>{g.reviewed}/{g.items}</span> : <span className="wt-0">Sin dispositivos</span>}</td>
                        <td>{g.responsible || <span className="dev-mute">Sin responsable</span>}</td>
                        <td className="wt-ver"><span className="wf-link">Abrir <Icon.arrowRight /></span></td>
                      </tr>
                    ))}
                  </tbody>
                </table>
                {!gs.length && <div className="act-empty"><Icon.shield style={{ width: 30, height: 30, fill: 'var(--ink-3)' }} /><p>{d.warranties.length ? 'Ninguna garantía con este filtro.' : 'Este cliente no tiene garantías aquí todavía.'}</p></div>}
              </div>
            </>
          )}

          {tab === 'portal' && (
            <SinPortal d={d} c={c}>
              <div className="dev-table-wrap keep">
                <table className="dev-table cl-t">
                  <thead><tr><th>Garantía del portal</th><th>Proyecto</th><th>Creada</th><th className="c">Presupuestos</th><th>Último presupuesto</th><th /></tr></thead>
                  <tbody>
                    {proyectos.map((p) => {
                      const e = p.estimates[0]
                      return (
                        <tr key={p.id}>
                          <td><span className="wr-code">{p.code || `#${p.id}`}</span></td>
                          <td>{p.title}</td>
                          <td>{fecha(p.created)}</td>
                          <td className="c">{p.estimates.length ? <b className="wt-num">{p.estimates.length}</b> : <span className="wt-0">0</span>}</td>
                          <td>{e ? <><Ext href={e.url}>{e.code}</Ext> <Pill cls={ETAPAS[e.stage]?.[1]}>{ETAPAS[e.stage]?.[0]}</Pill></> : <span className="dev-mute">Sin presupuestos</span>}</td>
                          <td className="wt-ver"><Ext href={p.url}>Abrir en el portal</Ext></td>
                        </tr>
                      )
                    })}
                  </tbody>
                </table>
                {!proyectos.length && <div className="act-empty"><Icon.doc style={{ width: 30, height: 30, fill: 'var(--ink-3)' }} /><p>Este cliente no tiene garantías en el portal desde julio de 2024.</p></div>}
              </div>
            </SinPortal>
          )}

          {tab === 'presupuestos' && (
            <SinPortal d={d} c={c}>
              <div className="dev-table-wrap keep">
                <table className="dev-table cl-t">
                  <thead><tr><th>Presupuesto</th><th>Garantía</th><th>Tipo</th><th className="r">Importe</th><th>Pedido</th><th>Expedición</th><th>Albarán</th><th>Situación</th></tr></thead>
                  <tbody>
                    {presupuestos.map((e) => (
                      <tr key={e.id}>
                        <td><Ext href={e.url}>{e.code}</Ext><small className="zk-sub">{fecha(e.date)}</small></td>
                        <td>{e.project.code || e.project.title}</td>
                        <td>{TIPOS[e.type] || e.type}</td>
                        <td className="r mono">{e.amount.toLocaleString('es-ES', { style: 'currency', currency: 'EUR' })}</td>
                        <td>{e.orders.length ? e.orders.map((o) => <div key={o.code}><Ext href={o.url}>{o.code}</Ext></div>) : <span className="dev-mute">—</span>}</td>
                        <td>{e.expeditions.length ? e.expeditions.map((o) => <div key={o.code}><Ext href={o.url}>{o.code}</Ext></div>) : <span className="dev-mute">—</span>}</td>
                        <td>{e.deliveries.length ? e.deliveries.map((o) => <div key={o.code}><Ext href={o.url}>{o.code}</Ext>{o.sent_at ? <small className="zk-sub">Enviado el {fecha(o.sent_at)}</small> : null}</div>) : <span className="dev-mute">—</span>}</td>
                        <td><Pill cls={ETAPAS[e.stage]?.[1]}>{ETAPAS[e.stage]?.[0]}</Pill></td>
                      </tr>
                    ))}
                  </tbody>
                </table>
                {!presupuestos.length && <div className="act-empty"><Icon.doc style={{ width: 30, height: 30, fill: 'var(--ink-3)' }} /><p>No hay presupuestos de garantía en el portal desde julio de 2024.</p></div>}
              </div>
            </SinPortal>
          )}

          {tab === 'zkong' && (
            <div className="dev-table-wrap keep">
              <table className="dev-table cl-t">
                <thead><tr><th>Remesa</th><th>Estado</th><th className="c" title="Etiquetas de este cliente en la remesa">Etiquetas</th><th>Garantías</th><th>Respuesta de ZKONG</th><th>Enviada</th><th>Llegada a ZKONG</th></tr></thead>
                <tbody>
                  {d.batches.map((b) => (
                    <tr key={b.id}>
                      <td><span className="wr-code">{b.code}</span></td>
                      <td><Pill cls={BATCH_CLS[b.status_key]}>{b.status}</Pill></td>
                      <td className="c"><b className="wt-num">{b.items}</b></td>
                      <td>{b.warranties.join(', ')}</td>
                      <td>{b.answered ? <span className="zk-prog"><span><i style={{ width: `${(b.answered / b.items) * 100}%` }} /></span>{b.answered}/{b.items} · {b.accepted} aceptadas</span> : <span className="dev-mute">Sin respuestas aún</span>}</td>
                      <td>{b.sent_at ? fecha(b.sent_at) : <span className="dev-mute">Sin enviar</span>}</td>
                      <td>{b.received_at ? <span className="zk-ok"><Icon.check /> {fecha(b.received_at)}</span> : <span className="dev-mute">—</span>}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
              {!d.batches.length && <div className="act-empty"><Icon.zkong style={{ width: 30, height: 30, fill: 'var(--ink-3)' }} /><p>Ninguna etiqueta de este cliente ha ido a ZKONG.</p></div>}
            </div>
          )}
        </div>
      </div>
    </>
  )
}

function SinPortal({ d, c, children }) {
  if (d.portal_projects) return children
  return (
    <div className="act-empty"><Icon.warn style={{ width: 30, height: 30, fill: 'var(--ink-3)' }} />
      <p>{c.id ? 'El portal AEME no responde ahora mismo. Prueba con «Actualizar» en un rato.' : 'Estas garantías no están enlazadas con un cliente del portal, así que no hay documentos que mostrar.'}</p></div>
  )
}
