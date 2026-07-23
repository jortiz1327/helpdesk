import { useState, useEffect } from 'react'
import { api } from '../api.js'
import { Icon } from '../icons.jsx'
import { initials, avatarBg, relTime } from '../util.js'

const DAY_NAMES = ['D', 'L', 'M', 'X', 'J', 'V', 'S']

function Sparkbars({ daily }) {
  const max = Math.max(1, ...daily.map((d) => d.total))
  return (
    <div className="spark">
      {daily.map((d, i) => (
        <span key={i} className="sb" style={{ height: Math.max(8, (d.total / max) * 100) + '%' }} title={`${d.date}: ${d.total}`} />
      ))}
    </div>
  )
}

export default function Dashboard({ user, onOpen }) {
  const [s, setS] = useState(null)
  const [tpls, setTpls] = useState(null)

  const load = () => {
    api.stats().then(setS)
    api.listTemplates().then((d) => setTpls(d.ok ? (d.templates || []).length : 0))
  }
  useEffect(() => { load() }, [])

  const now = new Date()
  const updated = now.toLocaleString('es-ES', { day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit' })

  if (!s) return <div className="center-load"><div className="spinner" /></div>

  const maxAct = Math.max(1, ...s.daily.map((d) => d.total))

  const cards = [
    { key: 'c', label: 'Conversaciones', value: s.contacts, icon: Icon.chat, color: '#00a884', sub: 'Contactos totales' },
    { key: 'u', label: 'Sin leer', value: s.unread, icon: Icon.message, color: '#25d366', sub: `${s.unread_chats} chats pendientes` },
    { key: 'm', label: 'Mensajes (7 días)', value: s.daily.reduce((a, d) => a + d.total, 0), icon: Icon.bolt, color: '#4a9bff', spark: true },
    { key: 't', label: 'Plantillas', value: tpls === null ? '…' : tpls, icon: Icon.templates, color: '#f4b740', sub: 'Aprobadas en Meta' },
  ]

  return (
    <>
      <header className="page-head">
        <h1>Dashboard</h1>
        <div className="spacer" />
        <button className="icon-btn" title="Actualizar" onClick={load}><Icon.dashboard /></button>
      </header>
      <div className="page-scroll">
        <div className="page" style={{ maxWidth: 1180 }}>
          {/* Bienvenida */}
          <div className="dash-welcome">
            <div className="dw-ico"><Icon.dashboard /></div>
            <div>
              <h2>¡Hola de nuevo, {user?.name || user?.email}!</h2>
              <p>Última actualización: {updated}</p>
            </div>
          </div>

          {/* Tarjetas */}
          <div className="stat-grid">
            {cards.map((c) => (
              <div className="stat-card" key={c.key}>
                <div className="stat-top">
                  <span className="stat-label">{c.label}</span>
                  <span className="stat-ico" style={{ background: c.color + '22', color: c.color }}><c.icon /></span>
                </div>
                <div className="stat-num">{c.value}</div>
                {c.spark ? <Sparkbars daily={s.daily} /> : <div className="stat-sub">{c.sub}</div>}
              </div>
            ))}
          </div>

          {/* Paneles */}
          <div className="dash-cols">
            {/* Actividad */}
            <div className="panel">
              <div className="panel-h"><Icon.bolt /> <div><b>Actividad de mensajes</b><span>Últimos 7 días · entrantes y salientes</span></div></div>
              {s.messages === 0 ? (
                <div className="panel-empty"><Icon.message /><p>No hay datos todavía.<br />Empieza a conversar para ver actividad.</p></div>
              ) : (
                <div className="act-chart">
                  {s.daily.map((d, i) => (
                    <div className="act-col" key={i}>
                      <div className="act-bars">
                        <span className="ab in" style={{ height: (d.in / maxAct * 100) + '%' }} title={`Entrantes: ${d.in}`} />
                        <span className="ab out" style={{ height: (d.out / maxAct * 100) + '%' }} title={`Salientes: ${d.out}`} />
                      </div>
                      <span className="act-day">{DAY_NAMES[new Date(d.date + 'T00:00').getDay()]}</span>
                    </div>
                  ))}
                </div>
              )}
              <div className="act-legend"><span><i className="dot in" /> Entrantes</span><span><i className="dot out" /> Salientes</span></div>
            </div>

            {/* Lateral */}
            <div className="dash-side">
              <div className="panel">
                <div className="panel-h"><Icon.message /> <div><b>Sin leer</b></div></div>
                {s.unread_chats === 0 ? (
                  <div className="all-caught"><span className="ac-check">✓</span><b>¡Todo al día!</b><p>No tienes mensajes sin leer.</p></div>
                ) : (
                  <div className="recent-list">
                    {s.recent.filter((c) => parseInt(c.unread) > 0).map((c) => (
                      <div className="recent-item" key={c.id} onClick={() => onOpen(c.id)}>
                        <div className="avatar md" style={{ background: avatarBg(c.wa_id) }}>{initials(c)}</div>
                        <div className="ri-info"><span className="ri-name">{c.name || (c.wa_id ? '+' + c.wa_id : '—')}</span><span className="ri-msg">{c.last_message}</span></div>
                        <span className="badge">{c.unread}</span>
                      </div>
                    ))}
                  </div>
                )}
              </div>

              <div className="panel">
                <div className="panel-h"><Icon.chat /> <div><b>Conversaciones recientes</b></div></div>
                {s.recent.length === 0 ? (
                  <div className="panel-empty"><Icon.chat /><p>Aún no hay conversaciones.</p></div>
                ) : (
                  <div className="recent-list">
                    {s.recent.map((c) => (
                      <div className="recent-item" key={c.id} onClick={() => onOpen(c.id)}>
                        <div className="avatar md" style={{ background: avatarBg(c.wa_id) }}>{initials(c)}</div>
                        <div className="ri-info"><span className="ri-name">{c.name || (c.wa_id ? '+' + c.wa_id : '—')}</span><span className="ri-msg">{c.last_message || '—'}</span></div>
                        <span className="ri-time">{relTime(c.last_time)}</span>
                      </div>
                    ))}
                  </div>
                )}
              </div>
            </div>
          </div>
        </div>
      </div>
    </>
  )
}
