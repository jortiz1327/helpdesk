import { useState, useEffect, useCallback } from 'react'
import { api } from '../api.js'
import { Icon } from '../icons.jsx'
import { useToast } from '../App.jsx'

/* ---------------------------------------------------------------------------
 * AVISOS DE CRON — apartado propio, fuera de la bandeja de soporte.
 *
 * No son tickets de cliente: nadie le contesta a un cron. Por eso NO se abren
 * como conversación, sino como una ficha técnica con los datos de la avería y el
 * histórico de ejecuciones. Y llegan siempre SIN ASIGNAR.
 *
 * Van AGRUPADOS por cron: un cron roto cada cinco minutos manda cientos de
 * correos al día, y aquí son un solo aviso con su contador.
 * ------------------------------------------------------------------------- */

const fmt = (s) => {
  if (!s) return '—'
  const d = new Date(String(s).replace(' ', 'T'))
  const hoy = new Date()
  const mismo = d.toDateString() === hoy.toDateString()
  const hora = d.toLocaleTimeString('es-ES', { hour: '2-digit', minute: '2-digit' })
  return mismo ? `hoy ${hora}` : `${d.toLocaleDateString('es-ES', { day: 'numeric', month: 'short' })} ${hora}`
}

/** Traduce la expresión de cron a algo legible en los casos habituales. */
const cadaCuanto = (e) => {
  if (!e) return null
  const m = e.trim().match(/^\*\/(\d+) \* \* \* \*$/)
  if (m) return `cada ${m[1]} min`
  if (/^0+ \* \* \* \*$/.test(e.trim())) return 'cada hora'
  const h = e.trim().match(/^(\d{1,2}) (\d{1,2}) \* \* \*$/)
  if (h) return `a diario ${h[2].padStart(2, '0')}:${h[1].padStart(2, '0')}`
  return e
}

export default function CronAlerts({ embedded = false, onCount }) {
  const toast = useToast()
  const [d, setD] = useState(null)
  const [estado, setEstado] = useState('open')
  const [q, setQ] = useState('')
  const [abierto, setAbierto] = useState(null)   // id del aviso desplegado
  const [sel, setSel] = useState(new Set())      // selección para resolver en lote

  const load = useCallback(() => {
    api.listCronAlerts({ status: estado, q }).then((r) => {
      setD(r)
      // Avisa al padre para que el distintivo de la pestaña no se quede desfasado
      // cuando se resuelve un cron sin salir de aquí.
      onCount?.(r.counts?.open ?? 0)
    })
  }, [estado, q, onCount])
  useEffect(() => { const t = setTimeout(load, 200); return () => clearTimeout(t) }, [load])

  // Al cambiar de pestaña o de búsqueda, la selección deja de tener sentido.
  useEffect(() => { setSel(new Set()) }, [estado, q])

  /*
   * Sin diálogo de confirmación a propósito: dar por resuelto un cron no tiene
   * nada de delicado —si vuelve a fallar, el aviso se reabre solo—, y preguntar
   * en cada clic no protege de nada, solo entorpece.
   */
  const resolver = async (ids, reabrir) => {
    const lista = [].concat(ids)
    const r = await api.resolveCronAlerts(lista, reabrir)
    if (!r.ok) { toast(r.error || 'Error', 'err'); return }

    setSel(new Set())
    toast(lista.length > 1
      ? `${r.affected} avisos ${reabrir ? 'reabiertos' : 'resueltos'}`
      : (reabrir ? 'Aviso reabierto' : 'Aviso resuelto'))
    load()
  }

  const marcar = (id) => setSel((s) => { const n = new Set(s); n.has(id) ? n.delete(id) : n.add(id); return n })
  const todos = d?.alerts?.length > 0 && d.alerts.every((a) => sel.has(a.id))
  const marcarTodos = () => setSel(todos ? new Set() : new Set((d?.alerts || []).map((a) => a.id)))

  if (!d) return <div className="center-load"><div className="spinner" /></div>

  const c = d.counts || {}

  return (
    <>
      {/* Dentro de «Gestión de tickets» la cabecera ya la pone la pantalla padre. */}
      {!embedded && (
        <header className="page-head">
          <span className="sc-ic"><Icon.bolt style={{ width: 18, height: 18, fill: 'var(--primary)' }} /></span>
          <div><h1>Crones</h1></div>
          <span className="sub">· Tareas programadas que están fallando. Agrupadas por cron, no por correo.</span>
          <div className="spacer" />
        </header>
      )}

      <div className="cr-bar">
        <div className="cr-tabs">
          <button className={estado === 'open' ? 'on' : ''} onClick={() => setEstado('open')}>
            Fallando <span>{c.open ?? 0}</span>
          </button>
          <button className={estado === 'resolved' ? 'on' : ''} onClick={() => setEstado('resolved')}>
            Resueltos <span>{c.resolved ?? 0}</span>
          </button>
        </div>
        <div className="spacer" />
        {estado === 'open' && c.fails > 0 && (
          <span className="cr-total"><b>{c.fails}</b> fallos acumulados</span>
        )}
        <input className="cr-q" value={q} onChange={(e) => setQ(e.target.value)} placeholder="Cron, cliente o motivo…" />
      </div>

      {d.alerts.length === 0 ? (
        <div className="card tk-empty">
          <div className="e-ic"><Icon.check style={{ width: 26, height: 26, fill: 'var(--ink-3)' }} /></div>
          <h3>{estado === 'open' ? 'Ningún cron fallando' : 'Nada resuelto todavía'}</h3>
          <p>{estado === 'open'
            ? 'Cuando una tarea programada falle, aparecerá aquí agrupada.'
            : 'Los avisos que des por arreglados se guardarán aquí.'}</p>
        </div>
      ) : (
        <>
          {/* Barra de selección: aparece solo cuando hay algo marcado. */}
          <div className={`cr-bulk ${sel.size ? 'on' : ''}`}>
            <label className="cr-chk">
              <input type="checkbox" checked={todos} onChange={marcarTodos} />
              <span>{sel.size ? `${sel.size} seleccionados` : 'Seleccionar todos'}</span>
            </label>
            <div className="spacer" />
            {sel.size > 0 && (
              <>
                <button className="btn ghost sm" onClick={() => setSel(new Set())}>Quitar selección</button>
                <button className="btn sm" onClick={() => resolver([...sel], estado !== 'open')}>
                  <Icon.check /> {estado === 'open' ? 'Marcar como resueltos' : 'Volver a abrir'}
                </button>
              </>
            )}
          </div>

          <div className="cr-list">
            {d.alerts.map((a) => (
              <Aviso key={a.id} a={a} abierto={abierto === a.id}
                marcado={sel.has(a.id)} onMarcar={() => marcar(a.id)}
                onToggle={() => setAbierto(abierto === a.id ? null : a.id)}
                onResolver={resolver} resuelto={estado !== 'open'} />
            ))}
          </div>
        </>
      )}
    </>
  )
}

/** Una avería: cabecera siempre visible y ficha técnica al desplegar. */
function Aviso({ a, abierto, onToggle, onResolver, resuelto, marcado, onMarcar }) {
  const [det, setDet] = useState(null)

  // El detalle se pide solo al abrirlo: la lista puede tener cientos.
  useEffect(() => {
    if (abierto && !det) api.getCronAlert(a.id).then(setDet)
  }, [abierto, a.id, det])

  const frec = cadaCuanto(a.expression)

  return (
    <div className={`cr-card ${abierto ? 'on' : ''} ${resuelto ? 'ok' : ''} ${marcado ? 'sel' : ''}`}>
      {/* La casilla va FUERA del botón: un control dentro de otro no se puede pulsar. */}
      <label className="cr-chk solo" onClick={(e) => e.stopPropagation()}>
        <input type="checkbox" checked={marcado} onChange={onMarcar} />
      </label>

      <button className="cr-h" onClick={onToggle}>
        <span className={`cr-dot ${resuelto ? 'ok' : ''}`} />
        <span className="cr-name">
          <b>{a.cron_name}</b>
          {a.params && <em>{a.params}</em>}
        </span>
        <span className="cr-reason">{a.last_reason || 'Fallo'}</span>
        <span className="cr-fails" title={`${a.fails} ejecuciones fallidas`}>{a.fails}</span>
        <span className="cr-when">{fmt(a.last_at)}</span>
        <span className={`cr-chev ${abierto ? 'on' : ''}`}>▾</span>
      </button>

      {abierto && (
        <div className="cr-body">
          <div className="cr-facts">
            <div><span>Frecuencia</span><b>{frec || '—'}</b></div>
            <div><span>Código de salida</span><b>{a.last_exit_code ?? '—'}</b></div>
            <div><span>Primer fallo</span><b>{fmt(a.first_at)}</b></div>
            <div><span>Referencia</span><b className="tk-code">{a.code}</b></div>
          </div>

          {!det ? <div className="center-load"><div className="spinner" /></div> : (
            <>
              {det.alert?.last_output && (
                <div className="cr-out">
                  <div className="cr-out-h">Salida del script</div>
                  <pre>{det.alert.last_output}</pre>
                </div>
              )}
              {det.alert?.command && (
                <div className="cr-out">
                  <div className="cr-out-h">Comando</div>
                  <pre className="cr-cmd">{det.alert.command}</pre>
                </div>
              )}

              <div className="cr-runs">
                <div className="cr-out-h">Últimas ejecuciones <span>{det.runs.length}</span></div>
                {det.runs.map((r) => (
                  <div key={r.id} className="cr-run">
                    <span className="cr-run-t">{fmt(r.created_at)}</span>
                    <span className="cr-run-x">✕</span>
                    <span className="cr-run-s">{r.resumen}</span>
                  </div>
                ))}
              </div>
            </>
          )}

          <div className="cr-foot">
            {resuelto
              ? <button className="btn ghost sm" onClick={() => onResolver(a.id, true)}>Volver a abrir</button>
              : <button className="btn sm" onClick={() => onResolver(a.id, false)}><Icon.check /> Marcar como resuelto</button>}
          </div>
        </div>
      )}
    </div>
  )
}
