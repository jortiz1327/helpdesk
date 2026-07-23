import { useState, useEffect, useCallback } from 'react'
import { api } from '../api.js'
import { Icon } from '../icons.jsx'
import { useToast, useConfirm } from '../App.jsx'

// Convierte el texto pegado (una línea por contacto) en [{wa_id, name}]
function parseContacts(text) {
  return text.split('\n').map((line) => {
    const t = line.trim()
    if (!t) return null
    const m = t.match(/^[+\s]*([\d][\d\s\-().]*\d|\d)\s*[,;:\-]?\s*(.*)$/)
    if (!m) return null
    const wa = m[1].replace(/\D/g, '')
    if (!wa) return null
    return { wa_id: wa, name: (m[2] || '').trim() }
  }).filter(Boolean)
}

// ---- Detalle de una agenda ----
function Detail({ id, onBack }) {
  const toast = useToast()
  const confirm = useConfirm()
  const [pb, setPb] = useState(null)
  const [labels, setLabels] = useState([])
  const [raw, setRaw] = useState('')
  const [busy, setBusy] = useState(false)

  const load = useCallback(() => {
    api.getPhonebook(id).then((d) => d.ok && setPb(d.phonebook))
    api.listLabels().then((d) => setLabels(d.labels || []))
  }, [id])
  useEffect(() => { load() }, [load])

  const addManual = async () => {
    const contacts = parseContacts(raw)
    if (!contacts.length) { toast('No hay números válidos que añadir', 'err'); return }
    setBusy(true)
    const r = await api.addPhonebookContacts({ phonebook_id: id, contacts })
    setBusy(false)
    if (r.ok) { toast(`${r.added} contacto(s) añadido(s)`); setRaw(''); load() }
    else toast(r.error || 'Error al añadir', 'err')
  }

  const importContacts = async () => {
    setBusy(true)
    const r = await api.addPhonebookContacts({ phonebook_id: id, import: 'contacts' })
    setBusy(false)
    if (r.ok) { toast(`${r.added} contacto(s) importado(s)`); load() }
    else toast(r.error || 'Error al importar', 'err')
  }

  const importLabel = async (labelId) => {
    setBusy(true)
    const r = await api.addPhonebookContacts({ phonebook_id: id, import: 'label', label_id: labelId })
    setBusy(false)
    if (r.ok) { toast(`${r.added} contacto(s) importado(s)`); load() }
    else toast(r.error || 'Error al importar', 'err')
  }

  const delContact = async (cid) => { await api.deletePhonebookContact(cid); load() }

  if (!pb) return <div className="center-load"><div className="spinner" /></div>

  return (
    <>
      <header className="page-head">
        <button className="btn ghost sm" onClick={onBack}><Icon.send style={{ transform: 'rotate(180deg)' }} /> Volver</button>
        <div style={{ marginLeft: 8 }}><h1>{pb.name}</h1></div>
        <span className="sub">· {pb.contacts.length} contactos</span>
        <div className="spacer" />
      </header>
      <div className="page-scroll">
        <div className="page" style={{ maxWidth: 980 }}>
          <div className="grid2" style={{ gap: 16, alignItems: 'start' }}>
            <div className="card" style={{ padding: 18 }}>
              <div className="fb-set-t" style={{ marginBottom: 10 }}>Añadir contactos</div>
              <p className="muted" style={{ fontSize: 12.5, marginBottom: 8 }}>Un contacto por línea: número con prefijo de país y, opcional, nombre tras una coma.</p>
              <textarea className="cmp-textarea" rows={6} placeholder={'34649786051, Juan Pérez\n34600111222, María'} value={raw} onChange={(e) => setRaw(e.target.value)} />
              <button className="btn" disabled={busy} onClick={addManual} style={{ marginTop: 10 }}><Icon.plus /> Añadir</button>
              <div className="hr" style={{ margin: '16px 0' }} />
              <div className="muted" style={{ fontSize: 12.5, marginBottom: 8 }}>O importar desde lo que ya tienes:</div>
              <div className="add-row" style={{ flexWrap: 'wrap', gap: 8 }}>
                <button className="btn ghost sm" disabled={busy} onClick={importContacts}><Icon.download /> Todos mis contactos</button>
                {labels.map((l) => (
                  <button key={l.id} className="btn ghost sm" disabled={busy} onClick={() => importLabel(l.id)}>
                    <span className="dot" style={{ background: l.color }} /> {l.name}
                  </button>
                ))}
              </div>
            </div>

            <div className="card" style={{ padding: 0 }}>
              <div className="fb-set-t" style={{ padding: '14px 16px 0' }}>Contactos en la agenda</div>
              {pb.contacts.length === 0 ? (
                <div className="empty" style={{ padding: '30px 16px' }}><p>Aún no hay contactos. Añade números o importa una etiqueta.</p></div>
              ) : (
                <div className="pb-list">
                  {pb.contacts.map((c) => (
                    <div key={c.id} className="pb-row">
                      <span className="pb-avatar">{(c.name || c.wa_id).slice(0, 1).toUpperCase()}</span>
                      <div className="pb-meta"><b>{c.name || '—'}</b><span className="muted">+{c.wa_id}</span></div>
                      <button className="icon-btn" style={{ color: 'var(--danger)', marginLeft: 'auto' }} onClick={() => delContact(c.id)}><Icon.trash /></button>
                    </div>
                  ))}
                </div>
              )}
            </div>
          </div>
        </div>
      </div>
    </>
  )
}

// ---- Lista de agendas ----
export default function Phonebook() {
  const toast = useToast()
  const confirm = useConfirm()
  const [list, setList] = useState(null)
  const [openId, setOpenId] = useState(null)
  const [creating, setCreating] = useState(false)
  const [name, setName] = useState('')
  const [desc, setDesc] = useState('')

  const load = useCallback(() => { api.listPhonebooks().then((d) => setList(d.phonebooks || [])) }, [])
  useEffect(() => { load() }, [load])

  const create = async () => {
    if (!name.trim()) { toast('Ponle un nombre a la agenda', 'err'); return }
    const r = await api.savePhonebook({ name, description: desc })
    if (r.ok) { toast('Agenda creada'); setCreating(false); setName(''); setDesc(''); load(); setOpenId(r.id) }
    else toast(r.error || 'Error', 'err')
  }

  const del = async (id) => {
    if (!(await confirm({ title: 'Eliminar agenda', message: '¿Eliminar esta agenda y todos sus contactos?', danger: true, confirmText: 'Eliminar' }))) return
    const r = await api.deletePhonebook(id)
    if (r.ok) { toast('Agenda eliminada'); load() }
  }

  if (openId) return <Detail id={openId} onBack={() => { setOpenId(null); load() }} />

  return (
    <>
      <header className="page-head">
        <span className="ic" style={{ width: 30, height: 30, borderRadius: 9, background: 'var(--primary-soft)', display: 'grid', placeItems: 'center' }}><Icon.user style={{ width: 18, height: 18, fill: 'var(--primary)' }} /></span>
        <div><h1>Agenda de contactos</h1></div>
        <span className="sub">· Listas para tus difusiones</span>
        <div className="spacer" />
        <button className="btn" onClick={() => setCreating(true)}><Icon.plus /> Nueva agenda</button>
      </header>
      <div className="page-scroll">
        <div className="page" style={{ maxWidth: 1120 }}>
          {creating && (
            <div className="card" style={{ padding: 18, marginBottom: 16 }}>
              <div className="fb-set-t" style={{ marginBottom: 12 }}>Nueva agenda</div>
              <div className="grid2">
                <label className="field"><span className="lbl">Nombre</span><input value={name} onChange={(e) => setName(e.target.value)} placeholder="p. ej. Clientes 2026" autoFocus /></label>
                <label className="field"><span className="lbl">Descripción <span className="hint">(opcional)</span></span><input value={desc} onChange={(e) => setDesc(e.target.value)} /></label>
              </div>
              <div className="add-row" style={{ marginTop: 12 }}>
                <button className="btn" onClick={create}><Icon.save /> Crear</button>
                <button className="btn ghost" onClick={() => { setCreating(false); setName(''); setDesc('') }}>Cancelar</button>
              </div>
            </div>
          )}

          {list === null ? <div className="center-load"><div className="spinner" /></div> :
            list.length === 0 && !creating ? (
              <div className="empty"><div className="ico"><Icon.user /></div><p><b>Aún no hay agendas</b><br />Crea una lista de contactos para enviar campañas.</p><button className="btn" onClick={() => setCreating(true)}><Icon.plus /> Nueva agenda</button></div>
            ) : (
              <div className="flow-grid">
                {list.map((p) => (
                  <div className="flow-card" key={p.id} onClick={() => setOpenId(p.id)}>
                    <div className="fc-top"><span className="fc-ic"><Icon.user /></span><span className="pill ok"><span className="dot" />{p.contacts} contactos</span></div>
                    <div className="fc-name">{p.name}</div>
                    {p.description && <div className="muted" style={{ fontSize: 12.5 }}>{p.description}</div>}
                    <div className="fc-foot"><span className="muted">{new Date(p.updated_at.replace(' ', 'T')).toLocaleDateString('es-ES')}</span><button className="btn ghost sm" style={{ color: 'var(--danger)' }} onClick={(e) => { e.stopPropagation(); del(p.id) }}><Icon.trash /></button></div>
                  </div>
                ))}
              </div>
            )}
        </div>
      </div>
    </>
  )
}
