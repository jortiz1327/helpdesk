import { useState } from 'react'
import { Icon } from '../icons.jsx'

/**
 * Constructor de mensajes interactivos de WhatsApp.
 * mode: 'button' (hasta 3 botones de respuesta) | 'list' (secciones + filas).
 * onSend(interactive) recibe el objeto "interactive" listo para la Graph API
 * y debe devolver una promesa (se cierra el modal cuando resuelve con ok).
 */
export default function InteractiveBuilder({ mode, onClose, onSend }) {
  const isList = mode === 'list'
  const [header, setHeader] = useState('')
  const [body, setBody] = useState('')
  const [footer, setFooter] = useState('')
  const [sending, setSending] = useState(false)
  const [err, setErr] = useState('')

  // Botones
  const [buttons, setButtons] = useState([{ title: '' }])
  // Listas
  const [btnLabel, setBtnLabel] = useState('Ver opciones')
  const [sections, setSections] = useState([{ title: '', rows: [{ title: '', description: '' }] }])

  const totalRows = sections.reduce((n, s) => n + s.rows.length, 0)

  const setBtn = (i, v) => setButtons((b) => b.map((x, k) => (k === i ? { title: v } : x)))
  const addBtn = () => buttons.length < 3 && setButtons((b) => [...b, { title: '' }])
  const delBtn = (i) => setButtons((b) => b.filter((_, k) => k !== i))

  const setSecTitle = (si, v) => setSections((s) => s.map((x, k) => (k === si ? { ...x, title: v } : x)))
  const setRow = (si, ri, field, v) =>
    setSections((s) => s.map((x, k) => k !== si ? x : { ...x, rows: x.rows.map((r, j) => (j === ri ? { ...r, [field]: v } : r)) }))
  const addRow = (si) => totalRows < 10 && setSections((s) => s.map((x, k) => (k === si ? { ...x, rows: [...x.rows, { title: '', description: '' }] } : x)))
  const delRow = (si, ri) => setSections((s) => s.map((x, k) => (k === si ? { ...x, rows: x.rows.filter((_, j) => j !== ri) } : x)))
  const addSection = () => setSections((s) => [...s, { title: '', rows: [{ title: '', description: '' }] }])
  const delSection = (si) => setSections((s) => s.filter((_, k) => k !== si))

  const build = () => {
    setErr('')
    const text = body.trim()
    if (!text) return setErr('El cuerpo del mensaje es obligatorio.')

    const interactive = { type: isList ? 'list' : 'button', body: { text } }
    if (header.trim()) interactive.header = { type: 'text', text: header.trim() }
    if (footer.trim()) interactive.footer = { text: footer.trim() }

    if (isList) {
      if (!btnLabel.trim()) return setErr('El texto del botón de la lista es obligatorio.')
      const secs = sections
        .map((s, si) => ({
          title: s.title.trim() || undefined,
          rows: s.rows
            .map((r, ri) => ({ id: `row_${si + 1}_${ri + 1}`, title: r.title.trim(), description: r.description.trim() || undefined }))
            .filter((r) => r.title),
        }))
        .filter((s) => s.rows.length)
      if (!secs.length) return setErr('Añade al menos una fila con título.')
      interactive.action = { button: btnLabel.trim().slice(0, 20), sections: secs }
    } else {
      const btns = buttons
        .map((b, i) => ({ type: 'reply', reply: { id: `btn_${i + 1}`, title: b.title.trim() } }))
        .filter((b) => b.reply.title)
      if (!btns.length) return setErr('Añade al menos un botón con texto.')
      interactive.action = { buttons: btns }
    }

    setSending(true)
    Promise.resolve(onSend(interactive)).finally(() => setSending(false))
  }

  return (
    <div className="modal-bg" onClick={(e) => e.target.classList.contains('modal-bg') && onClose()}>
      <div className="modal imodal">
        <div className="modal-head">
          <h3>{isList ? '📋 Mensaje de lista' : '🔘 Mensaje con botones'}</h3>
          <button className="x" onClick={onClose}>×</button>
        </div>

        <div className="modal-body ib">
          <label className="field">
            <span className="lbl">Encabezado <span className="hint">(opcional)</span></span>
            <input maxLength={60} value={header} onChange={(e) => setHeader(e.target.value)} placeholder="Texto de cabecera" />
          </label>
          <label className="field">
            <span className="lbl">Cuerpo del mensaje *</span>
            <textarea rows={3} maxLength={1024} value={body} onChange={(e) => setBody(e.target.value)} placeholder="Escribe el texto principal…" />
          </label>
          <label className="field">
            <span className="lbl">Pie <span className="hint">(opcional)</span></span>
            <input maxLength={60} value={footer} onChange={(e) => setFooter(e.target.value)} placeholder="Texto de pie" />
          </label>

          {!isList ? (
            <div className="ib-block">
              <div className="ib-block-head"><span>Botones · {buttons.length}/3</span></div>
              {buttons.map((b, i) => (
                <div className="ib-row" key={i}>
                  <input maxLength={20} value={b.title} onChange={(e) => setBtn(i, e.target.value)} placeholder={`Botón ${i + 1} (máx. 20)`} />
                  {buttons.length > 1 && <button className="ib-del" title="Quitar" onClick={() => delBtn(i)}><Icon.trash /></button>}
                </div>
              ))}
              {buttons.length < 3 && <button className="ib-add" onClick={addBtn}><Icon.plus /> Añadir botón</button>}
            </div>
          ) : (
            <div className="ib-block">
              <label className="field">
                <span className="lbl">Texto del botón de la lista *</span>
                <input maxLength={20} value={btnLabel} onChange={(e) => setBtnLabel(e.target.value)} placeholder="Ver opciones" />
              </label>
              <div className="ib-block-head"><span>Secciones · {totalRows}/10 filas</span></div>
              {sections.map((s, si) => (
                <div className="ib-section" key={si}>
                  <div className="ib-row">
                    <input className="ib-sec-title" value={s.title} onChange={(e) => setSecTitle(si, e.target.value)} placeholder={`Título de sección ${si + 1} (opcional)`} />
                    {sections.length > 1 && <button className="ib-del" title="Quitar sección" onClick={() => delSection(si)}><Icon.trash /></button>}
                  </div>
                  {s.rows.map((r, ri) => (
                    <div className="ib-listrow" key={ri}>
                      <div className="ib-listrow-fields">
                        <input maxLength={24} value={r.title} onChange={(e) => setRow(si, ri, 'title', e.target.value)} placeholder="Título de la opción (máx. 24)" />
                        <input maxLength={72} value={r.description} onChange={(e) => setRow(si, ri, 'description', e.target.value)} placeholder="Descripción (opcional)" />
                      </div>
                      {s.rows.length > 1 && <button className="ib-del" title="Quitar fila" onClick={() => delRow(si, ri)}><Icon.trash /></button>}
                    </div>
                  ))}
                  {totalRows < 10 && <button className="ib-add sm" onClick={() => addRow(si)}><Icon.plus /> Añadir fila</button>}
                </div>
              ))}
              <button className="ib-add" onClick={addSection}><Icon.plus /> Añadir sección</button>
            </div>
          )}

          {err && <div className="ib-err">{err}</div>}

          <div className="add-row" style={{ marginTop: 16, justifyContent: 'flex-end' }}>
            <button className="btn ghost" onClick={onClose}>Cancelar</button>
            <button className="btn" disabled={sending} onClick={build}>{sending ? 'Enviando…' : 'Enviar'}</button>
          </div>
        </div>
      </div>
    </div>
  )
}
