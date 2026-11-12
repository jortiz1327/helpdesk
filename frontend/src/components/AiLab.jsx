import { useState, useEffect, useCallback, useRef } from 'react'
import { api } from '../api.js'
import { useToast } from '../App.jsx'

/* -------------------------------------------------------------------------
 * LABORATORIO DE LA IA (solo en local). Tickets ya resueltos: el mensaje del
 * cliente, lo que propone la IA y, plegado, lo que contestó de verdad el
 * equipo. Se valida de un vistazo: «Coincide» o «No» (con el porqué). Nada se
 * envía a nadie: todo se guarda como memoria de la IA.
 * ---------------------------------------------------------------------- */
function Caso({ c, onDone }) {
  const toast = useToast()
  const [s, setS] = useState(c.suggestion)
  const [cargando, setCargando] = useState(false)
  const [real, setReal] = useState(false)
  const [corrigiendo, setCorrigiendo] = useState(false)
  const [texto, setTexto] = useState('')
  const [guardando, setGuardando] = useState(false)

  const generar = async (regenerar = false) => {
    setCargando(true)
    const r = await api.aiLabGenerate(c.ticket_id, c.message_id, regenerar)
    setCargando(false)
    if (r?.ok) { setS(r.suggestion); setCorrigiendo(false); setTexto('') } else toast(r?.error || 'No se pudo generar', 'err')
  }
  // Las que ya estaban generadas se ven al momento; las demás se piden solas, de una en una (ver AiLab).
  useEffect(() => { if (!s && c.auto) generar() }, [c.auto])   // eslint-disable-line react-hooks/exhaustive-deps

  const valorar = async (rating, correction) => {
    setGuardando(true)
    const r = await api.aiRate(s.id, rating, correction)
    setGuardando(false)
    if (r?.ok) { setS(r.suggestion); onDone() } else toast(r?.error || 'No se pudo guardar', 'err')
  }

  const valorada = s?.rating
  return (
    <article className={`lab-case${valorada ? ' done' : ''}`}>
      <header><b className="mono">{c.code}</b> <span>{c.subject}</span>{c.contact ? <small> · {c.contact}</small> : null}</header>
      <div className="lab-msg"><small>Cliente</small><p>{c.message}</p></div>

      <div className="lab-ai">
        <small>Sugerencia de la IA</small>
        {cargando && !s ? <p className="muted"><span className="ai-spin" aria-hidden="true" /> Generando…</p>
          : !s ? <button className="btn sm primary" onClick={() => generar()} disabled={cargando}>Generar sugerencia</button>
          : s.status === 'failed' ? <p className="muted">La IA falló. <button className="btn sm ghost" onClick={() => generar(true)} disabled={cargando}>Reintentar</button></p>
          : s.status === 'abstained' ? <p className="lab-abst"><b>Derivó a una persona</b> (no se atrevió a contestar).</p>
          : <p className="lab-body">{s.body}</p>}
      </div>

      {s && s.status !== 'failed' && (
        valorada ? (
          <p className="lab-ok">{s.rating === 'up' ? '✔ Validada.' : '✘ Corrección guardada en la memoria.'}{s.rating === 'down' && s.correction ? <q>{s.correction}</q> : null}</p>
        ) : corrigiendo ? (
          <div className="ai-fix">
            <label htmlFor={`lab-${c.message_id}`}>{s.status === 'abstained' ? '¿Sabía contestar? Indica cómo debía responder.' : '¿Qué falló y cómo debe responder? Basta el criterio; se guarda como memoria.'}</label>
            <textarea id={`lab-${c.message_id}`} rows={3} value={texto} onChange={(e) => setTexto(e.target.value)} autoFocus />
            <div className="ai-act">
              <button className="btn sm primary" disabled={guardando || texto.trim().length < 5} onClick={() => valorar('down', texto)}>Guardar</button>
              <button className="btn sm ghost" onClick={() => setCorrigiendo(false)}>Cancelar</button>
            </div>
          </div>
        ) : (
          <div className="ai-act">
            <button className="btn sm primary" disabled={guardando} onClick={() => valorar('up')}>{s.status === 'abstained' ? '✔ Bien derivado' : '✔ Coincide'}</button>
            <button className="btn sm ghost" disabled={guardando} onClick={() => setCorrigiendo(true)}>{s.status === 'abstained' ? '✘ Sí sabía' : '✘ No'}</button>
            <button className="btn sm ghost" disabled={cargando} onClick={() => generar(true)}>Otra</button>
          </div>
        )
      )}

      <button className="lab-real-t" onClick={() => setReal((v) => !v)} aria-expanded={real}>{real ? 'Ocultar' : 'Ver'} lo que contestó el equipo</button>
      {real && <div className="lab-real"><p>{c.real_reply}</p></div>}
    </article>
  )
}

export default function AiLab() {
  const [casos, setCasos] = useState(null)
  const [error, setError] = useState('')
  const [lote, setLote] = useState(0)
  const [auto, setAuto] = useState(false)
  const [hechos, setHechos] = useState(0)
  const n = useRef(10)

  const cargar = useCallback(async () => {
    setCasos(null); setError(''); setHechos(0)
    const r = await api.aiLabCases(n.current)
    if (r?.ok) setCasos(r.cases); else setError(r?.error || 'No se pudo cargar')
  }, [])
  useEffect(() => { cargar() }, [cargar, lote])

  if (error) return <div className="card em-card"><p className="em-desc">{error}</p></div>
  if (!casos) return <div className="center-load"><div className="spinner" /></div>

  return (
    <div className="card em-card lab">
      <h3 style={{ margin: '0 0 4px', fontSize: 15 }}>Laboratorio (solo local)</h3>
      <p className="em-desc">
        Tickets ya resueltos: la IA contesta <b>solo con lo que había antes de la respuesta del equipo</b>. Valida con <b>Coincide</b> o <b>No</b> (y el porqué);
        no se envía nada a nadie, todo queda en la memoria de la IA. Cada sugerencia cuesta unos céntimos.
      </p>
      <div className="ai-act" style={{ marginBottom: 10 }}>
        <button className="btn sm primary" onClick={() => setAuto(true)} disabled={auto}>{auto ? 'Generando las 10…' : 'Generar todas'}</button>
        <button className="btn sm ghost" onClick={() => { setAuto(false); setLote((l) => l + 1) }}>Otros 10 casos</button>
        <small className="muted" style={{ alignSelf: 'center' }}>{hechos} / {casos.length} validadas</small>
      </div>
      {casos.length === 0 ? <p className="em-desc">No quedan tickets resueltos con respuesta humana sin validar.</p> : (
        <div className="lab-grid">
          {casos.map((c) => <Caso key={`${lote}-${c.message_id}`} c={{ ...c, auto }} onDone={() => setHechos((h) => h + 1)} />)}
        </div>
      )}
    </div>
  )
}
