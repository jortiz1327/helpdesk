import { useState, useEffect, useCallback, useRef } from 'react'
import { api } from '../api.js'
import { useToast } from '../App.jsx'

/* -------------------------------------------------------------------------
 * LABORATORIO DE LA IA (solo en local). De uno en uno: el mensaje del cliente a
 * la izquierda y lo que propone la IA a la derecha. «Coincide» o «No» (con el
 * porqué) y pasa solo al siguiente. Mientras lees uno, ya se está generando el
 * siguiente. Nada se envía a nadie: todo queda como memoria de la IA.
 * ---------------------------------------------------------------------- */
export default function AiLab() {
  const toast = useToast()
  const [casos, setCasos] = useState(null)
  const [error, setError] = useState('')
  const [i, setI] = useState(0)
  const [sug, setSug] = useState({})          // message_id → sugerencia
  const [cargando, setCargando] = useState({}) // message_id → true mientras se genera
  const [real, setReal] = useState(false)
  const [corrigiendo, setCorrigiendo] = useState(false)
  const [texto, setTexto] = useState('')
  const [guardando, setGuardando] = useState(false)
  const [hechos, setHechos] = useState(0)
  const pidiendo = useRef(new Set())

  const cargar = useCallback(async () => {
    setCasos(null); setError(''); setI(0); setSug({}); setCargando({}); setHechos(0); pidiendo.current = new Set()
    const r = await api.aiLabCases(10)
    if (r?.ok) { setCasos(r.cases); setSug(Object.fromEntries(r.cases.filter((c) => c.suggestion).map((c) => [c.message_id, c.suggestion]))) }
    else setError(r?.error || 'No se pudo cargar')
  }, [])
  useEffect(() => { cargar() }, [cargar])

  const generar = useCallback(async (c, regenerar = false) => {
    if (pidiendo.current.has(c.message_id)) return
    pidiendo.current.add(c.message_id)
    setCargando((m) => ({ ...m, [c.message_id]: true }))
    const r = await api.aiLabGenerate(c.ticket_id, c.message_id, regenerar)
    pidiendo.current.delete(c.message_id)
    setCargando((m) => ({ ...m, [c.message_id]: false }))
    if (r?.ok) setSug((m) => ({ ...m, [c.message_id]: r.suggestion }))
    else toast(r?.error || 'No se pudo generar', 'err')
  }, [toast])

  // El actual y, de fondo, el siguiente.
  useEffect(() => {
    if (!casos) return
    ;[casos[i], casos[i + 1]].forEach((c) => { if (c && !sug[c.message_id]) generar(c) })
  }, [casos, i])   // eslint-disable-line react-hooks/exhaustive-deps

  const ir = (n) => { setI(n); setReal(false); setCorrigiendo(false); setTexto('') }

  if (error) return <div className="card em-card"><p className="em-desc">{error}</p></div>
  if (!casos) return <div className="center-load"><div className="spinner" /></div>
  if (casos.length === 0) return <div className="card em-card"><p className="em-desc">No quedan tickets resueltos con respuesta humana sin validar.</p></div>
  if (i >= casos.length) {
    return (
      <div className="card em-card lab">
        <h3 style={{ margin: '0 0 4px', fontSize: 15 }}>Lote terminado</h3>
        <p className="em-desc">Has validado {hechos} de {casos.length}. Todo ha quedado en la memoria de la IA.</p>
        <button className="btn primary" onClick={cargar}>Otros 10 casos</button>
      </div>
    )
  }

  const c = casos[i]
  const s = sug[c.message_id]
  const gen = cargando[c.message_id]
  const falloIA = s?.status === 'failed'
  const abst = s?.status === 'abstained'

  const valorar = async (rating, correction) => {
    setGuardando(true)
    const r = await api.aiRate(s.id, rating, correction)
    setGuardando(false)
    if (r?.ok) { setSug((m) => ({ ...m, [c.message_id]: r.suggestion })); setHechos((h) => h + 1); ir(i + 1) }
    else toast(r?.error || 'No se pudo guardar', 'err')
  }

  return (
    <div className="card em-card lab">
      <div className="lab-top">
        <h3 style={{ margin: 0, fontSize: 15 }}>Laboratorio (solo local)</h3>
        <small className="muted">Caso {i + 1} de {casos.length} · {hechos} validados</small>
        <span style={{ flex: 1 }} />
        <button className="btn sm ghost" onClick={() => ir(i + 1)}>Saltar</button>
      </div>
      <p className="em-desc" style={{ marginTop: 4 }}>La IA contesta solo con lo que había antes de la respuesta del equipo. No se envía nada: todo es memoria para la IA.</p>

      <div className="lab-one">
        <section className="lab-msg">
          <small>Cliente · <b className="mono">{c.code}</b>{c.contact ? ` · ${c.contact}` : ''}</small>
          <h4>{c.subject}</h4>
          <p>{c.message}</p>
        </section>

        <section className="lab-ai">
          <small>Sugerencia de la IA</small>
          {gen && !s ? <p className="muted"><span className="ai-spin" aria-hidden="true" /> Generando…</p>
            : !s ? <button className="btn sm primary" onClick={() => generar(c)}>Generar sugerencia</button>
            : falloIA ? <p className="muted">La IA falló. <button className="btn sm ghost" onClick={() => generar(c, true)} disabled={gen}>Reintentar</button></p>
            : abst ? <p className="lab-abst"><b>Derivó a una persona</b>: no se atrevió a contestar.</p>
            : <p className="lab-body">{s.body}</p>}

          {s && !falloIA && !corrigiendo && (
            <div className="ai-act lab-btns">
              <button className="btn primary" disabled={guardando} onClick={() => valorar('up')}>{abst ? '✔ Bien derivado' : '✔ Coincide'}</button>
              <button className="btn" disabled={guardando} onClick={() => setCorrigiendo(true)}>{abst ? '✘ Sí sabía' : '✘ No'}</button>
              <button className="btn ghost" disabled={gen} onClick={() => generar(c, true)}>{gen ? 'Generando…' : 'Otra'}</button>
            </div>
          )}
          {s && corrigiendo && (
            <div className="ai-fix">
              <label htmlFor="lab-fix">{abst ? '¿Sabía contestar? Indica cómo debía responder.' : '¿Qué falló y cómo debe responder? Basta el criterio; se guarda como memoria.'}</label>
              <textarea id="lab-fix" rows={4} value={texto} onChange={(e) => setTexto(e.target.value)} autoFocus />
              <div className="ai-act">
                <button className="btn primary" disabled={guardando || texto.trim().length < 5} onClick={() => valorar('down', texto)}>Guardar y siguiente</button>
                <button className="btn ghost" onClick={() => setCorrigiendo(false)}>Cancelar</button>
              </div>
            </div>
          )}
        </section>
      </div>

      <button className="lab-real-t" onClick={() => setReal((v) => !v)} aria-expanded={real}>{real ? 'Ocultar' : 'Ver'} lo que contestó el equipo</button>
      {real && <div className="lab-real"><p>{c.real_reply}</p></div>}
    </div>
  )
}
