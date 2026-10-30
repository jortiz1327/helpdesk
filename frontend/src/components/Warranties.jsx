import { useState, useEffect, useCallback, useRef } from 'react'
import { api } from '../api.js'
import { Icon } from '../icons.jsx'
import { useToast, useConfirm } from '../App.jsx'
import Select from './Select.jsx'
import { FichaDispositivo } from './Devices.jsx'

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
// Punto de color de cada opción en los desplegables (mismos tonos que las píldoras).
const COLOR = { gray: '#94a3b8', blue: '#3b82f6', amber: '#f59e0b', purple: '#8b5cf6', orange: '#f97316', indigo: '#6366f1', green: '#22c55e', red: '#ef4444', none: '#cbd5e1' }
const CHIPS = [
  ['mine', 'Mis garantías'], ['open', 'Abiertas'], ['urgent', 'Urgentes'], ['pte_cliente', 'Pte. cliente'],
  ['pte_zkong', 'Pte. ZKONG'], ['pte_envio', 'Pte. envío'], ['stale', 'Sin actualizar'], ['no_owner', 'Sin responsable'],
  ['no_client', 'Sin cliente'], ['no_project', 'Sin proyecto'], ['no_category', 'Sin categoría'], ['closed', 'Cerradas'],
]
const SIZE = 25
const hoy = () => new Date().toISOString().slice(0, 10)
const VACIA = { id: 0, first_contact_at: '', received_at: '', closed_at: '', portal_client_id: null, client_name: '', portal_project_id: null, project_name: '', category: '', urgency: '', status: 'no_iniciado', responsible_id: '', ticket_code: '', description: '' }

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
  const [f, setF] = useState(() => ({ ...inicial, closed_at: (inicial.closed_at || '').slice(0, 10) }))
  const [saving, setSaving] = useState(false)
  const set = (k) => (e) => setF((s) => ({ ...s, [k]: e.target.value }))
  const pon = (k) => (v) => setF((s) => ({ ...s, [k]: v }))
  // Al pasar a «Cerrada» la fecha de cierre propone hoy (se puede cambiar).
  const elegirEstado = (v) => setF((s) => ({ ...s, status: v, closed_at: v === 'cerrada' ? s.closed_at || hoy() : s.closed_at }))

  // Cliente y proyecto salen del portal AEME; si no responde, se escriben a mano.
  const [clientes, setClientes] = useState(null)       // null = cargando
  const [proyectos, setProyectos] = useState(null)
  const [aMano, setAMano] = useState(false)             // proyecto escrito a mano
  const portal = clientes?.available !== false

  useEffect(() => {
    api.portalClients().then((r) => {
      const lista = r.ok ? r : { available: false, clients: [] }
      setClientes(lista)
      // Desde un ticket llega el nombre del contacto: si coincide con un cliente del portal, se elige.
      setF((s) => {
        if (s.portal_client_id || !s.client_name) return s
        const c = lista.clients.find((x) => x.name.toLowerCase() === s.client_name.trim().toLowerCase())
        return c ? { ...s, portal_client_id: c.id, client_name: c.name } : s
      })
    })
  }, [])

  useEffect(() => {
    if (!f.portal_client_id) { setProyectos(null); return }
    let vivo = true
    setProyectos('cargando')
    api.portalProjects(f.portal_client_id).then((r) => {
      if (!vivo) return
      const lista = r.ok && r.available ? r.projects : []
      setProyectos(lista)
      setF((s) => {
        if (s.portal_project_id || s.project_name) return s
        // Un solo hotel o tienda se elige solo; ninguno, el nombre del cliente.
        if (lista.length === 1) return { ...s, portal_project_id: lista[0].id, project_name: lista[0].name }
        return s
      })
      if (!lista.length) setAMano(true)
    })
    return () => { vivo = false }
  }, [f.portal_client_id])

  const elegirCliente = (id) => {
    if (id === '__txt') return
    const c = clientes.clients.find((x) => x.id === id)
    setAMano(false)
    setF((s) => ({ ...s, portal_client_id: id, client_name: c?.name || '', portal_project_id: null, project_name: '' }))
  }
  const elegirProyecto = (id) => {
    if (id === '__otro') { setAMano(true); setF((s) => ({ ...s, portal_project_id: null })); return }
    if (id === '__txt') return
    const p = (proyectos || []).find((x) => x.id === id)
    setF((s) => ({ ...s, portal_project_id: id, project_name: p?.name || '' }))
  }

  // Lo guardado a mano (o que vino del ticket) sigue visible aunque no esté en el portal.
  const optsCliente = [
    ...(f.client_name && !f.portal_client_id ? [{ value: '__txt', label: f.client_name, sub: 'No vinculado al portal' }] : []),
    ...(clientes?.clients || []).map((c) => ({ value: c.id, label: c.name })),
  ]
  const listaProy = Array.isArray(proyectos) ? proyectos : []
  const optsProyecto = [
    ...(f.project_name && !f.portal_project_id ? [{ value: '__txt', label: f.project_name, sub: 'Escrito a mano' }] : []),
    ...listaProy.map((p) => ({ value: p.id, label: p.name, sub: p.title })),
    { value: '__otro', label: 'Otro… (escribir el nombre)' },
  ]
  const valCliente = f.portal_client_id || (f.client_name ? '__txt' : '')
  const valProyecto = f.portal_project_id || (f.project_name ? '__txt' : '')

  // En el alta, las etiquetas afectadas (solo sus códigos).
  const [comp, setComp] = useState(COMP_VACIO)

  const guardar = async () => {
    if (!f.received_at) { toast('Indica la fecha de recepción', 'err'); return }
    setSaving(true)
    const r = await api.saveWarranty(f.id ? f : { ...f, labels: comp.text, manual: comp.manual })
    setSaving(false)
    if (!r.ok) { toast(r.error || 'Error', 'err'); return }
    const n = r.added?.length || 0
    toast(f.id ? 'Garantía guardada' : `Garantía ${r.code} creada${n ? ` con ${n} ${n === 1 ? 'etiqueta' : 'etiquetas'}` : ''}`)
    if (r.skipped?.length) toast(`${r.skipped.length} ${r.skipped.length === 1 ? 'etiqueta no entró' : 'etiquetas no entraron'}: ${r.skipped.slice(0, 3).map((s) => `${s.code} (${s.reason})`).join('; ')}`, 'err')
    onSaved(r.id)
  }

  return (
    <div className="modal-bg wr-over" onMouseDown={(e) => e.target.classList.contains('modal-bg') && onClose()}>
      <div className="modal" style={{ maxWidth: f.id ? 640 : 720 }}>
        <div className="modal-head">
          <h3>{f.id ? `Editar ${f.code}` : inicial.ticket_code ? `Nueva garantía desde ${inicial.ticket_code}` : 'Nueva garantía'}</h3>
          <button className="x" onClick={onClose}>×</button>
        </div>
        <div className="modal-body wr-form">
          {portal ? (
            <div className="grid2">
              <div className="field"><span className="lbl">Cliente <span className="hint">(portal AEME)</span></span>
                <Select block searchable value={valCliente} onChange={elegirCliente} options={optsCliente}
                  placeholder={clientes ? 'Busca el cliente…' : 'Cargando clientes…'} disabled={!clientes} /></div>
              <div className="field"><span className="lbl">Hotel o tienda</span>
                {aMano ? (
                  <div className="wr-mano">
                    <input value={f.project_name || ''} onChange={set('project_name')} autoFocus
                      placeholder={listaProy.length ? 'Nombre del hotel o tienda' : 'Sin proyectos en el portal: escribe el nombre'} />
                    {listaProy.length > 0 && <button type="button" className="btn ghost sm" onClick={() => setAMano(false)}>Lista</button>}
                  </div>
                ) : (
                  <Select block searchable value={valProyecto} onChange={elegirProyecto} options={optsProyecto}
                    disabled={!f.portal_client_id || proyectos === 'cargando'}
                    placeholder={!f.portal_client_id ? 'Elige antes el cliente' : proyectos === 'cargando' ? 'Cargando proyectos…' : `Elige entre ${listaProy.length}`} />
                )}</div>
            </div>
          ) : (
            <div className="grid2">
              <label className="field"><span className="lbl">Cliente <span className="hint">(portal sin conexión)</span></span>
                <input value={f.client_name || ''} onChange={set('client_name')} placeholder="Empresa del cliente" /></label>
              <label className="field"><span className="lbl">Hotel o tienda</span>
                <input value={f.project_name || ''} onChange={set('project_name')} placeholder="Hotel, tienda…" /></label>
            </div>
          )}
          <div className="grid2">
            <label className="field"><span className="lbl">Primera fecha de contacto</span>
              <input type="date" value={f.first_contact_at || ''} onChange={set('first_contact_at')} /></label>
            <label className="field"><span className="lbl">Fecha de recepción <em>*</em></span>
              <input type="date" value={f.received_at} onChange={set('received_at')} /></label>
          </div>
          <div className="grid2">
            <div className="field"><span className="lbl">Estado</span>
              <Select block value={f.status} onChange={elegirEstado}
                options={Object.entries(opts?.statuses || {}).map(([k, v]) => ({ value: k, label: v, color: COLOR[ESTADO_CLS[k]] }))} /></div>
            <label className="field"><span className="lbl">Fecha de cierre {f.status !== 'cerrada' && <span className="hint">(al cerrarla)</span>}</span>
              <input type="date" value={f.status === 'cerrada' ? f.closed_at || '' : ''} onChange={set('closed_at')} disabled={f.status !== 'cerrada'} /></label>
          </div>
          <div className="grid2">
            <div className="field"><span className="lbl">Urgencia</span>
              <Select block value={f.urgency || ''} onChange={pon('urgency')}
                options={[{ value: '', label: 'No disponible', color: COLOR.none }, ...Object.entries(opts?.urgencies || {}).map(([k, v]) => ({ value: k, label: v, color: COLOR[URG_CLS[k]] }))]} /></div>
            <div className="field"><span className="lbl">Categoría</span>
              <Select block value={f.category || ''} onChange={pon('category')} placeholder="Sin categoría"
                options={[{ value: '', label: 'Sin categoría' }, ...[...new Set([...(opts?.categories || []), ...(f.category ? [f.category] : [])])].map((c) => ({ value: c, label: c }))]} /></div>
          </div>
          <div className="grid2">
            <div className="field"><span className="lbl">Responsable</span>
              <Select block value={f.responsible_id ? Number(f.responsible_id) : ''} onChange={pon('responsible_id')}
                options={[{ value: '', label: 'Sin responsable' }, ...(opts?.agents || []).map((a) => ({ value: a.id, label: a.name }))]} /></div>
            <label className="field"><span className="lbl">Ticket de soporte <span className="hint">(opcional)</span></span>
              <input className="mono" value={f.ticket_code || ''} onChange={set('ticket_code')} placeholder="TK-2610-0001" /></label>
          </div>
          <label className="field"><span className="lbl">Descripción</span>
            <textarea rows={3} value={f.description || ''} onChange={set('description')} placeholder="Qué le pasa al cliente, cómo llegó…" /></label>
          {!f.id && (
            <div className="field"><span className="lbl">Etiquetas y antenas afectadas <span className="hint">(se buscan en los servidores ZKONG; también se pueden añadir después)</span></span>
              <Comprobador contexto={{ client_name: f.client_name, project_name: f.project_name }} value={comp} onChange={setComp} /></div>
          )}
        </div>
        <div className="modal-foot">
          <button className="btn ghost" onClick={onClose}>Cancelar</button>
          <button className="btn" disabled={saving} onClick={guardar}>{saving ? (comp.text.trim() ? 'Creando y comprobando etiquetas…' : 'Guardando…') : (f.id ? 'Guardar' : 'Crear garantía')}</button>
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
              <th>Cliente</th><th>Proyecto</th><th>Etiquetas</th><th>Categoría</th><th>Urgencia</th><th>Responsable</th><th>Estado</th>
            </tr></thead>
            <tbody>
              {data === null
                ? Array.from({ length: 6 }, (_, i) => <tr key={i}>{Array.from({ length: 9 }, (_, j) => <td key={j}><span className="dev-skel" /></td>)}</tr>)
                : filas.map((w) => (
                  <tr key={w.id} className="wr-row" onClick={() => onOpen(w.id)}>
                    <td><span className="wr-code">{w.code}</span>{w.ticket_code ? <span className="wr-tk" title={`Ticket ${w.ticket_code}`}><Icon.ticket /></span> : null}</td>
                    <td>{fecha(w.received_at)}</td>
                    <td className="wr-ell">{w.client_name || <span className="dev-mute">—</span>}</td>
                    <td className="wr-ell">{w.project_name || <span className="dev-mute">—</span>}</td>
                    <td>{w.labels_count ? <b>{num(w.labels_count)}</b> : <span className="dev-mute">—</span>}</td>
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
/* --- Etiquetas (productos) ---------------------------------------------
 * Solo se piden los códigos: cada uno se busca en todos los servidores ZKONG (dónde
 * está, si está viva, qué modelo dice el servidor) y de ese modelo sale el producto
 * del portal. Lo raro se avisa; estar ya en otra garantía abierta la deja fuera. */
let catalogo = null   // referencias AEME para elegir el modelo a mano; se pide una vez
const cargarCatalogo = () => (catalogo ||= api.warrantyCatalog().then((r) => (r.ok ? r.models : [])))

const estadoEtiqueta = (r) => r.blocked || r.warnings?.some((a) => a.blocks) ? 'bad'
  : r.lookup_state === 'not_found' ? 'bad'
  : r.lookup_state !== 'found' || !(r.aeme_ref || r.server_model) || r.warnings?.some((a) => a.tone !== 'info') ? 'warn' : 'ok'
// Lo que es: producto del portal o, si no está en el catálogo, el modelo de ZKONG.
const productoDe = (r) => r.aeme_ref || r.server_model || 'Sin modelo'

function Avisos({ list }) {
  if (!list?.length) return null
  return (
    <ul className="wr-avisos">
      {list.map((a, i) => <li key={i} className={`t-${a.tone}`}>{a.tone === 'info' ? <Icon.clock /> : <Icon.warn />}<span>{a.text}</span></li>)}
    </ul>
  )
}

function Donde({ r }) {
  if (r.lookup_state === 'not_found') return <span className="wr-st bad">No aparece en los servidores</span>
  if (r.lookup_state !== 'found') return <span className="wr-st warn">Sin comprobar</span>
  const d = dias(r.last_commu_at)
  return (
    <div className="wr-donde">
      <span><i className={`wr-dot ${r.online ? 'on' : ''}`} /><b>{r.server}</b> · {r.store_name || '—'}</span>
      <small>{r.online ? 'Conectada' : 'Desconectada'}{r.last_commu_at ? ` · comunicó ${haceDias(d)}` : ''}</small>
    </div>
  )
}

/* El producto: referencia AEME y su nombre en el portal. Con onPick se puede elegir a mano. */
function Modelo({ r, onPick }) {
  const [editar, setEditar] = useState(false)
  const [cat, setCat] = useState(null)
  const elegir = onPick && (editar || (!r.aeme_ref && !r.server_model))
  useEffect(() => { if (elegir && !cat) cargarCatalogo().then(setCat) }, [elegir, cat])
  const abrir = () => setEditar(true)
  if (elegir) {
    if (!cat) return <span className="dev-mute">Cargando modelos…</span>
    return (
      <div className="wr-modelo-pick">
        <Select sm block searchable value={r.aeme_ref || ''} placeholder={r.kind === 'ap' ? 'Elegir antena del portal…' : 'Elegir producto del portal…'}
          options={[...cat].sort((a, b) => (a.kind === r.kind ? 0 : 1) - (b.kind === r.kind ? 0 : 1)).map((m) => ({ value: m.ref, label: m.ref, sub: [m.kind === 'ap' ? 'Antena' : null, m.product_name || m.description].filter(Boolean).join(' · ') }))}
          onChange={(v) => { setEditar(false); onPick(v, cat.find((m) => m.ref === v)) }} />
        {editar && r.aeme_ref ? <button className="icon-btn" title="Cancelar" onClick={() => setEditar(false)}>×</button> : null}
      </div>
    )
  }
  return (
    <div className="wr-modelo">
      <span><b>{r.aeme_ref || r.server_model || '—'}</b>{r.model_manual ? <em className="wr-mano-tag">a mano</em> : null}
        {onPick ? <button className="icon-btn sm" title={r.aeme_ref ? 'Cambiar producto' : 'Elegir el producto del portal'} onClick={abrir}><Icon.pencil /></button> : null}</span>
      <small>{r.aeme_ref ? r.product_name || '' : r.server_model ? 'Modelo de ZKONG · sin producto del portal' : ''}</small>
    </div>
  )
}

/* Caja para pegar códigos y comprobarlos. El estado (texto, resultados, modelos
   elegidos a mano) lo guarda quien la usa: value / onChange. */
function Comprobador({ contexto, value, onChange, autoFocus }) {
  const toast = useToast()
  const [cargando, setCargando] = useState(false)
  const v = value
  const set = (cambios) => onChange({ ...v, ...cambios })
  const lineas = v.text.split(/[\s,;]+/).filter(Boolean).length
  const comprobado = v.results && v.checked === v.text

  const comprobar = async () => {
    if (!v.text.trim()) return
    setCargando(true)
    const r = await api.checkWarrantyLabels({ ...contexto, codes: v.text })
    setCargando(false)
    if (r.ok) set({ results: r.results, invalid: r.invalid, checked: v.text }); else toast(r.error || 'No se pudo comprobar', 'err')
  }
  const elegir = (code, ref, m) => set({ manual: { ...v.manual, [code]: ref }, results: v.results.map((x) => (x.code === code ? { ...x, aeme_ref: ref, model_manual: true, product_name: m?.product_name || null, warnings: (x.warnings || []).filter((w) => w.key !== 'no_model') } : x)) })

  const res = comprobado ? v.results : []
  const cuenta = { ok: 0, warn: 0, bad: 0 }
  res.forEach((r) => { cuenta[r.already ? 'bad' : estadoEtiqueta(r)]++ })

  return (
    <div className="wr-comp">
      <textarea rows={4} className="mono" value={v.text} autoFocus={autoFocus}
        onChange={(e) => set({ text: e.target.value })}
        onKeyDown={(e) => { if (e.key === 'Enter' && (e.ctrlKey || e.metaKey)) comprobar() }}
        placeholder={'Pega los códigos, uno por línea: etiquetas (9 caracteres) o MAC de antenas\n8E9749440\nA0A3B85B5F7F'} />
      <div className="wr-comp-f">
        <span className="dev-mute">{lineas ? `${lineas} ${lineas === 1 ? 'código' : 'códigos'}` : 'Etiquetas: código de 9 caracteres · Antenas: su MAC'}{comprobado ? '' : lineas ? ' · sin comprobar' : ''}</span>
        <button type="button" className="dev-btn" disabled={cargando || !lineas || comprobado} onClick={comprobar}>
          <Icon.search /> {cargando ? 'Buscando en los servidores…' : comprobado ? 'Comprobadas' : 'Comprobar'}
        </button>
      </div>
      {comprobado && (
        <>
          <div className="wr-comp-sum">
            {cuenta.ok ? <span className="ok"><Icon.check /> {cuenta.ok} {cuenta.ok === 1 ? "correcta" : "correctas"}</span> : null}
            {cuenta.warn ? <span className="warn"><Icon.warn /> {cuenta.warn} con avisos</span> : null}
            {cuenta.bad ? <span className="bad"><Icon.warn /> {cuenta.bad} no entrarán o no aparecen</span> : null}
          </div>
          {v.invalid?.length ? <p className="wr-comp-bad">No parecen etiquetas (9 letras o números) ni MAC de antena (12): <span className="mono">{v.invalid.join(', ')}</span></p> : null}
          <ul className="wr-lbls">
            {res.map((r) => {
              const st = r.already ? 'bad' : estadoEtiqueta(r)
              return (
                <li key={r.code} className={`st-${st}`}>
                  <span className="wr-lbl-code mono">{r.code}{r.kind === 'ap' ? <em className="wr-kind">Antena</em> : null}</span>
                  <Donde r={r} />
                  {r.already ? <span className="wr-st bad">Ya está en esta garantía</span> : <Modelo r={r} onPick={r.blocked ? null : (ref, m) => elegir(r.code, ref, m)} />}
                  <div className="full"><Avisos list={r.warnings} /></div>
                </li>
              )
            })}
          </ul>
        </>
      )}
    </div>
  )
}
const COMP_VACIO = { text: '', results: null, invalid: [], manual: {}, checked: null }

function Productos({ w, items, onReload, onChanged }) {
  const toast = useToast()
  const confirm = useConfirm()
  const [panel, setPanel] = useState(!items.length)
  const [comp, setComp] = useState(COMP_VACIO)
  const [ocupado, setOcupado] = useState('')
  const [ficha, setFicha] = useState(null)
  const [filtro, setFiltro] = useState('')

  const anadir = async () => {
    setOcupado('add')
    const r = await api.addWarrantyLabels(w.id, comp.text, comp.manual)
    setOcupado('')
    if (!r.ok) { toast(r.error || 'Error', 'err'); return }
    if (r.added.length) toast(`${r.added.length} ${r.added.length === 1 ? 'etiqueta añadida' : 'etiquetas añadidas'}`)
    if (r.skipped.length) toast(`${r.skipped.length} no ${r.skipped.length === 1 ? 'entró' : 'entraron'}: ${r.skipped.slice(0, 3).map((s) => `${s.code} (${s.reason})`).join('; ')}${r.skipped.length > 3 ? '…' : ''}`, 'err')
    setComp(COMP_VACIO); setPanel(false); onReload(); onChanged()
  }
  const recomprobar = async () => {
    setOcupado('recheck')
    const r = await api.recheckWarrantyLabels(w.id)
    setOcupado('')
    if (r.ok) { toast('Etiquetas comprobadas de nuevo'); onReload() } else toast(r.error || 'Error', 'err')
  }
  const cambiarModelo = async (it, ref) => {
    const r = await api.setWarrantyLabelModel(it.id, ref)
    if (r.ok) { toast(`${it.code}: ${ref}`); onReload() } else toast(r.error || 'Error', 'err')
  }
  const quitar = async (it) => {
    if (!(await confirm({ title: 'Quitar etiqueta', message: `¿Quitar ${it.code} de ${w.code}?`, danger: true, confirmText: 'Quitar' }))) return
    const r = await api.deleteWarrantyLabel(it.id)
    if (r.ok) { onReload(); onChanged() } else toast(r.error || 'Error', 'err')
  }
  const exportar = () => {
    const esc = (v) => `"${String(v ?? '').replace(/"/g, '""')}"`
    const cab = ['Código', 'Tipo', 'Referencia AEME', 'Producto', 'Modelo en el servidor', 'Servidor', 'Tienda', 'Conectada', 'Última comunicación', 'Avisos']
    const lineas = items.map((i) => [i.code, i.kind === 'ap' ? 'Antena' : 'Etiqueta', i.aeme_ref, i.product_name, i.server_model, i.server, i.store_name, i.online == null ? '' : i.online ? 'Sí' : 'No', fechaHora(i.last_commu_at), (i.warnings || []).map((a) => a.text).join(' | ')])
    const blob = new Blob(['﻿' + [cab.map(esc).join(';'), ...lineas.map((l) => l.map(esc).join(';'))].join('\r\n')], { type: 'text/csv;charset=utf-8' })
    const a = document.createElement('a')
    a.href = URL.createObjectURL(blob); a.download = `${w.code}-etiquetas.csv`; a.click()
    setTimeout(() => URL.revokeObjectURL(a.href), 1000)
  }

  // Resumen por producto: «AMC26V ×12».
  const porProducto = {}
  items.forEach((i) => { const k = productoDe(i); porProducto[k] = (porProducto[k] || 0) + 1 })
  const conectadas = items.filter((i) => i.online).length
  const conAvisos = items.filter((i) => estadoEtiqueta(i) !== 'ok').length
  const listado = items.filter((i) => !filtro || (filtro === 'avisos' ? estadoEtiqueta(i) !== 'ok' : productoDe(i) === filtro))
  const nuevas = comp.results && comp.checked === comp.text ? comp.results.filter((r) => !r.blocked && !r.already).length : null

  return (
    <>
      <div className="wr-prod-h">
        <div>
          <b>{items.length} {items.length === 1 ? 'etiqueta' : 'etiquetas'}</b>
          {items.length ? <span className="dev-mute"> · {conectadas} conectadas{conAvisos ? ` · ${conAvisos} con avisos` : ''}</span> : null}
        </div>
        <div className="spacer" />
        {items.length ? <button className="dev-btn" disabled={!!ocupado} onClick={recomprobar}><Icon.refresh /> {ocupado === 'recheck' ? 'Comprobando…' : 'Volver a comprobar'}</button> : null}
        {items.length ? <button className="dev-btn" onClick={exportar}><Icon.download /> CSV</button> : null}
        <button className="btn" onClick={() => setPanel((p) => !p)}><Icon.plus /> Añadir etiquetas</button>
      </div>

      {panel && (
        <div className="wr-prod-add">
          <Comprobador contexto={{ id: w.id }} value={comp} onChange={setComp} autoFocus />
          <div className="wr-comp-acts">
            <button className="btn ghost" onClick={() => { setPanel(false); setComp(COMP_VACIO) }}>Cancelar</button>
            <button className="btn" disabled={!comp.text.trim() || ocupado === 'add' || nuevas === 0} onClick={anadir}>
              {ocupado === 'add' ? 'Añadiendo…' : nuevas != null ? `Añadir ${nuevas} ${nuevas === 1 ? 'etiqueta' : 'etiquetas'}` : 'Comprobar y añadir'}
            </button>
          </div>
        </div>
      )}

      {items.length > 0 && (
        <>
          <div className="wr-chips sm">
            <button className={!filtro ? 'on' : ''} onClick={() => setFiltro('')}>Todas ({items.length})</button>
            {conAvisos ? <button className={filtro === 'avisos' ? 'on' : ''} onClick={() => setFiltro('avisos')}>Con avisos ({conAvisos})</button> : null}
            {Object.entries(porProducto).sort((a, b) => b[1] - a[1]).map(([k, n]) => (
              <button key={k} className={filtro === k ? 'on' : ''} onClick={() => setFiltro(k)}>{k} ×{n}</button>
            ))}
          </div>
          <ul className="wr-lbls big">
            {listado.map((i) => (
              <li key={i.id} className={`st-${estadoEtiqueta(i)}`}>
                <span className="wr-lbl-code mono"><button className="link" title="Ver ficha e historial" onClick={() => setFicha(i.code)}>{i.code}</button>{i.kind === 'ap' ? <em className="wr-kind">Antena</em> : null}</span>
                <Donde r={i} />
                <Modelo r={i} onPick={(ref) => cambiarModelo(i, ref)} />
                <button className="icon-btn" title="Quitar de la garantía" onClick={() => quitar(i)}><Icon.trash /></button>
                <div className="full"><Avisos list={i.warnings} /></div>
              </li>
            ))}
          </ul>
          {items[0]?.checked_at ? <p className="dev-mute wr-prod-foot">Comprobadas por última vez {fechaHora(items.reduce((a, i) => (i.checked_at > a ? i.checked_at : a), ''))}.</p> : null}
        </>
      )}
      {!items.length && !panel ? <p className="dev-mute">Todavía no hay etiquetas. Añádelas con su código.</p> : null}
      {ficha && <FichaDispositivo code={ficha} servers={[]} onClose={() => setFicha(null)} onOpen={setFicha} />}
    </>
  )
}

const CAMPOS = { created: 'Garantía creada', items: 'Etiquetas', item_model: 'Modelo de una etiqueta', first_contact_at: 'Primera fecha de contacto', received_at: 'Fecha de recepción', closed_at: 'Fecha de cierre', client_name: 'Cliente', project_name: 'Proyecto', category: 'Categoría', urgency: 'Urgencia', status: 'Estado', responsible_id: 'Responsable', ticket_id: 'Ticket', description: 'Descripción' }

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
    const r = await api.saveWarranty({ ...w, responsible_id: w.responsible_id || '', urgency: w.urgency || '', ticket_code: w.ticket_code || '', first_contact_at: w.first_contact_at || '', closed_at: (w.closed_at || '').slice(0, 10), ...cambios })
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
        {[['resumen', 'Resumen', Icon.doc], ['productos', `Productos${d.items.length ? ` (${d.items.length})` : ''}`, Icon.tag], ['seguimiento', `Seguimiento${d.notes.length ? ` (${d.notes.length})` : ''}`, Icon.chat], ['historial', 'Historial', Icon.clock]].map(([k, l, Ic]) => (
          <button key={k} className={tab === k ? 'on' : ''} onClick={() => setTab(k)}><Ic /> {l}</button>
        ))}
        {[['Documentos', Icon.file]].map(([l, Ic]) => (
          <button key={l} disabled title="Próximamente"><Ic /> {l} <em>Pronto</em></button>
        ))}
      </div>

      <div className="wr-panel">
        {tab === 'resumen' && (
          <dl className="wr-dl">
            <div><dt>Nº expediente</dt><dd><b>{w.code}</b></dd></div>
            <div><dt>Primera fecha de contacto</dt><dd>{fecha(w.first_contact_at)}</dd></div>
            <div><dt>Fecha de recepción</dt><dd>{fecha(w.received_at)}</dd></div>
            <div><dt>Cliente</dt><dd>{w.client_name || '—'}</dd></div>
            <div><dt>Proyecto</dt><dd>{w.project_name || '—'}</dd></div>
            <div><dt>Categoría</dt><dd>{w.category || '—'}</dd></div>
            <div><dt>Urgencia</dt><dd><UrgPill u={w.urgency} opts={opts} /></dd></div>
            <div><dt>Estado</dt><dd><EstadoPill s={w.status} opts={opts} /></dd></div>
            <div><dt>Responsable</dt><dd>{w.responsible_name || '—'}</dd></div>
            <div><dt>Ticket de soporte</dt><dd>{w.ticket_code ? <>{ticket}{w.ticket_subject ? <span className="dev-mute"> · {w.ticket_subject}</span> : null}</> : '—'}</dd></div>
            <div><dt>Creada</dt><dd>{fechaHora(w.created_at)}{w.created_by_name ? <span className="dev-mute"> · {w.created_by_name}</span> : null}</dd></div>
            {w.closed_at ? <div><dt>Fecha de cierre</dt><dd>{fecha(w.closed_at)}</dd></div> : null}
            <div className="full"><dt>Descripción</dt><dd className="wr-desc">{w.description || <span className="dev-mute">Sin descripción</span>}</dd></div>
          </dl>
        )}

        {tab === 'productos' && <Productos w={w} items={d.items} onReload={cargar} onChanged={onChanged} />}

        {tab === 'seguimiento' && (
          <>
            <div className="wr-compose">
              <div className="wr-compose-type">
                <Select sm value={nota.type} onChange={(v) => setNota((s) => ({ ...s, type: v }))}
                  options={Object.entries(opts?.note_types || {}).map(([k, v]) => ({ value: k, label: v }))} />
              </div>
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
