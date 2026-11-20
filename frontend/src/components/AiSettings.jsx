import { useState, useEffect, useCallback } from 'react'
import { api } from '../api.js'
import { fmtDate } from '../util.js'
import { Icon } from '../icons.jsx'
import { useToast } from '../App.jsx'

/* -------------------------------------------------------------------------
 * AJUSTES › ASISTENTE IA. Encender o apagar las sugerencias, ver cuánto
 * aciertan según lo que valoran los agentes, y alimentar la base de
 * conocimiento. La clave del proveedor NO se pone aquí: va en el .env del
 * servidor, y esta pantalla solo dice si hay una.
 * ---------------------------------------------------------------------- */
const fmtTok = (n) => (n >= 1e6 ? (n / 1e6).toFixed(2) + ' M' : n >= 1e3 ? (n / 1e3).toFixed(1) + ' k' : String(n))
const ESTADO = { pending: 'sin usar', validated: 'en uso', rejected: 'retirada' }
const euros =(usd) => (usd > 0 && usd < 0.01 ? '< 0,01 $' : usd.toFixed(2).replace('.', ',') + ' $')

export default function AiSettings() {
  const toast = useToast()
  const [d, setD] = useState(null)
  const [busy, setBusy] = useState(false)

  const load = useCallback(() => { api.aiAdmin().then((r) => setD(r?.ok ? r : { error: r?.error || 'No se pudo cargar' })) }, [])
  useEffect(() => { load() }, [load])

  if (!d) return <div className="center-load"><div className="spinner" /></div>
  if (d.error) return <div className="card em-card"><p className="em-desc">{d.error}</p></div>

  const cambiar = async (enabled) => {
    setBusy(true)
    const r = await api.aiAdminSave(enabled)
    setBusy(false)
    if (r?.ok) { setD(r); toast(enabled ? 'Asistente activado' : 'Asistente apagado') }
    else toast(r?.error || 'No se pudo guardar', 'err')
  }
  const guardarTope = async (v) => {
    setBusy(true)
    const r = await api.aiAdminBudget(v)
    setBusy(false)
    if (r?.ok) { setD(r); toast('Tope mensual guardado') }
    else toast(r?.error || 'No se pudo guardar', 'err')
  }
  const indexar = async () => {
    setBusy(true)
    const r = await api.aiAdminIndex()
    setBusy(false)
    if (r?.ok) { setD(r); toast(`Conocimiento actualizado: ${r.nuevos} tickets nuevos`) }
    else toast(r?.error || 'No se pudo actualizar', 'err')
  }

  const revisar = async (id, status) => {
    setBusy(true)
    const r = await api.aiAdminReview(id, status)
    setBusy(false)
    if (r?.ok) { setD(r); toast(status === 'validated' ? 'Corrección en uso otra vez' : status === 'rejected' ? 'Corrección retirada: la IA ya no la usa' : 'Corrección sin usar') }
    else toast(r?.error || 'No se pudo guardar', 'err')
  }

  const { stats: s, knowledge: k } = d
  const valoradas = s.up + s.down

  return (
    <>
      <div className="cfg-head"><h2>Asistente de IA</h2></div>

      <div className="card em-card">
        <p className="em-desc">
          En horario de atención, la IA <b>propone un borrador</b> de respuesta en la ficha del ticket. <b>Nunca envía nada</b>:
          la persona lo revisa, lo usa o lo corrige. Cada 👎 con su corrección se guarda y mejora las siguientes sugerencias al momento.
        </p>
        <label className="fb-req-row" style={{ marginTop: 4 }}>
          <span className="fb-switch"><input type="checkbox" checked={d.enabled} disabled={busy}
            onChange={(e) => cambiar(e.target.checked)} /><span className={`fb-toggle ${d.enabled ? 'on' : ''}`} /></span>
          <span className="fb-req-label">Sugerir respuestas a los agentes
            <span className="hint"> · {d.configured ? `${d.provider} (${d.model})` : 'falta la clave del proveedor en el .env del servidor'}</span>
          </span>
        </label>
        {!d.configured ? <p className="em-desc" role="status" style={{ marginTop: 8 }}>Sin clave no sale ningún dato hacia fuera.</p> : null}
        <p className="em-desc" style={{ marginTop: 8 }}>
          Con el asistente activo, el texto de la conversación (y los casos parecidos) viaja al proveedor para redactar el borrador.
        </p>
      </div>

      <div className="card em-card" style={{ marginTop: 12 }}>
        <h3 style={{ margin: '0 0 6px', fontSize: 15 }}>Cómo lo está haciendo</h3>
        {s.total === 0 ? (
          <p className="em-desc">Aún no hay sugerencias. Aparecerán al abrir tickets con un mensaje del cliente sin contestar.</p>
        ) : (
          <dl className="ai-stats">
            <div><dt>Sugerencias</dt><dd>{s.total}</dd></div>
            <div><dt>Usadas como base</dt><dd>{s.used}</dd></div>
            <div><dt>Valoradas</dt><dd>{valoradas}</dd></div>
            <div><dt>Acierto</dt><dd>{s.accuracy === null ? '—' : `${s.accuracy} %`}</dd></div>
            <div><dt>No supo</dt><dd>{s.abstained}</dd></div>
            <div><dt>Fallos</dt><dd>{s.failed}</dd></div>
          </dl>
        )}
        {valoradas > 0 && valoradas < 20 ? <p className="em-desc" style={{ marginTop: 8 }}>Con menos de 20 valoraciones el acierto aún dice poco.</p> : null}
      </div>

      {d.medicion && d.medicion.borradores > 0 && (() => {
        const m = d.medicion, u = m.por_uso
        const min = (x) => x === null || x === undefined ? '—' : x < 60 ? `${x} min` : `${Math.round(x / 6) / 10} h`
        return (
          <div className="card em-card" style={{ marginTop: 12 }}>
            <h3 style={{ margin: '0 0 6px', fontSize: 15 }}>¿Ayuda de verdad? <small className="muted">· últimos {m.days} días</small></h3>
            <dl className="ai-stats">
              <div><dt>Borradores</dt><dd>{m.borradores}</dd></div>
              <div><dt>Enviada casi igual</dt><dd>{m.pct_sin_cambios === null ? '—' : `${m.pct_sin_cambios} %`}</dd></div>
              <div><dt>Aprovechada (igual o retocada)</dt><dd>{m.pct_aprovechada === null ? '—' : `${m.pct_aprovechada} %`}</dd></div>
              <div><dt>Reescrita del todo</dt><dd>{u.reescrita}</dd></div>
              <div><dt>Sin respuesta aún</dt><dd>{u.sin_respuesta}</dd></div>
              <div><dt>Tarda en contestar tras la sugerencia</dt><dd>{min(m.min_hasta_enviar)}</dd></div>
            </dl>
            <p className="em-desc" style={{ marginTop: 8 }}>
              Primera respuesta a un ticket nuevo: <b>{min(m.min_primera_con_ia)}</b> con sugerencia ({m.tickets_con_ia} tickets) frente a <b>{min(m.min_primera_sin_ia)}</b> sin ella ({m.tickets_sin_ia}).
              Es orientativo: los tickets no se reparten al azar entre unos y otros, y no mide el tiempo que cada agente se ahorra al escribir.
            </p>
          </div>
        )
      })()}


      {d.budget && <AiBudget b={d.budget} busy={busy} onSave={guardarTope} />}

      {d.hotels_api && <AiPanelHoteles p={d.hotels_api} />}

      <AiHistorial />

      {d.usage && (
        <div className="card em-card" style={{ marginTop: 12 }}>
          <h3 style={{ margin: '0 0 6px', fontSize: 15 }}>Consumo y coste estimado</h3>
          <dl className="ai-stats">
            {[['Hoy', d.usage.today], ['Últimos 30 días', d.usage.month], ['Total', d.usage.total]].map(([t, u]) => (
              <div key={t}>
                <dt>{t}</dt>
                <dd>{euros(u.cost)}</dd>
                <small className="muted">{u.calls} llamadas · {fmtTok(u.input)} entrada · {fmtTok(u.output)} salida</small>
              </div>
            ))}
          </dl>
          <p className="em-desc" style={{ marginTop: 8 }}>Estimación en dólares a partir de los tokens guardados y la tarifa configurada ({d.model}); la cifra oficial es la de la consola del proveedor, que puede tardar en actualizarse. Solo cuenta las llamadas que ya devolvieron una respuesta.</p>
        </div>
      )}

      <div className="card em-card" style={{ marginTop: 12 }}>
        <h3 style={{ margin: '0 0 6px', fontSize: 15 }}>Base de conocimiento</h3>
        <p className="em-desc">
          {k.tickets} tickets resueltos y {k.manuals} trozos de la guía del cliente
          {k.last ? <> · actualizada {fmtDate(k.last)}</> : null}. Se quitan correos, teléfonos y nombres; las <b>notas internas nunca entran</b>.
        </p>
        {d.docs_pending && (d.docs_pending.changed + d.docs_pending.nuevos + d.docs_pending.borrados) > 0 && (
          <p className="ai-revisar" role="alert">
            <b>La memoria está desactualizada:</b> {[
              d.docs_pending.changed ? `${d.docs_pending.changed} documento${d.docs_pending.changed > 1 ? 's' : ''} cambiado${d.docs_pending.changed > 1 ? 's' : ''}` : null,
              d.docs_pending.nuevos ? `${d.docs_pending.nuevos} nuevo${d.docs_pending.nuevos > 1 ? 's' : ''}` : null,
              d.docs_pending.borrados ? `${d.docs_pending.borrados} borrado${d.docs_pending.borrados > 1 ? 's' : ''}` : null,
            ].filter(Boolean).join(', ')}. Pulsa «Actualizar conocimiento» para que la IA use lo último.
            {d.docs_pending.ejemplos.length > 0 ? <small className="muted"> Por ejemplo: {d.docs_pending.ejemplos.join(', ')}.</small> : null}
          </p>
        )}
        <button className="btn" disabled={busy} onClick={indexar}><Icon.refresh /> {busy ? 'Actualizando…' : 'Actualizar conocimiento'}</button>
      </div>

      <div className="card em-card" style={{ marginTop: 12 }}>
        <h3 style={{ margin: '0 0 6px', fontSize: 15 }}>Lo que la IA no ha sabido hacer</h3>
        <p className="muted" style={{ margin: '0 0 8px', fontSize: 13 }}>Consultas en las que se ha rendido por falta de base, o que han fallado. Son las pistas de qué documentar o corregir: añade la respuesta como FAQ o en la documentación y se reducirán.</p>
        {(d.gaps || []).length === 0 ? <p className="muted" style={{ margin: 0, fontSize: 13 }}>Aún no hay casos.</p> : (
          <ul className="ai-corr">
            {d.gaps.map((g) => (
              <li key={g.id}><b className="mono">{g.ticket_code}</b> <small>{fmtDate(g.created_at)} · {g.status === 'abstained' ? 'No tenía base para contestar' : 'Error: ' + (g.error || 'desconocido')}</small><q>{g.subject}</q></li>
            ))}
          </ul>
        )}
      </div>

      {d.corrections.length > 0 && (
        <div className="card em-card" style={{ marginTop: 12 }}>
          <h3 style={{ margin: '0 0 6px', fontSize: 15 }}>
            Correcciones de los agentes
          </h3>
          <p className="muted" style={{ margin: '0 0 8px', fontSize: 13 }}>
            Lo que corrige un agente entra directo en la memoria de la IA: nadie tiene que validarlo antes.
            No hace falta revisarlas una a una; solo si ves alguna mala, puedes retirarla y la IA deja de usarla.
          </p>
          <ul className="ai-corr">
            {d.corrections.map((c) => (
              <li key={c.id}>
                <b className="mono">{c.ticket_code}</b> <small>{fmtDate(c.rated_at)} · {ESTADO[c.correction_status] || c.correction_status}</small>
                <q>{c.correction}</q>
                <span style={{ display: 'inline-flex', gap: 6, marginTop: 4 }}>
                  {c.correction_status === 'rejected'
                    ? <button className="btn" disabled={busy} onClick={() => revisar(c.id, 'validated')}>Volver a usarla</button>
                    : <button className="btn" disabled={busy} onClick={() => revisar(c.id, 'rejected')}>Retirar</button>}
                </span>
              </li>
            ))}
          </ul>
        </div>
      )}
    </>
  )
}

/* Historial de valoraciones: quién valoró qué, cuándo, qué sugirió la IA, qué corrigió la persona y qué se envió al final. Solo se añade. */
const FILTROS = [['', 'Todo'], ['up', '👍 Bien'], ['down', '👎 Corregidas'], ['review', 'Revisiones']]
function AiHistorial() {
  const [abierto, setAbierto] = useState(false)
  const [filtro, setFiltro] = useState('')
  const [q, setQ] = useState('')
  const [h, setH] = useState({ total: 0, items: [] })
  const [cargando, setCargando] = useState(false)
  const cargar = useCallback(async (f, texto, offset) => {
    setCargando(true)
    try {
      const r = await api.aiAdminHistory(f, texto, offset)
      setH((p) => ({ total: r.total, items: offset ? [...p.items, ...r.items] : r.items }))
    } catch { /* el historial es de consulta: si falla, se queda como estaba */ }
    setCargando(false)
  }, [])
  useEffect(() => { if (abierto) cargar(filtro, q, 0) }, [abierto, filtro]) // eslint-disable-line react-hooks/exhaustive-deps
  const quien = (x) => x.type === 'review'
    ? `${x.user || 'Alguien'} ${x.status === 'validated' ? 'volvió a usar' : x.status === 'rejected' ? 'retiró' : 'dejó sin usar'} la corrección`
    : `${x.user || 'Alguien'} valoró ${x.rating === 'up' ? '👍 bien' : '👎 mal'}`
  return (
    <div className="card em-card" style={{ marginTop: 12 }}>
      <h3 style={{ margin: '0 0 6px', fontSize: 15 }}>Historial de valoraciones</h3>
      <p className="em-desc">Cada valoración y cada validación queda apuntada para siempre: quién, cuándo, qué sugirió la IA, qué corrigió la persona y qué se envió finalmente al cliente.</p>
      {!abierto ? <button className="btn" onClick={() => setAbierto(true)}>Ver historial</button> : (
        <>
          <div className="ai-hist-filtros">
            {FILTROS.map(([k, t]) => <button key={k} className={'btn' + (filtro === k ? ' primary' : '')} onClick={() => setFiltro(k)}>{t}</button>)}
            <form onSubmit={(e) => { e.preventDefault(); cargar(filtro, q, 0) }}>
              <input className="input" placeholder="Ticket (TK-…)" value={q} onChange={(e) => setQ(e.target.value)} />
            </form>
          </div>
          {h.items.length === 0 && !cargando ? <p className="muted" style={{ margin: '8px 0 0', fontSize: 13 }}>No hay nada que enseñar con ese filtro.</p> : (
            <ul className="ai-hist">
              {h.items.map((x) => (
                <li key={x.id} data-t={x.type === 'review' ? 'review' : x.rating}>
                  <div className="ai-hist-cab"><b>{quien(x)}</b> <small>{fmtDate(x.at)} · <span className="mono">{x.ticket_code}</span>{x.used ? ' · la copió' : ''}</small></div>
                  <details>
                    <summary>{x.subject || 'Ver detalle'}</summary>
                    <dl>
                      <div><dt>La IA sugirió</dt><dd>{x.ai || '—'}</dd></div>
                      {x.reason ? <div><dt>Motivo</dt><dd>{x.reason}</dd></div> : null}
                      {x.correction ? <div><dt>La persona indicó{x.status ? ` (${ESTADO[x.status] || x.status})` : ''}</dt><dd>{x.correction}</dd></div> : null}
                      <div><dt>Se envió al cliente</dt><dd>{x.sent ? <>{x.sent.text}<small className="muted"> — {x.sent.by || 'sistema'}, {fmtDate(x.sent.at)}</small></> : 'Todavía nada tras esta sugerencia'}</dd></div>
                    </dl>
                  </details>
                </li>
              ))}
            </ul>
          )}
          {h.items.length < h.total ? <button className="btn" disabled={cargando} onClick={() => cargar(filtro, q, h.items.length)}>{cargando ? 'Cargando…' : `Ver más (${h.total - h.items.length})`}</button> : null}
        </>
      )}
    </div>
  )
}

/* Panel de hoteles: avisa a quien lo gestiona si en las últimas 24 h ha ido lento o ha fallado. */
const RUTA = { '/api/support/v1/users/lookup': 'comprobar un correo', '/api/support/v1/hotels': 'buscar un hotel' }
function AiPanelHoteles({ p }) {
  const nombre = (r) => RUTA[r] || (r.includes('/antennas') ? 'ver las antenas' : r.includes('/tags') ? 'ver una etiqueta' : r.includes('/buffets') ? 'ver los buffets' : r)
  const titulo = p.nivel === 'ok' ? 'El panel de hoteles responde bien' : p.nivel === 'mal' ? 'El panel de hoteles está fallando' : 'El panel de hoteles ha ido algo lento'
  return (
    <div className="card em-card ai-panel-h" data-level={p.nivel} style={{ marginTop: 12 }}>
      <h3 style={{ margin: '0 0 6px', fontSize: 15 }}>{titulo}</h3>
      {p.nivel === 'ok' ? <p className="em-desc">En las últimas 24 horas no ha habido esperas largas ni fallos al consultarlo.</p> : (
        <>
          <p className="em-desc">Últimas 24 horas: {p.fallos} sin respuesta o con error · {p.apps} con alguna app de hotel sin contestar · {p.lentas} lentas (más de 6 s). Cuando falla, la IA lo dice y no afirma nada sobre el hotel ni el correo; un agente espera como máximo unos 25 s. Conviene avisar a quien gestiona el panel.</p>
          <ul className="ai-corr">
            {p.recientes.map((x, i) => (
              <li key={i}><small>{fmtDate(x.at)} · {x.seg} s · {x.motivo}</small><q>Al {nombre(x.ruta)}</q></li>
            ))}
          </ul>
        </>
      )}
    </div>
  )
}

/* Tope mensual: barra de gasto del mes natural, aviso al 80 % y al 100 %, gasto por día y tope editable. */
function AiBudget({ b, busy, onSave }) {
  const [v, setV] = useState(String(b.limit))
  useEffect(() => { setV(String(b.limit)) }, [b.limit])
  const pct = Math.min(100, b.pct ?? 0)
  const max = Math.max(0.0001, ...b.days.map((x) => x.cost))
  const fecha = (iso) => new Date(iso + 'T00:00:00').toLocaleDateString('es-ES', { day: 'numeric', month: 'long' })
  const aviso = b.level === 'over'
    ? `Tope alcanzado: la IA no sugiere a los agentes hasta el ${fecha(b.resets)}. Sube el tope aquí si hay que seguir.`
    : b.level === 'warn' ? `Llevas el ${Math.round(b.pct)} % del tope mensual. Al llegar al 100 % la IA dejará de sugerir hasta el ${fecha(b.resets)}.` : null
  return (
    <div className="card em-card ai-budget" data-level={b.level} style={{ marginTop: 12 }}>
      <h3 style={{ margin: '0 0 6px', fontSize: 15 }}>Tope mensual de gasto</h3>
      {aviso ? <p className="ai-budget-aviso" role="alert">{aviso}</p> : null}
      <div className="ai-budget-top">
        <b>{euros(b.spent)}</b><span className="muted"> de {b.limit > 0 ? euros(b.limit) : 'sin tope'} · {fecha(b.from)} a fin de mes{b.pct != null ? ` · ${b.pct.toString().replace('.', ',')} %` : ''}</span>
      </div>
      {b.limit > 0 ? <div className="ai-budget-bar" role="progressbar" aria-valuenow={Math.round(pct)} aria-valuemin={0} aria-valuemax={100}><i style={{ width: pct + '%' }} /></div> : null}
      {b.days.length > 0 && (
        <div className="ai-budget-days" aria-label="Gasto por día">
          {b.days.map((x) => <span key={x.dia} title={`${fecha(x.dia)}: ${euros(x.cost)} · ${x.calls} llamadas`}><i style={{ height: Math.max(4, x.cost / max * 100) + '%' }} /></span>)}
        </div>
      )}
      <form className="ai-budget-form" onSubmit={(e) => { e.preventDefault(); onSave(Number(v.replace(',', '.'))) }}>
        <label>Tope al mes (USD, 0 = sin tope) <input className="input" inputMode="decimal" value={v} onChange={(e) => setV(e.target.value)} /></label>
        <button className="btn" disabled={busy}>Guardar</button>
      </form>
      <p className="em-desc" style={{ marginTop: 8 }}>
        Cuenta solo las sugerencias de los agentes (mes natural; se reinicia el día 1). Los chats de prueba ({euros(b.lab)} este mes) y las evaluaciones desde la consola no cortan a los agentes. El crédito de Anthropic es compartido por toda la organización: este tope protege la parte del helpdesk.
      </p>
    </div>
  )
}
