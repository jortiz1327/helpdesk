import { useState, useEffect, useCallback, useRef } from 'react'
import { createPortal } from 'react-dom'
import { api } from '../api.js'
import { csvCell } from '../csv.js'
import { Icon } from '../icons.jsx'
import { useToast, useConfirm } from '../App.jsx'
import Select from './Select.jsx'
import { FichaDispositivo } from './Devices.jsx'
import WarrantyDocuments from './WarrantyDocuments.jsx'

/* -------------------------------------------------------------------------
 * GARANTÍAS (Gestión de garantías). Expedientes G-AAAA-NNNN.
 *
 * Lista con filtros rápidos (mis garantías, abiertas, urgentes, pendientes…) y ficha
 * con tres pestañas: Resumen, Seguimiento (notas del equipo) e Historial (cada cambio
 * de campo, automático). Más adelante se abrirán desde un ticket de soporte y tendrán
 * Productos, ZKONG y Documentos.
 * ---------------------------------------------------------------------- */

// Los estados (nombre, color, si cuentan como cerrada) vienen de la configuración: opts.status_meta.
const cerradaEn = (opts, s) => !!opts?.status_meta?.[s]?.closed
const URG_CLS = { baja: 'gray', media: 'amber', alta: 'orange', muy_urgente: 'red' }
// Punto de color de cada opción en los desplegables (mismos tonos que las píldoras).
const COLOR = { gray: '#94a3b8', blue: '#3b82f6', amber: '#f59e0b', purple: '#8b5cf6', orange: '#f97316', indigo: '#6366f1', green: '#22c55e', red: '#ef4444', none: '#cbd5e1' }
const CHIPS = [
  ['mine', 'Mis garantías'], ['open', 'Abiertas'], ['alerts', 'Con alertas'], ['critical', 'Críticas'], ['urgent', 'Urgentes'], ['pte_cliente', 'Pte. cliente'],
  ['pte_zkong', 'Pte. ZKONG'], ['pte_envio', 'Pte. envío'], ['stale', 'Sin actualizar'], ['no_owner', 'Sin responsable'],
  ['unreviewed', 'Con dispositivos sin revisar'], ['no_client', 'Sin cliente'], ['no_project', 'Sin proyecto'], ['no_category', 'Sin categoría'], ['closed', 'Cerradas'],
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
function EstadoPill({ s, opts }) {
  return <span className="wr-pill" style={{ '--pc': opts?.status_meta?.[s]?.color || '#64748b' }}><i />{opts?.statuses?.[s] || s}</span>
}
/* Alertas de una garantía (reglas de la configuración): punto rojo si hay alguna crítica. */
function Alertas({ list, full }) {
  if (!list?.length) return null
  const crit = list.some((a) => a.level === 'crit')
  if (!full) return <span className={`wr-alert ${crit ? 'crit' : 'warn'}`} title={list.map((a) => a.text).join(' · ')}><Icon.warn /> {list.length}</span>
  return list.map((a) => <span key={a.key} className={`wr-flag ${a.level}`}><Icon.warn /> {a.text}</span>)
}
function UrgPill({ u, opts }) { return u ? <Pill cls={URG_CLS[u] || 'gray'}>{opts?.urgencies?.[u] || u}</Pill> : <Pill cls="none">No disponible</Pill> }

/* --- Alta y edición --- */
function Formulario({ inicial, opts, onClose, onSaved }) {
  const toast = useToast()
  const [f, setF] = useState(() => ({ ...inicial, closed_at: (inicial.closed_at || '').slice(0, 10) }))
  const [saving, setSaving] = useState(false)
  const set = (k) => (e) => setF((s) => ({ ...s, [k]: e.target.value }))
  const pon = (k) => (v) => setF((s) => ({ ...s, [k]: v }))
  // Al pasar a «Cerrada» la fecha de cierre propone hoy (se puede cambiar).
  const elegirEstado = (v) => setF((s) => ({ ...s, status: v, closed_at: cerradaEn(opts, v) ? s.closed_at || hoy() : s.closed_at }))
  const cerrado = cerradaEn(opts, f.status)

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
  // Alta en dos pasos: primero los datos y después las etiquetas (pantalla propia, sin scroll).
  const [paso, setPaso] = useState('datos')
  const enEtiquetas = !f.id && paso === 'etiquetas'
  const siguiente = () => { if (!f.received_at) { toast('Indica la fecha de recepción', 'err'); return } setPaso('etiquetas') }
  const bloqueoEtiquetas = f.id || !comp.text.trim() ? null
    : comp.checked !== comp.text || !comp.results ? 'Comprueba las etiquetas antes de crear la garantía'
    : comp.invalid?.length ? 'Quita los códigos que no son etiquetas ni antenas'
    : comp.results.some((r) => r.lookup_state !== 'found') ? 'Hay etiquetas que no aparecen en los servidores: corrígelas, quítalas o vuelve a comprobar'
    : comp.results.some((r) => r.blocked) ? 'Hay etiquetas que no pueden entrar: quítalas de la lista'
    : null

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
      <div className={`modal ${enEtiquetas ? 'wr-alta-lbls' : ''}`} style={{ maxWidth: f.id ? 640 : enEtiquetas ? 1080 : 720 }}>
        <div className="modal-head">
          <h3>{f.id ? `Editar ${f.code}` : inicial.ticket_code ? `Nueva garantía desde ${inicial.ticket_code}` : 'Nueva garantía'}</h3>
          {!f.id && (
            <div className="wr-pasos">
              <button className={paso === 'datos' ? 'on' : 'done'} onClick={() => setPaso('datos')}><b>{paso === 'datos' ? '1' : <Icon.check />}</b> Datos</button>
              <i />
              <button className={paso === 'etiquetas' ? 'on' : ''} onClick={siguiente}><b>2</b> Etiquetas</button>
            </div>
          )}
          <button className="x" onClick={onClose}>×</button>
        </div>
        {enEtiquetas ? (
          <div className="modal-body wr-alta-body">
            <Comprobador split contexto={{ client_name: f.client_name, project_name: f.project_name }} value={comp} onChange={setComp} autoFocus />
          </div>
        ) : (
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
                options={Object.entries(opts?.status_meta || {}).filter(([k, m]) => m.active || k === inicial.status).map(([k, m]) => ({ value: k, label: m.label, color: m.color }))} /></div>
            <label className="field"><span className="lbl">Fecha de cierre {!cerrado && <span className="hint">(al cerrarla)</span>}</span>
              <input type="date" value={cerrado ? f.closed_at || '' : ''} onChange={set('closed_at')} disabled={!cerrado} /></label>
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
        </div>
        )}
        <div className="modal-foot">
          {enEtiquetas && bloqueoEtiquetas ? <span className="wr-foot-why"><Icon.warn /> {bloqueoEtiquetas}</span> : null}
          {enEtiquetas && !comp.text.trim() ? <span className="wr-foot-why info">Puedes crearla sin etiquetas y añadirlas después.</span> : null}
          {enEtiquetas ? <button className="btn ghost" onClick={() => setPaso('datos')}>Atrás</button> : <button className="btn ghost" onClick={onClose}>Cancelar</button>}
          {!f.id && !enEtiquetas
            ? <button className="btn" onClick={siguiente}>Siguiente <Icon.arrowRight /></button>
            : <button className="btn" disabled={saving || !!bloqueoEtiquetas} onClick={guardar}>{saving ? (comp.text.trim() ? 'Creando y comprobando etiquetas…' : 'Guardando…') : (f.id ? 'Guardar' : 'Crear garantía')}</button>}
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
// Pestañas (con contador) y el resto de filtros rápidos, que van en «Más filtros».
const PESTANAS = [['open', 'Abiertas'], ['mine', 'Mías'], ['pte_zkong', 'Pte. ZKONG'], ['closed', 'Cerradas'], ['', 'Todas']]
const MAS_FILTROS = CHIPS.filter(([k]) => !PESTANAS.some(([p]) => p === k))
const COB_CORTO = { garantia: 'Garantía', cliente: 'Cliente', pilas: 'Pilas' }
const iniciales = (n) => String(n || '').trim().split(/\s+/).slice(0, 2).map((p) => p[0]).join('').toUpperCase()
// «hace 20 min», «hace 3 h», «ayer», «hace 4 días», y la fecha a partir de un mes.
const relativo = (v) => {
  const d = aFecha(v)
  if (!d || isNaN(d)) return '—'
  const min = Math.floor((Date.now() - d) / 60000)
  if (min < 1) return 'ahora'
  if (min < 60) return `hace ${min} min`
  if (min < 1440) return `hace ${Math.floor(min / 60)} h`
  const n = Math.floor(min / 1440)
  return n === 1 ? 'ayer' : n < 30 ? `hace ${n} días` : fecha(v)
}

function Lista({ opts, onOpen, onNew, reload, filtro }) {
  const toast = useToast()
  const confirm = useConfirm()
  const [chip, setChip] = useState(filtro ? filtro.chip || '' : 'open')
  // Responsable (al llegar desde Equipo viene puesto): '' todos, 'none' sin responsable o su id.
  const [resp, setResp] = useState(filtro?.responsible ? String(filtro.responsible) : '')
  useEffect(() => { if (filtro) { setChip(filtro.chip || ''); setResp(filtro.responsible ? String(filtro.responsible) : ''); setPage(0) } }, [filtro])
  const [cat, setCat] = useState('')
  const [qLive, setQLive] = useState('')
  const [q, setQ] = useState('')
  const [sort, setSort] = useState('')
  const [page, setPage] = useState(0)
  const [data, setData] = useState(null)
  const [loading, setLoading] = useState(true)
  const [sel, setSel] = useState(() => new Set())
  const [otra, setOtra] = useState(0)   // recarga tras un cambio en lote
  const [hueco, setHueco] = useState(null)   // acciones en la cabecera de la página
  useEffect(() => { setHueco(document.getElementById('wr-head-acts')) }, [])
  const pedida = useRef(0)

  useEffect(() => { const t = setTimeout(() => { setQ(qLive.trim()); setPage(0) }, 300); return () => clearTimeout(t) }, [qLive])
  useEffect(() => { setSel(new Set()) }, [chip, q, resp, cat])

  useEffect(() => {
    const n = ++pedida.current
    setLoading(true)
    api.warranties({ chip, q, sort, page, size: SIZE, ...(resp ? { responsible: resp } : {}), ...(cat ? { category: cat } : {}) }).then((r) => {
      if (n !== pedida.current) return
      setLoading(false)
      setData(r.ok ? r : { rows: [], total: 0, all: 0, counts: {}, error: r.error })
    })
  }, [chip, q, sort, page, reload, resp, cat, otra])

  const total = data?.total || 0
  const paginas = Math.ceil(total / SIZE)
  const filas = data?.rows || []
  const counts = data?.counts || {}
  const filtrando = !!(q || resp || cat || MAS_FILTROS.some(([k]) => k === chip))
  const todasMarcadas = filas.length > 0 && filas.every((w) => sel.has(w.id))
  const marcar = (id) => setSel((s) => { const n = new Set(s); n.has(id) ? n.delete(id) : n.add(id); return n })
  const marcarTodas = () => setSel(todasMarcadas ? new Set() : new Set(filas.map((w) => w.id)))
  const elegidas = filas.filter((w) => sel.has(w.id))

  const exportar = (lista) => {
    const esc = csvCell
    const cab = ['Expediente', 'Recepción', 'Cliente', 'Proyecto', 'Etiquetas', 'Categoría', 'Urgencia', 'Responsable', 'Estado', 'Quién paga', 'Ticket', 'Última actividad']
    const lineas = lista.map((w) => [w.code, fecha(w.received_at), w.client_name, w.project_name, w.labels_count, w.category, opts?.urgencies?.[w.urgency] || 'No disponible',
      w.responsible_name, opts?.statuses?.[w.status], (w.coverages || []).map((c) => COB_CORTO[c] || c).join(' + '), w.ticket_code, fechaHora(w.last_activity_at)])
    const blob = new Blob(['﻿' + [cab.map(esc).join(';'), ...lineas.map((l) => l.map(esc).join(';'))].join('\r\n')], { type: 'text/csv;charset=utf-8' })
    const a = document.createElement('a')
    a.href = URL.createObjectURL(blob)
    a.download = `garantias-${hoy()}.csv`
    a.click()
    setTimeout(() => URL.revokeObjectURL(a.href), 1000)
  }

  const enLote = async (cambios, texto) => {
    const n = sel.size
    if (!(await confirm({ title: `${texto} en ${n} ${n === 1 ? 'garantía' : 'garantías'}`, message: 'Cada cambio queda en el historial de su garantía.', confirmText: 'Aplicar' }))) return
    const r = await api.bulkWarranties([...sel], cambios)
    if (!r.ok) { toast(r.error || 'No se pudo cambiar', 'err'); return }
    toast(r.changed ? `${r.changed} ${r.changed === 1 ? 'garantía cambiada' : 'garantías cambiadas'}` : 'Ya estaban así: no ha cambiado nada')
    setSel(new Set())
    setOtra((x) => x + 1)
  }

  const estadosActivos = Object.entries(opts?.status_meta || {}).filter(([, m]) => m.active).map(([k, m]) => ({ value: k, label: m.label, color: m.color }))
  const agentes = (opts?.agents || []).map((a) => ({ value: String(a.id), label: a.name }))

  return (
    <div className={`dev-card wl-card ${loading ? 'busy' : ''}`}>
      {loading && <div className="dev-progress" />}
      <div className="wi-bar">
        <div className="dev-search wl-search">
          <Icon.search />
          <input value={qLive} onChange={(e) => setQLive(e.target.value)} placeholder="Buscar expediente, cliente, proyecto o ticket…" />
          {qLive ? <button className="dev-x" onClick={() => setQLive('')} title="Borrar">×</button> : null}
        </div>
        <div className="dc-seg wi-tabs">
          {PESTANAS.map(([k, l]) => (
            <button key={k || 'all'} className={chip === k ? 'on' : ''} onClick={() => { setChip(k); setPage(0) }}>
              {l}<span className="wi-n">{counts[k || 'all'] ?? '·'}</span>
            </button>
          ))}
        </div>
        <Select value={resp} onChange={(v) => { setResp(v); setPage(0) }} searchable
          options={[{ value: '', label: 'Responsable: todos' }, { value: 'none', label: 'Sin responsable' }, ...agentes]} />
        <Select value={cat} onChange={(v) => { setCat(v); setPage(0) }}
          options={[{ value: '', label: 'Categoría: todas' }, { value: 'none', label: 'Sin categoría' }, ...(opts?.categories || []).map((c) => ({ value: c, label: c }))]} />
        <Select value={MAS_FILTROS.some(([k]) => k === chip) ? chip : ''} onChange={(v) => { setChip(v || 'open'); setPage(0) }}
          options={[{ value: '', label: 'Más filtros' }, ...MAS_FILTROS.map(([k, l]) => ({ value: k, label: l }))]} />
        {filtrando ? <button className="dev-clear" onClick={() => { setQLive(''); setResp(''); setCat(''); setChip('open'); setPage(0) }}><Icon.x /> Limpiar</button> : null}
      </div>
      {hueco && createPortal(<>
        <button className="dev-btn" disabled={!filas.length} onClick={() => exportar(filas)} title="Exportar a CSV lo que se ve"><Icon.download /> Exportar</button>
        <button className="btn" onClick={onNew}><Icon.plus /> Nueva garantía</button>
      </>, hueco)}

      {sel.size > 0 && (
        <div className="wl-bulk">
          <b>{sel.size} {sel.size === 1 ? 'seleccionada' : 'seleccionadas'}</b>
          <span className="sep" />
          <Select sm value="" onChange={(v) => enLote({ status: v }, `Poner «${opts?.statuses?.[v] || v}»`)} options={[{ value: '', label: 'Cambiar estado…' }, ...estadosActivos]} />
          <Select sm searchable value="" onChange={(v) => enLote({ responsible_id: v }, v === 'none' ? 'Quitar el responsable' : `Asignar a ${agentes.find((a) => a.value === v)?.label}`)}
            options={[{ value: '', label: 'Asignar a…' }, { value: 'none', label: 'Sin responsable' }, ...agentes]} />
          <button className="dev-btn" onClick={() => exportar(elegidas)}><Icon.download /> Exportar selección</button>
          <span className="spacer" />
          <button className="icon-btn" title="Quitar la selección" onClick={() => setSel(new Set())}><Icon.x /></button>
        </div>
      )}

      <div className="dev-table-wrap keep">
        <table className="dev-table wr-table wl-table">
          <thead><tr>
            <th className="wl-ck"><input type="checkbox" checked={todasMarcadas} onChange={marcarTodas} title="Seleccionar las de esta página" /></th>
            <th>Expediente</th>
            <th>Cliente y proyecto</th>
            <th>Ticket</th>
            <th className="r">Etiquetas</th>
            <th>Responsable</th>
            <th>Estado</th>
            <th>Urgencia</th>
            <th>Quién paga</th>
            <th><button className="wr-sort" onClick={() => setSort(sort === 'activity' ? '' : 'activity')}>Última actividad <Icon.chevron style={{ transform: sort === 'activity' ? 'none' : 'rotate(-90deg)', opacity: sort === 'activity' ? 1 : 0.4 }} /></button></th>
          </tr></thead>
          <tbody>
            {data === null
              ? Array.from({ length: 6 }, (_, i) => <tr key={i}>{Array.from({ length: 10 }, (_, j) => <td key={j}><span className="dev-skel" /></td>)}</tr>)
              : filas.map((w) => (
                <tr key={w.id} className={`wr-row ${sel.has(w.id) ? 'wl-on' : ''}`} onClick={() => onOpen(w.id)}>
                  <td className="wl-ck" onClick={(e) => e.stopPropagation()}><input type="checkbox" checked={sel.has(w.id)} onChange={() => marcar(w.id)} /></td>
                  <td><span className="wr-code">{w.code}</span><Alertas list={w.alerts} /></td>
                  <td className="wl-cli">
                    <b>{w.client_name || <span className="dev-mute">Sin cliente</span>}</b>
                    <span>{[w.project_name, w.category].filter(Boolean).join(' · ') || '—'}</span>
                  </td>
                  <td>{w.ticket_code ? <span className="wl-tk">{w.ticket_code}</span> : <span className="dev-mute">—</span>}</td>
                  <td className="r">{w.labels_count ? <b>{num(w.labels_count)}</b> : <span className="dev-mute">—</span>}</td>
                  <td>{w.responsible_name
                    ? <span className="wl-resp"><i>{iniciales(w.responsible_name)}</i>{w.responsible_name}</span>
                    : <span className="dev-mute">Sin asignar</span>}</td>
                  <td><EstadoPill s={w.status} opts={opts} /></td>
                  <td><UrgPill u={w.urgency} opts={opts} /></td>
                  <td>{w.coverages?.length
                    ? <span className="wl-cob">{w.coverages.map((c) => <em key={c} className={`c-${c}`}>{COB_CORTO[c] || c}</em>)}</span>
                    : <span className="dev-mute" title="Se decide al revisar las etiquetas">—</span>}</td>
                  <td className="wl-act" title={fechaHora(w.last_activity_at)}>{relativo(w.last_activity_at)}</td>
                </tr>
              ))}
          </tbody>
        </table>
        {data && !filas.length && (
          <div className="act-empty"><Icon.shield style={{ width: 30, height: 30, fill: 'var(--ink-3)' }} />
            <p>{data.all ? 'Ninguna garantía coincide con el filtro.' : 'Aún no hay garantías. Crea la primera con «Nueva garantía».'}</p></div>
        )}
      </div>
      {total > 0 && (
        <div className="dev-foot">
          <span className="dev-mute">Mostrando <b>{num(page * SIZE + 1)}–{num(Math.min(total, (page + 1) * SIZE))}</b> de <b>{num(total)}</b>{data?.all !== total ? <> · {num(data.all)} en total</> : null}</span>
          <span className="spacer" />
          {paginas > 1 && (
            <div className="dev-pages">
              <button disabled={page === 0 || loading} onClick={() => setPage(page - 1)}><Icon.chevron style={{ transform: 'rotate(90deg)' }} /> <span>Anterior</span></button>
              <span className="dev-mute">{page + 1} / {paginas}</span>
              <button disabled={page + 1 >= paginas || loading} onClick={() => setPage(page + 1)}><span>Siguiente</span> <Icon.chevron style={{ transform: 'rotate(-90deg)' }} /></button>
            </div>
          )}
        </div>
      )}
    </div>
  )
}

/* --- Ficha --- */
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

/*
 * Avisos repetidos en varias etiquetas (misma tienda de otro cliente, sin comunicar…):
 * se dicen una vez arriba con cuántas son y salen de sus filas. Se agrupa por texto
 * idéntico; «sin comunicar» lleva la fecha de cada una, así que se junta por tipo.
 */
function agruparAvisos(res) {
  const porTexto = new Map()
  const silencio = []
  res.forEach((r) => (r.warnings || []).forEach((a) => {
    if (a.blocks) return   // lo que bloquea se queda en su fila, a la vista
    if (a.key === 'silent') { silencio.push(r); return }
    const k = `${a.key}|${a.text}`
    porTexto.set(k, [...(porTexto.get(k) || []), r.code])
  }))
  const grupos = []
  const dentro = new Set()
  porTexto.forEach((codes, k) => {
    if (codes.length < 2) return
    const [key, text] = [k.slice(0, k.indexOf('|')), k.slice(k.indexOf('|') + 1)]
    const tone = res.find((r) => r.code === codes[0]).warnings.find((a) => a.key === key && a.text === text).tone
    grupos.push({ id: k, n: codes.length, tone, text: text.replace(/^En los servidores está/, 'Están') })
    codes.forEach((c) => dentro.add(`${c}|${k}`))
  })
  if (silencio.length >= 2) {
    const fechas = silencio.map((r) => r.last_commu_at).filter(Boolean).sort()
    const vieja = fechas[0] ? fmtDiaCorto(fechas[0]) : null
    grupos.push({ id: 'silent', n: silencio.length, tone: 'warn', text: `Sin comunicar hace más de 30 días${vieja ? ` (la más antigua, desde el ${vieja})` : ''}.` })
    silencio.forEach((r) => dentro.add(`${r.code}|silent`))
  }
  grupos.sort((a, b) => b.n - a.n)
  const agrupado = (code, a) => dentro.has(a.key === 'silent' ? `${code}|silent` : `${code}|${a.key}|${a.text}`)
  return { grupos, agrupado }
}
const fmtDiaCorto = (s) => { const [y, m, d] = String(s).slice(0, 10).split('-'); return d && m && y ? `${d}/${m}/${y}` : s }

/* Caja para pegar códigos y comprobarlos. El estado (texto, resultados, modelos
   elegidos a mano) lo guarda quien la usa: value / onChange. */
function Comprobador({ contexto, value, onChange, autoFocus, split }) {
  const toast = useToast()
  const [cargando, setCargando] = useState(false)
  const [filtro, setFiltro] = useState('')   // en dos columnas: todas | bad | warn | ok
  const v = value
  const set = (cambios) => onChange({ ...v, ...cambios })
  const lineas = v.text.split(/[\s,;]+/).filter(Boolean).length
  const comprobado = v.results && v.checked === v.text
  const hayFallos = comprobado && v.results.some((r) => r.lookup_state !== 'found')

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

  // Quita códigos de la lista sin tener que volver a comprobar el resto.
  const norm = (t) => t.replace(/[^A-Za-z0-9]/g, '').toUpperCase()
  const quitar = (codes) => {
    const fuera = new Set(codes)
    const texto = v.text.split(/\r?\n/).map((l) => l.split(/[\s,;]+/).filter((t) => t && !fuera.has(norm(t))).join(' ')).filter((l) => l.trim()).join('\n')
    set({ text: texto, checked: comprobado ? texto : v.checked, results: v.results ? v.results.filter((r) => !fuera.has(r.code)) : v.results, invalid: (v.invalid || []).filter((c) => !fuera.has(c)) })
  }
  const estado = (r) => (r.already ? 'bad' : estadoEtiqueta(r))
  const ORDEN = { bad: 0, warn: 1, ok: 2 }
  const malas = [...res.filter((r) => estado(r) === 'bad').map((r) => r.code), ...(comprobado ? v.invalid || [] : [])]

  if (split) {
    const vista = [...res].sort((a, b) => ORDEN[estado(a)] - ORDEN[estado(b)]).filter((r) => !filtro || estado(r) === filtro)
    const cambiada = v.results && v.checked !== v.text
    const { grupos, agrupado } = agruparAvisos(res)
    return (
      <div className="wr-cs">
        <div className="wr-cs-in">
          <span className="lbl">Códigos <span className="hint">— uno por línea: etiqueta (9) o MAC de antena (12)</span></span>
          <textarea className="mono" value={v.text} autoFocus={autoFocus} onChange={(e) => set({ text: e.target.value })}
            onKeyDown={(e) => { if (e.key === 'Enter' && (e.ctrlKey || e.metaKey)) comprobar() }}
            placeholder={'806301480\n806301834\nA0A3B85B5F7F'} />
          {comprobado && v.invalid?.length ? <p className="wr-comp-bad">No son etiquetas ni antenas: <span className="mono">{v.invalid.join(', ')}</span></p> : null}
          <div className="wr-cs-f">
            <span className="dev-mute">{lineas ? `${lineas} ${lineas === 1 ? 'código' : 'códigos'}` : 'Ctrl+Enter para comprobar'}</span>
            <button type="button" className={comprobado && !hayFallos ? 'btn ghost' : 'btn'} disabled={cargando || !lineas || (comprobado && !hayFallos)} onClick={comprobar}>
              {hayFallos && !cargando ? <Icon.refresh /> : <Icon.search />} {cargando ? 'Buscando…' : hayFallos ? 'Volver a comprobar' : comprobado ? 'Comprobadas' : 'Comprobar'}
            </button>
          </div>
        </div>
        <div className="wr-cs-out">
          {!comprobado ? (
            <div className="wr-cs-empty">{cargando ? <div className="spinner" /> : <Icon.search />}<span>{cargando ? 'Buscando en los servidores ZKONG…' : cambiada ? 'Has cambiado la lista: vuelve a comprobar.' : 'Pega los códigos y pulsa Comprobar. Aquí verás en qué servidor y tienda está cada uno.'}</span></div>
          ) : (
            <>
              <div className="wr-cs-bar">
                <div className="dc-seg wr-cs-tabs">
                  {[['', 'Todas', res.length], ['bad', 'No entran', cuenta.bad], ['warn', 'Con avisos', cuenta.warn], ['ok', 'Correctas', cuenta.ok]].map(([k, l, n]) => (k === '' || n) ? (
                    <button key={k} type="button" className={`${filtro === k ? 'on' : ''} t-${k || 'all'}`} onClick={() => setFiltro(k)}>{l}<span className="wi-n">{n}</span></button>
                  ) : null)}
                </div>
                <span className="spacer" />
                {malas.length ? <button type="button" className="btn ghost sm wr-cs-quitar" onClick={() => { quitar(malas); setFiltro('') }}><Icon.x /> Quitar las que no entran ({malas.length})</button> : null}
              </div>
              {grupos.length ? (
                <ul className="wr-cs-grupos">
                  {grupos.map((g) => <li key={g.id} className={`t-${g.tone}`}><Icon.warn /><b>{g.n} {g.n === 1 ? 'etiqueta' : 'etiquetas'}</b><span>{g.text}</span></li>)}
                </ul>
              ) : null}
              <ul className="wr-cs-list">
                {vista.map((r) => (
                  <li key={r.code} className={`st-${estado(r)}`}>
                    <span className="wr-lbl-code mono">{r.code}{r.kind === 'ap' ? <em className="wr-kind">Antena</em> : null}</span>
                    <Donde r={r} />
                    {r.already ? <span className="wr-st bad">Ya está en esta garantía</span> : <Modelo r={r} onPick={r.blocked ? null : (ref, m) => elegir(r.code, ref, m)} />}
                    <button type="button" className="icon-btn sm" title="Quitar de la lista" onClick={() => quitar([r.code])}><Icon.x /></button>
                    {(() => {
                      const propios = (r.warnings || []).filter((a) => !agrupado(r.code, a))
                      return propios.length ? <p className="wr-cs-av">{propios.map((a, i) => <span key={i} className={`t-${a.tone}`}>{a.text}</span>)}</p> : null
                    })()}
                  </li>
                ))}
                {!vista.length ? <li className="wr-cs-none">Ninguna en este filtro.</li> : null}
              </ul>
            </>
          )}
        </div>
      </div>
    )
  }

  return (
    <div className="wr-comp">
      <textarea rows={4} className="mono" value={v.text} autoFocus={autoFocus}
        onChange={(e) => set({ text: e.target.value })}
        onKeyDown={(e) => { if (e.key === 'Enter' && (e.ctrlKey || e.metaKey)) comprobar() }}
        placeholder={'Pega los códigos, uno por línea: etiquetas (9 caracteres) o MAC de antenas\n8E9749440\nA0A3B85B5F7F'} />
      <div className="wr-comp-f">
        <span className="dev-mute">{lineas ? `${lineas} ${lineas === 1 ? 'código' : 'códigos'}` : 'Etiquetas: código de 9 caracteres · Antenas: su MAC'}{comprobado ? '' : lineas ? ' · sin comprobar' : ''}</span>
        <button type="button" className="dev-btn" disabled={cargando || !lineas || (comprobado && !hayFallos)} onClick={comprobar}>
          {hayFallos && !cargando ? <Icon.refresh /> : <Icon.search />} {cargando ? 'Buscando en los servidores…' : hayFallos ? 'Volver a comprobar' : comprobado ? 'Comprobadas' : 'Comprobar'}
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

/* ---- Revisión de los dispositivos ----
 * Cada etiqueta o antena acaba con una solución. Las que no cubre o cubre la garantía
 * de verdad (defectuosa, dañada por el cliente) piden fotos: tres en las etiquetas
 * (frontal con el código, trasera con pilas y sin pilas), dos en las antenas. Las
 * fotos se suben, se pegan o se arrastran desde las imágenes que mandó el cliente al
 * ticket. Los textos y lo que pide cada solución vienen del servidor (opts.review). */
const RES_UI = {
  pendiente:    { c: 'gray',   ic: Icon.clock,   d: 'Todavía sin revisar' },
  correcta:     { c: 'green',  ic: Icon.check,   d: 'Funciona bien, no tenía fallo' },
  bateria:      { c: 'amber',  ic: Icon.bolt,    d: 'Eran las pilas' },
  recuperada:   { c: 'blue',   ic: Icon.refresh, d: 'Revinculada o actualizada desde el servidor' },
  reparada:     { c: 'indigo', ic: Icon.settings, d: 'Arreglada por el equipo' },
  no_reproduce: { c: 'purple', ic: Icon.eye,     d: 'Probada y el fallo no aparece' },
  defectuosa:   { c: 'red',    ic: Icon.warn,    d: 'Fallo de fábrica (pantalla rojiza…): la cubre la garantía' },
  danada:       { c: 'orange', ic: Icon.shield,  d: 'Golpes, líquidos, mal uso…: no la cubre' },
  desechada:    { c: 'gray',   ic: Icon.trash,   d: 'Se retira y no vuelve' },
}
const RAPIDAS = ['correcta', 'bateria', 'defectuosa', 'danada']   // en la barra de selección

const tiposFoto = (rv, it) => Object.entries(rv?.photo_types?.[it.kind] || rv?.photo_types?.esl || {})
const pideFotos = (rv, it) => !!rv?.resolutions?.[it.resolution]?.photos
const fotosDe = (rv, it) => { const t = tiposFoto(rv, it).map(([k]) => k); return { hay: (it.photos || []).filter((p) => t.includes(p.type)).length, total: t.length } }
const faltanFotos = (rv, it) => { if (!pideFotos(rv, it)) return false; const f = fotosDe(rv, it); return f.hay < f.total }

function ResPill({ rv, it, onClick }) {
  const ui = RES_UI[it.resolution] || RES_UI.pendiente
  const f = pideFotos(rv, it) ? fotosDe(rv, it) : null
  const Ic = ui.ic
  return (
    <button type="button" className={`wr-res c-${ui.c}`} onClick={onClick} title="Revisar">
      <Ic /> <span>{rv?.resolutions?.[it.resolution]?.label || it.resolution}</span>
      {f ? <em className={f.hay < f.total ? 'falta' : ''}><Icon.image /> {f.hay}/{f.total}</em> : null}
    </button>
  )
}

/* Lo que se sabe de la revisión, en una línea: «Pilas cambiadas», «Enviar a ZKONG: no enciende»… */
function ResDetalle({ rv, it }) {
  const partes = []
  if (it.resolution === 'bateria' && it.battery_changed) partes.push('Pilas cambiadas')
  if (it.zkong_decision) partes.push(`${rv?.zkong?.[it.zkong_decision] || it.zkong_decision}${it.zkong_issue ? `: ${it.zkong_issue}` : ''}`)
  if (it.batch_code) partes.push(`Remesa ${it.batch_code} (${rv?.batch_statuses?.[it.batch_status] || it.batch_status})${it.zkong_result ? `: ${rv?.zkong_results?.[it.zkong_result] || it.zkong_result}${it.zkong_result_note ? ` — ${it.zkong_result_note}` : ''}` : ''}`)
  if (it.resolution === 'danada' && it.broken_cases?.length) partes.push(it.broken_cases.map((k) => rv?.broken_cases?.[k] || k).join(', '))
  if (it.resolution_note) partes.push(it.resolution_note)
  if (!partes.length && !it.reviewed_by_name && !it.coverage) return null
  return <p className="wr-res-det">{it.coverage ? <span className={`wr-cov cov-${it.coverage}`}>{rv?.coverages?.[it.coverage] || it.coverage}</span> : null}{partes.join(' · ')}{it.reviewed_by_name ? <span className="dev-mute">{partes.length ? ' — ' : ''}{it.reviewed_by_name}, {fechaHora(it.reviewed_at)}</span> : null}</p>
}

/* Hueco de una foto: vacío acepta clic, arrastrar un fichero o una imagen del ticket, y
   pegar (Ctrl+V) cuando está activo; lleno enseña la miniatura con «quitar». */
function FotoSlot({ label, photo, active, busy, onActivate, onFile, onSrc, onDelete }) {
  const input = useRef(null)
  const [encima, setEncima] = useState(false)
  const soltar = (e) => {
    e.preventDefault(); setEncima(false)
    const src = e.dataTransfer.getData('text/x-wr-src')
    if (src) onSrc(src)
    else if (e.dataTransfer.files?.[0]) onFile(e.dataTransfer.files[0])
  }
  return (
    <div className={`wr-slot${photo ? ' has' : ''}${active ? ' on' : ''}${encima ? ' over' : ''}${busy ? ' busy' : ''}`}
      onClick={() => { onActivate(); if (!photo && !busy) input.current?.click() }}
      onDragOver={(e) => { e.preventDefault(); setEncima(true) }} onDragLeave={() => setEncima(false)} onDrop={soltar}>
      {photo
        ? <>
            <img src={api.warrantyPhotoUrl(photo.id)} alt={label} loading="lazy" />
            <div className="wr-slot-acts">
              <a className="icon-btn" href={api.warrantyPhotoUrl(photo.id)} target="_blank" rel="noreferrer" title="Ver en grande" onClick={(e) => e.stopPropagation()}><Icon.eye /></a>
              <button type="button" className="icon-btn" title="Cambiar" onClick={(e) => { e.stopPropagation(); input.current?.click() }}><Icon.upload /></button>
              <button type="button" className="icon-btn" title="Quitar" onClick={(e) => { e.stopPropagation(); onDelete() }}><Icon.trash /></button>
            </div>
            {photo.source ? <em className="wr-slot-src">Del ticket</em> : null}
          </>
        : <div className="wr-slot-empty">{busy ? <div className="spinner sm" /> : <Icon.upload />}<small>{busy ? 'Subiendo…' : active ? 'Suelta, pega o haz clic' : 'Añadir'}</small></div>}
      <span className="wr-slot-l">{label}</span>
      <input ref={input} type="file" accept="image/jpeg,image/png,image/webp,image/gif" hidden
        onChange={(e) => { const f = e.target.files?.[0]; e.target.value = ''; if (f) onFile(f) }} />
    </div>
  )
}

// preset: la solución elegida en la barra de selección. Un dispositivo que ya pide fotos
// abre directamente en las fotos.
function Revisar({ w, rv, diag, items: iniciales, preset, onClose, onSaved }) {
  const toast = useToast()
  const uno = iniciales.length === 1 ? iniciales[0] : null
  const [items, setItems] = useState(iniciales)
  const [paso, setPaso] = useState(!preset && uno && pideFotos(rv, uno) ? 'fotos' : 'solucion')
  const [f, setF] = useState(() => {
    const resolution = preset || (uno && uno.resolution !== 'pendiente' ? uno.resolution : '')
    return {
      resolution, coverage: (!preset && uno?.coverage) || rv.default_coverage?.[resolution] || '',
      note: uno?.resolution_note || '', broken_cases: uno?.broken_cases || [], battery_changed: !!uno?.battery_changed,
      zkong_decision: uno?.zkong_decision || 'decidir', zkong_issue: uno?.zkong_issue || '',
    }
  })
  // Quién paga se propone según la solución hasta que el técnico lo cambia a mano.
  const [covAMano, setCovAMano] = useState(!preset && !!uno?.coverage && uno.coverage !== rv.default_coverage?.[uno.resolution])
  const elegirSolucion = (k) => set({ resolution: k, ...(covAMano ? {} : { coverage: rv.default_coverage?.[k] || '' }) })
  const [guardando, setGuardando] = useState(false)
  const [cambios, setCambios] = useState(false)
  const [galeria, setGaleria] = useState(w.ticket_id ? null : [])
  const [activo, setActivo] = useState(null)       // «itemId|tipo» al que van las fotos pegadas o elegidas
  const [subiendo, setSubiendo] = useState('')
  const set = (c) => setF((s) => ({ ...s, ...c }))
  const conFotos = items.filter((it) => pideFotos(rv, it))
  const res = rv.resolutions[f.resolution]

  useEffect(() => { if (paso === 'fotos' && galeria === null) api.warrantyTicketImages(w.id, true).then((r) => setGaleria(!r.ok ? [] : r.visible === false ? false : r.images)) }, [paso, galeria, w.id])

  // Primer hueco vacío (para que pegar o elegir una imagen del ticket vaya solo a su sitio).
  const huecos = conFotos.flatMap((it) => tiposFoto(rv, it).map(([t]) => ({ key: `${it.id}|${t}`, it, t, lleno: (it.photos || []).some((p) => p.type === t) })))
  const siguiente = (desde) => { const i = huecos.findIndex((h) => h.key === desde); return [...huecos.slice(i + 1), ...huecos.slice(0, i + 1)].find((h) => !h.lleno && h.key !== desde)?.key || null }
  useEffect(() => { if (paso === 'fotos' && !activo) setActivo(huecos.find((h) => !h.lleno)?.key || null) }, [paso, activo, huecos.length])

  const tras = (it, r, key) => {
    setSubiendo('')
    if (!r.ok) { toast(r.error || 'No se pudo guardar la foto', 'err'); return }
    setCambios(true)
    setItems((xs) => xs.map((x) => (x.id === it.id ? { ...x, photos: r.photos } : x)))
    if (key) setActivo(siguiente(key))
  }
  const subir = async (it, t, file) => { const key = `${it.id}|${t}`; setSubiendo(key); tras(it, await api.uploadWarrantyPhoto(it.id, t, file), key) }
  const delTicket = async (it, t, src) => { const key = `${it.id}|${t}`; setSubiendo(key); tras(it, await api.warrantyPhotoFromTicket(it.id, t, src), key) }
  const quitar = async (it, p) => { setSubiendo(`${it.id}|${p.type}`); tras(it, await api.deleteWarrantyPhoto(p.id)); setActivo(`${it.id}|${p.type}`) }
  const elegirDelTicket = (src) => { const h = huecos.find((x) => x.key === activo); if (h) delTicket(h.it, h.t, src); else toast('Elige primero el hueco de la foto', 'err') }

  // Ctrl+V con una imagen copiada: al hueco activo.
  useEffect(() => {
    if (paso !== 'fotos') return
    const pegar = (e) => {
      const file = [...(e.clipboardData?.files || [])].find((x) => x.type.startsWith('image/'))
      const h = huecos.find((x) => x.key === activo)
      if (file && h) { e.preventDefault(); subir(h.it, h.t, file) }
    }
    window.addEventListener('paste', pegar)
    return () => window.removeEventListener('paste', pegar)
  })

  const guardar = async () => {
    if (!f.resolution) return
    setGuardando(true)
    const r = await api.reviewWarrantyItems({ id: w.id, item_ids: items.map((x) => x.id), ...f })
    setGuardando(false)
    if (!r.ok) { toast(r.error || 'No se pudo guardar', 'err'); return }
    setCambios(true)
    const nuevos = r.items.filter((x) => items.some((y) => y.id === x.id))
    setItems(nuevos)
    toast(`${nuevos.length === 1 ? nuevos[0].code : `${nuevos.length} dispositivos`}: ${res.label}`)
    if (res.photos) { setActivo(null); setPaso('fotos') } else { onSaved(); onClose() }
  }
  const cerrar = () => { if (cambios) onSaved(); onClose() }
  const pendientes = huecos.filter((h) => !h.lleno).length

  const toggleCaso = (k) => set({ broken_cases: f.broken_cases.includes(k) ? f.broken_cases.filter((x) => x !== k) : [...f.broken_cases, k] })
  const faltaAlgo = !f.resolution || !f.coverage || (f.resolution === 'danada' && !f.broken_cases.length) || (f.resolution === 'desechada' && !f.note.trim())
    || (rv.resolutions[f.resolution]?.zkong && f.zkong_decision === 'enviar' && !f.zkong_issue.trim())

  return (
    <div className="modal-bg wr-over" onClick={(e) => e.target.classList.contains('modal-bg') && cerrar()}>
      <div className={`modal wr-rev${paso === 'fotos' ? ' wide' : ''}`} onKeyDown={(e) => { if (e.key === 'Escape') cerrar() }}>
        <div className="modal-h">
          <h3>{uno ? <>Revisar <span className="mono">{uno.code}</span></> : `Revisar ${items.length} dispositivos`}</h3>
          <button className="icon-btn" onClick={cerrar} title="Cerrar (Esc)">✕</button>
        </div>
        {(res?.photos || conFotos.length > 0) && (
          <div className="wr-steps">
            <button className={paso === 'solucion' ? 'on' : ''} onClick={() => setPaso('solucion')}><b>1</b> Solución</button>
            <i />
            <button className={paso === 'fotos' ? 'on' : ''} disabled={!conFotos.length} onClick={() => setPaso('fotos')}><b>2</b> Fotos{huecos.length ? <em>{huecos.length - pendientes}/{huecos.length}</em> : null}</button>
          </div>
        )}

        {paso === 'solucion' ? (
          <div className="modal-body">
            {!uno && <p className="wr-rev-codes mono">{items.slice(0, 12).map((x) => x.code).join(' · ')}{items.length > 12 ? ` y ${items.length - 12} más` : ''}</p>}
            <div className="wr-res-grid">
              {Object.entries(rv.resolutions).filter(([k]) => k !== 'pendiente').map(([k, r]) => {
                const ui = RES_UI[k] || RES_UI.pendiente
                const Ic = ui.ic
                return (
                  <button key={k} type="button" className={`wr-res-card c-${ui.c}${f.resolution === k ? ' on' : ''}`} onClick={() => elegirSolucion(k)}>
                    <span className="ic"><Ic /></span>
                    <span className="tx">
                      <b>{r.label}</b>
                      <small>{ui.d}</small>
                      {r.photos || r.zkong ? (
                        <span className="req-l">
                          {r.photos ? <span><Icon.image /> Con fotos</span> : null}
                          {r.zkong ? <span><Icon.send /> ZKONG</span> : null}
                        </span>
                      ) : null}
                    </span>
                  </button>
                )
              })}
            </div>

            {f.resolution && (
              <div className="wr-rev-sec">
                <label>¿Quién paga? <span className="req">*</span>
                  <span className="dev-mute"> — {!f.coverage ? 'elígelo según el motivo' : f.coverage === rv.default_coverage?.[f.resolution] ? 'lo propuesto para esta solución' : 'elegido a mano'}</span></label>
                <div className="wr-seg">
                  {Object.entries(rv.coverages || {}).map(([k, l]) => (
                    <button key={k} type="button" className={f.coverage === k ? `on cov-${k}` : ''} onClick={() => { setCovAMano(k !== rv.default_coverage?.[f.resolution]); set({ coverage: k }) }}>{l}</button>
                  ))}
                </div>
              </div>
            )}
            {f.resolution === 'bateria' && (
              <label className="wr-check"><input type="checkbox" checked={f.battery_changed} onChange={(e) => set({ battery_changed: e.target.checked })} /> Se le han cambiado las pilas</label>
            )}
            {res?.zkong && (
              <div className="wr-rev-sec">
                <label>¿Se manda a ZKONG?</label>
                <div className="wr-seg">
                  {Object.entries(rv.zkong).map(([k, l]) => <button key={k} type="button" className={f.zkong_decision === k ? 'on' : ''} onClick={() => set({ zkong_decision: k })}>{l}</button>)}
                </div>
                <label>Fallo {f.zkong_decision === 'enviar' ? <span className="req">*</span> : null} <span className="dev-mute">— va en el RMA de ZKONG («Issue Description»)</span></label>
                <input value={f.zkong_issue} maxLength={500} onChange={(e) => set({ zkong_issue: e.target.value })} placeholder="No enciende, pantalla rota de fábrica, no vincula…" />
              </div>
            )}
            {f.resolution === 'danada' && (
              <div className="wr-rev-sec">
                <label>Por qué no la cubre la garantía <span className="req">*</span></label>
                <div className="wr-casos">
                  {Object.entries(rv.broken_cases).map(([k, l]) => <button key={k} type="button" className={f.broken_cases.includes(k) ? 'on' : ''} onClick={() => toggleCaso(k)}>{f.broken_cases.includes(k) ? <Icon.check /> : null}{l}</button>)}
                </div>
              </div>
            )}
            {f.resolution && (
              <div className="wr-rev-sec">
                <div className="wr-rev-lbl">
                  <label>{f.resolution === 'desechada' ? <>Por qué se desecha <span className="req">*</span></> : 'Comentario'}</label>
                  {diag?.length ? (
                    <Select sm value="" placeholder="Usar plantilla de diagnóstico…" onChange={(id) => {
                      const t = diag.find((x) => x.id === id)
                      if (t) set({ note: f.note.trim() ? `${f.note.trim()}\n${t.body}` : t.body })
                    }} options={diag.map((t) => ({ value: t.id, label: t.title }))} />
                  ) : null}
                </div>
                <textarea rows={2} value={f.note} onChange={(e) => set({ note: e.target.value })} placeholder="Opcional: lo que se ha visto o hecho"
                  onKeyDown={(e) => { if (e.key === 'Enter' && (e.ctrlKey || e.metaKey) && !faltaAlgo) guardar() }} />
              </div>
            )}
          </div>
        ) : (
          <div className="modal-body wr-fotos">
            <div className="wr-fotos-l">
              <p className="dev-mute wr-fotos-tip">{w.ticket_id ? 'Elige un hueco y haz clic en una imagen del cliente, arrástrala, pega con Ctrl+V o sube un fichero.' : 'Haz clic en un hueco para subir la foto, arrástrala o pégala con Ctrl+V.'}</p>
              {conFotos.map((it) => (
                <div key={it.id} className="wr-fotos-it">
                  <div className="wr-fotos-ith"><b className="mono">{it.code}</b>{it.kind === 'ap' ? <em className="wr-kind">Antena</em> : null}<span className="dev-mute">{productoDe(it)}</span>
                    <ResPill rv={rv} it={it} onClick={() => setPaso('solucion')} /></div>
                  <div className="wr-slots">
                    {tiposFoto(rv, it).map(([t, l]) => {
                      const key = `${it.id}|${t}`
                      return <FotoSlot key={t} label={l} photo={(it.photos || []).find((p) => p.type === t)} active={activo === key} busy={subiendo === key}
                        onActivate={() => setActivo(key)} onFile={(file) => subir(it, t, file)} onSrc={(src) => delTicket(it, t, src)}
                        onDelete={() => quitar(it, (it.photos || []).find((p) => p.type === t))} />
                    })}
                  </div>
                </div>
              ))}
            </div>
            {w.ticket_id ? (
              <aside className="wr-gal">
                <h4><Icon.image /> Imágenes del cliente</h4>
                {galeria === null ? <div className="spinner sm" /> : galeria === false ? <p className="dev-mute">No puedes ver este ticket en el Helpdesk, así que tampoco sus imágenes.</p> : !galeria.length ? <p className="dev-mute">El cliente no ha mandado imágenes en sus tickets.</p> : (
                  <div className="wr-gal-grid">
                    {galeria.map((g) => (
                      <button key={g.src} type="button" draggable title={`${g.name} · ${g.from === 'cliente' ? 'del cliente' : 'del equipo'} · ${g.ticket} · ${fechaHora(g.at)}`}
                        onDragStart={(e) => e.dataTransfer.setData('text/x-wr-src', g.src)} onClick={() => elegirDelTicket(g.src)}>
                        <img src={api.warrantyTicketImageUrl(w.id, g.src)} alt={g.name} loading="lazy"
                          onError={(e) => { e.currentTarget.parentElement.hidden = true }} />
                        {g.ticket_id !== w.ticket_id ? <em>{g.ticket}</em> : g.from !== 'cliente' ? <em>Equipo</em> : null}
                      </button>
                    ))}
                  </div>
                )}
              </aside>
            ) : null}
          </div>
        )}

        <div className="modal-foot">
          {paso === 'solucion' ? (
            <>
              <button className="btn ghost" onClick={cerrar}>Cancelar</button>
              <button className="btn" disabled={guardando || faltaAlgo} onClick={guardar}>
                {guardando ? 'Guardando…' : res?.photos ? <>Guardar y añadir fotos <Icon.arrowRight /></> : items.length > 1 ? `Marcar ${items.length}` : 'Guardar'}
              </button>
            </>
          ) : (
            <>
              <span className={`wr-fotos-sum${pendientes ? '' : ' ok'}`}>{pendientes ? `Faltan ${pendientes} ${pendientes === 1 ? 'foto' : 'fotos'}` : <><Icon.check /> Todas las fotos</>}</span>
              <button className="btn" onClick={cerrar}>{pendientes ? 'Seguir luego' : 'Listo'}</button>
            </>
          )}
        </div>
      </div>
    </div>
  )
}

function Productos({ w, items, opts, onReload, onChanged }) {
  const toast = useToast()
  const confirm = useConfirm()
  const [panel, setPanel] = useState(!items.length)
  const [comp, setComp] = useState(COMP_VACIO)
  const [ocupado, setOcupado] = useState('')
  const [ficha, setFicha] = useState(null)
  const [filtro, setFiltro] = useState('')
  const [rev, setRev] = useState('')               // filtro por revisión
  const [sel, setSel] = useState(() => new Set())
  const [revisar, setRevisar] = useState(null)     // { items, preset }
  const rv = opts?.review

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
    const esc = csvCell
    const cab = ['Código', 'Tipo', 'Referencia AEME', 'Producto', 'Modelo en el servidor', 'Servidor', 'Tienda', 'Conectada', 'Última comunicación', 'Avisos', 'Revisión', 'Detalle de la revisión', 'Fotos']
    const lineas = items.map((i) => [i.code, i.kind === 'ap' ? 'Antena' : 'Etiqueta', i.aeme_ref, i.product_name, i.server_model, i.server, i.store_name, i.online == null ? '' : i.online ? 'Sí' : 'No', fechaHora(i.last_commu_at), (i.warnings || []).map((a) => a.text).join(' | '),
      rv?.resolutions?.[i.resolution]?.label || i.resolution,
      [i.battery_changed ? 'Pilas cambiadas' : '', i.zkong_decision ? rv?.zkong?.[i.zkong_decision] : '', i.zkong_issue, (i.broken_cases || []).map((k) => rv?.broken_cases?.[k] || k).join(', '), i.resolution_note].filter(Boolean).join(' · '),
      pideFotos(rv, i) ? `${fotosDe(rv, i).hay}/${fotosDe(rv, i).total}` : ''])
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
  const porRes = {}
  items.forEach((i) => { porRes[i.resolution] = (porRes[i.resolution] || 0) + 1 })
  const revisadas = items.length - (porRes.pendiente || 0)
  const sinFotos = items.filter((i) => faltanFotos(rv, i)).length
  const listado = items.filter((i) => (!filtro || (filtro === 'avisos' ? estadoEtiqueta(i) !== 'ok' : productoDe(i) === filtro))
    && (!rev || (rev === 'fotos' ? faltanFotos(rv, i) : i.resolution === rev)))
  const nuevas = comp.results && comp.checked === comp.text ? comp.results.filter((r) => !r.blocked && !r.already).length : null

  const elegidas = items.filter((i) => sel.has(i.id))
  const todasVisibles = listado.length > 0 && listado.every((i) => sel.has(i.id))
  const marcar = (id) => setSel((s) => { const n = new Set(s); n.has(id) ? n.delete(id) : n.add(id); return n })
  const marcarVisibles = () => setSel((s) => { const n = new Set(s); listado.forEach((i) => (todasVisibles ? n.delete(i.id) : n.add(i.id))); return n })
  const trasRevisar = () => { setSel(new Set()); onReload(); onChanged() }

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
          {rv && (
            <div className="wr-revbar">
              <div className="wr-revbar-h">
                <b>Revisión</b>
                <span>{revisadas} de {items.length} revisadas</span>
                {sinFotos ? <button className="wr-revbar-warn" onClick={() => setRev('fotos')}><Icon.image /> Faltan fotos en {sinFotos}</button> : null}
              </div>
              <div className="wr-revbar-bar">
                {Object.keys(rv.resolutions).filter((k) => k !== 'pendiente' && porRes[k]).map((k) => (
                  <i key={k} className={`c-${RES_UI[k]?.c || 'gray'}`} style={{ width: `${(porRes[k] / items.length) * 100}%` }} title={`${rv.resolutions[k].label}: ${porRes[k]}`} />
                ))}
              </div>
              <div className="wr-chips sm">
                <button className={!rev ? 'on' : ''} onClick={() => setRev('')}>Todas</button>
                {Object.keys(rv.resolutions).filter((k) => porRes[k]).map((k) => (
                  <button key={k} className={rev === k ? 'on' : ''} onClick={() => setRev(rev === k ? '' : k)}><i className={`wr-cdot c-${RES_UI[k]?.c || 'gray'}`} />{rv.resolutions[k].label} ({porRes[k]})</button>
                ))}
                {sinFotos ? <button className={rev === 'fotos' ? 'on' : ''} onClick={() => setRev(rev === 'fotos' ? '' : 'fotos')}><Icon.image /> Faltan fotos ({sinFotos})</button> : null}
              </div>
            </div>
          )}
          <div className="wr-chips sm">
            <button className={!filtro ? 'on' : ''} onClick={() => setFiltro('')}>Todos los productos</button>
            {conAvisos ? <button className={filtro === 'avisos' ? 'on' : ''} onClick={() => setFiltro('avisos')}>Con avisos ({conAvisos})</button> : null}
            {Object.entries(porProducto).sort((a, b) => b[1] - a[1]).map(([k, n]) => (
              <button key={k} className={filtro === k ? 'on' : ''} onClick={() => setFiltro(filtro === k ? '' : k)}>{k} ×{n}</button>
            ))}
          </div>
          {rv && listado.length > 0 && (
            <label className="wr-selall"><input type="checkbox" checked={todasVisibles} onChange={marcarVisibles} />
              {todasVisibles ? 'Quitar la selección' : `Seleccionar ${listado.length === items.length ? 'todas' : `las ${listado.length} que se ven`}`}</label>
          )}
          {!listado.length ? <p className="dev-mute">Ninguna con este filtro.</p> : null}
          <ul className={`wr-lbls big${rv ? ' rev' : ''}`}>
            {listado.map((i) => (
              <li key={i.id} className={`st-${estadoEtiqueta(i)}${sel.has(i.id) ? ' sel' : ''}`}>
                {rv ? <input type="checkbox" className="wr-pick" checked={sel.has(i.id)} onChange={() => marcar(i.id)} aria-label={`Seleccionar ${i.code}`} /> : null}
                <span className="wr-lbl-code mono"><button className="link" title="Ver ficha e historial" onClick={() => setFicha(i.code)}>{i.code}</button>{i.kind === 'ap' ? <em className="wr-kind">Antena</em> : null}</span>
                <Donde r={i} />
                <Modelo r={i} onPick={(ref) => cambiarModelo(i, ref)} />
                {rv ? <ResPill rv={rv} it={i} onClick={() => setRevisar({ items: [i] })} /> : null}
                <button className="icon-btn" title="Quitar de la garantía" onClick={() => quitar(i)}><Icon.trash /></button>
                <div className="full"><Avisos list={i.warnings} />{rv ? <ResDetalle rv={rv} it={i} /> : null}</div>
              </li>
            ))}
          </ul>
          {elegidas.length > 0 && (
            <div className="wr-selbar">
              <b title="Marcar como…">{elegidas.length} {elegidas.length === 1 ? 'seleccionada' : 'seleccionadas'}:</b>
              {RAPIDAS.map((k) => {
                const Ic = RES_UI[k].ic
                return <button key={k} className={`wr-res c-${RES_UI[k].c}`} onClick={() => setRevisar({ items: elegidas, preset: k })}><Ic /> <span>{rv.resolutions[k]?.label}</span></button>
              })}
              <button className="dev-btn" onClick={() => setRevisar({ items: elegidas })}>Otra…</button>
              <button className="icon-btn" title="Quitar la selección" onClick={() => setSel(new Set())}>✕</button>
            </div>
          )}
          {items[0]?.checked_at ? <p className="dev-mute wr-prod-foot">Comprobadas por última vez {fechaHora(items.reduce((a, i) => (i.checked_at > a ? i.checked_at : a), ''))}.</p> : null}
        </>
      )}
      {!items.length && !panel ? <p className="dev-mute">Todavía no hay etiquetas. Añádelas con su código.</p> : null}
      {ficha && <FichaDispositivo code={ficha} servers={[]} onClose={() => setFicha(null)} onOpen={setFicha} />}
      {revisar && <Revisar w={w} rv={rv} diag={opts?.diagnosis} items={revisar.items} preset={revisar.preset} onClose={() => setRevisar(null)} onSaved={trasRevisar} />}
    </>
  )
}

const CANAL = { correo: ['Correo', Icon.mail], whatsapp: ['WhatsApp', Icon.whatsapp], adjunto: ['Adjunto', Icon.file] }

/**
 * Fotos del cliente: lo que ha mandado por correo, WhatsApp o adjunto en el ticket de la
 * garantía y en sus otros tickets (solo los que el agente ve en el Helpdesk), por ticket.
 * Visor a pantalla completa con flechas, descarga y enlace al ticket.
 */
function FotosCliente({ w, fotos, inicio, onClose, onOpenTicket }) {
  const delCliente = fotos.filter((g) => g.from === 'cliente')
  const [filtro, setFiltro] = useState(delCliente.length || !fotos.length ? 'cliente' : 'todas')
  const lista = filtro === 'cliente' ? delCliente : fotos
  const [sel, setSel] = useState(inicio != null ? Math.max(0, lista.findIndex((g) => g.src === inicio)) : null)
  const g = sel != null ? lista[sel] : null
  const grupos = [...lista.reduce((m, x, i) => m.set(x.ticket_id, [...(m.get(x.ticket_id) || []), [x, i]]), new Map()).entries()]
    .sort(([a], [b]) => (a === w.ticket_id ? -1 : b === w.ticket_id ? 1 : 0))
  const tickets = new Set(fotos.map((x) => x.ticket_id)).size

  useEffect(() => {
    const k = (e) => {
      if (e.key === 'Escape') { e.stopPropagation(); sel != null ? setSel(null) : onClose() }
      else if (sel != null && e.key === 'ArrowRight') setSel((i) => (i + 1) % lista.length)
      else if (sel != null && e.key === 'ArrowLeft') setSel((i) => (i - 1 + lista.length) % lista.length)
    }
    document.addEventListener('keydown', k)
    return () => document.removeEventListener('keydown', k)
  }, [sel, lista.length, onClose])

  const canal = (x) => { const [l, Ic] = CANAL[x.channel] || CANAL.adjunto; return <span className={`fc-canal c-${x.channel}`}><Ic /> {l}</span> }

  return (
    <div className="modal-bg wr-over" onMouseDown={(e) => e.target.classList.contains('modal-bg') && onClose()}>
      <div className="modal fc-modal" role="dialog" aria-label="Fotos del cliente">
        <div className="modal-h">
          <div>
            <h3>Fotos del cliente</h3>
            <p className="dev-mute fc-sub">{fotos.length} {fotos.length === 1 ? 'imagen' : 'imágenes'} de {tickets} {tickets === 1 ? 'ticket' : 'tickets'} · {w.client_name || 'Sin cliente'}</p>
          </div>
          <div className="fc-h-acts">
            <div className="dc-seg">
              {[['cliente', `Del cliente (${delCliente.length})`], ['todas', `Todas (${fotos.length})`]].map(([k, l]) => (
                <button key={k} className={filtro === k ? 'on' : ''} onClick={() => { setFiltro(k); setSel(null) }}>{l}</button>
              ))}
            </div>
            <button className="icon-btn" onClick={onClose} title="Cerrar (Esc)">✕</button>
          </div>
        </div>
        <div className="modal-body fc-body">
          {!lista.length ? <p className="dev-mute fc-none">{filtro === 'cliente' ? 'El cliente no ha mandado imágenes. En «Todas» están también las del equipo.' : 'No hay imágenes en los tickets de este cliente.'}</p> : grupos.map(([tid, xs]) => (
            <section key={tid} className="fc-grupo">
              <header>
                <Icon.ticket /> <span className="mono">{xs[0][0].ticket}</span>
                {tid === w.ticket_id ? <small>ticket de esta garantía</small> : <small>otro ticket del cliente</small>}
                {onOpenTicket ? <button className="wf-link" onClick={() => onOpenTicket(tid)}>Abrir ticket</button> : null}
              </header>
              <div className="fc-grid">
                {xs.map(([x, i]) => (
                  <button key={x.src} type="button" className="fc-item" onClick={() => setSel(i)} title={x.name}>
                    <span className="fc-th"><img src={api.warrantyTicketImageUrl(w.id, x.src)} alt="" loading="lazy" onError={(e) => { e.currentTarget.closest('.fc-item').hidden = true }} />
                      {x.from !== 'cliente' ? <em>Equipo</em> : null}</span>
                    <span className="fc-meta">{canal(x)}<time>{fechaHora(x.at)}</time></span>
                  </button>
                ))}
              </div>
            </section>
          ))}
        </div>
        <div className="modal-foot fc-foot">
          <span className="dev-mute"><Icon.info /> Para ponerla como foto de un dispositivo: Etiquetas → Revisar → paso Fotos, donde salen estas mismas imágenes.</span>
        </div>

        {g ? (
          <div className="fc-visor" onMouseDown={(e) => e.target === e.currentTarget && setSel(null)}>
            <button className="fc-cerrar" onClick={() => setSel(null)} title="Volver (Esc)">✕</button>
            {lista.length > 1 ? <button className="fc-nav prev" onClick={() => setSel((i) => (i - 1 + lista.length) % lista.length)} title="Anterior (←)"><Icon.chevron /></button> : null}
            <img key={g.src} src={api.warrantyTicketImageUrl(w.id, g.src)} alt={g.name} />
            {lista.length > 1 ? <button className="fc-nav next" onClick={() => setSel((i) => (i + 1) % lista.length)} title="Siguiente (→)"><Icon.chevron /></button> : null}
            <div className="fc-barra">
              <div className="fc-info">
                <b>{g.name}</b>
                <span>{canal(g)} · {g.from === 'cliente' ? 'del cliente' : 'del equipo'} · <span className="mono">{g.ticket}</span> · {fechaHora(g.at)}</span>
              </div>
              <span className="fc-pos">{sel + 1} / {lista.length}</span>
              <a className="dev-btn" href={api.warrantyTicketImageUrl(w.id, g.src, true)} download><Icon.download /> Descargar</a>
              <a className="dev-btn" href={api.warrantyTicketImageUrl(w.id, g.src)} target="_blank" rel="noreferrer"><Icon.link /> Tamaño real</a>
              {onOpenTicket ? <button className="dev-btn" onClick={() => onOpenTicket(g.ticket_id)}><Icon.ticket /> Abrir ticket</button> : null}
            </div>
          </div>
        ) : null}
      </div>
    </div>
  )
}

const CAMPOS = { created: 'Garantía creada', items: 'Etiquetas', item_model: 'Modelo de una etiqueta', review: 'Revisión de dispositivos', zkong: 'ZKONG', documents: 'Documentos', first_contact_at: 'Primera fecha de contacto', received_at: 'Fecha de recepción', closed_at: 'Fecha de cierre', client_name: 'Cliente', project_name: 'Proyecto', category: 'Categoría', urgency: 'Urgencia', status: 'Estado', responsible_id: 'Responsable', ticket_id: 'Ticket', description: 'Descripción' }

function Ficha({ id, opts, isSuper, onBack, onEdit, onChanged, onOpenTicket }) {
  const toast = useToast()
  const confirm = useConfirm()
  const [d, setD] = useState(null)
  const [tab, setTab] = useState('productos')   // etiquetas | documentos
  const [hist, setHist] = useState('')
  const [nota, setNota] = useState({ type: 'nota', body: '' })
  const [enviando, setEnviando] = useState(false)
  const notaRef = useRef(null)
  const [fotos, setFotos] = useState(null)         // imágenes de los tickets del cliente; false si no ve el ticket
  const [verFotos, setVerFotos] = useState(undefined)   // undefined cerrado; null rejilla; src abre el visor

  const cargar = useCallback(() => api.warranty(id).then((r) => setD(r.ok ? r : { error: r.error || 'No se pudo cargar' })), [id])
  useEffect(() => { setD(null); cargar() }, [cargar])
  const wid = d?.warranty?.id
  const tid = d?.warranty?.ticket_id
  useEffect(() => {
    setFotos(null)
    if (wid && tid) api.warrantyTicketImages(wid, true).then((r) => setFotos(!r.ok ? [] : r.visible === false ? false : r.images))
  }, [wid, tid])

  if (d === null) return <div className="center-load"><div className="spinner" /></div>
  if (d.error) return <><button className="wr-back" onClick={onBack}><Icon.chevron style={{ transform: 'rotate(90deg)' }} /> Garantías</button><div className="dev-error"><Icon.warn /> {d.error}</div></>

  const w = d.warranty
  const abierta = !cerradaEn(opts, w.status)
  const diasAbierta = dias(w.received_at, abierta ? null : w.closed_at)
  const diasSin = dias(w.last_activity_at)
  const stale = abierta && diasSin != null && diasSin >= (opts?.stale_days || 15)

  const guardarCon = async (cambios, msg) => {
    const r = await api.saveWarranty({ ...w, responsible_id: w.responsible_id || '', urgency: w.urgency || '', ticket_code: w.ticket_code || '', first_contact_at: w.first_contact_at || '', closed_at: (w.closed_at || '').slice(0, 10), ...cambios })
    if (r.ok) { toast(msg); cargar(); onChanged() } else toast(r.error || 'Error', 'err')
  }
  const cerrar = async () => {
    if (!(await confirm({ title: 'Cerrar garantía', message: `¿Cerrar ${w.code}? Se podrá reabrir después.`, confirmText: 'Cerrar garantía' }))) return
    guardarCon({ status: opts?.status_closed || 'cerrada' }, 'Garantía cerrada')
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

  const ticket = w.ticket_code
    ? (onOpenTicket ? <button className="wr-tklink mono" title={w.ticket_subject || 'Abrir ticket'} onClick={() => onOpenTicket(w.ticket_id)}>{w.ticket_code}</button> : <span className="mono">{w.ticket_code}</span>)
    : null
  // Seguimiento (notas del equipo), historial (cambios automáticos) y lo que escribe el
  // cliente en el ticket, en una sola línea de tiempo.
  const mensajes = d.messages || []
  const linea = [
    ...(!hist || hist === 'notas' ? d.notes.map((n) => ({ k: `n${n.id}`, nota: n, at: n.created_at })) : []),
    ...(!hist || hist === 'cambios' ? d.events.map((e) => ({ k: `e${e.id}`, ev: e, at: e.created_at })) : []),
    ...(!hist || hist === 'cliente' ? mensajes.map((m) => ({ k: `m${m.id}`, msg: m, at: m.created_at })) : []),
  ].sort((a, b) => String(b.at).localeCompare(String(a.at)))
  const coberturas = [...new Set(d.items.map((i) => i.coverage).filter(Boolean))]
  const remesas = [...new Map(d.items.filter((i) => i.batch_code).map((i) => [i.batch_code, i.batch_status])).entries()]
  const revisadas = d.items.filter((i) => i.resolution && i.resolution !== 'pendiente').length
  const estados = Object.entries(opts?.status_meta || {}).filter(([k, m]) => m.active || k === w.status).map(([k, m]) => ({ value: k, label: m.label, color: m.color }))

  return (
    <>
      <button className="wr-back" onClick={onBack}><Icon.chevron style={{ transform: 'rotate(90deg)' }} /> Garantías</button>

      <div className="wf-head">
        <div className="wf-id">
          <div className="wf-t">
            <span className="wf-code">{w.code}</span>
            <h2>{w.client_name || <span className="dev-mute">Sin cliente</span>}</h2>
            <EstadoPill s={w.status} opts={opts} />
            <UrgPill u={w.urgency} opts={opts} />
            {w.alerts?.length ? <Alertas list={w.alerts} full /> : stale ? <span className="wr-flag"><Icon.warn /> Sin actividad {diasSin} días</span> : null}
          </div>
          <div className="wf-meta">
            <span>{w.project_name || <span className="dev-mute">Sin proyecto</span>}</span>
            <span className="wl-resp">{w.responsible_name ? <><i>{iniciales(w.responsible_name)}</i>{w.responsible_name}</> : <span className="wf-warn">Sin responsable</span>}</span>
            {ticket ? <span>Ticket {ticket}</span> : null}
            <span className={abierta && diasAbierta >= 30 ? 'wf-warn' : ''}>{abierta ? `Abierta ${diasAbierta ?? 0} ${diasAbierta === 1 ? 'día' : 'días'}` : `Cerrada en ${diasAbierta ?? 0} días`}</span>
            <span className={stale ? 'wf-warn' : ''}>Última actividad {haceDias(diasSin)}</span>
          </div>
        </div>
        <div className="wf-acts">
          <Select value={w.status} onChange={(v) => v !== w.status && guardarCon({ status: v }, `Estado: ${opts?.statuses?.[v] || v}`)} options={estados} />
          {abierta
            ? <button className="btn dark" onClick={cerrar}><Icon.lock /> Cerrar</button>
            : <button className="btn ghost" onClick={() => guardarCon({ status: opts?.status_reopen || 'en_curso' }, 'Garantía reabierta')}><Icon.refresh /> Reabrir</button>}
          {w.ticket_id && fotos !== false ? (
            <button className="dev-btn" onClick={() => setVerFotos(null)} disabled={!fotos?.length} title={fotos && !fotos.length ? 'El cliente no ha mandado imágenes en sus tickets' : 'Lo que ha mandado el cliente por correo, WhatsApp o adjunto'}>
              <Icon.image /> Fotos del cliente{fotos === null ? null : <em className="fc-n">{fotos.filter((x) => x.from === 'cliente').length}</em>}
            </button>
          ) : null}
          <button className="dev-btn" onClick={() => onEdit(w)}><Icon.pencil /> Editar</button>
          {isSuper ? <button className="icon-btn" title="Borrar garantía" style={{ color: 'var(--danger)' }} onClick={borrar}><Icon.trash /></button> : null}
        </div>
      </div>

      <div className="wf-grid">
        <div className="wf-main">
          <section className="wf-card">
            <header><h3><Icon.doc /> Qué le pasa al cliente</h3>
              {ticket ? <span className="dev-mute wf-orig">Ticket {ticket}{w.ticket_subject ? ` · ${w.ticket_subject}` : ''}</span> : null}
            </header>
            {w.description ? <p className="wf-desc">{w.description}</p> : <p className="dev-mute wf-desc-none">Sin descripción. <button className="wf-link" onClick={() => onEdit(w)}>Añadirla</button></p>}
            {fotos?.some((x) => x.from === 'cliente') ? (
              <div className="fc-tira">
                {fotos.filter((x) => x.from === 'cliente').slice(0, 6).map((x) => (
                  <button key={x.src} type="button" onClick={() => setVerFotos(x.src)} title={`${x.name} · ${x.ticket} · ${fechaHora(x.at)}`}>
                    <img src={api.warrantyTicketImageUrl(w.id, x.src)} alt={x.name} loading="lazy" onError={(e) => { e.currentTarget.parentElement.hidden = true }} />
                  </button>
                ))}
                <button type="button" className="wf-link" onClick={() => setVerFotos(null)}>Ver las fotos del cliente</button>
              </div>
            ) : null}
          </section>

          <section className="wf-card wf-tabs-card">
            <div className="wr-tabs" role="tablist">
              {[['productos', `Etiquetas${d.items.length ? ` (${d.items.length})` : ''}`, Icon.tag], ['documentos', 'Documentos', Icon.file]].map(([k, l, Ic]) => (
                <button key={k} className={tab === k ? 'on' : ''} onClick={() => setTab(k)}><Ic /> {l}</button>
              ))}
              {tab === 'productos' && d.items.length ? <span className="wf-tab-sum dev-mute">{revisadas} de {d.items.length} revisadas</span> : null}
            </div>
            <div className="wf-tab-body">
              {tab === 'productos' && <Productos w={w} items={d.items} opts={opts} onReload={cargar} onChanged={onChanged} />}
              {tab === 'documentos' && <WarrantyDocuments w={w} opts={opts} onGoReview={() => setTab('productos')} onEditWarranty={() => onEdit(w)} onChanged={cargar} />}
            </div>
          </section>
        </div>

        <aside className="wf-side">
          <section className="wf-card">
            <header><h3>Datos del expediente</h3></header>
            <dl className="wf-dl">
              <div><dt>Cliente</dt><dd>{w.client_name || '—'}</dd></div>
              <div><dt>Proyecto</dt><dd>{w.project_name || '—'}</dd></div>
              <div><dt>Categoría</dt><dd>{w.category || '—'}</dd></div>
              <div><dt>Quién paga</dt><dd>{coberturas.length
                ? <span className="wl-cob">{coberturas.map((c) => <em key={c} className={`c-${c}`}>{COB_CORTO[c] || c}</em>)}</span>
                : <span className="dev-mute">Al revisar las etiquetas</span>}</dd></div>
              <div><dt>Remesa ZKONG</dt><dd>{remesas.length
                ? remesas.map(([code, st]) => <span key={code} className="wf-rem"><b className="mono">{code}</b> <small>{opts?.review?.batch_statuses?.[st] || st}</small></span>)
                : '—'}</dd></div>
              <div><dt>Primer contacto</dt><dd>{fecha(w.first_contact_at)}</dd></div>
              <div><dt>Recepción</dt><dd>{fecha(w.received_at)}</dd></div>
              {w.closed_at ? <div><dt>Cierre</dt><dd>{fecha(w.closed_at)}</dd></div> : null}
              <div><dt>Creada</dt><dd>{fechaHora(w.created_at)}{w.created_by_name ? <small> · {w.created_by_name}</small> : null}</dd></div>
            </dl>
          </section>

          <section className="wf-card">
            <header><h3><Icon.clock /> Seguimiento e historial</h3></header>
            <div className="wf-compose">
              <textarea ref={notaRef} rows={2} value={nota.body} onChange={(e) => setNota((s) => ({ ...s, body: e.target.value }))}
                onKeyDown={(e) => { if (e.key === 'Enter' && (e.ctrlKey || e.metaKey)) enviarNota() }}
                placeholder="Qué se ha hecho, con quién se ha hablado, qué falta…" />
              <div className="wf-compose-f">
                <Select sm value={nota.type} onChange={(v) => setNota((s) => ({ ...s, type: v }))}
                  options={Object.entries(opts?.note_types || {}).map(([k, v]) => ({ value: k, label: v }))} />
                <button className="btn sm" disabled={enviando || !nota.body.trim()} onClick={enviarNota}><Icon.plus /> {enviando ? 'Añadiendo…' : 'Añadir'}</button>
              </div>
            </div>
            <div className="dc-seg wf-filtro">
              {[['', 'Todo'], ['notas', `Notas (${d.notes.length})`], ['cambios', 'Cambios'], ...(w.ticket_id && w.ticket_visible ? [['cliente', `Cliente (${mensajes.length})`]] : [])].map(([k, l]) => <button key={k} className={hist === k ? 'on' : ''} onClick={() => setHist(k)}>{l}</button>)}
            </div>
            {!linea.length ? <p className="dev-mute wf-none">{hist === 'notas' ? 'Todavía no hay seguimiento.' : hist === 'cliente' ? 'El cliente no ha escrito en el ticket.' : 'Sin movimientos.'}</p> : (
              <ol className="wf-tl">
                {linea.map(({ k, nota: n, ev: e, msg: m }) => m ? (
                  <li key={k} className="msg">
                    <div className="wf-tl-h"><b>Cliente</b><span className={`wr-type t-msg-${m.channel}`}>{m.channel === 'whatsapp' ? 'WhatsApp' : 'Correo'}</span><time>{fechaHora(m.created_at)}</time></div>
                    <p>{m.text}</p>
                  </li>
                ) : n ? (
                  <li key={k} className="nota">
                    <div className="wf-tl-h"><b>{n.user_name || 'Sistema'}</b><span className={`wr-type t-${n.type}`}>{opts?.note_types?.[n.type] || n.type}</span><time>{fechaHora(n.created_at)}</time></div>
                    <p>{n.body}</p>
                  </li>
                ) : (
                  <li key={k} className={`ev f-${e.field}`}>
                    <div className="wf-tl-h"><b>{e.field === 'created' ? 'Garantía creada' : CAMPOS[e.field] || e.field}</b><time>{fechaHora(e.created_at)}</time></div>
                    {e.field === 'created' ? null : <div className="wf-ev">{e.from_value ? <><s>{e.from_value}</s> → </> : null}<b>{e.to_value || '—'}</b></div>}
                    <small>{e.user_name || 'Sistema'}</small>
                  </li>
                ))}
              </ol>
            )}
          </section>
        </aside>
      </div>
      {verFotos !== undefined && fotos?.length ? <FotosCliente w={w} fotos={fotos} inicio={verFotos} onClose={() => setVerFotos(undefined)} onOpenTicket={onOpenTicket} /> : null}
    </>
  )
}

export default function Warranties({ isSuper = false, openId = null, onOpened, onOpenTicket, filtro = null }) {
  const [opts, setOpts] = useState(null)
  const [sel, setSel] = useState(openId)
  // Llegar con una garantía concreta (desde la ficha de un ticket o ZKONG).
  useEffect(() => { if (openId) { setSel(openId); onOpened?.() } }, [openId])
  // Llegar con la lista filtrada (desde Equipo).
  useEffect(() => { if (filtro) setSel(null) }, [filtro])
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
        <span className="spacer" />
        {/* Aquí pone la lista sus acciones (exportar y nueva), como en el resto de pantallas. */}
        {!sel && <div id="wr-head-acts" className="wl-head-acts" />}
      </header>

      <div className="page-scroll"><div className="page dev-page wr-page">
        {sel
          ? <Ficha key={`${sel}-${reload}`} id={sel} opts={opts} isSuper={isSuper} onBack={() => setSel(null)}
              onEdit={(w) => setForm({ ...VACIA, ...w, responsible_id: w.responsible_id || '', urgency: w.urgency || '', ticket_code: w.ticket_code || '' })}
              onChanged={() => {}} onOpenTicket={onOpenTicket} />
          : <Lista opts={opts} reload={reload} filtro={filtro} onOpen={setSel} onNew={() => setForm({ ...VACIA, received_at: hoy() })} />}
      </div></div>

      {form && <Formulario inicial={form} opts={opts} onClose={() => setForm(null)} onSaved={(id) => { setForm(null); refrescar(); setSel(id) }} />}
    </>
  )
}
