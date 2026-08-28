import { useRef, useState, useEffect } from 'react'
import { api } from '../api.js'
import { Icon } from '../icons.jsx'
import RichInput from './RichInput.jsx'

const esCorreo = (d) => /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(d.trim())

/**
 * COMPOSER — el editor de respuestas del ticket: RichInput + botón de enviar.
 *
 * En los tickets de correo lleva además los DESTINATARIOS: quien venía en copia en
 * el hilo sigue en la conversación, así que se propone solo y el agente decide.
 */
export default function Composer({ onSend, disabled = false, disabledHint, to, ccSugerido = [], ticketId = null, cannedVars = null, mentionUsers = null, canSchedule = false }) {
  const ed = useRef(null)
  const [empty, setEmpty] = useState(true)
  const [mode, setMode] = useState('reply') // 'reply' = al cliente · 'note' = interna
  const note = mode === 'note'
  // Programar envío (solo respuesta por correo): menú del botón partido.
  const [schedOpen, setSchedOpen] = useState(false)
  const [schedCustom, setSchedCustom] = useState(false)
  const [schedWhen, setSchedWhen] = useState('')
  const schedRef = useRef(null)
  useEffect(() => {
    if (!schedOpen) return
    const h = (e) => { if (schedRef.current && !schedRef.current.contains(e.target)) { setSchedOpen(false); setSchedCustom(false) } }
    document.addEventListener('mousedown', h)
    return () => document.removeEventListener('mousedown', h)
  }, [schedOpen])

  /* Memoria de respuestas efectivas: las que funcionaron en casos PARECIDOS a este
     ticket. Se cargan al abrir y el agente puede insertarlas con un clic. */
  const [efectivas, setEfectivas] = useState([])
  const [verEf, setVerEf] = useState(false)
  useEffect(() => {
    if (!ticketId) return
    api.suggestEffective(ticketId).then((r) => setEfectivas(r?.items || [])).catch(() => setEfectivas([]))
  }, [ticketId])
  const insertarEfectiva = (e) => {
    ed.current?.setHtml(e.body); setEmpty(false); setVerEf(false)
    api.usedEffective(e.id).catch(() => {})
  }
  const tituloEf = (e) => {
    const tmp = document.createElement('div'); tmp.innerHTML = e.body || ''
    return (tmp.textContent || '').replace(/\s+/g, ' ').trim().slice(0, 90) || '(respuesta)'
  }

  const [cc, setCc] = useState([])
  const [bcc, setBcc] = useState([])
  const [verCopias, setVerCopias] = useState(false)

  /* Las copias del hilo llegan con el ticket (asíncronas) y se proponen marcadas:
     si alguien estaba en la conversación, dejarlo fuera sin querer es el fallo caro. */
  useEffect(() => {
    setCc(ccSugerido)
    if (ccSugerido.length) setVerCopias(true)
  }, [ccSugerido.join(',')])

  const send = (schedule = '', sendAt = '') => {
    onSend?.({
      html: ed.current.getHtml(), files: ed.current.getFiles(), internal: note, cc, bcc,
      mentions: note ? (ed.current.getMentions?.() || []) : [],   // @menciones solo en nota interna
      schedule, send_at: sendAt,   // programar el envío (solo respuesta, no nota)
    })
    ed.current.reset()
    setEmpty(true)
    setBcc([])   // el Cco no se arrastra al siguiente mensaje; el Cc sí (sigue el hilo)
    setSchedOpen(false); setSchedCustom(false); setSchedWhen('')
    if (draftKey) localStorage.removeItem(draftKey)   // enviado: fuera el borrador
  }

  /* --- Autosave del borrador por ticket -------------------------------------
   * Al saltar de un ticket a otro, el Composer se remonta y se perdía lo escrito.
   * Se guarda un borrador por ticket (con su modo) y se restaura al volver; se borra
   * al enviar. En una cola donde se salta mucho, esto evita perder respuestas a medias. */
  const draftKey = ticketId ? `tk_draft_${ticketId}` : null
  const draftTimer = useRef(null)

  useEffect(() => {
    if (!draftKey) return
    try {
      const saved = JSON.parse(localStorage.getItem(draftKey) || 'null')
      if (saved?.html) {
        setMode(saved.note ? 'note' : 'reply')
        ed.current?.setHtml(saved.html)   // el hijo ya montó: el ref está listo
        setEmpty(false)
      }
    } catch { /* borrador corrupto: se ignora */ }
  }, [draftKey])   // eslint-disable-line react-hooks/exhaustive-deps

  const guardarBorrador = () => {
    setEmpty(ed.current.isEmpty())
    if (!draftKey) return
    clearTimeout(draftTimer.current)
    draftTimer.current = setTimeout(() => {
      if (ed.current?.isEmpty()) localStorage.removeItem(draftKey)
      else localStorage.setItem(draftKey, JSON.stringify({ html: ed.current.getHtml(), note }))
    }, 400)
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
        cannedVars={cannedVars}
        mentionUsers={note ? mentionUsers : null}
        minHeight={84}
        placeholder={disabled ? (disabledHint || 'No disponible')
          : note ? 'Escribe una nota interna… (solo la verán los agentes)'
          : 'Escribe tu respuesta… (o / para respuestas rápidas)'}
        onChange={guardarBorrador}
      />
      <div className="cmp-foot">
        {disabled && <span className="cmp-warn"><Icon.warn /> {disabledHint}</span>}
        {!disabled && note && <span className="cmp-note-tag"><Icon.lock /> No se envía al cliente</span>}
        {/* Respuestas efectivas parecidas: memoria de lo que funcionó en casos similares. */}
        {!disabled && !note && efectivas.length > 0 && (
          <div className="cmp-ef">
            <button type="button" className="cmp-similar" onClick={() => setVerEf((v) => !v)}
              title="Respuestas que funcionaron en casos parecidos">
              <Icon.bolt /> Parecidas ({efectivas.length})
            </button>
            {verEf && (
              <div className="cmp-ef-menu">
                <div className="cmp-ef-h">Respuestas que funcionaron en casos parecidos</div>
                {efectivas.map((e) => (
                  <button type="button" key={e.id} className="cmp-ef-item" onClick={() => insertarEfectiva(e)}>
                    <span className="cmp-ef-tx">{tituloEf(e)}</span>
                    {e.uses > 0 && <span className="cmp-ef-uses" title="veces reutilizada">{e.uses}×</span>}
                  </button>
                ))}
              </div>
            )}
          </div>
        )}
        <span className="spacer" />
        <span className="cmp-hint">Ctrl + Enter</span>
        {note || !canSchedule ? (
          <button className={`btn ${note ? 'note-btn' : ''}`} disabled={disabled || empty} onClick={() => send()}>
            {note ? <><Icon.note /> Guardar nota</> : <><Icon.send /> Enviar respuesta</>}
          </button>
        ) : (
          <span className="cmp-split" ref={schedRef}>
            <button className="btn cmp-split-go" disabled={disabled || empty} onClick={() => send()}>
              <Icon.send /> Enviar respuesta
            </button>
            <button className="btn cmp-split-caret" disabled={disabled || empty}
              onClick={() => setSchedOpen((v) => !v)} title="Programar el envío">▾</button>
            {schedOpen && (
              <div className="cmp-sched">
                <div className="cmp-sched-h"><b>¿Cuándo sale?</b><small>Se guarda y se envía sola.</small></div>
                <button type="button" className="cmp-sched-opt" onClick={() => send('business')}>
                  <span>🌅</span> Al abrir el horario</button>
                <button type="button" className="cmp-sched-opt" onClick={() => send('tomorrow')}>
                  <span>☀️</span> Mañana a primera hora</button>
                {schedCustom ? (
                  <div className="cmp-sched-custom">
                    <input type="datetime-local" value={schedWhen} onChange={(e) => setSchedWhen(e.target.value)} />
                    <button type="button" className="btn sm" disabled={!schedWhen}
                      onClick={() => schedWhen && send('custom', schedWhen)}>Programar</button>
                  </div>
                ) : (
                  <button type="button" className="cmp-sched-opt" onClick={() => setSchedCustom(true)}>
                    <span>🗓️</span> Fecha y hora…</button>
                )}
              </div>
            )}
          </span>
        )}
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
