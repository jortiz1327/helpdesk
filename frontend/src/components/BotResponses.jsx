import { useState, useEffect, useCallback, useMemo } from 'react'
import { api } from '../api.js'
import { Icon } from '../icons.jsx'
import { initials, avatarBg, relTime } from '../util.js'

// Agrupa las respuestas planas por contacto, quedándose con el último valor de
// cada variable (las filas llegan ordenadas por fecha descendente).
function groupByContact(rows) {
  const map = new Map()
  for (const r of rows) {
    let g = map.get(r.contact_id)
    if (!g) { g = { contact_id: r.contact_id, name: r.contact_name, wa_id: r.wa_id, last: r.created_at, fields: new Map() }; map.set(r.contact_id, g) }
    if (!g.fields.has(r.variable)) g.fields.set(r.variable, { value: r.value, at: r.created_at, flow: r.flow_name })
  }
  return [...map.values()].map((g) => ({ ...g, fields: [...g.fields.entries()].map(([variable, v]) => ({ variable, ...v })) }))
}

export default function BotResponses({ onOpen }) {
  const [rows, setRows] = useState(null)
  const [query, setQuery] = useState('')

  const load = useCallback((q) => { api.listBotResponses(q ?? '').then((d) => setRows(d.responses || [])) }, [])
  useEffect(() => { load('') }, [load])
  useEffect(() => { const t = setTimeout(() => load(query), 280); return () => clearTimeout(t) }, [query, load])

  const groups = useMemo(() => (rows ? groupByContact(rows) : []), [rows])

  return (
    <>
      <header className="page-head">
        <span className="ic" style={{ width: 30, height: 30, borderRadius: 9, background: 'var(--primary-soft)', display: 'grid', placeItems: 'center' }}><Icon.note style={{ width: 18, height: 18, fill: 'var(--primary)' }} /></span>
        <h1>Respuestas del bot</h1>
        <span className="sub">· Datos capturados por tus chatbots</span>
        <div className="spacer" />
        <div className="search" style={{ maxWidth: 260 }}>
          <Icon.search />
          <input placeholder="Buscar nombre, teléfono o dato" value={query} onChange={(e) => setQuery(e.target.value)} />
        </div>
        {rows && rows.length > 0 && (
          <a className="btn ghost" href={api.botResponsesCsvUrl(query)} target="_blank" rel="noreferrer"><Icon.download /> CSV</a>
        )}
      </header>

      <div className="page-scroll">
        <div className="page" style={{ maxWidth: 920 }}>
          {rows === null && <div className="center-load"><div className="spinner" /></div>}

          {rows && groups.length === 0 && (
            <div className="empty">
              <div className="ico"><Icon.note /></div>
              <p>Aún no hay respuestas guardadas.<br />Aparecerán aquí cuando un chatbot capture datos con un nodo «Guardar respuesta» o con la elección de un menú de botones.</p>
            </div>
          )}

          {groups.map((g) => (
            <div className="card resp-card" key={g.contact_id}>
              <div className="resp-head">
                <div className="avatar md" style={{ background: avatarBg(g.wa_id || g.name || '') }}>{initials({ name: g.name, wa_id: g.wa_id })}</div>
                <div className="resp-who">
                  <div className="name">{g.name || '+' + g.wa_id}</div>
                  <div className="sub">+{g.wa_id} · {relTime(g.last)}</div>
                </div>
                {onOpen && <button className="btn ghost sm" onClick={() => onOpen(g.contact_id)}><Icon.chat /> Abrir chat</button>}
              </div>
              <div className="resp-fields">
                {g.fields.map((f) => (
                  <div className="resp-field" key={f.variable}>
                    <span className="resp-k">{f.variable}</span>
                    <span className="resp-v">{f.value || <em className="muted">—</em>}</span>
                  </div>
                ))}
              </div>
            </div>
          ))}
        </div>
      </div>
    </>
  )
}
