import { useState, useEffect, useCallback } from 'react'
import { api } from '../api.js'
import { Icon } from '../icons.jsx'
import { initials, avatarBg, parseDate } from '../util.js'

/* -------------------------------------------------------------------------
 * REGISTRO DE ACCIONES (auditoría). Solo el superadmin llega aquí (activity.view).
 *
 * Una línea de tiempo agrupada por día: quién hizo qué, en qué apartado y sobre
 * qué, con hora e IP. Filtros por usuario / apartado / fecha y buscador. Lo llena
 * solo un middleware en el backend; aquí únicamente se consulta.
 * ---------------------------------------------------------------------- */

// Cada apartado tiene su icono y color (el de la casa donde aplica).
const SECT = {
  Helpdesk:        { icon: Icon.ticket,   color: '#2563eb' },
  Contactos:       { icon: Icon.user,     color: '#0d9488' },
  'Organización':  { icon: Icon.building, color: '#7c5cff' },
  'Campañas':      { icon: Icon.send,     color: '#f59e0b' },
  Turnos:          { icon: Icon.calendar, color: '#db2777' },
  'Configuración': { icon: Icon.settings, color: '#64748b' },
  'Administración':{ icon: Icon.lock,     color: '#dc2626' },
  'Sesión':        { icon: Icon.logout,   color: '#0ea5e9' },
}
const sectOf = (s) => SECT[s] || { icon: Icon.dot, color: '#94a3b8' }

// Presets de fecha → {from, to} en formato YYYY-MM-DD (o vacío = sin límite).
const iso = (d) => d.toISOString().slice(0, 10)
const RANGOS = [
  ['all', 'Todo'],
  ['today', 'Hoy'],
  ['7d', '7 días'],
  ['30d', '30 días'],
]
function rangoFechas(key) {
  if (key === 'all') return { from: '', to: '' }
  const hoy = new Date()
  if (key === 'today') return { from: iso(hoy), to: iso(hoy) }
  const desde = new Date()
  desde.setDate(hoy.getDate() - (key === '7d' ? 6 : 29))
  return { from: iso(desde), to: iso(hoy) }
}

// Cabecera de cada día: Hoy / Ayer / «lunes, 28 jul».
function tituloDia(fecha) {
  const d = parseDate(fecha + ' 00:00:00') || new Date(fecha)
  const hoy = new Date(); hoy.setHours(0, 0, 0, 0)
  const ayer = new Date(hoy); ayer.setDate(hoy.getDate() - 1)
  const dd = new Date(d); dd.setHours(0, 0, 0, 0)
  if (+dd === +hoy) return 'Hoy'
  if (+dd === +ayer) return 'Ayer'
  return d.toLocaleDateString('es-ES', { weekday: 'long', day: 'numeric', month: 'short' })
}
const hora = (s) => { const d = parseDate(s); return d ? d.toLocaleTimeString('es-ES', { hour: '2-digit', minute: '2-digit' }) : '' }
const diaDe = (s) => (s || '').slice(0, 10)

export default function Activity() {
  const [meta, setMeta] = useState({ usuarios: [], apartados: [], total: 0 })
  const [f, setF] = useState({ user_id: '', section: 'all', rango: 'all', q: '' })
  const [qLive, setQLive] = useState('')          // input inmediato (se debouncea a f.q)
  const [rows, setRows] = useState([])
  const [page, setPage] = useState(1)
  const [hasMore, setHasMore] = useState(false)
  const [loading, setLoading] = useState(true)
  const [more, setMore] = useState(false)

  useEffect(() => { api.activityMeta().then((r) => { if (r.ok) setMeta(r) }) }, [])

  // Debounce del buscador.
  useEffect(() => {
    const t = setTimeout(() => setF((s) => ({ ...s, q: qLive })), 300)
    return () => clearTimeout(t)
  }, [qLive])

  const params = useCallback((p) => {
    const { from, to } = rangoFechas(f.rango)
    const o = { page: p }
    if (f.user_id) o.user_id = f.user_id
    if (f.section !== 'all') o.section = f.section
    if (f.q) o.q = f.q
    if (from) o.from = from
    if (to) o.to = to
    return o
  }, [f])

  // Recarga desde cero cuando cambian los filtros.
  useEffect(() => {
    let vivo = true
    setLoading(true)
    api.activity(params(1)).then((r) => {
      if (!vivo) return
      setRows(r.ok ? r.rows : [])
      setHasMore(!!r.has_more)
      setPage(1)
      setLoading(false)
    })
    return () => { vivo = false }
  }, [params])

  const cargarMas = () => {
    setMore(true)
    api.activity(params(page + 1)).then((r) => {
      if (r.ok) { setRows((prev) => [...prev, ...r.rows]); setHasMore(!!r.has_more); setPage((p) => p + 1) }
      setMore(false)
    })
  }

  const limpiar = () => { setF({ user_id: '', section: 'all', rango: 'all', q: '' }); setQLive('') }
  const filtrando = f.user_id || f.section !== 'all' || f.rango !== 'all' || f.q

  // Agrupar por día (los rows ya vienen en orden descendente).
  const dias = []
  let actual = null
  for (const r of rows) {
    const dk = diaDe(r.created_at)
    if (!actual || actual.dia !== dk) { actual = { dia: dk, items: [] }; dias.push(actual) }
    actual.items.push(r)
  }

  return (
    <>
      <header className="page-head">
        <span className="sc-ic"><Icon.activity style={{ width: 18, height: 18, fill: 'var(--primary)' }} /></span>
        <div><h1>Acciones</h1></div>
        <span className="sub">· Registro de actividad{meta.total ? ` · ${meta.total.toLocaleString('es-ES')} evento${meta.total === 1 ? '' : 's'}` : ''}</span>
      </header>

      <div className="page-scroll"><div className="page" style={{ maxWidth: 860 }}>
        {/* --- Filtros --- */}
        <div className="act-filters card">
          <div className="act-search">
            <Icon.search style={{ width: 16, height: 16, fill: 'var(--ink-3)' }} />
            <input
              value={qLive}
              onChange={(e) => setQLive(e.target.value)}
              placeholder="Buscar en las acciones, referencia o usuario…"
            />
          </div>
          <div className="act-row">
            <select value={f.user_id} onChange={(e) => setF((s) => ({ ...s, user_id: e.target.value }))}>
              <option value="">Todos los usuarios</option>
              {meta.usuarios.map((u) => (
                <option key={u.id ?? 'sys'} value={u.id ?? ''}>{u.name} ({u.n})</option>
              ))}
            </select>
            <select value={f.section} onChange={(e) => setF((s) => ({ ...s, section: e.target.value }))}>
              <option value="all">Todos los apartados</option>
              {meta.apartados.map((a) => <option key={a} value={a}>{a}</option>)}
            </select>
            <div className="kb-seg act-seg">
              {RANGOS.map(([k, l]) => (
                <button key={k} className={f.rango === k ? 'on' : ''} onClick={() => setF((s) => ({ ...s, rango: k }))}>{l}</button>
              ))}
            </div>
            {filtrando ? <button className="act-clear" onClick={limpiar}>Limpiar</button> : null}
          </div>
        </div>

        {/* --- Línea de tiempo --- */}
        {loading ? (
          <div className="center-load"><div className="spinner" /></div>
        ) : rows.length === 0 ? (
          <div className="act-empty">
            <Icon.activity style={{ width: 34, height: 34, fill: 'var(--ink-3)' }} />
            <p>{filtrando ? 'No hay acciones que coincidan con el filtro.' : 'Aún no se ha registrado ninguna acción.'}</p>
          </div>
        ) : (
          <div className="act-timeline">
            {dias.map((d) => (
              <div className="act-day" key={d.dia}>
                <div className="act-day-h"><span>{tituloDia(d.dia)}</span></div>
                {d.items.map((r) => {
                  const sec = sectOf(r.section)
                  const Ic = sec.icon
                  return (
                    <div className="act-item" key={r.id}>
                      <span className="act-node" style={{ '--sc': sec.color }}>
                        <Ic style={{ width: 14, height: 14, fill: '#fff' }} />
                      </span>
                      <div className="act-card" style={{ '--sc': sec.color }}>
                        <span className="act-avatar" style={{ background: avatarBg(r.user_name || '?') }}>
                          {initials({ name: r.user_name })}
                        </span>
                        <div className="act-body">
                          <p className="act-headline">
                            <span className="act-user">{r.user_name || 'Sistema'}</span>{' '}
                            <span className="act-what">{frase(r.summary)}</span>
                            {r.subject ? <span className="act-ref">{r.subject}</span> : null}
                          </p>
                          <p className="act-meta">
                            <span className="act-sec" style={{ '--sc': sec.color }}>{r.section}</span>
                            <span className="act-time">{hora(r.created_at)}</span>
                            {r.ip ? <span className="act-ip" title="IP de origen">{r.ip}</span> : null}
                          </p>
                        </div>
                      </div>
                    </div>
                  )
                })}
              </div>
            ))}

            {hasMore && (
              <div className="act-more">
                <button onClick={cargarMas} disabled={more}>{more ? 'Cargando…' : 'Cargar más'}</button>
              </div>
            )}
          </div>
        )}
      </div></div>
    </>
  )
}

/* El resumen empieza por el verbo en pasado («Respondió a…»); en la línea el nombre
 * ya va delante, así que bajamos la inicial a minúscula para que se lea natural
 * («Robert respondió a un ticket»). */
function frase(s) {
  if (!s) return ''
  return s.charAt(0).toLowerCase() + s.slice(1)
}
