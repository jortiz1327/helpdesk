import { useState, useEffect, useRef } from 'react'
import { api } from '../api.js'
import { Icon } from '../icons.jsx'
import { useToast } from '../App.jsx'

/* -------------------------------------------------------------------------
 * PANEL DE LA SUGERENCIA DE LA IA (cajón lateral, SOLO LECTURA).
 * La IA no responde ni escribe nada en el editor. Mientras trabaja, el panel va
 * enseñando cada paso (hotel, correo, antenas, memoria…); al acabar queda el
 * detalle de lo que ha consultado y el borrador. La persona puede COPIARLO y,
 * sobre todo, valorarlo: «Útil», o «Corregir» con lo que debía decir. Esa
 * valoración se guarda y alimenta las siguientes sugerencias.
 * ---------------------------------------------------------------------- */
const ROTULO = { filtro: 'Filtro', hotel: 'Hotel', correo: 'Correo', rol: 'Usuario', antenas: 'Antenas', etiqueta: 'Etiqueta', memoria: 'Memoria' }
const hora = (iso) => { try { return new Date(iso).toLocaleTimeString('es-ES', { hour: '2-digit', minute: '2-digit' }) } catch { return '' } }

function Marca({ ok }) {
  if (ok === false) return <Icon.warn className="ai-ic warn" aria-label="Atención" />
  if (ok === true) return <Icon.check className="ai-ic ok" aria-label="Correcto" />
  return <Icon.dot className="ai-ic" aria-label="Informativo" />
}

/* Una fila de «Qué ha hecho la IA»: etiqueta a la izquierda, lo encontrado a la derecha. */
function Fila({ p }) {
  const d = p.datos
  let cuerpo
  if (p.k === 'hotel' && d) cuerpo = <><b>{d.nombre}</b>{d.grupo ? <span className="ai-sub"> · {d.grupo}</span> : null}{d.otras > 0 ? <span className="ai-sub"> · {d.otras} coincidencias más</span> : null}</>
  else if (p.k === 'correo' && d) cuerpo = <><b>{d.correo}</b><span className="ai-sub"> · verificado en {d.sitios.join('; ')}</span></>
  else if (p.k === 'antenas' && d) {
    const en = d.lista.filter((a) => a.en_linea).length
    const todas = en === d.lista.length
    cuerpo = (
      <>
        <span className={`ai-estado ${todas ? 'ok' : 'mal'}`}><span className="ai-punto" aria-hidden="true" />{todas ? 'Todas en línea' : `${d.lista.length - en} sin conexión`}<span className="ai-estado-n"> · {en} de {d.lista.length}</span></span>
        <span className="ai-sub"> · {d.buffet}</span>
        <details className="ai-det" open={!todas}>
          <summary>Ver antenas</summary>
          <table className="ai-tabla">
            <thead><tr><th scope="col">Antena</th><th scope="col">Estado</th><th scope="col" className="num">Etiquetas</th></tr></thead>
            <tbody>
              {d.lista.map((a) => (
                <tr key={a.nombre} className={a.en_linea ? '' : 'caida'}>
                  <td>{a.nombre}</td>
                  <td><span className={`ai-estado sm ${a.en_linea ? 'ok' : 'mal'}`}><span className="ai-punto" aria-hidden="true" />{a.en_linea ? 'En línea' : `Sin conexión${a.caida_desde ? ' desde ' + hora(a.caida_desde) : ''}`}</span></td>
                  <td className="num">{a.etiquetas}</td>
                </tr>
              ))}
            </tbody>
          </table>
          {d.consultado && <span className="ai-sub ai-pie">Consultado a las {hora(d.consultado)}{d.en_vivo === false ? ' · datos en caché' : ' · en vivo'}</span>}
        </details>
      </>
    )
  } else if (p.k === 'memoria' && d) {
    cuerpo = (
      <>
        <b>{d.faqs} preguntas frecuentes</b><span className="ai-sub"> · {d.casos.length} casos y manuales parecidos</span>
        {d.casos.length > 0 && <ul className="ai-lista">{d.casos.slice(0, 4).map((c, i) => <li key={i}>{c.titulo || '(sin título)'}</li>)}</ul>}
      </>
    )
  } else cuerpo = <>{p.texto}</>
  return (
    <div className={`ai-fila ${p.ok === false ? 'bad' : ''}`}>
      <dt><Marca ok={p.ok} />{ROTULO[p.k] || p.k}</dt>
      <dd>{cuerpo}</dd>
    </div>
  )
}

export default function AiSuggestion({ ticketId, onClose }) {
  const toast = useToast()
  const [s, setS] = useState(null)
  const [vivo, setVivo] = useState([])         // pasos que van llegando
  const [hace, setHace] = useState('')          // lo que está haciendo ahora
  const [cargando, setCargando] = useState(true)
  const [fallo, setFallo] = useState(null)
  const [corrigiendo, setCorrigiendo] = useState(false)
  const [texto, setTexto] = useState('')
  const [guardando, setGuardando] = useState(false)
  const [copiado, setCopiado] = useState(false)
  const seq = useRef(0)
  const cierre = useRef(null)

  const cargar = async (regenerar = false) => {
    const mia = ++seq.current
    setCargando(true); setFallo(null); setVivo([]); setHace('Empezando…'); setCorrigiendo(false); setTexto(''); setCopiado(false)
    const r = await api.aiSuggestStream(ticketId, regenerar, (e) => {
      if (mia !== seq.current) return
      if (e.paso) setVivo((v) => [...v, e.paso])
      if (e.hace) setHace(e.hace)
    })
    if (mia !== seq.current) return
    setCargando(false); setHace('')
    if (r?.ok === false) { setFallo(r.error || 'No se pudo pedir la sugerencia.'); return }
    setS(r?.suggestion || null)
  }

  useEffect(() => { cargar(); return () => { seq.current++ } }, [ticketId])   // eslint-disable-line react-hooks/exhaustive-deps
  useEffect(() => {
    cierre.current?.focus()
    const h = (e) => { if (e.key === 'Escape') onClose() }
    document.addEventListener('keydown', h)
    return () => document.removeEventListener('keydown', h)
  }, [onClose])

  const noSabe = s?.status === 'abstained'
  const guardada = Array.isArray(s?.sources?.traza) ? s.sources.traza : []
  const pasos = (cargando ? vivo : guardada.length ? guardada : vivo).filter((p) => p.k !== 'salida')
  const salida = guardada.find((p) => p.k === 'salida')
  const tipo = salida?.texto?.match(/·\s*([^(]+?)\s*\(estimado\)/)?.[1]

  const copiar = async () => {
    try {
      await navigator.clipboard.writeText(s.body)
      api.aiUsed(s.id)
      setCopiado(true); setS({ ...s, used: true })
      toast?.('Texto copiado: pégalo en tu respuesta y revísalo antes de enviar')
    } catch { toast?.('No se pudo copiar: selecciona el texto a mano', 'err') }
  }
  const valorar = async (rating, correction) => {
    setGuardando(true)
    const r = await api.aiRate(s.id, rating, correction)
    setGuardando(false)
    if (r?.ok) { setS(r.suggestion); setCorrigiendo(false); toast?.(rating === 'up' ? 'Gracias: anotado' : 'Corrección guardada: la IA la tendrá en cuenta') }
    else toast?.(r?.error || 'No se pudo guardar la valoración', 'err')
  }

  return (
    <>
      <div className="ai-back" onClick={onClose} />
      <aside className="ai-drawer" role="dialog" aria-modal="false" aria-label="Sugerencia de la IA">
        <header className="ai-dh">
          <div>
            <h2><Icon.bolt aria-hidden="true" /> Sugerencia de la IA</h2>
            <p>Solo lectura: no se envía nada al cliente</p>
          </div>
          <button ref={cierre} className="ai-x" onClick={onClose} aria-label="Cerrar el panel"><Icon.x aria-hidden="true" /></button>
        </header>

        <div className="ai-db" aria-live="polite">
          {/* Qué está haciendo (en vivo) y qué ha hecho */}
          {(cargando || pasos.length > 0) && (
            <section className="ai-sec" aria-label="Qué ha hecho la IA">
              <h3>{cargando ? 'Trabajando en ello' : 'Qué ha hecho la IA'}</h3>
              <dl className="ai-filas">
                {pasos.map((p, i) => <Fila key={i} p={p} />)}
              </dl>
              {cargando && <p className="ai-hace" role="status"><span className="ai-spin" aria-hidden="true" /> {hace}</p>}
            </section>
          )}

          {!cargando && fallo && (
            <div className="ai-soft" role="alert">
              <span><b>No se pudo pedir la sugerencia.</b> {fallo} Puedes responder tú mientras tanto.</span>
              <button className="btn sm" onClick={() => cargar()}>Reintentar</button>
            </div>
          )}

          {!cargando && !fallo && !s && <p className="ai-vacio">Este ticket aún no tiene un mensaje del cliente al que sugerir respuesta.</p>}

          {!cargando && !fallo && s && s.status === 'failed' && (
            <div className="ai-soft" role="alert">
              <span><b>La IA no pudo preparar una sugerencia.</b> Suele ser un fallo puntual: reintenta, y si sigue igual, respóndelo tú.</span>
              <button className="btn sm" onClick={() => cargar(true)}>Reintentar</button>
            </div>
          )}

          {!cargando && !fallo && s && s.status !== 'failed' && (
            <>
              <section className="ai-sec ai-resp" aria-label="Respuesta preparada">
                <div className="ai-resp-h">
                  <h3>{noSabe ? 'La IA no se atreve' : 'Respuesta preparada'}{tipo && !noSabe ? <span className="ai-sub"> · {tipo}</span> : null}</h3>
                  {!noSabe && <button className="btn sm primary" onClick={copiar}>{copiado ? <><Icon.check aria-hidden="true" /> Copiado</> : <><Icon.copy aria-hidden="true" /> Copiar texto</>}</button>}
                </div>
                {noSabe
                  ? <p className="ai-body">No tiene claro cómo responder y prefiere no inventar: respóndelo tú. Si sí sabías qué contestar, díselo con «Sí sabía» y aprenderá.</p>
                  : <p className="ai-body ai-borrador">{s.body}</p>}
              </section>

              <section className="ai-sec" aria-label="Valoración">
                <h3>¿Qué te parece?</h3>
                {s.rating ? (
                  <div className="ai-done">
                    <p>{s.rating === 'up' ? (noSabe ? 'Anotado: era un caso para una persona.' : 'Valoraste esta sugerencia como útil.') : 'Corrección guardada. Gracias, así aprende.'}</p>
                    {s.rating === 'down' && s.correction ? <q>{s.correction}</q> : null}
                    {s.rating === 'down' && <div className="ai-act"><button className="btn sm primary" onClick={() => cargar(true)}>Generar de nuevo con esta indicación</button></div>}
                  </div>
                ) : corrigiendo ? (
                  <div className="ai-fix">
                    <label htmlFor={`aifix-${s.id}`}>¿Qué falló y cómo debe responder? Basta con el criterio, no hace falta redactar el mensaje.</label>
                    <textarea id={`aifix-${s.id}`} rows={4} value={texto} onChange={(e) => setTexto(e.target.value)} autoFocus
                      placeholder="Ej.: es ambiguo; para cambiar platos en un hotel se hace desde Posiciones, indícaselo paso a paso." />
                    <div className="ai-act">
                      <button className="btn sm primary" disabled={guardando || texto.trim().length < 5} onClick={() => valorar('down', texto)}>Guardar corrección</button>
                      <button className="btn sm ghost" onClick={() => setCorrigiendo(false)}>Cancelar</button>
                    </div>
                  </div>
                ) : (
                  <div className="ai-act">
                    <button className="btn sm" onClick={() => valorar('up')} disabled={guardando}><Icon.check aria-hidden="true" /> {noSabe ? 'Mejor una persona' : 'Útil'}</button>
                    <button className="btn sm" onClick={() => setCorrigiendo(true)}><Icon.pencil aria-hidden="true" /> {noSabe ? 'Sí sabía' : 'Corregir'}</button>
                    <button className="btn sm ghost ai-re" onClick={() => cargar(true)}><Icon.refresh aria-hidden="true" /> Otra sugerencia</button>
                  </div>
                )}
              </section>
            </>
          )}
        </div>
      </aside>
    </>
  )
}
