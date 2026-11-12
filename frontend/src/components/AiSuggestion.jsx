import { useState, useEffect, useRef } from 'react'
import { api } from '../api.js'
import { Icon } from '../icons.jsx'

/* -------------------------------------------------------------------------
 * SUGERENCIA DE LA IA para el último mensaje del cliente. La IA NO responde:
 * propone un borrador. La persona lo usa (pasa al editor, donde lo revisa y lo
 * envía) y, sobre todo, lo valora: 👍, o 👎 con lo que debía decir. Esa
 * corrección se guarda y alimenta las siguientes sugerencias.
 * Si el asistente está apagado no se pinta nada.
 * ---------------------------------------------------------------------- */
export default function AiSuggestion({ ticketId, lastMessageKey, onUse, toast }) {
  const [s, setS] = useState(null)          // sugerencia vigente
  const [on, setOn] = useState(false)       // ¿asistente activo?
  const [cargando, setCargando] = useState(false)
  const [corrigiendo, setCorrigiendo] = useState(false)
  const [texto, setTexto] = useState('')
  const [guardando, setGuardando] = useState(false)
  const seq = useRef(0)

  const cargar = async (regenerar = false) => {
    const mia = ++seq.current
    setCargando(true)
    const r = await api.aiSuggest(ticketId, regenerar)
    if (mia !== seq.current) return
    setCargando(false)
    setOn(!!r?.enabled)
    setS(r?.suggestion || null)
    setCorrigiendo(false); setTexto('')
  }

  // Al abrir el ticket o cuando llega un mensaje nuevo del cliente.
  useEffect(() => { cargar(); return () => { seq.current++ } }, [ticketId, lastMessageKey])   // eslint-disable-line react-hooks/exhaustive-deps

  if (!on) return null
  if (cargando && !s) return <div className="ai-box ai-load" aria-busy="true"><Icon.bolt className="ic-x" aria-hidden="true" /> Preparando una sugerencia…</div>
  if (!s) return null
  if (s.status === 'failed') {
    return (
      <div className="ai-box ai-soft">
        <span>La IA no pudo preparar una sugerencia ahora.</span>
        <button className="btn sm ghost" onClick={() => cargar(true)} disabled={cargando}>Reintentar</button>
      </div>
    )
  }
  if (s.status === 'abstained') {
    return <div className="ai-box ai-soft"><span>La IA no tiene base para contestar esto con fiabilidad. Respóndelo tú; si quieres que aprenda, anótalo en una FAQ.</span></div>
  }

  const usar = () => { api.aiUsed(s.id); onUse(s.body); setS({ ...s, used: true }) }
  const valorar = async (rating, correction) => {
    setGuardando(true)
    const r = await api.aiRate(s.id, rating, correction)
    setGuardando(false)
    if (r?.ok) { setS(r.suggestion); setCorrigiendo(false); toast?.(rating === 'up' ? 'Gracias: anotado' : 'Corrección guardada: la IA la tendrá en cuenta') }
    else toast?.(r?.error || 'No se pudo guardar la valoración', 'err')
  }

  return (
    <div className="ai-box" role="region" aria-label="Sugerencia de la IA">
      <div className="ai-head">
        <b><Icon.bolt className="ic-x" aria-hidden="true" /> Sugerencia de la IA</b>
        <small>Borrador: revísalo antes de enviar</small>
      </div>
      <p className="ai-body">{s.body}</p>

      {s.rating ? (
        <div className="ai-done">
          {s.rating === 'up' ? 'Valoraste esta sugerencia como útil.' : 'Corrección guardada. Gracias, así aprende.'}
          {s.rating === 'down' && s.correction ? <q>{s.correction}</q> : null}
        </div>
      ) : corrigiendo ? (
        <div className="ai-fix">
          <label htmlFor={`aifix-${s.id}`}>¿Qué debía decir? Se guarda para que la IA mejore.</label>
          <textarea id={`aifix-${s.id}`} rows={3} value={texto} onChange={(e) => setTexto(e.target.value)} autoFocus />
          <div className="ai-act">
            <button className="btn sm primary" disabled={guardando || texto.trim().length < 5} onClick={() => valorar('down', texto)}>Guardar corrección</button>
            <button className="btn sm ghost" onClick={() => setCorrigiendo(false)}>Cancelar</button>
          </div>
        </div>
      ) : (
        <div className="ai-act">
          <button className="btn sm primary" onClick={usar} disabled={s.used}>{s.used ? 'Usada' : 'Usar como base'}</button>
          <button className="btn sm ghost" onClick={() => valorar('up')} disabled={guardando} aria-label="Valorar como útil" title="Es útil">👍 Útil</button>
          <button className="btn sm ghost" onClick={() => setCorrigiendo(true)} aria-label="Valorar como incorrecta y corregir" title="Está mal: la corrijo">👎 Corregir</button>
          <button className="btn sm ghost ai-re" onClick={() => cargar(true)} disabled={cargando}>Otra sugerencia</button>
        </div>
      )}
    </div>
  )
}
