import { useState, useEffect, useCallback, useRef, Fragment } from 'react'
import { api, mediaUrl } from '../api.js'
import WaAudio from './WaAudio.jsx'
import { Icon } from '../icons.jsx'
import { useToast, useConfirm } from '../App.jsx'
import Select from './Select.jsx'
import ChannelBadge from './ChannelBadge.jsx'
import SedeField from './SedeField.jsx'
import OrgFilter from './OrgFilter.jsx'
import Composer from './Composer.jsx'
import Agents from './Agents.jsx'
import CronAlerts from './CronAlerts.jsx'
import LoadError from './LoadError.jsx'
import { onTicketActivity } from '../realtime.js'

/* Enter/Espacio activan una fila clicable con el teclado (Espacio sin hacer scroll
   de la página). Accesibilidad: las filas son enfocables (tabIndex) y así se abren
   sin ratón; se apoya en el anillo de :focus-visible para verse. */
const teclaAbrir = (fn) => (e) => {
  // Solo si la tecla es sobre la propia fila, no burbujeada desde un control hijo
  // (checkbox, Selects): si no, marcar el checkbox con Espacio abriría el ticket.
  if (e.target !== e.currentTarget) return
  if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); fn() }
}

/* Fila «fantasma» mientras carga la bandeja: imita las columnas con shimmer, en vez
   de un spinner que sustituye toda la tabla. Se siente más rápida y no salta el layout. */
function SkelRow({ canTimes }) {
  return (
    <tr className="tk-skel" aria-hidden="true">
      <td className="tk-chk"><span className="sk sk-chk" /></td>
      <td><span className="sk" style={{ width: 80 }} /></td>
      <td><span className="sk sk-badge" /></td>
      <td>
        <span className="sk sk-block" style={{ width: '70%' }} />
        <span className="sk sk-block" style={{ width: '45%', height: 9, marginTop: 5 }} />
      </td>
      <td><span className="sk" style={{ width: '85%' }} /></td>
      <td><span className="sk" style={{ width: 66 }} /></td>
      <td><span className="sk" style={{ width: 66 }} /></td>
      <td><span className="sk sk-pill" /></td>
      <td><span className="sk sk-pill" /></td>
      {canTimes && <><td><span className="sk" style={{ width: 40 }} /></td><td><span className="sk" style={{ width: 40 }} /></td></>}
      <td><span className="sk" style={{ width: 52 }} /></td>
    </tr>
  )
}

/* ---------------------------------------------------------------------------
 * GESTIÓN DE TICKETS
 *
 * Reglas acordadas:
 *  - Se ordena por ÚLTIMA ACTIVIDAD, no por fecha de creación.
 *  - Se distingue de quién es la última respuesta: si habló el cliente, el ticket
 *    está SIN RESPONDER (la pelota es nuestra). Si hablamos nosotros, está respondido.
 *  - Los TIEMPOS (atención/resolución) y el panel de AGENTES solo los ven quienes
 *    tienen permiso (encargado / superadmin). El backend ni siquiera los envía.
 *  - Al abrir un ticket NO se cambia de página: se abre un MODAL grande.
 * ------------------------------------------------------------------------- */

const fmtDate = (s) => (s ? new Date(s.replace(' ', 'T')).toLocaleString('es-ES', { day: '2-digit', month: '2-digit', year: '2-digit', hour: '2-digit', minute: '2-digit' }) : '—')
const fmtTime = (s) => (s ? new Date(s.replace(' ', 'T')).toLocaleTimeString('es-ES', { hour: '2-digit', minute: '2-digit' }) : '')
const fmtMins = (m) => (m === null || m === undefined ? '—' : m < 60 ? `${m}m` : `${Math.floor(m / 60)}h ${m % 60}m`)
/** Tamaño de un adjunto en algo legible (KB/MB). */
const fmtSize = (b) => (!b ? '' : b >= 1048576 ? `${(b / 1048576).toFixed(1)} MB` : `${Math.max(1, Math.round(b / 1024))} KB`)

/** "hace 3 min" — para ver de un vistazo qué se movió hace nada. */
function ago(s) {
  if (!s) return '—'
  const d = new Date(s.replace(' ', 'T'))
  const min = Math.round((Date.now() - d.getTime()) / 60000)
  if (min < 1) return 'ahora mismo'
  if (min < 60) return `hace ${min} min`
  const h = Math.floor(min / 60)
  if (h < 24) return `hace ${h} h`
  const dd = Math.floor(h / 24)
  return dd === 1 ? 'ayer' : `hace ${dd} días`
}

const CHANNEL = { whatsapp: 'WhatsApp', email: 'Correo', web: 'Web' }

/* --- SLA: etiqueta del reloj ---
 * met/missed = ya se cumplió (a tiempo o tarde) · ok/warn/late = sigue corriendo.
 * Solo se pinta lo que pide atención: lo que va bien no ensucia la lista. */
const SLA_TXT = { late: 'Vencido', warn: 'Por vencer', missed: 'Fuera de plazo' }
function slaChip(s, etiqueta) {
  if (!s || !SLA_TXT[s.state]) return null
  const m = Math.abs(s.minutes_left || 0)
  const falta = m >= 60 ? `${Math.floor(m / 60)} h` : `${m} min`
  const titulo = s.state === 'warn' ? `${etiqueta}: quedan ${falta}`
    : s.state === 'late' ? `${etiqueta}: vencido hace ${falta}`
    : `${etiqueta}: se cumplió fuera de plazo`
  return <span className={`sla-chip ${s.state}`} title={titulo}>{etiqueta} · {SLA_TXT[s.state]}</span>
}
/** Línea de SLA bajo cada tiempo del ticket: el plazo y cómo va. */
function SlaLinea({ sla }) {
  if (!sla) return null
  const m = Math.abs(sla.minutes_left || 0)
  const falta = m >= 60 ? `${Math.floor(m / 60)} h ${m % 60} min` : `${m} min`
  const txt = {
    met:    'Cumplido en plazo',
    missed: 'Se cumplió fuera de plazo',
    ok:     `Quedan ${falta}`,
    warn:   `Quedan ${falta}`,
    late:   `Vencido hace ${falta}`,
  }[sla.state]
  return (
    <span className={`sla-line ${sla.state}`}>
      {txt} <i>· vence {fmtDate(sla.due)}</i>
    </span>
  )
}

/** ¿Alguno de los dos relojes se pasó de plazo? (vencido o cumplido tarde) */
const seFueDePlazo = (sla) =>
  ['late', 'missed'].includes(sla?.response?.state) || ['late', 'missed'].includes(sla?.resolve?.state)

/** El reloj que más urge de los dos (para no repetir dos etiquetas en la fila). */
function slaPeor(sla) {
  if (!sla) return null
  const orden = { late: 3, warn: 2, missed: 1 }
  const cands = [[sla.response, 'Respuesta'], [sla.resolve, 'Resolución']]
    .filter(([s]) => s && orden[s.state])
    .sort((a, b) => orden[b[0].state] - orden[a[0].state])
  return cands[0] || null
}

/* Etiqueta de PRIORIDAD. El color ya no está en el CSS: viene de la BD, porque las
   prioridades se configuran. Si no hay color (prioridad borrada), cae a la clase de
   siempre para no quedarse sin estilo. */
function prChip(v, meta, small = false) {
  const p = meta?.priority_meta?.[v]
  const cls = `chip ${p ? '' : `p-${v}`} ${small ? 'sm' : ''}`.trim()
  const style = p ? { background: p.color + '22', color: p.color } : undefined
  return <span className={cls} style={style}>{p?.name || meta?.priorities?.[v] || v}</span>
}

/* Chip de ESTADO con color desde meta.status_meta (misma idea que prChip): así no
   depende del nombre de clase CSS. Si falta meta, cae a la clase `.chip.{estado}`. */
export function stChip(v, meta, small = false) {
  const s = meta?.status_meta?.[v]
  const cls = `chip ${s ? '' : v} ${small ? 'sm' : ''}`.trim()
  const style = s ? { background: s.color + '22', color: s.color } : undefined
  return <span className={cls} style={style}>{s?.name || meta?.statuses?.[v] || v}</span>
}

// Historial de movimientos: icono + frase legible por tipo de evento.
const EV_ICON = { created: '🎫', status: '🔄', assign: '👤', category: '🏷️', priority: '⚑', merge_in: '🔗', merge_out: '🔗', requester: '✉️' }
function describeEvent(e, meta) {
  const st = (v) => meta?.statuses?.[v] || v
  const pr = (v) => meta?.priorities?.[v] || v

  switch (e.type) {
    case 'created':  return 'Ticket creado'
    case 'status':   return `Estado: ${st(e.from_value)} → ${st(e.to_value)}`
    case 'priority': return `Prioridad: ${pr(e.from_value)} → ${pr(e.to_value)}`
    case 'category': return 'Categoría cambiada'
    case 'assign':
      if (!e.to_name) return `Desasignado${e.from_name ? ` (era de ${e.from_name})` : ''}`
      return `Asignado a ${e.to_name}`
    // El motivo se pinta aparte (e.note): aquí solo va qué pasó.
    case 'merge_in':  return `Se fusionó aquí el ticket ${e.from_value}`
    case 'merge_out': return `Fusionado en el ticket ${e.to_value}`
    case 'requester': return `Solicitante: ${e.from_value} → ${e.to_value}`
    default: return e.type
  }
}

/*
 * VISTAS RÁPIDAS. La pregunta de un agente al entrar no es «¿cómo filtro?», es
 * «¿qué me toca ahora?». Cada vista responde a esa pregunta de un clic, y el
 * contador le dice si merece la pena mirarla antes de hacerlo.
 *
 * Por defecto: ACTIVOS (todo menos resueltos y cerrados). Los cerrados son
 * archivo, no trabajo: solo aparecen si los pides expresamente en «Todos».
 */
const VIEWS = [
  { k: 'active',     label: 'Activos',       hint: 'Todo menos resueltos y cerrados', f: { status: 'open', assigned: 'all',  reply: 'all', snoozed: '' } },
  { k: 'pending',    label: 'Sin responder', hint: 'El cliente escribió lo último',   f: { status: 'open', assigned: 'all',  reply: 'pending', snoozed: '' }, accent: 'warn' },
  { k: 'mine',       label: 'Mis tickets',   hint: 'Los que tengo asignados',         f: { status: 'open', assigned: 'me',   reply: 'all', snoozed: '' } },
  { k: 'unassigned', label: 'Sin asignar',   hint: 'Nadie los ha cogido todavía',     f: { status: 'open', assigned: 'none', reply: 'all', snoozed: '' } },
  { k: 'all',        label: 'Todos',         hint: 'Incluye resueltos y cerrados',    f: { status: 'all',  assigned: 'all',  reply: 'all', snoozed: '' } },
]

/*
 * «Pospuestos» va aparte (como «SLA vencido»): solo aparece si hay tickets dormidos.
 * Enseña justo esos —los que salen de la cola diaria— para poder repescar alguno.
 */
const VISTA_SNOOZE = { k: 'snoozed', label: '💤 Pospuestos', hint: 'Apartados hasta una fecha o una respuesta', accent: 'snz', f: { status: 'all', assigned: 'all', reply: 'all', sla: 'all', snoozed: 'only' } }

/*
 * «SLA vencido» va aparte de las demás vistas: solo aparece si el SLA está encendido
 * Y hay alguno fuera de plazo. Un contador a cero permanente se convierte en parte
 * del decorado y deja de mirarse.
 */
const VISTA_SLA = { k: 'sla_late', label: 'SLA vencido', hint: 'Se pasó el plazo y sigue abierto', accent: 'late', f: { status: 'open', assigned: 'all', reply: 'all', sla: 'late' } }
/**
 * Marca en negrita lo buscado dentro del fragmento. Se parte el texto por la palabra
 * en vez de inyectar HTML: el fragmento viene de un correo y no hay que confiar en él.
 */
function resaltar(texto, aguja) {
  const q = (aguja || '').trim()
  if (!q) return texto

  const partes = []
  let resto = texto
  let i = resto.toLowerCase().indexOf(q.toLowerCase())
  let n = 0
  while (i !== -1 && n < 20) {
    partes.push(resto.slice(0, i), <b key={n}>{resto.slice(i, i + q.length)}</b>)
    resto = resto.slice(i + q.length)
    i = resto.toLowerCase().indexOf(q.toLowerCase())
    n++
  }
  partes.push(resto)
  return partes
}

// search_in: 'ficha' (código/asunto/cliente) o 'messages' (dentro de la conversación)
const BASE_F = { q: '', search_in: 'ficha', priority: 'all', category: 'all', label: 'all', sla: 'all', org: 'all', ...VIEWS[0].f }

/*
 * POSPONER un ticket. Si ya está dormido, enseña hasta cuándo + «Reactivar ahora».
 * Si no, un botón que abre el menú de presets (esta tarde, el lunes, hasta que
 * responda…) con fecha a medida y un motivo corto opcional.
 */
function SnoozeControl({ t, onSnooze, onWake, compact = false }) {
  const [open, setOpen] = useState(false)
  const [custom, setCustom] = useState(false)
  const [when, setWhen] = useState('')
  const [reason, setReason] = useState('')
  const ref = useRef(null)
  const btnRef = useRef(null)
  // En compacto la ficha tiene overflow: el menú se sacaría del recuadro y se recorta.
  // Lo anclamos con position:fixed calculado desde el botón (como la campana de avisos).
  const [pos, setPos] = useState(null)

  useEffect(() => {
    if (!open) return
    const h = (e) => { if (ref.current && !ref.current.contains(e.target)) { setOpen(false); setCustom(false) } }
    document.addEventListener('mousedown', h)
    return () => document.removeEventListener('mousedown', h)
  }, [open])

  useEffect(() => {
    if (!open || !compact || !btnRef.current) { setPos(null); return }
    const calc = () => {
      const r = btnRef.current.getBoundingClientRect()
      const W = 250
      let left = Math.max(8, r.right - W)                              // alinea por la derecha, sin salir por la izquierda
      let top = r.bottom + 6
      if (top + 360 > window.innerHeight - 8) top = Math.max(8, r.top - 6 - 360)  // sin sitio abajo → hacia arriba
      setPos({ position: 'fixed', top, left, right: 'auto', width: W })
    }
    calc()
    window.addEventListener('resize', calc)
    return () => window.removeEventListener('resize', calc)
  }, [open, compact])

  const dormido = t.snoozed_at && (Number(t.snooze_wake_on_reply)
    || (t.snoozed_until && new Date(t.snoozed_until) > new Date()))

  // En modo compacto la ficha se encarga del estado «dormido» (una línea aparte); aquí
  // solo el disparador de posponer. En modo normal se mantiene el banner completo.
  if (dormido && !compact) {
    return (
      <div className="tkm-snoozed">
        <div className="tkm-snoozed-h">
          <Icon.clock />
          <b>{Number(t.snooze_wake_on_reply)
            ? 'Pospuesto hasta que el cliente responda'
            : `Pospuesto hasta ${fmtDate(t.snoozed_until)}`}</b>
        </div>
        {t.snooze_reason && <p className="tkm-snoozed-why">«{t.snooze_reason}»</p>}
        <button className="btn ghost block" style={{ marginTop: 8 }} onClick={onWake}>
          <Icon.check /> Reactivar ahora
        </button>
      </div>
    )
  }

  const pick = (preset) => { onSnooze({ preset, reason: reason.trim() || undefined }); setOpen(false); setCustom(false) }

  return (
    <div className={compact ? 'tkm-q-wrap' : 'tkm-snooze'} ref={ref}>
      {compact ? (
        <button ref={btnRef} className="tkm-q" onClick={() => setOpen((o) => !o)} title="Posponer">
          <Icon.clock /><small>Posponer</small>
        </button>
      ) : (
        <button className="btn ghost block" style={{ marginTop: 10 }} onClick={() => setOpen((o) => !o)}>
          <Icon.clock /> Posponer…
        </button>
      )}
      {open && (
        <div className="snz-menu" style={compact ? pos || { visibility: 'hidden' } : undefined}>
          <div className="snz-h"><b>¿Cuándo lo retomas?</b><small>Se aparta de la cola y te lo recuerdo ese día.</small></div>
          <button className="snz-opt" onClick={() => pick('later_today')}><span className="snz-ic">🌆</span>Esta tarde</button>
          <button className="snz-opt" onClick={() => pick('tomorrow')}><span className="snz-ic">☀️</span>Mañana por la mañana</button>
          <button className="snz-opt" onClick={() => pick('monday')}><span className="snz-ic">📅</span>El lunes</button>
          <button className="snz-opt" onClick={() => pick('week')}><span className="snz-ic">🗓️</span>En una semana</button>
          <button className="snz-opt special" onClick={() => pick('reply')}><span className="snz-ic">💬</span>Hasta que el cliente responda</button>
          {custom ? (
            <div className="snz-custom">
              <input type="datetime-local" value={when} onChange={(e) => setWhen(e.target.value)} />
              <button className="btn primary sm" disabled={!when}
                onClick={() => when && (onSnooze({ preset: 'custom', until: when, reason: reason.trim() || undefined }), setOpen(false), setCustom(false))}>
                Posponer
              </button>
            </div>
          ) : (
            <button className="snz-opt" onClick={() => setCustom(true)}><span className="snz-ic">⏰</span>Fecha y hora…</button>
          )}
          <input className="snz-reason" placeholder="Motivo (opcional): esperando repuesto…"
            value={reason} onChange={(e) => setReason(e.target.value)} maxLength={160} />
        </div>
      )}
    </div>
  )
}

/*
 * Sección PLEGABLE de la ficha. Recuerda si el usuario la deja abierta o cerrada
 * (localStorage global, por sección), para que la vista se adapte a cómo trabaja.
 */
function Collapsible({ id, title, defaultOpen = false, children }) {
  const key = `tkm_fold_${id}`
  const [open, setOpen] = useState(() => {
    try { const v = localStorage.getItem(key); return v === null ? defaultOpen : v === '1' } catch { return defaultOpen }
  })
  const toggle = () => {
    const n = !open; setOpen(n)
    try { localStorage.setItem(key, n ? '1' : '0') } catch { /* sin storage */ }
  }
  return (
    <div className={`tkm-fold ${open ? 'open' : ''}`}>
      <button className="tkm-fold-h" onClick={toggle} aria-expanded={open}>
        <span>{title}</span><Icon.chevron className="tkm-fold-ch" />
      </button>
      {open && <div className="tkm-fold-b">{children}</div>}
    </div>
  )
}

/*
 * ETIQUETAS de un ticket (en la ficha). Chips de color + un selector que carga el
 * catálogo activo. Marcar/desmarcar guarda al instante (reemplaza el conjunto).
 */
function TicketLabels({ ticketId, initial }) {
  const [labels, setLabels] = useState(initial || [])
  const [catalogo, setCatalogo] = useState(null)
  const [abierto, setAbierto] = useState(false)

  useEffect(() => {
    if (abierto && catalogo === null) api.listTicketLabels().then((d) => setCatalogo((d.labels || []).filter((l) => Number(l.active))))
  }, [abierto, catalogo])

  const tiene = (id) => labels.some((l) => l.id === id)
  const toggle = async (l) => {
    const next = tiene(l.id) ? labels.filter((x) => x.id !== l.id) : [...labels, { id: l.id, name: l.name, color: l.color }]
    setLabels(next)
    await api.setTicketLabels(ticketId, next.map((x) => x.id))
  }

  return (
    <div className="tkm-block">
      <div className="tkm-sec">Etiquetas</div>
      <div className="tkm-tags">
        {labels.map((l) => (
          <span key={l.id} className="tk-tag" style={{ '--tc': l.color }}><span className="tk-tag-dot" />{l.name}</span>
        ))}
        <button className="tk-tag-add" onClick={() => setAbierto((o) => !o)}>
          <Icon.plus />{labels.length ? '' : ' Etiqueta'}
        </button>
      </div>
      {abierto && (
        <div className="tk-tag-picker">
          {catalogo === null ? <div className="tk-tag-empty">Cargando…</div>
            : catalogo.length === 0 ? <div className="tk-tag-empty">No hay etiquetas. Créalas en Configuración → Etiquetas.</div>
              : catalogo.map((l) => (
                <label key={l.id} className="tk-tag-opt">
                  <input type="checkbox" checked={tiene(l.id)} onChange={() => toggle(l)} />
                  <span className="tk-tag-dot" style={{ '--tc': l.color }} />{l.name}
                </label>
              ))}
        </div>
      )}
    </div>
  )
}

/*
 * Campos personalizados (globales) del ticket. Se definen en Configuración →
 * Campos personalizados; aquí el agente rellena el valor de cada uno. Un solo
 * botón «Guardar» manda todos. Si no hay campos activos, no se pinta nada.
 */
function TicketCustomFields({ ticketId, initial, bare = false }) {
  const toast = useToast()
  const campos = initial || []
  const inicial = () => Object.fromEntries(campos.map((c) => [c.id, c.value ?? '']))
  const [vals, setVals] = useState(inicial)
  const [dirty, setDirty] = useState(false)
  const [saving, setSaving] = useState(false)

  // Al cambiar de ticket, recargar los valores de este.
  useEffect(() => { setVals(inicial()); setDirty(false) }, [ticketId]) // eslint-disable-line react-hooks/exhaustive-deps

  if (campos.length === 0) return null

  const set = (id, v) => { setVals((s) => ({ ...s, [id]: v })); setDirty(true) }

  const guardar = async () => {
    // Validación de obligatorios (los que no son sí/no).
    const falta = campos.find((c) => c.required && c.type !== 'checkbox'
      && (vals[c.id] === '' || vals[c.id] == null))
    if (falta) { toast(`«${falta.label}» es obligatorio`, 'err'); return }
    setSaving(true)
    const r = await api.saveTicketFields(ticketId, vals)
    setSaving(false)
    if (r.ok) { toast('Campos guardados'); setDirty(false) } else toast(r.error || 'Error', 'err')
  }

  const inner = (
    <>
      <div className="tk-cf">
        {campos.map((c) => (
          <label key={c.id} className="tk-cf-row">
            <span className="tk-cf-lbl">{c.label}{c.required && <em> *</em>}</span>
            {c.type === 'textarea' ? (
              <textarea className="tk-cf-in" rows={2} value={vals[c.id] || ''} onChange={(e) => set(c.id, e.target.value)} />
            ) : c.type === 'number' ? (
              <input className="tk-cf-in" type="number" value={vals[c.id] || ''} onChange={(e) => set(c.id, e.target.value)} />
            ) : c.type === 'date' ? (
              <input className="tk-cf-in" type="date" value={vals[c.id] || ''} onChange={(e) => set(c.id, e.target.value)} />
            ) : c.type === 'select' ? (
              <Select value={vals[c.id] || ''} onChange={(v) => set(c.id, v)}
                options={[{ value: '', label: '—' }, ...(c.options || []).map((o) => ({ value: o, label: o }))]} />
            ) : c.type === 'checkbox' ? (
              <span className="fb-switch">
                <input type="checkbox" checked={String(vals[c.id]) === '1' || vals[c.id] === true}
                  onChange={(e) => set(c.id, e.target.checked ? '1' : '0')} />
                <span className={`fb-toggle ${String(vals[c.id]) === '1' || vals[c.id] === true ? 'on' : ''}`} />
              </span>
            ) : (
              <input className="tk-cf-in" value={vals[c.id] || ''} onChange={(e) => set(c.id, e.target.value)} />
            )}
          </label>
        ))}
      </div>
      {dirty && (
        <button className="btn sm tk-cf-save" onClick={guardar} disabled={saving}>
          {saving ? 'Guardando…' : 'Guardar campos'}
        </button>
      )}
    </>
  )

  if (bare) return inner
  return <div className="tkm-block"><div className="tkm-sec">Campos personalizados</div>{inner}</div>
}

/*
 * `initialTicket`: al llegar desde otra pantalla (p. ej. pinchando uno de los
 * «tickets recientes» del Centro de Soporte) se abre ESE ticket directamente, en
 * vez de dejar al usuario delante de la lista buscándolo otra vez.
 */
export default function Tickets({ user, onGo, initialTab = 'tickets', initialTicket = null, initialOrg = null }) {
  const toast = useToast()
  const confirm = useConfirm()
  const [tab, setTab] = useState(initialTab)   // tickets | agents | cron
  const [crones, setCrones] = useState(0)     // crones fallando, para el distintivo
  const [meta, setMeta] = useState(null)
  const [rows, setRows] = useState(null)
  const [err, setErr] = useState(false)   // fallo de carga de la bandeja
  const [canTimes, setCanTimes] = useState(false)
  const [counts, setCounts] = useState({})
  const [open, setOpen] = useState(initialTicket)   // id del ticket abierto en el modal
  // Fusión lanzada DESDE LA LISTA (sin abrir ningún ticket): { id, preselect }
  const [openFusion, setOpenFusion] = useState(null)
  const [bulkMerge, setBulkMerge] = useState(null)   // null | { n } — motivo para fusión en lote (3+)
  const [f, setF] = useState(initialOrg ? { ...BASE_F, org: initialOrg, status: 'all' } : BASE_F)
  const [sel, setSel] = useState(new Set())   // ids seleccionados (acciones en lote)
  // Al llegar desde la pantalla de Organización con un filtro, se aplica (y se ve todo).
  useEffect(() => { if (initialOrg) setF({ ...BASE_F, org: initialOrg, status: 'all' }) }, [initialOrg])

  // Paginación. El tamaño de página se recuerda por agente: cada uno tiene su
  // pantalla y su forma de trabajar.
  const [page, setPage] = useState(1)
  const [perPage, setPerPage] = useState(() => Number(localStorage.getItem('tk_per_page')) || 25)
  // Densidad de la tabla: «cómoda» (por defecto) o «compacta» para barrer más cola de un vistazo.
  const [density, setDensity] = useState(() => localStorage.getItem('tk_density') || 'comoda')
  const toggleDensity = () => {
    const d = density === 'compacta' ? 'comoda' : 'compacta'
    localStorage.setItem('tk_density', d); setDensity(d)
  }
  const [pag, setPag] = useState({ total: 0, pages: 1 })

  // Vistas guardadas: personales de cada agente + COMPARTIDAS del equipo.
  const [savedViews, setSavedViews] = useState([])
  const [canShare, setCanShare] = useState(false)  // puede crear/editar vistas de equipo (encargado)
  const [naming, setNaming] = useState(false)      // mostrando el input de «guardar vista»
  const [newName, setNewName] = useState('')
  const [shareNew, setShareNew] = useState(false)  // la vista que se está guardando, ¿compartida?
  const [exporting, setExporting] = useState(false)
  const cargarVistas = useCallback(() => {
    api.listTicketViews().then((d) => { setSavedViews(d.views || []); setCanShare(!!d.can_share) })
  }, [])
  useEffect(() => { cargarVistas() }, [cargarVistas])

  const can = (p) => (user?.permissions || []).includes(p)

  const load = useCallback(() => {
    setErr(false)
    api.listTickets({ ...f, page, per_page: perPage }).then((d) => {
      if (!d || !Array.isArray(d.tickets)) { setErr(true); return }   // 500/no-JSON → error, no lista vacía falsa
      setRows(d.tickets)
      setCanTimes(!!d.can_times)
      setCounts(d.counts || {})
      setPag({ total: d.total ?? 0, pages: d.pages ?? 1 })
      // El servidor recorta si pides una página que ya no existe (p. ej. tras filtrar).
      if (d.page && d.page !== page) setPage(d.page)
    }).catch(() => setErr(true))
  }, [f, page, perPage])

  // Al cambiar de filtro o de tamaño, se vuelve a la primera página: quedarse en la
  // 7 de un resultado que ahora tiene 2 es la forma más tonta de ver la lista vacía.
  useEffect(() => { setPage(1) }, [f, perPage])
  const cambiarPorPagina = (n) => { localStorage.setItem('tk_per_page', n); setPerPage(n) }

  useEffect(() => { api.ticketMeta().then(setMeta) }, [])
  /*
   * Contador de crones fallando. Se pide UNA vez al entrar: dentro de la pestaña lo
   * mantiene al día la propia lista (onCount), y si entra un cron nuevo llega por
   * el aviso de tiempo real. Pedirlo en cada cambio de pestaña era una llamada de
   * más —y en este servidor cada llamada cuesta medio segundo—.
   */
  const cargarCrones = useCallback(() => {
    api.cronAlertCounts().then((r) => setCrones(r.counts?.open ?? 0))
  }, [])
  useEffect(() => { cargarCrones() }, [cargarCrones])
  useEffect(() => {
    if (tab !== 'tickets') return          // en Agentes no hace falta pedir la lista
    const t = setTimeout(load, 220)
    return () => clearTimeout(t)
  }, [load, tab])

  // Tiempo real: cualquier movimiento en un ticket recarga la tabla sola.
  useEffect(() => onTicketActivity(() => { if (tab === 'tickets') load() }), [load, tab])

  // Una vista está activa si el filtro actual coincide con su preajuste
  // `sla` entra en la comparación: si no, «Activos» y «SLA vencido» se verían las dos activas.
  const viewOn = (v) => f.status === v.f.status && f.assigned === v.f.assigned
    && f.reply === v.f.reply && (f.sla || 'all') === (v.f.sla || 'all')
    && (f.snoozed || '') === (v.f.snoozed || '')
  //  primero: al salir de la vista de vencidos hay que quitar ese filtro,
  // que las demás vistas no lo mencionan y se quedaría pegado.
  const applyView = (v) => setF((s) => ({ ...s, sla: 'all', ...v.f }))

  // --- Vistas guardadas (personales) ---
  const aplicarVista = (v) => setF({ ...BASE_F, ...v.filters })
  // Activa si el filtro actual coincide con TODAS las claves guardadas de la vista.
  const vistaGuardadaOn = (v) => Object.entries(v.filters || {}).every(([k, val]) => String(f[k] ?? '') === String(val ?? ''))
  const guardarVista = async () => {
    const nombre = newName.trim()
    if (!nombre) return
    // Se guarda la foto de los filtros finos + la vista base (estado/asignado/respuesta).
    const shared = canShare && shareNew
    const r = await api.saveTicketView({ name: nombre, filters: f, shared })
    if (r.ok) {
      toast(shared ? 'Vista de equipo guardada' : 'Vista guardada')
      setNewName(''); setNaming(false); setShareNew(false); cargarVistas()
    } else toast(r.error || 'No se pudo guardar', 'err')
  }
  const borrarVista = async (v, e) => {
    e?.stopPropagation()
    if (!(await confirm({ title: 'Borrar vista', message: `¿Borrar «${v.name}»?`, danger: true, confirmText: 'Borrar' }))) return
    const r = await api.deleteTicketView(v.id)
    if (r.ok) { toast('Vista borrada'); cargarVistas() } else toast(r.error || 'Error', 'err')
  }

  /** Asignar desde la propia tabla, sin abrir el ticket. */
  const quickAssign = async (id, uid) => {
    const r = await api.assignTicket(id, uid || null)
    if (r.ok) { toast('Ticket asignado'); load() } else toast(r.error || 'Error', 'err')
  }
  const quickCategory = async (id, catId) => {
    const r = await api.setTicketCategory(id, catId || null)
    if (r.ok) { toast('Categoría actualizada'); load() } else toast(r.error || 'Error', 'err')
  }

  // --- Selección para acciones en lote ---
  const toggleSel = (id) => setSel((s) => { const n = new Set(s); n.has(id) ? n.delete(id) : n.add(id); return n })
  const clearSel = () => setSel(new Set())
  const allSelected = rows?.length > 0 && rows.every((t) => sel.has(t.id))
  const toggleAll = () => setSel(allSelected ? new Set() : new Set((rows || []).map((t) => t.id)))
  // Al cambiar de filtro/vista, la selección deja de tener sentido
  useEffect(() => { clearSel() }, [f])

  const bulk = async (payload, okMsg) => {
    const r = await api.bulkTickets([...sel], payload)
    if (r.ok) { toast(`${okMsg} (${r.affected})`); clearSel(); load() } else toast(r.error || 'Error', 'err')
  }

  // Cambio de estado en lote CON deshacer: guarda el estado previo de cada uno y ofrece
  // «Deshacer» unos segundos (como Gmail). Restaura cada ticket a SU estado anterior.
  const bulkStatusUndo = async (status, verbo) => {
    const previos = (rows || []).filter((x) => sel.has(x.id)).map((x) => ({ id: x.id, status: x.status }))
    const r = await api.bulkTickets([...sel], { op: 'status', status })
    if (!r.ok) { toast(r.error || 'Error', 'err'); return }
    clearSel(); load()
    toast(`${verbo} (${r.affected})`, 'ok', {
      label: 'Deshacer',
      fn: async () => {
        const rr = await api.bulkTickets(previos.map((p) => p.id), { op: 'restore', states: previos })
        if (rr?.ok) { toast('Deshecho'); load() } else toast('No se pudo deshacer', 'err')
      },
    })
  }

  // Exportar a Excel lo que se ve (mismos filtros). Descarga binaria vía blob.
  const exportar = async () => {
    setExporting(true)
    const r = await api.exportTickets({ ...f })
    setExporting(false)
    if (!r.ok) { toast(r.error || 'No se pudo exportar', 'err'); return }
    const url = URL.createObjectURL(r.blob)
    const a = document.createElement('a')
    a.href = url; a.download = r.filename; document.body.appendChild(a); a.click()
    a.remove(); URL.revokeObjectURL(url)
    toast('Excel descargado')
  }

  const clear = () => setF(BASE_F)
  const refined = f.q || f.priority !== 'all' || f.category !== 'all' || f.label !== 'all' || (f.org && f.org !== 'all')   // filtros finos por encima de la vista

  const statusOpts = [
    { value: 'open', label: 'Activos', sub: 'Sin resueltos ni cerrados' },
    { value: 'all', label: 'Todos los estados', sub: 'Incluye el archivo' },
    ...Object.entries(meta?.statuses || {}).map(([value, label]) => ({ value, label })),
  ]
  const opts = (obj, all) => [{ value: 'all', label: all }, ...Object.entries(obj || {}).map(([value, label]) => ({ value, label }))]

  return (
    <>
      <header className="page-head">
        <span className="sc-ic"><Icon.ticket style={{ width: 18, height: 18, fill: 'var(--primary)' }} /></span>
        <div><h1>Gestión de tickets</h1></div>
        <span className="sub">
          · {tab === 'agents' ? 'Carga de trabajo y disponibilidad del equipo'
            : tab === 'cron' ? 'Tareas programadas que fallan, agrupadas por cron'
            : 'Administra y da seguimiento a los tickets de soporte'}
        </span>
        <div className="spacer" />

        <div className="seg">
          <button className={tab === 'tickets' ? 'on' : ''} onClick={() => setTab('tickets')}><Icon.ticket /> Tickets</button>
          {/* Solo un encargado ve la carga de trabajo del equipo */}
          {can('agents.view') && (
            <button className={tab === 'agents' ? 'on' : ''} onClick={() => setTab('agents')}><Icon.user /> Agentes</button>
          )}
          {/*
            Los crones viven aquí y no en un apartado suelto: son tickets, solo que
            de máquina. El distintivo avisa sin tener que entrar a mirar.
          */}
          <button className={tab === 'cron' ? 'on' : ''} onClick={() => setTab('cron')}>
            <Icon.bolt /> Crones
            {crones > 0 && <span className="seg-n">{crones}</span>}
          </button>
        </div>
        {tab === 'tickets' && (
          <button className="btn ghost" onClick={toggleDensity}
            title={density === 'compacta' ? 'Vista cómoda (filas más altas)' : 'Vista compacta (más filas en pantalla)'}>
            <Icon.list /> {density === 'compacta' ? 'Cómoda' : 'Compacta'}
          </button>
        )}
        {can('tickets.export') && (
          <button className="btn ghost" disabled={exporting} onClick={exportar}
            title="Descargar en Excel lo que ves con los filtros actuales">
            <Icon.download /> {exporting ? 'Exportando…' : 'Exportar'}
          </button>
        )}
        {can('tickets.create') && (
          <button className="btn" onClick={() => onGo?.('ticket_new')}><Icon.plus /> Nuevo ticket</button>
        )}
      </header>

      <div className="page-scroll">
        <div className="page" style={{ maxWidth: 'none' }}>

          {/* Desde Agentes se salta a los tickets de uno concreto: el paso natural
              después de mirar quién está libre es repartirle trabajo. */}
          {tab === 'agents' && (
            <Agents onSeeTickets={(who) => { setF({ ...BASE_F, assigned: who }); setTab('tickets') }} />
          )}

          {tab === 'cron' && <CronAlerts embedded onCount={setCrones} />}

          {tab === 'tickets' && <>

          {/* --- Vistas rápidas: «¿qué me toca ahora?» en un clic --- */}
          <div className="tk-views">
            {/* La de SLA solo se cuela si hay algo fuera de plazo (ver VISTA_SLA). */}
            {[...VIEWS,
              ...(counts.sla_late > 0 ? [VISTA_SLA] : []),
              ...(counts.snoozed > 0 ? [VISTA_SNOOZE] : []),
            ].map((v) => (
              <button key={v.k} className={`tkv ${viewOn(v) ? 'on' : ''} ${v.accent || ''}`} onClick={() => applyView(v)} title={v.hint}>
                {v.label}
                {counts[v.k] !== undefined && <span className="tkv-n">{counts[v.k]}</span>}
              </button>
            ))}

            {/* Vistas guardadas: personales + COMPARTIDAS del equipo (marcadas con 👥). */}
            {savedViews.map((v) => (
              <button key={`sv${v.id}`} className={`tkv sv ${v.shared ? 'team' : ''} ${vistaGuardadaOn(v) ? 'on' : ''}`} style={{ '--sv': v.color }}
                onClick={() => aplicarVista(v)} title={v.shared ? 'Vista del equipo' : 'Vista guardada'}>
                {v.shared ? <span className="tkv-team" aria-label="Vista del equipo">👥</span> : <span className="tkv-dot" />}
                {v.name}
                {(!v.shared || canShare) && (
                  <span className="tkv-x" title="Borrar vista" onClick={(e) => borrarVista(v, e)}>×</span>
                )}
              </button>
            ))}

            {/* Guardar la combinación de filtros actual como una vista. Solo tiene
                sentido cuando hay filtros finos puestos (si no, es la vista de siempre). */}
            {naming ? (
              <span className="tkv-save">
                <input autoFocus value={newName} onChange={(e) => setNewName(e.target.value)} maxLength={80}
                  onKeyDown={(e) => { if (e.key === 'Enter') guardarVista(); if (e.key === 'Escape') { setNaming(false); setNewName(''); setShareNew(false) } }}
                  placeholder="Nombre de la vista…" />
                {/* Compartir con el equipo: solo los encargados lo ven. */}
                {canShare && (
                  <label className="tkv-share" title="Que la vean todos los agentes">
                    <input type="checkbox" checked={shareNew} onChange={(e) => setShareNew(e.target.checked)} /> 👥 Equipo
                  </label>
                )}
                <button className="tkv-save-ok" onClick={guardarVista}>Guardar</button>
                <button className="tkv-save-no" onClick={() => { setNaming(false); setNewName(''); setShareNew(false) }}>✕</button>
              </span>
            ) : refined ? (
              <button className="tkv add" onClick={() => setNaming(true)} title="Guardar los filtros actuales como una vista rápida">
                <Icon.plus /> Guardar vista
              </button>
            ) : null}
          </div>

          {/* --- Filtros finos: se aplican DENTRO de la vista elegida --- */}
          <div className="card tk-filters">
            {/*
              Dos búsquedas distintas y un botón para cambiar entre ellas: buscar «el
              pedido 4471» dentro de la conversación es otra pregunta que buscar por
              código o cliente, y mezclarlas devuelve resultados que no se entienden.
            */}
            <label className="field grow">
              <span className="lbl">Buscar</span>
              <div className="tk-search">
                <input value={f.q} onChange={(e) => setF((s) => ({ ...s, q: e.target.value }))}
                  placeholder={f.search_in === 'messages' ? 'Texto dentro de la conversación…' : 'Código, asunto o cliente…'} />
                <div className="tk-search-mode">
                  <button type="button" className={f.search_in !== 'messages' ? 'on' : ''}
                    onClick={() => setF((s) => ({ ...s, search_in: 'ficha' }))}
                    title="Buscar por código, asunto, cliente o correo">Ficha</button>
                  <button type="button" className={f.search_in === 'messages' ? 'on' : ''}
                    onClick={() => setF((s) => ({ ...s, search_in: 'messages' }))}
                    title="Buscar dentro del texto de los mensajes y las notas">Mensajes</button>
                </div>
              </div>
            </label>
            <div className="field"><span className="lbl">Estado</span>
              <Select block value={f.status} onChange={(v) => setF((s) => ({ ...s, status: v }))} options={statusOpts} />
            </div>
            <div className="field"><span className="lbl">Prioridad</span>
              <Select block value={f.priority} onChange={(v) => setF((s) => ({ ...s, priority: v }))} options={opts(meta?.priorities, 'Todas')} />
            </div>
            <div className="field"><span className="lbl">Categoría</span>
              <Select block value={f.category} onChange={(v) => setF((s) => ({ ...s, category: v }))}
                options={[{ value: 'all', label: 'Todas' }, ...(meta?.categories || []).map((c) => ({ value: String(c.id), label: c.name }))]} />
            </div>
            {/* Filtro por etiqueta. Solo si hay catálogo de etiquetas. */}
            {meta?.labels?.length > 0 && (
              <div className="field"><span className="lbl">Etiqueta</span>
                <Select block value={f.label} onChange={(v) => setF((s) => ({ ...s, label: v }))}
                  options={[{ value: 'all', label: 'Todas' }, ...meta.labels.map((l) => ({ value: String(l.id), label: l.name }))]} />
              </div>
            )}
            {/* Filtro por organización (grupo/marca/sede). Se oculta solo si no hay grupos. */}
            <OrgFilter value={f.org} onChange={(v) => setF((s) => ({ ...s, org: v }))} />

            {/* Filtrar por agente: solo tiene sentido para quien reparte el trabajo.
                Un agente ya tiene su atajo «Mis tickets» en las vistas de arriba. */}
            {can('tickets.assign') && (
              <div className="field"><span className="lbl">Asignado a</span>
                <Select block value={f.assigned} onChange={(v) => setF((s) => ({ ...s, assigned: v }))}
                  options={[
                    { value: 'all', label: 'Todos' },
                    { value: 'none', label: 'Sin asignar' },
                    { value: 'me', label: 'Yo' },
                    ...(meta?.users || []).map((u) => ({ value: String(u.id), label: u.name })),
                  ]} />
              </div>
            )}

            {(refined || !viewOn(VIEWS[0])) && (
              <button className="btn ghost sm" onClick={clear} style={{ marginBottom: 2 }}>Limpiar</button>
            )}
          </div>

          {/* --- Barra de acciones en lote (aparece con selección) --- */}
          {sel.size > 0 && (
            <div className="tk-bulk">
              <span className="tk-bulk-n">{sel.size} seleccionado{sel.size > 1 ? 's' : ''}</span>

              {/* FUSIONAR desde la lista: es AQUÍ donde se ve que dos líneas del
                  mismo cliente dicen lo mismo. Exige exactamente dos, del mismo
                  cliente y ninguno ya fusionado; si no se cumple, el botón sigue
                  visible pero apagado y el `title` dice por qué. Esconderlo dejaría
                  al agente sin saber que esto se puede hacer. */}
              {can('tickets.reply') && (() => {
                const marcados = (rows || []).filter((x) => sel.has(x.id))
                const suf = marcados.length >= 2
                const mismo = suf && marcados.every((x) => Number(x.contact_id) === Number(marcados[0].contact_id))
                const libres = marcados.every((x) => !x.merged_into_id)
                const vale = suf && mismo && libres && marcados[0].contact_id
                return (
                  <button className="btn ghost sm" disabled={!vale}
                    title={vale ? 'Juntar los seleccionados en una sola conversación'
                      : !suf ? 'Marca dos o más tickets para fusionarlos'
                        : !libres ? 'Alguno ya está fusionado en otro'
                          : 'Solo se pueden fusionar tickets del mismo cliente'}
                    onClick={() => marcados.length === 2
                      ? setOpenFusion({ id: marcados[0].id, preselect: marcados[1].id })
                      : setBulkMerge({ n: marcados.length })}>
                    <Icon.merge /> Fusionar{marcados.length > 2 ? ` (${marcados.length})` : ''}
                  </button>
                )
              })()}

              <button className="btn ghost sm" onClick={() => bulk({ op: 'assign', user_id: user.id }, 'Asignados a ti')}>
                <Icon.user /> Asignármelos
              </button>
              {can('tickets.close') && (
                <>
                  <button className="btn ghost sm" onClick={() => bulkStatusUndo('resuelto', 'Resueltos')}>
                    <Icon.check /> Resolver
                  </button>
                  <button className="btn ghost sm" onClick={() => bulkStatusUndo('cerrado', 'Cerrados')}>
                    Cerrar
                  </button>
                </>
              )}
              {can('tickets.assign') && (
                <div style={{ minWidth: 170 }}>
                  <Select sm block value="" placeholder="Asignar a…"
                    onChange={(uid) => bulk({ op: 'assign', user_id: uid || null }, 'Asignados')}
                    options={[{ value: '', label: 'Sin asignar' }, ...(meta?.users || []).map((u) => ({ value: String(u.id), label: u.name }))]} />
                </div>
              )}
              {/* Etiquetar en lote: pone la etiqueta elegida a todos los seleccionados
                  (quitar una etiqueta se hace desde cada ficha). */}
              {meta?.labels?.length > 0 && (
                <div style={{ minWidth: 160 }}>
                  <Select sm block value="" placeholder="Etiquetar…"
                    onChange={(lid) => lid && bulk({ op: 'label', label_id: lid, mode: 'add' }, 'Etiquetados')}
                    options={meta.labels.map((l) => ({ value: String(l.id), label: l.name }))} />
                </div>
              )}
              {/* Prioridad y categoría en lote (misma gestión que en la ficha). */}
              {can('tickets.categorize') && (
                <>
                  <div style={{ minWidth: 140 }}>
                    <Select sm block value="" placeholder="Prioridad…"
                      onChange={(pr) => pr && bulk({ op: 'priority', priority: pr }, 'Prioridad cambiada')}
                      options={Object.entries(meta?.priorities || {}).map(([value, label]) => ({ value, label }))} />
                  </div>
                  <div style={{ minWidth: 160 }}>
                    <Select sm block value="" placeholder="Categoría…"
                      onChange={(cid) => bulk({ op: 'category', category_id: cid || null }, 'Categoría cambiada')}
                      options={[{ value: '', label: 'Sin categoría' }, ...(meta?.categories || []).map((c) => ({ value: String(c.id), label: c.name }))]} />
                  </div>
                </>
              )}
              <span className="spacer" />
              <button className="btn ghost sm" onClick={clearSel}>Cancelar</button>
            </div>
          )}

          {/* --- Tabla --- */}
          {err && rows === null ? (
            <div className="card"><LoadError onRetry={load} msg="No se pudo cargar la bandeja" /></div>
          ) : rows !== null && rows.length === 0 ? (
            <div className="card tk-empty">
              <div className="e-ic"><Icon.check style={{ width: 26, height: 26, fill: 'var(--ink-2)' }} /></div>
              <h3>{refined ? 'Sin resultados' : viewOn(VIEWS[1]) ? '¡Todo respondido!' : 'Nada por aquí'}</h3>
              <p>{refined
                ? 'Ningún ticket coincide con los filtros.'
                : viewOn(VIEWS[1]) ? 'No hay ningún cliente esperando respuesta.'
                : 'Cuando llegue una solicitud aparecerá aquí.'}</p>
            </div>
          ) : (
            <div className="card" style={{ padding: 0, overflowX: 'auto' }}>
              <table className={`tk-table ${density === 'compacta' ? 'compact' : ''}`}>
                <thead>
                  <tr>
                    <th className="tk-chk" onClick={(e) => e.stopPropagation()}>
                      <input type="checkbox" checked={allSelected} onChange={toggleAll} title="Seleccionar todos" />
                    </th>
                    <th>Ticket</th><th>Canal</th><th>Cliente</th><th>Asunto</th><th>Categoría</th><th>Asignado</th>
                    <th>Prioridad</th><th>Estado</th>
                    {canTimes && <><th>T. atención</th><th>T. resolución</th></>}
                    <th>Última actividad</th>
                  </tr>
                </thead>
                <tbody>
                  {rows === null
                    ? Array.from({ length: 8 }).map((_, i) => <SkelRow key={i} canTimes={canTimes} />)
                    : rows.map((t) => {
                    const waiting = t.last_direction === 'in'   // habló el cliente: nos toca
                    const sleeping = t.snoozed_at && (Number(t.snooze_wake_on_reply)
                      || (t.snoozed_until && new Date(t.snoozed_until) > new Date()))
                    // Presencia: otro agente lo tiene abierto AHORA (bloqueo vigente).
                    const lockMin = meta?.lock_minutes || 0
                    const viewing = (lockMin > 0 && t.locked_by && Number(t.locked_by) !== Number(user?.id)
                      && t.locked_at && (Date.now() - new Date(t.locked_at).getTime()) < lockMin * 60000)
                      ? t.locked_name : null
                    return (
                      <tr key={t.id} className={`tk-row ${waiting ? 'wait' : ''} ${sleeping ? 'sleeping' : ''} ${sel.has(t.id) ? 'picked' : ''}`} onClick={() => setOpen(t.id)}
                        tabIndex={0} onKeyDown={teclaAbrir(() => setOpen(t.id))}
                        aria-label={`Ticket ${t.code}, ${t.contact_name || 'sin nombre'}: ${t.subject || 'sin asunto'}. Pulsa Intro para abrir.`}>
                        <td className="tk-chk" onClick={(e) => e.stopPropagation()}>
                          <input type="checkbox" checked={sel.has(t.id)} onChange={() => toggleSel(t.id)} />
                        </td>
                        <td className="tk-code">{t.code}</td>
                        <td><ChannelBadge channel={t.channel} /></td>
                        <td className="tk-cli"><b>{t.contact_name || 'Sin nombre'}</b><small>{t.contact_email || (t.contact_wa ? '+' + t.contact_wa : '—')}</small></td>
                        <td className="tk-subj">
                          {waiting && <span className="dot-wait" title="El cliente escribió lo último: sin responder" />}
                          {t.subject}
                          {/* Solo se marca el SLA que pide atención (vencido o por vencer). */}
                          {(() => { const p = slaPeor(t.sla); return p ? slaChip(p[0], p[1]) : null })()}
                          {sleeping && (
                            <span className="tk-sleep-chip" title={t.snooze_reason || 'Pospuesto'}>
                              💤 {Number(t.snooze_wake_on_reply) ? 'Hasta que responda' : `Hasta ${fmtDate(t.snoozed_until)}`}
                            </span>
                          )}
                          {viewing && (
                            <span className="tk-view-chip" title={`${viewing} está viendo este ticket ahora`}>
                              <span className="tk-view-dot" />{(viewing || '').split(' ')[0]}
                            </span>
                          )}
                          {t.labels?.length > 0 && (
                            <div className="tk-row-tags">
                              {t.labels.map((l) => (
                                <span key={l.id} className="tk-tag sm" style={{ '--tc': l.color }}><span className="tk-tag-dot" />{l.name}</span>
                              ))}
                            </div>
                          )}
                          {/* Al buscar en los mensajes, el trozo encontrado: si no, no se
                              entiende por qué ha salido un ticket cuyo asunto no lo menciona. */}
                          {t.match && (
                            <div className="tk-match">
                              <span className="tk-match-de">
                                {t.match.interna ? 'nota interna' : t.match.de === 'cliente' ? 'del cliente' : 'de soporte'}
                              </span>
                              {resaltar(t.match.texto, f.q)}
                            </div>
                          )}
                        </td>
                        {/* Categoría editable en la propia tabla (como el asignar).
                            stopPropagation para que abrir el desplegable no abra el modal. */}
                        <td onClick={(e) => e.stopPropagation()}>
                          {can('tickets.categorize') ? (
                            <Select sm block value={t.category_id ? String(t.category_id) : ''}
                              onChange={(v) => quickCategory(t.id, v)}
                              options={[{ value: '', label: 'Sin categoría' }, ...(meta?.categories || []).map((c) => ({ value: String(c.id), label: c.name }))]} />
                          ) : (
                            (meta?.categories || []).find((c) => String(c.id) === String(t.category_id))?.name
                              || <span className="tk-time">Sin categoría</span>
                          )}
                        </td>

                        {/* Asignar sin abrir el ticket: un encargado reparte la cola de un vistazo.
                            stopPropagation para que abrir el desplegable no abra el modal. */}
                        <td onClick={(e) => e.stopPropagation()}>
                          {can('tickets.assign') ? (
                            <Select sm block value={String(t.assigned_to || '')}
                              onChange={(uid) => quickAssign(t.id, uid)}
                              options={[{ value: '', label: 'Sin asignar' }, ...(meta?.users || []).map((u) => ({ value: String(u.id), label: u.name }))]} />
                          ) : (t.agent_name || <span className="tk-time">Sin asignar</span>)}
                        </td>
                        <td>{prChip(t.priority, meta)}</td>
                        <td>
                          {stChip(t.status, meta)}
                          {!waiting && t.last_direction === 'out' && <span className="chip answered" title="Ya hemos respondido">✓</span>}
                        </td>
                        {canTimes && <>
                          <td className="tk-time">{fmtMins(t.response_mins)}</td>
                          <td className="tk-time">{fmtMins(t.resolve_mins)}</td>
                        </>}
                        <td className="tk-time" title={fmtDate(t.last_message_at || t.created_at)}>
                          {ago(t.last_message_at || t.created_at)}
                        </td>
                      </tr>
                    )
                  })}
                </tbody>
              </table>
            </div>
          )}

          {/* Paginador: solo estorba si no hay nada que paginar. */}
          {rows !== null && pag.total > 0 && (
            <Paginador page={page} pages={pag.pages} total={pag.total} perPage={perPage}
              mostrados={rows.length} onPage={setPage} onPerPage={cambiarPorPagina} />
          )}

          </>}
        </div>
      </div>

      {open && <TicketModal id={open} meta={meta} user={user} onClose={() => { setOpen(null); load() }} onChange={load}
        onOpenTicket={(tid) => setOpen(tid)} />}

      {/* Fusión lanzada desde la lista. Al terminar se limpia la selección: dejar
          marcados dos tickets que ya son uno solo invita a repetir la acción. */}
      {openFusion && (
        <ModalFusion id={openFusion.id} preselect={openFusion.preselect} meta={meta}
          onClose={() => setOpenFusion(null)}
          onDone={(jefeId, jefeCode) => {
            setOpenFusion(null); setSel(new Set()); load()
            toast(`Tickets fusionados en ${jefeCode}`)
          }} />
      )}

      {bulkMerge && (
        <BulkMergeDialog n={bulkMerge.n} onCancel={() => setBulkMerge(null)}
          onConfirm={async (motivo) => {
            setBulkMerge(null)
            await bulk({ op: 'merge', reason: motivo }, 'Tickets fusionados')
          }} />
      )}
    </>
  )
}

/* ------------------------- Modal: ficha + conversación ------------------------- */

/**
 * PAGINADOR de la lista. Además de mover entre páginas, su trabajo es decir CUÁNTOS
 * tickets hay: antes la lista se cortaba en 200 sin avisar y no había forma de saberlo.
 */
function Paginador({ page, pages, total, perPage, mostrados, onPage, onPerPage }) {
  const desde = (page - 1) * perPage + 1
  const hasta = desde + mostrados - 1

  /* Con muchas páginas no se pintan todas: primera, última, la actual y sus vecinas.
     Los saltos se marcan con «…» para que se vea que falta trozo. */
  const numeros = []
  for (let n = 1; n <= pages; n++) {
    if (n === 1 || n === pages || Math.abs(n - page) <= 1) numeros.push(n)
    else if (numeros[numeros.length - 1] !== '…') numeros.push('…')
  }

  return (
    <div className="tk-pag">
      <span className="tk-pag-n">
        {total === 1 ? '1 ticket' : <><b>{desde}–{hasta}</b> de {total} tickets</>}
      </span>

      <div className="spacer" />

      {pages > 1 && (
        <div className="tk-pag-btns">
          <button className="tk-pag-b" disabled={page <= 1} onClick={() => onPage(page - 1)} title="Anterior">‹</button>
          {numeros.map((n, i) => (n === '…'
            ? <span key={`s${i}`} className="tk-pag-s">…</span>
            : <button key={n} className={`tk-pag-b ${n === page ? 'on' : ''}`} onClick={() => onPage(n)}>{n}</button>
          ))}
          <button className="tk-pag-b" disabled={page >= pages} onClick={() => onPage(page + 1)} title="Siguiente">›</button>
        </div>
      )}

      <label className="tk-pag-pp">
        Ver
        <select value={perPage} onChange={(e) => onPerPage(Number(e.target.value))}>
          {[10, 25, 50, 100].map((n) => <option key={n} value={n}>{n}</option>)}
        </select>
      </label>
    </div>
  )
}

/* --------------------------- Fusionar dos tickets --------------------------
 * Se abre desde TRES sitios (lista con dos marcados, pestaña «Del cliente» y el
 * panel del ticket), así que vive suelto en vez de dentro del modal del ticket.
 *
 * Los candidatos los da el servidor (`mergeable`), no la lista de «Del cliente»
 * que la pantalla ya tiene cargada: esa se busca por CORREO y puede traer tickets
 * de otra ficha de contacto —el mismo señor con ficha de correo y de WhatsApp—, y
 * fusionar exige el mismo contacto exacto.
 * -------------------------------------------------------------------------- */
const MOTIVOS_FUSION = ['Duplicado', 'Mismo asunto', 'Abierto por error', 'Continuación del anterior']
/* El principal por defecto es el MÁS ANTIGUO: es el que el cliente conoce y el que
   suele traer el contexto original. Se puede cambiar en el diálogo. */
const masAntiguo = (a, b) => (new Date(a.created_at) <= new Date(b.created_at) ? a : b)

/*
 * Diálogo de MOTIVO para la fusión en lote (3+ tickets del mismo cliente). Para dos, se
 * usa ModalFusion (permite elegir principal); para 3+, el principal es el más antiguo y
 * aquí solo se pide el motivo obligatorio.
 */
function BulkMergeDialog({ n, onCancel, onConfirm }) {
  const [motivo, setMotivo] = useState('')
  const [yendo, setYendo] = useState(false)

  useEffect(() => {
    const h = (e) => e.key === 'Escape' && !yendo && onCancel()
    document.addEventListener('keydown', h)
    return () => document.removeEventListener('keydown', h)
  }, [onCancel, yendo])

  const go = async () => { if (!motivo.trim() || yendo) return; setYendo(true); await onConfirm(motivo.trim()) }

  return (
    <div className="modal-bg" onMouseDown={(e) => e.target.classList.contains('modal-bg') && !yendo && onCancel()}>
      <div className="modal" style={{ maxWidth: 460 }}>
        <div className="modal-h"><h3>Fusionar {n} tickets</h3>
          <button className="icon-btn" onClick={onCancel}>✕</button></div>
        <div className="modal-body">
          <p className="cfg-hint">Los {n} tickets (del mismo cliente) pasan a ser <b>una sola conversación</b>,
            ordenada por fecha. El más antiguo se queda como principal.</p>
          <div className="field">
            <span className="lbl">Motivo de la fusión</span>
            <textarea rows={3} value={motivo} onChange={(e) => setMotivo(e.target.value)}
              placeholder="Ej: el cliente abrió varias incidencias por el mismo problema" autoFocus />
          </div>
        </div>
        <div className="modal-foot">
          <button className="btn ghost" onClick={onCancel} disabled={yendo}>Cancelar</button>
          <button className="btn primary" onClick={go} disabled={!motivo.trim() || yendo}>
            {yendo ? 'Fusionando…' : 'Fusionar'}
          </button>
        </div>
      </div>
    </div>
  )
}

function ModalFusion({ id, preselect = null, meta, onClose, onDone }) {
  const toast = useToast()
  const [datos, setDatos] = useState(null)
  const [otro, setOtro] = useState(null)
  const [principal, setPrincipal] = useState(null)
  const [motivo, setMotivo] = useState('')
  const [yendo, setYendo] = useState(false)

  useEffect(() => {
    let vivo = true
    api.mergeableTickets(id).then((r) => {
      if (!vivo) return
      if (!r.ok) { toast(r.error || 'No se pudo preparar la fusión', 'err'); onClose(); return }
      setDatos(r)
      // Al venir de la lista o de «Del cliente» ya se sabe el otro ticket.
      if (preselect) setOtro((r.others || []).find((o) => Number(o.id) === Number(preselect)) || null)
    })
    return () => { vivo = false }
  }, [id, preselect]) // eslint-disable-line react-hooks/exhaustive-deps

  useEffect(() => {
    const h = (e) => e.key === 'Escape' && !yendo && onClose()
    document.addEventListener('keydown', h)
    return () => document.removeEventListener('keydown', h)
  }, [onClose, yendo])

  const fusionar = async () => {
    if (!otro || !motivo.trim()) return
    const yo = datos.ticket
    const jefe = principal || masAntiguo(yo, otro)
    const absorbido = Number(jefe.id) === Number(yo.id) ? otro : yo

    setYendo(true)
    const r = await api.mergeTickets(Number(jefe.id), Number(absorbido.id), motivo.trim())
    if (!r.ok) { toast(r.error || 'No se pudo fusionar', 'err'); setYendo(false); return }
    onDone(Number(jefe.id), jefe.code)
  }

  const yo = datos?.ticket
  const otros = datos?.others || []
  const jefe = otro ? (principal || masAntiguo(yo, otro)) : null
  const absorbido = otro ? (Number(jefe.id) === Number(yo.id) ? otro : yo) : null

  return (
    <div className="modal-bg" onMouseDown={(e) => e.target.classList.contains('modal-bg') && !yendo && onClose()}>
      <div className="modal fus-dlg">
        <div className="modal-h"><h3>Fusionar tickets</h3>
          <button className="icon-btn" onClick={onClose}>✕</button></div>

        <div className="modal-body">
          {!datos ? <div className="center-load"><div className="spinner" /></div>
            : !otros.length ? (
              <div className="tk-empty" style={{ padding: 30 }}>
                <p>Este cliente no tiene otro ticket con el que fusionar.</p>
              </div>
            ) : (
              <>
                <p className="cfg-hint">
                  Los mensajes de los dos tickets pasan a formar <b>una sola conversación</b>, ordenados por fecha.
                </p>

                <div className="field">
                  <span className="lbl">¿Con cuál lo fusionas?</span>
                  <div className="fus-lista">
                    {otros.map((o) => (
                      <label key={o.id} className={`fus-op ${Number(otro?.id) === Number(o.id) ? 'on' : ''}`}>
                        <input type="radio" name="fus" checked={Number(otro?.id) === Number(o.id)}
                          onChange={() => { setOtro(o); setPrincipal(null) }} />
                        <span className="fus-op-tx">
                          <span className="fus-op-h">
                            <b className="mono">{o.code}</b>
                            {stChip(o.status, meta, true)}
                            <small>{o.messages} {o.messages === 1 ? 'mensaje' : 'mensajes'} · {fmtDate(o.created_at)}</small>
                          </span>
                          <span className="fus-asunto">{o.subject}</span>
                        </span>
                      </label>
                    ))}
                  </div>
                </div>

                {/* Cuál sobrevive. Se propone el más antiguo, pero se puede cambiar:
                    a veces el bueno es el nuevo (el viejo se abrió por error). */}
                {otro && (
                  <div className="field">
                    <span className="lbl">¿Cuál se queda como principal?</span>
                    <div className="fus-jefe">
                      {[yo, otro].map((x) => (
                        <label key={x.id} className={`fus-op ${Number(jefe.id) === Number(x.id) ? 'on' : ''}`}>
                          <input type="radio" name="fusjefe" checked={Number(jefe.id) === Number(x.id)}
                            onChange={() => setPrincipal(x)} />
                          <span className="fus-op-tx">
                            <span className="fus-op-h">
                              <b className="mono">{x.code}</b>
                              {Number(masAntiguo(yo, otro).id) === Number(x.id) && <small className="fus-tag">el más antiguo</small>}
                            </span>
                            <span className="fus-asunto">{x.subject}</span>
                          </span>
                        </label>
                      ))}
                    </div>
                  </div>
                )}

                {/* MOTIVO, obligatorio: es lo único que explicará dentro de seis meses
                    por qué dos conversaciones son ahora una. Con atajos de un clic,
                    porque obligar a escribirlo a mano acaba en un «-». */}
                {otro && (
                  <div className="field">
                    <span className="lbl">Motivo de la fusión <em>*</em></span>
                    <div className="fus-motivos">
                      {MOTIVOS_FUSION.map((m) => (
                        <button key={m} type="button" className={`chip sm ${motivo === m ? 'on' : ''}`}
                          onClick={() => setMotivo(m)}>{m}</button>
                      ))}
                    </div>
                    <input value={motivo} maxLength={300} onChange={(e) => setMotivo(e.target.value)}
                      placeholder="Elige uno de arriba o escríbelo" />
                  </div>
                )}

                {otro && (
                  <div className="fus-aviso">
                    <Icon.warn />
                    <div>
                      <b>{absorbido.code} se cerrará</b>
                      <small>
                        Sus mensajes pasan a <b>{jefe.code}</b>. El ticket seguirá existiendo para redirigir: si el
                        cliente responde a ese correo antiguo, su respuesta entrará en <b>{jefe.code}</b>.
                        <br />Esto <b>no se puede deshacer</b>.
                      </small>
                    </div>
                  </div>
                )}
              </>
            )}
        </div>

        <div className="modal-foot">
          <button className="btn ghost" onClick={onClose}>Cancelar</button>
          <button className="btn" onClick={fusionar} disabled={!datos || !otro || !motivo.trim() || yendo}>
            {yendo ? 'Fusionando…' : 'Fusionar'}
          </button>
        </div>
      </div>
    </div>
  )
}

function TicketModal({ id, meta, user, onClose, onChange, onOpenTicket }) {
  const toast = useToast()
  const confirm = useConfirm()
  const [d, setD] = useState(null)
  const [view, setView] = useState('chat')   // chat | history | client
  const [clientTickets, setClientTickets] = useState(null)
  const [gate, setGate] = useState(null)     // candados (WhatsApp de soporte sin configurar…)
  const endRef = useRef(null)
  const msgCountRef = useRef(0)
  const lastTicketRef = useRef(null)
  const [composerKey, setComposerKey] = useState(0)   // fuerza recargar el compositor al «Editar» una programada
  const [older, setOlder] = useState([])              // mensajes anteriores cargados a demanda (paginación)
  const [moreOld, setMoreOld] = useState(false)       // ¿hay mensajes antes de los cargados?
  const [loadingOld, setLoadingOld] = useState(false)
  const [loadErr, setLoadErr] = useState(false)       // no se pudo cargar el ticket (red/servidor)
  const [masOpen, setMasOpen] = useState(false)       // menú «Más ⋯» de acciones secundarias
  const masRef = useRef(null)

  const can = (p) => (user?.permissions || []).includes(p)

  // Candados del envío: para saber si se puede responder por WhatsApp (número de soporte).
  useEffect(() => { api.gating().then((g) => setGate(g?.ok ? g : null)) }, [])

  // Cerrar el menú «Más» al clicar fuera.
  useEffect(() => {
    if (!masOpen) return
    const h = (e) => { if (masRef.current && !masRef.current.contains(e.target)) setMasOpen(false) }
    document.addEventListener('mousedown', h)
    return () => document.removeEventListener('mousedown', h)
  }, [masOpen])

  // Guarda de VIGENCIA: cada carga lleva un número de secuencia; si al resolver ya hay
  // otra más nueva (se saltó de ticket), se descarta. Y si la petición falla (red/500),
  // se marca error en vez de dejar el spinner girando para siempre.
  const reqSeq = useRef(0)
  const load = useCallback(() => {
    const seq = ++reqSeq.current
    return api.getTicket(id).then((r) => {
      if (seq !== reqSeq.current) return          // llegó tarde: hay una carga más nueva
      if (r?.ok && r.ticket) { setD(r); setLoadErr(false) } else setLoadErr(true)
    })
  }, [id])
  useEffect(() => { load() }, [load])

  /* Paginación del hilo: al cambiar de ticket se olvidan los anteriores cargados; y
     mientras no se haya cargado ninguno, «hay más» lo dice el propio detalle. */
  useEffect(() => { setOlder([]); setMoreOld(false) }, [id])
  useEffect(() => { if (d && older.length === 0) setMoreOld(!!d.messages_more) }, [d, older.length])

  const cargarAnteriores = async () => {
    const shown = [...older, ...(d?.messages || [])]
    const oldestId = shown.reduce((m, x) => Math.min(m, x.id), Infinity)
    if (!isFinite(oldestId) || loadingOld) return
    setLoadingOld(true)
    const r = await api.olderMessages(id, oldestId).catch(() => null)
    setLoadingOld(false)
    if (r?.ok) { setOlder((o) => [...(r.messages || []), ...o]); setMoreOld(!!r.more) }
    else toast('No se pudieron cargar los mensajes anteriores', 'err')
  }

  /* Al SALTAR a otro ticket (desde «Del cliente», o tras una fusión) se vuelve a la
     conversación. El modal no se desmonta, así que sin esto aterrizas en el nuevo
     ticket mirando la pestaña anterior y parece que no ha pasado nada. */
  useEffect(() => { setView('chat') }, [id])

  /* Bloqueo: al cerrar el ticket —o al saltar a otro— se suelta, para que otro
     agente pueda entrar sin esperar a que caduque. La limpieza del efecto recibe
     el id ANTERIOR, que es justo el que hay que soltar. */
  useEffect(() => () => { api.unlockTicket(id) }, [id])

  /*
   * Tickets del MISMO cliente. Se buscan por su CORREO, no por la ficha de
   * contacto: si el cliente escribió por correo y por WhatsApp tendrá dos fichas,
   * y por correo se recuperan igualmente sus tickets. Si no tiene correo, se cae
   * al contacto (es lo único que lo identifica).
   */
  useEffect(() => {
    const t = d?.ticket
    if (!t) return
    let vivo = true   // guarda de vigencia: si se salta de ticket, no pisar con datos viejos
    const filtro = t.contact_email ? { contact_email: t.contact_email } : { contact: t.contact_id }
    api.listTickets({ ...filtro, status: 'all' })
      .then((r) => { if (vivo) setClientTickets((r.tickets || []).filter((x) => Number(x.id) !== Number(id))) })
      .catch(() => { if (vivo) setClientTickets([]) })
    return () => { vivo = false }
  }, [d?.ticket?.contact_email, d?.ticket?.contact_id, id]) // eslint-disable-line react-hooks/exhaustive-deps
  // Auto-scroll INTELIGENTE: baja al fondo solo al abrir el ticket (carga inicial) o
  // si llegan mensajes NUEVOS y ya estabas abajo. Si estás leyendo arriba, no te mueve
  // (antes bajaba en cada refresco por tiempo, aunque no hubiera nada nuevo).
  useEffect(() => {
    if (!d) return
    const cont = endRef.current?.parentElement
    const count = d.messages?.length || 0
    const ticketChanged = lastTicketRef.current !== d.ticket?.id
    const nearBottom = cont ? (cont.scrollHeight - cont.scrollTop - cont.clientHeight < 140) : true
    if (ticketChanged || (count > msgCountRef.current && nearBottom)) {
      endRef.current?.scrollIntoView({ behavior: ticketChanged ? 'auto' : 'smooth' })
    }
    msgCountRef.current = count
    lastTicketRef.current = d.ticket?.id
  }, [d])

  // Si llega un mensaje AL TICKET QUE ESTOY MIRANDO, aparece solo en el hilo.
  useEffect(() => onTicketActivity((e) => {
    if (!e.ticketId || Number(e.ticketId) === Number(id)) load()
  }), [id, load])
  useEffect(() => {
    const h = (e) => e.key === 'Escape' && onClose()
    document.addEventListener('keydown', h)
    return () => document.removeEventListener('keydown', h)
  }, [onClose])

  // Guarda una respuesta SALIENTE como «respuesta efectiva» (memoria que se reutiliza).
  const guardarEfectiva = async (messageId) => {
    const r = await api.saveEffective(id, messageId)
    if (r.ok) toast(r.dup ? 'Esa respuesta ya estaba guardada' : '⭐ Guardada como respuesta efectiva')
    else toast(r.error || 'No se pudo guardar', 'err')
  }

  const setStatus = async (status) => {
    // ¿Se cierra fuera de plazo? Hay que mirarlo ANTES: al resolver, el reloj para
    // y el estado pasa de «vencido» a «cumplido fuera de plazo».
    const tarde = ['resuelto', 'cerrado'].includes(status) && seFueDePlazo(d?.ticket?.sla)

    const r = await api.setTicketStatus(id, status)
    if (!r.ok) { toast(r.error || 'Error', 'err'); return }

    toast('Estado actualizado')
    load(); onChange?.()

    /*
     * Solo se pregunta cuando de verdad se pasó, y DESPUÉS de guardar: el ticket
     * queda resuelto se escriba o no la explicación. Justificar es opcional; si
     * fuese obligatorio, la gente escribiría «-» y no serviría de nada.
     */
    if (tarde) setJustificar(true)
  }
  const assign = async (user_id) => {
    const r = await api.assignTicket(id, user_id || null)
    if (r.ok) { toast('Ticket asignado'); load(); onChange?.() } else toast(r.error || 'Error', 'err')
  }
  const cambiarCategoria = async (category_id) => {
    const r = await api.setTicketCategory(id, category_id || null)
    if (r.ok) { toast('Categoría actualizada'); load(); onChange?.() } else toast(r.error || 'Error', 'err')
  }
  const posponer = async (payload) => {
    const r = await api.snoozeTicket(id, payload)
    if (r.ok) { toast('Ticket pospuesto 😴'); load(); onChange?.() } else toast(r.error || 'No se pudo posponer', 'err')
  }
  const reactivar = async () => {
    const r = await api.unsnoozeTicket(id)
    if (r.ok) { toast('Ticket reactivado'); load(); onChange?.() } else toast(r.error || 'Error', 'err')
  }
  const cancelarProgramada = async (sid) => {
    const r = await api.cancelScheduled(sid)
    if (r?.ok) { toast('Programación cancelada'); load() } else toast('No se pudo cancelar', 'err')
  }
  const editarProgramada = async (s) => {
    // Devuelve el texto al compositor (vía su borrador) y cancela la programación.
    try { localStorage.setItem(`tk_draft_${id}`, JSON.stringify({ html: s.body, note: false })) } catch { /* sin storage */ }
    const r = await api.cancelScheduled(s.id)
    if (r?.ok) { setComposerKey((k) => k + 1); toast('Cargada en el compositor para editar'); load() }
    else toast('No se pudo editar', 'err')
  }
  const del = async () => {
    const ok = await confirm({
      title: 'Eliminar ticket',
      message: `¿Eliminar el ticket ${d?.ticket?.code || ''} por completo? Se borrarán su conversación, notas, historial y adjuntos. Esta acción NO se puede deshacer.`,
      danger: true, confirmText: 'Eliminar ticket',
    })
    if (!ok) return
    const r = await api.deleteTicket(id)
    if (r.ok) { toast(`Ticket ${r.code || ''} eliminado`); onClose(); onChange?.() }
    else toast(r.error || 'No se pudo eliminar', 'err')
  }

  // Fusionar: el diálogo es un componente aparte (ModalFusion), porque se abre
  // desde tres sitios distintos. Aquí solo se dice CON QUÉ ticket se entra.
  const [fusion, setFusion] = useState(null)   // null | { preselect }

  // Generar PDF: se abre un diálogo con opciones (notas internas / imágenes) antes de descargar.
  const [pdfOpts, setPdfOpts] = useState(null)   // null | { notes, images, busy }
  const [editReq, setEditReq] = useState(null)   // null | { email, name } — cambiar solicitante
  const [justificar, setJustificar] = useState(false)   // se cerró fuera de plazo
  const [motivo, setMotivo] = useState('')
  const genPdf = async () => {
    setPdfOpts((o) => ({ ...o, busy: true }))
    const r = await api.ticketPdf(id, { notes: pdfOpts.notes, images: pdfOpts.images })
    if (r.ok) {
      const url = URL.createObjectURL(r.blob)
      const a = document.createElement('a')
      a.href = url; a.download = `ticket-${d?.ticket?.code || id}.pdf`
      document.body.appendChild(a); a.click(); a.remove()
      URL.revokeObjectURL(url)
      toast('PDF generado'); setPdfOpts(null)
    } else { toast('No se pudo generar el PDF', 'err'); setPdfOpts((o) => ({ ...o, busy: false })) }
  }

  const t = d?.ticket

  // ¿Este ticket está dormido (pospuesto)? y ¿NO es mío? (para el panel de acciones).
  const dormido = t?.snoozed_at && (Number(t.snooze_wake_on_reply)
    || (t.snoozed_until && new Date(t.snoozed_until) > new Date()))
  const masMio = Number(t?.assigned_to) !== Number(user?.id)

  // Hilo a pintar: los anteriores cargados + la página del detalle, sin duplicar y por id.
  const mensajes = (() => {
    const vistos = new Set()
    return [...older, ...(d?.messages || [])]
      .filter((m) => (vistos.has(m.id) ? false : vistos.add(m.id)))
      .sort((a, b) => a.id - b.id)
  })()

  return (
    <div className="modal-bg" onClick={(e) => e.target.classList.contains('modal-bg') && onClose()}>
      <div className="tk-modal">
        {!t ? (loadErr ? (
          <div className="center-load tk-load-err">
            <p>No se pudo cargar el ticket.</p>
            <button className="btn" onClick={() => { setLoadErr(false); load() }}>Reintentar</button>
            <button className="btn ghost" onClick={onClose}>Cerrar</button>
          </div>
        ) : <div className="center-load"><div className="spinner" /></div>) : (
          <>
            {/* --- Panel izquierdo: la ficha --- */}
            <aside className="tkm-side">
              {/* Cliente: lo primero, con cara. Es con quien estás hablando. El lápiz
                  permite CAMBIAR el solicitante (corregir el correo mal escrito). */}
              <div className="tkm-cli">
                <span className="tkm-av">{(t.contact_name || '?').slice(0, 1).toUpperCase()}</span>
                <div className="tkm-cli-tx">
                  <b>{t.contact_name || 'Sin nombre'}</b>
                  <small>{t.contact_email || (t.contact_wa ? '+' + t.contact_wa : 'Sin datos de contacto')}</small>
                </div>
                {can('tickets.reply') && (
                  <button className="tkm-cli-edit" title="Cambiar solicitante (corregir el correo)"
                    onClick={() => setEditReq({ email: t.contact_email || '', name: t.contact_name || '' })}>
                    <Icon.pencil />
                  </button>
                )}
              </div>
              {t.contact_email && t.contact_wa && (
                <div className="tkm-extra"><Icon.phone /> +{t.contact_wa}</div>
              )}

              {/* ACCIONES ARRIBA: lo que más se usa, a la vista y sin scroll. */}
              <div className="tkm-act">
                {can('tickets.close') ? (
                  <div className="field">
                    <span className="lbl">Estado</span>
                    <Select block value={t.status} onChange={setStatus}
                      options={Object.entries(meta?.statuses || {}).map(([value, label]) => ({ value, label }))} />
                  </div>
                ) : (
                  <div className="tkm-row"><span>Estado</span>{stChip(t.status, meta)}</div>
                )}

                {can('tickets.assign') ? (
                  <div className="field">
                    <span className="lbl">Asignado a</span>
                    <Select block value={String(t.assigned_to || '')} onChange={assign}
                      options={[{ value: '', label: 'Sin asignar' }, ...(meta?.users || []).map((u) => ({ value: String(u.id), label: u.name }))]} />
                  </div>
                ) : (
                  <div className="tkm-row"><span>Asignado a</span><b>{t.agent_name || 'Sin asignar'}</b></div>
                )}

                {/* Accesos rápidos (iconos): Posponer · Fusionar · PDF · Más ⋯ */}
                <div className="tkm-quick">
                  {can('tickets.reply') && !t.merged_into_id && t.status !== 'cerrado' && !dormido && (
                    <SnoozeControl t={t} onSnooze={posponer} onWake={reactivar} compact />
                  )}
                  {can('tickets.reply') && !t.merged_into_id && (
                    <button className="tkm-q" onClick={() => setFusion({ preselect: null })} title="Fusionar tickets">
                      <Icon.merge /><small>Fusionar</small>
                    </button>
                  )}
                  <button className="tkm-q" onClick={() => setPdfOpts({ notes: true, images: true, busy: false })} title="Generar PDF">
                    <Icon.file /><small>PDF</small>
                  </button>
                  {(masMio || can('tickets.delete')) && (
                    <div className="tkm-q-wrap" ref={masRef}>
                      <button className="tkm-q" onClick={() => setMasOpen((o) => !o)} title="Más acciones">
                        <Icon.settings /><small>Más</small>
                      </button>
                      {masOpen && (
                        <div className="tkm-mas">
                          {masMio && (
                            <button onClick={() => { setMasOpen(false); assign(String(user.id)) }}>
                              <Icon.user /> Asignármelo a mí
                            </button>
                          )}
                          {can('tickets.delete') && (
                            <button className="danger" onClick={() => { setMasOpen(false); del() }}>
                              <Icon.trash /> Eliminar ticket
                            </button>
                          )}
                        </div>
                      )}
                    </div>
                  )}
                </div>

                {/* Si está pospuesto, una línea con hasta cuándo + reactivar. */}
                {dormido && (
                  <div className="tkm-snz-live">
                    <Icon.clock />
                    <span>{Number(t.snooze_wake_on_reply) ? 'Pospuesto hasta que responda' : `Pospuesto hasta ${fmtDate(t.snoozed_until)}`}</span>
                    <button onClick={reactivar}>Reactivar</button>
                  </div>
                )}
              </div>

              {/* PROPIEDADES compactas: una línea cada una. */}
              <div className="tkm-props">
                <div className="tkm-row"><span>Referencia</span><b className="tk-code">{t.code}</b></div>
                <div className="tkm-row"><span>Origen</span><ChannelBadge channel={t.channel} /></div>
                <div className="tkm-row"><span>Prioridad</span>{prChip(t.priority, meta)}</div>
                <div className="tkm-row"><span>Categoría</span>
                  {can('tickets.categorize') ? (
                    <Select value={t.category_id ? String(t.category_id) : ''} onChange={(v) => cambiarCategoria(v || null)}
                      options={[{ value: '', label: 'Sin categoría' }, ...(meta?.categories || []).map((c) => ({ value: String(c.id), label: c.name }))]} />
                  ) : (
                    <b>{(meta?.categories || []).find((c) => String(c.id) === String(t.category_id))?.name || 'Sin categoría'}</b>
                  )}
                </div>
                <div className="tkm-row"><span>Creado</span><b>{fmtDate(t.created_at)}</b></div>
              </div>

              {/* Etiquetas: chips editables (bloque propio, corto). */}
              <TicketLabels ticketId={t.id} initial={t.labels} />

              {/* SECUNDARIO plegable: Tiempos abierto por defecto; el resto cerrado
                  (se recuerda lo que abras). Así la ficha cabe sin scroll. */}
              {can('tickets.view_times') && (
                <Collapsible id="times" title="Tiempos (SLA)" defaultOpen>
                  <div className="tkm-time blue">
                    <b>Primera atención</b>
                    <span>{t.first_response_at ? fmtDate(t.first_response_at) : 'Pendiente de responder'}</span>
                    <SlaLinea sla={t.sla?.response} />
                  </div>
                  <div className="tkm-time green">
                    <b>Resolución</b>
                    <span>{t.resolved_at ? fmtDate(t.resolved_at) : 'Aún sin resolver'}</span>
                    <SlaLinea sla={t.sla?.resolve} />
                  </div>
                </Collapsible>
              )}

              {(can('contacts.edit') || !!d.cc_sugerido?.length) && (
                <Collapsible id="sede" title={`Sede y copias${d.cc_sugerido?.length ? ` (${d.cc_sugerido.length})` : ''}`}>
                  {can('contacts.edit') && <SedeField contactId={t.contact_id} value={t.contact_sede_id} />}
                  {!!d.cc_sugerido?.length && (
                    <div className="tkm-cc">
                      <div className="tkm-cc-h"><Icon.user /> En copia <span>{d.cc_sugerido.length}</span></div>
                      <div className="tkm-cc-list">
                        {d.cc_sugerido.map((c) => <span key={c} title={c}>{c}</span>)}
                      </div>
                      <small>Se les incluye al responder, salvo que los quites.</small>
                    </div>
                  )}
                </Collapsible>
              )}

              {!!t.custom_fields?.length && (
                <Collapsible id="fields" title="Campos personalizados">
                  <TicketCustomFields ticketId={t.id} initial={t.custom_fields} bare />
                </Collapsible>
              )}

              {t.rating && (
                <Collapsible id="rating" title="Valoración del cliente">
                  <div className="tkm-rating">
                    <div className="tkm-stars" title={`${t.rating.score} de 5`}>
                      {[1, 2, 3, 4, 5].map((n) => (
                        <Icon.star key={n} className={n <= t.rating.score ? 'on' : 'off'} />
                      ))}
                      <b>{t.rating.score}/5</b>
                    </div>
                    {t.rating.comment && <p className="tkm-rating-cmt">“{t.rating.comment}”</p>}
                  </div>
                </Collapsible>
              )}
            </aside>

            {/* --- Panel derecho: la conversación --- */}
            <section className="tkm-main">
              <div className="tkm-main-h">
                <div className="tkm-tabs">
                  <button className={view === 'chat' ? 'on' : ''} onClick={() => setView('chat')}>Conversación</button>
                  <button className={view === 'history' ? 'on' : ''} onClick={() => setView('history')}>
                    Historial {d.events?.length ? <span className="tkm-tab-n">{d.events.length}</span> : null}
                  </button>
                  {/* Otros tickets del MISMO cliente (se buscan por su correo). */}
                  <button className={view === 'client' ? 'on' : ''} onClick={() => setView('client')}>
                    Del cliente {clientTickets?.length ? <span className="tkm-tab-n">{clientTickets.length}</span> : null}
                  </button>
                </div>
              </div>
              <button className="icon-btn tk-close" onClick={onClose} title="Cerrar (Esc)">✕</button>

              {/* --- DEL CLIENTE: sus otros tickets, localizados por su correo --- */}
              {view === 'client' ? (
                <div className="tkm-history">
                  <div className="tkm-client-h">
                    <b>{t.contact_name || 'Cliente'}</b>
                    <span>{t.contact_email || (t.contact_wa ? '+' + t.contact_wa : 'sin datos')}</span>
                  </div>
                  {clientTickets === null
                    ? <div className="center-load"><div className="spinner" /></div>
                    : clientTickets.length === 0
                      ? <div className="tk-empty" style={{ padding: 40 }}><p>Este cliente no tiene más tickets.</p></div>
                      : (
                        <table className="tk-table ct-tickets">
                          <thead><tr><th>Referencia</th><th>Asunto</th><th>Estado</th><th>Prioridad</th><th>Última actividad</th><th></th></tr></thead>
                          <tbody>
                            {clientTickets.map((x) => (
                              <tr key={x.id} onClick={() => onOpenTicket?.(x.id)} title="Abrir este ticket"
                                tabIndex={0} onKeyDown={teclaAbrir(() => onOpenTicket?.(x.id))}
                                aria-label={`Ticket ${x.code}: ${x.subject || 'sin asunto'}. Pulsa Intro para abrir.`}>
                                <td><b className="mono">{x.code}</b></td>
                                <td>{x.subject}</td>
                                <td>{stChip(x.status, meta, true)}</td>
                                <td>{prChip(x.priority, meta, true)}</td>
                                <td>{fmtDate(x.last_message_at || x.created_at)}</td>
                                {/* Aquí es donde se ve que dos son lo mismo, así que aquí
                                    está el botón. `stopPropagation`: la fila entera abre
                                    el ticket, y sin esto fusionar lo abriría por detrás. */}
                                <td className="ct-acc">
                                  {can('tickets.reply') && !t.merged_into_id && !x.merged_into_id && (
                                    <button className="btn ghost sm" title="Fusionar con este ticket"
                                      onClick={(e) => { e.stopPropagation(); setFusion({ preselect: x.id }) }}>
                                      <Icon.merge /> Fusionar
                                    </button>
                                  )}
                                </td>
                              </tr>
                            ))}
                          </tbody>
                        </table>
                      )}
                </div>
              ) : view === 'history' ? (
                <div className="tkm-history">
                  {(!d.events || d.events.length === 0)
                    ? <div className="tk-empty" style={{ padding: 40 }}><p>Sin movimientos registrados.</p></div>
                    : d.events.map((e, i) => (
                      <div key={i} className="ev-row">
                        <span className={`ev-ic ev-${e.type}`}>{EV_ICON[e.type] || '•'}</span>
                        <div className="ev-tx">
                          <div className="ev-what">{describeEvent(e, meta)}</div>
                          {/* El PORQUÉ, cuando el evento lo trae (hoy, la fusión). */}
                          {e.note && <div className="ev-why">«{e.note}»</div>}
                          <div className="ev-meta">
                            {fmtDate(e.created_at)}{e.user_name ? ` · ${e.user_name}` : ' · sistema'}
                          </div>
                        </div>
                      </div>
                    ))}
                </div>
              ) : (
              <div className="tkm-thread">
                {mensajes.length === 0 && <div className="tk-empty"><p>Este ticket aún no tiene mensajes.</p></div>}
                {/* Cargar mensajes anteriores (hilos largos de WhatsApp): no se traen todos de golpe. */}
                {moreOld && (
                  <button className="tk-loadmore" disabled={loadingOld} onClick={cargarAnteriores}>
                    {loadingOld ? 'Cargando…' : '↑ Ver mensajes anteriores'}
                  </button>
                )}
                {(() => {
                  // Separador «conversación más reciente»: solo en tickets ABIERTOS
                  // (en los cerrados es todo historial) y si hay mensajes anteriores.
                  const abierto = !['resuelto', 'cerrado'].includes(t.status)
                  const convSince = abierto ? t.conversation_since : null
                  let sepPuesto = false
                  return mensajes.map((m, i) => {
                  const out = m.direction === 'out'
                  const sep = convSince && !sepPuesto && i > 0 && m.created_at >= convSince
                  if (sep) sepPuesto = true
                  return (
                    <Fragment key={m.id}>
                    {sep && <div className="tk-conv-sep"><span><Icon.chat style={{ width: 13, height: 13, fill: 'currentColor' }} /> Conversación más reciente</span></div>}
                    <div className={`tk-msg ${out ? 'out' : 'in'} ${Number(m.is_internal_note) ? 'note' : ''} ${m.channel === 'email' && !out ? 'is-email' : ''}`}>
                      <span className={`tk-av ${Number(m.is_internal_note) ? 'note' : out ? 'sop' : 'cli'}`}>
                        {Number(m.is_internal_note)
                          ? <Icon.note style={{ width: 15, height: 15, fill: 'currentColor' }} />
                          : out ? <Icon.headset style={{ width: 15, height: 15, fill: 'currentColor' }} /> : <Icon.user style={{ width: 15, height: 15, fill: 'currentColor' }} />}
                      </span>
                      <div className="tk-bub">
                        <div className="b-who">
                          {/* Nota interna: badge bien visible + quién la escribió. Si no,
                              el agente concreto, «Automático» (bot) o el cliente. */}
                          {Number(m.is_internal_note)
                            ? <>
                                <span className={`note-badge ${m.status === 'sla_justificacion' ? 'sla' : ''}`}>
                                  {m.status === 'sla_justificacion'
                                    ? <><Icon.clock /> Motivo del retraso</>
                                    : <><Icon.note /> Nota interna</>}
                                </span>
                                {m.author_name && <span className="note-author"> · {m.author_name}</span>}
                              </>
                            : out
                              ? (m.author_name || 'Automático')
                              : (t.contact_name || 'Cliente')}
                          {/* Guardar una respuesta buena en la memoria de respuestas efectivas. */}
                          {out && !Number(m.is_internal_note) && (m.body || m.type === 'text') && (
                            <button type="button" className="tk-star" title="Guardar como respuesta efectiva"
                              onClick={() => guardarEfectiva(m.id)}><Icon.star /></button>
                          )}
                        </div>

                        {/* Multimedia de WhatsApp (media_url = id de Meta; se baja por el proxy). */}
                        {m.media_url && (m.type === 'image' || m.type === 'sticker') && (
                          <a href={mediaUrl(m.media_url)} target="_blank" rel="noreferrer" className={`tk-media-link ${m.type === 'sticker' ? 'is-sticker' : ''}`}>
                            <img className="tk-media" src={mediaUrl(m.media_url)} loading="lazy" alt="" />
                          </a>
                        )}
                        {m.media_url && m.type === 'video' && (
                          <div className="tk-video"><video controls preload="metadata" src={mediaUrl(m.media_url)} /></div>
                        )}
                        {m.media_url && m.type === 'audio' && <WaAudio src={mediaUrl(m.media_url)} />}
                        {m.media_url && m.type === 'document' && (
                          <a className="tk-doc" href={mediaUrl(m.media_url)} target="_blank" rel="noreferrer" download>
                            <span className="tk-doc-ic"><Icon.file /></span>
                            <span className="tk-doc-tx"><b>Documento</b><small>Abrir o descargar</small></span>
                            <Icon.download />
                          </a>
                        )}

                        {/* Ubicación: tarjeta con enlace al mapa, no las coordenadas crudas. */}
                        {m.type === 'location'
                          ? (() => {
                              const c = (m.body || '').match(/(-?\d+\.\d+)\s*,\s*(-?\d+\.\d+)/)
                              return c
                                ? <a className="tk-loc" href={`https://www.google.com/maps?q=${c[1]},${c[2]}`} target="_blank" rel="noreferrer">
                                    <span className="tk-loc-pin">📍</span>
                                    <span className="tk-loc-tx"><b>Ubicación compartida</b><small>Ver en el mapa →</small></span>
                                  </a>
                                : m.body
                            })()
                          /* is_html = HTML ya saneado en el servidor. Si no, texto plano
                             (WhatsApp/correo) y React lo escapa solo. El «[tipo]» solo se
                             muestra si NO hay ni texto ni multimedia. */
                          : Number(m.is_html)
                            ? <div className={`b-html${m.channel === 'email' ? ' b-email' : ''}`} dangerouslySetInnerHTML={{ __html: m.body }} />
                            : (m.body || (!m.media_url && <i>[{m.type}]</i>))}

                        {/* Adjuntos: se excluyen las imágenes EN LÍNEA (inline), que ya
                            se muestran dentro del cuerpo del correo (firma, etc.). */}
                        {(() => {
                          const atts = (m.attachments || []).filter((a) => !a.inline)
                          return atts.length > 0 && (
                            <div className="b-att">
                              {atts.map((a) => (
                                <a key={a.id} href={api.attachmentUrl(a.id)} target="_blank" rel="noreferrer"
                                  className="att" title={`${a.name} · abrir`}>
                                  {/* Miniatura si es imagen; si no, icono de fichero. */}
                                  {a.is_image
                                    ? <img className="att-thumb" src={api.attachmentUrl(a.id)} alt={a.name} />
                                    : <span className="att-ico"><Icon.file /></span>}
                                  <span className="att-meta"><b>{a.name}</b><small>{fmtSize(a.size)}</small></span>
                                </a>
                              ))}
                            </div>
                          )
                        })()}

                        {/* Quién más iba en el correo: se ve en el hilo, no solo al responder. */}
                        {(m.cc || m.bcc) && (
                          <div className="b-cc">
                            {m.cc && <span><b>Cc</b> {m.cc}</span>}
                            {m.bcc && <span title="Copia oculta: el resto de destinatarios no la vieron"><b>Cco</b> {m.bcc}</span>}
                          </div>
                        )}

                        <div className="b-t">{fmtTime(m.created_at)}
                          {/* Estado de entrega del WhatsApp saliente: enviado/entregado/leído/fallido */}
                          {out && m.channel === 'whatsapp' && !Number(m.is_internal_note) && m.status && (
                            m.status === 'failed'
                              ? <span className="wa-ack fail" title={m.delivery_error || 'No entregado'}><Icon.warn /> No entregado</span>
                              : <span className={`wa-ack ${m.status}`} title={m.status === 'read' ? 'Leído' : m.status === 'delivered' ? 'Entregado' : 'Enviado'}>
                                  {m.status === 'sent' ? '✓' : '✓✓'}
                                </span>
                          )}
                        </div>
                      </div>
                    </div>
                    </Fragment>
                  )
                  })
                })()}

                {/* Respuestas PROGRAMADAS pendientes: aún no se han enviado (el cliente no
                    las ve). Se pueden editar (vuelven al compositor) o cancelar. */}
                {(t.scheduled || []).map((s) => (
                  <div key={s.id} className="tk-sched">
                    <div className="tk-sched-h"><Icon.clock /> Respuesta programada</div>
                    <div className="tk-sched-body" dangerouslySetInnerHTML={{ __html: s.body }} />
                    <div className="tk-sched-foot">
                      <span className="tk-sched-when">📤 Saldrá el <b>{fmtDate(s.send_at)}</b>{s.author_name ? ` · ${s.author_name}` : ''}</span>
                      {can('tickets.reply') && <>
                        <button className="tk-sched-act" onClick={() => editarProgramada(s)}>Editar</button>
                        <button className="tk-sched-act" onClick={() => cancelarProgramada(s.id)}>Cancelar</button>
                      </>}
                    </div>
                  </div>
                ))}

                <div ref={endRef} />
              </div>
              )}

              {/* Otro agente lo está atendiendo: se avisa y no se deja escribir encima. */}
              {view === 'chat' && d.lock && !d.lock.mine && (
                <div className="tk-locked">
                  <Icon.lock />
                  <span><b>{d.lock.user_name || 'Otro agente'}</b> está atendiendo este ticket ahora mismo.
                    Se liberará solo en unos minutos si deja de mirarlo.</span>
                </div>
              )}

              {/* Este ticket se fusionó en otro: aquí ya no se contesta, la
                  conversación viva está en el principal. */}
              {view === 'chat' && t.merged_into_id && (
                <div className="tk-fusionado">
                  <Icon.merge />
                  <div>
                    <b>Este ticket se fusionó en {t.merged_into_code}</b>
                    <small>Sus mensajes están allí. Las respuestas del cliente a este hilo también entran allí.</small>
                  </div>
                  <button className="btn sm" onClick={() => onOpenTicket?.(Number(t.merged_into_id))}>
                    Ir a {t.merged_into_code}
                  </button>
                </div>
              )}

              {/* El editor solo en la conversación (no en el historial). */}
              {view === 'chat' && !t.merged_into_id && (
                <Composer
                  // Remonta al cambiar de ticket (si no, el borrador del anterior se
                  // quedaba en pantalla al saltar sin cerrar el modal) y al «Editar» una
                  // respuesta programada (composerKey).
                  key={`${id}-${composerKey}`}
                  ticketId={id}
                  // Variables de las respuestas predefinidas: se sustituyen al insertar.
                  cannedVars={{
                    cliente: t.contact_name || 'cliente',
                    codigo:  t.code || '',
                    agente:  user?.name || '',
                    sede:    t.contact_sede_name || '',
                  }}
                  // Agentes mencionables con «@» en la nota interna (excluye al propio).
                  mentionUsers={(meta?.users || []).filter((u) => u.id !== user?.id)}
                  // Destinatarios solo en correo: en WhatsApp no hay copias que valgan.
                  to={d.ticket.channel === 'email' ? d.ticket.contact_email : null}
                  // Programar el envío: solo por correo (WhatsApp tiene ventana de 24h).
                  canSchedule={d.ticket.channel === 'email'}
                  ccSugerido={d.cc_sugerido || []}
                  disabled={!can('tickets.reply') || (d.lock && !d.lock.mine)}
                  disabledHint={!can('tickets.reply')
                    ? 'No tienes permiso para responder tickets'
                    : (d.lock && !d.lock.mine)
                      ? `${d.lock.user_name || 'Otro agente'} lo está atendiendo`
                      : undefined}
                  // Candado del ENVÍO al cliente por WhatsApp (número de soporte sin
                  // configurar). No afecta a la nota interna ni a cambiar estados.
                  replyLock={d.ticket.channel === 'whatsapp' ? (gate?.features?.wa_ticket_reply || null) : null}
                  replyNote={d.ticket.channel === 'whatsapp' && gate?.wa_soporte === 'prueba'
                    ? 'Modo prueba: solo se puede escribir a destinatarios registrados en Meta.'
                    : ''}
                  // La IA propone un borrador con las FAQs + el historial del cliente.
                  onAiSuggest={async () => {
                    const r = await api.aiDraft(id)
                    if (!r.ok) { toast(r.error || 'La IA no pudo proponer una respuesta', 'err'); return r }
                    toast(r.modo === 'soporteqa'
                      ? (r.foto
                          ? `🏷️ Borrador de soporteQA (leyó la foto${r.barcode ? ` · código ${r.barcode}` : ''}) — revísalo antes de enviar`
                          : '🤖 Borrador de soporteQA — revísalo antes de enviar')
                      : r.modo === 'simulado'
                        ? '✨ Borrador simulado cargado — revísalo antes de enviar (sin clave de IA)'
                        : '✨ Borrador de la IA cargado — revísalo antes de enviar')
                    return r
                  }}
                  onSend={async ({ html, files, internal, cc, bcc, mentions, schedule, send_at }) => {
                    if (internal) {
                      const r = await api.ticketNote(id, html, false, mentions)
                      if (r.ok) {
                        toast(mentions?.length ? `📝 Nota guardada · avisados ${mentions.length}` : '📝 Nota interna guardada')
                        load(); onChange?.()
                      }
                      else toast(r.error || 'No se pudo guardar la nota', 'err')
                    } else {
                      const r = await api.ticketReply(id, html, files, cc, bcc, schedule, send_at)
                      if (r.ok) {
                        if (r.scheduled) toast(`⏱ Respuesta programada para ${fmtDate(r.send_at)}`)
                        else toast(d.ticket.channel === 'whatsapp' ? '💬 Respuesta enviada por WhatsApp' : '✉️ Respuesta enviada por correo')
                        if (r.warnings?.length) toast(r.warnings.join(' · '), 'err')
                        load(); onChange?.()
                      } else toast(r.error || 'No se pudo enviar la respuesta', 'err')
                    }
                  }}
                />
              )}
            </section>
          </>
        )}
      </div>

      {/* Fusionar. El diálogo vive fuera (ModalFusion): se abre igual desde aquí,
          desde la lista con dos tickets marcados y desde la pestaña «Del cliente». */}
      {fusion && (
        <ModalFusion id={id} preselect={fusion.preselect} meta={meta}
          onClose={() => setFusion(null)}
          onDone={(jefeId, jefeCode) => {
            setFusion(null)
            toast(`Tickets fusionados en ${jefeCode}`)
            onChange?.()
            // Si el que sobrevive es el otro, se salta a él: la conversación está allí.
            if (Number(jefeId) !== Number(id)) onOpenTicket?.(Number(jefeId)); else load()
          }} />
      )}

      {/* Se cerró fuera de plazo: se ofrece dejar constancia del motivo. */}
      {editReq && (
        <div className="modal-bg" onMouseDown={(e) => e.target.classList.contains('modal-bg') && setEditReq(null)}>
          <div className="modal" style={{ maxWidth: 440 }}>
            <div className="modal-h"><h3>Cambiar solicitante</h3>
              <button className="icon-btn" onClick={() => setEditReq(null)}>✕</button></div>
            <div className="modal-body">
              <p className="cfg-hint">Corrige el <b>correo del cliente</b> de esta incidencia (p. ej. si se escribió mal).
                Solo cambia en este ticket; el contacto anterior se queda con los suyos.</p>
              <label className="field"><span className="lbl">Correo <em>*</em></span>
                <input type="email" autoFocus value={editReq.email}
                  onChange={(e) => setEditReq((s) => ({ ...s, email: e.target.value }))} placeholder="cliente@empresa.com" /></label>
              <label className="field"><span className="lbl">Nombre <span className="hint">(opcional)</span></span>
                <input value={editReq.name}
                  onChange={(e) => setEditReq((s) => ({ ...s, name: e.target.value }))} placeholder="Nombre del cliente" /></label>
            </div>
            <div className="modal-foot">
              <button className="btn ghost" onClick={() => setEditReq(null)}>Cancelar</button>
              <button className="btn" disabled={!/^[^@\s]+@[^@\s]+\.[^@\s]+$/.test((editReq.email || '').trim())}
                onClick={async () => {
                  const r = await api.setRequester(id, editReq.email.trim(), editReq.name.trim())
                  if (r?.ok) { setEditReq(null); toast(r.unchanged ? 'Ese ya era el solicitante' : 'Solicitante actualizado'); load(); onChange?.() }
                  else toast(r?.error || 'No se pudo cambiar', 'err')
                }}>Guardar</button>
            </div>
          </div>
        </div>
      )}

      {justificar && (
        <div className="modal-bg" onMouseDown={(e) => e.target.classList.contains('modal-bg') && setJustificar(false)}>
          <div className="modal jst-dlg">
            <div className="modal-h"><h3>Se cerró fuera de plazo</h3>
              <button className="icon-btn" onClick={() => setJustificar(false)}>✕</button></div>

            <div className="modal-body">
              <p className="cfg-hint">
                Este ticket ha superado su plazo. Si quieres, deja constancia de <b>por qué</b>: se guarda como
                <b> nota interna</b> —el cliente no la ve— y sirve para entender después qué se atasca.
              </p>
              <label className="field"><span className="lbl">Motivo <span className="hint">(opcional)</span></span>
                <textarea rows={3} autoFocus value={motivo} onChange={(e) => setMotivo(e.target.value)}
                  placeholder="Faltaba una pieza del proveedor · hubo que escalarlo a fábrica · …" /></label>
            </div>

            <div className="modal-foot">
              <button className="btn ghost" onClick={() => { setJustificar(false); setMotivo('') }}>Ahora no</button>
              <button className="btn" onClick={async () => {
                const txt = motivo.trim()
                if (!txt) { setJustificar(false); return }
                const r = await api.ticketNote(id, `<p>${txt.replace(/[<>&]/g, (c) => ({ '<': '&lt;', '>': '&gt;', '&': '&amp;' })[c])}</p>`, true)
                if (r.ok) { toast('Motivo guardado en el hilo'); setJustificar(false); setMotivo(''); load() }
                else toast(r.error || 'Error', 'err')
              }}>Guardar motivo</button>
            </div>
          </div>
        </div>
      )}

      {/* Diálogo de opciones del PDF */}
      {pdfOpts && (
        <div className="pdf-dialog-bg" onClick={(e) => e.target.classList.contains('pdf-dialog-bg') && !pdfOpts.busy && setPdfOpts(null)}>
          <div className="pdf-dialog">
            <div className="pdf-dialog-h"><Icon.file /> Generar PDF del ticket</div>
            <p className="pdf-dialog-sub">Elige qué incluir en el documento.</p>
            <label className="pdf-opt">
              <span className="fb-switch"><input type="checkbox" checked={pdfOpts.notes} onChange={(e) => setPdfOpts((o) => ({ ...o, notes: e.target.checked }))} /><span className={`fb-toggle ${pdfOpts.notes ? 'on' : ''}`} /></span>
              <span className="pdf-opt-tx"><b>Notas internas</b><small>Los comentarios que no ve el cliente</small></span>
            </label>
            <label className="pdf-opt">
              <span className="fb-switch"><input type="checkbox" checked={pdfOpts.images} onChange={(e) => setPdfOpts((o) => ({ ...o, images: e.target.checked }))} /><span className={`fb-toggle ${pdfOpts.images ? 'on' : ''}`} /></span>
              <span className="pdf-opt-tx"><b>Imágenes</b><small>Capturas incrustadas en el hilo</small></span>
            </label>
            <div className="pdf-dialog-foot">
              <button className="btn ghost" onClick={() => setPdfOpts(null)} disabled={pdfOpts.busy}>Cancelar</button>
              <button className="btn" onClick={genPdf} disabled={pdfOpts.busy}><Icon.file /> {pdfOpts.busy ? 'Generando…' : 'Generar PDF'}</button>
            </div>
          </div>
        </div>
      )}
    </div>
  )
}
