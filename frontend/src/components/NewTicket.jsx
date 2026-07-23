import { useState, useEffect, useRef } from 'react'
import { api } from '../api.js'
import { Icon } from '../icons.jsx'
import { useToast } from '../App.jsx'
import Select from './Select.jsx'
import RichInput from './RichInput.jsx'

/* ---------------------------------------------------------------------------
 * NUEVO TICKET — alta interna. La crea un agente (no el cliente).
 * Puede crearla CUALQUIER usuario con el permiso `tickets.create`, que ahora
 * tienen todos los roles de soporte. El campo «Asignar a» solo se muestra a
 * quien además tiene `tickets.assign`.
 * ------------------------------------------------------------------------- */

const blank = {
  name: '', email: '', phone: '',
  subject: '', category_id: '', priority: 'media',
  assigned_to: '',
}

export default function NewTicket({ user, onCreated, onCancel }) {
  const toast = useToast()
  const [meta, setMeta] = useState(null)
  const [f, setF] = useState(blank)
  const [saving, setSaving] = useState(false)
  const desc = useRef(null)   // el editor: HTML + adjuntos

  useEffect(() => { api.ticketMeta().then(setMeta) }, [])

  const can = (p) => (user?.permissions || []).includes(p)
  const set = (k) => (v) => setF((s) => ({ ...s, [k]: v?.target ? v.target.value : v }))

  const submit = async () => {
    if (!f.name.trim())    return toast('El nombre del solicitante es obligatorio', 'err')
    if (!f.email.trim() && !f.phone.trim()) return toast('Indica al menos un email o un teléfono', 'err')
    if (!f.subject.trim()) return toast('El asunto es obligatorio', 'err')
    if (desc.current.isEmpty()) return toast('Describe el problema o adjunta un archivo', 'err')

    setSaving(true)
    const r = await api.createTicket({
      ...f,
      description: desc.current.getHtml(),
      files: desc.current.getFiles(),
    })
    setSaving(false)

    if (r.ok) {
      toast(`Ticket ${r.code} creado`)
      // Si algún adjunto se rechazó (tipo o tamaño), se avisa: no se traga en silencio.
      ;(r.warnings || []).forEach((w) => toast(w, 'err'))
      setF(blank)
      desc.current.reset()
      onCreated?.(r.id)
    } else {
      toast(r.error || 'No se pudo crear el ticket', 'err')
    }
  }

  return (
    <>
      <header className="page-head">
        <span className="sc-ic"><Icon.ticket style={{ width: 18, height: 18, fill: 'var(--primary)' }} /></span>
        <div><h1>Nuevo ticket</h1></div>
        <span className="sub">· Alta interna de una solicitud de soporte</span>
        <div className="spacer" />
      </header>

      <div className="page-scroll">
        <div className="page" style={{ maxWidth: 880 }}>

          {/* --- Solicitante --- */}
          <div className="card nt-card">
            <div className="nt-sec"><Icon.user /> <b>Información del solicitante</b></div>
            <p className="nt-help">Si el email o el teléfono ya existen, el ticket se asocia a ese cliente en vez de duplicarlo.</p>

            <div className="grid3">
              <label className="field">
                <span className="lbl">Nombre <em>*</em></span>
                <input value={f.name} onChange={set('name')} placeholder="María García" autoFocus />
              </label>
              <label className="field">
                <span className="lbl">Email</span>
                <input type="email" value={f.email} onChange={set('email')} placeholder="maria@empresa.com" />
              </label>
              <label className="field">
                <span className="lbl">Teléfono</span>
                <input value={f.phone} onChange={set('phone')} placeholder="+34 600 000 000" />
              </label>
            </div>
          </div>

          {/* --- Problema --- */}
          <div className="card nt-card">
            <div className="nt-sec"><Icon.warn /> <b>Detalles del problema</b></div>

            <label className="field">
              <span className="lbl">Asunto <em>*</em></span>
              <input value={f.subject} onChange={set('subject')} placeholder="Resumen breve del problema" />
            </label>

            <div className={can('tickets.assign') ? 'grid3' : 'grid2'}>
              <div className="field">
                <span className="lbl">Categoría</span>
                <Select block value={String(f.category_id)} onChange={set('category_id')}
                  options={[{ value: '', label: 'Sin categoría' },
                    ...(meta?.categories || []).map((c) => ({
                      value: String(c.id), label: c.name,
                      sub: c.sla_hours ? `SLA ${c.sla_hours} h` : undefined,
                    }))]} />
              </div>
              <div className="field">
                <span className="lbl">Prioridad</span>
                <Select block value={f.priority} onChange={set('priority')}
                  options={Object.entries(meta?.priorities || {}).map(([value, label]) => ({ value, label }))} />
              </div>
              {/* Solo quien puede repartir trabajo ve este campo */}
              {can('tickets.assign') && (
                <div className="field">
                  <span className="lbl">Asignar a</span>
                  <Select block value={String(f.assigned_to)} onChange={set('assigned_to')}
                    options={[{ value: '', label: 'Sin asignar' },
                      ...(meta?.users || []).map((u) => ({ value: String(u.id), label: u.name }))]} />
                </div>
              )}
            </div>

            <div className="field">
              <span className="lbl">Descripción <em>*</em> <span className="hint">(con formato; puedes adjuntar capturas)</span></span>
              <RichInput ref={desc} minHeight={150}
                placeholder="Describe el problema con el máximo detalle: qué ocurre, desde cuándo, pasos para reproducirlo, mensajes de error…" />
            </div>
          </div>

          <div className="add-row" style={{ justifyContent: 'flex-end' }}>
            <button className="btn ghost" onClick={onCancel}>Cancelar</button>
            <button className="btn" onClick={submit} disabled={saving}>
              <Icon.ticket /> {saving ? 'Creando…' : 'Crear ticket'}
            </button>
          </div>
        </div>
      </div>
    </>
  )
}
