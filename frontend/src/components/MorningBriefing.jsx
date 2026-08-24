import { useEffect, useState } from 'react'
import { api } from '../api.js'

/*
 * RECIBIMIENTO MATUTINO. Al entrar por primera vez en el día, si algún ticket que
 * pospusiste ha despertado (venció la fecha o el cliente respondió), un saludo cálido
 * con la lista para retomar justo donde lo dejaste. Se enseña una vez: al cerrarlo se
 * marca «visto» y no reaparece hasta que despierte algo nuevo.
 */
const REASONS = {
  due:   { icon: '📅', text: 'lo pediste para hoy', cls: 'why' },
  reply: { icon: '💬', text: 'el cliente respondió', cls: 'why reply' },
}

function saludo() {
  const h = new Date().getHours()
  if (h < 12) return { hi: 'Buenos días', emoji: '☀️' }
  if (h < 20) return { hi: 'Buenas tardes', emoji: '🌤️' }
  return { hi: 'Buenas noches', emoji: '🌙' }
}

export default function MorningBriefing({ user, onOpen, onGoInbox }) {
  const [items, setItems] = useState(null)   // null = aún no cargado / no mostrar
  const [closing, setClosing] = useState(false)

  useEffect(() => {
    let vivo = true
    if (!(user?.permissions || []).includes('helpdesk.access')) return
    api.snoozeBriefing()
      .then((r) => { if (vivo && r?.ok && (r.items || []).length) setItems(r.items) })
      .catch(() => {})
    return () => { vivo = false }
  }, [user])

  if (!items || !items.length) return null

  const cerrar = () => {
    setClosing(true)
    api.snoozeBriefingSeen().catch(() => {})
    setTimeout(() => setItems(null), 180)
  }
  // Abrir un ticket marca SU aviso de la campana como leído (ya lo atiendes); los demás
  // siguen ahí sin leer, así no se pierde ningún recordatorio al abrir solo uno.
  const abrir = (id) => { api.readTicketNotifications(id, 'snooze_wake').catch(() => {}); onOpen?.(id); cerrar() }
  const empezar = () => { onGoInbox?.(); cerrar() }

  const { hi, emoji } = saludo()
  const nombre = (user?.name || '').split(' ')[0] || ''
  const n = items.length

  return (
    <div className={`mb-overlay ${closing ? 'out' : ''}`} onMouseDown={cerrar}>
      <div className="mb-card" onMouseDown={(e) => e.stopPropagation()}>
        <div className="mb-top">
          <div className="mb-sun" />
          <div className="mb-hi">{hi}{nombre ? <>, <span className="mb-em">{nombre}</span></> : ''} {emoji}</div>
          <div className="mb-sub">Retomamos donde lo dejaste. Hoy {n === 1 ? 'vuelve' : 'vuelven'} <b>{n} {n === 1 ? 'ticket' : 'tickets'}</b> que aparcaste.</div>
        </div>

        <div className="mb-body">
          <div className="mb-lbl">Te toca volver a</div>
          {items.map((t) => {
            const r = REASONS[t.reason] || REASONS.due
            return (
              <button key={t.id} className="mb-item" onClick={() => abrir(t.id)}>
                <span className="mb-mid">
                  <b>{t.subject || 'Sin asunto'}</b>
                  <span className="mb-meta">
                    {t.code}{t.contact_name ? ` · ${t.contact_name}` : ''} &nbsp;·&nbsp;
                    <span className={`mb-${r.cls}`}>{r.icon} {r.text}</span>
                  </span>
                </span>
                <span className="mb-go">Ver →</span>
              </button>
            )
          })}
        </div>

        <div className="mb-foot">
          <button className="btn primary block" onClick={empezar}>Empezar el día</button>
        </div>
        {n > 1 && <p className="mb-note">Abre los que quieras ahora; el resto te espera en la campana 🔔</p>}
      </div>
    </div>
  )
}
