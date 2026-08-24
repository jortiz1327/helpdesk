import { useState, useEffect, useCallback, useRef } from 'react'
import { api } from '../api.js'
import { Icon } from '../icons.jsx'

/*
 * Campana del centro de notificaciones. Muestra el nº de no leídas (sondeo cada 45s)
 * y, al abrir, el historial; al pulsar una, la marca leída y salta a su ticket.
 */
export default function NotificationBell({ onOpenTicket, expanded }) {
  const [unread, setUnread] = useState(0)
  const [open, setOpen] = useState(false)
  const [items, setItems] = useState(null)
  const ref = useRef(null)

  // Contador: al montar y cada 45s (no hay websocket por usuario en este bloque).
  const poll = useCallback(() => {
    api.unreadNotifications().then((r) => { if (r?.ok) setUnread(r.unread || 0) }).catch(() => {})
  }, [])
  useEffect(() => { poll(); const t = setInterval(poll, 45000); return () => clearInterval(t) }, [poll])

  // Cerrar el panel al clicar fuera.
  useEffect(() => {
    if (!open) return
    const h = (e) => { if (ref.current && !ref.current.contains(e.target)) setOpen(false) }
    document.addEventListener('mousedown', h)
    return () => document.removeEventListener('mousedown', h)
  }, [open])

  const toggle = () => {
    const abriendo = !open
    setOpen(abriendo)
    if (abriendo) {
      setItems(null)
      api.listNotifications().then((r) => {
        if (r?.ok) { setItems(r.notifications || []); setUnread(r.unread || 0) } else setItems([])
      }).catch(() => setItems([]))
    }
  }

  const clickNotif = async (n) => {
    if (!n.read_at) { const r = await api.readNotification(n.id); if (r?.ok) setUnread(r.unread || 0) }
    setOpen(false)
    if (n.ticket_id) onOpenTicket?.(n.ticket_id)
  }

  const marcarTodas = async () => {
    const r = await api.readAllNotifications()
    if (r?.ok) { setUnread(0); setItems((it) => (it || []).map((n) => ({ ...n, read_at: n.read_at || 'x' }))) }
  }

  return (
    <div className="notif" ref={ref}>
      <button className={`notif-bell ${expanded ? 'wide' : ''}`} onClick={toggle}
        title="Notificaciones" aria-label={`Notificaciones${unread ? `, ${unread} sin leer` : ''}`}>
        <Icon.bell />
        {unread > 0 && <span className="notif-badge">{unread > 9 ? '9+' : unread}</span>}
        {expanded && <span className="notif-lbl">Notificaciones</span>}
      </button>

      {open && (
        <div className="notif-panel" role="dialog" aria-label="Notificaciones">
          <div className="notif-h">
            <b>Notificaciones</b>
            {unread > 0 && <button className="link-btn" onClick={marcarTodas}>Marcar todas</button>}
          </div>
          <div className="notif-list">
            {items === null ? <div className="notif-empty">Cargando…</div>
              : items.length === 0 ? <div className="notif-empty">No tienes notificaciones</div>
                : items.map((n) => (
                  <button key={n.id} className={`notif-i ${n.read_at ? '' : 'unread'}`} onClick={() => clickNotif(n)}>
                    <span className="notif-dot" />
                    <span className="notif-tx">
                      <span className="notif-body">{n.body}</span>
                      {n.ticket_code && <small>{n.ticket_code}</small>}
                    </span>
                  </button>
                ))}
          </div>
        </div>
      )}
    </div>
  )
}
