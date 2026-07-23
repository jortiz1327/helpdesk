import { useState, useEffect, useCallback } from 'react'
import { api } from '../api.js'
import { Icon } from '../icons.jsx'
import { useToast, useConfirm } from '../App.jsx'
import LockTip from './LockTip.jsx'

// ---- Tipos de campo ----
const TYPES = {
  text:      { label: 'Texto', group: 'input', icon: Icon.textT, base: 'TextInput', sub: 'text' },
  email:     { label: 'Email', group: 'input', icon: Icon.mail, base: 'TextInput', sub: 'email' },
  phone:     { label: 'Teléfono', group: 'input', icon: Icon.phone, base: 'TextInput', sub: 'phone' },
  number:    { label: 'Número', group: 'input', icon: Icon.hash, base: 'TextInput', sub: 'number' },
  password:  { label: 'Contraseña', group: 'input', icon: Icon.lock, base: 'TextInput', sub: 'password' },
  textarea:  { label: 'Área de texto', group: 'rich', icon: Icon.alignLeft, base: 'TextArea' },
  paragraph: { label: 'Párrafo', group: 'rich', icon: Icon.note, base: 'Paragraph', content: true },
  caption:   { label: 'Leyenda', group: 'rich', icon: Icon.textT, base: 'Caption', content: true },
  dropdown:  { label: 'Desplegable', group: 'select', icon: Icon.list, base: 'Dropdown', options: true },
  radio:     { label: 'Opción única', group: 'select', icon: Icon.radio, base: 'RadioButtons', options: true },
  checkbox:  { label: 'Casillas', group: 'select', icon: Icon.checkSquare, base: 'CheckboxGroup', options: true },
  date:      { label: 'Fecha', group: 'date', icon: Icon.calendar, base: 'DatePicker' },
}
const GROUPS = [
  ['input', 'Campos de entrada'],
  ['rich', 'Multilínea y texto'],
  ['select', 'Selección'],
  ['date', 'Fecha y hora'],
]
const slug = (s) => s.toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '').replace(/[^a-z0-9]+/g, '_').replace(/^_|_$/g, '')

function newField(type) {
  const t = TYPES[type]
  const n = Math.floor(Math.random() * 90 + 10)
  return {
    id: 'f' + Date.now() + n, type,
    label: t.label, key: `${slug(t.label)}_${n}`,
    required: !t.content, placeholder: '',
    options: t.options ? ['Opción 1', 'Opción 2'] : undefined,
  }
}

// ---- Preview de un campo ----
function PreviewField({ f }) {
  const t = TYPES[f.type]
  if (f.type === 'paragraph') return <p className="pf-para">{f.label}</p>
  if (f.type === 'caption') return <p className="pf-cap">{f.label}</p>
  return (
    <div className="pf">
      <label>{f.label} {f.required && <span className="pf-req">*</span>}</label>
      {['text', 'email', 'phone', 'number', 'password'].includes(f.type) && <input disabled placeholder={f.placeholder || 'Escribe…'} />}
      {f.type === 'textarea' && <textarea disabled placeholder={f.placeholder || 'Escribe…'} rows={2} />}
      {f.type === 'date' && <div className="pf-date"><Icon.calendar /> Selecciona fecha</div>}
      {f.type === 'dropdown' && <select disabled><option>Elige…</option></select>}
      {f.type === 'radio' && (f.options || []).map((o, i) => <div className="pf-opt" key={i}><span className="pf-radio" />{o}</div>)}
      {f.type === 'checkbox' && (f.options || []).map((o, i) => <div className="pf-opt" key={i}><span className="pf-check" />{o}</div>)}
    </div>
  )
}

// ---- Constructor ----
function Builder({ form, onClose, onSaved }) {
  const toast = useToast()
  const [name, setName] = useState(form?.name || '')
  const [desc, setDesc] = useState(form?.description || '')
  const [fields, setFields] = useState(form?.fields || [])
  const [open, setOpen] = useState(null)
  const [saving, setSaving] = useState(false)
  const idRef = form?.id || 0

  const [dragIdx, setDragIdx] = useState(null)
  const [overIdx, setOverIdx] = useState(null)

  const add = (type) => { const f = newField(type); setFields((fs) => [...fs, f]); setOpen(f.id) }
  const upd = (id, patch) => setFields((fs) => fs.map((f) => (f.id === id ? { ...f, ...patch } : f)))
  const del = (id) => setFields((fs) => fs.filter((f) => f.id !== id))
  // Reordenar campos arrastrando (solo estado local; se guarda al publicar/guardar)
  const move = (from, to) => {
    if (from == null || Number.isNaN(from) || to == null || from === to) return
    setFields((fs) => { const next = [...fs]; const [it] = next.splice(from, 1); next.splice(to, 0, it); return next })
  }

  // Devuelve { id, msg } del primer campo inválido, o null si todo está bien
  const validate = () => {
    const seen = {}
    for (let i = 0; i < fields.length; i++) {
      const f = fields[i]
      const t = TYPES[f.type]
      const n = i + 1
      if (t.content) {
        if (!String(f.label || '').trim()) return { id: f.id, msg: `El bloque #${n} (${t.label}) no puede estar vacío` }
        continue
      }
      if (!String(f.label || '').trim()) return { id: f.id, msg: `El campo #${n} necesita una etiqueta visible` }
      const key = String(f.key || '').trim()
      if (!key) return { id: f.id, msg: `El campo «${f.label}» necesita una clave` }
      if (seen[key]) return { id: f.id, msg: `La clave «${key}» está repetida en otro campo` }
      seen[key] = true
      if (t.options) {
        const opts = (f.options || []).map((o) => String(o).trim()).filter(Boolean)
        if (opts.length < 2) return { id: f.id, msg: `«${f.label}» necesita al menos 2 opciones con texto` }
      }
    }
    return null
  }

  const save = async (status) => {
    if (!name.trim()) { toast('Ponle un nombre al formulario', 'err'); return }
    if (!fields.length) { toast('Añade al menos un campo', 'err'); return }
    const bad = validate()
    if (bad) { setOpen(bad.id); toast(bad.msg, 'err'); return }
    // Limpia opciones vacías antes de enviar
    const clean = fields.map((f) => (TYPES[f.type].options ? { ...f, options: (f.options || []).map((o) => String(o).trim()).filter(Boolean) } : f))
    setSaving(true)
    const res = await api.saveForm({ id: idRef || undefined, name, description: desc, fields: clean, status })
    setSaving(false)
    if (res.ok) { toast(status === 'published' ? 'Formulario publicado' : 'Formulario guardado'); onSaved() }
    else toast(res.error || 'Error al guardar', 'err')
  }

  return (
    <div className="form-builder">
      <header className="fb-head">
        <span className="fb-ico"><Icon.forms /></span>
        <h1>{idRef ? 'Editar formulario' : 'Nuevo formulario de WhatsApp'}</h1>
        <div className="spacer" style={{ flex: 1 }} />
        <button className="icon-btn" onClick={onClose} title="Cerrar">✕</button>
      </header>

      <div className="fb-body">
        {/* Paleta */}
        <aside className="fb-palette">
          <div className="fb-pal-t">Componentes</div>
          <p className="muted" style={{ fontSize: 12, marginBottom: 12 }}>Pulsa para añadir un campo</p>
          {GROUPS.map(([g, gl]) => (
            <div key={g} className="fb-pal-group">
              <div className="fb-pal-glabel">{gl}</div>
              {Object.entries(TYPES).filter(([, t]) => t.group === g).map(([k, t]) => (
                <button key={k} className="fb-pal-item" onClick={() => add(k)}>
                  <span className="fb-pal-i"><t.icon /></span>{t.label}
                </button>
              ))}
            </div>
          ))}
        </aside>

        {/* Centro */}
        <div className="fb-center">
          <div className="fb-meta">
            <input className="fb-name" value={name} onChange={(e) => setName(e.target.value)} placeholder="Nombre del formulario (título que abre el formulario)" />
            <input className="fb-desc" value={desc} onChange={(e) => setDesc(e.target.value)} placeholder="Mensaje de invitación — ej. ¡Hola! Pulsa abajo para rellenar nuestro formulario." />
          </div>

          <div className="fb-canvas-head">
            <span>Lienzo del formulario</span>
            <span className="pill ok">{fields.length} {fields.length === 1 ? 'campo' : 'campos'}</span>
          </div>

          {fields.length === 0 && (
            <div className="empty" style={{ padding: '40px 20px' }}><div className="ico"><Icon.forms /></div><p>Pulsa un componente de la izquierda para empezar a construir tu formulario.</p></div>
          )}

          {fields.map((f, i) => {
            const t = TYPES[f.type]
            return (
              <div key={f.id}
                className={`fb-field ${open === f.id ? 'open' : ''} ${dragIdx === i ? 'fb-dragging' : ''} ${overIdx === i && dragIdx !== i ? 'fb-over' : ''}`}
                onDragOver={(e) => { e.preventDefault(); setOverIdx(i) }}
                onDragLeave={() => setOverIdx((o) => (o === i ? null : o))}
                onDrop={(e) => { e.preventDefault(); const from = parseInt(e.dataTransfer.getData('text/plain'), 10); move(Number.isNaN(from) ? dragIdx : from, i); setDragIdx(null); setOverIdx(null) }}
              >
                <div className="fb-field-head"
                  draggable
                  onDragStart={(e) => { e.dataTransfer.setData('text/plain', String(i)); e.dataTransfer.effectAllowed = 'move'; setDragIdx(i) }}
                  onDragEnd={() => { setDragIdx(null); setOverIdx(null) }}
                  onClick={() => setOpen(open === f.id ? null : f.id)}>
                  <span className="fb-grip" title="Arrastra para reordenar">⋮⋮</span>
                  <span className="fb-num">{String(i + 1).padStart(2, '0')}</span>
                  <b>{f.label}</b>
                  {f.required && <span className="pill ok sm">req</span>}
                  <span className="fb-tags"><span className="fb-tag">{t.base}</span>{t.sub && <span className="fb-tag b">{t.sub}</span>}<span className="fb-key">{f.key}</span></span>
                  <button className="icon-btn" style={{ color: 'var(--danger)', marginLeft: 'auto' }} onClick={(e) => { e.stopPropagation(); del(f.id) }}><Icon.trash /></button>
                </div>
                {open === f.id && (
                  <div className="fb-settings">
                    <div className="fb-set-t">⚙ Ajustes del campo</div>
                    <div className="grid2">
                      <label className="field"><span className="lbl">Etiqueta visible</span><input value={f.label} onChange={(e) => upd(f.id, { label: e.target.value })} /></label>
                      <label className="field"><span className="lbl">Clave del campo <span className="hint">(snake_case)</span></span><input value={f.key} onChange={(e) => upd(f.id, { key: slug(e.target.value) })} /></label>
                    </div>
                    {t.options && (
                      <div className="field">
                        <span className="lbl">Opciones</span>
                        {(f.options || []).map((o, oi) => (
                          <div className="add-row" key={oi} style={{ marginBottom: 6 }}>
                            <input value={o} onChange={(e) => upd(f.id, { options: f.options.map((x, j) => (j === oi ? e.target.value : x)) })} />
                            <button className="icon-btn" style={{ color: 'var(--danger)' }} onClick={() => upd(f.id, { options: f.options.filter((_, j) => j !== oi) })}><Icon.trash /></button>
                          </div>
                        ))}
                        <button className="btn ghost sm" onClick={() => upd(f.id, { options: [...(f.options || []), 'Opción ' + ((f.options?.length || 0) + 1)] })}><Icon.plus /> Añadir opción</button>
                      </div>
                    )}
                    {!t.content && (
                      <label className="fb-req-row">
                        <span className="fb-switch"><input type="checkbox" checked={f.required} onChange={(e) => upd(f.id, { required: e.target.checked })} /><span className={`fb-toggle ${f.required ? 'on' : ''}`} /></span>
                        <span className="fb-req-label">Obligatorio</span>
                      </label>
                    )}
                  </div>
                )}
              </div>
            )
          })}

          <div className="fb-actions">
            <button className="btn ghost" disabled={saving} onClick={() => save('draft')}>Guardar borrador</button>
            <button className="btn" disabled={saving} onClick={() => save('published')}><Icon.send /> {saving ? 'Guardando…' : 'Publicar formulario'}</button>
          </div>
        </div>

        {/* Preview */}
        <aside className="fb-preview">
          <div className="fb-prev-t">Vista previa</div>
          <div className="phone" style={{ height: 'auto', minHeight: 420 }}>
            <div className="phone-top"><span>9:41</span><span>●●●</span></div>
            <div className="phone-head"><span className="pb">W</span><div><div className="pn">WhatsApp Business</div><div className="pst">en línea</div></div></div>
            <div className="phone-body" style={{ padding: 14 }}>
              <div className="pbubble" style={{ maxWidth: '100%' }}>{desc || '¡Hola! Pulsa abajo para rellenar nuestro formulario.'}<div className="pf-flow">Ver formulario</div></div>
              <div className="pf-form">
                <div className="pf-form-t">{name || 'Formulario'}</div>
                {fields.length === 0 && <p className="muted" style={{ fontSize: 12 }}>Añade campos para verlos aquí.</p>}
                {fields.map((f) => <PreviewField key={f.id} f={f} />)}
                {fields.length > 0 && <button className="pf-submit">Enviar</button>}
              </div>
            </div>
          </div>
        </aside>
      </div>
    </div>
  )
}

// ---- Lista de formularios ----
export default function Forms() {
  const toast = useToast()
  const confirm = useConfirm()
  const [forms, setForms] = useState(null)
  const [stats, setStats] = useState({ total: 0, published: 0, drafts: 0, submissions: 0 })
  const [subs, setSubs] = useState([])
  const [tab, setTab] = useState('forms')
  const [editing, setEditing] = useState(undefined) // undefined=list, null=new, obj=edit
  const [syncing, setSyncing] = useState(false)

  const [gate, setGate] = useState(null)
  const load = useCallback(() => {
    api.listForms().then((d) => setForms(d.forms || []))
    api.formsStats().then((d) => d.ok && setStats(d))
    api.formSubmissions().then((d) => setSubs(d.submissions || []))
    api.gating().then((d) => setGate(d.ok ? d : null))
  }, [])
  useEffect(() => { load() }, [load])

  const open = async (id) => { const d = await api.getForm(id); if (d.ok) setEditing(d.form) }
  const del = async (id) => { if (!(await confirm({ title: 'Eliminar formulario', message: '¿Eliminar este formulario y sus envíos?', danger: true, confirmText: 'Eliminar' }))) return; const r = await api.deleteForm(id); if (r.ok) { toast('Formulario eliminado'); load() } }
  const sync = async () => { setSyncing(true); const r = await api.syncForms(); setSyncing(false); if (r.ok) { toast(`${r.imported} importados de ${r.found} en Meta`); load() } else toast(r.error || 'No se pudo sincronizar', 'err') }
  const [publishing, setPublishing] = useState(0)
  const publish = async (id) => { setPublishing(id); const r = await api.publishFormToMeta(id); setPublishing(0); if (r.ok) { toast('Formulario publicado como Flow en WhatsApp ✅'); load() } else toast(r.error || 'No se pudo publicar', 'err') }

  if (editing !== undefined) {
    return <Builder form={editing} onClose={() => setEditing(undefined)} onSaved={() => { setEditing(undefined); load() }} />
  }

  const CARDS = [
    { k: 'Total formularios', v: stats.total, c: '#00a884' },
    { k: 'Publicados', v: stats.published, c: '#25d366' },
    { k: 'Borradores', v: stats.drafts, c: '#f4b740' },
    { k: 'Envíos', v: stats.submissions, c: '#4a9bff' },
  ]

  return (
    <>
      <header className="page-head">
        <span className="ic" style={{ width: 30, height: 30, borderRadius: 9, background: 'var(--primary-soft)', display: 'grid', placeItems: 'center' }}><Icon.forms style={{ width: 18, height: 18, fill: 'var(--primary)' }} /></span>
        <div><h1>Formularios de WhatsApp</h1></div>
        <span className="sub">· Capta leads con formularios</span>
        <div className="spacer" />
        <button className="btn ghost" disabled={syncing} onClick={sync}><Icon.refresh /> {syncing ? 'Sincronizando…' : 'Sincronizar de Meta'}</button>
        <button className="btn" onClick={() => setEditing(null)}><Icon.plus /> Nuevo formulario</button>
      </header>
      <div className="page-scroll">
        <div className="page" style={{ maxWidth: 1120 }}>
          <div className="stat-grid">
            {CARDS.map((c) => (
              <div className="stat-card" key={c.k}>
                <div className="stat-num" style={{ color: c.c, marginTop: 0 }}>{c.v}</div>
                <div className="stat-sub">{c.k}</div>
              </div>
            ))}
          </div>

          <div className="tabs" style={{ padding: '0 0 16px' }}>
            <button className={`tab ${tab === 'forms' ? 'active' : ''}`} onClick={() => setTab('forms')}>Mis formularios ({forms?.length || 0})</button>
            <button className={`tab ${tab === 'subs' ? 'active' : ''}`} onClick={() => setTab('subs')}>Envíos ({subs.length})</button>
          </div>

          {tab === 'forms' && (
            forms === null ? <div className="center-load"><div className="spinner" /></div> :
            forms.length === 0 ? (
              <div className="empty"><div className="ico"><Icon.forms /></div><p><b>Aún no hay formularios</b><br />Crea tu primer formulario de WhatsApp para empezar a captar leads.</p><button className="btn" onClick={() => setEditing(null)}><Icon.plus /> Nuevo formulario</button></div>
            ) : (
              <div className="flow-grid">
                {forms.map((f) => (
                  <div className="flow-card" key={f.id} onClick={() => open(f.id)}>
                    <div className="fc-top"><span className="fc-ic"><Icon.forms /></span>{f.meta_flow_id ? <span className="pill ok"><span className="dot" />Flow en WhatsApp</span> : <span className={`pill ${f.status === 'published' ? 'ok' : 'gray'}`}><span className="dot" />{f.status === 'published' ? 'Publicado' : 'Borrador'}</span>}</div>
                    <div className="fc-name">{f.name}</div>
                    <div className="muted" style={{ fontSize: 12.5 }}>{f.fields_count || 0} campos · {f.submissions || 0} envíos</div>
                    <div className="fc-foot" style={{ gap: 8 }}>
                      {gate?.features?.flow_publish ? (
                        <span className="gated-wrap" onClick={(e) => e.stopPropagation()}>
                          <button className="btn ghost sm gated" disabled><Icon.lock /> Publicar en WhatsApp</button>
                          <LockTip info={gate.features.flow_publish} />
                        </span>
                      ) : (
                        <button className="btn ghost sm" disabled={publishing === f.id} onClick={(e) => { e.stopPropagation(); publish(f.id) }} title="Publicar como formulario nativo de WhatsApp">
                          <Icon.send /> {publishing === f.id ? 'Publicando…' : (f.meta_flow_id ? 'Re-publicar' : 'Publicar en WhatsApp')}
                        </button>
                      )}
                      <span className="spacer" style={{ flex: 1 }} />
                      <button className="btn ghost sm" style={{ color: 'var(--danger)' }} onClick={(e) => { e.stopPropagation(); del(f.id) }}><Icon.trash /></button>
                    </div>
                  </div>
                ))}
              </div>
            )
          )}

          {tab === 'subs' && (
            subs.length === 0 ? (
              <div className="empty"><div className="ico"><Icon.forms /></div><p>Todavía no hay envíos. Aparecerán aquí cuando alguien rellene un formulario.</p></div>
            ) : (
              <div className="card" style={{ padding: 0 }}>
                {subs.map((s) => (
                  <div key={s.id} className="sub-row">
                    <div><b>{s.contact_name || '+' + (s.wa_id || '?')}</b><span className="muted" style={{ display: 'block', fontSize: 12 }}>{s.form_name} · {new Date(s.created_at.replace(' ', 'T')).toLocaleString('es-ES')}</span></div>
                    <div className="sub-data">{Object.entries(s.data || {}).map(([k, v]) => <span key={k} className="pill gray sm">{k}: {String(v)}</span>)}</div>
                  </div>
                ))}
              </div>
            )
          )}
        </div>
      </div>
    </>
  )
}
