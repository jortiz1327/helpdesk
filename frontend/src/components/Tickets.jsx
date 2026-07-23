import { useState, useEffect, useCallback, useRef } from 'react'
import { api } from '../api.js'
import { Icon } from '../icons.jsx'
import { useToast, useConfirm } from '../App.jsx'
import Select from './Select.jsx'
import ChannelBadge from './ChannelBadge.jsx'
import Composer from './Composer.jsx'
import Agents from './Agents.jsx'
import CronAlerts from './CronAlerts.jsx'
import { onTicketActivity } from '../realtime.js'

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

// Historial de movimientos: icono + frase legible por tipo de evento.
const EV_ICON = { created: '🎫', status: '🔄', assign: '👤', category: '🏷️', priority: '⚑', merge_in: '🔗', merge_out: '🔗' }
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
  { k: 'active',     label: 'Activos',       hint: 'Todo menos resueltos y cerrados', f: { status: 'open', assigned: 'all',  reply: 'all' } },
  { k: 'pending',    label: 'Sin responder', hint: 'El cliente escribió lo último',   f: { status: 'open', assigned: 'all',  reply: 'pending' }, accent: 'warn' },
  { k: 'mine',       label: 'Mis tickets',   hint: 'Los que tengo asignados',         f: { status: 'open', assigned: 'me',   reply: 'all' } },
  { k: 'unassigned', label: 'Sin asignar',   hint: 'Nadie los ha cogido todavía',     f: { status: 'open', assigned: 'none', reply: 'all' } },
  { k: 'all',        label: 'Todos',         hint: 'Incluye resueltos y cerrados',    f: { status: 'all',  assigned: 'all',  reply: 'all' } },
]

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
const BASE_F = { q: '', search_in: 'ficha', priority: 'all', category: 'all', sla: 'all', ...VIEWS[0].f }

/*
 * `initialTicket`: al llegar desde otra pantalla (p. ej. pinchando uno de los
 * «tickets recientes» del Centro de Soporte) se abre ESE ticket directamente, en
 * vez de dejar al usuario delante de la lista buscándolo otra vez.
 */
export default function Tickets({ user, initialTab = 'tickets', initialTicket = null }) {
  const toast = useToast()
  const [tab, setTab] = useState(initialTab)   // tickets | agents | cron
  const [crones, setCrones] = useState(0)     // crones fallando, para el distintivo
  const [meta, setMeta] = useState(null)
  const [rows, setRows] = useState(null)
  const [canTimes, setCanTimes] = useState(false)
  const [counts, setCounts] = useState({})
  const [open, setOpen] = useState(initialTicket)   // id del ticket abierto en el modal
  // Fusión lanzada DESDE LA LISTA (sin abrir ningún ticket): { id, preselect }
  const [openFusion, setOpenFusion] = useState(null)
  const [f, setF] = useState(BASE_F)
  const [sel, setSel] = useState(new Set())   // ids seleccionados (acciones en lote)

  // Paginación. El tamaño de página se recuerda por agente: cada uno tiene su
  // pantalla y su forma de trabajar.
  const [page, setPage] = useState(1)
  const [perPage, setPerPage] = useState(() => Number(localStorage.getItem('tk_per_page')) || 25)
  const [pag, setPag] = useState({ total: 0, pages: 1 })

  const can = (p) => (user?.permissions || []).includes(p)

  const load = useCallback(() => {
    api.listTickets({ ...f, page, per_page: perPage }).then((d) => {
      setRows(d.tickets || [])
      setCanTimes(!!d.can_times)
      setCounts(d.counts || {})
      setPag({ total: d.total ?? 0, pages: d.pages ?? 1 })
      // El servidor recorta si pides una página que ya no existe (p. ej. tras filtrar).
      if (d.page && d.page !== page) setPage(d.page)
    })
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
  //  primero: al salir de la vista de vencidos hay que quitar ese filtro,
  // que las demás vistas no lo mencionan y se quedaría pegado.
  const applyView = (v) => setF((s) => ({ ...s, sla: 'all', ...v.f }))

  /** Asignar desde la propia tabla, sin abrir el ticket. */
  const quickAssign = async (id, uid) => {
    const r = await api.assignTicket(id, uid || null)
    if (r.ok) { toast('Ticket asignado'); load() } else toast(r.error || 'Error', 'err')
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

  const clear = () => setF(BASE_F)
  const refined = f.q || f.priority !== 'all' || f.category !== 'all'   // filtros finos por encima de la vista

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
        {can('tickets.export') && (
          <button className="btn ghost" disabled title="Pendiente: falta definir formato y alcance (ver NOTAS.md)"><Icon.download /> Exportar</button>
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
            {[...VIEWS, ...(counts.sla_late > 0 ? [VISTA_SLA] : [])].map((v) => (
              <button key={v.k} className={`tkv ${viewOn(v) ? 'on' : ''} ${v.accent || ''}`} onClick={() => applyView(v)} title={v.hint}>
                {v.label}
                {counts[v.k] !== undefined && <span className="tkv-n">{counts[v.k]}</span>}
              </button>
            ))}
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
                const dos = marcados.length === 2
                const mismo = dos && Number(marcados[0].contact_id) === Number(marcados[1].contact_id)
                const libres = marcados.every((x) => !x.merged_into_id)
                const vale = dos && mismo && libres && marcados[0].contact_id
                return (
                  <button className="btn ghost sm" disabled={!vale}
                    title={vale ? 'Juntar los dos en una sola conversación'
                      : !dos ? 'Marca exactamente dos tickets para fusionarlos'
                        : !libres ? 'Uno de los dos ya está fusionado'
                          : 'Solo se pueden fusionar tickets del mismo cliente'}
                    onClick={() => { setOpenFusion({ id: marcados[0].id, preselect: marcados[1].id }) }}>
                    <Icon.merge /> Fusionar
                  </button>
                )
              })()}

              <button className="btn ghost sm" onClick={() => bulk({ op: 'assign', user_id: user.id }, 'Asignados a ti')}>
                <Icon.user /> Asignármelos
              </button>
              {can('tickets.close') && (
                <>
                  <button className="btn ghost sm" onClick={() => bulk({ op: 'status', status: 'resuelto' }, 'Resueltos')}>
                    <Icon.check /> Resolver
                  </button>
                  <button className="btn ghost sm" onClick={() => bulk({ op: 'status', status: 'cerrado' }, 'Cerrados')}>
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
              <span className="spacer" />
              <button className="btn ghost sm" onClick={clearSel}>Cancelar</button>
            </div>
          )}

          {/* --- Tabla --- */}
          {rows === null ? <div className="center-load"><div className="spinner" /></div> : rows.length === 0 ? (
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
              <table className="tk-table">
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
                  {rows.map((t) => {
                    const waiting = t.last_direction === 'in'   // habló el cliente: nos toca
                    return (
                      <tr key={t.id} className={`${waiting ? 'wait' : ''} ${sel.has(t.id) ? 'picked' : ''}`} onClick={() => setOpen(t.id)}>
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
                        {/* También etiqueta cuando no hay categoría: si es texto suelto,
                            su primera letra queda 9 px a la izquierda de la de las
                            etiquetas y la columna se ve desalineada. */}
                        <td>{t.category_name
                          ? <span className="chip cat">{t.category_name}</span>
                          : <span className="chip cat vacia">Sin categoría</span>}</td>

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
                          <span className={`chip ${t.status}`}>{meta?.statuses?.[t.status] || t.status}</span>
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
                            <span className={`chip ${o.status} sm`}>{meta?.statuses?.[o.status] || o.status}</span>
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
  const endRef = useRef(null)

  const can = (p) => (user?.permissions || []).includes(p)

  const load = useCallback(() => { api.getTicket(id).then(setD) }, [id])
  useEffect(() => { load() }, [load])

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
    const filtro = t.contact_email ? { contact_email: t.contact_email } : { contact: t.contact_id }
    api.listTickets({ ...filtro, status: 'all' })
      .then((r) => setClientTickets((r.tickets || []).filter((x) => Number(x.id) !== Number(id))))
      .catch(() => setClientTickets([]))
  }, [d?.ticket?.contact_email, d?.ticket?.contact_id, id]) // eslint-disable-line react-hooks/exhaustive-deps
  useEffect(() => { endRef.current?.scrollIntoView({ behavior: 'smooth' }) }, [d])

  // Si llega un mensaje AL TICKET QUE ESTOY MIRANDO, aparece solo en el hilo.
  useEffect(() => onTicketActivity((e) => {
    if (!e.ticketId || Number(e.ticketId) === Number(id)) load()
  }), [id, load])
  useEffect(() => {
    const h = (e) => e.key === 'Escape' && onClose()
    document.addEventListener('keydown', h)
    return () => document.removeEventListener('keydown', h)
  }, [onClose])

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

  return (
    <div className="modal-bg" onClick={(e) => e.target.classList.contains('modal-bg') && onClose()}>
      <div className="tk-modal">
        {!t ? <div className="center-load"><div className="spinner" /></div> : (
          <>
            {/* --- Panel izquierdo: la ficha --- */}
            <aside className="tkm-side">
              {/* Cliente: lo primero, con cara. Es con quien estás hablando. */}
              <div className="tkm-cli">
                <span className="tkm-av">{(t.contact_name || '?').slice(0, 1).toUpperCase()}</span>
                <div className="tkm-cli-tx">
                  <b>{t.contact_name || 'Sin nombre'}</b>
                  <small>{t.contact_email || (t.contact_wa ? '+' + t.contact_wa : 'Sin datos de contacto')}</small>
                </div>
              </div>
              {t.contact_email && t.contact_wa && (
                <div className="tkm-extra"><Icon.phone /> +{t.contact_wa}</div>
              )}

              {/*
                EN COPIA: quién más sigue esta conversación. Va aquí arriba, junto al
                cliente, porque antes de escribir hay que saber quién lo va a leer.
              */}
              {!!d.cc_sugerido?.length && (
                <div className="tkm-cc">
                  <div className="tkm-cc-h"><Icon.user /> En copia <span>{d.cc_sugerido.length}</span></div>
                  <div className="tkm-cc-list">
                    {d.cc_sugerido.map((c) => <span key={c} title={c}>{c}</span>)}
                  </div>
                  <small>Se les incluye al responder, salvo que los quites.</small>
                </div>
              )}

              <div className="tkm-block">
                <div className="tkm-sec">Ticket</div>
                <div className="tkm-row"><span>Referencia</span><b className="tk-code">{t.code}</b></div>
                <div className="tkm-row"><span>Origen</span><ChannelBadge channel={t.channel} /></div>
                <div className="tkm-row"><span>Categoría</span>
                  {t.category_name ? <span className="chip cat">{t.category_name}</span> : <i className="tk-time">Sin categoría</i>}
                </div>
                <div className="tkm-row"><span>Prioridad</span>
                  {prChip(t.priority, meta)}
                </div>
                <div className="tkm-row"><span>Creado</span><b>{fmtDate(t.created_at)}</b></div>
              </div>

              {/* Los tiempos solo para quien tiene permiso */}
              {can('tickets.view_times') && (
                <div className="tkm-block">
                  <div className="tkm-sec">Tiempos</div>
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
                </div>
              )}

              <div className="tkm-block">
                <div className="tkm-sec">Acciones</div>
                {can('tickets.close') ? (
                  <div className="field">
                    <span className="lbl">Estado</span>
                    <Select block value={t.status} onChange={setStatus}
                      options={Object.entries(meta?.statuses || {}).map(([value, label]) => ({ value, label }))} />
                  </div>
                ) : (
                  <div className="tkm-row"><span>Estado</span><span className={`chip ${t.status}`}>{meta?.statuses?.[t.status] || t.status}</span></div>
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

                {/* Cualquier agente puede COGERSE un ticket, aunque no reparta trabajo. */}
                {Number(t.assigned_to) !== Number(user?.id) && (
                  <button className="btn ghost block" style={{ marginTop: 10 }} onClick={() => assign(String(user.id))}>
                    <Icon.user /> Asignármelo a mí
                  </button>
                )}

                {/* Exportar el hilo a PDF (con diálogo de opciones). */}
                <button className="btn ghost block" style={{ marginTop: 10 }} onClick={() => setPdfOpts({ notes: true, images: true, busy: false })}>
                  <Icon.file /> Generar PDF
                </button>

                {/* Juntar dos tickets del mismo cliente en uno. No sale si este ya
                    está fusionado: ahí no hay nada que juntar, solo que ir al bueno. */}
                {can('tickets.reply') && !t.merged_into_id && (
                  <button className="btn ghost block" style={{ marginTop: 10 }} onClick={() => setFusion({ preselect: null })}>
                    <Icon.merge /> Fusionar tickets…
                  </button>
                )}

                {/* Zona peligrosa: borrar el ticket entero (solo con permiso). */}
                {can('tickets.delete') && (
                  <button className="btn ghost block tk-del" style={{ marginTop: 10 }} onClick={del}>
                    <Icon.trash /> Eliminar ticket
                  </button>
                )}
              </div>
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
                              <tr key={x.id} onClick={() => onOpenTicket?.(x.id)} title="Abrir este ticket">
                                <td><b className="mono">{x.code}</b></td>
                                <td>{x.subject}</td>
                                <td><span className={`chip ${x.status} sm`}>{meta?.statuses?.[x.status] || x.status}</span></td>
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
                {d.messages.length === 0 && <div className="tk-empty"><p>Este ticket aún no tiene mensajes.</p></div>}
                {d.messages.map((m) => {
                  const out = m.direction === 'out'
                  return (
                    <div key={m.id} className={`tk-msg ${out ? 'out' : 'in'} ${Number(m.is_internal_note) ? 'note' : ''} ${m.channel === 'email' && !out ? 'is-email' : ''}`}>
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
                        </div>

                        {/* is_html = HTML ya saneado en el servidor. Si no, texto plano
                            (WhatsApp/correo) y React lo escapa solo. */}
                        {Number(m.is_html)
                          ? <div className={`b-html${m.channel === 'email' ? ' b-email' : ''}`} dangerouslySetInnerHTML={{ __html: m.body }} />
                          : (m.body || <i>[{m.type}]</i>)}

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

                        <div className="b-t">{fmtTime(m.created_at)}</div>
                      </div>
                    </div>
                  )
                })}
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
                  // Destinatarios solo en correo: en WhatsApp no hay copias que valgan.
                  to={d.ticket.channel === 'email' ? d.ticket.contact_email : null}
                  ccSugerido={d.cc_sugerido || []}
                  disabled={!can('tickets.reply') || (d.lock && !d.lock.mine)}
                  disabledHint={!can('tickets.reply')
                    ? 'No tienes permiso para responder tickets'
                    : (d.lock && !d.lock.mine)
                      ? `${d.lock.user_name || 'Otro agente'} lo está atendiendo`
                      : undefined}
                  onSend={async ({ html, files, internal, cc, bcc }) => {
                    if (internal) {
                      const r = await api.ticketNote(id, html)
                      if (r.ok) { toast('📝 Nota interna guardada'); load(); onChange?.() }
                      else toast(r.error || 'No se pudo guardar la nota', 'err')
                    } else {
                      const r = await api.ticketReply(id, html, files, cc, bcc)
                      if (r.ok) {
                        toast('✉️ Respuesta enviada por correo')
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
