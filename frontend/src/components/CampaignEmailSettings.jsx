import { useState, useEffect } from 'react'
import { api } from '../api.js'
import { Icon } from '../icons.jsx'
import { useToast } from '../App.jsx'
import Select from './Select.jsx'

/* ---------------------------------------------------------------------------
 * Correo de CAMPAÑAS (remitente SMTP aparte del buzón de soporte).
 *
 * A propósito NO es el mismo correo que el de soporte: las respuestas de una
 * campaña NO deben convertirse en tickets. Por eso esta cuenta es SOLO SALIENTE
 * (SMTP) —sin IMAP, sin cuarentena— y usa `funcion=campanas`.
 * ------------------------------------------------------------------------- */
const FUNC = 'campanas'

export default function CampaignEmailSettings() {
  const toast = useToast()
  const [f, setF] = useState(null)
  const [saving, setSaving] = useState(false)
  const [testing, setTesting] = useState(false)
  const [result, setResult] = useState(null)   // { smtp }
  const [diag, setDiag] = useState({ to: '', subject: 'Prueba de campañas por correo', body: '' })
  const [sending, setSending] = useState(false)
  const [diagRes, setDiagRes] = useState(null)

  useEffect(() => {
    api.getEmailAccount(FUNC).then((d) => {
      const a = d.account || {}
      setF({
        email: a.email || '', from_name: a.from_name || '', active: a.active !== false,
        smtp_host: a.smtp_host || '', smtp_port: a.smtp_port || 465,
        smtp_encryption: a.smtp_encryption || 'ssl', smtp_user: a.smtp_user || '', smtp_password: '',
        has_smtp_password: !!a.has_smtp_password,
      })
    })
  }, [])

  if (!f) return <div className="center-load"><div className="spinner" /></div>
  const set = (k) => (e) => setF((s) => ({ ...s, [k]: e.target.value }))
  const encOpts = [{ value: 'ssl', label: 'SSL/TLS' }, { value: 'tls', label: 'STARTTLS' }, { value: 'none', label: 'Ninguno' }]

  const save = async () => {
    setSaving(true)
    const r = await api.saveEmailAccount(f, FUNC)
    setSaving(false)
    if (r.ok) { toast('Remitente de campañas guardado'); setF((s) => ({ ...s, has_smtp_password: s.smtp_password ? true : s.has_smtp_password, smtp_password: '' })) }
    else toast(r.error || 'Error al guardar', 'err')
  }
  const test = async () => {
    setTesting(true); setResult(null)
    const r = await api.testEmailAccount(f, FUNC)
    setTesting(false)
    if (r.ok) setResult({ smtp: r.smtp }); else toast(r.error || 'Error', 'err')
  }
  const sendDiag = async () => {
    if (!diag.to.trim()) { toast('Indica a quién enviarlo', 'err'); return }
    setSending(true); setDiagRes(null)
    const r = await api.sendTestEmail(diag, FUNC)
    setSending(false); setDiagRes(r)
    if (r.ok) toast(`Correo enviado a ${r.to}`); else toast(r.error || 'No se pudo enviar', 'err')
  }
  const badge = (res) => !res ? null
    : res.ok ? <span className="pill ok sm"><span className="dot" />Conecta</span>
    : <span className="pill err sm" title={res.error}><span className="dot" />{(res.error || 'Error').slice(0, 44)}</span>

  return (
    <>
      <header className="page-head">
        <span className="ic" style={{ width: 30, height: 30, borderRadius: 9, background: 'var(--primary-soft)', display: 'grid', placeItems: 'center' }}><Icon.mail style={{ width: 17, height: 17, fill: 'var(--primary)' }} /></span>
        <div><h1>Correo de campañas</h1></div>
        <span className="sub">· Remitente SMTP para las campañas por correo</span>
        <div className="spacer" />
      </header>

      <div className="page-scroll">
        <div className="page" style={{ maxWidth: 860 }}>
          <div className="card" style={{ background: 'var(--primary-soft)', borderColor: 'transparent' }}>
            <p className="desc" style={{ margin: 0 }}>
              Las campañas por correo salen desde <b>esta</b> dirección, <b>no</b> desde el buzón de soporte.
              Así, si alguien responde a una campaña, su respuesta <b>no</b> se convierte en un ticket.
              Es una cuenta <b>solo de envío</b> (SMTP): no hace falta configurar entrada.
            </p>
          </div>

          <div className="card">
            <h2>Remitente</h2>
            <p className="desc">La dirección y el nombre con los que llegan las campañas. La contraseña se guarda <b>cifrada</b>.</p>
            <div className="grid2">
              <label className="field"><span className="lbl">Dirección de correo</span><input type="email" value={f.email} onChange={set('email')} placeholder="campanas@tudominio.com" /></label>
              <label className="field"><span className="lbl">Nombre visible <span className="hint">(el «De:»)</span></span><input value={f.from_name} onChange={set('from_name')} placeholder="Aeme Group" /></label>
            </div>
            <label className="fb-req-row" style={{ marginTop: 4 }}>
              <span className="fb-switch"><input type="checkbox" checked={f.active} onChange={(e) => setF((s) => ({ ...s, active: e.target.checked }))} /><span className={`fb-toggle ${f.active ? 'on' : ''}`} /></span>
              <span className="fb-req-label">Remitente de campañas activo</span>
            </label>
          </div>

          <div className="card">
            <h2>Saliente (SMTP)</h2>
            <p className="desc">Por aquí salen los correos de las campañas.</p>
            <div className="grid2">
              <label className="field"><span className="lbl">Host</span><input className="mono" value={f.smtp_host} onChange={set('smtp_host')} placeholder="smtp.tudominio.com" /></label>
              <div className="grid2">
                <label className="field"><span className="lbl">Puerto</span><input className="mono" value={f.smtp_port} onChange={set('smtp_port')} /></label>
                <div className="field"><span className="lbl">Cifrado</span><Select block value={f.smtp_encryption} onChange={(v) => setF((s) => ({ ...s, smtp_encryption: v }))} options={encOpts} /></div>
              </div>
            </div>
            <div className="grid2">
              <label className="field"><span className="lbl">Usuario</span><input className="mono" value={f.smtp_user} onChange={set('smtp_user')} placeholder="campanas@tudominio.com" /></label>
              <label className="field"><span className="lbl">Contraseña {f.has_smtp_password && <span className="hint">(guardada · vacío = no cambiar)</span>}</span><input type="password" value={f.smtp_password} onChange={set('smtp_password')} placeholder={f.has_smtp_password ? '••••••••' : ''} /></label>
            </div>
          </div>

          <div style={{ display: 'flex', gap: 11, alignItems: 'center', flexWrap: 'wrap' }}>
            <button className="btn" disabled={saving} onClick={save}><Icon.save /> {saving ? 'Guardando…' : 'Guardar cambios'}</button>
            <button className="btn ghost" disabled={testing} onClick={test}>{testing ? 'Probando…' : 'Probar conexión'}</button>
            {result && <span className="em-test">SMTP: {badge(result.smtp)}</span>}
          </div>

          <div className="card em-card" style={{ marginTop: 18 }}>
            <h2>Diagnóstico</h2>
            <p className="em-desc">Envía un correo real para comprobar que la salida funciona de punta a punta.</p>
            <div className="grid2">
              <label className="field"><span className="lbl">De</span><input value={f.email || '(sin remitente configurado)'} disabled /></label>
              <label className="field"><span className="lbl">Para <em>*</em></span><input type="email" value={diag.to} onChange={(e) => setDiag((s) => ({ ...s, to: e.target.value }))} placeholder="tu-correo@dominio.com" /></label>
            </div>
            <label className="field"><span className="lbl">Asunto</span><input value={diag.subject} onChange={(e) => setDiag((s) => ({ ...s, subject: e.target.value }))} /></label>
            <label className="field"><span className="lbl">Mensaje</span><textarea rows={4} value={diag.body} onChange={(e) => setDiag((s) => ({ ...s, body: e.target.value }))} placeholder="Si lo dejas vacío se envía un texto de prueba." /></label>
            <div style={{ display: 'flex', gap: 11, alignItems: 'center', flexWrap: 'wrap' }}>
              <button className="btn ghost" disabled={sending} onClick={sendDiag}><Icon.send /> {sending ? 'Enviando…' : 'Enviar correo de prueba'}</button>
              {diagRes && <span className="em-test">{diagRes.ok ? <span className="pill ok">Enviado a {diagRes.to}</span> : <span className="pill err">{diagRes.error}</span>}</span>}
            </div>
          </div>
        </div>
      </div>
    </>
  )
}
