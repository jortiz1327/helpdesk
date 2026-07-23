import { useState } from 'react'
import { api } from '../api.js'
import { Icon } from '../icons.jsx'
import { useToast } from '../App.jsx'

export default function Account({ user, onAccountChange }) {
  const toast = useToast()
  const [a, setA] = useState({ email: user?.email || '', current: '', new_password: '', confirm: '' })
  const [busy, setBusy] = useState(false)
  const set = (k) => (e) => setA((s) => ({ ...s, [k]: e.target.value }))

  const submit = async () => {
    if (!a.current) { toast('Introduce tu contraseña actual', 'err'); return }
    if (a.new_password && a.new_password !== a.confirm) { toast('Las contraseñas nuevas no coinciden', 'err'); return }
    setBusy(true)
    const res = await api.changeAccount({ email: a.email, current: a.current, new_password: a.new_password })
    setBusy(false)
    if (res.ok) {
      toast('Datos de acceso actualizados')
      onAccountChange?.({ email: a.email || user.email })
      setA((s) => ({ ...s, current: '', new_password: '', confirm: '' }))
    } else toast(res.error || 'No se pudo actualizar', 'err')
  }

  return (
    <>
      <header className="page-head">
        <h1>Cuenta</h1>
        <span className="sub">· Tus datos de acceso</span>
      </header>
      <div className="page-scroll">
        <div className="page" style={{ maxWidth: 760 }}>
          <div className="card" style={{ marginBottom: 0 }}>
            <h2>Cuenta y acceso</h2>
            <p className="desc">Cambia tu email o contraseña de inicio de sesión. Necesitas tu contraseña actual para confirmar.</p>
            <div className="grid2">
              <label className="field">
                <span className="lbl">Email <span className="hint">(es con lo que inicias sesión)</span></span>
                <input type="email" value={a.email} onChange={set('email')} autoComplete="email" />
              </label>
              <label className="field">
                <span className="lbl">Contraseña actual</span>
                <input type="password" value={a.current} onChange={set('current')} placeholder="••••••••" autoComplete="current-password" />
              </label>
              <label className="field">
                <span className="lbl">Nueva contraseña <span className="hint">(opcional)</span></span>
                <input type="password" value={a.new_password} onChange={set('new_password')} placeholder="mín. 6 caracteres" autoComplete="new-password" />
              </label>
              <label className="field">
                <span className="lbl">Repetir nueva contraseña</span>
                <input type="password" value={a.confirm} onChange={set('confirm')} autoComplete="new-password" />
              </label>
            </div>
            <button className="btn" disabled={busy} onClick={submit}><Icon.lock /> {busy ? 'Guardando…' : 'Actualizar acceso'}</button>
          </div>
        </div>
      </div>
    </>
  )
}
