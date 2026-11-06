import { useState, useEffect, useCallback, useRef } from 'react'
import { createPortal } from 'react-dom'
import { api } from '../api.js'
import { csvCell } from '../csv.js'
import { Icon } from '../icons.jsx'
import { useToast, useConfirm } from '../App.jsx'
import Select from './Select.jsx'
import { FichaDispositivo } from './Devices.jsx'
import WarrantyDocuments from './WarrantyDocuments.jsx'
import { GuiasRelacionadas } from './WarrantyGuides.jsx'
import { leerCodigos } from '../barcode.js'
import { TicketModal } from './Tickets.jsx'
import { useDialog, filaClic } from './a11y.js'
import Vacio from './Vacio.jsx'
import { SkFicha } from './Esqueleto.jsx'

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
  ['unreviewed', 'Con dispositivos sin revisar'], ['no_client', 'Sin cliente'], ['no_project', 'Sin proyecto'], ['no_category', 'Sin categoría'], ['repeated', 'Con dispositivos repetidos'], ['closed', 'Cerradas'],
]
const SIZE = 25
const hoy = () => new Date().toISOString().slice(0, 10)
const motivoDe = (opts, k) => (opts?.close_reasons || []).find((r) => r.key === k)?.label || k

/* Lo que pide cerrar (motivo y cómo se resolvió) o reabrir (por qué). v: valores · pon(campo)(valor). */
function CamposCierre({ modo, v, pon, opts, actual }) {
  if (modo === 'reabrir') return (
    <label className="field"><span className="lbl">Por qué se reabre <em>*</em></span>
      <textarea rows={2} maxLength={500} value={v.reopen_reason || ''} onChange={(e) => pon('reopen_reason')(e.target.value)} placeholder="El cliente vuelve a tener el problema, faltaba algo…" /></label>
  )
  const motivos = (opts?.close_reasons || []).filter((r) => r.active || r.key === actual).map((r) => ({ value: r.key, label: r.label }))
  return (
    <>
      <div className="field"><span className="lbl">Motivo de cierre <em>*</em></span>
        <Select block value={v.close_reason || ''} placeholder="Elige el motivo" onChange={pon('close_reason')} options={motivos} /></div>
      <label className="field"><span className="lbl">Cómo se ha resuelto <span className="hint">(opcional)</span></span>
        <textarea rows={3} maxLength={2000} value={v.close_resolution || ''} onChange={(e) => pon('close_resolution')(e.target.value)} placeholder="Qué se ha hecho al final, qué se ha acordado con el cliente…" /></label>
    </>
  )
}

/* Cerrar o reabrir una o varias garantías: estado de destino y lo que pide cada caso. */
function CierreModal({ modo, titulo, estados, inicial, opts, agentes, conFecha, onClose, onOk }) {
  const dlg = useDialog(onClose, titulo)
  const [v, setV] = useState(inicial)
  const [busy, setBusy] = useState(false)
  const pon = (k) => (x) => setV((s) => ({ ...s, [k]: x }))
  const falta = modo === 'cerrar' ? !v.close_reason : !(v.reopen_reason || '').trim()
  const ok = async () => { setBusy(true); const r = await onOk(v); setBusy(false); if (r) onClose() }
  return (
    <div className="modal-bg wr-over" onMouseDown={(e) => e.target.classList.contains('modal-bg') && onClose()}>
      <div className="modal" style={{ maxWidth: 520 }} {...dlg}>
        <div className="modal-head"><h3>{titulo}</h3><button className="x" onClick={onClose} aria-label="Cerrar"><Icon.x className="ic-x" aria-hidden="true" /></button></div>
        <div className="modal-body">
          <div className={conFecha ? 'grid2' : ''}>
            <div className="field"><span className="lbl">{modo === 'cerrar' ? 'Queda como' : 'Vuelve a'}</span>
              <Select block value={v.status} onChange={pon('status')} options={estados} /></div>
            {conFecha ? <label className="field"><span className="lbl">Fecha de cierre</span>
              <input type="date" value={v.closed_at || ''} max={hoy()} onChange={(e) => pon('closed_at')(e.target.value)} /></label> : null}
          </div>
          <CamposCierre modo={modo} v={v} pon={pon} opts={opts} />
          {agentes ? <div className="field"><span className="lbl">Responsable <span className="hint">(si cambia)</span></span>
            <Select block searchable value={String(v.responsible_id || '')} onChange={pon('responsible_id')}
              options={[{ value: '', label: 'Sin responsable' }, ...agentes]} /></div> : null}
        </div>
        <div className="modal-foot">
          <button className="btn ghost" onClick={onClose}>Cancelar</button>
          <button className={`btn ${modo === 'cerrar' ? 'dark' : ''}`} disabled={busy || falta} onClick={ok}>
            {modo === 'cerrar' ? <><Icon.lock /> {busy ? 'Cerrando…' : 'Cerrar'}</> : <><Icon.refresh /> {busy ? 'Reabriendo…' : 'Reabrir'}</>}
          </button>
        </div>
      </div>
    </div>
  )
}
const VACIA = { id: 0, first_contact_at: '', received_at: '', installed_at: '', closed_at: '', portal_client_id: null, client_name: '', portal_project_id: null, project_name: '', category: '', urgency: '', status: 'no_iniciado', responsible_id: '', ticket_code: '', description: '' }

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
  const dlg = useDialog(null, inicial.id ? 'Editar garantía' : 'Nueva garantía')
  const [f, setF] = useState(() => ({ ...inicial, closed_at: (inicial.closed_at || '').slice(0, 10) }))
  const [saving, setSaving] = useState(false)
  const set = (k) => (e) => setF((s) => ({ ...s, [k]: e.target.value }))
  const pon = (k) => (v) => setF((s) => ({ ...s, [k]: v }))
  // Al pasar a «Cerrada» la fecha de cierre propone hoy (se puede cambiar).
  const elegirEstado = (v) => setF((s) => ({ ...s, status: v, closed_at: cerradaEn(opts, v) ? s.closed_at || hoy() : s.closed_at }))
  const cerrado = cerradaEn(opts, f.status)
  const eraCerrada = !!f.id && cerradaEn(opts, inicial.status)
  const modoCierre = cerrado && !eraCerrada ? 'cerrar' : eraCerrada && !cerrado ? 'reabrir' : eraCerrada ? 'corregir' : null

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
  const [comp, setComp] = useState(() => (inicial.labels_text ? { ...COMP_VACIO, text: inicial.labels_text } : COMP_VACIO))
  // Alta en dos pasos: primero los datos y después las etiquetas (pantalla propia, sin scroll).
  const [paso, setPaso] = useState('datos')
  const enEtiquetas = !f.id && paso === 'etiquetas'
  const siguiente = () => setPaso('etiquetas')
  const bloqueoEtiquetas = f.id || !comp.text.trim() ? null
    : comp.checked !== comp.text || !comp.results ? 'Comprueba las etiquetas antes de crear la garantía'
    : comp.invalid?.length ? 'Quita los códigos que no son etiquetas ni antenas'
    : comp.results.some((r) => r.lookup_state !== 'found') ? 'Hay etiquetas que no aparecen en los servidores: corrígelas, quítalas o vuelve a comprobar'
    : comp.results.some((r) => r.blocked) ? 'Hay etiquetas que no pueden entrar: quítalas de la lista'
    : null

  const guardar = async () => {
    setSaving(true)
    const r = await api.saveWarranty(f.id ? f : { ...f, labels: comp.text, manual: comp.manual })
    setSaving(false)
    if (!r.ok) { toast(r.error || 'Error', 'err'); return }
    const n = r.added?.length || 0
    toast(f.id ? 'Garantía guardada' : `Garantía ${r.code} creada${n ? ` con ${n} ${n === 1 ? 'etiqueta' : 'etiquetas'}` : ''}`)
    if (r.skipped?.length) toast(`${r.skipped.length} ${r.skipped.length === 1 ? 'etiqueta no entró' : 'etiquetas no entraron'}: ${r.skipped.slice(0, 3).map((s) => `${s.code} (${s.reason})`).join('; ')}`, 'err')
    avisarReincidentes(toast, r.repeat)
    onSaved(r.id)
  }

  return (
    <div className="modal-bg wr-over" onMouseDown={(e) => e.target.classList.contains('modal-bg') && onClose()}>
      <div className={`modal ${enEtiquetas ? 'wr-alta-lbls' : ''}`} style={{ maxWidth: f.id ? 640 : enEtiquetas ? 1080 : 720 }} {...dlg}>
        <div className="modal-head">
          <h3>{f.id ? `Editar ${f.code}` : inicial.ticket_code ? `Nueva garantía desde ${inicial.ticket_code}` : 'Nueva garantía'}</h3>
          {!f.id && (
            <div className="wr-pasos">
              <button className={paso === 'datos' ? 'on' : 'done'} onClick={() => setPaso('datos')}><b>{paso === 'datos' ? '1' : <Icon.check />}</b> Datos</button>
              <i />
              <button className={paso === 'etiquetas' ? 'on' : ''} onClick={siguiente}><b>2</b> Etiquetas</button>
            </div>
          )}
          <button className="x" onClick={onClose} aria-label="Cerrar"><Icon.x className="ic-x" aria-hidden="true" /></button>
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
            <label className="field"><span className="lbl">Fecha de recepción <span className="hint">(cuando lleguen al almacén)</span></span>
              <input type="date" value={f.received_at || ''} onChange={set('received_at')} /></label>
          </div>
          <label className="field"><span className="lbl">Fecha de instalación <span className="hint">(opcional: si se indica, cuenta para todos los dispositivos en lugar de su alta en ZKONG)</span></span>
            <input type="date" value={f.installed_at || ''} onChange={set('installed_at')} /></label>
          <div className="grid2">
            <div className="field"><span className="lbl">Estado</span>
              <Select block value={f.status} onChange={elegirEstado}
                options={Object.entries(opts?.status_meta || {}).filter(([k, m]) => m.active || k === inicial.status).map(([k, m]) => ({ value: k, label: m.label, color: m.color }))} /></div>
            <label className="field"><span className="lbl">Fecha de cierre {!cerrado && <span className="hint">(al cerrarla)</span>}</span>
              <input type="date" value={cerrado ? f.closed_at || '' : ''} onChange={set('closed_at')} disabled={!cerrado} /></label>
          </div>
          {modoCierre ? <div className="wr-cierre-f"><CamposCierre modo={modoCierre === 'reabrir' ? 'reabrir' : 'cerrar'} v={f} pon={pon} opts={opts} actual={inicial.close_reason} /></div> : null}
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
// Si el ticket vino del formulario de garantías del portal, trae ya el cliente, el sitio
// y los dispositivos que dio el cliente (su fallo y sus fotos pasan solos al añadirlos).
export function NuevaGarantia({ inicial, onClose, onSaved }) {
  const [opts, setOpts] = useState(null)
  const [solicitud, setSolicitud] = useState(inicial?.ticket_code ? undefined : null)
  useEffect(() => { api.warrantyOptions().then((r) => setOpts(r.ok ? r : {})) }, [])
  useEffect(() => {
    if (inicial?.ticket_code) api.warrantyTicketRequest(inicial.ticket_code).then((r) => setSolicitud(r.ok ? r.request : null))
  }, [inicial?.ticket_code])
  if (!opts || solicitud === undefined) return null
  const s = solicitud
  const desdePortal = s ? {
    portal_client_id: s.portal_client_id || null, client_name: s.client_name || inicial.client_name || '',
    portal_project_id: s.portal_project_id || null, project_name: s.project_name || '',
    labels_text: (s.items || []).map((i) => i.code).join('\n'),
  } : {}
  return <Formulario inicial={{ ...VACIA, ...inicial, ...desdePortal }} opts={opts} onClose={onClose} onSaved={onSaved} />
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
  const quitarFiltros = () => { setQLive(''); setQ(''); setResp(''); setCat(''); if (MAS_FILTROS.some(([k]) => k === chip)) setChip('open'); setPage(0) }
  const todasMarcadas = filas.length > 0 && filas.every((w) => sel.has(w.id))
  const marcar = (id) => setSel((s) => { const n = new Set(s); n.has(id) ? n.delete(id) : n.add(id); return n })
  const marcarTodas = () => setSel(todasMarcadas ? new Set() : new Set(filas.map((w) => w.id)))
  const elegidas = filas.filter((w) => sel.has(w.id))

  const exportar = (lista) => {
    const esc = csvCell
    const cab = ['Expediente', 'Recepción', 'Cliente', 'Proyecto', 'Etiquetas', 'Categoría', 'Urgencia', 'Responsable', 'Estado', 'Motivo de cierre', 'Quién paga', 'Ticket', 'Última actividad']
    const lineas = lista.map((w) => [w.code, fecha(w.received_at), w.client_name, w.project_name, w.labels_count, w.category, opts?.urgencies?.[w.urgency] || 'No disponible',
      w.responsible_name, opts?.statuses?.[w.status], w.close_reason ? motivoDe(opts, w.close_reason) : '', (w.coverages || []).map((c) => COB_CORTO[c] || c).join(' + '), w.ticket_code, fechaHora(w.last_activity_at)])
    const blob = new Blob(['﻿' + [cab.map(esc).join(';'), ...lineas.map((l) => l.map(esc).join(';'))].join('\r\n')], { type: 'text/csv;charset=utf-8' })
    const a = document.createElement('a')
    a.href = URL.createObjectURL(blob)
    a.download = `garantias-${hoy()}.csv`
    a.click()
    setTimeout(() => URL.revokeObjectURL(a.href), 1000)
  }

  const [loteCierre, setLoteCierre] = useState(null)   // {modo, status}: cerrar o reabrir las elegidas
  // Cambios de estado en lote: por defecto se avisa al cliente como en uno a mano; se puede callar.
  const [loteAvisar, setLoteAvisar] = useState(true)
  const hechoEnLote = (r) => {
    toast(r.changed ? `${r.changed} ${r.changed === 1 ? 'garantía cambiada' : 'garantías cambiadas'}${r.silenced ? ` · ${r.silenced} sin avisar al cliente` : ''}` : 'Ya estaban así: no ha cambiado nada')
    setSel(new Set()); setOtra((x) => x + 1)
  }
  const enLote = async (cambios, texto) => {
    const n = sel.size
    const deEstado = 'status' in cambios
    const message = 'Cada cambio queda en el historial de su garantía.' + (deEstado ? (loteAvisar
      ? ' Si la nueva fase tiene aviso, el cliente recibirá su correo.'
      : ' No se avisará al cliente: quedará anotado en cada una.') : '')
    if (!(await confirm({ title: `${texto} en ${n} ${n === 1 ? 'garantía' : 'garantías'}`, message, confirmText: 'Aplicar' }))) return
    const r = await api.bulkWarranties([...sel], deEstado ? { ...cambios, notify: loteAvisar } : cambios)
    if (!r.ok) { toast(r.error || 'No se pudo cambiar', 'err'); return }
    hechoEnLote(r)
  }

  const estadosActivos = Object.entries(opts?.status_meta || {}).filter(([, m]) => m.active).map(([k, m]) => ({ value: k, label: m.label, color: m.color }))
  const agentes = (opts?.agents || []).map((a) => ({ value: String(a.id), label: a.name }))

  return (
    <div className={`dev-card wl-card ${loading ? 'busy' : ''}`}>
      {loading && <div className="dev-progress" />}
      <div className="wi-bar" data-tour="wl-filtros">
        <div className="dev-search wl-search">
          <Icon.search />
          <input value={qLive} onChange={(e) => setQLive(e.target.value)} placeholder="Buscar expediente, cliente, proyecto o ticket…" />
          {qLive ? <button className="dev-x" onClick={() => setQLive('')} title="Borrar"><Icon.x className="ic-x" aria-hidden="true" /></button> : null}
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
      {loteCierre ? (
        <CierreModal modo={loteCierre.modo} opts={opts} onClose={() => setLoteCierre(null)}
          titulo={loteCierre.modo === 'cerrar' ? `Cerrar ${sel.size} ${sel.size === 1 ? 'garantía' : 'garantías'}` : `Reabrir las cerradas de las ${sel.size} elegidas`}
          estados={estadosActivos.filter((e) => cerradaEn(opts, e.value) === (loteCierre.modo === 'cerrar'))}
          inicial={{ status: loteCierre.status }}
          onOk={async (v) => {
            const r = await api.bulkWarranties([...sel], { status: v.status, close_reason: v.close_reason, close_resolution: v.close_resolution, reopen_reason: v.reopen_reason, notify: loteAvisar })
            if (!r.ok) { toast(r.error || 'No se pudo cambiar', 'err'); return false }
            hechoEnLote(r)
            return true
          }} />
      ) : null}
      {hueco && createPortal(<>
        <button className="dev-btn" disabled={!filas.length} onClick={() => exportar(filas)} title="Exportar a CSV lo que se ve"><Icon.download /> Exportar</button>
        {opts?.perms?.edit ? <button className="btn" onClick={onNew}><Icon.plus /> Nueva garantía</button> : null}
      </>, hueco)}

      {sel.size > 0 && opts?.perms?.edit && (
        <div className="wl-bulk">
          <b>{sel.size} {sel.size === 1 ? 'seleccionada' : 'seleccionadas'}</b>
          <span className="sep" />
          <Select sm value="" onChange={(v) => {
            if (cerradaEn(opts, v)) setLoteCierre({ modo: 'cerrar', status: v })
            else if (elegidas.some((w) => cerradaEn(opts, w.status))) setLoteCierre({ modo: 'reabrir', status: v })
            else enLote({ status: v }, `Poner «${opts?.statuses?.[v] || v}»`)
          }} options={[{ value: '', label: 'Cambiar estado…' }, ...estadosActivos]} />
          <label className="wl-bulk-avisar" title="Si la nueva fase tiene aviso por correo al cliente (Configuración › Avisos al cliente), se le manda igual que en un cambio a mano">
            <input type="checkbox" checked={loteAvisar} onChange={(e) => setLoteAvisar(e.target.checked)} /> Avisar al cliente
          </label>
          <span className="sep" />
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
              ? Array.from({ length: 6 }, (_, i) => <tr key={i} className="wl-skel">{Array.from({ length: 10 }, (_, j) => <td key={j}><span className="dev-skel" /></td>)}</tr>)
              : filas.map((w) => (
                <tr key={w.id} className={`wr-row ${sel.has(w.id) ? 'wl-on' : ''}`} {...filaClic(() => onOpen(w.id))}>
                  <td className="wl-ck" onClick={(e) => e.stopPropagation()}><input type="checkbox" checked={sel.has(w.id)} onChange={() => marcar(w.id)} /></td>
                  <td className="wl-c-code"><span className="wr-code">{w.code}</span><Alertas list={w.alerts} /></td>
                  <td className="wl-cli">
                    <b>{w.client_name || <span className="dev-mute">Sin cliente</span>}</b>
                    <span>{[w.project_name, w.category].filter(Boolean).join(' · ') || '—'}</span>
                  </td>
                  <td className="wl-c-tk">{w.ticket_code ? <span className="wl-tk">{w.ticket_code}</span> : <span className="dev-mute">—</span>}</td>
                  <td className="r wl-c-n">{w.labels_count ? <><b>{num(w.labels_count)}</b><span className="wl-m">{w.labels_count === 1 ? ' etiqueta' : ' etiquetas'}</span></> : <span className="dev-mute">—</span>}
                    {w.repeated ? <span className="wr-rep sm" title={`${w.repeated} ${w.repeated === 1 ? 'dispositivo ya ha estado' : 'dispositivos ya han estado'} en otra garantía`}><Icon.refresh /> {w.repeated}</span> : null}</td>
                  <td className="wl-c-resp">{w.responsible_name
                    ? <span className="wl-resp"><i>{iniciales(w.responsible_name)}</i>{w.responsible_name}</span>
                    : <span className="dev-mute">Sin asignar</span>}</td>
                  <td className="wl-c-st"><EstadoPill s={w.status} opts={opts} /></td>
                  <td className="wl-c-urg"><UrgPill u={w.urgency} opts={opts} /></td>
                  <td className="wl-c-cob">{w.coverages?.length
                    ? <span className="wl-cob">{w.coverages.map((c) => <em key={c} className={`c-${c}`}>{COB_CORTO[c] || c}</em>)}</span>
                    : <span className="dev-mute" title="Se decide al revisar las etiquetas">—</span>}</td>
                  <td className="wl-act" title={fechaHora(w.last_activity_at)}>{relativo(w.last_activity_at)}</td>
                </tr>
              ))}
          </tbody>
        </table>
        {data && !filas.length && !data.error && (
          !data.all ? (
            <Vacio icon={Icon.shield} title="Aún no hay garantías"
              actions={opts?.perms?.edit ? <button className="btn" onClick={onNew}><Icon.plus /> Nueva garantía</button> : null}>
              Cada garantía reúne las etiquetas del cliente, su revisión y los documentos. También se crean desde un ticket.
            </Vacio>
          ) : filtrando ? (
            <Vacio icon={Icon.search} title="Ninguna garantía coincide"
              actions={<button className="dev-btn" onClick={quitarFiltros}><Icon.x /> Quitar los filtros</button>}>
              {q ? <>No hay resultados para «{q}» con estos filtros.</> : 'Prueba con otros filtros o quítalos para ver todas.'}
            </Vacio>
          ) : (
            <Vacio icon={chip === 'open' ? Icon.check : Icon.shield} title={chip === 'open' ? 'No hay garantías abiertas' : chip === 'mine' ? 'No tienes garantías asignadas' : 'Nada en esta pestaña'}
              actions={<button className="dev-btn" onClick={() => { setChip(''); setPage(0) }}>Ver todas ({num(data.all)})</button>}>
              {chip === 'open' ? 'Todo al día: las demás están cerradas.' : 'Las demás garantías están en las otras pestañas.'}
            </Vacio>
          )
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

const REINCIDE = ['previous_warranty', 'recent_warranties']
const avisarReincidentes = (toast, codes) => {
  if (!codes?.length) return
  toast(`${codes.length === 1 ? `${codes[0]} ya estuvo` : `${codes.length} etiquetas ya estuvieron`} en otra garantía: lo verás en ${codes.length === 1 ? 'su tarjeta' : 'sus tarjetas'}.`, 'warn')
}
const esReincidencia = (a) => REINCIDE.includes(a.key)

/* El texto de un aviso con las garantías que nombra enlazadas (se abren en otra pestaña:
   quien está dando de alta o revisando no pierde lo que tiene a medias). `otras`: las
   garantías conocidas de la etiqueta, para los avisos guardados antes de llevar enlaces. */
function AvisoTexto({ a, otras }) {
  const links = a.links?.length ? a.links : (otras || []).map((o) => ({ id: o.id, code: o.code }))
  if (!links.length) return a.text
  const porCodigo = new Map(links.map((l) => [l.code, l.id]))
  const re = new RegExp(`(${links.map((l) => l.code.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')).join('|')})`, 'g')
  return a.text.split(re).map((t, i) => porCodigo.has(t)
    ? <a key={i} className="wr-av-link mono" href={`/agentes/garantias/${porCodigo.get(t)}`} target="_blank" rel="noopener" title={`Abrir la garantía ${t} en otra pestaña`}>{t}</a>
    : t)
}

function Avisos({ list, otras }) {
  if (!list?.length) return null
  return (
    <ul className="wr-avisos">
      {list.map((a, i) => <li key={i} className={`t-${a.tone}`}>{esReincidencia(a) ? <Icon.refresh /> : a.tone === 'info' ? <Icon.clock /> : <Icon.warn />}<span><AvisoTexto a={a} otras={otras} /></span></li>)}
    </ul>
  )
}

// Cortes del diagnóstico. Señal: los de dialprix (rojo <50, ámbar 50-59, verde ≥60).
const BATERIA_PILAS = 90     // por debajo: seguramente son las pilas
const SENAL_MALA = 50
const SENAL_JUSTA = 60
const RECIENTE_MIN = 60      // comunicó hace menos de esto (al comprobarla): «comunica bien»
const tonoSenal = (s) => (s < SENAL_MALA ? 'bad' : s < SENAL_JUSTA ? 'warn' : 'ok')

/*
 * Lo que dice ZKONG de una etiqueta, con pinzas: estar conectada no quiere decir que la
 * pantalla funcione. Nunca resuelve sola; propone y el técnico decide. Los minutos desde la
 * última comunicación se cuentan desde que se comprobó (no desde ahora).
 */
function diagnostico(r) {
  if (r.kind === 'ap' || r.lookup_state !== 'found') return null
  const ref = (r.checked_at && aFecha(r.checked_at)) || new Date()
  const min = r.last_commu_at ? (ref - aFecha(r.last_commu_at)) / 60000 : null
  if (r.battery != null && r.battery < BATERIA_PILAS) {
    return { tone: 'warn', key: 'pilas', corto: 'Seguramente las pilas', text: `Batería al ${r.battery} %: casi seguro son las pilas. Pide al cliente que las cambie (Beidongli, HenliMax o NSN) antes de que la envíe.` }
  }
  if (r.ap_signal != null && r.ap_signal < SENAL_MALA) {
    return { tone: 'warn', key: 'senal', corto: 'Llega mala señal', text: `Señal con la antena al ${r.ap_signal} %: llega mal. Suele ser la cobertura o la antena, no la etiqueta: mira dónde está colocada antes de pedir que la envíe.` }
  }
  if (!r.online || min == null || min > 24 * 60) {
    const d = r.last_commu_at ? Math.floor(min / 1440) : null
    return { tone: 'bad', key: 'muda', corto: 'Parece averiada', text: `No se conecta al Cloud${d ? ` desde hace ${d} ${d === 1 ? 'día' : 'días'}` : ''}. Lo normal es que esté averiada: pide al cliente que la envíe para revisarla.` }
  }
  if (min <= RECIENTE_MIN && (r.battery == null || r.battery >= BATERIA_PILAS) && (r.ap_signal == null || r.ap_signal >= SENAL_JUSTA)) {
    return { tone: 'info', key: 'bien', corto: 'Se conecta bien', text: 'Se conecta bien al Cloud, con batería y señal correctas. Aun así la pantalla puede fallar: pide una foto o fuérzala antes de pedir que la envíe.' }
  }
  return null
}

function Diagnostico({ r, onPilas }) {
  const d = diagnostico(r)
  if (!d) return null
  return (
    <p className={`wr-diag t-${d.tone}`}>
      {d.tone === 'info' ? <Icon.check /> : <Icon.warn />}<span><b>Qué indica el Cloud:</b> {d.text}</span>
      {d.key === 'pilas' && onPilas && r.resolution === 'pendiente'
        ? <button className="dev-btn" onClick={onPilas}>Marcar «Batería baja»</button> : null}
    </p>
  )
}

function Donde({ r }) {
  if (r.lookup_state === 'not_found') return <span className="wr-st bad">No aparece en el Cloud</span>
  if (r.lookup_state !== 'found') return <span className="wr-st warn">Sin comprobar</span>
  const d = dias(r.last_commu_at)
  return (
    <div className="wr-donde">
      <span><i className={`wr-dot ${r.online ? 'on' : ''}`} /><b>{r.server}</b> · {r.store_name || '—'}</span>
      <small>{r.online ? 'Conectada' : 'Desconectada'}{r.last_commu_at ? ` · comunicó ${haceDias(d)}` : ''}
        {r.battery != null ? <> · <span className={r.battery < BATERIA_PILAS ? 'wr-m-warn' : ''}>batería {r.battery} %</span></> : null}
        {r.ap_signal != null ? <> · <span className={`wr-m-${tonoSenal(r.ap_signal)}`}>señal {r.ap_signal} %</span></> : null}</small>
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
        {editar && r.aeme_ref ? <button className="icon-btn" title="Cancelar" onClick={() => setEditar(false)}><Icon.x className="ic-x" aria-hidden="true" /></button> : null}
      </div>
    )
  }
  return (
    <div className="wr-modelo">
      <span><b>{r.aeme_ref || r.server_model || '—'}</b>{r.model_manual ? <em className="wr-mano-tag">a mano</em> : null}
        {onPick ? <button className="icon-btn sm" title={r.aeme_ref ? 'Cambiar producto' : 'Elegir el producto del portal'} onClick={abrir}><Icon.pencil /></button> : null}</span>
      <small>{r.aeme_ref ? r.product_name || '' : r.server_model ? 'Modelo del Cloud · sin producto del portal' : ''}</small>
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
    if (esReincidencia(a)) return   // cada una nombra su garantía: se queda en su fila (arriba va el recuento)
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
  // Reincidentes: el recuento va el primero, aunque sea una sola.
  const rein = res.filter((r) => (r.warnings || []).some(esReincidencia)).length
  if (rein) grupos.unshift({ id: 'reincide', n: rein, tone: 'warn', rein: true, text: `${rein === 1 ? 'ya estuvo' : 'ya estuvieron'} en otra garantía: mira cómo acabó antes de darla por buena.` })
  const agrupado = (code, a) => dentro.has(a.key === 'silent' ? `${code}|silent` : `${code}|${a.key}|${a.text}`)
  return { grupos, agrupado }
}
const fmtDiaCorto = (s) => { const [y, m, d] = String(s).slice(0, 10).split('-'); return d && m && y ? `${d}/${m}/${y}` : s }

/* Códigos de una lista pegada (también de Excel: código y problema por fila). Lo mismo que
   WarrantyLabels::parseList en el servidor: el código lleva alguna cifra; el resto es texto. */
const normCodigo = (t) => t.replace(/[^A-Za-z0-9]/g, '').toUpperCase()
const esCodigo = (t) => { const c = normCodigo(t); return /\d/.test(c) && (/^[A-Z0-9]{9}$/.test(c) || /^[0-9A-F]{12}$/.test(c)) }
const pareceCodigo = (t) => { const c = normCodigo(t); return c.length >= 6 && c.length <= 20 && /\d/.test(c) }   // mal copiado
const trozos = (l) => l.split(/[\s,;]+/).filter(Boolean)
const codigosDe = (linea) => trozos(linea).filter(esCodigo).map(normCodigo)
const contarCodigos = (texto) => new Set(texto.split(/\r?\n/).flatMap(codigosDe)).size

/* Caja para pegar códigos y comprobarlos. El estado (texto, resultados, modelos
   elegidos a mano) lo guarda quien la usa: value / onChange. */
function Comprobador({ contexto, value, onChange, autoFocus, split }) {
  const toast = useToast()
  const [cargando, setCargando] = useState(false)
  const [filtro, setFiltro] = useState('')   // en dos columnas: todas | bad | warn | ok
  const v = value
  const set = (cambios) => onChange({ ...v, ...cambios })
  const lineas = contarCodigos(v.text)
  const conTexto = v.text.split(/\r?\n/).some((l) => codigosDe(l).length && trozos(l).some((t) => !esCodigo(t) && !pareceCodigo(t)))
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
  const quitar = (codes) => {
    const fuera = new Set(codes)
    // Una fila con su problema (Excel) se quita entera; una de solo códigos, código a código.
    const texto = v.text.split(/\r?\n/).map((l) => {
      const cs = codigosDe(l)
      if (!cs.some((c) => fuera.has(c))) return l
      if (trozos(l).every(esCodigo)) return trozos(l).filter((t) => !fuera.has(normCodigo(t))).join(' ')
      return cs.every((c) => fuera.has(c)) ? '' : l
    }).filter((l) => l.trim()).join('\n')
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
          <span className="lbl">Códigos <span className="hint">— uno por línea: etiqueta (9) o MAC de antena (12). Desde Excel, pega el código y el problema: se rellena lo que dice el cliente</span></span>
          <textarea className="mono" value={v.text} autoFocus={autoFocus} onChange={(e) => set({ text: e.target.value })}
            onKeyDown={(e) => { if (e.key === 'Enter' && (e.ctrlKey || e.metaKey)) comprobar() }}
            placeholder={'806301480\tPantalla rota\n806301834\tNo enciende\nA0A3B85B5F7F'} />
          {comprobado && v.invalid?.length ? <p className="wr-comp-bad">No son etiquetas ni antenas: <span className="mono">{v.invalid.join(', ')}</span></p> : null}
          <div className="wr-cs-f">
            <span className="dev-mute">{lineas ? `${lineas} ${lineas === 1 ? 'código' : 'códigos'}${conTexto ? ' con su problema' : ''}` : 'Ctrl+Enter para comprobar'}</span>
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
                  {grupos.map((g) => <li key={g.id} className={`t-${g.tone}`}>{g.rein ? <Icon.refresh /> : <Icon.warn />}<b>{g.n} {g.n === 1 ? 'etiqueta' : 'etiquetas'}</b><span>{g.text}</span></li>)}
                </ul>
              ) : null}
              <ul className="wr-cs-list">
                {vista.map((r) => (
                  <li key={r.code} className={`st-${estado(r)}`}>
                    <span className="wr-lbl-code mono">{r.code}{r.kind === 'ap' ? <em className="wr-kind">Antena</em> : null}</span>
                    <Donde r={r} />
                    {r.already ? <span className="wr-st bad">Ya está en esta garantía</span> : <Modelo r={r} onPick={r.blocked ? null : (ref, m) => elegir(r.code, ref, m)} />}
                    <button type="button" className="icon-btn sm" title="Quitar de la lista" onClick={() => quitar([r.code])}><Icon.x /></button>
                    {r.issue ? <p className="wr-cs-issue" title="Lo que dice el cliente (de la lista pegada)"><Icon.quote /> {r.issue}</p> : null}
                    {(() => {
                      const propios = (r.warnings || []).filter((a) => !agrupado(r.code, a))
                      return propios.length ? <p className="wr-cs-av">{propios.map((a, i) => <span key={i} className={`t-${a.tone}`}><AvisoTexto a={a} /></span>)}</p> : null
                    })()}
                  </li>
                ))}
                {!vista.length ? <li className="wr-cs-none">Ninguna en este filtro. <button type="button" className="st-link" onClick={() => setFiltro('')}>Ver todas</button></li> : null}
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
        placeholder={'Pega los códigos, uno por línea: etiquetas (9 caracteres) o MAC de antenas.\nDesde Excel puedes pegar el código y el problema:\n8E9749440\tPantalla rota\nA0A3B85B5F7F'} />
      <div className="wr-comp-f">
        <span className="dev-mute">{lineas ? `${lineas} ${lineas === 1 ? 'código' : 'códigos'}${conTexto ? ' con su problema' : ''}` : 'Etiquetas: código de 9 caracteres · Antenas: su MAC · desde Excel, con el problema al lado'}{comprobado ? '' : lineas ? ' · sin comprobar' : ''}</span>
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
                  {r.issue ? <p className="full wr-cs-issue"><Icon.quote /> {r.issue}</p> : null}
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
    <button type="button" className={`wr-res c-${ui.c}`} onClick={onClick || undefined} disabled={!onClick} title={onClick ? 'Revisar' : ''}>
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

/* La foto que lleva el código de barras: la frontal de la etiqueta y la trasera (MAC) de
   la antena. Se lee en el navegador y se compara con el dispositivo. */
const fotoConCodigo = (it, t) => (it.kind === 'ap' ? t === 'back' : t === 'front')
const lecturas = new Map()   // id de foto → promesa con los códigos leídos
const leerFoto = (id) => {
  if (!lecturas.has(id)) {
    lecturas.set(id, fetch(api.warrantyPhotoUrl(id)).then((r) => (r.ok ? r.blob() : null))
      .then((b) => (b ? leerCodigos(new File([b], 'foto', { type: b.type })) : [])).catch(() => []))
  }
  return lecturas.get(id)
}

function LecturaCodigo({ photo, code }) {
  const [leidos, setLeidos] = useState(null)
  useEffect(() => {
    let vivo = true
    setLeidos(null)
    leerFoto(photo.id).then((c) => { if (vivo) setLeidos(c) })
    return () => { vivo = false }
  }, [photo.id])
  if (leidos === null) return <em className="wr-slot-bc lee" title="Leyendo el código de barras…"><span className="spinner xs" /> Leyendo código</em>
  if (leidos.includes(code)) return <em className="wr-slot-bc ok" title={`El código de barras de la foto es ${code}`}><Icon.check /> Código {code}</em>
  if (leidos.length) return <em className="wr-slot-bc mal" title={`La foto es de ${leidos.join(', ')} y el dispositivo es ${code}: ¿foto equivocada?`}><Icon.warn /> Es de {leidos[0]}</em>
  return <em className="wr-slot-bc no" title="No se ha podido leer el código de barras de la foto. Compruébalo con la cámara del móvil o una app de escáner de códigos (Google Lens, la cámara del iPhone…). Si la foto está borrosa o con reflejos, pide otra.">No se lee el código</em>
}

/* Hueco de una foto: vacío acepta clic, arrastrar un fichero o una imagen del ticket, y
   pegar (Ctrl+V) cuando está activo; lleno enseña la miniatura con «quitar». Con `code`,
   la foto debe llevar ese código de barras y se comprueba. */
function FotoSlot({ label, photo, code, active, busy, onActivate, onFile, onSrc, onDelete }) {
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
            {code ? <LecturaCodigo photo={photo} code={code} /> : null}
          </>
        : <div className="wr-slot-empty">{busy ? <div className="spinner sm" /> : <Icon.upload />}<small>{busy ? 'Subiendo…' : active ? 'Suelta, pega o haz clic' : 'Añadir'}</small></div>}
      <span className="wr-slot-l">{label}</span>
      <input ref={input} type="file" accept="image/jpeg,image/png,image/webp,image/gif" hidden
        onChange={(e) => { const f = e.target.files?.[0]; e.target.value = ''; if (f) onFile(f) }} />
    </div>
  )
}

// «hace 2 años y 3 meses» desde una fecha AAAA-MM-DD.
function antiguedad(d) {
  const [a, m] = String(d).split('-').map(Number)
  const hoy = new Date()
  let meses = (hoy.getFullYear() - a) * 12 + (hoy.getMonth() + 1 - m)
  if (!(meses >= 0)) return ''
  const y = Math.floor(meses / 12); meses %= 12
  const txt = [y ? `${y} ${y === 1 ? 'año' : 'años'}` : '', meses ? `${meses} ${meses === 1 ? 'mes' : 'meses'}` : ''].filter(Boolean).join(' y ')
  return txt ? `hace ${txt}` : 'este mes'
}

/* Plazo de garantía de un dispositivo: la fecha de alta SIEMPRE (dato informativo, para
   tenerlo en cuenta al revisar) y, si está fuera de plazo, la marca. */
function Plazo({ t }) {
  if (!t) return <em className="wr-term alta none" title="El Cloud no da la fecha de alta de este dispositivo">Alta: sin fecha</em>
  const desde = t.source === 'manual' ? 'Instalada (fecha de la garantía)' : 'Alta en el Cloud'
  const tit = `${desde} el ${fecha(t.installed_at)} (${antiguedad(t.installed_at)}) · hardware hasta el ${fecha(t.hardware_until)} · pilas hasta el ${fecha(t.battery_until)}`
  return (
    <>
      <em className="wr-term alta" title={tit}>{t.source === 'manual' ? 'Instalada' : 'Alta'} {fecha(t.installed_at)}</em>
      {!t.hardware_ok ? <em className="wr-term out" title={tit}><Icon.clock /> Fuera de plazo</em>
        : !t.battery_ok ? <em className="wr-term bat" title={tit}>Pilas fuera de plazo</em> : null}
    </>
  )
}

/* En «Revisar»: cuándo se dio de alta (uno) o el rango de altas (varios). Solo informa. */
function AltaZkong({ items }) {
  const fechas = items.map((i) => i.term?.installed_at).filter(Boolean).sort()
  const sin = items.length - fechas.length
  if (!fechas.length) return <p className="wr-rev-alta dev-mute"><Icon.clock /> El Cloud no da la fecha de alta {items.length === 1 ? 'de este dispositivo' : 'de estos dispositivos'}.</p>
  const manual = items.some((i) => i.term?.source === 'manual')
  const que = manual ? 'Instalación (fecha de la garantía)' : 'Alta en el Cloud'
  return (
    <p className="wr-rev-alta"><Icon.clock /> <span>{que}: <b>{fechas[0] === fechas[fechas.length - 1] ? fecha(fechas[0]) : `del ${fecha(fechas[0])} al ${fecha(fechas[fechas.length - 1])}`}</b>
      {' '}<span className="dev-mute">({fechas[0] === fechas[fechas.length - 1] ? antiguedad(fechas[0]) : `la más antigua, ${antiguedad(fechas[0])}`}{sin ? ` · ${sin} sin fecha` : ''})</span></span></p>
  )
}

// preset: la solución elegida en la barra de selección. Un dispositivo que ya pide fotos
// abre directamente en las fotos.
function Revisar({ w, rv, diag, items: iniciales, preset, onClose, onSaved }) {
  const toast = useToast()
  const dlg = useDialog(() => cerrar(), 'Revisar dispositivos')
  const uno = iniciales.length === 1 ? iniciales[0] : null
  // Lo que se propone para una solución; si todos están fuera de plazo, lo que cubría la
  // garantía pasa a «Paga el cliente» (Configuración → Plazos).
  const fuera = iniciales.every((it) => it.term?.hardware_ok === false)
  const propuesto = (k) => { const c = rv.default_coverage?.[k] || ''; return c === 'garantia' && fuera && rv.out_of_term_to_client ? 'cliente' : c }
  const [items, setItems] = useState(iniciales)
  const [paso, setPaso] = useState(!preset && uno && pideFotos(rv, uno) ? 'fotos' : 'solucion')
  const [f, setF] = useState(() => {
    const resolution = preset || (uno && uno.resolution !== 'pendiente' ? uno.resolution : '')
    return {
      resolution, coverage: (!preset && uno?.coverage) || propuesto(resolution),
      note: uno?.resolution_note || '', broken_cases: uno?.broken_cases || [], battery_changed: !!uno?.battery_changed,
      zkong_decision: uno?.zkong_decision || 'decidir', zkong_issue: uno?.zkong_issue || '',
    }
  })
  // Quién paga se propone según la solución hasta que el técnico lo cambia a mano.
  const [covAMano, setCovAMano] = useState(!preset && !!uno?.coverage && uno.coverage !== propuesto(uno.resolution))
  const elegirSolucion = (k) => set({ resolution: k, ...(covAMano ? {} : { coverage: propuesto(k) }) })
  const [guardando, setGuardando] = useState(false)
  const [cambios, setCambios] = useState(false)
  const [galeria, setGaleria] = useState(w.ticket_id ? null : [])
  const [activo, setActivo] = useState(null)       // «itemId|tipo» al que van las fotos pegadas o elegidas
  const [subiendo, setSubiendo] = useState('')
  const set = (c) => setF((s) => ({ ...s, ...c }))
  // Las fotos las pide la solución ELEGIDA (aún sin guardar), no la que tenía guardada.
  const conFotos = rv.resolutions[f.resolution]?.photos ? items : []
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

  /*
   * Una solución con fotos no se guarda hasta tenerlas todas: primero las fotos y luego
   * «Guardar revisión». Si se sale antes, la etiqueta sigue como estaba (pendiente de
   * revisar); las fotos subidas se quedan para la próxima vez.
   */
  const guardar = async () => {
    if (!f.resolution) return
    if (res?.photos && pendientes > 0) { setActivo(null); setPaso('fotos'); return }
    setGuardando(true)
    const r = await api.reviewWarrantyItems({ id: w.id, item_ids: items.map((x) => x.id), ...f })
    setGuardando(false)
    if (!r.ok) { toast(r.error || 'No se pudo guardar', 'err'); return }
    setCambios(true)
    const nuevos = r.items.filter((x) => items.some((y) => y.id === x.id))
    setItems(nuevos)
    toast(`${nuevos.length === 1 ? nuevos[0].code : `${nuevos.length} dispositivos`}: ${res.label}`)
    onSaved(); onClose()
  }
  // Lo elegido aún no está guardado en alguno de los dispositivos.
  const sinGuardar = !!f.resolution && items.some((it) => it.resolution !== f.resolution)
  const cerrar = () => { if (cambios) onSaved(); onClose() }
  const pendientes = huecos.filter((h) => !h.lleno).length

  const toggleCaso = (k) => set({ broken_cases: f.broken_cases.includes(k) ? f.broken_cases.filter((x) => x !== k) : [...f.broken_cases, k] })
  const faltaAlgo = !f.resolution || !f.coverage || (f.resolution === 'danada' && !f.broken_cases.length) || (f.resolution === 'desechada' && !f.note.trim())
    || (rv.resolutions[f.resolution]?.zkong && f.zkong_decision === 'enviar' && !f.zkong_issue.trim())

  return (
    <div className="modal-bg wr-over" onClick={(e) => e.target.classList.contains('modal-bg') && cerrar()}>
      <div className={`modal wr-rev${paso === 'fotos' ? ' wide' : ''}`} {...dlg}>
        <div className="modal-h">
          <h3>{uno ? <>Revisar <span className="mono">{uno.code}</span></> : `Revisar ${items.length} dispositivos`}</h3>
          <button className="icon-btn" onClick={cerrar} title="Cerrar (Esc)"><Icon.x className="ic-x" aria-hidden="true" /></button>
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
            <AltaZkong items={items} />
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
                  <span className="dev-mute"> — {!f.coverage ? 'elígelo según el motivo' : f.coverage === propuesto(f.resolution) ? (fuera && propuesto(f.resolution) !== rv.default_coverage?.[f.resolution] ? 'fuera del plazo de garantía' : 'lo propuesto para esta solución') : 'elegido a mano'}</span></label>
                <div className="wr-seg">
                  {Object.entries(rv.coverages || {}).map(([k, l]) => (
                    <button key={k} type="button" className={f.coverage === k ? `on cov-${k}` : ''} onClick={() => { setCovAMano(k !== propuesto(f.resolution)); set({ coverage: k }) }}>{l}</button>
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
              <p className="dev-mute wr-fotos-tip">{w.ticket_id ? 'Elige un hueco y haz clic en una imagen del cliente, arrástrala, pega con Ctrl+V o sube un fichero.' : 'Haz clic en un hueco para subir la foto, arrástrala o pégala con Ctrl+V.'}
                {' '}Cada etiqueta lleva frontal, trasera con pilas y sin pilas; de la frontal (en las antenas, la trasera) se lee el código de barras para comprobar que es la suya.</p>
              {conFotos.map((it) => (
                <div key={it.id} className="wr-fotos-it">
                  <div className="wr-fotos-ith"><b className="mono">{it.code}</b>{it.kind === 'ap' ? <em className="wr-kind">Antena</em> : null}<span className="dev-mute">{productoDe(it)}</span>
                    <ResPill rv={rv} it={it} onClick={() => setPaso('solucion')} /></div>
                  <div className="wr-slots">
                    {tiposFoto(rv, it).map(([t, l]) => {
                      const key = `${it.id}|${t}`
                      return <FotoSlot key={t} label={l} code={fotoConCodigo(it, t) ? it.code : null} photo={(it.photos || []).find((p) => p.type === t)} active={activo === key} busy={subiendo === key}
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
                {guardando ? 'Guardando…' : res?.photos && pendientes ? <>Siguiente: fotos <Icon.arrowRight /></> : items.length > 1 ? `Marcar ${items.length}` : 'Guardar'}
              </button>
            </>
          ) : (
            <>
              <span className={`wr-fotos-sum${pendientes ? '' : ' ok'}`}>{pendientes
                ? <>Faltan {pendientes} {pendientes === 1 ? 'foto' : 'fotos'}{sinGuardar ? <small> · sin todas, sigue pendiente de revisar</small> : null}</>
                : <><Icon.check /> Todas las fotos</>}</span>
              {pendientes ? <button className={sinGuardar ? 'btn ghost' : 'btn'} onClick={cerrar}>{sinGuardar ? 'Salir sin guardar' : 'Seguir luego'}</button>
                : sinGuardar ? <button className="btn" disabled={guardando || faltaAlgo} onClick={guardar}>{guardando ? 'Guardando…' : <><Icon.check /> Guardar revisión</>}</button>
                : <button className="btn" onClick={cerrar}>Listo</button>}
            </>
          )}
        </div>
      </div>
    </div>
  )
}

/*
 * Estado de una etiqueta = su revisión (lo que decide el técnico), nunca lo que dice el
 * Cloud: «Sin revisar» en gris hasta que alguien la revisa. Lo del Cloud va aparte.
 */
function EstadoRev({ rv, it }) {
  const ui = RES_UI[it.resolution] || RES_UI.pendiente
  const Ic = ui.ic
  const f = pideFotos(rv, it) ? fotosDe(rv, it) : null
  return (
    <span className={`wr-estado c-${ui.c}${it.resolution === 'pendiente' ? ' pend' : ''}`}>
      <Ic /> {it.resolution === 'pendiente' ? 'Sin revisar' : rv?.resolutions?.[it.resolution]?.label || it.resolution}
      {f ? <em className={f.hay < f.total ? 'falta' : ''} title={f.hay < f.total ? 'Faltan fotos' : 'Fotos completas'}><Icon.image /> {f.hay}/{f.total}</em> : null}
    </span>
  )
}

// El botón que dice qué hacer con la etiqueta: «Revisar» si está pendiente, «Cambiar» si no.
function AccionRev({ it, onRevisar, sm }) {
  if (!onRevisar) return null
  return it.resolution === 'pendiente'
    ? <button className={`btn${sm ? ' sm' : ''} wr-revisar`} onClick={onRevisar}><Icon.eye /> Revisar</button>
    : <button className={`dev-btn${sm ? ' sm' : ''}`} onClick={onRevisar} title="Cambiar la revisión"><Icon.pencil /> Cambiar</button>
}

// El estado en el Cloud, corto: punto + conectada/desconectada/no aparece.
function CloudCorto({ r }) {
  if (r.lookup_state === 'not_found') return <span className="wr-cl bad"><i />No aparece en el Cloud</span>
  if (r.lookup_state !== 'found') return <span className="wr-cl warn"><i />Sin comprobar</span>
  const d = dias(r.last_commu_at)
  return <span className={`wr-cl ${r.online ? 'on' : 'off'}`} title={`${r.server} · ${r.store_name || '—'}${r.battery != null ? ` · batería ${r.battery} %` : ''}${r.ap_signal != null ? ` · señal ${r.ap_signal} %` : ''}`}>
    <i />{r.online ? 'Conectada' : 'Desconectada'}{r.last_commu_at ? <small> · {haceDias(d)}</small> : null}</span>
}

/*
 * Lo que contó el cliente desde el portal: lo que se repite en varias etiquetas se dice
 * una vez (con a cuáles afecta); en cada etiqueta solo queda lo que es solo suyo.
 */
function agruparDichos(items) {
  const m = new Map()
  items.forEach((i) => { const t = (i.reported_issue || '').trim(); if (t) m.set(t, [...(m.get(t) || []), i.code]) })
  return [...m.entries()].map(([texto, codes]) => ({ texto, codes })).sort((a, b) => b.codes.length - a.codes.length)
}

/* «Opción elegida — comentario»: lo que eligió el cliente en el portal y, debajo, en negrita
   y cursiva, lo que escribió él. */
function Dicho({ texto }) {
  const i = texto.indexOf(' — ')
  const sel = i >= 0 ? texto.slice(0, i).trim() : texto
  const com = i >= 0 ? texto.slice(i + 3).trim() : ''
  return <><span className="wr-dicho-sel">{sel}</span>{com ? <span className="wr-dicho-com">“{com.replace(/^["“”]+|["“”]+$/g, '')}”</span> : null}</>
}

function DiceCliente({ grupos, total }) {
  const [todo, setTodo] = useState(false)
  if (!grupos.length) return null
  const ver = todo ? grupos : grupos.slice(0, 3)
  return (
    <div className="wr-dice-box">
      <h4><Icon.quote /> Lo que dice el cliente</h4>
      <ul>
        {ver.map((g) => (
          <li key={g.texto}>
            <p><Dicho texto={g.texto} /></p>
            <small>{g.codes.length === total && total > 1 ? `Todas las etiquetas (${total})` : g.codes.length > 4 ? `${g.codes.length} etiquetas: ${g.codes.slice(0, 4).join(', ')}…` : g.codes.join(', ')}</small>
          </li>
        ))}
      </ul>
      {grupos.length > 3 ? <button className="wf-link" onClick={() => setTodo((x) => !x)}>{todo ? 'Ver menos' : `Ver los ${grupos.length} comentarios`}</button> : null}
    </div>
  )
}

/* Las fotos de la etiqueta dentro de su tarjeta: la frontal grande (es la que lleva el
   código) y al lado las demás que haya, en el orden del tipo. Clic: se abre en grande. */
const CORTO_FOTO = { front: 'Frontal', back: 'Trasera', back_batteries: 'Con pilas', back_no_batteries: 'Sin pilas' }
function FotosCarta({ i, rv }) {
  const fotos = i.photos || []
  if (!fotos.length) return null
  const tipos = tiposFoto(rv, i)
  const orden = tipos.map(([t]) => t)
  const etiqueta = (t) => tipos.find(([k]) => k === t)?.[1] || t
  const vistas = [...fotos].sort((a, b) => {
    const ia = orden.indexOf(a.type), ib = orden.indexOf(b.type)
    return (ia < 0 ? 99 : ia) - (ib < 0 ? 99 : ib)
  })
  const principal = vistas.find((f) => fotoConCodigo(i, f.type)) || vistas[0]
  const resto = vistas.filter((f) => f !== principal)
  const delCliente = fotos.every((f) => f.source)
  const Foto = ({ f, big }) => (
    <a className={`wr-cf${big ? ' big' : ''}`} href={api.warrantyPhotoUrl(f.id)} target="_blank" rel="noreferrer" title={`${etiqueta(f.type)}: ver en grande`}>
      <img src={api.warrantyPhotoUrl(f.id)} alt={`${etiqueta(f.type)} de ${i.code}`} loading="lazy" />
      <span>{CORTO_FOTO[f.type] || etiqueta(f.type)}</span>
    </a>
  )
  return (
    <div className="wr-card-fotos">
      <Foto f={principal} big />
      <div className="wr-cf-side">
        <small className="wr-cf-t"><Icon.image /> {delCliente ? 'Fotos del cliente' : 'Fotos'} · {fotos.length}{tipos.length ? `/${tipos.length}` : ''}</small>
        {resto.length ? <div className="wr-cf-rest">{resto.map((f) => <Foto key={f.id} f={f} />)}</div> : null}
      </div>
    </div>
  )
}

/* En la lista: la frontal en miniatura y, si hay más, cuántas. Clic: se abre en grande. */
function FotosFila({ i, rv }) {
  const fotos = i.photos || []
  if (!fotos.length) return <span className="dev-mute">—</span>
  const tipos = tiposFoto(rv, i)
  const etiqueta = (t) => tipos.find(([k]) => k === t)?.[1] || t
  const f = fotos.find((x) => fotoConCodigo(i, x.type)) || fotos[0]
  const todas = fotos.map((x) => CORTO_FOTO[x.type] || etiqueta(x.type)).join(', ')
  return (
    <a className="wr-lt-foto" href={api.warrantyPhotoUrl(f.id)} target="_blank" rel="noreferrer"
      title={`${fotos.every((x) => x.source) ? 'Fotos del cliente' : 'Fotos'}: ${todas}. Clic: ver la ${(CORTO_FOTO[f.type] || etiqueta(f.type)).toLowerCase()} en grande`}>
      <img src={api.warrantyPhotoUrl(f.id)} alt={`${etiqueta(f.type)} de ${i.code}`} loading="lazy" />
      {fotos.length > 1 ? <em>+{fotos.length - 1}</em> : null}
    </a>
  )
}

function EtiquetaCarta({ i, rv, p, sel, propio, onSel, onFicha, onRevisar, onPilas, onModelo, onQuitar }) {
  const ui = RES_UI[i.resolution] || RES_UI.pendiente
  return (
    <li className={`wr-card c-${ui.c}${i.resolution === 'pendiente' ? ' pend' : ''}${sel ? ' sel' : ''}`}>
      <div className="wr-card-h">
        {onSel ? <input type="checkbox" className="wr-pick" checked={sel} onChange={onSel} aria-label={`Seleccionar ${i.code}`} /> : null}
        <button className="link mono wr-card-code" title="Ver ficha e historial" onClick={onFicha}>{i.code}</button>
        {i.kind === 'ap' ? <em className="wr-kind">Antena</em> : null}
        {i.other_warranties?.length ? <button className="wr-rep" onClick={onFicha} title={`También en: ${i.other_warranties.map((o) => o.code).join(', ')}`}><Icon.refresh /> En {i.other_warranties.length + 1} garantías</button> : null}
        <span className="spacer" />
        {onQuitar ? <button className="icon-btn sm" title="Quitar de la garantía" onClick={onQuitar}><Icon.trash /></button> : null}
      </div>
      <div className="wr-card-st">{rv ? <EstadoRev rv={rv} it={i} /> : null}<AccionRev it={i} onRevisar={onRevisar} sm /></div>
      {propio ? <blockquote className="wr-dice" title="Lo que contó el cliente de esta etiqueta"><Dicho texto={propio} /></blockquote> : null}
      <FotosCarta i={i} rv={rv} />
      <dl className="wr-card-dl">
        <div><dt>Producto</dt><dd><Modelo r={i} onPick={onModelo} /></dd></div>
        <div><dt>Cloud</dt><dd><Donde r={i} /></dd></div>
        <div><dt>Alta</dt><dd className="wr-card-alta"><Plazo t={i.term} /></dd></div>
      </dl>
      <Diagnostico r={i} onPilas={onPilas} />
      <Avisos list={i.warnings} otras={i.other_warranties} />
      {rv ? <ResDetalle rv={rv} it={i} /> : null}
    </li>
  )
}

function EtiquetaFila({ i, rv, sel, propio, comun, onSel, onFicha, onRevisar, onQuitar }) {
  const ui = RES_UI[i.resolution] || RES_UI.pendiente
  const d = diagnostico(i)
  const avisos = (i.warnings || []).filter((a) => a.tone !== 'info' && !esReincidencia(a))   // la reincidencia ya la dice ↻ junto al código
  return (
    <tr className={`c-${ui.c}${i.resolution === 'pendiente' ? ' pend' : ''}${sel ? ' sel' : ''}`}>
      {onSel !== undefined ? <td className="wr-lt-ck">{onSel ? <input type="checkbox" className="wr-pick" checked={sel} onChange={onSel} aria-label={`Seleccionar ${i.code}`} /> : null}</td> : null}
      <td className="wr-lt-code">
        <button className="link mono" title="Ver ficha e historial" onClick={onFicha}>{i.code}</button>
        {i.kind === 'ap' ? <em className="wr-kind">Antena</em> : null}
        {i.other_warranties?.length ? <button className="wr-rep" onClick={onFicha} title={`Reincidente, también en: ${i.other_warranties.map((o) => o.code).join(', ')}. Pulsa para ver su historial`}><Icon.refresh /> {i.other_warranties.length + 1}</button> : null}
      </td>
      <td className="wr-lt-fotos"><FotosFila i={i} rv={rv} /></td>
      <td className="wr-lt-prod"><b>{productoDe(i)}</b>{i.term && !i.term.hardware_ok ? <em className="wr-term out"><Icon.clock /> Fuera de plazo</em> : null}</td>
      <td className="wr-lt-cloud">
        <CloudCorto r={i} />
        {d ? <span className={`wr-lt-pista t-${d.tone}`} title={d.text}>{d.tone === 'info' ? <Icon.check /> : <Icon.warn />}{d.corto}</span> : null}
        {avisos.length ? <span className="wr-lt-pista t-warn" title={avisos.map((a) => a.text).join('\n')}><Icon.warn />{avisos.length === 1 ? '1 aviso' : `${avisos.length} avisos`}</span> : null}
      </td>
      <td className="wr-lt-dice">{propio ? <span className="wr-lt-dicho" title={propio}><Dicho texto={propio} /></span> : comun ? <span className="dev-mute" title={comun}>Ver arriba</span> : <span className="dev-mute">—</span>}</td>
      <td className="wr-lt-rev">{rv ? <EstadoRev rv={rv} it={i} /> : null}</td>
      <td className="wr-lt-acc"><AccionRev it={i} onRevisar={onRevisar} sm />{onQuitar ? <button className="icon-btn sm" title="Quitar de la garantía" onClick={onQuitar}><Icon.trash /></button> : null}</td>
    </tr>
  )
}

const VISTA_KEY = 'wr_etiquetas_vista'
const leerVista = () => { try { return localStorage.getItem(VISTA_KEY) } catch { return null } }

function Productos({ w, items, opts, onReload, onChanged }) {
  const toast = useToast()
  const confirm = useConfirm()
  const [panel, setPanel] = useState(false)
  // «Pegar desde Excel»: lee el portapapeles (el navegador puede pedir permiso); si no deja, abre el cuadro para pegar a mano.
  const pegarExcel = async () => {
    let t = ''
    try { t = (await navigator.clipboard?.readText?.()) || '' } catch { /* sin permiso */ }
    setPanel(true)
    if (t.trim()) { setComp({ ...COMP_VACIO, text: t.replace(/\r/g, '').trim() }); toast('Lista pegada: pulsa «Comprobar y añadir»') }
    else toast('Pega la columna de Excel en el cuadro (Ctrl+V)')
  }
  const [comp, setComp] = useState(COMP_VACIO)
  const [ocupado, setOcupado] = useState('')
  const [ficha, setFicha] = useState(null)
  const [filtro, setFiltro] = useState('')
  const [rev, setRev] = useState('')               // filtro por revisión
  const [sel, setSel] = useState(() => new Set())
  const [revisar, setRevisar] = useState(null)     // { items, preset }
  // Lista o cartas: lo que eligió el agente; si no, cartas con pocas y lista con muchas.
  const [vista, setVistaSt] = useState(() => leerVista() || (items.length > 6 ? 'lista' : 'cartas'))
  const setVista = (v) => { setVistaSt(v); try { localStorage.setItem(VISTA_KEY, v) } catch { /* sin almacenamiento */ } }
  const rv = opts?.review
  const p = opts?.perms || {}

  const anadir = async () => {
    setOcupado('add')
    const r = await api.addWarrantyLabels(w.id, comp.text, comp.manual)
    setOcupado('')
    if (!r.ok) { toast(r.error || 'Error', 'err'); return }
    if (r.added.length) toast(`${r.added.length} ${r.added.length === 1 ? 'etiqueta añadida' : 'etiquetas añadidas'}`)
    avisarReincidentes(toast, r.repeat)
    if (r.skipped.length) toast(`${r.skipped.length} no ${r.skipped.length === 1 ? 'entró' : 'entraron'}: ${r.skipped.slice(0, 3).map((s) => `${s.code} (${s.reason})`).join('; ')}${r.skipped.length > 3 ? '…' : ''}`, 'err')
    setComp(COMP_VACIO); setPanel(false); onReload(); onChanged()
  }
  const recomprobar = async () => {
    setOcupado('recheck')
    const r = await api.recheckWarrantyLabels(w.id)
    setOcupado('')
    if (r.ok) { toast('Etiquetas comprobadas en el Cloud'); onReload(); onChanged() } else toast(r.error || 'Error', 'err')
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
    const cab = ['Código', 'Tipo', 'Referencia AEME', 'Producto', 'Modelo en el servidor', 'Servidor', 'Tienda', 'Conectada', 'Última comunicación', 'Avisos', 'Revisión', 'Detalle de la revisión', 'Fotos', 'Otras garantías', 'Instalación', 'Garantía hasta', 'En plazo']
    const lineas = items.map((i) => [i.code, i.kind === 'ap' ? 'Antena' : 'Etiqueta', i.aeme_ref, i.product_name, i.server_model, i.server, i.store_name, i.online == null ? '' : i.online ? 'Sí' : 'No', fechaHora(i.last_commu_at), (i.warnings || []).map((a) => a.text).join(' | '),
      rv?.resolutions?.[i.resolution]?.label || i.resolution,
      [i.battery_changed ? 'Pilas cambiadas' : '', i.zkong_decision ? rv?.zkong?.[i.zkong_decision] : '', i.zkong_issue, (i.broken_cases || []).map((k) => rv?.broken_cases?.[k] || k).join(', '), i.resolution_note].filter(Boolean).join(' · '),
      pideFotos(rv, i) ? `${fotosDe(rv, i).hay}/${fotosDe(rv, i).total}` : '', (i.other_warranties || []).map((o) => o.code).join(', '), i.term ? fecha(i.term.installed_at) : '', i.term ? fecha(i.term.hardware_until) : '', !i.term ? 'Sin fecha' : i.term.hardware_ok ? 'Sí' : 'No'])
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
  const repetidos = items.filter((i) => i.other_warranties?.length).length
  const fueraPlazo = items.filter((i) => i.term?.hardware_ok === false).length
  const porRes = {}
  items.forEach((i) => { porRes[i.resolution] = (porRes[i.resolution] || 0) + 1 })
  const revisadas = items.length - (porRes.pendiente || 0)
  const sinFotos = items.filter((i) => faltanFotos(rv, i)).length
  const listado = items.filter((i) => (!filtro || (filtro === 'avisos' ? estadoEtiqueta(i) !== 'ok' : filtro === 'repetidos' ? i.other_warranties?.length > 0 : filtro === 'plazo' ? i.term?.hardware_ok === false : productoDe(i) === filtro))
    && (!rev || (rev === 'fotos' ? faltanFotos(rv, i) : i.resolution === rev)))
  const nuevas = comp.results && comp.checked === comp.text ? comp.results.filter((r) => !r.blocked && !r.already).length : null
  // Lo que contó el cliente: lo repetido arriba una vez; en cada etiqueta, solo lo suyo.
  const comunes = agruparDichos(items).filter((g) => g.codes.length > 1)
  const txtComun = new Set(comunes.map((g) => g.texto))
  const propioDe = (i) => { const t = (i.reported_issue || '').trim(); return t && !txtComun.has(t) ? t : '' }
  const comunDe = (i) => { const t = (i.reported_issue || '').trim(); return t && txtComun.has(t) ? t : '' }

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
        {items.length > 0 && (
          <div className="dc-seg wr-vista" role="group" aria-label="Vista">
            <button className={vista === 'lista' ? 'on' : ''} onClick={() => setVista('lista')} title="Lista"><Icon.list /> Lista</button>
            <button className={vista === 'cartas' ? 'on' : ''} onClick={() => setVista('cartas')} title="Cartas"><Icon.dashboard /> Cartas</button>
          </div>
        )}
        {items.length && p.edit ? <button className="dev-btn" disabled={!!ocupado} onClick={recomprobar} title="Vuelve a mirar en el Cloud si cada etiqueta se conecta, su batería, señal y tienda"><Icon.refresh /> {ocupado === 'recheck' ? 'Comprobando…' : 'Comprobar en el Cloud'}</button> : null}
        {items.length ? <button className="dev-btn" onClick={exportar} title="Descargar en CSV"><Icon.download /> CSV</button> : null}
        {p.edit ? <button className="btn" onClick={() => setPanel((x) => !x)}><Icon.plus /> Añadir etiquetas</button> : null}
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
          <DiceCliente grupos={comunes} total={items.length} />
          {rv && (
            <div className="wr-revbar">
              <div className="wr-revbar-h">
                <b>Revisión</b>
                <span>{revisadas} de {items.length} revisadas</span>
                {sinFotos ? <button className="wr-revbar-warn" onClick={() => setRev('fotos')}><Icon.image /> Faltan fotos en {sinFotos}</button> : null}
              </div>
              {p.review ? (
                <p className={`wr-revbar-next${revisadas === items.length && !sinFotos ? ' ok' : ''}`}>
                  {revisadas < items.length
                    ? <><Icon.arrowRight /> <span>Quedan <b>{items.length - revisadas}</b> por revisar. Pulsa <b>Revisar</b> en cada una{items.length > 1 ? <>, o márcalas y elige el resultado para todas a la vez</> : null}.</span>
                        {porRes.pendiente && rev !== 'pendiente' ? <button className="dev-btn sm" onClick={() => setRev('pendiente')}>Ver solo las pendientes</button> : null}</>
                    : sinFotos ? <><Icon.image /> <span>Todas revisadas; faltan fotos en {sinFotos}.</span></>
                    : <><Icon.check /> <span>Todas revisadas. Ya puedes preparar los <b>Documentos</b>.</span></>}
                </p>
              ) : null}
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
            {repetidos ? <button className={filtro === 'repetidos' ? 'on' : ''} onClick={() => setFiltro(filtro === 'repetidos' ? '' : 'repetidos')} title="Han estado también en otras garantías"><Icon.refresh /> Repetidos ({repetidos})</button> : null}
            {fueraPlazo ? <button className={filtro === 'plazo' ? 'on' : ''} onClick={() => setFiltro(filtro === 'plazo' ? '' : 'plazo')} title="Instaladas hace más del plazo de garantía"><Icon.clock /> Fuera de plazo ({fueraPlazo})</button> : null}
            {Object.entries(porProducto).sort((a, b) => b[1] - a[1]).map(([k, n]) => (
              <button key={k} className={filtro === k ? 'on' : ''} onClick={() => setFiltro(filtro === k ? '' : k)}>{k} ×{n}</button>
            ))}
          </div>
          {rv && p.review && listado.length > 0 && vista === 'cartas' && (
            <label className="wr-selall"><input type="checkbox" checked={todasVisibles} onChange={marcarVisibles} />
              {todasVisibles ? 'Quitar la selección' : `Seleccionar ${listado.length === items.length ? 'todas' : `las ${listado.length} que se ven`}`}</label>
          )}
          {!listado.length ? (
            <Vacio compact icon={Icon.search} title="Ninguna con este filtro"
              actions={<button className="dev-btn sm" onClick={() => { setFiltro(''); setRev('') }}>Ver todas ({items.length})</button>} />
          ) : null}
          {listado.length > 0 && vista === 'cartas' ? (
            <ul className="wr-cards">
              {listado.map((i) => (
                <EtiquetaCarta key={i.id} i={i} rv={rv} p={p} sel={sel.has(i.id)} propio={propioDe(i)}
                  onSel={rv && p.review ? () => marcar(i.id) : null} onFicha={() => setFicha(i.code)}
                  onRevisar={rv && p.review ? () => setRevisar({ items: [i] }) : null}
                  onPilas={rv && p.review ? () => setRevisar({ items: [i], preset: 'bateria' }) : null}
                  onModelo={p.edit ? (ref) => cambiarModelo(i, ref) : null} onQuitar={p.edit ? () => quitar(i) : null} />
              ))}
            </ul>
          ) : listado.length > 0 ? (
            <div className="wr-lt-wrap">
              <table className="wr-lt">
                <thead><tr>
                  {rv && p.review ? <th className="wr-lt-ck"><input type="checkbox" className="wr-pick" checked={todasVisibles} onChange={marcarVisibles} aria-label="Seleccionar todas" /></th> : null}
                  <th>Etiqueta</th><th>Fotos</th><th>Producto</th><th>Cloud</th><th>Qué dice el cliente</th><th>Revisión</th><th />
                </tr></thead>
                <tbody>
                  {listado.map((i) => (
                    <EtiquetaFila key={i.id} i={i} rv={rv} sel={sel.has(i.id)} propio={propioDe(i)} comun={comunDe(i)}
                      onSel={rv && p.review ? () => marcar(i.id) : undefined} onFicha={() => setFicha(i.code)}
                      onRevisar={rv && p.review ? () => setRevisar({ items: [i] }) : null} onQuitar={p.edit ? () => quitar(i) : null} />
                  ))}
                </tbody>
              </table>
            </div>
          ) : null}
          {elegidas.length > 0 && (
            <div className="wr-selbar">
              <b title="Marcar como…">{elegidas.length} {elegidas.length === 1 ? 'seleccionada' : 'seleccionadas'}:</b>
              {RAPIDAS.map((k) => {
                const Ic = RES_UI[k].ic
                return <button key={k} className={`wr-res c-${RES_UI[k].c}`} onClick={() => setRevisar({ items: elegidas, preset: k })}><Ic /> <span>{rv.resolutions[k]?.label}</span></button>
              })}
              <button className="dev-btn" onClick={() => setRevisar({ items: elegidas })}>Otra…</button>
              <button className="icon-btn" title="Quitar la selección" onClick={() => setSel(new Set())}><Icon.x className="ic-x" aria-hidden="true" /></button>
            </div>
          )}
          {items[0]?.checked_at ? <p className="dev-mute wr-prod-foot">Comprobadas por última vez {fechaHora(items.reduce((a, i) => (i.checked_at > a ? i.checked_at : a), ''))}.</p> : null}
        </>
      )}
      {!items.length && !panel ? (
        <Vacio icon={Icon.tag} title="Sin etiquetas"
          actions={p.edit ? <>
            <button className="btn" onClick={() => setPanel(true)}><Icon.plus /> Añadir etiquetas</button>
            <button className="dev-btn" onClick={pegarExcel} title="Una columna con el código y, al lado, el problema"><Icon.copy /> Pegar desde Excel</button>
          </> : null}>
          {p.edit ? 'Añade los códigos de las etiquetas o las MAC de las antenas: se comprueban en el Cloud y luego se revisan una a una. Desde Excel puedes pegar el código con su problema.' : 'Todavía no hay etiquetas en esta garantía.'}
        </Vacio>
      ) : null}
      {ficha && <FichaDispositivo code={ficha} servers={[]} onClose={() => setFicha(null)} onOpen={setFicha} />}
      {revisar && <Revisar w={w} rv={rv} diag={opts?.diagnosis} items={revisar.items} preset={revisar.preset} onClose={() => setRevisar(null)} onSaved={trasRevisar} />}
    </>
  )
}

const CANAL = { correo: ['Correo', Icon.mail], whatsapp: ['WhatsApp', Icon.whatsapp], adjunto: ['Adjunto', Icon.file] }

/**
 * Barra de fases de la ficha: los estados en su orden, dónde está ahora y cuántos días
 * ha pasado en cada uno (sumando si volvió a él). Los que no ha tocado quedan apagados:
 * el recorrido no es fijo y una garantía puede saltarse fases.
 */
const dd = (n) => (n == null ? null : n < 1 ? '<1 d' : `${Math.round(n)} d`)
function PasosFase({ ph }) {
  const caja = useRef(null)
  useEffect(() => {
    // En pantallas estrechas la barra se desplaza: que se vea el paso actual.
    const id = requestAnimationFrame(() => {
      const c = caja.current, el = c?.querySelector('.now')
      if (el && c.scrollWidth > c.clientWidth) c.scrollLeft = el.offsetLeft - c.clientWidth / 2 + el.offsetWidth / 2
    })
    return () => cancelAnimationFrame(id)
  }, [ph])
  const actual = ph.steps.find((s) => s.state === 'current')
  return (
    <div className="wf-steps-w">
      <div className="wf-steps-h">
        <b>Recorrido</b>
        {actual ? <span>{ph.closed ? 'Terminó en' : 'Ahora en'} <b>{actual.label}</b> · días en cada fase</span> : null}
      </div>
      <ol className="wf-steps" ref={caja} aria-label="Fases de la garantía">
        {ph.steps.map((s) => {
          const now = s.state === 'current'
          const dias = now && !s.closed ? s.now : s.days
          const tit = [s.label,
            now && !s.closed ? `ahora: ${s.now} ${s.now === 1 ? 'día' : 'días'}${s.warn != null ? ` (aviso a los ${s.warn}, crítico a los ${s.crit})` : ''}` : null,
            s.days != null ? `antes: ${Math.round(s.days * 10) / 10} días${s.visits > 1 ? ` en ${s.visits} veces` : ''}` : null,
            s.state === 'todo' && !s.closed ? 'no ha pasado por aquí' : null].filter(Boolean).join(' · ')
          return (
            <li key={s.key} className={`${s.state === 'done' ? 'done' : now ? 'now' : 'todo'}${now && s.level ? ` lv-${s.level}` : ''}${s.closed ? ' end' : ''}`}
              style={s.color ? { '--sc': s.color } : null} title={tit} aria-current={now ? 'step' : undefined}>
              <i className="bar" />
              <span className="lb">{s.state === 'done' ? <Icon.check /> : null}{s.label}</span>
              <span className="dy">
                {now && !s.closed ? <>{s.now === 0 ? 'Desde hoy' : `${s.now} ${s.now === 1 ? 'día' : 'días'}`}{s.level ? <em>{s.level === 'crit' ? 'Atascada' : 'Aviso'}</em> : null}</>
                  : dias != null ? <>{dd(dias)}{s.visits > 1 ? <small> ×{s.visits}</small> : null}</>
                  : now ? 'Terminada' : '—'}
                {now && !s.closed && s.days ? <small> (+{dd(s.days)} antes)</small> : null}
              </span>
            </li>
          )
        })}
      </ol>
    </div>
  )
}

/**
 * «⋯» de la cabecera de la ficha: lo que se usa poco (editar) y lo que no tiene vuelta
 * atrás (borrar) va aquí, para que la cabecera tenga una sola acción principal.
 */
function MasAcciones({ code, onEdit, onDelete, seguimiento = null, onCopiar, onRenovar }) {
  const [pos, setPos] = useState(null)
  const btn = useRef(null)
  const menu = useRef(null)

  useEffect(() => {
    if (!pos) return
    menu.current?.querySelector('[role="menuitem"]')?.focus()
    const fuera = (e) => { if (!menu.current?.contains(e.target) && !btn.current?.contains(e.target)) setPos(null) }
    const tecla = (e) => {
      if (e.key === 'Escape') { e.stopPropagation(); setPos(null); btn.current?.focus() }
      if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
        e.preventDefault()
        const items = [...menu.current.querySelectorAll('[role="menuitem"]')]
        const i = items.indexOf(document.activeElement)
        items[(i + (e.key === 'ArrowDown' ? 1 : -1) + items.length) % items.length]?.focus()
      }
    }
    const cerrar = () => setPos(null)
    document.addEventListener('mousedown', fuera)
    document.addEventListener('keydown', tecla)
    window.addEventListener('scroll', cerrar, true)
    window.addEventListener('resize', cerrar)
    return () => {
      document.removeEventListener('mousedown', fuera)
      document.removeEventListener('keydown', tecla)
      window.removeEventListener('scroll', cerrar, true)
      window.removeEventListener('resize', cerrar)
    }
  }, [pos])

  const abrir = () => {
    if (pos) { setPos(null); return }
    const b = btn.current.getBoundingClientRect()
    const right = Math.max(8, window.innerWidth - b.right)
    setPos(b.bottom + 320 > window.innerHeight ? { bottom: window.innerHeight - b.top + 4, right } : { top: b.bottom + 4, right })
  }
  const elegir = (fn) => () => { setPos(null); fn() }

  return (
    <>
      <button ref={btn} type="button" className={`icon-btn wf-more ${pos ? 'on' : ''}`} onClick={abrir}
        title="Más acciones" aria-label="Más acciones" aria-haspopup="menu" aria-expanded={!!pos}>
        <Icon.dots />
      </button>
      {pos && createPortal(
        <div ref={menu} className="dev-acc-menu wf-more-menu" role="menu" aria-label={`Acciones de ${code}`} style={pos}>
          {onEdit ? (
            <button role="menuitem" onClick={elegir(onEdit)}>
              <Icon.pencil /><span><b>Editar los datos</b><small>Cliente, proyecto, fechas…</small></span>
            </button>
          ) : null}
          {seguimiento ? (
            <>
              <button role="menuitem" onClick={elegir(onCopiar)}>
                <Icon.copy /><span><b>Copiar enlace de seguimiento</b><small>{seguimiento.url ? 'Para que el cliente vea en qué fase está' : 'Se crea ahora: el cliente ve en qué fase está'}</small></span>
              </button>
              {seguimiento.url ? (
                <a role="menuitem" href={`${seguimiento.url}?previa=1`} target="_blank" rel="noreferrer" onClick={() => setPos(null)}>
                  <Icon.eye /><span><b>Ver lo que ve el cliente</b><small>Se abre en otra pestaña (no cuenta como visita)</small></span>
                </a>
              ) : null}
              {seguimiento.url ? (
                <button role="menuitem" onClick={elegir(onRenovar)}>
                  <Icon.refresh /><span><b>Nuevo enlace de seguimiento</b><small>El anterior deja de funcionar</small></span>
                </button>
              ) : null}
            </>
          ) : null}
          {onDelete ? (
            <button role="menuitem" className="bad" onClick={elegir(onDelete)}>
              <Icon.trash /><span><b>Borrar garantía</b><small>Pide confirmación antes de borrarla.</small></span>
            </button>
          ) : null}
        </div>,
        document.body,
      )}
    </>
  )
}

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
      <div className="modal fc-modal" role="dialog" aria-modal="true" aria-label="Fotos del cliente">
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
            <button className="icon-btn" onClick={onClose} title="Cerrar (Esc)"><Icon.x className="ic-x" aria-hidden="true" /></button>
          </div>
        </div>
        <div className="modal-body fc-body">
          {!lista.length ? <Vacio icon={Icon.image} title={filtro === 'cliente' ? 'El cliente no ha mandado imágenes' : 'No hay imágenes'}
            actions={filtro === 'cliente' && fotos.length ? <button className="dev-btn" onClick={() => { setFiltro('todas'); setSel(null) }}>Ver todas ({fotos.length})</button> : null}>
            {filtro === 'cliente' ? 'En «Todas» están también las que ha subido el equipo.' : 'Ni el cliente ni el equipo han adjuntado imágenes en los tickets de este cliente.'}</Vacio> : grupos.map(([tid, xs]) => (
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
            <button className="fc-cerrar" onClick={() => setSel(null)} title="Volver (Esc)"><Icon.x className="ic-x" aria-hidden="true" /></button>
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

const CAMPOS = { created: 'Garantía creada', close: 'Cierre', reopen: 'Reapertura', steps: 'Pasos', items: 'Etiquetas', item_model: 'Modelo de una etiqueta', review: 'Revisión de dispositivos', zkong: 'ZKONG', documents: 'Documentos', shipment: 'Envío del cliente', client_notice: 'Aviso al cliente', cloud_label: 'Etiqueta de almacén', first_contact_at: 'Primera fecha de contacto', received_at: 'Fecha de recepción', installed_at: 'Fecha de instalación', closed_at: 'Fecha de cierre', client_name: 'Cliente', project_name: 'Proyecto', category: 'Categoría', urgency: 'Urgencia', status: 'Estado', responsible_id: 'Responsable', ticket_id: 'Ticket', description: 'Descripción' }

/* Envíos que el cliente avisó desde el portal («Ya lo he enviado»): para esperarlo y
   localizar el paquete. No es obligatorio, así que puede no haber ninguno. */
function EnviosCliente({ list }) {
  if (!list?.length) return null
  const fecha = (s) => new Date(`${s}T12:00:00`).toLocaleDateString('es-ES', { day: 'numeric', month: 'short', year: 'numeric' })
  return (
    <section className="wf-card wf-envios">
      <header><h3><Icon.truck /> Envío del cliente</h3></header>
      <ul>
        {list.map((s, i) => (
          <li key={i}>
            <b>Enviado el {fecha(s.shipped_on)}</b>
            <span>{[s.carrier, s.tracking ? `Seguimiento ${s.tracking}` : 'Sin seguimiento'].filter(Boolean).join(' · ')}</span>
            <span className="dev-mute">{s.packages} {s.packages === 1 ? 'paquete' : 'paquetes'}{s.units ? ` · ${s.units} dispositivos` : ''}{s.email ? ` · avisó ${s.email}` : ''}</span>
            {s.note ? <span className="wf-envio-nota">{s.note}</span> : null}
          </li>
        ))}
      </ul>
    </section>
  )
}

/* Pasos por tipo de cliente (Configuración): los de todas y los de su categoría o cliente. */
/*
 * Lo que escribió el cliente, línea a línea: «Clave: valor» con la clave en negrita, las
 * líneas «- …» como lista (los dispositivos de la solicitud del portal) y, si es largo,
 * recogido con «Ver todo».
 */
function MensajeCliente({ text }) {
  const [todo, setTodo] = useState(false)
  // Sin líneas en blanco: los correos guardan cada línea como un párrafo (una en blanco entre medias).
  const lineas = String(text || '').split('\n').filter((l) => l.trim() !== '')
  const largo = lineas.length > 12 || text.length > 900
  const vista = largo && !todo ? lineas.slice(0, 10) : lineas
  const bloques = []
  vista.forEach((l, i) => {
    const t = l.trim()
    if (/^[-•*]\s+/.test(t)) {
      const item = t.replace(/^[-•*]\s+/, '')
      const ult = bloques[bloques.length - 1]
      if (ult?.tipo === 'lista') ult.items.push(item); else bloques.push({ tipo: 'lista', items: [item], k: i })
      return
    }
    const kv = t.match(/^([A-ZÁÉÍÓÚÑ][\wÁÉÍÓÚÑáéíóúñ ().]{0,28}):\s*(.*)$/u)
    bloques.push(kv ? { tipo: 'kv', clave: kv[1], valor: kv[2], k: i } : { tipo: 'texto', t, k: i })
  })
  return (
    <div className="wf-msg">
      {bloques.map((b) => b.tipo === 'lista' ? <ul key={b.k}>{b.items.map((x, j) => <li key={j}>{x}</li>)}</ul>
        : b.tipo === 'kv' ? <p key={b.k}><b>{b.clave}:</b> {b.valor}</p>
        : <p key={b.k}>{b.t}</p>)}
      {largo ? <button type="button" className="ws-txt wf-msg-mas" onClick={() => setTodo((v) => !v)}>{todo ? 'Ver menos' : 'Ver todo'}</button> : null}
    </div>
  )
}

/*
 * Etiqueta de almacén: la etiqueta de la tienda del almacén (Cloud) que enseña la pegatina
 * de la garantía. Se escribe o se escanea su código y se empareja con el producto de la
 * garantía; se puede cambiar por otra o quitar. Solo si está configurada (Servidores ZKONG).
 */
function EtiquetaAlmacen({ w, enabled, usual, puede, onChanged }) {
  const toast = useToast()
  const confirm = useConfirm()
  const [code, setCode] = useState('')
  const [abierto, setAbierto] = useState(false)
  const [otra, setOtra] = useState(false)          // sin etiqueta: escribir otra en vez de la de siempre
  const [ocupado, setOcupado] = useState('')       // 'pair' | 'remove' | 'scan'
  const foto = useRef(null)
  const actual = w.cloud_label || ''
  const limpio = code.toUpperCase().replace(/[^A-Z0-9]/g, '')
  const valido = /^[A-Z0-9]{9}$/.test(limpio)

  if (!enabled && !actual) return null

  const emparejar = async (c = limpio) => {
    if (!/^[A-Z0-9]{9}$/.test(c)) { toast('El código de una etiqueta tiene 9 letras o números', 'err'); return }
    // Si la enseña otra garantía, se le quita a aquella: se avisa antes.
    const enOtra = usual?.warranty_code && usual.code === c ? usual.warranty_code : null
    if (enOtra) {
      if (!(await confirm({ title: 'Mover la etiqueta de almacén', message: `${c} enseña ahora la garantía ${enOtra}. Se desvinculará de ${enOtra} y pasará a enseñar ${w.code}.${actual && actual !== c ? ` ${actual} dejará de enseñar ${w.code}.` : ''}`, confirmText: `Mover a ${w.code}` }))) return
    } else if (actual && c !== actual && !(await confirm({ title: 'Cambiar la etiqueta de almacén', message: `${actual} dejará de enseñar ${w.code} y la enseñará ${c}.`, confirmText: 'Cambiar' }))) return
    setOcupado('pair')
    const r = await api.warrantyCloudLabel(w.id, c).catch(() => ({ ok: false, error: 'No se pudo conectar' }))
    setOcupado('')
    if (!r.ok) { toast(r.error || 'No se pudo emparejar', 'err'); return }
    toast(enOtra ? `Etiqueta ${r.cloud_label} movida de ${enOtra} a ${w.code}` : `Etiqueta ${r.cloud_label} emparejada con ${w.code}`)
    setCode(''); setAbierto(false); setOtra(false)
    onChanged?.()
  }
  const quitar = async () => {
    if (!(await confirm({ title: 'Quitar la etiqueta de almacén', message: `${actual} dejará de enseñar la pegatina de ${w.code}.`, danger: true, confirmText: 'Quitar' }))) return
    setOcupado('remove')
    const r = await api.warrantyCloudLabel(w.id, '', true).catch(() => ({ ok: false, error: 'No se pudo conectar' }))
    setOcupado('')
    if (!r.ok) { toast(r.error || 'No se pudo quitar', 'err'); return }
    toast(`Etiqueta ${actual} quitada`)
    onChanged?.()
  }
  // Foto con la cámara (o una imagen): se lee su código de barras y se empareja.
  const escanear = async (e) => {
    const f = e.target.files?.[0]
    e.target.value = ''
    if (!f) return
    setOcupado('scan')
    const leidos = (await leerCodigos(f)).filter((x) => /^[A-Z0-9]{9}$/.test(x))
    setOcupado('')
    if (!leidos.length) { toast('No se ha leído ningún código de etiqueta en la foto', 'err'); return }
    setCode(leidos[0]); setAbierto(true)
    emparejar(leidos[0])
  }

  const form = (
    <div className="wf-alm-f">
      <input className="mono" value={code} onChange={(e) => setCode(e.target.value)} placeholder="Código de la etiqueta"
        maxLength={20} autoFocus={abierto} disabled={!!ocupado}
        onKeyDown={(e) => { if (e.key === 'Enter' && valido) emparejar() ; if (e.key === 'Escape') { setAbierto(false); setCode('') } }} aria-label="Código de la etiqueta de almacén" />
      <button className="dev-btn" type="button" title="Hacer una foto a la etiqueta y leer su código" disabled={!!ocupado} onClick={() => foto.current?.click()}>
        <Icon.scan /> {ocupado === 'scan' ? 'Leyendo…' : 'Escanear'}</button>
      <button className="btn" type="button" disabled={!valido || !!ocupado} onClick={() => emparejar()}>{ocupado === 'pair' ? 'Emparejando…' : 'Emparejar'}</button>
      <input ref={foto} type="file" accept="image/*" capture="environment" hidden onChange={escanear} />
    </div>
  )

  return (
    <section className="wf-card wf-alm">
      <header><h3><Icon.tag /> Etiqueta de almacén</h3>{actual ? <span className="wf-alm-st ok">Emparejada</span> : <span className="wf-alm-st">Sin etiqueta</span>}</header>
      <div className="wf-alm-b">
        {actual ? (
          <>
            <div className="wf-alm-act">
              <code>{actual}</code>
              <small>Enseña la pegatina de {w.code}{w.cloud_label_at ? ` · desde ${fechaHora(w.cloud_label_at)}` : ''}</small>
            </div>
            {puede && enabled && !abierto ? (
              <div className="wf-alm-acc">
                <button className="ws-txt" disabled={!!ocupado} onClick={() => setAbierto(true)}>Cambiar</button>
                <button className="ws-txt danger" disabled={!!ocupado} onClick={quitar}>{ocupado === 'remove' ? 'Quitando…' : 'Quitar'}</button>
              </div>
            ) : null}
            {abierto ? form : null}
          </>
        ) : puede && usual?.code && !otra ? (
          <>
            <p className="dev-mute">Elige la etiqueta que enseñará la pegatina de esta garantía (se actualiza sola con cada cambio).</p>
            <div className="wf-alm-op">
              <button className="wf-alm-usual" disabled={!!ocupado} onClick={() => emparejar(usual.code)}>
                <span><small>La de siempre</small><code>{usual.code}</code></span>
                <em>{ocupado === 'pair' ? 'Emparejando…' : usual.warranty_code ? `Ahora en ${usual.warranty_code}` : 'Libre'}</em>
              </button>
              <button className="dev-btn" disabled={!!ocupado} onClick={() => setOtra(true)}>Otra</button>
            </div>
            {usual.warranty_code ? <p className="wf-alm-aviso"><Icon.info /> Al usarla aquí se desvincula de {usual.warranty_code}.</p> : null}
          </>
        ) : puede ? (
          <>
            <p className="dev-mute">Escribe o escanea el código de una etiqueta de la tienda del almacén: enseñará la pegatina de esta garantía y se actualiza sola con cada cambio.</p>
            {form}
            {usual?.code && otra ? <button className="ws-txt" onClick={() => { setOtra(false); setCode('') }}>Usar la de siempre ({usual.code})</button> : null}
          </>
        ) : <p className="dev-mute">Aún no tiene etiqueta de almacén.</p>}
      </div>
    </section>
  )
}

const PASO_AUTO = { cloud: 'al comprobar las etiquetas en el Cloud', revision: 'al revisar todas las etiquetas' }

function Pasos({ w, listas, puede, onChanged }) {
  const toast = useToast()
  const [ls, setLs] = useState(listas)
  const [ocupado, setOcupado] = useState('')
  useEffect(() => setLs(listas), [listas])
  if (!ls?.length) return null
  const total = ls.reduce((a, l) => a + l.steps.length, 0)
  const hechos = ls.reduce((a, l) => a + l.steps.filter((s) => s.done_at).length, 0)
  const marcar = async (l, s) => {
    setOcupado(`${l.id}|${s.id}`)
    const r = await api.warrantyStep(w.id, l.id, s.id, !s.done_at)
    setOcupado('')
    if (!r.ok) { toast(r.error || 'No se pudo guardar', 'err'); return }
    setLs(r.steps)
    onChanged?.()
  }
  return (
    <section className="wf-card wf-pasos">
      <header><h3><Icon.check /> Pasos</h3><span className={`wf-pasos-n${hechos === total ? ' ok' : ''}`}>{hechos}/{total}</span></header>
      {ls.map((l) => (
        <div key={l.id} className="wf-pasos-l">
          {ls.length > 1 ? <h4>{l.name}</h4> : null}
          <ul>
            {l.steps.map((s) => (
              <li key={s.id} className={s.done_at ? 'done' : ''}>
                <label className="wr-check">
                  <input type="checkbox" checked={!!s.done_at} disabled={!puede || ocupado === `${l.id}|${s.id}`} onChange={() => marcar(l, s)} />
                  <span>{s.text}</span>
                </label>
                {s.done_at ? <small>{s.done_by || 'Automático'} · {fechaHora(s.done_at)}</small>
                  : s.auto ? <small className="wf-pasos-auto"><Icon.bolt /> Se marca solo {PASO_AUTO[s.auto] || ''}</small> : null}
              </li>
            ))}
          </ul>
        </div>
      ))}
    </section>
  )
}

function Ficha({ id, opts, onBack, onEdit, onChanged, onOpenTicket, onGoGuides, user = null, onOpenWarranty = null }) {
  const [chat, setChat] = useState(null)           // ticket abierto encima de la garantía
  const [meta, setMeta] = useState(null)
  const tabsRef = useRef(null)
  const p = opts?.perms || {}
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
  const [cierre, setCierre] = useState(null)            // {modo, status}: formulario de cerrar o reabrir

  const cargar = useCallback(() => api.warranty(id).then((r) => setD(r.ok ? r : { error: r.error || 'No se pudo cargar' })), [id])
  useEffect(() => { setD(null); cargar() }, [cargar])
  const wid = d?.warranty?.id
  const tid = d?.warranty?.ticket_id
  useEffect(() => {
    setFotos(null)
    if (wid && tid) api.warrantyTicketImages(wid, true).then((r) => setFotos(!r.ok ? [] : r.visible === false ? false : r.images))
  }, [wid, tid])

  if (d === null) return <SkFicha />
  if (d.error) return <><button className="wr-back" onClick={onBack}><Icon.chevron style={{ transform: 'rotate(90deg)' }} /> Garantías</button><div className="dev-error"><Icon.warn /> {d.error}</div></>

  const w = d.warranty
  const abierta = !cerradaEn(opts, w.status)
  // Sin recepción (aún no ha llegado nada) cuenta desde que se abrió.
  const diasAbierta = dias(w.received_at || w.created_at, abierta ? null : w.closed_at)
  const diasSin = dias(w.last_activity_at)
  const stale = abierta && diasSin != null && diasSin >= (opts?.stale_days || 15)

  const guardarCon = async (cambios, msg) => {
    const r = await api.saveWarranty({ ...w, responsible_id: w.responsible_id || '', urgency: w.urgency || '', ticket_code: w.ticket_code || '', first_contact_at: w.first_contact_at || '', installed_at: w.installed_at || '', closed_at: (w.closed_at || '').slice(0, 10), ...cambios })
    if (r.ok) { toast(msg); cargar(); onChanged() } else toast(r.error || 'Error', 'err')
  }
  const cerrar = () => setCierre({ modo: 'cerrar', status: opts?.status_closed || 'cerrada' })
  // Del selector de estado: cerrar o reabrir pasan por su formulario; lo demás se guarda sin más.
  const cambiarEstado = (v) => {
    if (v === w.status) return
    const va = cerradaEn(opts, v)
    if (va !== !abierta) setCierre({ modo: va ? 'cerrar' : 'reabrir', status: v })
    else guardarCon({ status: v }, `Estado: ${opts?.statuses?.[v] || v}`)
  }
  const borrar = async () => {
    if (!(await confirm({ title: 'Borrar garantía', message: `¿Borrar ${w.code} con todo su seguimiento e historial? No se puede deshacer.`, danger: true, confirmText: 'Borrar' }))) return
    const r = await api.deleteWarranty(w.id)
    if (r.ok) { toast('Garantía borrada'); onChanged(); onBack() } else toast(r.error || 'Error', 'err')
  }
  // Seguimiento para el cliente: copia el enlace (lo crea la primera vez). renew: uno nuevo,
  // y el anterior deja de funcionar (si se ha mandado a quien no debía).
  const copiarSeguimiento = async (renew = false) => {
    if (renew && !(await confirm({ title: 'Nuevo enlace de seguimiento', message: 'El enlace que tenga el cliente dejará de funcionar y tendrás que mandarle el nuevo. ¿Seguir?', confirmText: 'Crear otro enlace' }))) return
    let url = renew ? null : w.tracking?.url
    if (!url) {
      const r = await api.warrantyTracking(w.id, renew)
      if (!r.ok) { toast(r.error || 'No se pudo crear el enlace', 'err'); return }
      url = r.tracking.url
      cargar()
    }
    try { await navigator.clipboard.writeText(url); toast(renew ? 'Enlace nuevo copiado: el anterior ya no funciona' : 'Enlace de seguimiento copiado: pégalo en el correo o el WhatsApp') }
    catch { window.prompt('Copia el enlace de seguimiento', url) }
  }
  const enviarNota = async () => {
    if (!nota.body.trim()) return
    setEnviando(true)
    const r = await api.addWarrantyNote(w.id, nota.type, nota.body)
    setEnviando(false)
    if (r.ok) { setNota({ type: nota.type, body: '' }); cargar(); onChanged() } else toast(r.error || 'Error', 'err')
  }

  // El ticket se abre aquí mismo, encima de la garantía; al cerrarlo se sigue en ella.
  const puedeChat = !!(user && onOpenTicket)
  const abrirTicket = puedeChat ? (tid) => { setChat(tid); if (!meta) api.ticketMeta().then(setMeta) } : onOpenTicket
  const ticket = w.ticket_code
    ? (abrirTicket && w.ticket_visible !== false ? <button className="wr-tklink mono" title={w.ticket_subject || 'Abrir ticket'} onClick={() => abrirTicket(w.ticket_id)}>{w.ticket_code}</button> : <span className="mono">{w.ticket_code}</span>)
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

  // Con pocas etiquetas el historial va debajo de ellas; con muchas, en el lateral.
  const pocas = d.items.length < 5
  const historial = <Historial w={w} d={d} opts={opts} puede={!!p.edit} hist={hist} setHist={setHist} nota={nota} setNota={setNota}
    notaRef={notaRef} enviando={enviando} enviarNota={enviarNota} linea={linea} mensajes={mensajes} />

  // Documentos se abren cuando todas las etiquetas están revisadas (o la garantía cerrada).
  const docsLock = abierta && (!d.items.length || revisadas < d.items.length)
  const irA = (t) => { setTab(t); tabsRef.current?.scrollIntoView({ behavior: 'smooth', block: 'start' }) }
  const sinFotos = d.items.filter((i) => faltanFotos(opts?.review, i)).length
  // Qué toca ahora, en una frase.
  const siguiente = !abierta ? null
    : !d.items.length ? { tone: 'warn', text: 'añade las etiquetas que manda el cliente.', btn: 'Ir a etiquetas', go: () => irA('productos') }
    : revisadas < d.items.length ? { tone: 'warn', text: `revisa ${d.items.length - revisadas === 1 ? 'la etiqueta que falta' : `las ${d.items.length - revisadas} etiquetas que faltan`}.`, btn: 'Ir a etiquetas', go: () => irA('productos') }
    : sinFotos ? { tone: 'warn', text: `faltan fotos en ${sinFotos} ${sinFotos === 1 ? 'etiqueta' : 'etiquetas'}.`, btn: 'Ir a etiquetas', go: () => irA('productos') }
    : { tone: 'ok', text: 'todas las etiquetas revisadas: prepara los documentos y cierra la garantía.', btn: 'Ir a documentos', go: () => irA('documentos') }

  return (
    <>
      <button className="wr-back" onClick={onBack}><Icon.chevron style={{ transform: 'rotate(90deg)' }} /> Garantías</button>

      <div className="wf-head" data-tour="wf-head">
        <div className="wf-h-top">
          <div className="wf-id">
            <div className="wf-t">
              <span className="wf-code">{w.code}</span>
              <EstadoPill s={w.status} opts={opts} />
              <UrgPill u={w.urgency} opts={opts} />
              {w.alerts?.length ? <Alertas list={w.alerts} full /> : stale ? <span className="wr-flag"><Icon.warn /> Sin actividad {diasSin} días</span> : null}
            </div>
            <h2>{w.client_name || <span className="dev-mute">Sin cliente</span>}</h2>
            <p className="wf-sub">{w.project_name || <span className="dev-mute">Sin proyecto</span>}{w.category ? <> · {w.category}</> : null}</p>
          </div>
          <div className="wf-acts">
            {w.ticket_id && puedeChat && w.ticket_visible ? (
              <button className="btn wf-chat-btn" onClick={() => abrirTicket(w.ticket_id)} title={`Abrir la conversación del ticket ${w.ticket_code || ''}`}>
                <Icon.chat /> Conversación{mensajes.length ? <em>{mensajes.length}</em> : null}
              </button>
            ) : null}
            {w.ticket_id && fotos !== false ? (
              <button className="dev-btn" onClick={() => setVerFotos(null)} disabled={!fotos?.length} title={fotos && !fotos.length ? 'El cliente no ha mandado imágenes en sus tickets' : 'Lo que ha mandado el cliente por correo, WhatsApp o adjunto'}>
                <Icon.image /> Fotos del cliente{fotos === null ? null : <em className="fc-n">{fotos.filter((x) => x.from === 'cliente').length}</em>}
              </button>
            ) : null}
            {p.edit || p.delete ? <MasAcciones code={w.code} onEdit={p.edit ? () => onEdit(w) : null} onDelete={p.delete ? borrar : null}
              seguimiento={p.edit && w.tracking?.active ? w.tracking : null} onCopiar={() => copiarSeguimiento(false)} onRenovar={() => copiarSeguimiento(true)} /> : null}
          </div>
        </div>

        <div className="wf-facts">
          <div className="wf-fact">
            <span>Estado</span>
            {p.edit ? (
              <div className="wf-fact-st">
                <Select sm value={w.status} onChange={cambiarEstado} options={estados} />
                {abierta
                  ? <button className="btn sm dark" onClick={cerrar}><Icon.lock /> Cerrar</button>
                  : <button className="btn sm ghost" onClick={() => setCierre({ modo: 'reabrir', status: opts?.status_reopen || 'en_curso' })}><Icon.refresh /> Reabrir</button>}
              </div>
            ) : <b>{opts?.statuses?.[w.status] || w.status}</b>}
            {abierta && w.status_changed_at ? (() => {
              const n = Math.max(0, Math.floor((Date.now() - new Date(String(w.status_changed_at).replace(' ', 'T')).getTime()) / 86400000))
              return <small className="wf-fact-since" title={`En este estado desde el ${String(w.status_changed_at).slice(0, 10).split('-').reverse().join('/')}`}>
                {n === 0 ? 'Desde hoy' : `Desde hace ${n} ${n === 1 ? 'día' : 'días'}`}</small>
            })() : null}
          </div>
          <div className="wf-fact">
            <span>Responsable</span>
            <b className="wl-resp">{w.responsible_name ? <><i>{iniciales(w.responsible_name)}</i>{w.responsible_name}</> : <span className="wf-warn">Sin responsable</span>}</b>
          </div>
          <div className="wf-fact">
            <span>Etiquetas revisadas</span>
            <b>{d.items.length ? <>{revisadas}/{d.items.length}<i className="wf-mini"><i style={{ width: `${(revisadas / d.items.length) * 100}%` }} /></i></> : <span className="dev-mute">Ninguna aún</span>}</b>
          </div>
          <div className="wf-fact">
            <span>{abierta ? 'Abierta' : 'Duró'}</span>
            <b className={abierta && diasAbierta >= 30 ? 'wf-warn' : ''}>{diasAbierta ?? 0} {diasAbierta === 1 ? 'día' : 'días'}</b>
          </div>
          <div className="wf-fact">
            <span>Última actividad</span>
            <b className={stale ? 'wf-warn' : ''}>{haceDias(diasSin)}</b>
          </div>
          {ticket ? <div className="wf-fact"><span>Ticket</span><b>{ticket}</b></div> : null}
          {w.tracking?.url ? (
            <div className="wf-fact" title={w.tracking.expired ? 'El enlace ha caducado: crea uno nuevo desde «⋯»' : w.tracking.expires ? `El enlace vale hasta el ${fecha(w.tracking.expires)}` : 'El enlace vale mientras la garantía siga abierta'}>
              <span>Seguimiento del cliente</span>
              <b className={`wf-fact-trk${w.tracking.expired ? ' wf-warn' : ''}`}>
                {w.tracking.expired ? 'Enlace caducado'
                  : w.tracking.views ? <><Icon.eye /> Visto {w.tracking.views} {w.tracking.views === 1 ? 'vez' : 'veces'}</> : <span className="dev-mute">Sin abrir todavía</span>}
              </b>
              {!w.tracking.expired && w.tracking.last_view ? <small className="wf-fact-since">Última, {haceDias(dias(w.tracking.last_view))}</small> : null}
            </div>
          ) : null}
          {w.rating ? (
            <div className="wf-fact" title={w.rating.comment ? `“${w.rating.comment}”` : 'Sin comentario'}>
              <span>Encuesta del cliente</span>
              <b className={`wf-rate r${w.rating.score}`}><i>{w.rating.score}</i>{w.rating.label}{w.rating.comment ? <Icon.chat /> : null}</b>
            </div>
          ) : null}
        </div>

        {w.phases?.steps?.length ? <PasosFase ph={w.phases} /> : null}

        {siguiente ? (
          <div className={`wf-next t-${siguiente.tone}`}>
            <Icon.arrowRight /><span><b>Siguiente:</b> {siguiente.text}</span>
            {siguiente.go ? <button className="dev-btn sm" onClick={siguiente.go}>{siguiente.btn}</button> : null}
          </div>
        ) : null}
      </div>

      <div className="wf-grid">
        <div className="wf-main">
          <section className="wf-card">
            <header><h3><Icon.doc /> Qué le pasa al cliente</h3>
              {w.ticket_id && puedeChat && w.ticket_visible ? (
                <button className="dev-btn wf-open-chat" onClick={() => abrirTicket(w.ticket_id)} title={w.ticket_subject || 'Abrir la conversación del ticket'}>
                  <Icon.chat /> Abrir conversación <span className="mono">{w.ticket_code}</span>
                </button>
              ) : ticket ? <span className="dev-mute wf-orig">Ticket {ticket}</span> : null}
            </header>
            {w.description ? <p className="wf-desc">{w.description}</p> : <p className="dev-mute wf-desc-none">Sin descripción.{p.edit ? <> <button className="wf-link" onClick={() => onEdit(w)}>Añadirla</button></> : null}</p>}
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

          <section className="wf-card wf-tabs-card" data-tour="wf-tabs" ref={tabsRef}>
            <div className="wr-tabs" role="tablist">
              <button className={tab === 'productos' ? 'on' : ''} onClick={() => setTab('productos')}><Icon.tag /> Etiquetas{d.items.length ? ` (${d.items.length})` : ''}
                {d.items.length ? <em className={revisadas === d.items.length ? 'ok' : ''}>{revisadas}/{d.items.length}</em> : null}</button>
              <button className={`${tab === 'documentos' ? 'on' : ''}${docsLock ? ' locked' : ''}`} onClick={() => setTab('documentos')}
                title={docsLock ? 'Se desbloquea al revisar todas las etiquetas' : ''}>{docsLock ? <Icon.lock /> : <Icon.file />} Documentos</button>
            </div>
            <div className="wf-tab-body">
              {tab === 'productos' && <Productos w={w} items={d.items} opts={opts} onReload={cargar} onChanged={onChanged} />}
              {tab === 'documentos' && (docsLock ? (
                <div className="wf-lock">
                  <span className="wf-lock-ic"><Icon.lock /></span>
                  <h4>Primero, las etiquetas</h4>
                  <p>Los documentos (presupuesto, informe, pegatina…) se preparan cuando todas las etiquetas están revisadas.
                    {d.items.length ? <> Llevas <b>{revisadas} de {d.items.length}</b>.</> : ' Todavía no hay etiquetas.'}</p>
                  <button className="btn" onClick={() => setTab('productos')}><Icon.tag /> {d.items.length ? 'Seguir revisando' : 'Añadir etiquetas'}</button>
                </div>
              ) : <WarrantyDocuments w={w} opts={opts} onGoReview={() => setTab('productos')} onEditWarranty={p.edit ? () => onEdit(w) : null} onChanged={cargar} onOpenTicket={abrirTicket} />)}
            </div>
          </section>
          {pocas ? historial : null}
        </div>

        <aside className="wf-side">
          <EnviosCliente list={d.shipments} />
          <EtiquetaAlmacen w={w} enabled={!!d.cloud?.enabled} usual={d.cloud?.usual} puede={!!p.edit} onChanged={cargar} />
          <Pasos w={w} listas={d.steps} puede={!!p.edit} onChanged={cargar} />
          <GuiasRelacionadas w={w} onGoAll={onGoGuides} />
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
              <div><dt>Recepción</dt><dd>{w.received_at ? fecha(w.received_at) : <span className="dev-mute">Pendiente de recibir</span>}</dd></div>
              <div><dt>Instalación</dt><dd>{w.installed_at ? fecha(w.installed_at) : <span className="dev-mute" title="Cada dispositivo cuenta desde su alta en el Cloud">Alta en el Cloud</span>}</dd></div>
              {w.closed_at ? <div><dt>Cierre</dt><dd>{fecha(w.closed_at)}</dd></div> : null}
              {!abierta && w.close_reason ? <div><dt>Motivo de cierre</dt><dd>{motivoDe(opts, w.close_reason)}</dd></div> : null}
              {!abierta && w.close_resolution ? <div className="wf-dl-largo"><dt>Resolución</dt><dd>{w.close_resolution}</dd></div> : null}
              <div><dt>Creada</dt><dd>{fechaHora(w.created_at)}{w.created_by_name ? <small> · {w.created_by_name}</small> : null}</dd></div>
            </dl>
          </section>

          {!pocas ? historial : null}
        </aside>
      </div>
      {cierre ? (
        <CierreModal modo={cierre.modo} opts={opts} onClose={() => setCierre(null)} conFecha={cierre.modo === 'cerrar'}
          titulo={`${cierre.modo === 'cerrar' ? 'Cerrar' : 'Reabrir'} ${w.code}`}
          estados={estados.filter((e) => cerradaEn(opts, e.value) === (cierre.modo === 'cerrar'))}
          agentes={cierre.modo === 'reabrir' ? (opts?.agents || []).map((a) => ({ value: String(a.id), label: a.name })) : null}
          inicial={{ status: cierre.status, closed_at: hoy(), responsible_id: w.responsible_id ? String(w.responsible_id) : '' }}
          onOk={async (v) => {
            const extra = cierre.modo === 'cerrar'
              ? { close_reason: v.close_reason, close_resolution: v.close_resolution || '', closed_at: v.closed_at || '' }
              : { reopen_reason: v.reopen_reason, responsible_id: v.responsible_id || '' }
            const r = await api.saveWarranty({ ...w, responsible_id: w.responsible_id || '', urgency: w.urgency || '', ticket_code: w.ticket_code || '', first_contact_at: w.first_contact_at || '', installed_at: w.installed_at || '', closed_at: '', status: v.status, ...extra })
            if (!r.ok) { toast(r.error || 'Error', 'err'); return false }
            toast(cierre.modo === 'cerrar' ? 'Garantía cerrada' : 'Garantía reabierta'); cargar(); onChanged()
            return true
          }} />
      ) : null}
      {verFotos !== undefined && fotos?.length ? <FotosCliente w={w} fotos={fotos} inicio={verFotos} onClose={() => setVerFotos(undefined)} onOpenTicket={abrirTicket} /> : null}
      {chat && meta ? (
        <TicketModal id={chat} meta={meta} user={user} scope="garantias" onClose={() => { setChat(null); cargar() }} onChange={() => {}}
          onOpenTicket={(tid) => setChat(tid)}
          onOpenWarranty={(gid) => { setChat(null); if (gid !== w.id) onOpenWarranty?.(gid) }} />
      ) : null}
    </>
  )
}

/* Seguimiento e historial: notas del equipo, cambios automáticos y lo que escribe el cliente. */
function Historial({ w, d, opts, puede, hist, setHist, nota, setNota, notaRef, enviando, enviarNota, linea, mensajes }) {
  const p = { edit: puede }
  return (
          <section className="wf-card wf-hist" data-tour="wf-hist">
            <header><h3><Icon.clock /> Seguimiento e historial</h3></header>
            {p.edit ? <div className="wf-compose">
              <textarea ref={notaRef} rows={2} value={nota.body} onChange={(e) => setNota((s) => ({ ...s, body: e.target.value }))}
                onKeyDown={(e) => { if (e.key === 'Enter' && (e.ctrlKey || e.metaKey)) enviarNota() }}
                placeholder="Qué se ha hecho, con quién se ha hablado, qué falta…" />
              <div className="wf-compose-f">
                <Select sm value={nota.type} onChange={(v) => setNota((s) => ({ ...s, type: v }))}
                  options={Object.entries(opts?.note_types || {}).map(([k, v]) => ({ value: k, label: v }))} />
                <button className="btn sm" disabled={enviando || !nota.body.trim()} onClick={enviarNota}><Icon.plus /> {enviando ? 'Añadiendo…' : 'Añadir'}</button>
              </div>
            </div> : null}
            <div className="dc-seg wf-filtro">
              {[['', 'Todo'], ['notas', `Notas (${d.notes.length})`], ['cambios', 'Cambios'], ...(w.ticket_id && w.ticket_visible ? [['cliente', `Cliente (${mensajes.length})`]] : [])].map(([k, l]) => <button key={k} className={hist === k ? 'on' : ''} onClick={() => setHist(k)}>{l}</button>)}
            </div>
            {!linea.length ? <p className="dev-mute wf-none">{hist === 'notas' ? 'Todavía no hay seguimiento.' : hist === 'cliente' ? 'El cliente no ha escrito en el ticket.' : 'Sin movimientos.'}</p> : (
              <ol className="wf-tl">
                {linea.map(({ k, nota: n, ev: e, msg: m }) => m ? (
                  <li key={k} className="msg">
                    <div className="wf-tl-h"><b>Cliente</b><span className={`wr-type t-msg-${m.channel}`}>{m.channel === 'whatsapp' ? 'WhatsApp' : 'Correo'}</span><time>{fechaHora(m.created_at)}</time></div>
                    <MensajeCliente text={m.text} />
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
  )
}

export default function Warranties({ openId = null, onOpened, onOpenTicket, onGoGuides, filtro = null, user = null }) {
  const [opts, setOpts] = useState(null)
  const [sel, setSel] = useState(openId)
  // Llegar con una garantía concreta (desde la ficha de un ticket o ZKONG).
  useEffect(() => { if (openId) { setSel(openId); onOpened?.() } }, [openId])
  // Llegar con la lista filtrada (desde Equipo).
  // (Al entrar con una garantía, manda la garantía: un filtro de antes no la cierra.)
  useEffect(() => { if (filtro && !openId) setSel(null) }, [filtro])
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
          ? <Ficha key={`${sel}-${reload}`} id={sel} opts={opts} onBack={() => setSel(null)}
              onEdit={(w) => setForm({ ...VACIA, ...w, responsible_id: w.responsible_id || '', urgency: w.urgency || '', ticket_code: w.ticket_code || '' })}
              onChanged={() => {}} onOpenTicket={onOpenTicket} onGoGuides={onGoGuides} user={user} onOpenWarranty={setSel} />
          : <Lista opts={opts} reload={reload} filtro={filtro} onOpen={setSel} onNew={() => setForm({ ...VACIA })} />}
      </div></div>

      {form && <Formulario inicial={form} opts={opts} onClose={() => setForm(null)} onSaved={(id) => { setForm(null); refrescar(); setSel(id) }} />}
    </>
  )
}
