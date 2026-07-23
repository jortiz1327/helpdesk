import { useState, useEffect, useCallback } from 'react'
import { api } from '../api.js'
import { Icon } from '../icons.jsx'
import { useToast, useConfirm } from '../App.jsx'
import Select from './Select.jsx'

export default function Users() {
  const toast = useToast()
  const confirm = useConfirm()
  const [users, setUsers] = useState(null)
  const [cats, setCats] = useState([])         // catálogo de categorías (áreas)
  const [roles, setRoles] = useState([])       // catálogo de roles (del backend)
  const [modules, setModules] = useState({})
  const [perms, setPerms] = useState([])       // catálogo de permisos
  const [form, setForm] = useState(null)       // null=lista, obj=editando/creando usuario
  const [defRole, setDefRole] = useState('')   // rol por defecto (lo dice el backend)
  const [superRole, setSuperRole] = useState('superadmin')
  const [roleForm, setRoleForm] = useState(null) // null=lista, obj=editando/creando rol

  const load = useCallback(() => { api.listUsers().then((d) => { setUsers(d.users || []); setCats(d.categories || []) }) }, [])
  const loadRoles = useCallback(() => api.listRoles().then((d) => {
    setRoles(d.roles || [])
    setModules(d.modules || {})
    setPerms(d.permissions || [])
    setDefRole(d.default || '')
    setSuperRole(d.super_role || 'superadmin')
  }), [])
  useEffect(() => { load(); loadRoles() }, [load, loadRoles])

  const blank = { id: 0, name: '', email: '', role: defRole, password: '', category_ids: [] }

  const toggleCat = (cid) => setForm((f) => {
    const has = f.category_ids.includes(cid)
    return { ...f, category_ids: has ? f.category_ids.filter((x) => x !== cid) : [...f.category_ids, cid] }
  })

  const save = async () => {
    if (!form.email.trim()) { toast('El email es obligatorio: es con lo que inicia sesión', 'err'); return }
    if (!form.id && form.password.length < 6) { toast('La contraseña debe tener al menos 6 caracteres', 'err'); return }
    const r = await api.saveUser(form)
    if (r.ok) { toast(form.id ? 'Usuario actualizado' : 'Usuario creado'); setForm(null); load() }
    else toast(r.error || 'Error', 'err')
  }
  const del = async (u) => {
    if (!(await confirm({ title: 'Eliminar usuario', message: `¿Eliminar a «${u.name || u.email}»? Sus conversaciones quedarán sin asignar.`, danger: true, confirmText: 'Eliminar' }))) return
    const r = await api.deleteUser(u.id)
    if (r.ok) { toast('Usuario eliminado'); load() } else toast(r.error || 'Error', 'err')
  }

  const roleOptions = roles.map((r) => ({ value: r.name, label: r.label, sub: r.description }))
  const roleOf = (name) => roles.find((r) => r.name === name)
  // Permisos del rol abierto, agrupados por módulo (solo los que tiene)
  const permsByModule = (roleName) => {
    const has = new Set(roleOf(roleName)?.permissions || [])
    const out = {}
    for (const p of perms) {
      if (!has.has(p.name)) continue
      ;(out[p.module] ||= []).push(p)
    }
    return out
  }
  // Catálogo COMPLETO de permisos agrupado por módulo (para el editor de rol)
  const permGroups = () => {
    const out = {}
    for (const p of perms) (out[p.module] ||= []).push(p)
    return out
  }

  // --- Edición de roles ---
  const blankRole = { name: '', label: '', description: '', permissions: [] }
  const openEditRole = (r) => setRoleForm({ name: r.name, label: r.label, description: r.description || '', permissions: [...r.permissions] })
  const toggleRolePerm = (pname) => setRoleForm((f) => ({
    ...f, permissions: f.permissions.includes(pname) ? f.permissions.filter((x) => x !== pname) : [...f.permissions, pname],
  }))
  const toggleModule = (list) => setRoleForm((f) => {
    const names = list.map((p) => p.name)
    const allOn = names.every((n) => f.permissions.includes(n))
    return { ...f, permissions: allOn ? f.permissions.filter((n) => !names.includes(n)) : [...new Set([...f.permissions, ...names])] }
  })
  const saveRole = async () => {
    if (!roleForm.label.trim()) { toast('El nombre del rol es obligatorio', 'err'); return }
    const r = await api.saveRole(roleForm)
    if (r.ok) { toast(roleForm.name ? 'Rol actualizado' : 'Rol creado'); setRoleForm(null); loadRoles() }
    else toast(r.error || 'Error', 'err')
  }
  const delRole = async (r) => {
    if (!(await confirm({ title: 'Eliminar rol', message: `¿Eliminar el rol «${r.label}»? Esta acción no se puede deshacer.`, danger: true, confirmText: 'Eliminar' }))) return
    const res = await api.deleteRole(r.name)
    if (res.ok) { toast('Rol eliminado'); loadRoles() } else toast(res.error || 'Error', 'err')
  }

  return (
    <>
      <header className="page-head">
        <span className="ic" style={{ width: 30, height: 30, borderRadius: 9, background: 'var(--primary-soft)', display: 'grid', placeItems: 'center' }}><Icon.user style={{ width: 18, height: 18, fill: 'var(--primary)' }} /></span>
        <div><h1>Usuarios</h1></div>
        <span className="sub">· Equipo, roles y permisos</span>
        <div className="spacer" />
        <button className="btn" onClick={() => setForm({ ...blank })}><Icon.plus /> Nuevo usuario</button>
      </header>

      <div className="page-scroll">
        <div className="page" style={{ maxWidth: 860 }}>
          {form && (
            <div className="card" style={{ padding: 18, marginBottom: 16 }}>
              <div className="fb-set-t" style={{ marginBottom: 12 }}>{form.id ? 'Editar usuario' : 'Nuevo usuario'}</div>
              <div className="grid2">
                <label className="field"><span className="lbl">Email <span className="hint">(inicio de sesión)</span></span><input type="email" value={form.email} onChange={(e) => setForm((f) => ({ ...f, email: e.target.value }))} placeholder="maria@aemegroup.com" autoFocus /></label>
                <label className="field"><span className="lbl">Nombre <span className="hint">(visible en el chat)</span></span><input value={form.name} onChange={(e) => setForm((f) => ({ ...f, name: e.target.value }))} placeholder="María García" /></label>
              </div>
              <div className="grid2">
                <label className="field"><span className="lbl">Contraseña {form.id && <span className="hint">(dejar vacío para no cambiarla)</span>}</span><input type="password" value={form.password} onChange={(e) => setForm((f) => ({ ...f, password: e.target.value }))} placeholder={form.id ? '••••••' : 'mín. 6 caracteres'} /></label>
                <div className="field"><span className="lbl">Rol</span>
                  <Select block value={form.role} onChange={(role) => setForm((f) => ({ ...f, role }))} options={roleOptions} />
                  {roleOf(form.role) && (
                    <span className="hint" style={{ marginTop: 6, display: 'block' }}>
                      Módulos: {Object.keys(permsByModule(form.role)).map((m) => modules[m]?.label || m).join(' · ') || '—'}
                    </span>
                  )}
                </div>
              </div>

              {/* Áreas (categorías) que atiende el agente. Solo importa a roles SIN
                  «ver todos»: un encargado/superadmin ve todo igualmente. */}
              {cats.length > 0 && (
                <div className="field" style={{ marginTop: 4 }}>
                  <span className="lbl">Áreas que atiende <span className="hint">(categorías cuyos tickets verá)</span></span>
                  {roleOf(form.role)?.permissions?.includes('tickets.view_all') && (
                    <span className="hint" style={{ display: 'block', marginBottom: 6 }}>
                      Este rol ve <b>todos</b> los tickets; las áreas no le limitan.
                    </span>
                  )}
                  <div className="cat-picker">
                    {cats.map((c) => {
                      const on = form.category_ids.includes(c.id)
                      return (
                        <button key={c.id} type="button" className={`cat-chip ${on ? 'on' : ''}`} onClick={() => toggleCat(c.id)}>
                          <span className="cat-dot" style={{ background: c.color }} />
                          {c.name}
                          {on && <Icon.check style={{ width: 13, height: 13 }} />}
                        </button>
                      )
                    })}
                  </div>
                </div>
              )}

              <div className="add-row" style={{ marginTop: 14 }}>
                <button className="btn" onClick={save}><Icon.save /> Guardar</button>
                <button className="btn ghost" onClick={() => setForm(null)}>Cancelar</button>
              </div>
            </div>
          )}

          {users === null ? <div className="center-load"><div className="spinner" /></div> : (
            <div className="card" style={{ padding: 0 }}>
              {users.map((u) => (
                <div key={u.id} className="usr-row">
                  <span className="pb-avatar">{(u.name || u.email).slice(0, 1).toUpperCase()}</span>
                  <div className="pb-meta">
                    <b>{u.name || u.email}</b>
                    <span className="muted">
                      {u.email}
                      {/* Áreas del agente, si tiene (los roles que ven todo no las necesitan) */}
                      {u.category_ids?.length > 0 && !roleOf(u.role)?.permissions?.includes('tickets.view_all') &&
                        ' · ' + u.category_ids.map((id) => cats.find((c) => c.id === id)?.name).filter(Boolean).join(', ')}
                    </span>
                  </div>
                  <span className={`pill ${roleOf(u.role)?.is_super ? 'ok' : 'gray'} sm`} style={{ marginLeft: 12 }}>{u.role_label || '—'}</span>
                  <span style={{ flex: 1 }} />
                  <button className="icon-btn" title="Editar" onClick={() => setForm({ id: u.id, name: u.name || '', email: u.email || '', role: u.role || defRole, password: '', category_ids: u.category_ids || [] })}><Icon.pencil /></button>
                  <button className="icon-btn" title="Eliminar" style={{ color: 'var(--danger)' }} onClick={() => del(u)}><Icon.trash /></button>
                </div>
              ))}
            </div>
          )}

          {/* Roles y permisos: crear, editar, borrar y asignar permisos */}
          {roles.length > 0 && (
            <>
              <div className="fb-set-t" style={{ margin: '24px 0 10px', display: 'flex', alignItems: 'center', gap: 10 }}>
                <span>Roles y permisos</span>
                <span style={{ flex: 1 }} />
                <button className="btn sm" onClick={() => setRoleForm({ ...blankRole })}><Icon.plus /> Nuevo rol</button>
              </div>

              {roleForm && (
                <div className="card" style={{ padding: 18, marginBottom: 14 }}>
                  <div className="fb-set-t" style={{ marginBottom: 12 }}>{roleForm.name ? 'Editar rol' : 'Nuevo rol'}</div>
                  <div className="grid2">
                    <label className="field"><span className="lbl">Nombre del rol</span><input value={roleForm.label} onChange={(e) => setRoleForm((f) => ({ ...f, label: e.target.value }))} placeholder="p. ej. Supervisor de garantías" autoFocus /></label>
                    <label className="field"><span className="lbl">Descripción <span className="hint">(opcional)</span></span><input value={roleForm.description} onChange={(e) => setRoleForm((f) => ({ ...f, description: e.target.value }))} placeholder="Qué hace este rol" /></label>
                  </div>
                  <div className="field" style={{ marginTop: 6 }}>
                    <span className="lbl">Permisos <span className="hint">({roleForm.permissions.length} marcados)</span></span>
                    <div className="perm-grid">
                      {Object.entries(permGroups()).map(([mod, list]) => {
                        const allOn = list.every((p) => roleForm.permissions.includes(p.name))
                        return (
                          <div key={mod} className="perm-mod">
                            <button type="button" className="perm-mod-h" onClick={() => toggleModule(list)}>
                              <span>{modules[mod]?.label || mod}</span>
                              <span className={`perm-all ${allOn ? 'on' : ''}`}>{allOn ? 'Quitar' : 'Todo'}</span>
                            </button>
                            {list.map((p) => {
                              const on = roleForm.permissions.includes(p.name)
                              return (
                                <button key={p.name} type="button" className={`perm-chk ${on ? 'on' : ''}`} onClick={() => toggleRolePerm(p.name)} title={p.name}>
                                  <span className="perm-box">{on && <Icon.check />}</span>
                                  <span>{p.label}</span>
                                </button>
                              )
                            })}
                          </div>
                        )
                      })}
                    </div>
                  </div>
                  <div className="add-row" style={{ marginTop: 14 }}>
                    <button className="btn" onClick={saveRole}><Icon.save /> Guardar rol</button>
                    <button className="btn ghost" onClick={() => setRoleForm(null)}>Cancelar</button>
                  </div>
                </div>
              )}

              <div className="card" style={{ padding: 0 }}>
                {roles.map((r) => (
                  <div key={r.name} className="role-row">
                    <div className="role-main">
                      <b>{r.label}</b>
                      <span className="muted">{r.description || '—'}</span>
                    </div>
                    <span className="role-count">{r.users_count} {r.users_count === 1 ? 'usuario' : 'usuarios'}</span>
                    <span className="role-count perms">{r.is_super ? 'Todos' : `${r.permissions.length} permisos`}</span>
                    <div className="role-actions">
                      {r.is_super ? (
                        <span className="pill ok sm">Acceso total</span>
                      ) : (
                        <>
                          <button className="icon-btn" title="Editar rol y permisos" onClick={() => openEditRole(r)}><Icon.pencil /></button>
                          <button className="icon-btn" title="Eliminar rol" style={{ color: 'var(--danger)' }} onClick={() => delRole(r)}><Icon.trash /></button>
                        </>
                      )}
                    </div>
                  </div>
                ))}
              </div>
            </>
          )}
        </div>
      </div>
    </>
  )
}
