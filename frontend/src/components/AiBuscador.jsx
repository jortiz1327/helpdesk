import { useEffect, useRef, useState } from 'react'
import { api } from '../api.js'
import { Icon } from '../icons.jsx'

/* Buscador de la memoria para el agente (cajón lateral): manuales, casos resueltos, correcciones y plantillas.
 * Es solo búsqueda de texto sobre lo ya indexado (sin datos personales): no llama a la IA ni gasta. */
const TIPOS = [['', 'Todo'], ['manual', 'Manuales'], ['ticket', 'Casos'], ['correccion', 'Correcciones'], ['plantilla', 'Plantillas']]
const ROT = { manual: 'Manual', ticket: 'Caso resuelto', correccion: 'Corrección', plantilla: 'Plantilla' }

export default function AiBuscador({ onClose }) {
  const [q, setQ] = useState('')
  const [res, setRes] = useState(null)
  const [cargando, setCargando] = useState(false)
  const [error, setError] = useState(null)
  const [tipo, setTipo] = useState('')
  const seq = useRef(0)
  const campo = useRef(null)
  const alCerrar = useRef(onClose)
  alCerrar.current = onClose

  useEffect(() => {
    campo.current?.focus()
    const h = (e) => { if (e.key === 'Escape') alCerrar.current() }
    document.addEventListener('keydown', h)
    return () => document.removeEventListener('keydown', h)
  }, [])

  // Busca solo al dejar de escribir (350 ms): sin una petición por tecla.
  useEffect(() => {
    const t = q.trim()
    if (t.length < 3) { setRes(null); setError(null); setCargando(false); return }
    const mia = ++seq.current
    setCargando(true)
    const id = setTimeout(async () => {
      const x = await api.aiSearch(t).catch(() => null)
      if (mia !== seq.current) return
      setCargando(false)
      if (!x || x.ok === false) { setError(x?.error || 'No se pudo buscar.'); setRes(null) } else { setError(null); setRes(x.results || []) }
    }, 350)
    return () => clearTimeout(id)
  }, [q])

  const lista = (res || []).filter((r) => !tipo || r.tipo === tipo)
  const cuenta = (t) => (res || []).filter((r) => !t || r.tipo === t).length

  return (
    <>
      <div className="ai-back" onClick={onClose} />
      <aside className="ai-drawer" role="dialog" aria-modal="false" aria-label="Buscar en manuales y casos">
        <header className="ai-dh">
          <div>
            <h2><Icon.search aria-hidden="true" /> Buscar en manuales y casos</h2>
            <p>Manuales, casos resueltos y correcciones · sin IA, sin gasto</p>
          </div>
          <button className="ai-x" onClick={onClose} aria-label="Cerrar el buscador"><Icon.x aria-hidden="true" /></button>
        </header>
        <div className="ai-db" aria-live="polite">
          <input ref={campo} className="ai-bq" type="search" value={q} onChange={(e) => setQ(e.target.value)}
            placeholder="Escribe unas palabras: «cambiar idioma etiqueta», «pilas CR2450»…" aria-label="Qué buscar" />
          {res && res.length > 0 && (
            <div className="ai-bt" role="tablist" aria-label="Tipo de resultado">
              {TIPOS.filter(([k]) => !k || cuenta(k) > 0).map(([k, n]) => (
                <button key={k} role="tab" aria-selected={tipo === k} className={`ai-bchip${tipo === k ? ' on' : ''}`} onClick={() => setTipo(k)}>{n} <span>{cuenta(k)}</span></button>
              ))}
            </div>
          )}
          {q.trim().length < 3 && <p className="ai-vacio">Escribe al menos 3 letras. Busca por palabras, no por frases enteras.</p>}
          {cargando && <p className="ai-hace" role="status"><span className="ai-spin" aria-hidden="true" /> Buscando…</p>}
          {!cargando && error && <div className="ai-soft" role="alert"><span>{error}</span></div>}
          {!cargando && !error && res && res.length === 0 && <p className="ai-vacio">No hay nada parecido en la memoria. Prueba con otras palabras.</p>}
          {!cargando && !error && lista.map((r, i) => (
            <article key={i} className="ai-bres">
              <div className="ai-bh"><span className={`ai-btipo ${r.tipo}`}>{ROT[r.tipo] || r.tipo}</span><b>{r.titulo}</b></div>
              <p>{r.texto}</p>
            </article>
          ))}
        </div>
      </aside>
    </>
  )
}
