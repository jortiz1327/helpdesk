import { useState, useEffect } from 'react'
import { api } from '../api.js'
import { Icon } from '../icons.jsx'

// Texto por defecto del cuerpo cuando el formulario no tiene descripción
// (el backend usa este mismo fallback en FormsController::send).
const FALLBACK_BODY = '¡Hola! Pulsa abajo para rellenar nuestro formulario.'

/*
 * Selector para enviar un formulario de WhatsApp al chat de un contacto.
 * Mismo patrón que TemplatePicker (2 pasos): lista → confirmar y enviar.
 * Solo son enviables los formularios con Flow publicado en Meta (meta_flow_id);
 * el resto salen deshabilitados. El paso 2 muestra la vista previa y esconde
 * la personalización (mensaje / texto del botón) detrás de «Personalizar».
 */
export default function FormPicker({ onClose, onSend }) {
  const [forms, setForms] = useState(null)
  const [err, setErr] = useState('')
  const [sel, setSel] = useState(null)          // formulario elegido (paso 2)
  const [custom, setCustom] = useState(false)   // personalización desplegada
  const [body, setBody] = useState('')
  const [cta, setCta] = useState('Ver formulario')
  const [sending, setSending] = useState(false)

  useEffect(() => {
    api.listForms().then((d) => {
      if (!d.ok) { setErr(d.error || 'No se pudieron cargar los formularios'); setForms([]); return }
      setForms(d.forms || [])
    })
  }, [])

  const choose = (f) => {
    if (!f.meta_flow_id) return                 // solo los publicados en WhatsApp
    setSel(f)
    setBody((f.description || '').trim() || FALLBACK_BODY)
    setCta('Ver formulario')
    setCustom(false)
  }

  const enviar = async () => {
    if (sending) return
    setSending(true)
    await onSend({ id: sel.id, body: body.trim() || undefined, cta: cta.trim() || undefined })
    // onSend cierra el modal (éxito) o avisa por toast (error); no reabrimos aquí.
    setSending(false)
  }

  const enviables = (forms || []).filter((f) => f.meta_flow_id)

  return (
    <div className="modal-bg" onClick={(e) => e.target.classList.contains('modal-bg') && onClose()}>
      <div className="modal">
        <div className="modal-head">
          <h3 style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
            <Icon.forms style={{ width: 16, height: 16, fill: 'var(--primary)' }} />
            {sel ? `Formulario · ${sel.name}` : 'Enviar formulario'}
          </h3>
          <button className="icon-btn" onClick={onClose}>✕</button>
        </div>

        {!sel ? (
          <div className="modal-body">
            {forms === null && <div className="center-load"><div className="spinner" /></div>}
            {err && <p style={{ color: 'var(--danger)' }}>{err}</p>}
            {forms && !err && enviables.length === 0 && (
              <div className="empty"><p>No tienes formularios publicados en WhatsApp. Publica uno en la sección <b>Formularios</b> para poder enviarlo.</p></div>
            )}
            {forms?.map((f) => {
              const ok = !!f.meta_flow_id
              return (
                <div className={`pick${ok ? '' : ' fp-dis'}`} key={f.id} onClick={() => choose(f)}>
                  <div className="top">
                    <b>{f.name}</b>
                    {ok
                      ? <span className="pill ok sm"><span className="dot" />Publicado</span>
                      : <span className="pill gray sm"><span className="dot" />Sin publicar</span>}
                  </div>
                  {f.description && <div className="body">{f.description}</div>}
                  <div className="hint" style={{ marginTop: 4 }}>
                    {ok ? `${f.fields_count || 0} campo${f.fields_count === 1 ? '' : 's'}` : 'Publícalo en WhatsApp para poder enviarlo'}
                  </div>
                </div>
              )
            })}
          </div>
        ) : (
          <div className="modal-body">
            {/* Vista previa de lo que recibe el cliente */}
            <div className="var-preview">
              <span className="vp-lbl">Lo que recibe el cliente</span>
              <div className="pbubble" style={{ maxWidth: '100%' }}>
                <div>{body || FALLBACK_BODY}</div>
                <div className="fp-cta"><Icon.forms style={{ width: 14, height: 14, fill: 'currentColor' }} /> {cta || 'Ver formulario'}</div>
              </div>
            </div>

            {/* Personalización plegada */}
            {!custom ? (
              <button className="link-btn" style={{ marginTop: 12 }} onClick={() => setCustom(true)}>Personalizar mensaje…</button>
            ) : (
              <div style={{ marginTop: 12 }}>
                <label className="lbl">Mensaje de invitación</label>
                <textarea className="cmp-var" style={{ width: '100%', minHeight: 64 }} value={body} onChange={(e) => setBody(e.target.value)} />
                <label className="lbl" style={{ marginTop: 10 }}>Texto del botón <span className="hint">· máx. 30</span></label>
                <input className="cmp-var" maxLength={30} value={cta} onChange={(e) => setCta(e.target.value)} />
              </div>
            )}

            <div className="add-row" style={{ marginTop: 16, justifyContent: 'flex-end' }}>
              <button className="btn ghost" onClick={() => setSel(null)}>Atrás</button>
              <button className="btn" disabled={sending} onClick={enviar}><Icon.send /> {sending ? 'Enviando…' : 'Enviar formulario'}</button>
            </div>
          </div>
        )}
      </div>
    </div>
  )
}
