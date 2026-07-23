import { useState, useEffect, useCallback } from 'react'
import { api } from '../api.js'
import { Icon } from '../icons.jsx'
import ChannelBadge from './ChannelBadge.jsx'
import { onTicketActivity } from '../realtime.js'

/* ---------------------------------------------------------------------------
 * CENTRO DE SOPORTE — apartado GENERAL con atajos. Vista del agente.
 *
 * Es un hub, no una página pesada: de un vistazo, cómo va el soporte y desde
 * dónde saltar a lo demás.
 *
 * Enfoque acordado en reunión (14/07/2026): los tickets llegan por WEB y CORREO.
 * WhatsApp (y convertir sus mensajes en tickets) se aborda después.
 * Guardias, Live Chat y Reportes quedan fuera por ahora.
 * ------------------------------------------------------------------------- */

export default function SupportCenter({ onGo, user }) {
  const [meta, setMeta] = useState(null)
  const [s, setS] = useState(null)

  useEffect(() => { api.ticketMeta().then(setMeta) }, [])

  const [crones, setCrones] = useState(null)

  const load = useCallback(() => {
    api.ticketStats().then(setS)
    api.cronAlertCounts().then((r) => setCrones(r.counts || null))
  }, [])
  useEffect(() => { load() }, [load])

  // Tiempo real: las cifras y los tickets recientes se actualizan solos.
  useEffect(() => onTicketActivity(load), [load])

  const can = (p) => (user?.permissions || []).includes(p)
  const max = Math.max(1, ...Object.values(s?.by_status || { x: 1 }))

  return (
    <>
      <header className="page-head">
        <span className="sc-ic"><Icon.headset style={{ width: 18, height: 18, fill: 'var(--primary)' }} /></span>
        <div><h1>Centro de Soporte</h1></div>
        <span className="sub">· Gestión de tickets y atención al cliente</span>
        <div className="spacer" />
        {can('tickets.create') && (
          <button className="btn" onClick={() => onGo?.('ticket_new')}><Icon.ticket /> Nuevo ticket</button>
        )}
      </header>

      <div className="page-scroll">
        <div className="page" style={{ maxWidth: 1180 }}>

          {/*
            AVISO DE CRONES. Solo existe si hay alguno fallando: un panel permanente
            a cero se convierte en parte del decorado y deja de leerse. Se destaca
            lo que ha empezado a fallar HOY, que es la noticia — que haya cinco rotos
            desde hace semanas ya se sabe.
          */}
          {crones?.open > 0 && (
            <button className={`sc-cron ${crones.nuevos > 0 ? 'nuevo' : ''}`} onClick={() => onGo?.('tickets', 'cron')}>
              <span className="sc-cron-ic"><Icon.bolt /></span>
              <span className="sc-cron-tx">
                <b>
                  {crones.nuevos > 0
                    ? `${crones.nuevos} ${crones.nuevos === 1 ? 'cron ha empezado' : 'crones han empezado'} a fallar en las últimas 24 h`
                    : `${crones.open} ${crones.open === 1 ? 'cron sigue fallando' : 'crones siguen fallando'}`}
                </b>
                <small>
                  {/* «en total» solo aporta si hay más de los nuevos; si no, repite el número. */}
                  {crones.nuevos > 0 && crones.open > crones.nuevos && <>{crones.open} fallando en total · </>}
                  {crones.fails} {crones.fails === 1 ? 'ejecución fallida' : 'ejecuciones fallidas'} acumuladas
                </small>
              </span>
              <span className="sc-cron-go">Ver crones →</span>
            </button>
          )}

          {/* --- Estado del soporte --- */}
          <div className="tk-kpis">
            <Kpi cls="blue"  label="Total tickets" value={s?.total}    sub="En el sistema"        icon={Icon.ticket} />
            <Kpi cls="amber" label="Abiertos"      value={s?.open}     sub="Requieren atención"   icon={Icon.warn} />
            <Kpi cls="green" label="Resueltos"     value={s?.resolved} sub="Resueltos y cerrados" icon={Icon.check} />
            <Kpi cls="red"   label="Urgentes"      value={s?.urgent}   sub="Prioridad urgente"    icon={Icon.clock} />
          </div>

          <div className="sc-grid">
            {/* Tickets por estado */}
            <div className="card sc-panel">
              <div className="sc-panel-h"><Icon.chart /> <b>Tickets por estado</b></div>
              <div className="sc-bars">
                {Object.entries(meta?.statuses || {}).map(([k, label]) => {
                  const n = s?.by_status?.[k] ?? 0
                  return (
                    <div key={k} className="sc-bar-row">
                      <span className={`chip ${k}`}>{label}</span>
                      <span className="sc-bar-n">{n} {n === 1 ? 'ticket' : 'tickets'}</span>
                      <span className="sc-bar"><i style={{ width: `${(n / max) * 100}%` }} /></span>
                    </div>
                  )
                })}
              </div>
            </div>

            {/* Tickets recientes */}
            <div className="card sc-panel">
              <div className="sc-panel-h">
                <Icon.clock /> <b>Tickets recientes</b>
                <span className="spacer" />
                <button className="lnk" onClick={() => onGo?.('tickets')}>Ver todos</button>
              </div>
              {!s?.recent?.length ? (
                <div className="tk-empty" style={{ padding: '30px 16px' }}>
                  <p>Aún no hay tickets.</p>
                </div>
              ) : (
                <div className="sc-recent">
                  {s.recent.map((t) => (
                    <button key={t.id} className="sc-rec" onClick={() => onGo?.('tickets', t.id)}>
                      <div className="r-main">
                        <b>{t.subject}</b>
                        <small>{t.code} · {t.contact_email || t.contact_name || '—'}</small>
                      </div>
                      <ChannelBadge channel={t.channel} />
                      <span className="chip" style={meta?.priority_meta?.[t.priority]
                        ? { background: meta.priority_meta[t.priority].color + '22', color: meta.priority_meta[t.priority].color }
                        : undefined}>{meta?.priorities?.[t.priority] || t.priority}</span>
                      <span className={`chip ${t.status}`}>{meta?.statuses?.[t.status] || t.status}</span>
                    </button>
                  ))}
                </div>
              )}
            </div>
          </div>

          {/* --- Atajos: para eso está este apartado --- */}
          <div className="fb-set-t" style={{ margin: '24px 0 10px' }}>Atajos</div>
          <div className="sc-shortcuts">
            <Shortcut icon={Icon.ticket}   title="Gestión de tickets" desc="Buscar, filtrar y atender todos los tickets" onClick={() => onGo?.('tickets')} />
            {can('tickets.create') && (
              <Shortcut icon={Icon.plus}   title="Nuevo ticket"       desc="Dar de alta una solicitud de soporte"        onClick={() => onGo?.('ticket_new')} />
            )}
            {/* Solo para quien puede configurar: al resto no se le ofrece un atajo
                que acabaría en un 403. */}
            {can('support.config') && (
              <Shortcut icon={Icon.settings} title="Configuración"    desc="Categorías, SLA y ajustes del soporte"       onClick={() => onGo?.('support_cfg')} />
            )}
          </div>
        </div>
      </div>
    </>
  )
}

function Kpi({ cls, label, value, sub, icon: I }) {
  return (
    <div className={`tk-kpi ${cls}`}>
      <div className="k-l">{label}</div>
      <div className="k-v">{value ?? '…'}</div>
      <div className="k-s">{sub}</div>
      {I && <I className="k-i" />}
    </div>
  )
}

function Shortcut({ icon: I, title, desc, onClick, soon }) {
  return (
    <button className={`sc-sc ${soon ? 'soon' : ''}`} onClick={soon ? undefined : onClick} disabled={soon}>
      <span className="s-ic"><I /></span>
      <span className="s-tx">
        <b>{title} {soon && <em className="s-soon">pendiente</em>}</b>
        <small>{desc}</small>
      </span>
      {!soon && <span className="s-go">›</span>}
    </button>
  )
}
