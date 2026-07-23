import { useState, useEffect } from 'react'
import { api } from '../api.js'
import { Icon } from '../icons.jsx'
import { useToast } from '../App.jsx'

const FIELDS = ['business_name', 'wa_phone_number_id', 'wa_business_id', 'wa_app_id', 'wa_token', 'wa_app_secret', 'wa_verify_token', 'consent_message']

export default function Settings() {
  const toast = useToast()
  const [f, setF] = useState(null)
  const [webhook, setWebhook] = useState('')
  const [saving, setSaving] = useState(false)
  const [testing, setTesting] = useState(false)
  const [conn, setConn] = useState({ state: 'idle', info: null, error: '' })
  const [verified, setVerified] = useState(false)
  const [consentOn, setConsentOn] = useState(true)
  const [sigActive, setSigActive] = useState(false)

  useEffect(() => {
    api.getSettings().then((d) => {
      const init = {}; FIELDS.forEach((k) => (init[k] = d[k] || ''))
      setF(init); setWebhook(d.webhook_url || ''); setVerified(!!d.account_verified); setConsentOn(!!d.consent_enabled)
      setSigActive(!!d.webhook_signature_active)
    })
  }, [])

  const toggleVerified = async (v) => {
    setVerified(v)
    await api.saveSettings({ account_verified: v })
    toast(v ? 'Cuenta marcada como verificada — candados levantados' : 'Candados reactivados')
  }

  const set = (k) => (e) => setF((s) => ({ ...s, [k]: e.target.value }))

  const save = async () => {
    setSaving(true)
    const res = await api.saveSettings({ ...f, consent_enabled: consentOn ? 1 : 0 })
    setSaving(false)
    if (res.ok) setSigActive(!!(f.wa_app_secret || '').trim())
    toast(res.ok ? 'Configuración guardada' : 'Error al guardar', res.ok ? 'ok' : 'err')
  }

  const test = async () => {
    setTesting(true); setConn({ state: 'testing', info: null, error: '' })
    const res = await api.testConnection()
    setTesting(false)
    if (res.ok) { setConn({ state: 'ok', info: res.info, error: '' }); toast('Conexión correcta') }
    else { setConn({ state: 'err', info: null, error: res.error || 'No se pudo conectar' }) }
  }

  const copy = (val) => { navigator.clipboard?.writeText(val); toast('Copiado al portapapeles') }

  if (!f) return <div className="center-load"><div className="spinner" /></div>

  const pill = conn.state === 'ok'
    ? <span className="pill ok"><span className="dot" />Conectado</span>
    : conn.state === 'err'
      ? <span className="pill err"><span className="dot" />Error</span>
      : conn.state === 'testing'
        ? <span className="pill gray"><span className="dot" />Comprobando…</span>
        : <span className="pill gray"><span className="dot" />Sin verificar</span>

  return (
    <>
      <header className="page-head">
        <h1>Configuración</h1>
        <span className="sub">· API de WhatsApp Cloud</span>
        <div className="spacer" />
        {pill}
      </header>
      <div className="page-scroll">
        <div className="page">
          <div className="card">
            <h2>Conexión con Meta</h2>
            <p className="desc">Datos de tu cuenta de WhatsApp Business (Meta for Developers). Se guardan en tu base de datos local.</p>
            <div className="grid2">
              <label className="field">
                <span className="lbl">Nombre del negocio</span>
                <input value={f.business_name} onChange={set('business_name')} />
              </label>
              <label className="field">
                <span className="lbl">ID del número <span className="hint">(phone_number_id)</span></span>
                <input className="mono" value={f.wa_phone_number_id} onChange={set('wa_phone_number_id')} />
              </label>
              <label className="field">
                <span className="lbl">WABA ID <span className="hint">(cuenta de WhatsApp Business)</span></span>
                <input className="mono" value={f.wa_business_id} onChange={set('wa_business_id')} />
              </label>
              <label className="field">
                <span className="lbl">ID de la app <span className="hint">(app_id)</span></span>
                <input className="mono" value={f.wa_app_id} onChange={set('wa_app_id')} />
              </label>
            </div>
            <label className="field">
              <span className="lbl">Token de acceso</span>
              <textarea className="mono" rows={3} value={f.wa_token} onChange={set('wa_token')} />
            </label>
            <label className="field">
              <span className="lbl">App Secret <span className="hint">(Meta → tu App → Configuración → Básica → Clave secreta)</span></span>
              <input className="mono" type="password" value={f.wa_app_secret} onChange={set('wa_app_secret')} placeholder="Necesario para verificar la firma del webhook" />
            </label>
            <div style={{ display: 'flex', gap: 11, alignItems: 'center' }}>
              <button className="btn" disabled={saving} onClick={save}><Icon.save /> {saving ? 'Guardando…' : 'Guardar cambios'}</button>
              <button className="btn ghost" disabled={testing} onClick={test}>Probar conexión</button>
            </div>
          </div>

          <div className="card">
            <h2>Webhook</h2>
            <p className="desc">Configura estos valores en Meta → tu App → WhatsApp → Configuración → Webhook, y suscríbete al campo <b>messages</b>.</p>
            {/* Estado de la verificación de firma: protege el webhook de mensajes falsos */}
            <div className={`wn-banner ${sigActive ? 'ok' : 'warn'}`} style={{ marginBottom: 14 }}>
              <Icon.lock />
              <div>
                <b>{sigActive ? 'Verificación de firma ACTIVA' : 'Verificación de firma INACTIVA'}</b>
                <span>{sigActive
                  ? 'Solo se procesan eventos firmados por Meta (App Secret configurado).'
                  : 'Cualquiera que sepa la URL podría inyectar eventos falsos. Pon el App Secret arriba para activarla antes de producción.'}</span>
              </div>
            </div>
            <label className="field">
              <span className="lbl">Callback URL</span>
              <div className="copybox">
                <input className="mono" readOnly value={webhook} />
                <button className="btn ghost" onClick={() => copy(webhook)}><Icon.copy /></button>
              </div>
              <span className="hint">Debe ser accesible públicamente por HTTPS. En localhost no recibirá eventos de Meta.</span>
            </label>
            <label className="field" style={{ marginBottom: 0 }}>
              <span className="lbl">Verify Token</span>
              <div className="copybox">
                <input className="mono" value={f.wa_verify_token} onChange={set('wa_verify_token')} />
                <button className="btn ghost" onClick={() => copy(f.wa_verify_token)}><Icon.copy /></button>
              </div>
            </label>
          </div>

          <div className="card">
            <h2>Consentimiento (primera vez)</h2>
            <p className="desc">Cuando alguien te escribe <b>por primera vez</b>, el bot le envía este mensaje con los botones <b>Acepto</b> y <b>BAJA</b> antes de continuar. Usa <code>{'{{{senderName}}}'}</code> para el nombre del cliente. Edita <b>[Tu Empresa]</b> y <b>[Enlace a tu web]</b>.</p>
            <label className="fb-req-row" style={{ marginTop: 4, marginBottom: 12 }}>
              <span className="fb-switch"><input type="checkbox" checked={consentOn} onChange={(e) => setConsentOn(e.target.checked)} /><span className={`fb-toggle ${consentOn ? 'on' : ''}`} /></span>
              <span className="fb-req-label">Pedir consentimiento al primer contacto</span>
            </label>
            <label className="field" style={{ marginBottom: 0, opacity: consentOn ? 1 : 0.5 }}>
              <span className="lbl">Mensaje de consentimiento</span>
              <textarea rows={9} value={f.consent_message} onChange={set('consent_message')} disabled={!consentOn} />
            </label>
            <div style={{ marginTop: 11 }}>
              <button className="btn" disabled={saving} onClick={save}><Icon.save /> {saving ? 'Guardando…' : 'Guardar cambios'}</button>
            </div>
          </div>

          <div className="card">
            <h2>Verificación de Meta</h2>
            <p className="desc">Algunas acciones (publicar/enviar formularios nativos, borrar plantillas) están <b>bloqueadas con candado</b> porque la cuenta de prueba de Meta no las permite. Activa esto <b>solo cuando</b> el negocio "Aeme Group" esté verificado en Meta y hayas puesto arriba su WABA real.</p>
            <label className="fb-req-row" style={{ marginTop: 4 }}>
              <span className="fb-switch"><input type="checkbox" checked={verified} onChange={(e) => toggleVerified(e.target.checked)} /><span className={`fb-toggle ${verified ? 'on' : ''}`} /></span>
              <span className="fb-req-label">Cuenta de Meta verificada (levantar candados)</span>
            </label>
            <span className="hint" style={{ display: 'block', marginTop: 8 }}>Esto solo quita los candados de la app. Si la cuenta no cumple de verdad, Meta seguirá devolviendo su propio error al intentar la acción.</span>
          </div>

          <div className="card">
            <h2>Estado de la cuenta</h2>
            <p className="desc">Resultado de la última prueba de conexión.</p>
            {conn.state === 'idle' && <p className="hint">Pulsa «Probar conexión» para consultar los datos del número en Meta.</p>}
            {conn.state === 'err' && <p style={{ color: 'var(--danger)' }}>{conn.error}</p>}
            {conn.state === 'ok' && conn.info && (
              <table style={{ width: '100%', borderCollapse: 'collapse' }}>
                <tbody>
                  {[
                    ['Nombre verificado', conn.info.verified_name],
                    ['Número', conn.info.display_phone_number],
                    ['Plataforma', conn.info.platform_type],
                  ].map(([k, v]) => (
                    <tr key={k}><td style={{ padding: '7px 0', color: 'var(--ink-2)' }}>{k}</td><td style={{ fontWeight: 600 }}>{v || '—'}</td></tr>
                  ))}
                  <tr>
                    <td style={{ padding: '7px 0', color: 'var(--ink-2)' }}>Calidad</td>
                    <td><span className={`pill ${conn.info.quality_rating === 'GREEN' ? 'ok' : conn.info.quality_rating === 'RED' ? 'err' : 'warn'}`}>{conn.info.quality_rating || '—'}</span></td>
                  </tr>
                </tbody>
              </table>
            )}
          </div>
        </div>
      </div>
    </>
  )
}
