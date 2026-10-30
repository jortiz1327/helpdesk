import { useState, useEffect, useCallback, useRef } from 'react'
import { api } from '../api.js'
import { Icon } from '../icons.jsx'
import { useToast, useConfirm } from '../App.jsx'

/* -------------------------------------------------------------------------
 * GARANTÍAS (Gestión de garantías). Expedientes G-AAAA-NNNN.
 *
 * Lista con filtros rápidos (mis garantías, abiertas, urgentes, pendientes…) y ficha
 * con tres pestañas: Resumen, Seguimiento (notas del equipo) e Historial (cada cambio
 * de campo, automático). Más adelante se abrirán desde un ticket de soporte y tendrán
 * Productos, ZKONG y Documentos.
 * ---------------------------------------------------------------------- */

const ESTADO_CLS = { no_iniciado: 'gray', en_curso: 'blue', pte_cliente: 'amber', pte_zkong: 'purple', pte_envio: 'orange', enviada: 'indigo', cerrada: 'green' }
const URG_CLS = { baja: 'gray', media: 'amber', alta: 'orange', muy_urgente: 'red' }
const CHIPS = [
  ['mine', 'Mis garantías'], ['open', 'Abiertas'], ['urgent', 'Urgentes'], ['pte_cliente', 'Pte. cliente'],
  ['pte_zkong', 'Pte. ZKONG'], ['pte_envio', 'Pte. envío'], ['stale', 'Sin actualizar'], ['no_owner', 'Sin responsable'],
  ['no_client', 'Sin cliente'], ['no_project', 'Sin proyecto'], ['no_category', 'Sin categoría'], ['closed', 'Cerradas'],
]
const SIZE = 25
const hoy = () => new Date().toISOString().slice(0, 10)
const VACIA = { id: 0, received_at: '', client_name: '', project_name: '', category: '', urgency: '', status: 'no_iniciado', responsible_id: '', ticket_code: '', description: '' }

const aFecha = (v) => (v ? new Date(String(v).replace(' ', 'T')) : null)
const fecha = (v) => { const d = aFecha(v); return d && !isNaN(d) ? d.toLocaleDateString('es-ES', { day: '2-digit', month: '2-digit', year: 'numeric' }) : '—' }
const fechaHora = (v) => { const d = aFecha(v); return d && !isNaN(d) ? d.toLocaleString('es-ES', { day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit' }) : '—' }
const dias = (desde, hasta) => { const a = aFecha(desde); if (!a) return null; const b = hasta ? aFecha(hasta) : new Date(); return Math.max(0, Math.floor((b - a) / 86400000)) }
const haceDias = (n) => (n == null ? '—' : n === 0 ? 'hoy' : n === 1 ? 'ayer' : `hace ${n} días`)
const num = (n) => Number(n || 0).toLocaleString('es-ES')

function Pill({ cls, children }) {
  return <span className={`wr-pill ${cls}`}><i />{children}</span>
}
function EstadoPill({ s, opts }) { return <Pill cls={ESTADO_CLS[s] || 'gray'}>{opts?.statuses?.[s] || s}</Pill> }
function UrgPill({ u, opts }) { return u ? <Pill cls={URG_CLS[u] || 'gray'}>{opts?.urgencies?.[u] || u}</Pill> : <Pill cls="none">No disponible</Pill> }

/* --- Alta y edición --- */
function Formulario({ inicial, opts, onClose, onSaved }) {
  const toast = useToast()
  const [f, setF] = useState(inicial)
  const [saving, setSaving] = useState(false)
  const set = (k) => (e) => setF((s) => ({ ...s, [k]: e.target.value }))

  const guardar = async () => {
    if (!f.received_at) { toast('Indica la fecha de recepción', 'err'); return }
    setSaving(true)
    const r = await api.saveWarranty(f)
    setSaving(false)
    if (r.ok) { toast(f.id ? 'Garantía guardada' : `Garantía ${r.code} creada`); onSaved(r.id) } else toast(r.error || 'Error', 'err')
  }

  return (
    <div className="modal-bg wr-over" onMouseDown={(e) => e.target.classList.contains('modal-bg') && onClose()}>
      <div className="modal" style={{ maxWidth: 640 }}>
        <div className="modal-head">
          <h3>{f.id ? `Editar ${f.code}` : inicial.ticket_code ? `Nueva garantía desde ${inicial.ticket_code}` : 'Nueva garantía'}</h3>
          <button className="x" onClick={onClose}>×</button>
        </div>
        <div className="modal-body">
          <div className="grid2">
            <label className="field"><span className="lbl">Cliente</span>
              <input value={f.client_name} onChange={set('client_name')} placeholder="Empresa del cliente" autoFocus /></label>
            <label className="field"><span className="lbl">Proyecto</span>
              <input value={f.project_name} onChange={set('project_name')} placeholder="Hotel, tienda…" /></label>
          </div>
          <div className="grid2">
            <label className="field"><span className="lbl">Fecha de recepción <em>*</em></span>
              <input type="date" value={f.received_at} onChange={set('received_at')} /></label>
            <label className="field"><span className="lbl">Categoría</span>
              <input list="wr-cats" value={f.category} onChange={set('category')} placeholder="Elige o escribe" />
              <datalist id="wr-cats">{(opts?.categories || []).map((c) => <option key={c} value={c} />)}</datalist></label>
          </div>
          <div className="grid2">
            <label className="field"><span className="lbl">Estado</span>
              <select value={f.status} onChange={set('status')}>
                {Object.entries(opts?.statuses || {}).map(([k, v]) => <option key={k} value={k}>{v}</option>)}
              </select></label>
            <label className="field"><span className="lbl">Urgencia</span>
              <select value={f.urgency || ''} onChange={set('urgency')}>
                <option value="">No disponible</option>
                {Object.entries(opts?.urgencies || {}).map(([k, v]) => <option key={k} value={k}>{v}</option>)}
              </select></label>
          </div>
          <div className="grid2">
            <label className="field"><span className="lbl">Responsable</span>
              <select value={f.responsible_id || ''} onChange={set('responsible_id')}>
                <option value="">Sin responsable</option>
                {(opts?.agents || []).map((a) => <option key={a.id} value={a.id}>{a.name}</option>)}
              </select></label>
            <label className="field"><span className="lbl">Ticket de soporte <span className="hint">(opcional)</span></span>
              <input className="mono" value={f.ticket_code || ''} onChange={set('ticket_code')} placeholder="TK-2610-0001" /></label>
          </div>
          <label className="field"><span className="lbl">Descripción</span>
            <textarea rows={4} value={f.description || ''} onChange={set('description')} placeholder="Qué le pasa al cliente, cuántas etiquetas, cómo llegó…" /></label>
        </div>
        <div className="modal-foot">
          <button className="btn ghost" onClick={onClose}>Cancelar</button>
          <button className="btn" disabled={saving} onClick={guardar}>{saving ? 'Guardando…' : (f.id ? 'Guardar' : 'Crear garantía')}</button>
        </div>
      </div>
    </div>
  )
}

/* Alta desde otra pantalla (la ficha de un ticket): carga sus opciones y abre el
   formulario con lo que ya se sabe del ticket. */
export function NuevaGarantia({ inicial, onClose, onSaved }) {
  const [opts, setOpts] = useState(null)
  useEffect(() => { api.warrantyOptions().then((r) => setOpts(r.ok ? r : {})) }, [])
  if (!opts) return null
  return <Formulario inicial={{ ...VACIA, received_at: hoy(), ...inicial }} opts={opts} onClose={onClose} onSaved={onSaved} />
}

/* --- Lista --- */
function Lista({ opts, onOpen, onNew, reload }) {
  const [chip, setChip] = useState('')
  const [qLive, setQLive] = useState('')
  const [q, setQ] = useState('')
  const [sort, setSort] = useState('')
  const [page, setPage] = useState(0)
  const [data, setData] = useState(null)
  const [loading, setLoading] = useState(true)
  const pedida = useRef(0)

  useEffect(() => { const t = setTimeout(() => { setQ(qLive.trim()); setPage(0) }, 300); return () => clearTimeout(t) }, [qLive])

  useEffect(() => {
    const n = ++pedida.current
    setLoading(true)
    api.warranties({ chip, q, sort, page, size: SIZE }).then((r) => {
      if (n !== pedida.current) return
      setLoading(false)
      setData(r.ok ? r : { rows: [], total: 0, all: 0, error: r.error })
    })
  }, [chip, q, sort, page, reload])

  const total = data?.total || 0
  const paginas = Math.ceil(total / SIZE)
  const filas = data?.rows || []

  const exportar = () => {
    const esc = (v) => `"${String(v ?? '').replace(/"/g, '""')}"`
    const cab = ['Expediente', 'Recepción', 'Cliente', 'Proyecto', 'Categoría', 'Urgencia', 'Responsable', 'Estado', 'Ticket']
    const lineas = filas.map((w) => [w.code, fecha(w.received_at), w.client_name, w.project_name, w.category, opts?.urgencies?.[w.urgency] || 'No disponible', w.responsible_name, opts?.statuses?.[w.status], w.ticket_code])
    const blob = new Blob(['﻿' + [cab.map(esc).join(';'), ...lineas.map((l) => l.map(esc).join(';'))].join('\r\n')], { type: 'text/csv;charset=utf-8' })
    const a = document.createElement('a')
    a.href = URL.createObjectURL(blob)
    a.download = `garantias-${hoy()}.csv`
    a.click()
    setTimeout(() => URL.revokeObjectURL(a.href), 1000)
  }

  return (
    <>
      <div className="wr-head">
        <div>
          <h2>Garantías</h2>
          <p className="dev-mute">{data ? <><b>{num(total)}</b> de {num(data.all)} expedientes</> : 'Cargando…'}</p>
        </div>
        <span className="spacer" />
        <button className="dev-btn" disabled={!filas.length} onClick={exportar}><Icon.download /> Exportar</button>
        <button className="btn" onClick={onNew}><Icon.plus /> Nueva garantía</button>
      </div>

      <div className="wr-chips">
        {CHIPS.map(([k, l]) => (
          <button key={k} className={chip === k ? 'on' : ''} onClick={() => { setChip(chip === k ? '' : k); setPage(0) }}>{l}</button>
        ))}
      </div>

      <div className={`dev-card ${loading ? 'busy' : ''}`}>
        {loading && <div className="dev-progress" />}
        <div className="dev-tools">
          <div className="dev-search" style={{ flexBasis: 420 }}>
            <Icon.search />
            <input value={qLive} onChange={(e) => setQLive(e.target.value)} placeholder="Buscar por expediente, cliente, proyecto o ticket…" />
            {qLive ? <button className="dev-x" onClick={() => setQLive('')} title="Borrar">×</button> : null}
          </div>
          {chip ? <button className="dev-clear" onClick={() => setChip('')}><Icon.x /> Quitar filtro</button> : null}
        </div>
        <div className="dev-table-wrap keep">
          <table className="dev-table wr-table">
            <thead><tr>
              <th>Expediente</th>
              <th><button className="wr-sort" onClick={() => setSort(sort === 'received_asc' ? '' : 'received_asc')}>Recepción <Icon.chevron style={{ transform: sort === 'received_asc' ? 'rotate(180deg)' : 'none' }} /></button></th>
              <th>Cliente</th><th>Proyecto</th><th>Categoría</th><th>Urgencia</th><th>Responsable</th><th>Estado</th>
            </tr></thead>
            <tbody>
              {data === null
                ? Array.from({ length: 6 }, (_, i) => <tr key={i}>{Array.from({ length: 8 }, (_, j) => <td key={j}><span className="dev-skel" /></td>)}</tr>)
                : filas.map((w) => (
                  <tr key={w.id} className="wr-row" onClick={() => onOpen(w.id)}>
                    <td><span className="wr-code">{w.code}</span>{w.ticket_code ? <span className="wr-tk" title={`Ticket ${w.ticket_code}`}><Icon.ticket /></span> : null}</td>
                    <td>{fecha(w.received_at)}</td>
                    <td className="wr-ell">{w.client_name || <span className="dev-mute">—</span>}</td>
                    <td className="wr-ell">{w.project_name || <span className="dev-mute">—</span>}</td>
                    <td>{w.category || <span className="dev-mute">—</span>}</td>
                    <td><UrgPill u={w.urgency} opts={opts} /></td>
                    <td>{w.responsible_name || <span className="dev-mute">Sin asignar</span>}</td>
                    <td><EstadoPill s={w.status} opts={opts} /></td>
                  </tr>
                ))}
            </tbody>
          </table>
          {data && !filas.length && (
            <div className="act-empty"><Icon.shield style={{ width: 30, height: 30, fill: 'var(--ink-3)' }} />
              <p>{chip || q ? 'Ninguna garantía coincide con el filtro.' : 'Aún no hay garantías. Crea la primera con «Nueva garantía».'}</p></div>
          )}
        </div>
        {paginas > 1 && (
          <div className="dev-foot">
            <span className="dev-mute">Mostrando <b>{num(page * SIZE + 1)}–{num(Math.min(total, (page + 1) * SIZE))}</b> de <b>{num(total)}</b></span>
            <span className="spacer" />
            <div className="dev-pages">
              <button disabled={page === 0 || loading} onClick={() => setPage(page - 1)}><Icon.chevron style={{ transform: 'rotate(90deg)' }} /> <span>Anterior</span></button>
              <span className="dev-mute">{page + 1} / {paginas}</span>
              <button disabled={page + 1 >= paginas || loading} onClick={() => setPage(page + 1)}><span>Siguiente</span> <Icon.chevron style={{ transform: 'rotate(-90deg)' }} /></button>
            </div>
          </div>
        )}
      </div>
    </>
  )
}

/* --- Ficha --- */
const HIST_FILTROS = [['', 'Todos'], ['status', 'Estado'], ['responsible_id', 'Responsable'], ['urgency', 'Urgencia'], ['otros', 'Otros']]
const CAMPOS = { created: 'Garantía creada', received_at: 'Fecha de recepción', client_name: 'Cliente', project_name: 'Proyecto', category: 'Categoría', urgency: 'Urgencia', status: 'Estado', responsible_id: 'Responsable', ticket_id: 'Ticket', description: 'Descripción' }

function Ficha({ id, opts, isSuper, onBack, onEdit, onChanged, onOpenTicket }) {
  const toast = useToast()
  const confirm = useConfirm()
  const [d, setD] = useState(null)
  const [tab, setTab] = useState('resumen')
  const [hist, setHist] = useState('')
  const [nota, setNota] = useState({ type: 'nota', body: '' })
  const [enviando, setEnviando] = useState(false)
  const notaRef = useRef(null)

  const cargar = useCallback(() => api.warranty(id).then((r) => setD(r.ok ? r : { error: r.error || 'No se pudo cargar' })), [id])
  useEffect(() => { setD(null); cargar() }, [cargar])

  if (d === null) return <div className="center-load"><div className="spinner" /></div>
  if (d.error) return <><button className="wr-back" onClick={onBack}><Icon.chevron style={{ transform: 'rotate(90deg)' }} /> Garantías</button><div className="dev-error"><Icon.warn /> {d.error}</div></>

  const w = d.warranty
  const abierta = w.status !== 'cerrada'
  const diasAbierta = dias(w.received_at, abierta ? null : w.closed_at)
  const diasSin = dias(w.last_activity_at)
  const stale = abierta && diasSin != null && diasSin >= (opts?.stale_days || 15)

  const guardarCon = async (cambios, msg) => {
    const r = await api.saveWarranty({ ...w, responsible_id: w.responsible_id || '', urgency: w.urgency || '', ticket_code: w.ticket_code || '', ...cambios })
    if (r.ok) { toast(msg); cargar(); onChanged() } else toast(r.error || 'Error', 'err')
  }
  const cerrar = async () => {
    if (!(await confirm({ title: 'Cerrar garantía', message: `¿Cerrar ${w.code}? Se podrá reabrir después.`, confirmText: 'Cerrar garantía' }))) return
    guardarCon({ status: 'cerrada' }, 'Garantía cerrada')
  }
  const borrar = async () => {
    if (!(await confirm({ title: 'Borrar garantía', message: `¿Borrar ${w.code} con todo su seguimiento e historial? No se puede deshacer.`, danger: true, confirmText: 'Borrar' }))) return
    const r = await api.deleteWarranty(w.id)
    if (r.ok) { toast('Garantía borrada'); onChanged(); onBack() } else toast(r.error || 'Error', 'err')
  }
  const enviarNota = async () => {
    if (!nota.body.trim()) return
    setEnviando(true)
    const r = await api.addWarrantyNote(w.id, nota.type, nota.body)
    setEnviando(false)
    if (r.ok) { setNota({ type: nota.type, body: '' }); cargar(); onChanged() } else toast(r.error || 'Error', 'err')
  }
  const irASeguimiento = () => { setTab('seguimiento'); setTimeout(() => notaRef.current?.focus(), 50) }

  const ticket = w.ticket_code
    ? (onOpenTicket ? <button className="wr-tklink mono" title={w.ticket_subject || 'Abrir ticket'} onClick={() => onOpenTicket(w.ticket_id)}>{w.ticket_code}</button> : <span className="mono">{w.ticket_code}</span>)
    : null
  const eventos = d.events.filter((e) => !hist || (hist === 'otros' ? !['status', 'responsible_id', 'urgency'].includes(e.field) : e.field === hist))

  return (
    <>
      <button className="wr-back" onClick={onBack}><Icon.chevron style={{ transform: 'rotate(90deg)' }} /> Garantías</button>

      <div className="wr-hero">
        <div className="wr-hero-top">
          <div className="wr-hero-id">
            <h2>{w.code}</h2>
            <div className="wr-hero-pills"><EstadoPill s={w.status} opts={opts} /><UrgPill u={w.urgency} opts={opts} /></div>
            <p>{[w.client_name, w.project_name].filter(Boolean).join(' — ') || <span className="dev-mute">Sin cliente ni proyecto</span>}</p>
            <div className="wr-flags">
              {abierta && diasAbierta >= 30 ? <span className="wr-flag"><Icon.warn /> Abierta {diasAbierta} días</span> : null}
              {stale ? <span className="wr-flag"><Icon.warn /> Sin actividad {diasSin} días</span> : null}
            </div>
          </div>
          <div className="wr-acts">
            <button className="btn" onClick={irASeguimiento}><Icon.plus /> Añadir seguimiento</button>
            {abierta
              ? <button className="btn dark" onClick={cerrar}><Icon.lock /> Cerrar garantía</button>
              : <button className="btn ghost" onClick={() => guardarCon({ status: 'en_curso' }, 'Garantía reabierta')}><Icon.refresh /> Reabrir</button>}
            <button className="dev-btn" onClick={() => onEdit(w)}><Icon.pencil /> Editar</button>
            {isSuper ? <button className="icon-btn" title="Borrar garantía" style={{ color: 'var(--danger)' }} onClick={borrar}><Icon.trash /></button> : null}
          </div>
        </div>
        <div className="wr-kpis">
          <div><span className="ic"><Icon.user /></span><div><small>Responsable</small><b>{w.responsible_name || 'Sin asignar'}</b></div></div>
          <div className={abierta && diasAbierta >= 30 ? 'bad' : ''}><span className="ic"><Icon.calendar /></span><div><small>{abierta ? 'Días abierta' : 'Días hasta el cierre'}</small><b>{diasAbierta ?? '—'} días</b></div></div>
          <div className={stale ? 'bad' : ''}><span className="ic"><Icon.clock /></span><div><small>Última actividad</small><b>{haceDias(diasSin)}</b></div></div>
          <div><span className="ic"><Icon.ticket /></span><div><small>Ticket de soporte</small><b>{ticket || 'Sin ticket'}</b></div></div>
        </div>
      </div>

      <div className="wr-tabs" role="tablist">
        {[['resumen', 'Resumen', Icon.doc], ['seguimiento', `Seguimiento${d.notes.length ? ` (${d.notes.length})` : ''}`, Icon.chat], ['historial', 'Historial', Icon.clock]].map(([k, l, Ic]) => (
          <button key={k} className={tab === k ? 'on' : ''} onClick={() => setTab(k)}><Ic /> {l}</button>
        ))}
        {[['Productos', Icon.tag], ['ZKONG', Icon.server], ['Documentos', Icon.file]].map(([l, Ic]) => (
          <button key={l} disabled title="Próximamente"><Ic /> {l} <em>Pronto</em></button>
        ))}
      </div>

      <div className="wr-panel">
        {tab === 'resumen' && (
          <dl className="wr-dl">
            <div><dt>Nº expediente</dt><dd><b>{w.code}</b></dd></div>
            <div><dt>Fecha de recepción</dt><dd>{fecha(w.received_at)}</dd></div>
            <div><dt>Cliente</dt><dd>{w.client_name || '—'}</dd></div>
            <div><dt>Proyecto</dt><dd>{w.project_name || '—'}</dd></div>
            <div><dt>Categoría</dt><dd>{w.category || '—'}</dd></div>
            <div><dt>Urgencia</dt><dd><UrgPill u={w.urgency} opts={opts} /></dd></div>
            <div><dt>Estado</dt><dd><EstadoPill s={w.status} opts={opts} /></dd></div>
            <div><dt>Responsable</dt><dd>{w.responsible_name || '—'}</dd></div>
            <div><dt>Ticket de soporte</dt><dd>{w.ticket_code ? <>{ticket}{w.ticket_subject ? <span className="dev-mute"> · {w.ticket_subject}</span> : null}</> : '—'}</dd></div>
            <div><dt>Creada</dt><dd>{fechaHora(w.created_at)}{w.created_by_name ? <span className="dev-mute"> · {w.created_by_name}</span> : null}</dd></div>
            {w.closed_at ? <div><dt>Cerrada</dt><dd>{fechaHora(w.closed_at)}</dd></div> : null}
            <div className="full"><dt>Descripción</dt><dd className="wr-desc">{w.description || <span className="dev-mute">Sin descripción</span>}</dd></div>
          </dl>
        )}

        {tab === 'seguimiento' && (
          <>
            <div className="wr-compose">
              <select value={nota.type} onChange={(e) => setNota((s) => ({ ...s, type: e.target.value }))}>
                {Object.entries(opts?.note_types || {}).map(([k, v]) => <option key={k} value={k}>{v}</option>)}
              </select>
              <textarea ref={notaRef} rows={3} value={nota.body} onChange={(e) => setNota((s) => ({ ...s, body: e.target.value }))}
                onKeyDown={(e) => { if (e.key === 'Enter' && (e.ctrlKey || e.metaKey)) enviarNota() }}
                placeholder="Qué se ha hecho, con quién se ha hablado, qué falta…" />
              <div className="wr-compose-f">
                <span className="dev-mute">Ctrl + Enter para añadir</span>
                <button className="btn" disabled={enviando || !nota.body.trim()} onClick={enviarNota}><Icon.plus /> {enviando ? 'Añadiendo…' : 'Añadir seguimiento'}</button>
              </div>
            </div>
            {!d.notes.length ? <p className="dev-mute">Todavía no hay seguimiento.</p> : (
              <ul className="wr-timeline">
                {d.notes.map((n) => (
                  <li key={n.id}>
                    <div className="wr-tl-h"><b>{n.user_name || 'Sistema'}</b><span className="dev-mute">{fechaHora(n.created_at)}</span></div>
                    <span className={`wr-type t-${n.type}`}>{opts?.note_types?.[n.type] || n.type}</span>
                    <p>{n.body}</p>
                  </li>
                ))}
              </ul>
            )}
          </>
        )}

        {tab === 'historial' && (
          <>
            <div className="wr-chips sm">
              {HIST_FILTROS.map(([k, l]) => <button key={k} className={hist === k ? 'on' : ''} onClick={() => setHist(k)}>{l}</button>)}
            </div>
            {!eventos.length ? <p className="dev-mute">Sin cambios {hist ? 'de este tipo' : 'todavía'}.</p> : (
              <ul className="wr-hist">
                {eventos.map((e) => (
                  <li key={e.id}>
                    <div className="wr-tl-h"><b>{e.user_name || 'Sistema'}</b><span className="dev-mute">{fechaHora(e.created_at)}</span><span className="wr-type">{e.field === 'created' ? 'Alta' : 'Modificación'}</span></div>
                    <div className="wr-hist-f">{CAMPOS[e.field] || e.field}</div>
                    {e.field === 'created' ? null : <div className="wr-hist-v"><s>{e.from_value || '—'}</s> → <b>{e.to_value || '—'}</b></div>}
                  </li>
                ))}
              </ul>
            )}
          </>
        )}
      </div>
    </>
  )
}

export default function Warranties({ isSuper = false, openId = null, onOpened, onOpenTicket }) {
  const [opts, setOpts] = useState(null)
  const [sel, setSel] = useState(openId)
  // Llegar con una garantía concreta (desde la ficha de un ticket).
  useEffect(() => { if (openId) { setSel(openId); onOpened?.() } }, [openId])
  const [form, setForm] = useState(null)
  const [reload, setReload] = useState(0)
  const refrescar = () => setReload((n) => n + 1)

  useEffect(() => { api.warrantyOptions().then((r) => r.ok && setOpts(r)) }, [])

  return (
    <>
      <header className="page-head">
        <span className="sc-ic"><Icon.shield style={{ width: 18, height: 18, fill: 'var(--primary)' }} /></span>
        <div><h1>Garantías</h1></div>
        <span className="sub">· Expedientes de garantía</span>
      </header>

      <div className="page-scroll"><div className="page dev-page wr-page">
        {sel
          ? <Ficha key={`${sel}-${reload}`} id={sel} opts={opts} isSuper={isSuper} onBack={() => setSel(null)}
              onEdit={(w) => setForm({ ...VACIA, ...w, responsible_id: w.responsible_id || '', urgency: w.urgency || '', ticket_code: w.ticket_code || '' })}
              onChanged={() => {}} onOpenTicket={onOpenTicket} />
          : <Lista opts={opts} reload={reload} onOpen={setSel} onNew={() => setForm({ ...VACIA, received_at: hoy() })} />}
      </div></div>

      {form && <Formulario inicial={form} opts={opts} onClose={() => setForm(null)} onSaved={(id) => { setForm(null); refrescar(); setSel(id) }} />}
    </>
  )
}
