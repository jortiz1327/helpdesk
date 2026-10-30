import { useState, useEffect, useCallback } from 'react'
import { api } from '../api.js'
import { Icon } from '../icons.jsx'
import { useToast, useConfirm } from '../App.jsx'
import InfoTip from './InfoTip.jsx'

/* -------------------------------------------------------------------------
 * SERVIDORES ZKONG (Gestión de garantías → Ajustes). Solo superadmin.
 * Cada servidor es una cuenta de ADMINISTRADOR de la nube de etiquetas: con ella
 * el Centro de dispositivos lista y busca etiquetas y antenas. La contraseña se
 * guarda cifrada y no vuelve nunca al navegador; «Probar» dice si entra.
 * ---------------------------------------------------------------------- */

const blank = { id: 0, name: '', base_url: 'http://', username: '', password: '', active: true }

export default function ZkongServers() {
  const toast = useToast()
  const confirm = useConfirm()
  const [rows, setRows] = useState(null)
  const [form, setForm] = useState(null)
  const [saving, setSaving] = useState(false)
  const [testing, setTesting] = useState(0)

  const load = useCallback(() => { api.zkongServers().then((d) => setRows(d.servers || [])) }, [])
  useEffect(() => { load() }, [load])

  const abrir = (s) => setForm(s ? { ...blank, ...s, password: '' } : { ...blank })

  const save = async () => {
    if (!form.name.trim() || !form.username.trim()) { toast('Pon un nombre y el usuario', 'err'); return }
    if (!form.id && !form.password) { toast('Falta la contraseña', 'err'); return }
    setSaving(true)
    const payload = { ...form }
    if (form.id && !form.password) delete payload.password
    const r = await api.saveZkongServer(payload)
    setSaving(false)
    if (r.ok) { toast(form.id ? 'Servidor actualizado' : 'Servidor añadido'); setForm(null); load() }
    else toast(r.error || 'Error', 'err')
  }

  const del = async (s) => {
    if (!(await confirm({ title: 'Quitar servidor', message: `¿Quitar «${s.name}»? El Centro de dispositivos dejará de consultarlo.`, danger: true, confirmText: 'Quitar' }))) return
    const r = await api.deleteZkongServer(s.id)
    if (r.ok) { toast('Servidor quitado'); load() } else toast(r.error || 'Error', 'err')
  }

  const probar = async (s) => {
    setTesting(s.id)
    const r = await api.testZkongServer(s.id)
    setTesting(0)
    if (r.ok) { toast(`Conecta ✓ ${s.name}`); load() }
    else toast(r.error || 'No se pudo conectar', 'err')
  }

  return (
    <>
      <header className="page-head">
        <span className="sc-ic"><Icon.settings style={{ width: 18, height: 18, fill: 'var(--primary)' }} /></span>
        <div><h1>Servidores ZKONG</h1></div>
        <span className="sub">· Cuentas que usa el Centro de dispositivos</span>
      </header>

      <div className="page-scroll"><div className="page" style={{ maxWidth: 820 }}>
        <div className="card">
          <div className="wa-num-head">
            <div>
              <h2>Servidores</h2>
              <p className="desc" style={{ margin: '2px 0 0' }}>Una cuenta de administrador por servidor de etiquetas. La contraseña se guarda cifrada y no se vuelve a mostrar.</p>
            </div>
            <button className="btn" onClick={() => abrir(null)}><Icon.plus /> Añadir servidor</button>
          </div>

          {rows === null ? <div className="center-load"><div className="spinner" /></div> : rows.length === 0 ? (
            <div className="wa-num-empty">
              <Icon.shield style={{ width: 30, height: 30, fill: 'var(--ink-3)' }} />
              <p>Aún no hay servidores. Añade los de etiquetas (por ejemplo <b>APP01</b> y <b>APP02</b>) para ver sus dispositivos.</p>
            </div>
          ) : (
            <div className="wa-num-list">
              {rows.map((s) => (
                <div key={s.id} className="wa-num" style={{ '--fc': '#f59e0b' }}>
                  <span className="wa-num-ic"><Icon.shield style={{ width: 18, height: 18, fill: '#f59e0b' }} /></span>
                  <div className="wa-num-body">
                    <div className="wa-num-top">
                      <b>{s.name}</b>
                      {!s.active && <span className="wa-num-off">Inactivo</span>}
                      {s.token_updated_at
                        ? <span className="wa-num-env real" title={`Última entrada: ${s.token_updated_at}`}>Conectado</span>
                        : <span className="wa-num-env prueba">Sin probar</span>}
                    </div>
                    <div className="wa-num-sub"><code>{s.base_url}</code> · {s.username}</div>
                  </div>
                  <div className="wa-num-acts">
                    <button className="btn ghost sm" disabled={testing === s.id} onClick={() => probar(s)}>{testing === s.id ? 'Probando…' : 'Probar'}</button>
                    <button className="icon-btn" title="Editar" onClick={() => abrir(s)}><Icon.pencil /></button>
                    <button className="icon-btn" title="Quitar" style={{ color: 'var(--danger)' }} onClick={() => del(s)}><Icon.trash /></button>
                  </div>
                </div>
              ))}
            </div>
          )}
        </div>
      </div></div>

      {form && (
        <div className="modal-bg" onMouseDown={(e) => e.target.classList.contains('modal-bg') && setForm(null)}>
          <div className="modal" style={{ maxWidth: 540 }}>
            <div className="modal-head">
              <h3>{form.id ? 'Editar servidor' : 'Añadir servidor ZKONG'}</h3>
              <button className="x" onClick={() => setForm(null)}>×</button>
            </div>
            <div className="modal-body">
              <div className="grid2">
                <label className="field"><span className="lbl">Nombre <em>*</em> <InfoTip text="Cómo se verá en el Centro de dispositivos, p. ej. «APP02»." /></span>
                  <input value={form.name} onChange={(e) => setForm((f) => ({ ...f, name: e.target.value }))} placeholder="APP02" autoFocus /></label>
                <label className="field"><span className="lbl">Usuario <em>*</em></span>
                  <input value={form.username} onChange={(e) => setForm((f) => ({ ...f, username: e.target.value }))} autoComplete="off" /></label>
              </div>
              <label className="field"><span className="lbl">Dirección del servidor <em>*</em> <InfoTip text="La dirección base de la API, con su puerto. La misma que tiene la cuenta en la app de garantías." wide /></span>
                <input className="mono" value={form.base_url} onChange={(e) => setForm((f) => ({ ...f, base_url: e.target.value }))} placeholder="http://app02.etiquetaselectronicas.com:9999" /></label>
              <label className="field"><span className="lbl">Contraseña {form.id ? <span className="hint">(vacío = no cambiar)</span> : <em>*</em>}</span>
                <input type="password" value={form.password} onChange={(e) => setForm((f) => ({ ...f, password: e.target.value }))} placeholder={form.id ? '••••••••' : ''} autoComplete="new-password" /></label>
              <label className="fb-req-row" style={{ marginTop: 2 }}>
                <span className="fb-switch"><input type="checkbox" checked={form.active} onChange={(e) => setForm((f) => ({ ...f, active: e.target.checked }))} /><span className={`fb-toggle ${form.active ? 'on' : ''}`} /></span>
                <span className="fb-req-label">Activo <span className="hint">· si no, el Centro de dispositivos no lo consulta</span></span>
              </label>
            </div>
            <div className="modal-foot">
              <button className="btn ghost" onClick={() => setForm(null)}>Cancelar</button>
              <button className="btn" disabled={saving} onClick={save}>{saving ? 'Guardando…' : (form.id ? 'Guardar' : 'Añadir')}</button>
            </div>
          </div>
        </div>
      )}
    </>
  )
}
