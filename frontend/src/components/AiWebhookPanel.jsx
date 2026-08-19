import { useState, useEffect, useCallback } from 'react'
import { api } from '../api.js'
import { Icon } from '../icons.jsx'
import { useToast } from '../App.jsx'
import InfoTip from './InfoTip.jsx'

/* -------------------------------------------------------------------------
 * Panel del WEBHOOK RECEPTOR del agente externo. El trigger de un Workspace
 * Agent es asíncrono: la respuesta llega DESPUÉS a este webhook. Aquí se ve la
 * URL (con su secreto) que hay que poner como «salida» del agente, y los últimos
 * resultados que han ido llegando. Solo lectura / inspección.
 * ---------------------------------------------------------------------- */

export default function AiWebhookPanel() {
  const toast = useToast()
  const [d, setD] = useState(null)
  const [loading, setLoading] = useState(false)

  const load = useCallback(() => {
    setLoading(true)
    api.aiWebhook().then((r) => { setD(r || {}); setLoading(false) })
  }, [])
  useEffect(() => { load() }, [load])

  const copiar = (txt) => {
    if (!txt) return
    navigator.clipboard?.writeText(txt).then(() => toast('Copiado al portapapeles')).catch(() => {})
  }

  if (!d) return <div className="card" style={{ marginTop: 14 }}><div className="center-load"><div className="spinner" /></div></div>

  return (
    <div className="card" style={{ marginTop: 14 }}>
      <div className="wa-num-head">
        <div>
          <h2>Webhook del agente externo <span className="hint" style={{ fontWeight: 400 }}>· recibe las respuestas</span></h2>
          <p className="desc" style={{ margin: '2px 0 0' }}>
            Cuando un agente externo (p. ej. un Workspace Agent) termina, manda su resultado <b>aquí</b>.
            Pon esta URL como su <b>«salida»/destino</b>. Abajo ves lo que va llegando.
          </p>
        </div>
        <button className="btn ghost sm" onClick={load} disabled={loading}><Icon.refresh /> {loading ? 'Actualizando…' : 'Actualizar'}</button>
      </div>

      <label className="field">
        <span className="lbl">URL del webhook <InfoTip text="Ponla como salida/destino del agente externo. Lleva el secreto en ?key= — trátala como confidencial." wide /></span>
        <div style={{ display: 'flex', gap: 8 }}>
          <input className="mono" readOnly value={d.webhook_url || ''} style={{ flex: 1 }} onFocus={(e) => e.target.select()} />
          <button className="btn ghost sm" onClick={() => copiar(d.webhook_url)}><Icon.copy /> Copiar</button>
        </div>
      </label>

      <div className="field" style={{ marginBottom: 0 }}>
        <span className="lbl">Resultados recibidos <span className="hint">· últimos {d.recent?.length || 0}</span></span>
        {(!d.recent || d.recent.length === 0)
          ? <p className="desc" style={{ margin: 0 }}>Aún no ha llegado ningún resultado. Cuando el agente mande algo a esta URL, aparecerá aquí.</p>
          : (
            <div className="kb-list">
              {d.recent.map((r) => (
                <div key={r.id} className="kb-doc">
                  <div className="kb-body">
                    <b>{r.ref || '(sin ref)'} <span className="muted" style={{ fontWeight: 400, fontSize: 12 }}>· {r.source} · {r.created}</span></b>
                    <span className="kb-sub" style={{ whiteSpace: 'normal' }}>{r.answer || '(sin texto extraído)'}</span>
                  </div>
                </div>
              ))}
            </div>
          )}
      </div>
    </div>
  )
}
