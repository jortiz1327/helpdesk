import { useState, useEffect } from 'react'
import { api } from '../api.js'
import { Icon } from '../icons.jsx'
import Select from './Select.jsx'

/* ---------------------------------------------------------------------------
 * GESTIÓN DE AGENTES — carga de trabajo del equipo.
 *
 * Para qué sirve realmente: un encargado abre esto para responder a UNA pregunta,
 * «¿a quién le doy el siguiente ticket?». Por eso lo primero que se ve arriba es
 * el trabajo SIN DUEÑO, y las tarjetas se ordenan por carga: quien menos tiene,
 * primero.
 * ------------------------------------------------------------------------- */

const fmtMins = (m) => (m === null || m === undefined ? '—' : m < 60 ? `${m} min` : `${Math.floor(m / 60)}h ${m % 60}m`)

/**
 * Cargas de trabajo (umbrales definidos por el cliente). Se cuentan los tickets
 * ACTIVOS: los resueltos y cerrados no ocupan a nadie.
 */
const LOADS = [
  { k: 'free', label: 'Disponible',   range: '0 tickets',   color: '#10b981', min: 0, max: 0 },
  { k: 'ok',   label: 'Normal',       range: '1-3 tickets', color: '#f59e0b', min: 1, max: 3 },
  { k: 'busy', label: 'Ocupado',      range: '4-5 tickets', color: '#f97316', min: 4, max: 5 },
  { k: 'full', label: 'Sobrecargado', range: '6+ tickets',  color: '#ef4444', min: 6, max: Infinity },
]

const loadOf = (n) => LOADS.find((l) => n >= l.min && n <= l.max) || LOADS[0]

export default function Agents({ onSeeTickets }) {
  const [d, setD] = useState(null)
  const [filter, setFilter] = useState('all')
  const [history, setHistory] = useState(null)   // agente cuyo historial se está viendo

  useEffect(() => { api.listTicketAgents().then(setD) }, [])

  if (!d) return <div className="center-load"><div className="spinner" /></div>

  const agents = (d.agents || [])
    .filter((a) => filter === 'all' || loadOf(a.open).k === filter)
    // Menos cargado primero: es a quien hay que darle el siguiente ticket
    .sort((a, b) => a.open - b.open || b.total - a.total)

  return (
    <>
      {/* Lo primero: ¿hay trabajo sin dueño? */}
      {d.unassigned > 0 && (
        <button className="ag-alert" onClick={() => onSeeTickets?.('none')}>
          <span className="ag-alert-ic"><Icon.warn /></span>
          <span>
            <b>{d.unassigned} {d.unassigned === 1 ? 'ticket sin asignar' : 'tickets sin asignar'}</b>
            <small>Nadie los ha cogido todavía. Haz clic para repartirlos.</small>
          </span>
          <span className="spacer" />
          <span className="ag-go">›</span>
        </button>
      )}

      <div className="card tk-filters" style={{ marginTop: d.unassigned > 0 ? 16 : 0 }}>
        <div className="field" style={{ minWidth: 260 }}>
          <span className="lbl">Carga de trabajo</span>
          <Select block value={filter} onChange={setFilter}
            options={[
              { value: 'all', label: 'Todas las cargas' },
              ...LOADS.map((l) => ({ value: l.k, label: l.label, sub: `(${l.range})`, color: l.color })),
            ]} />
        </div>
        <span className="spacer" />
        <span className="tk-time" style={{ marginBottom: 9 }}>
          {agents.length} {agents.length === 1 ? 'agente' : 'agentes'}
        </span>
        {filter !== 'all' && (
          <button className="btn ghost sm" onClick={() => setFilter('all')} style={{ marginBottom: 2 }}>Limpiar</button>
        )}
      </div>

      {agents.length === 0 ? (
        <div className="card tk-empty">
          <div className="e-ic"><Icon.user style={{ width: 26, height: 26, fill: 'var(--ink-2)' }} /></div>
          <h3>Ningún agente en este estado</h3>
          <p>Prueba con otro filtro de carga.</p>
        </div>
      ) : (
        <div className="ag-grid">
          {agents.map((a) => {
            const load = loadOf(a.open)
            return (
              <div key={a.id} className="card ag-card">
                <div className="ag-head">
                  <span className="ag-av">{a.name.slice(0, 1).toUpperCase()}</span>
                  <div className="ag-id">
                    <b>{a.name}</b>
                    <small>{a.email}</small>
                  </div>
                  <span className={`chip load-${load.k}`}>{load.label}</span>
                </div>

                <div className="ag-kpis">
                  <div className="ag-k blue"><b>{a.total}</b><span>Total</span></div>
                  <div className="ag-k amber"><b>{a.open}</b><span>Abiertos</span></div>
                  <div className="ag-k green"><b>{a.resolved}</b><span>Resueltos</span></div>
                </div>

                <dl className="ag-dl">
                  <dt>Carga actual</dt>
                  <dd><b>{a.open}</b> {a.open === 1 ? 'ticket activo' : 'tickets activos'}
                    {a.urgent > 0 && <span className="chip p-urgente" style={{ marginLeft: 6 }}>{a.urgent} urgente{a.urgent > 1 ? 's' : ''}</span>}
                  </dd>

                  <dt>Tasa de resolución</dt>
                  <dd>{a.rate === null ? <i className="tk-time">Sin datos</i> : <b className="ag-rate">{a.rate}%</b>}</dd>

                  <dt>T. medio de atención</dt>
                  <dd>{fmtMins(a.avg_response)}</dd>

                  <dt>T. medio de resolución</dt>
                  <dd>{fmtMins(a.avg_resolve)}</dd>
                </dl>

                <button className="btn ghost block" onClick={() => setHistory(a)}>
                  Ver historial
                </button>
              </div>
            )
          })}
        </div>
      )}

      {history && <HistoryModal agent={history} onClose={() => setHistory(null)} />}
    </>
  )
}

/* --------------------- Historial: lo que este agente YA cerró -------------------- */

const fmtDate = (s) => (s ? new Date(s.replace(' ', 'T')).toLocaleString('es-ES', { day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit' }) : '—')

function HistoryModal({ agent, onClose }) {
  const [d, setD] = useState(null)

  useEffect(() => { api.agentHistory(agent.id).then(setD) }, [agent.id])
  useEffect(() => {
    const h = (e) => e.key === 'Escape' && onClose()
    document.addEventListener('keydown', h)
    return () => document.removeEventListener('keydown', h)
  }, [onClose])

  const rows = d?.tickets || []

  return (
    <div className="modal-bg" onClick={(e) => e.target.classList.contains('modal-bg') && onClose()}>
      <div className="hist-modal">
        <header className="hist-h">
          <span className="ag-av">{agent.name.slice(0, 1).toUpperCase()}</span>
          <div className="ag-id">
            <b style={{ fontSize: 16 }}>{agent.name}</b>
            <small>{agent.email}</small>
          </div>
          <span className="spacer" />
          <button className="icon-btn" onClick={onClose} title="Cerrar (Esc)">✕</button>
        </header>

        <div className="hist-body">
          <div className="ag-kpis" style={{ gridTemplateColumns: 'repeat(4, 1fr)', marginBottom: 22 }}>
            <div className="ag-k blue"><b>{agent.total}</b><span>Total tickets</span></div>
            <div className="ag-k amber"><b>{agent.open}</b><span>Abiertos</span></div>
            <div className="ag-k green"><b>{agent.resolved}</b><span>Resueltos</span></div>
            <div className="ag-k violet"><b>{agent.rate === null ? '—' : agent.rate + '%'}</b><span>Tasa resolución</span></div>
          </div>

          <div className="fb-set-t" style={{ marginBottom: 10 }}>Historial de tickets cerrados</div>

          {!d ? <div className="center-load"><div className="spinner" /></div> : rows.length === 0 ? (
            <div className="tk-empty" style={{ padding: '40px 20px' }}>
              <div className="e-ic"><Icon.check style={{ width: 24, height: 24, fill: 'var(--ink-2)' }} /></div>
              <h3>Sin tickets cerrados</h3>
              <p>Este agente todavía no ha cerrado ningún ticket.</p>
            </div>
          ) : (
            <div className="card" style={{ padding: 0, overflowX: 'auto' }}>
              <table className="tk-table">
                <thead>
                  <tr><th>Ticket</th><th>Cliente</th><th>Asunto</th><th>Estado</th><th>T. resolución</th><th>Fecha de cierre</th></tr>
                </thead>
                <tbody>
                  {rows.map((t) => (
                    <tr key={t.id} style={{ cursor: 'default' }}>
                      <td className="tk-code">{t.code}</td>
                      <td className="tk-cli"><b>{t.contact_name || '—'}</b><small>{t.contact_email || ''}</small></td>
                      <td className="tk-subj">{t.subject}</td>
                      <td><span className={`chip ${t.status}`}>{t.status === 'resuelto' ? 'Resuelto' : 'Cerrado'}</span></td>
                      <td className="tk-time">{fmtMins(t.resolve_mins)}</td>
                      <td className="tk-time">{fmtDate(t.closed_on)}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
        </div>
      </div>
    </div>
  )
}
