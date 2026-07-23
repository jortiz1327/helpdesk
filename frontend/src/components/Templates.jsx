import { useState, useEffect, useCallback } from 'react'
import { api } from '../api.js'
import { Icon } from '../icons.jsx'
import { useToast, useConfirm } from '../App.jsx'
import TemplateWizard from './TemplateWizard.jsx'
import LockTip from './LockTip.jsx'

const STATUS = {
  APPROVED: ['ok', 'Aprobada'], PENDING: ['warn', 'Pendiente'],
  REJECTED: ['err', 'Rechazada'], PAUSED: ['gray', 'Pausada'], DISABLED: ['gray', 'Desactivada'],
}

function comp(t, type) { return (t.components || []).find((c) => c.type === type) }

// Solo se pueden editar: estándar (sin carrusel/catálogo), cabecera no-media y en estado editable
function isEditable(t) {
  if (!['APPROVED', 'REJECTED', 'PAUSED'].includes(t.status)) return false
  const cs = t.components || []
  if (cs.some((c) => c.type === 'CAROUSEL')) return false
  const h = cs.find((c) => c.type === 'HEADER')
  if (h && ['IMAGE', 'VIDEO', 'DOCUMENT'].includes(h.format)) return false
  const b = cs.find((c) => c.type === 'BUTTONS')
  if (b && (b.buttons || []).some((x) => x.type === 'CATALOG')) return false
  return true
}

export default function Templates() {
  const toast = useToast()
  const confirm = useConfirm()
  const [tpls, setTpls] = useState(null)
  const [err, setErr] = useState('')
  const [creating, setCreating] = useState(false)
  const [editing, setEditing] = useState(null)

  const [gate, setGate] = useState(null)
  const load = useCallback(() => {
    setErr('')
    api.listTemplates().then((d) => {
      if (!d.ok) { setErr(d.error || 'Error'); setTpls([]); return }
      setTpls(d.templates || [])
    })
    api.gating().then((d) => setGate(d.ok ? d : null))
  }, [])
  useEffect(() => { load() }, [load])

  const del = async (name) => {
    if (!(await confirm({ title: 'Eliminar plantilla', message: `¿Eliminar la plantilla "${name}"? No se puede deshacer.`, danger: true, confirmText: 'Eliminar' }))) return
    const res = await api.deleteTemplate(name)
    if (res.ok) { toast('Plantilla eliminada'); load() }
    else toast(res.error || 'No se pudo eliminar', 'err')
  }

  if (creating) {
    return <TemplateWizard onClose={() => setCreating(false)} onCreated={() => { setCreating(false); load() }} />
  }
  if (editing) {
    return <TemplateWizard editing={editing} onClose={() => setEditing(null)} onCreated={() => { setEditing(null); load() }} />
  }

  return (
    <>
      <header className="page-head">
        <h1>Plantillas</h1>
        <span className="sub">· Mensajes preaprobados por Meta</span>
        <div className="spacer" />
        <button className="btn" onClick={() => setCreating(true)}><Icon.plus /> Nueva plantilla</button>
      </header>
      <div className="page-scroll">
        <div className="page">
          {tpls === null && <div className="center-load"><div className="spinner" /></div>}
          {err && (
            <div className="card"><b style={{ color: 'var(--danger)' }}>Error:</b> {err}
              <div className="hint" style={{ marginTop: 6 }}>Revisa el token y el WABA ID en Configuración.</div>
            </div>
          )}
          {tpls?.length === 0 && !err && (
            <div className="empty">
              <div className="ico"><Icon.templates /></div>
              <p>No tienes plantillas todavía.<br />Crea la primera con «Nueva plantilla».</p>
            </div>
          )}
          {tpls?.length > 0 && (
            <div className="tpl-grid">
              {tpls.map((t) => {
                const h = comp(t, 'HEADER'), b = comp(t, 'BODY'), foot = comp(t, 'FOOTER'), btns = comp(t, 'BUTTONS')
                const [cls, txt] = STATUS[t.status] || ['gray', t.status]
                return (
                  <div className="tpl-card" key={t.id}>
                    <div className="top">
                      <span className="tname">{t.name}</span>
                      <span className={`pill ${cls}`}><span className="dot" />{txt}</span>
                    </div>
                    <div className="preview">
                      <div className="tpl-bubble">
                        {h?.text && <div className="h">{h.text}</div>}
                        <div>{b?.text || ''}</div>
                        {foot?.text && <div className="f">{foot.text}</div>}
                        {btns && (
                          <div className="btns">
                            {(btns.buttons || []).map((x, i) => <div className="b" key={i}>{x.text}</div>)}
                          </div>
                        )}
                      </div>
                    </div>
                    <div className="foot">
                      <span><span className="pill gray">{t.language}</span> &nbsp;{t.category || ''}</span>
                      <span style={{ display: 'flex', gap: 8 }}>
                        {isEditable(t) && <button className="btn ghost sm" onClick={() => setEditing(t)}><Icon.pencil /> Editar</button>}
                        {gate?.features?.template_delete ? (
                          <span className="gated-wrap">
                            <button className="btn ghost sm gated" disabled><Icon.lock /> Eliminar</button>
                            <LockTip info={gate.features.template_delete} />
                          </span>
                        ) : (
                          <button className="btn ghost sm" style={{ color: 'var(--danger)', borderColor: '#f3d2cd' }} onClick={() => del(t.name)}>Eliminar</button>
                        )}
                      </span>
                    </div>
                  </div>
                )
              })}
            </div>
          )}
        </div>
      </div>
    </>
  )
}
