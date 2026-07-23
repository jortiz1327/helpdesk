import { useRef, useState, useEffect } from 'react'
import { Icon } from '../icons.jsx'
import RichInput from './RichInput.jsx'

const esCorreo = (d) => /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(d.trim())

/**
 * COMPOSER — el editor de respuestas del ticket: RichInput + botón de enviar.
 *
 * En los tickets de correo lleva además los DESTINATARIOS: quien venía en copia en
 * el hilo sigue en la conversación, así que se propone solo y el agente decide.
 */
export default function Composer({ onSend, disabled = false, disabledHint, to, ccSugerido = [] }) {
  const ed = useRef(null)
  const [empty, setEmpty] = useState(true)
  const [mode, setMode] = useState('reply') // 'reply' = al cliente · 'note' = interna
  const note = mode === 'note'

  const [cc, setCc] = useState([])
  const [bcc, setBcc] = useState([])
  const [verCopias, setVerCopias] = useState(false)

  /* Las copias del hilo llegan con el ticket (asíncronas) y se proponen marcadas:
     si alguien estaba en la conversación, dejarlo fuera sin querer es el fallo caro. */
  useEffect(() => {
    setCc(ccSugerido)
    if (ccSugerido.length) setVerCopias(true)
  }, [ccSugerido.join(',')])

  const send = () => {
    onSend?.({ html: ed.current.getHtml(), files: ed.current.getFiles(), internal: note, cc, bcc })
    ed.current.reset()
    setEmpty(true)
    setBcc([])   // el Cco no se arrastra al siguiente mensaje; el Cc sí (sigue el hilo)
  }

  return (
    <div className={`cmp-wrap ${note ? 'note-mode' : ''}`} onKeyDown={(e) => {
      if ((e.ctrlKey || e.metaKey) && e.key === 'Enter' && !disabled && !empty) send()
    }}>
      {/* Conmutador: responder al cliente vs nota interna (solo la ven los agentes) */}
      <div className="cmp-mode">
        <button type="button" className={`cmp-mode-btn ${!note ? 'on' : ''}`} onClick={() => setMode('reply')}>
          <Icon.send /> Responder
        </button>
        <button type="button" className={`cmp-mode-btn note ${note ? 'on' : ''}`} onClick={() => setMode('note')}>
          <Icon.note /> Nota interna
        </button>
      </div>
      {/* Destinatarios: solo al responder (una nota interna no se envía a nadie). */}
      {!note && to && (
        <div className="cmp-dest">
          <div className="cmp-dest-l">
            <span className="cmp-dest-k">Para</span>
            <span className="cmp-dest-to">{to}</span>
            <span className="spacer" />
            <button type="button" className="cmp-dest-mas" onClick={() => setVerCopias((v) => !v)}>
              {verCopias ? 'Ocultar copias' : (cc.length ? `Cc (${cc.length})` : 'Añadir copia')}
            </button>
          </div>

          {verCopias && (
            <>
              <Direcciones etiqueta="Cc" valor={cc} onChange={setCc} disabled={disabled}
                pista="Se ve quién está en copia" />
              <Direcciones etiqueta="Cco" valor={bcc} onChange={setBcc} disabled={disabled}
                pista="Nadie ve que están" />
            </>
          )}
        </div>
      )}

      {/* canned = activa el menú «/» de respuestas predefinidas */}
      <RichInput
        ref={ed}
        disabled={disabled}
        canned
        minHeight={84}
        placeholder={disabled ? (disabledHint || 'No disponible')
          : note ? 'Escribe una nota interna… (solo la verán los agentes)'
          : 'Escribe tu respuesta… (o / para respuestas rápidas)'}
        onChange={() => setEmpty(ed.current.isEmpty())}
      />
      <div className="cmp-foot">
        {disabled && <span className="cmp-warn"><Icon.warn /> {disabledHint}</span>}
        {!disabled && note && <span className="cmp-note-tag"><Icon.lock /> No se envía al cliente</span>}
        <span className="spacer" />
        <span className="cmp-hint">Ctrl + Enter</span>
        <button className={`btn ${note ? 'note-btn' : ''}`} disabled={disabled || empty} onClick={send}>
          {note ? <><Icon.note /> Guardar nota</> : <><Icon.send /> Enviar respuesta</>}
        </button>
      </div>
    </div>
  )
}

/**
 * Lista de direcciones como etiquetas. Se añade con Enter, coma o al salir del campo;
 * lo que no sea un correo válido se marca en rojo en vez de perderse en silencio.
 */
function Direcciones({ etiqueta, valor, onChange, disabled, pista }) {
  const [txt, setTxt] = useState('')
  const [malo, setMalo] = useState(false)

  const meter = () => {
    const partes = txt.split(/[,;\s]+/).map((s) => s.trim()).filter(Boolean)
    if (!partes.length) { setMalo(false); return }

    const buenas = partes.filter(esCorreo)
    const malas  = partes.filter((p) => !esCorreo(p))
    if (buenas.length) onChange([...new Set([...valor, ...buenas.map((b) => b.toLowerCase())])])
    setTxt(malas.join(' '))
    setMalo(malas.length > 0)
  }

  return (
    <div className="cmp-cc">
      <span className="cmp-dest-k" title={pista}>{etiqueta}</span>
      <div className={`cmp-cc-box ${malo ? 'malo' : ''}`}>
        {valor.map((d) => (
          <span key={d} className="cmp-cc-chip">
            {d}
            <button type="button" onClick={() => onChange(valor.filter((x) => x !== d))} title="Quitar">✕</button>
          </span>
        ))}
        <input
          value={txt} disabled={disabled}
          placeholder={valor.length ? 'Añadir otra…' : 'correo@dominio.com'}
          onChange={(e) => { setTxt(e.target.value); setMalo(false) }}
          onKeyDown={(e) => {
            if (e.key === 'Enter' || e.key === ',' || e.key === ';') { e.preventDefault(); meter() }
            if (e.key === 'Backspace' && !txt && valor.length) onChange(valor.slice(0, -1))
          }}
          onBlur={meter}
        />
      </div>
      {malo && <span className="cmp-cc-err">Eso no es una dirección válida</span>}
    </div>
  )
}
