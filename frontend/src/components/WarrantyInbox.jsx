import { useState, useEffect, useRef, useCallback } from 'react'
import { api } from '../api.js'
import { parseDate, fmtDate, fmtTime } from '../util.js'
import { Icon } from '../icons.jsx'
import Select from './Select.jsx'
import LoadError from './LoadError.jsx'
import { TicketModal, Paginador, prChip } from './Tickets.jsx'
import { NuevaGarantia } from './Warranties.jsx'

/*
 * «Tickets de garantías»: bandeja propia de Gestión de garantías. Lista los tickets de la
 * categoría Garantías con la garantía creada de cada uno (o «Sin garantía»). Pendientes =
 * vivos y aún sin garantía, el que más lleva esperando arriba. La fila abre el ticket
 * (la misma ficha de Soporte) y «Crear garantía» abre el alta ya rellena.
 */
const PESTANAS = [['pendientes', 'Pendientes'], ['con_garantia', 'Con garantía creada'], ['todos', 'Todos']]

// Cuánto lleva esperando: «<24 h», «1 día», «5 días» y el tono de la pastilla.
function espera(s) {
  const d = parseDate(s)
  if (!d) return null
  const h = (Date.now() - d.getTime()) / 36e5
  const dias = Math.floor(h / 24)
  return { txt: h < 24 ? '<24 h' : `${dias} ${dias === 1 ? 'día' : 'días'}`, cls: h >= 120 ? 'red' : h >= 48 ? 'amber' : 'gray' }
}

export default function WarrantyInbox({ user, initialTicket = null, onOpenWarranty }) {
  const [tab, setTab] = useState('pendientes')
  const [qLive, setQLive] = useState('')
  const [q, setQ] = useState('')
  const [prio, setPrio] = useState('')
  const [orden, setOrden] = useState('antiguos')
  const [page, setPage] = useState(1)
  const [perPage, setPerPage] = useState(25)
  const [d, setD] = useState(null)
  const [err, setErr] = useState(false)
  const [meta, setMeta] = useState(null)
  const [opts, setOpts] = useState(null)   // estados de garantía y su color (Configuración)
  const [open, setOpen] = useState(initialTicket)
  const [crear, setCrear] = useState(null)   // fila del ticket del que se crea la garantía
  const pedida = useRef(0)

  useEffect(() => { if (initialTicket) setOpen(initialTicket) }, [initialTicket])
  useEffect(() => { api.ticketMeta().then(setMeta); api.warrantyOptions().then((r) => setOpts(r?.ok ? r : {})) }, [])
  useEffect(() => { const t = setTimeout(() => { setQ(qLive.trim()); setPage(1) }, 300); return () => clearTimeout(t) }, [qLive])

  const load = useCallback(() => {
    const n = ++pedida.current
    setErr(false)
    api.warrantyInbox({ tab, q, priority: prio, order: orden === 'recientes' ? 'recientes' : '', page, per_page: perPage }).then((r) => {
      if (n !== pedida.current) return
      if (!r?.ok) { setErr(true); return }
      setD(r)
      if (r.page !== page) setPage(r.page)
    }).catch(() => setErr(true))
  }, [tab, q, prio, orden, page, perPage])
  useEffect(() => { load() }, [load])

  const prios = Object.entries(meta?.priority_meta || meta?.priorities || {}).map(([value, p]) => ({ value, label: typeof p === 'string' ? p : p.name, color: p?.color }))
  const rows = d?.tickets || []
  const c = d?.counts || {}
  const s = d?.summary

  return (
    <>
      <header className="page-head">
        <span className="sc-ic"><Icon.inbox style={{ width: 18, height: 18, fill: 'var(--primary)' }} /></span>
        <div><h1>Tickets de garantías</h1></div>
        <span className="sub">· Tickets de la categoría Garantías y la garantía que se ha abierto de cada uno</span>
      </header>
      <div className="page-scroll"><div className="page dev-page wr-page">
        {s && (
          <div className="wi-sum">
            <div><span>Pendientes sin asignar</span><b>{s.sin_asignar}</b></div>
            <div className={s.mas_48h ? 'bad' : ''}><span>Esperando más de 48 h</span><b>{s.mas_48h}</b></div>
            <div><span>Garantías creadas este mes</span><b>{s.creadas_mes}</b></div>
          </div>
        )}

        <div className="dev-card">
          <div className="wi-bar">
            <div className="dev-search"><Icon.search /><input value={qLive} placeholder="Buscar por código, asunto, cliente o garantía…" onChange={(e) => setQLive(e.target.value)} /></div>
            <div className="dc-seg wi-tabs">
              {PESTANAS.map(([k, l]) => (
                <button key={k} className={tab === k ? 'on' : ''} onClick={() => { setTab(k); setPage(1) }}>{l}<span className="wi-n">{c[k] ?? '·'}</span></button>
              ))}
            </div>
            <Select value={prio} onChange={(v) => { setPrio(v); setPage(1) }} options={[{ value: '', label: 'Prioridad: todas' }, ...prios]} />
            {tab === 'pendientes' && (
              <Select value={orden} onChange={(v) => { setOrden(v); setPage(1) }} options={[{ value: 'antiguos', label: 'Más antiguos primero' }, { value: 'recientes', label: 'Más recientes primero' }]} />
            )}
            <span className="spacer" />
            <button className="btn ghost" onClick={load} title="Volver a cargar"><Icon.refresh /> Actualizar</button>
          </div>

          {err ? <LoadError onRetry={load} /> : !d ? <div className="center-load"><div className="spinner" /></div> : !rows.length ? (
            <div className="wi-empty"><Icon.inbox /><span>{q ? 'Nada coincide con la búsqueda.' : tab === 'pendientes' ? 'No hay tickets de garantías esperando: todos tienen su garantía o están cerrados.' : 'No hay tickets en esta vista.'}</span></div>
          ) : (
            <div className="dev-table-wrap keep">
              <table className="dev-table wi-table">
                <thead><tr><th>Ticket</th><th>Asunto y remitente</th><th>Cliente</th><th>Llegó</th><th>Espera</th><th>Prioridad</th><th>Garantía</th><th className="r" /></tr></thead>
                <tbody>
                  {rows.map((t) => {
                    const e = espera(t.opened_at || t.created_at)
                    const vivo = ['nuevo', 'abierto', 'planificado'].includes(t.status)
                    const remite = t.contact_email || t.contact_phone || ''
                    return (
                      <tr key={t.id} className="wi-row" onClick={() => setOpen(t.id)}>
                        <td><span className="wi-code">{t.code}</span></td>
                        <td className="wi-asunto"><b title={t.subject}>{t.subject || '(sin asunto)'}</b><span>{t.contact_name && remite ? `${t.contact_name} · ${remite}` : (t.contact_name || remite)}</span></td>
                        <td className="wi-cli">{t.empresa || t.sede_name || <span className="dev-mute">—</span>}{t.empresa && t.sede_name ? <span>{t.sede_name}</span> : null}</td>
                        <td className="wi-fecha">{fmtDate(t.created_at, { day: '2-digit', month: '2-digit', year: 'numeric' })}<span>{fmtTime(t.created_at)}</span></td>
                        <td>{vivo && !t.warranties.length && e ? <span className={`wr-pill ${e.cls}`}><i />{e.txt}</span> : <span className="dev-mute">—</span>}</td>
                        <td>{prChip(t.priority, meta, true)}</td>
                        <td className="wi-gar">
                          {t.warranties.length ? t.warranties.map((w) => (
                            <button key={w.id} className="wr-pill wi-glink" style={{ '--pc': opts?.status_meta?.[w.status]?.color || '#2563eb' }} title={`Abrir la garantía · ${w.status_label}`}
                              onClick={(ev) => { ev.stopPropagation(); onOpenWarranty?.(w.id) }}><i />{w.code}</button>
                          )) : <span className={`wr-pill ${vivo ? 'amber' : 'none'}`}><i />Sin garantía</span>}
                        </td>
                        <td className="r">
                          {t.warranties.length
                            ? <button className="btn ghost sm" onClick={(ev) => { ev.stopPropagation(); onOpenWarranty?.(t.warranties[0].id) }}>Ver garantía <Icon.arrowRight /></button>
                            : <button className={vivo ? 'btn sm' : 'btn ghost sm'} onClick={(ev) => { ev.stopPropagation(); setCrear(t) }}><Icon.plus /> Crear garantía</button>}
                        </td>
                      </tr>
                    )
                  })}
                </tbody>
              </table>
            </div>
          )}
          {d && d.total > 0 && (
            <Paginador page={page} pages={d.pages} total={d.total} perPage={perPage} mostrados={rows.length}
              onPage={setPage} onPerPage={(n) => { setPerPage(n); setPage(1) }} />
          )}
        </div>
      </div></div>

      {open && <TicketModal id={open} meta={meta} user={user} onClose={() => { setOpen(null); load() }} onChange={load}
        onOpenTicket={(tid) => setOpen(tid)} scope="garantias" onOpenWarranty={onOpenWarranty} />}

      {crear && (
        <NuevaGarantia
          inicial={{
            ticket_code: crear.code, client_name: crear.contact_name || '', project_name: crear.sede_name || '', description: crear.subject || '',
            ...(/^\d{4}-\d{2}-\d{2}/.test(crear.opened_at || '') ? { first_contact_at: crear.opened_at.slice(0, 10) } : {}),
          }}
          onClose={() => setCrear(null)}
          onSaved={() => { setCrear(null); load() }} />
      )}
    </>
  )
}
