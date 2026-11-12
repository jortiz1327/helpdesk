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

  const { stats: s, knowledge: k } = d
  const valoradas = s.up + s.down

  return (
    <>
      <div className="cfg-head"><h2>Asistente de IA</h2></div>

      <div className="card em-card">
        <p className="em-desc">
          En horario de atención, la IA <b>propone un borrador</b> de respuesta en la ficha del ticket. <b>Nunca envía nada</b>:
          la persona lo revisa, lo usa o lo corrige. Cada 👎 con su corrección se guarda y mejora las siguientes sugerencias.
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
          <h3 style={{ margin: '0 0 6px', fontSize: 15 }}>Últimas correcciones de los agentes</h3>
          <ul className="ai-corr">
            {d.corrections.map((c) => (
              <li key={c.id}><b className="mono">{c.ticket_code}</b> <small>{fmtDate(c.rated_at)}</small><q>{c.correction}</q></li>
            ))}
          </ul>
        </div>
      )}
    </>
  )
}
