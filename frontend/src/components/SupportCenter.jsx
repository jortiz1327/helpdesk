import { useState, useEffect, useCallback } from 'react'
import { api } from '../api.js'
import { Icon } from '../icons.jsx'
import ChannelBadge from './ChannelBadge.jsx'
import { stChip } from './Tickets.jsx'
import { onTicketActivity } from '../realtime.js'

/* ---------------------------------------------------------------------------
 * CENTRO DE SOPORTE — apartado GENERAL. Vista del agente.
 *
 * De un vistazo, cómo va la atención HOY: la cifra que manda (lo que espera
 * nuestra respuesta), cómo va la semana frente a la anterior y el movimiento
 * reciente. Sigue el lenguaje de Estadísticas de Garantías: cifra protagonista,
 * indicadores con contexto y tendencia, filas en vez de cajas.
 * ------------------------------------------------------------------------- */

export default function SupportCenter({ onGo, user }) {
  const [meta, setMeta] = useState(null)
  const [s, setS] = useState(null)

  useEffect(() => { api.ticketMeta().then(setMeta) }, [])

  const [crones, setCrones] = useState(null)

  // Rango de fechas del gráfico «Tickets por estado» (los KPIs de arriba siguen globales).
  const [rango, setRango] = useState('all')   // all | today | 7d | 30d | custom
  const [desde, setDesde] = useState('')
  const [hasta, setHasta] = useState('')
  const rangoFechas = useCallback(() => {
    const iso = (d) => d.toISOString().slice(0, 10)
    const atras = (n) => { const d = new Date(); d.setDate(d.getDate() - n); return iso(d) }
    if (rango === 'today') return [iso(new Date()), '']
    if (rango === '7d') return [atras(7), '']
    if (rango === '30d') return [atras(30), '']
    if (rango === 'custom') return [desde, hasta]
    return ['', '']
  }, [rango, desde, hasta])

  const load = useCallback(() => {
    const [from, to] = rangoFechas()
    api.ticketStats(from, to).then(setS)
    api.cronAlertCounts().then((r) => setCrones(r.counts || null))
  }, [rangoFechas])
  useEffect(() => { load() }, [load])

  // Tiempo real: las cifras y los tickets recientes se actualizan solos.
  useEffect(() => onTicketActivity(load), [load])

  const can = (p) => (user?.permissions || []).includes(p)
  const max = Math.max(1, ...Object.values(s?.by_status || { x: 1 }))
  const p = s?.pulse
  const serie = p?.series || []

  return (
    <>
      {/* Cabecera común a todas las áreas: icono, título y subtítulo; a la derecha
          las pestañas de la sección y la acción principal. */}
      <header className="page-head ds-head">
        <span className="ds-head-ic"><Icon.headset /></span>
        <div className="ds-head-tx">
          <h1>Centro de Soporte</h1>
          <span className="sub">Cómo va la atención hoy</span>
        </div>
        <div className="spacer" />
        {can('analytics.view') && (
          <div className="ds-tabs" role="tablist">
            <button role="tab" aria-selected="true" className="on">Resumen</button>
            <button role="tab" aria-selected="false" onClick={() => onGo?.('reports')}><Icon.chart /> Informes</button>
          </div>
        )}
        {can('tickets.create') && (
          <button className="btn" onClick={() => onGo?.('ticket_new')}><Icon.plus /> Nuevo ticket</button>
        )}
      </header>

      <div className="page-scroll">
        <div className="page" style={{ maxWidth: 1180 }}>

          {/*
            AVISO DE CRONES. Solo existe si hay alguno fallando: un panel permanente
            a cero se convierte en parte del decorado y deja de leerse. Se destaca
            lo que ha empezado a fallar HOY, que es la noticia.
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
                  {crones.nuevos > 0 && crones.open > crones.nuevos && <>{crones.open} fallando en total · </>}
                  {crones.fails} {crones.fails === 1 ? 'ejecución fallida' : 'ejecuciones fallidas'} acumuladas
                </small>
              </span>
              <span className="sc-cron-go">Ver crones →</span>
            </button>
          )}

          {/* --- La cifra que manda y las que la acompañan --- */}
          <div className="ds-hero">
            <button className="ds-hero-main" onClick={() => onGo?.('tickets')}>
              <span className="k">Esperan nuestra respuesta</span>
              <b className="v">{p ? nf(p.pending) : '…'}</b>
              <span className="h">
                {p ? (
                  <>de <b>{nf(s.open)}</b> abiertos · {p.stale > 0
                    ? <><b>{nf(p.stale)}</b> sin movimiento hace más de {p.stale_days} días</>
                    : 'ninguno atascado'}</>
                ) : ' '}
              </span>
              <span className="ds-hero-go">Ir a la cola <Icon.chevron /></span>
            </button>
            <div className="ds-kpis">
              <DsKpi k="Nuevos esta semana" v={p?.new_week} prev={p?.new_prev} h="Últimos 7 días" spark={serie.map((x) => x.new)} />
              <DsKpi k="Resueltos esta semana" v={p?.solved_week} prev={p?.solved_prev} good h="Últimos 7 días" spark={serie.map((x) => x.solved)} />
              <DsKpi k="Urgentes abiertos" v={s?.urgent} tone={s?.urgent > 0 ? 'bad' : null} h="Prioridad urgente" />
              <DsKpi k="Resueltos en total" v={s?.resolved} h={s ? `de ${nf(s.total)} tickets en el sistema` : ' '} />
            </div>
          </div>

          <div className="sc-grid">
            {/* Tickets por estado */}
            <div className="card sc-panel">
              <div className="sc-panel-h">
                <b>Tickets por estado</b>
                <span className="spacer" />
                <div className="sc-range">
                  {[['all', 'Todo'], ['today', 'Hoy'], ['7d', '7 días'], ['30d', '30 días'], ['custom', 'Fechas']].map(([k, lb]) => (
                    <button key={k} className={rango === k ? 'on' : ''} onClick={() => setRango(k)}>{lb}</button>
                  ))}
                </div>
              </div>
              {rango === 'custom' && (
                <div className="sc-range-custom">
                  <label>Desde <input type="date" value={desde} max={hasta || undefined} onChange={(e) => setDesde(e.target.value)} /></label>
                  <label>Hasta <input type="date" value={hasta} min={desde || undefined} onChange={(e) => setHasta(e.target.value)} /></label>
                </div>
              )}
              <div className="sc-bars ds-bars">
                {Object.entries(meta?.statuses || {}).map(([k, label]) => {
                  const n = s?.by_status?.[k] ?? 0
                  return (
                    <div key={k} className="sc-bar-row">
                      <span className={`chip ${k}`}>{label}</span>
                      <span className="sc-bar"><i style={{ width: `${(n / max) * 100}%` }} /></span>
                      <span className="sc-bar-n">{nf(n)}</span>
                    </div>
                  )
                })}
              </div>
            </div>

            {/* Movimiento reciente: filas, no cajas */}
            <div className="card sc-panel">
              <div className="sc-panel-h">
                <b>Movimiento reciente</b>
                <span className="spacer" />
                <button className="lnk" onClick={() => onGo?.('tickets')}>Ver todos</button>
              </div>
              {!s?.recent?.length ? (
                <div className="tk-empty" style={{ padding: '30px 16px' }}>
                  <p>Aún no hay tickets.</p>
                </div>
              ) : (
                <div className="ds-rows">
                  {s.recent.map((t) => (
                    <button key={t.id} className="ds-row" onClick={() => onGo?.('tickets', t.id)}>
                      <ChannelBadge channel={t.channel} source={t.source} />
                      <span className="ds-row-main">
                        <b>{t.subject}</b>
                        <small>{t.code} · {t.contact_name || t.contact_email || 'Sin contacto'}</small>
                      </span>
                      {stChip(t.status, meta)}
                    </button>
                  ))}
                </div>
              )}
            </div>
          </div>
        </div>
      </div>
    </>
  )
}

const nf = (n) => (n == null ? '…' : Number(n).toLocaleString('es-ES'))

// Minigráfica de tendencia (14 días). Solo forma: la cifra ya está escrita al lado.
function Spark({ data }) {
  if (!data || data.length < 2) return null
  const top = Math.max(1, ...data)
  const pts = data.map((v, i) => `${(i / (data.length - 1)) * 100},${28 - (v / top) * 24}`).join(' ')
  return (
    <svg className="ds-spark" viewBox="0 0 100 30" preserveAspectRatio="none" aria-hidden="true">
      <polyline points={pts} fill="none" vectorEffect="non-scaling-stroke" />
    </svg>
  )
}

/* KPI del sistema común: etiqueta, cifra, contexto y, si hay, la comparación con la
   semana anterior y su minigráfica. `good`: subir es bueno (resueltos). */
function DsKpi({ k, v, prev, good, h, tone, spark }) {
  const diff = v != null && prev != null ? v - prev : null
  return (
    <div className={`ds-kpi${tone ? ` t-${tone}` : ''}`}>
      <span className="k">{k}</span>
      <b className="v">{nf(v)}</b>
      {diff != null && diff !== 0 && (
        <span className={`ds-diff ${good ? (diff > 0 ? 'good' : 'bad') : ''}`}>
          {diff > 0 ? '+' : '−'}{nf(Math.abs(diff))} frente a la semana anterior
        </span>
      )}
      {h ? <span className="h">{h}</span> : null}
      <Spark data={spark} />
    </div>
  )
}
