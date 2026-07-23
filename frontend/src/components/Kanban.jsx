import { useState, useEffect, useCallback, useRef } from 'react'
import { api } from '../api.js'
import { Icon } from '../icons.jsx'
import { initials, avatarBg, relTime } from '../util.js'
import { useToast } from '../App.jsx'
import LabelManager from './LabelManager.jsx'

export default function Kanban({ onOpen, embedded = false, area = '' }) {
  const toast = useToast()
  const [convs, setConvs] = useState(null)
  const [labels, setLabels] = useState([])
  const [query, setQuery] = useState('')
  const [managing, setManaging] = useState(false)
  const [overCol, setOverCol] = useState(undefined)
  const draggingRef = useRef(false)

  const load = useCallback(() => {
    /*
     * CONTACTOS, no conversaciones: `listConversations` solo devuelve las de
     * WhatsApp (el Chat en vivo es de Campañas), así que en Helpdesk el tablero
     * salía casi vacío. Se usa la misma fuente que la vista de lista, con su
     * mismo filtro de área.
     */
    api.listContacts('', 0, '', area).then((d) => setConvs(d.contacts || []))
    api.listLabels().then((d) => setLabels(d.labels || []))
  }, [area])
  useEffect(() => { load() }, [load])

  /* El resumen puede venir de un mensaje con HTML (correo/editor): en la tarjeta
     se muestra como TEXTO, nunca las etiquetas en crudo. */
  const preview = (s) => (s || '').replace(/<[^>]*>/g, ' ').replace(/&nbsp;/g, ' ').replace(/\s+/g, ' ').trim()

  const q = query.trim().toLowerCase()
  const match = (c) => !q || (c.name || '').toLowerCase().includes(q) || (c.wa_id || '').includes(q) || (c.email || '').toLowerCase().includes(q)
    || (c.labels || []).some((l) => (l.name || '').toLowerCase().includes(q))
  const filtered = (convs || []).filter(match)

  const columns = [
    { id: null, name: 'Sin etiqueta', color: '#8696a0' },
    ...labels.map((l) => ({ id: l.id, name: l.name, color: l.color })),
  ]
  const chatsFor = (colId) => filtered.filter((c) =>
    colId === null ? !(c.labels || []).length : (c.labels || []).some((l) => l.id === colId))

  // ---- drag & drop ----
  const onDragStart = (e, contactId, sourceColId) => {
    draggingRef.current = true
    e.dataTransfer.setData('text/plain', JSON.stringify({ contactId, sourceColId }))
    e.dataTransfer.effectAllowed = 'move'
  }
  // Reordenar columnas (etiquetas) arrastrando su cabecera.
  const reorderCols = (draggedId, targetColId) => {
    if (draggedId == null) return
    const ids = labels.map((l) => l.id)
    const from = ids.indexOf(draggedId)
    const to = targetColId == null ? 0 : ids.indexOf(targetColId)
    if (from < 0 || to < 0 || from === to) return
    const next = [...labels]
    const [it] = next.splice(from, 1)
    next.splice(to, 0, it)
    setLabels(next)
    api.reorderLabels(next.map((l) => l.id)).then((r) => { if (!r.ok) { toast('No se pudo reordenar', 'err'); load() } })
  }

  const onDrop = (e, targetColId) => {
    e.preventDefault(); setOverCol(undefined); draggingRef.current = false
    let data; try { data = JSON.parse(e.dataTransfer.getData('text/plain')) } catch { return }
    if (data.kind === 'col') { reorderCols(data.id, targetColId); return }
    const { contactId, sourceColId } = data
    if (sourceColId === targetColId) return
    const conv = (convs || []).find((c) => c.id === contactId)
    if (!conv) return
    let ids = (conv.labels || []).map((l) => l.id)
    if (sourceColId != null) ids = ids.filter((id) => id !== sourceColId)
    if (targetColId != null && !ids.includes(targetColId)) ids.push(targetColId)
    const newLabels = labels.filter((l) => ids.includes(l.id))
    setConvs((prev) => prev.map((c) => (c.id === contactId ? { ...c, labels: newLabels } : c)))
    api.setContactLabels(contactId, ids).then((r) => { if (!r.ok) { toast('No se pudo mover', 'err'); load() } })
  }

  const openCard = (id) => { if (!draggingRef.current && onOpen) onOpen(id) }

  return (
    <>
      {/* Cabecera propia solo cuando NO va embebido dentro de Contactos. */}
      {!embedded && (
        <header className="page-head">
          <span className="ic" style={{ width: 30, height: 30, borderRadius: 9, background: 'var(--primary-soft)', display: 'grid', placeItems: 'center' }}><Icon.kanban style={{ width: 18, height: 18, fill: 'var(--primary)' }} /></span>
          <div>
            <h1>Kanban</h1>
          </div>
          <span className="sub">· Arrastra los chats entre columnas de etiqueta</span>
          <div className="spacer" />
          <span className="muted" style={{ fontSize: 12.5 }}>Mostrando <b style={{ color: 'var(--ink)' }}>{filtered.length}</b> de <b style={{ color: 'var(--ink)' }}>{convs?.length || 0}</b> chats</span>
        </header>
      )}

      <div className="kb-toolbar">
        <div className="search" style={{ maxWidth: 320, flex: 1 }}>
          <Icon.search />
          <input placeholder="Buscar por nombre, número o etiqueta…" value={query} onChange={(e) => setQuery(e.target.value)} />
        </div>
        <button className="btn ghost sm" onClick={load} title="Refrescar"><Icon.refresh /></button>
        {/* Embebido, la gestión de etiquetas ya está en la cabecera de Contactos. */}
        {!embedded && <button className="btn ghost sm" onClick={() => setManaging(true)}><Icon.tag /> Gestionar etiquetas</button>}
        {embedded && <span className="muted" style={{ marginLeft: 'auto', fontSize: 12.5 }}>Mostrando <b style={{ color: 'var(--ink)' }}>{filtered.length}</b> de <b style={{ color: 'var(--ink)' }}>{convs?.length || 0}</b></span>}
      </div>

      {convs === null ? (
        <div className="center-load"><div className="spinner" /></div>
      ) : (
        <div className="kanban-board">
          {columns.map((col) => {
            const chats = chatsFor(col.id)
            return (
              <div
                key={col.id ?? 'none'}
                className={`kb-col ${overCol === (col.id ?? 'none') ? 'over' : ''}`}
                onDragOver={(e) => { e.preventDefault(); setOverCol(col.id ?? 'none') }}
                onDragLeave={() => setOverCol((c) => (c === (col.id ?? 'none') ? undefined : c))}
                onDrop={(e) => onDrop(e, col.id)}
              >
                <div className={`kb-col-head ${col.id != null ? 'draggable' : ''}`}
                  draggable={col.id != null}
                  onDragStart={col.id != null ? (e) => { e.stopPropagation(); e.dataTransfer.setData('text/plain', JSON.stringify({ kind: 'col', id: col.id })); e.dataTransfer.effectAllowed = 'move' } : undefined}
                  title={col.id != null ? 'Arrastra para reordenar la columna' : undefined}
                >
                  {col.id != null && <span className="kb-col-grip">⠿</span>}
                  <span className="kb-col-dot" style={{ background: col.color }} />
                  <span className="kb-col-name">{col.name}</span>
                  <span className="kb-count">{chats.length}</span>
                </div>
                <div className="kb-cards">
                  {chats.length === 0 && <div className="kb-drop-empty">Suelta aquí</div>}
                  {chats.map((c) => (
                    <div
                      key={c.id}
                      className="kb-card"
                      draggable
                      onDragStart={(e) => onDragStart(e, c.id, col.id)}
                      onDragEnd={() => { draggingRef.current = false }}
                      onClick={() => openCard(c.id)}
                    >
                      <div className="kb-card-top">
                        <div className="avatar md" style={{ background: avatarBg(c.wa_id || c.email || '') }}>{initials(c)}</div>
                        <div className="kb-card-info">
                          {/* Un contacto puede tener teléfono y/o correo: se muestra lo que haya. */}
                          <div className="kb-card-name">{c.name || (c.wa_id ? '+' + c.wa_id : c.email) || '—'}</div>
                          <div className="kb-card-msg">{preview(c.last_message) || c.email || (c.wa_id ? '+' + c.wa_id : '—')}</div>
                        </div>
                        {parseInt(c.unread) > 0 && <span className="badge">{c.unread}</span>}
                      </div>
                      {(c.labels || []).length > 0 && (
                        <div className="kb-card-labels">
                          {c.labels.map((l) => <span key={l.id} className="lbl-chip" style={{ background: l.color + '22', color: l.color }}><span className="lbl-dot" style={{ background: l.color }} />{l.name}</span>)}
                        </div>
                      )}
                      <div className="kb-card-time">{relTime(c.last_time)}</div>
                    </div>
                  ))}
                </div>
              </div>
            )
          })}

          {labels.length === 0 && (
            <div className="kb-noLabels">
              <div className="ico"><Icon.kanban /></div>
              <p>No hay etiquetas. Crea etiquetas para organizar tus contactos en columnas.</p>
              <button className="btn sm" onClick={() => setManaging(true)}><Icon.plus /> Crear etiqueta</button>
            </div>
          )}
        </div>
      )}

      {managing && <LabelManager labels={labels} onClose={() => setManaging(false)} onChanged={load} />}
    </>
  )
}
