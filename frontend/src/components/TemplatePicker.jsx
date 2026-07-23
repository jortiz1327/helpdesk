import { useState, useEffect } from 'react'
import { api } from '../api.js'

const headerTextComp = (t) => (t.components || []).find((c) => c.type === 'HEADER' && c.format === 'TEXT')
const bodyTextOf = (t) => (t.components || []).find((c) => c.type === 'BODY')?.text || ''
const varCount = (text) => { const m = [...(text || '').matchAll(/\{\{(\d+)\}\}/g)].map((x) => +x[1]); return m.length ? Math.max(...m) : 0 }

export default function TemplatePicker({ onClose, onPick }) {
  const [tpls, setTpls] = useState(null)
  const [err, setErr] = useState('')
  const [sel, setSel] = useState(null) // { t, hN, bN }
  const [hVals, setHVals] = useState([])
  const [bVals, setBVals] = useState([])

  useEffect(() => {
    api.listTemplates().then((d) => {
      if (!d.ok) { setErr(d.error || 'Error al cargar'); setTpls([]); return }
      setTpls((d.templates || []).filter((t) => t.status === 'APPROVED'))
    })
  }, [])

  // Al elegir una plantilla: si no tiene variables, se envía directa;
  // si tiene, se pide rellenarlas para no fallar con #132000.
  const choose = (t) => {
    const hN = varCount(headerTextComp(t)?.text)
    const bN = varCount(bodyTextOf(t))
    if (hN === 0 && bN === 0) { onPick(t, []); return }
    setSel({ t, hN, bN }); setHVals(Array(hN).fill('')); setBVals(Array(bN).fill(''))
  }

  const sendFilled = () => {
    const comps = []
    if (sel.hN) comps.push({ type: 'header', parameters: hVals.map((v) => ({ type: 'text', text: v })) })
    if (sel.bN) comps.push({ type: 'body', parameters: bVals.map((v) => ({ type: 'text', text: v })) })
    onPick(sel.t, comps)
  }

  const allFilled = sel && hVals.every((v) => v.trim()) && bVals.every((v) => v.trim())
  const preview = sel ? bodyTextOf(sel.t).replace(/\{\{(\d+)\}\}/g, (_, n) => bVals[+n - 1] || `{{${n}}}`) : ''

  return (
    <div className="modal-bg" onClick={(e) => e.target.classList.contains('modal-bg') && onClose()}>
      <div className="modal">
        <div className="modal-head">
          <h3>{sel ? `Plantilla · ${sel.t.name}` : 'Enviar plantilla'}</h3>
          <button className="x" onClick={onClose}>×</button>
        </div>

        {!sel ? (
          <div className="modal-body">
            {tpls === null && <div className="center-load"><div className="spinner" /></div>}
            {err && <p style={{ color: 'var(--danger)' }}>{err}</p>}
            {tpls?.length === 0 && !err && (
              <div className="empty"><p>No tienes plantillas aprobadas. Crea una en la sección <b>Plantillas</b>.</p></div>
            )}
            {tpls?.map((t) => {
              const vars = varCount(headerTextComp(t)?.text) + varCount(bodyTextOf(t))
              return (
                <div className="pick" key={t.id} onClick={() => choose(t)}>
                  <div className="top">
                    <b>{t.name}</b>
                    <span className="pill gray">{t.language}</span>
                    {vars > 0 && <span className="pill ok sm">{vars} variable{vars > 1 ? 's' : ''}</span>}
                  </div>
                  <div className="body">{bodyTextOf(t)}</div>
                </div>
              )
            })}
          </div>
        ) : (
          <div className="modal-body">
            <p className="hint" style={{ marginBottom: 12 }}>Rellena las variables antes de enviar.</p>
            {Array.from({ length: sel.hN }).map((_, i) => (
              <input key={'h' + i} className="cmp-var" placeholder={`Cabecera · variable {{${i + 1}}}`} value={hVals[i] || ''} onChange={(e) => setHVals((v) => v.map((x, j) => (j === i ? e.target.value : x)))} />
            ))}
            {Array.from({ length: sel.bN }).map((_, i) => (
              <input key={'b' + i} className="cmp-var" placeholder={`Mensaje · variable {{${i + 1}}}`} value={bVals[i] || ''} onChange={(e) => setBVals((v) => v.map((x, j) => (j === i ? e.target.value : x)))} />
            ))}
            <div className="var-preview" style={{ marginTop: 14 }}>
              <span className="vp-lbl">Vista previa</span>
              <div className="pbubble" style={{ maxWidth: '100%' }}>{preview}</div>
            </div>
            <div className="add-row" style={{ marginTop: 16, justifyContent: 'flex-end' }}>
              <button className="btn ghost" onClick={() => setSel(null)}>Atrás</button>
              <button className="btn" disabled={!allFilled} onClick={sendFilled}>Enviar plantilla</button>
            </div>
          </div>
        )}
      </div>
    </div>
  )
}
