import { useState, useEffect, useCallback, useMemo } from 'react'
import { api } from '../api.js'
import { Icon } from '../icons.jsx'
import { useToast } from '../App.jsx'
import { initials, avatarBg, parseDate } from '../util.js'
import LabelManager from './LabelManager.jsx'
import Select from './Select.jsx'
import Kanban from './Kanban.jsx'

export default function Contacts({ onOpen, area = '' }) {
  const toast = useToast()
  const [mode, setMode] = useState('list')   // 'list' | 'kanban' — misma info, dos vistas
  const [editing, setEditing] = useState(null)   // contacto en edición (ficha)
  const [merging, setMerging] = useState(null)   // los dos contactos a fusionar
  // Las difusiones y las altas/bajas son de Campañas; en Helpdesk se ocultan.
  const esCampanas = area !== 'helpdesk'
  const [contacts, setContacts] = useState(null)
  const [labels, setLabels] = useState([])
  const [phonebooks, setPhonebooks] = useState([])
  const [q, setQ] = useState('')
  const [labelFilter, setLabelFilter] = useState(0)
  const [optoutFilter, setOptoutFilter] = useState('')
  const [sel, setSel] = useState(new Set())
  const [managing, setManaging] = useState(false)
  const [bulkLabelId, setBulkLabelId] = useState('')
  const [bulkPbId, setBulkPbId] = useState('')

  const load = useCallback(() => {
    api.listContacts(q, labelFilter, optoutFilter, area).then((d) => setContacts(d.contacts || []))
  }, [q, labelFilter, optoutFilter, area])
  useEffect(() => { const t = setTimeout(load, 250); return () => clearTimeout(t) }, [load])
  const loadAux = useCallback(() => {
    api.listLabels().then((d) => setLabels(d.labels || []))
    api.listPhonebooks().then((d) => setPhonebooks(d.phonebooks || []))
  }, [])
  useEffect(() => { loadAux() }, [loadAux])

  const visibleIds = useMemo(() => (contacts || []).map((c) => c.id), [contacts])
  const allSelected = visibleIds.length > 0 && visibleIds.every((id) => sel.has(id))

  const toggle = (id) => setSel((s) => { const n = new Set(s); n.has(id) ? n.delete(id) : n.add(id); return n })
  const toggleAll = () => setSel((s) => (allSelected ? new Set() : new Set(visibleIds)))
  const clearSel = () => setSel(new Set())

  const applyLabel = async (mode) => {
    if (!bulkLabelId) { toast('Elige una etiqueta', 'err'); return }
    const ids = [...sel]
    const r = await api.bulkLabel(ids, Number(bulkLabelId), mode)
    if (!r.ok) { toast(r.error || 'Error', 'err'); return }
    const lbl = labels.find((l) => l.id === Number(bulkLabelId))
    toast(mode === 'add' ? `Etiqueta «${lbl?.name}» aplicada a ${r.changed} contacto(s)` : `Etiqueta quitada de ${r.changed} contacto(s)`)
    clearSel(); load()
  }
  const addToPhonebook = async () => {
    if (!bulkPbId) { toast('Elige una agenda', 'err'); return }
    const r = await api.bulkAddToPhonebook([...sel], Number(bulkPbId))
    if (!r.ok) { toast(r.error || 'Error', 'err'); return }
    const pb = phonebooks.find((p) => p.id === Number(bulkPbId))
    toast(`${r.added} contacto(s) añadidos a «${pb?.name}»`)
    clearSel(); loadAux()
  }
  const setOptout = async (value) => {
    const r = await api.setOptout([...sel], value)
    if (!r.ok) { toast(r.error || 'Error', 'err'); return }
    toast(value ? 'Contactos dados de baja' : 'Contactos reactivados')
    clearSel(); load()
  }

  return (
    <>
      <header className="page-head">
        <span className="ic" style={{ width: 30, height: 30, borderRadius: 9, background: 'var(--primary-soft)', display: 'grid', placeItems: 'center' }}><Icon.user style={{ width: 18, height: 18, fill: 'var(--primary)' }} /></span>
        <div><h1>Contactos</h1></div>
        <span className="sub">· Etiqueta por sectores y crea segmentos</span>
        <div className="spacer" />
        {/* Conmutador de vista: misma info (contactos + etiquetas), lista o kanban */}
        <div className="view-toggle">
          <button className={mode === 'list' ? 'on' : ''} onClick={() => setMode('list')} title="Vista lista"><Icon.list /> Lista</button>
          <button className={mode === 'kanban' ? 'on' : ''} onClick={() => setMode('kanban')} title="Vista kanban"><Icon.kanban /> Kanban</button>
        </div>
        <button className="btn ghost" onClick={() => setManaging(true)}><Icon.tag /> Gestionar etiquetas</button>
      </header>

      {mode === 'kanban' ? (
        <Kanban embedded onOpen={onOpen} area={area} />
      ) : (
      <div className="page-scroll">
        <div className="page" style={{ maxWidth: 1120 }}>
          <div className="cmp-toolbar">
            <div className="search-box" style={{ flex: 1 }}><Icon.search /><input placeholder="Buscar por nombre, número, correo o etiqueta…" value={q} onChange={(e) => setQ(e.target.value)} /></div>
            <Select value={labelFilter} onChange={setLabelFilter}
              options={[{ value: 0, label: 'Todas las etiquetas' }, ...labels.map((l) => ({ value: l.id, label: l.name, color: l.color }))]} />
            {esCampanas && (
              <Select value={optoutFilter} onChange={setOptoutFilter} options={[
                { value: '', label: 'Campañas: todos' },
                { value: '0', label: 'Reciben campañas', color: '#25d366' },
                { value: '1', label: 'Baja de campañas', color: '#f25c54' },
              ]} />
            )}
          </div>

          {/* Barra de acciones en lote */}
          {sel.size > 0 && (
            <div className="bulk-bar">
              <span className="bulk-count">{sel.size} seleccionado{sel.size > 1 ? 's' : ''}</span>
              <div className="bulk-actions">
                <Select sm value={bulkLabelId} onChange={setBulkLabelId} placeholder="Etiqueta…"
                  options={labels.map((l) => ({ value: l.id, label: l.name, color: l.color }))} />
                <button className="btn sm" onClick={() => applyLabel('add')}><Icon.tag /> Aplicar</button>
                <button className="btn ghost sm" onClick={() => applyLabel('remove')}>Quitar</button>
                {/* Agendas de difusión y altas/bajas son cosa de CAMPAÑAS: en Helpdesk no pintan nada. */}
                {esCampanas && <>
                  <span className="bulk-sep" />
                  <Select sm value={bulkPbId} onChange={setBulkPbId} placeholder="Agenda…"
                    options={phonebooks.map((p) => ({ value: p.id, label: p.name }))} />
                  <button className="btn ghost sm" onClick={addToPhonebook}><Icon.plus /> Añadir a agenda</button>
                  <span className="bulk-sep" />
                  <button className="btn ghost sm" onClick={() => setOptout(0)} title="Vuelve a recibir campañas"><Icon.check /> Reactivar campañas</button>
                  <button className="btn ghost sm" style={{ color: 'var(--danger)' }} onClick={() => setOptout(1)} title="Deja de recibir campañas/difusiones. Soporte SÍ puede seguir escribiéndole."><Icon.bell /> Baja de campañas</button>
                </>}
                {/* Fusionar: solo tiene sentido con EXACTAMENTE dos (el mismo cliente duplicado). */}
                {sel.size === 2 && <>
                  <span className="bulk-sep" />
                  <button className="btn sm" onClick={() => setMerging([...sel].map((id) => contacts.find((c) => c.id === id)).filter(Boolean))}
                    title="El mismo cliente duplicado (uno por WhatsApp y otro por correo): únelos en uno solo">
                    <Icon.user /> Fusionar
                  </button>
                </>}
              </div>
              <button className="link-btn" style={{ marginLeft: 'auto', marginTop: 0 }} onClick={clearSel}>Quitar selección</button>
            </div>
          )}

          {contacts === null ? <div className="center-load"><div className="spinner" /></div> :
            contacts.length === 0 ? (
              <div className="empty"><div className="ico"><Icon.user /></div><p>No hay contactos {q || labelFilter ? 'con ese filtro' : 'todavía'}.</p></div>
            ) : (
              <div className="card" style={{ padding: 0 }}>
                <div className="ct-row ct-head">
                  <label className="ct-check"><input type="checkbox" checked={allSelected} onChange={toggleAll} /></label>
                  <span className="ct-name">Contacto ({contacts.length})</span>
                  <span className="ct-labels">Etiquetas</span>
                  <span className="ct-date">Último mensaje</span>
                  <span className="ct-edit" />
                </div>
                {contacts.map((c) => (
                  <label key={c.id} className={`ct-row ${sel.has(c.id) ? 'on' : ''}`}>
                    <span className="ct-check"><input type="checkbox" checked={sel.has(c.id)} onChange={() => toggle(c.id)} /></span>
                    <span className="ct-name">
                      <span className="avatar sm" style={{ background: avatarBg(c.wa_id || '') }}>{initials(c)}</span>
                      <span className="ct-meta">
                        <b>{c.name || '—'}{esCampanas && Number(c.opted_out) === 1 && <span className="pill err sm" style={{ marginLeft: 7, verticalAlign: 'middle' }} title="Baja de campañas: no recibe difusiones. Soporte sí puede escribirle.">Baja campañas</span>}</b>
                        {/* Un contacto puede tener teléfono y/o correo: se muestra lo que haya. */}
                        <span className="muted">
                          {c.wa_id ? `+${c.wa_id}` : ''}
                          {c.wa_id && c.email ? ' · ' : ''}
                          {c.email || ''}
                          {!c.wa_id && !c.email ? '—' : ''}
                        </span>
                      </span>
                    </span>
                    <span className="ct-labels">
                      {c.labels.length === 0 ? <span className="muted" style={{ fontSize: 12 }}>—</span> :
                        c.labels.map((l) => <span key={l.id} className="ct-tag" style={{ background: l.color + '22', color: l.color }}>{l.name}</span>)}
                    </span>
                    <span className="ct-date muted">{c.last_time ? parseDate(c.last_time)?.toLocaleDateString('es-ES') : '—'}</span>
                    <span className="ct-edit">
                      {/* preventDefault: si no, el clic dentro del <label> marcaría el checkbox */}
                      <button className="icon-btn" title="Editar contacto"
                        onClick={(e) => { e.preventDefault(); e.stopPropagation(); setEditing(c) }}>
                        <Icon.pencil />
                      </button>
                    </span>
                  </label>
                ))}
              </div>
            )}
        </div>
      </div>
      )}

      {managing && <LabelManager labels={labels} onClose={() => setManaging(false)} onChanged={loadAux} />}
      {editing && <ContactEdit contact={editing} onClose={() => setEditing(null)} onSaved={() => { setEditing(null); load() }} />}
      {merging && <ContactMerge pair={merging} onClose={() => setMerging(null)}
        onMerged={() => { setMerging(null); clearSel(); load() }} />}
    </>
  )
}

/* --------------------------- Fusionar contactos ---------------------------
 * El mismo cliente puede acabar duplicado: un contacto creado por WhatsApp y otro
 * por correo. Como no comparten ningún dato, no hay forma de detectarlo solo: se
 * eligen los dos a mano y se decide CUÁL SE QUEDA.
 * ------------------------------------------------------------------------- */
function ContactMerge({ pair, onClose, onMerged }) {
  const toast = useToast()
  const [keepId, setKeepId] = useState(pair[0].id)
  const [busy, setBusy] = useState(false)

  useEffect(() => {
    const h = (e) => e.key === 'Escape' && onClose()
    document.addEventListener('keydown', h)
    return () => document.removeEventListener('keydown', h)
  }, [onClose])

  const keep = pair.find((c) => c.id === keepId)
  const gone = pair.find((c) => c.id !== keepId)
  const datos = (c) => [c.wa_id ? `+${c.wa_id}` : null, c.email].filter(Boolean).join(' · ') || 'sin datos'
  // Lo que el principal NO tiene y sí aporta el otro: eso es lo que se hereda.
  const hereda = ['name', 'email', 'wa_id'].filter((k) => !keep?.[k] && gone?.[k])
  const ETIQ = { name: 'nombre', email: 'correo', wa_id: 'teléfono' }

  const doMerge = async () => {
    setBusy(true)
    const r = await api.mergeContacts(keepId, gone.id)
    setBusy(false)
    if (r.ok) { toast('Contactos fusionados'); onMerged() }
    else toast(r.error || 'No se pudo fusionar', 'err')
  }

  return (
    <div className="modal-bg" onClick={(e) => e.target.classList.contains('modal-bg') && onClose()}>
      <div className="modal" style={{ maxWidth: 560 }}>
        <div className="modal-h"><h3>Fusionar contactos</h3><button className="icon-btn" onClick={onClose} title="Cerrar (Esc)">✕</button></div>
        <div className="modal-body">
          <p className="ct-hint" style={{ marginTop: 0 }}>Elige cuál se queda. El otro se elimina y toda su actividad pasa al primero.</p>
          <div className="mg-opts">
            {pair.map((c) => (
              <label key={c.id} className={`mg-opt ${c.id === keepId ? 'on' : ''}`}>
                <input type="radio" name="keep" checked={c.id === keepId} onChange={() => setKeepId(c.id)} />
                <span className="mg-tx">
                  <b>{c.name || '—'}</b>
                  <small>{datos(c)}</small>
                </span>
                {c.id === keepId && <span className="pill sm">Se queda</span>}
              </label>
            ))}
          </div>
          <div className="mg-sum">
            <div><b>{gone?.name || 'El otro contacto'}</b> se elimina; sus mensajes, tickets y etiquetas pasan a <b>{keep?.name || 'el principal'}</b>.</div>
            {hereda.length > 0 && <div>Además, <b>{keep?.name || 'el principal'}</b> heredará su {hereda.map((k) => ETIQ[k]).join(' y ')}.</div>}
            <div className="mg-warn">Esta acción no se puede deshacer.</div>
          </div>
        </div>
        <div className="modal-foot">
          <button className="btn ghost" onClick={onClose}>Cancelar</button>
          <button className="btn" onClick={doMerge} disabled={busy}>{busy ? 'Fusionando…' : 'Fusionar'}</button>
        </div>
      </div>
    </div>
  )
}

/* --------------------------- Ficha del contacto ---------------------------
 * Un contacto puede tener CORREO y/o TELÉFONO (los de soporte entran por correo;
 * los de campañas, por WhatsApp). El teléfono se edita en dos campos —código de
 * país y número— y el servidor lo guarda junto, que es como lo necesita WhatsApp.
 * ------------------------------------------------------------------------- */
function ContactEdit({ contact, onClose, onSaved }) {
  const toast = useToast()
  // El wa_id guardado es país+número: se parte con el country_code conocido.
  const cc0 = contact.country_code || ''
  const phone0 = contact.wa_id ? (cc0 && contact.wa_id.startsWith(cc0) ? contact.wa_id.slice(cc0.length) : contact.wa_id) : ''
  const [f, setF] = useState({
    name: contact.name || '',
    email: contact.email || '',
    country_code: cc0 || '34',
    phone: phone0,
  })
  const [busy, setBusy] = useState(false)
  const set = (k) => (e) => setF((s) => ({ ...s, [k]: e.target.value }))

  useEffect(() => {
    const h = (e) => e.key === 'Escape' && onClose()
    document.addEventListener('keydown', h)
    return () => document.removeEventListener('keydown', h)
  }, [onClose])

  const save = async () => {
    if (!f.email.trim() && !f.phone.trim()) { toast('Indica al menos un correo o un teléfono', 'err'); return }
    setBusy(true)
    const r = await api.saveContact(contact.id, {
      name: f.name, email: f.email, country_code: f.country_code, phone: f.phone,
    })
    setBusy(false)
    if (r.ok) { toast('Contacto actualizado'); onSaved() }
    else toast(r.error || 'No se pudo guardar', 'err')
  }

  return (
    <div className="modal-bg" onClick={(e) => e.target.classList.contains('modal-bg') && onClose()}>
      <div className="modal" style={{ maxWidth: 520 }}>
        <div className="modal-h">
          <h3>Editar contacto</h3>
          <button className="icon-btn" onClick={onClose} title="Cerrar (Esc)">✕</button>
        </div>
        <div className="modal-body">
          <label className="field"><span className="lbl">Nombre</span>
            <input value={f.name} onChange={set('name')} placeholder="Nombre del contacto" autoFocus /></label>
          <label className="field"><span className="lbl">Correo electrónico</span>
            <input type="email" value={f.email} onChange={set('email')} placeholder="cliente@dominio.com" /></label>
          {/* Prefijo estrecho + número ancho: se leen como un solo teléfono. */}
          <div className="ct-phone">
            <label className="field"><span className="lbl">Código de país</span>
              <input value={f.country_code} onChange={set('country_code')} placeholder="34" inputMode="numeric" /></label>
            <label className="field"><span className="lbl">Teléfono</span>
              <input value={f.phone} onChange={set('phone')} placeholder="600123456" inputMode="numeric" /></label>
          </div>
          <p className="ct-hint">Puede tener correo, teléfono o ambos.</p>
        </div>
        <div className="modal-foot">
          <button className="btn ghost" onClick={onClose}>Cancelar</button>
          <button className="btn" onClick={save} disabled={busy}>{busy ? 'Guardando…' : 'Guardar'}</button>
        </div>
      </div>
    </div>
  )
}
