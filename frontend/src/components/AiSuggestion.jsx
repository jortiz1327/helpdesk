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
export default function AiSuggestion({ ticketId, lastMessageKey, onUse, toast, inline = false }) {
  const [s, setS] = useState(null)          // sugerencia vigente
  const [on, setOn] = useState(inline)   // en la pestaña ya sabemos que está activo       // ¿asistente activo?
  const [cargando, setCargando] = useState(inline)   // en la pestaña arranca «generando» desde el primer pintado
  const [corrigiendo, setCorrigiendo] = useState(false)
  const [texto, setTexto] = useState('')
  const [guardando, setGuardando] = useState(false)
  const [fallo, setFallo] = useState(null)   // la petición misma falló (sin red, tardó demasiado…): se avisa, no se esconde
  const seq = useRef(0)

  const cargar = async (regenerar = false) => {
    const mia = ++seq.current
    setCargando(true)
    const r = await api.aiSuggest(ticketId, regenerar)
    if (mia !== seq.current) return
    setCargando(false)
    if (r?.ok === false) { setOn(true); setFallo(r.error || 'No se pudo pedir la sugerencia.'); return }
    setFallo(null)
    setOn(!!r?.enabled)
    setS(r?.suggestion || null)
    // En la pestaña de la composición, la sugerencia se escribe sola en el editor (si está vacío).
    const n = r?.suggestion
    if (inline && n?.status === 'ready' && !n.used && !n.rating && onUse(n.body, true)) { api.aiUsed(n.id); setS({ ...n, used: true }) }
    setCorrigiendo(false); setTexto('')
  }

  // Al abrir el ticket o cuando llega un mensaje nuevo del cliente.
  useEffect(() => { cargar(); return () => { seq.current++ } }, [ticketId, lastMessageKey])   // eslint-disable-line react-hooks/exhaustive-deps

  if (!on) return null
  if (cargando && (!s || inline)) return <div className="ai-box ai-load" role="status" aria-busy="true"><span className="ai-spin" aria-hidden="true" /> Generando sugerencia…</div>
  if (fallo) {
    return (
      <div className="ai-box ai-soft" role="alert">
        <span><b>No se pudo pedir la sugerencia.</b> {fallo} Puedes responder tú mientras tanto.</span>
        <button className="btn sm ghost" onClick={() => cargar()} disabled={cargando}>Reintentar</button>
      </div>
    )
  }
  if (!s) return null
  if (s.status === 'failed') {
    return (
      <div className="ai-box ai-soft" role="alert">
        <span><b>La IA no pudo preparar una sugerencia.</b> Suele ser un fallo puntual: reintenta, y si sigue igual, respóndelo tú.</span>
        <button className="btn sm ghost" onClick={() => cargar(true)} disabled={cargando}>Reintentar</button>
      </div>
    )
  }
  const noSabe = s.status === 'abstained'

  const usar = () => { api.aiUsed(s.id); onUse(s.body, false); setS({ ...s, used: true }) }
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
        <b><Icon.bolt className="ic-x" aria-hidden="true" /> {noSabe ? 'La IA no se atreve con este ticket' : 'Sugerencia de la IA'}</b>
        <small>{noSabe ? 'No hay borrador' : inline && s.used ? 'Escrita en el editor: revísala antes de enviar' : 'Borrador: revísalo antes de enviar'}</small>
      </div>
      {noSabe
        ? <p className="ai-body">No tiene claro cómo responder y prefiere no inventar: respóndelo tú. Si sí sabías qué contestar, díselo con «Sí sabía» y aprenderá.</p>
        : !(inline && s.used) && <p className="ai-body">{s.body}</p>}

      {s.rating ? (
        <div className="ai-done">
          {s.rating === 'up' ? (noSabe ? 'Anotado: era un caso para una persona.' : 'Valoraste esta sugerencia como útil.') : 'Corrección guardada. Gracias, así aprende.'}
          {s.rating === 'down' && s.correction ? <q>{s.correction}</q> : null}
          {s.rating === 'down' && (
            <div className="ai-act">
              <button className="btn sm primary" onClick={() => cargar(true)} disabled={cargando}>Generar de nuevo con esta indicación</button>
            </div>
          )}
        </div>
      ) : corrigiendo ? (
        <div className="ai-fix">
          <label htmlFor={`aifix-${s.id}`}>¿Qué falló y cómo debe responder? Basta con el criterio, no hace falta redactar el mensaje. Se guarda para que la IA mejore.</label>
          <textarea id={`aifix-${s.id}`} rows={3} value={texto} onChange={(e) => setTexto(e.target.value)} autoFocus
            placeholder="Ej.: es ambiguo; para cambiar platos en un hotel se hace desde Posiciones, indícaselo paso a paso." />
          <div className="ai-act">
            <button className="btn sm primary" disabled={guardando || texto.trim().length < 5} onClick={() => valorar('down', texto)}>Guardar corrección</button>
            <button className="btn sm ghost" onClick={() => setCorrigiendo(false)}>Cancelar</button>
          </div>
        </div>
      ) : (
        <div className="ai-act">
          {!noSabe && <button className="btn sm primary" onClick={usar} disabled={s.used}>{s.used ? 'Usada' : (inline ? 'Escribir en el editor' : 'Usar como base')}</button>}
          <button className="btn sm ghost" onClick={() => valorar('up')} disabled={guardando} aria-label={noSabe ? 'Valorar como bien llevado por una persona' : 'Valorar como útil'} title={noSabe ? 'Hizo bien: lo lleva una persona' : 'Es útil'}>{noSabe ? '👍 Mejor una persona' : '👍 Útil'}</button>
          <button className="btn sm ghost" onClick={() => setCorrigiendo(true)} aria-label={noSabe ? 'Indicar que sí sabía responder' : 'Valorar como incorrecta y corregir'} title={noSabe ? 'Sí sabía: cuento cómo debía responder' : 'Está mal: la corrijo'}>{noSabe ? '👎 Sí sabía' : '👎 Corregir'}</button>
          <button className="btn sm ghost ai-re" onClick={() => cargar(true)} disabled={cargando}>Otra sugerencia</button>
        </div>
      )}
    </div>
  )
}
