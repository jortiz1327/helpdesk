import { useState, useEffect } from 'react'
import { api } from '../api.js'
import { Icon } from '../icons.jsx'
import { useToast } from '../App.jsx'
import WhatsAppNumbers from './WhatsAppNumbers.jsx'

// La conexión con Meta (token, phone_number_id, WABA, App Secret) se configura
// POR NÚMERO en <WhatsAppNumbers>; aquí solo quedan el Verify Token del webhook
// (global) y el mensaje de consentimiento.
const FIELDS = ['business_name', 'wa_verify_token', 'consent_message']

export default function Settings({ embedded = false }) {
  const toast = useToast()
  const [f, setF] = useState(null)
  const [webhook, setWebhook] = useState('')
  const [saving, setSaving] = useState(false)
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
    // El estado de la firma lo decide el backend (App Secret de un número), no
    // un campo de este formulario: se refleja tal cual al cargar la pantalla.
    toast(res.ok ? 'Configuración guardada' : 'Error al guardar', res.ok ? 'ok' : 'err')
  }

  const copy = (val) => { navigator.clipboard?.writeText(val); toast('Copiado al portapapeles') }

  if (!f) return <div className="center-load"><div className="spinner" /></div>

  return (
    <>
      {/* Cabecera propia solo como pantalla independiente; embebido en Configuración
          de Soporte va sin ella (la pone el contenedor). */}
      {!embedded && (
        <header className="page-head">
          <h1>Configuración</h1>
          <span className="sub">· API de WhatsApp Cloud</span>
          <div className="spacer" />
        </header>
      )}
      <div className={embedded ? '' : 'page-scroll'}>
        <div className={embedded ? '' : 'page'}>
          {/* Opción B: los números y su función (enrutado por phone_number_id).
              Aquí se configura TODO (token, App Secret, WABA…) por número; el viejo
              bloque global «Conexión con Meta» se retiró por redundante. */}
          <WhatsAppNumbers />

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
                <input className="mono" readOnly value={f.wa_verify_token} />
                <button className="btn ghost" onClick={() => copy(f.wa_verify_token)}><Icon.copy /></button>
              </div>
              <span className="hint">Se genera solo. Pégalo tal cual en Meta → Webhook → «Identificador de verificación».</span>
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
            <p className="desc">Algunas acciones (publicar/enviar formularios nativos, borrar plantillas) están <b>bloqueadas con candado</b> porque la cuenta de prueba de Meta no las permite. Activa esto <b>solo cuando</b> {f.business_name ? `el negocio "${f.business_name}"` : 'tu negocio'} esté verificado en Meta y hayas puesto arriba su WABA real.</p>
            <label className="fb-req-row" style={{ marginTop: 4 }}>
              <span className="fb-switch"><input type="checkbox" checked={verified} onChange={(e) => toggleVerified(e.target.checked)} /><span className={`fb-toggle ${verified ? 'on' : ''}`} /></span>
              <span className="fb-req-label">Cuenta de Meta verificada (levantar candados)</span>
            </label>
            <span className="hint" style={{ display: 'block', marginTop: 8 }}>Esto solo quita los candados de la app. Si la cuenta no cumple de verdad, Meta seguirá devolviendo su propio error al intentar la acción.</span>
          </div>
        </div>
      </div>
    </>
  )
}
