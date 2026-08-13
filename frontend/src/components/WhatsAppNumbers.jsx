import { useState, useEffect, useCallback } from 'react'
import { api } from '../api.js'
import { Icon } from '../icons.jsx'
import { useToast, useConfirm } from '../App.jsx'
import InfoTip from './InfoTip.jsx'

/* -------------------------------------------------------------------------
 * NÚMEROS DE WHATSAPP (opción B). Un webhook, varios números; cada mensaje se
 * enruta por su `phone_number_id` a su FUNCIÓN (Soporte → tickets · Campañas →
 * chat/flujos). Cada campo lleva su pista (?) para poder configurarlo a mano.
 * ---------------------------------------------------------------------- */

const FUNC = { soporte: { label: 'Helpdesk / Soporte', color: '#2563eb', icon: Icon.headset, hint: 'crea tickets' },
               campanas: { label: 'Campañas',          color: '#12925a', icon: Icon.send,    hint: 'chat en vivo / flujos' } }
const funcOf = (k) => FUNC[k] || FUNC.soporte

const blank = { id: 0, label: '', phone_number_id: '', funcion: 'soporte', entorno: 'prueba', token: '', app_secret: '', waba_id: '', app_id: '', active: true }

export default function WhatsAppNumbers() {
  const toast = useToast()
  const confirm = useConfirm()
  const [rows, setRows] = useState(null)
  const [form, setForm] = useState(null)
  const [saving, setSaving] = useState(false)
  const [testing, setTesting] = useState(0)

  const load = useCallback(() => { api.waNumbers().then((d) => setRows(d.numbers || [])) }, [])
  useEffect(() => { load() }, [load])

  const abrir = (n) => setForm(n
    ? { ...blank, ...n, token: '', app_secret: '', active: !!Number(n.active) }   // no se re-muestran secretos
    : { ...blank, position: (rows?.length || 0) + 1 })

  const save = async () => {
    if (!form.label.trim()) { toast('Ponle una etiqueta', 'err'); return }
    if (!form.phone_number_id.trim()) { toast('Falta el ID del número (phone_number_id)', 'err'); return }
    setSaving(true)
    // Token/App Secret: solo se mandan si el usuario escribió algo (para no borrarlos al editar).
    const payload = { ...form }
    if (form.id && !form.token) delete payload.token
    if (form.id && !form.app_secret) delete payload.app_secret
    const r = await api.saveWaNumber(payload)
    setSaving(false)
    if (r.ok) { toast(form.id ? 'Número actualizado' : 'Número añadido'); setForm(null); load() }
    else toast(r.error || 'Error', 'err')
  }

  const del = async (n) => {
    if (!(await confirm({ title: 'Quitar número', message: `¿Quitar «${n.label}»? Dejará de enrutar sus mensajes.`, danger: true, confirmText: 'Quitar' }))) return
    const r = await api.deleteWaNumber(n.id)
    if (r.ok) { toast('Número quitado'); load() } else toast(r.error || 'Error', 'err')
  }

  const probar = async (n) => {
    setTesting(n.id)
    const r = await api.testWaNumber(n.id)
    setTesting(0)
    if (r.ok) { toast(`Conecta ✓ ${r.info?.display_phone_number || ''}`); load() }
    else toast(r.error || 'No se pudo conectar', 'err')
  }

  return (
    <div className="card">
      <div className="wa-num-head">
        <div>
          <h2>Números de WhatsApp</h2>
          <p className="desc" style={{ margin: '2px 0 0' }}>Cada número entrante se enruta a su función por su <code>phone_number_id</code>. Un número que no esté aquí no crea nada.</p>
        </div>
        <button className="btn" onClick={() => abrir(null)}><Icon.plus /> Añadir número</button>
      </div>

      {rows === null ? <div className="center-load"><div className="spinner" /></div> : rows.length === 0 ? (
        <div className="wa-num-empty">
          <Icon.message style={{ width: 30, height: 30, fill: 'var(--ink-3)' }} />
          <p>Aún no hay números. Añade el de <b>Soporte</b> (al menos el de prueba de Meta) para recibir tickets por WhatsApp.</p>
        </div>
      ) : (
        <div className="wa-num-list">
          {rows.map((n) => {
            const fn = funcOf(n.funcion)
            const Ic = fn.icon
            return (
              <div key={n.id} className="wa-num" style={{ '--fc': fn.color }}>
                <span className="wa-num-ic"><Ic style={{ width: 18, height: 18, fill: fn.color }} /></span>
                <div className="wa-num-body">
                  <div className="wa-num-top">
                    <b>{n.label}</b>
                    <span className="wa-num-func">{fn.label}</span>
                    <span className={`wa-num-env ${n.entorno === 'real' ? 'real' : 'prueba'}`}>{n.entorno === 'real' ? 'Real' : 'Pruebas'}</span>
                    {!Number(n.active) && <span className="wa-num-off">Inactivo</span>}
                  </div>
                  <div className="wa-num-sub">
                    <code>{n.phone_number_id}</code>
                    {n.display_number && <span> · {n.display_number}</span>}
                  </div>
                </div>
                <div className="wa-num-acts">
                  <button className="btn ghost sm" disabled={testing === n.id} onClick={() => probar(n)}>
                    {testing === n.id ? 'Probando…' : 'Probar'}
                  </button>
                  <button className="icon-btn" title="Editar" onClick={() => abrir(n)}><Icon.pencil /></button>
                  <button className="icon-btn" title="Quitar" style={{ color: 'var(--danger)' }} onClick={() => del(n)}><Icon.trash /></button>
                </div>
              </div>
            )
          })}
        </div>
      )}

      {form && (
        <div className="modal-bg" onMouseDown={(e) => e.target.classList.contains('modal-bg') && setForm(null)}>
          <div className="modal" style={{ maxWidth: 560 }}>
            <div className="modal-head"><h3>{form.id ? 'Editar número' : 'Añadir número de WhatsApp'}</h3><button className="x" onClick={() => setForm(null)}>×</button></div>
            <div className="modal-body">
              <div className="grid2">
                <label className="field"><span className="lbl">Etiqueta <em>*</em> <InfoTip text="Un nombre para reconocerlo, p. ej. «Soporte» o «Campañas»." /></span>
                  <input value={form.label} onChange={(e) => setForm((f) => ({ ...f, label: e.target.value }))} placeholder="Soporte" autoFocus /></label>
                <label className="field"><span className="lbl">Función <InfoTip text="A qué módulo van los mensajes de este número. Soporte crea tickets; Campañas alimenta el chat en vivo y los flujos." /></span>
                  <select value={form.funcion} onChange={(e) => setForm((f) => ({ ...f, funcion: e.target.value }))}>
                    <option value="soporte">Helpdesk / Soporte</option>
                    <option value="campanas">Campañas</option>
                  </select></label>
              </div>

              <div className="grid2">
                <label className="field"><span className="lbl">Entorno <InfoTip text="«Pruebas» = el número gratis que da Meta (solo escribe a destinatarios que registres en la app). «Real» = un número verificado de producción, para escribir a cualquier cliente." /></span>
                  <select value={form.entorno} onChange={(e) => setForm((f) => ({ ...f, entorno: e.target.value }))}>
                    <option value="prueba">Número de pruebas de Meta</option>
                    <option value="real">Número real (producción)</option>
                  </select></label>
                <label className="field"><span className="lbl">ID del número <InfoTip text="El phone_number_id. En Meta → tu App → WhatsApp → Configuración de la API. OJO: es el ID, no el número de teléfono en sí." /></span>
                  <input className="mono" value={form.phone_number_id} onChange={(e) => setForm((f) => ({ ...f, phone_number_id: e.target.value }))} placeholder="1186960007840194" /></label>
              </div>

              <label className="field"><span className="lbl">Token de acceso {form.id ? <span className="hint">(vacío = no cambiar)</span> : null} <InfoTip text="El token de la app (Meta → WhatsApp → Configuración de la API) o un token permanente de Usuario del Sistema. Vale para todos los números de su WABA." wide /></span>
                <textarea className="mono" rows={2} value={form.token} onChange={(e) => setForm((f) => ({ ...f, token: e.target.value }))} placeholder={form.id ? '••••••••  (déjalo vacío para conservar el actual)' : 'EAAV…'} /></label>

              <div className="grid2">
                <label className="field"><span className="lbl">App Secret {form.id ? <span className="hint">(vacío = no cambiar)</span> : null} <InfoTip text="Meta → tu App → Configuración → Básica → Clave secreta. Sirve para verificar la firma del webhook (que los eventos vienen de Meta). Recomendado antes de producción." wide /></span>
                  <input className="mono" type="password" value={form.app_secret} onChange={(e) => setForm((f) => ({ ...f, app_secret: e.target.value }))} placeholder={form.id ? '••••••••' : 'Clave secreta de la app'} /></label>
                <label className="field"><span className="lbl">WABA ID <span className="hint">(opcional)</span> <InfoTip text="ID de la cuenta de WhatsApp Business. Informativo." /></span>
                  <input className="mono" value={form.waba_id} onChange={(e) => setForm((f) => ({ ...f, waba_id: e.target.value }))} placeholder="106735…" /></label>
              </div>

              <label className="fb-req-row" style={{ marginTop: 2 }}>
                <span className="fb-switch"><input type="checkbox" checked={form.active} onChange={(e) => setForm((f) => ({ ...f, active: e.target.checked }))} /><span className={`fb-toggle ${form.active ? 'on' : ''}`} /></span>
                <span className="fb-req-label">Activo <span className="hint">· si no, deja de enrutar sus mensajes</span></span>
              </label>
            </div>
            <div className="modal-foot">
              <button className="btn ghost" onClick={() => setForm(null)}>Cancelar</button>
              <button className="btn" disabled={saving} onClick={save}>{saving ? 'Guardando…' : (form.id ? 'Guardar' : 'Añadir')}</button>
            </div>
          </div>
        </div>
      )}
    </div>
  )
}
