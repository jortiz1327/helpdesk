import { useState, useEffect, useRef, useMemo } from 'react'
import { api, mediaUrl } from '../api.js'
import { Icon } from '../icons.jsx'
import { initials, avatarBg, parseDate } from '../util.js'
import { useToast } from '../App.jsx'
import LabelManager from './LabelManager.jsx'
import Select from './Select.jsx'

export default function ChatInfo({ contact, messages, onClose, onUpdated }) {
  const toast = useToast()
  const [editName, setEditName] = useState(false)
  const [nameVal, setNameVal] = useState(contact.name || '')
  const [note, setNote] = useState(contact.note || '')
  const [noteState, setNoteState] = useState('idle') // idle | saving | saved
  const [allLabels, setAllLabels] = useState([])
  const [managing, setManaging] = useState(false)
  const [phonebooks, setPhonebooks] = useState([])
  const [pbSel, setPbSel] = useState('')
  const [pbBusy, setPbBusy] = useState(false)
  const [, setTick] = useState(0)
  const noteTimer = useRef(null)

  useEffect(() => { setNameVal(contact.name || ''); setNote(contact.note || ''); setNoteState('idle') }, [contact.id])
  useEffect(() => { api.listLabels().then((d) => setAllLabels(d.labels || [])) }, [])
  const loadPhonebooks = () => api.listPhonebooks().then((d) => setPhonebooks(d.phonebooks || []))
  useEffect(() => { loadPhonebooks() }, [])
  // refresco del contador cada 30 s
  useEffect(() => { const t = setInterval(() => setTick((x) => x + 1), 30000); return () => clearInterval(t) }, [])

  const assigned = useMemo(() => new Set((contact.labels || []).map((l) => l.id)), [contact.labels])

  const saveName = async () => {
    const v = nameVal.trim()
    await api.saveContact(contact.id, { name: v })
    setEditName(false)
    onUpdated({ name: v || null })
    toast('Contacto guardado')
  }

  const onNoteChange = (v) => {
    setNote(v); setNoteState('saving')
    clearTimeout(noteTimer.current)
    noteTimer.current = setTimeout(async () => {
      await api.saveContact(contact.id, { note: v })
      setNoteState('saved')
      onUpdated({ note: v })
    }, 700)
  }

  const addToPhonebook = async () => {
    if (!pbSel) return
    setPbBusy(true)
    const r = await api.addPhonebookContacts({ phonebook_id: Number(pbSel), contacts: [{ wa_id: contact.wa_id, name: contact.name || '' }] })
    setPbBusy(false)
    if (!r.ok) { toast(r.error || 'Error al añadir', 'err'); return }
    const pb = phonebooks.find((p) => p.id === Number(pbSel))
    toast(r.added ? `Añadido a «${pb?.name}»` : `Ya estaba en «${pb?.name}»`)
    loadPhonebooks()
  }

  const toggleLabel = async (id) => {
    const next = new Set(assigned)
    next.has(id) ? next.delete(id) : next.add(id)
    const ids = [...next]
    await api.setContactLabels(contact.id, ids)
    onUpdated({ labels: allLabels.filter((l) => next.has(l.id)) })
  }

  // ventana de 24 h
  const lastIn = [...messages].reverse().find((m) => m.direction === 'in')
  let win = { open: false, text: 'Expirada', detail: 'Tiempo agotado para responder sin plantilla' }
  if (lastIn) {
    const ms = 24 * 3600 * 1000 - (Date.now() - parseDate(lastIn.created_at).getTime())
    if (ms > 0) {
      const h = Math.floor(ms / 3600000), m = Math.floor((ms % 3600000) / 60000)
      win = { open: true, text: `${h}h ${m}m`, detail: 'Tiempo restante para responder sin plantilla' }
    }
  }

  // archivos compartidos
  const files = messages.filter((m) => ['image', 'sticker', 'video', 'document'].includes(m.type) && m.media_url)

  return (
    <>
      <aside className="info-panel">
        <div className="info-head"><h3>Información del chat</h3><button className="icon-btn x" onClick={onClose} title="Cerrar">✕</button></div>
        <div className="info-scroll">
          <div className="info-hero">
            <div className="avatar xl" style={{ background: avatarBg(contact.wa_id || '') }}>{initials(contact)}</div>
            <div className="nm">{contact.name || '+' + contact.wa_id}</div>
            <div className="ph">+{contact.wa_id}</div>
          </div>

          {/* Contact info */}
          <div className="info-sec">
            <div className="sec-t"><Icon.user /> Datos del contacto</div>
            <div className="kv"><span className="k">Nombre</span>
              {editName
                ? <input style={{ maxWidth: 150, padding: '5px 8px', fontSize: 13 }} value={nameVal} autoFocus onChange={(e) => setNameVal(e.target.value)} onKeyDown={(e) => e.key === 'Enter' && saveName()} />
                : <span className="v">{contact.name || '—'}</span>}
            </div>
            <div className="kv"><span className="k">Número</span><span className="v">+{contact.wa_id}</span></div>
            <div className="kv"><span className="k">Canal</span><span className="v">WhatsApp Cloud</span></div>
            {editName
              ? <button className="info-btn" onClick={saveName}><Icon.save /> Guardar contacto</button>
              : <button className="info-btn" onClick={() => setEditName(true)}><Icon.pencil /> {contact.name ? 'Editar nombre' : 'Guardar como contacto'}</button>}
          </div>

          {/* Response window */}
          <div className="info-sec">
            <div className="sec-t"><Icon.clock /> Ventana de respuesta</div>
            <div className={`window-box ${win.open ? 'open' : 'closed'}`}>
              <div className="wt">{win.open ? win.text : 'Expirada'}</div>
              <div className="wd"><Icon.clock style={{ width: 13, height: 13, fill: 'currentColor' }} /> {win.detail}</div>
            </div>
          </div>

          {/* Labels */}
          <div className="info-sec">
            <div className="sec-t"><Icon.tag /> Etiquetas</div>
            {allLabels.length === 0 && <p className="muted">No hay etiquetas creadas.</p>}
            <div className="lbl-row">
              {allLabels.map((l) => {
                const on = assigned.has(l.id)
                return (
                  <span key={l.id} className="lbl-pick" onClick={() => toggleLabel(l.id)}
                    style={{ background: on ? l.color : 'transparent', color: on ? '#04130c' : l.color, borderColor: l.color }}>
                    {on && <span className="check">✓</span>}{l.name}
                  </span>
                )
              })}
            </div>
            <button className="link-btn" onClick={() => setManaging(true)}><Icon.plus /> Gestionar etiquetas</button>
          </div>

          {/* Agendas (phonebooks) */}
          <div className="info-sec">
            <div className="sec-t"><Icon.user /> Agendas de difusión</div>
            {phonebooks.length === 0 ? (
              <p className="muted">No hay agendas. Créalas en «Agenda de contactos».</p>
            ) : (
              <div className="pb-add-row">
                <div style={{ flex: 1, minWidth: 0 }}>
                  <Select sm block value={pbSel} onChange={setPbSel} placeholder="Elige una agenda…"
                    options={phonebooks.map((p) => ({ value: p.id, label: p.name, sub: `${p.contacts} contactos` }))} />
                </div>
                <button className="info-btn sm" disabled={!pbSel || pbBusy} onClick={addToPhonebook}><Icon.plus /> Añadir</button>
              </div>
            )}
          </div>

          {/* Notes */}
          <div className="info-sec">
            <div className="sec-t"><Icon.note /> Notas</div>
            <textarea className="note-area" placeholder="Escribe una nota interna…" value={note} onChange={(e) => onNoteChange(e.target.value)} />
            {noteState !== 'idle' && <div className="note-save">{noteState === 'saving' ? 'Guardando…' : '✓ Guardado'}</div>}
          </div>

          {/* Shared files */}
          <div className="info-sec">
            <div className="sec-t"><Icon.file /> Archivos compartidos</div>
            {files.length === 0 ? <p className="muted">Sin archivos en esta conversación.</p> : (
              <div className="files-grid">
                {files.slice(0, 12).map((m) => (
                  <a key={m.id} href={mediaUrl(m.media_url)} target="_blank" rel="noreferrer">
                    {['image', 'sticker'].includes(m.type)
                      ? <img src={mediaUrl(m.media_url)} alt="" loading="lazy" />
                      : <span className="filey"><Icon.file /></span>}
                  </a>
                ))}
              </div>
            )}
          </div>

          {/* Activity */}
          <div className="info-sec">
            <div className="sec-t"><Icon.info /> Actividad</div>
            <div className="kv"><span className="k">Creado</span><span className="v">{contact.created_at ? parseDate(contact.created_at)?.toLocaleDateString('es-ES') : '—'}</span></div>
            <div className="kv"><span className="k">Mensajes</span><span className="v">{messages.length}</span></div>
          </div>
        </div>
      </aside>

      {managing && <LabelManager labels={allLabels} onClose={() => setManaging(false)} onChanged={() => api.listLabels().then((d) => setAllLabels(d.labels || []))} />}
    </>
  )
}
