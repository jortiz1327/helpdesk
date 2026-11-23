import { useState, useEffect, useRef } from 'react'
import { api } from '../api.js'
import { Icon } from '../icons.jsx'

/* Resumen del ticket para quien lo coge (cajón lateral): qué pasó, qué se ha probado y qué falta.
 * Solo lee: no envía nada ni cambia el ticket. Se guarda por último mensaje, así que reabrirlo no gasta. */
const TITULOS = ['Qué pasó', 'Qué se ha probado', 'Qué falta']

// «Qué pasó:\n- a\n- b\nQué falta:\n- c» → [{ t: 'Qué pasó', li: ['a', 'b'] }, …]; si el formato no es el esperado, un solo bloque de texto.
export function partir(texto) {
  const sec = []
  let act = null
  for (const l of String(texto || '').split('\n').map((x) => x.trim()).filter(Boolean)) {
    const t = TITULOS.find((x) => l.toLowerCase().startsWith(x.toLowerCase()))
    if (t) { act = { t, li: [] }; sec.push(act); const resto = l.slice(t.length).replace(/^[:\s-]+/, ''); if (resto) act.li.push(resto) }
    else if (act) act.li.push(l.replace(/^[-•*]\s*/, ''))
  }
  return sec.length ? sec : [{ t: 'Resumen', li: String(texto || '').split('\n').filter(Boolean) }]
}

export default function AiResumen({ ticketId, onClose }) {
  const [r, setR] = useState(null)
  const [cargando, setCargando] = useState(true)
  const [error, setError] = useState(null)
  const seq = useRef(0)
  const cierre = useRef(null)

  const cargar = async (rehacer = false) => {
    const mia = ++seq.current
    setCargando(true); setError(null)
    const x = await api.aiSummary(ticketId, rehacer)
    if (mia !== seq.current) return
    setCargando(false)
    if (x?.ok === false) setError(x.error || 'No se pudo preparar el resumen.')
    else setR(x?.summary || null)
  }
  useEffect(() => { cargar(); return () => { seq.current++ } }, [ticketId])   // eslint-disable-line react-hooks/exhaustive-deps
  const alCerrar = useRef(onClose)
  alCerrar.current = onClose
  useEffect(() => {
    cierre.current?.focus()
    const h = (e) => { if (e.key === 'Escape') alCerrar.current() }
    document.addEventListener('keydown', h)
    return () => document.removeEventListener('keydown', h)
  }, [])

  return (
    <>
      <div className="ai-back" onClick={onClose} />
      <aside className="ai-drawer" role="dialog" aria-modal="false" aria-label="Resumen del ticket">
        <header className="ai-dh">
          <div>
            <h2><Icon.bolt aria-hidden="true" /> Resumen del ticket</h2>
            <p>Para quien lo coge ahora · lo prepara la IA, revísalo con el hilo</p>
          </div>
          <button ref={cierre} className="ai-x" onClick={onClose} aria-label="Cerrar el resumen"><Icon.x aria-hidden="true" /></button>
        </header>
        <div className="ai-db" aria-live="polite">
          {cargando && <p className="ai-hace" role="status"><span className="ai-spin" aria-hidden="true" /> Leyendo el hilo…</p>}
          {!cargando && error && (
            <div className="ai-soft" role="alert">
              <span>{error}</span>
              <button className="btn sm" onClick={() => cargar(true)}>Reintentar</button>
            </div>
          )}
          {!cargando && !error && !r && <p className="ai-vacio">Este ticket aún no tiene conversación que resumir (hacen falta al menos dos mensajes).</p>}
          {!cargando && !error && r && (
            <>
              {partir(r.body).map((s) => (
                <section key={s.t} className="ai-sec" aria-label={s.t}>
                  <h3>{s.t}</h3>
                  <ul className="ai-resumen">{s.li.map((x, i) => <li key={i}>{x}</li>)}</ul>
                </section>
              ))}
              <div className="ai-act">
                <span className="ai-sub">Basado en {r.messages} mensajes (sin notas internas)</span>
                <button className="btn sm ghost ai-re" onClick={() => cargar(true)}><Icon.refresh aria-hidden="true" /> Rehacer</button>
              </div>
            </>
          )}
        </div>
      </aside>
    </>
  )
}
