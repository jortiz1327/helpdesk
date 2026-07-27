import { useState, useEffect, useCallback } from 'react'
import { api } from '../api.js'
import { Icon } from '../icons.jsx'
import { useToast, useConfirm } from '../App.jsx'

/* ---------------------------------------------------------------------------
 * ORGANIZACIÓN de clientes: Grupo → Marca → Sede.
 * Ej.: Grupo Barceló → marcas Allegro/Occidental → sedes (hoteles). El contacto se
 * asigna a una sede desde su ficha; aquí se gestiona el árbol.
 * ------------------------------------------------------------------------- */

const LBL = { grupo: 'grupo', marca: 'marca', sede: 'sede' }

function OrgModal({ title, onClose, onSave, saveLabel, children }) {
  useEffect(() => {
    const h = (e) => e.key === 'Escape' && onClose()
    document.addEventListener('keydown', h); return () => document.removeEventListener('keydown', h)
  }, [onClose])
  return (
    <div className="modal-bg" onClick={(e) => e.target.classList.contains('modal-bg') && onClose()}>
      <div className="modal" style={{ maxWidth: 520 }}>
        <div className="modal-h"><h3>{title}</h3><button className="icon-btn" onClick={onClose}>✕</button></div>
        <div className="modal-body">{children}</div>
        <div className="modal-foot">
          <button className="btn ghost" onClick={onClose}>Cancelar</button>
          <button className="btn" onClick={onSave}>{saveLabel}</button>
        </div>
      </div>
    </div>
  )
}

/* Suma de tickets: marca = sus sedes; grupo = todas sus marcas. */
const sumMarca = (m) => m.sedes.reduce((a, s) => a + (s.tickets || 0), 0)
const sumGrupo = (g) => g.marcas.reduce((a, m) => a + sumMarca(m), 0)

export default function Organizations({ onVerTickets }) {
  const toast = useToast()
  const confirm = useConfirm()
  const [grupos, setGrupos] = useState(null)
  const [form, setForm] = useState(null)   // { level, id, name, ...campos }

  const load = useCallback(() => { api.orgTree().then((d) => setGrupos(d.ok ? d.grupos : [])) }, [])
  useEffect(() => { load() }, [load])

  const set = (patch) => setForm((f) => ({ ...f, ...patch }))

  const save = async () => {
    if (!form.name.trim()) { toast('El nombre es obligatorio', 'err'); return }
    const r = await api.orgSave(form)
    if (r.ok) { toast('Guardado'); setForm(null); load() } else toast(r.error || 'Error', 'err')
  }
  const del = async (level, id, name, aviso = '') => {
    if (!(await confirm({ title: `Eliminar ${LBL[level]}`, message: `¿Eliminar «${name}»?${aviso ? ' ' + aviso : ''}`, danger: true, confirmText: 'Eliminar' }))) return
    const r = await api.orgDelete(level, id)
    if (r.ok) { toast('Eliminado'); load() } else toast(r.error || 'Error', 'err')
  }

  // Aperturas del formulario
  const newGrupo = () => setForm({ level: 'grupo', id: 0, name: '', color: '#2563eb', note: '', active: true })
  const editGrupo = (g) => setForm({ level: 'grupo', id: g.id, name: g.name, color: g.color || '#2563eb', note: g.note || '', active: g.active })
  const newMarca = (g) => setForm({ level: 'marca', id: 0, grupo_id: g.id, grupoName: g.name, name: '', active: true })
  const editMarca = (g, m) => setForm({ level: 'marca', id: m.id, grupo_id: g.id, grupoName: g.name, name: m.name, active: m.active })
  const newSede = (g, m) => setForm({ level: 'sede', id: 0, marca_id: m.id, ruta: `${g.name} · ${m.name}`, name: '', city: '', address: '', active: true })
  const editSede = (g, m, s) => setForm({ level: 'sede', id: s.id, marca_id: m.id, ruta: `${g.name} · ${m.name}`, name: s.name, city: s.city || '', address: s.address || '', active: s.active })

  return (
    <>
      <header className="page-head">
        <span className="sc-ic"><Icon.building style={{ width: 18, height: 18, fill: 'var(--primary)' }} /></span>
        <div><h1>Organización</h1></div>
        <span className="sub">· Grupos, marcas y sedes de tus clientes</span>
        <div className="spacer" />
        <button className="btn" onClick={newGrupo}><Icon.plus /> Nuevo grupo</button>
      </header>

      <div className="page-scroll"><div className="page" style={{ maxWidth: 920 }}>
        {grupos === null ? <div className="center-load"><div className="spinner" /></div> : grupos.length === 0 ? (
          <div className="card tk-empty">
            <div className="e-ic"><Icon.building style={{ width: 26, height: 26, fill: 'var(--ink-2)' }} /></div>
            <h3>Aún no hay grupos</h3>
            <p>Crea un grupo (p. ej. «Grupo Barceló»), sus marcas y sus sedes.</p>
          </div>
        ) : grupos.map((g) => (
          <div key={g.id} className={`org-grupo ${g.active ? '' : 'off'}`}>
            <div className="org-g-head">
              <span className="org-dot" style={{ background: g.color || 'var(--ink-3)' }} />
              <b className="org-g-name">{g.name}</b>
              {!g.active && <span className="chip cerrado sm">Inactivo</span>}
              <span className="org-count">{g.marcas.length} marca{g.marcas.length === 1 ? '' : 's'}</span>
              <span style={{ flex: 1 }} />
              {onVerTickets && sumGrupo(g) > 0 && (
                <button className="btn ghost sm org-vt" title="Ver sus tickets" onClick={() => onVerTickets(`grupo:${g.id}`)}><Icon.ticket /> {sumGrupo(g)} tickets</button>
              )}
              <button className="btn ghost sm" onClick={() => newMarca(g)}><Icon.plus /> Marca</button>
              <button className="icon-btn" title="Editar grupo" onClick={() => editGrupo(g)}><Icon.pencil /></button>
              <button className="icon-btn" title="Eliminar grupo" style={{ color: 'var(--danger)' }} onClick={() => del('grupo', g.id, g.name)}><Icon.trash /></button>
            </div>

            {g.marcas.length === 0 ? (
              <div className="org-empty">Sin marcas todavía. Añade una con «+ Marca».</div>
            ) : g.marcas.map((m) => (
              <div key={m.id} className={`org-marca ${m.active ? '' : 'off'}`}>
                <div className="org-m-head">
                  <Icon.tag style={{ width: 15, height: 15, fill: 'var(--ink-3)', flex: 'none' }} />
                  <b>{m.name}</b>
                  {!m.active && <span className="chip cerrado sm">Inactiva</span>}
                  <span className="org-count">{m.sedes.length} sede{m.sedes.length === 1 ? '' : 's'}</span>
                  <span style={{ flex: 1 }} />
                  {onVerTickets && sumMarca(m) > 0 && (
                    <button className="btn ghost sm org-vt" title="Ver sus tickets" onClick={() => onVerTickets(`marca:${m.id}`)}><Icon.ticket /> {sumMarca(m)}</button>
                  )}
                  <button className="btn ghost sm" onClick={() => newSede(g, m)}><Icon.plus /> Sede</button>
                  <button className="icon-btn" title="Editar marca" onClick={() => editMarca(g, m)}><Icon.pencil /></button>
                  <button className="icon-btn" title="Eliminar marca" style={{ color: 'var(--danger)' }} onClick={() => del('marca', m.id, m.name)}><Icon.trash /></button>
                </div>

                {m.sedes.map((s) => (
                  <div key={s.id} className={`org-sede ${s.active ? '' : 'off'}`}>
                    <Icon.home style={{ width: 14, height: 14, fill: 'var(--ink-3)', flex: 'none' }} />
                    <span className="org-s-name">{s.name}</span>
                    {s.city && <span className="org-s-city">{s.city}</span>}
                    {!s.active && <span className="chip cerrado sm">Inactiva</span>}
                    <span className="org-s-count" title="Contactos en esta sede">{s.contactos} 👤</span>
                    {onVerTickets && s.tickets > 0 && (
                      <button className="org-s-tk" title="Ver sus tickets" onClick={() => onVerTickets(`sede:${s.id}`)}><Icon.ticket /> {s.tickets}</button>
                    )}
                    <span style={{ flex: 1 }} />
                    <button className="icon-btn" title="Editar sede" onClick={() => editSede(g, m, s)}><Icon.pencil /></button>
                    <button className="icon-btn" title="Eliminar sede" style={{ color: 'var(--danger)' }}
                      onClick={() => del('sede', s.id, s.name, s.contactos ? `Sus ${s.contactos} contacto(s) se quedarán sin sede.` : '')}><Icon.trash /></button>
                  </div>
                ))}
              </div>
            ))}
          </div>
        ))}
      </div></div>

      {form && (
        <OrgModal
          title={`${form.id ? 'Editar' : 'Nuevo'} ${LBL[form.level]}${form.id ? '' : (form.level === 'grupo' ? '' : '')}`}
          onClose={() => setForm(null)} onSave={save} saveLabel={form.id ? 'Guardar' : 'Crear'}>
          {form.level === 'marca' && <p className="org-ctx">En el grupo <b>{form.grupoName}</b></p>}
          {form.level === 'sede' && <p className="org-ctx">En <b>{form.ruta}</b></p>}

          <label className="field"><span className="lbl">Nombre <em>*</em></span>
            <input value={form.name} autoFocus onChange={(e) => set({ name: e.target.value })}
              placeholder={form.level === 'grupo' ? 'p. ej. Grupo Barceló' : form.level === 'marca' ? 'p. ej. Allegro' : 'p. ej. Allegro Madrid'} /></label>

          {form.level === 'grupo' && (
            <div className="grid2">
              <label className="field"><span className="lbl">Color</span>
                <input type="color" value={form.color} onChange={(e) => set({ color: e.target.value })} style={{ height: 42, padding: 4 }} /></label>
            </div>
          )}
          {form.level === 'grupo' && (
            <label className="field"><span className="lbl">Nota <span className="hint">· opcional</span></span>
              <textarea rows={2} value={form.note} onChange={(e) => set({ note: e.target.value })} placeholder="Cualquier apunte del grupo…" /></label>
          )}
          {form.level === 'sede' && (
            <div className="grid2">
              <label className="field"><span className="lbl">Ciudad</span>
                <input value={form.city} onChange={(e) => set({ city: e.target.value })} placeholder="Madrid" /></label>
              <label className="field"><span className="lbl">Dirección <span className="hint">· opcional</span></span>
                <input value={form.address} onChange={(e) => set({ address: e.target.value })} placeholder="Calle…" /></label>
            </div>
          )}

          <label className="fb-req-row">
            <span className="fb-switch"><input type="checkbox" checked={form.active} onChange={(e) => set({ active: e.target.checked })} /><span className={`fb-toggle ${form.active ? 'on' : ''}`} /></span>
            <span className="fb-req-label">Activo <span className="hint">· si no, deja de ofrecerse al asignar</span></span>
          </label>
        </OrgModal>
      )}
    </>
  )
}
