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
const ESTADO = { pending: 'pendiente de revisar', validated: 'validada', rejected: 'rechazada' }
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
    if (r?.ok) { setD(r); toast(status === 'validated' ? 'Corrección validada: ya la usa la IA' : status === 'rejected' ? 'Corrección rechazada' : 'Corrección pendiente') }
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
          la persona lo revisa, lo usa o lo corrige. Cada 👎 con su corrección se guarda; cuando un responsable la valida, mejora las siguientes sugerencias.
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
            Correcciones de los agentes{d.corrections_pending > 0 ? ` · ${d.corrections_pending} por revisar` : ''}
          </h3>
          <p className="muted" style={{ margin: '0 0 8px', fontSize: 13 }}>
            Una corrección no llega a la IA hasta que la validas: así un error de un agente no se convierte en norma.
            Las rechazadas tampoco se usan.
          </p>
          <ul className="ai-corr">
            {d.corrections.map((c) => (
              <li key={c.id}>
                <b className="mono">{c.ticket_code}</b> <small>{fmtDate(c.rated_at)} · {ESTADO[c.correction_status] || c.correction_status}</small>
                <q>{c.correction}</q>
                <span style={{ display: 'inline-flex', gap: 6, marginTop: 4 }}>
                  {c.correction_status !== 'validated' && <button className="btn" disabled={busy} onClick={() => revisar(c.id, 'validated')}>Validar</button>}
                  {c.correction_status !== 'rejected' && <button className="btn" disabled={busy} onClick={() => revisar(c.id, 'rejected')}>Rechazar</button>}
                  {c.correction_status !== 'pending' && <button className="btn" disabled={busy} onClick={() => revisar(c.id, 'pending')}>Dejar pendiente</button>}
                </span>
              </li>
            ))}
          </ul>
        </div>
      )}
    </>
  )
}
