import { useState, useEffect } from 'react'
import { Icon } from '../icons.jsx'
import { useToast } from '../App.jsx'
import { api } from '../api.js'
import { getNotify, setNotify, notifySupported, notifyPermission, requestNotifyPermission, fireNotification } from '../notify.js'

function Toggle({ on, onChange, disabled }) {
  return (
    <label className="fb-req-row" style={{ marginTop: 0 }}>
      <span className="fb-switch"><input type="checkbox" checked={on} disabled={disabled} onChange={(e) => onChange(e.target.checked)} /><span className={`fb-toggle ${on ? 'on' : ''}`} /></span>
    </label>
  )
}

// Avisos por CORREO que cada agente puede activar/desactivar para sí. Son las
// plantillas globales internas; el interruptor de aquí es un opt-out personal.
const MAIL_PREFS = [
  ['notify_assigned', 'Se te asigna un ticket', 'Cuando un ticket pasa a estar a tu nombre'],
  ['notify_reply', 'El cliente responde tu ticket', 'Cuando el cliente contesta un ticket que tienes asignado'],
]

export default function WebNotifications({ user, onUser }) {
  const toast = useToast()
  const [s, setS] = useState(getNotify())
  const [perm, setPerm] = useState(notifyPermission())
  // Preferencias de correo del propio usuario (vienen en auth.user).
  const [mail, setMail] = useState({
    notify_assigned: user?.notify_assigned !== false,
    notify_reply: user?.notify_reply !== false,
    notify_sla: user?.notify_sla !== false,
  })
  const guardarMail = async (key, val) => {
    const next = { ...mail, [key]: val }
    setMail(next)
    const r = await api.saveNotifyPrefs(next)
    if (r?.ok) { onUser?.(r.user); toast(val ? 'Aviso activado' : 'Aviso desactivado') }
    else { setMail(mail); toast(r?.error || 'No se pudo guardar', 'err') }
  }

  useEffect(() => {
    const h = () => { setS(getNotify()); setPerm(notifyPermission()) }
    window.addEventListener('notify-change', h)
    return () => window.removeEventListener('notify-change', h)
  }, [])

  const supported = notifySupported()

  // Activa/desactiva una opción; al activar la primera vez, pide permiso al navegador
  const update = async (patch) => {
    const turningOn = Object.values(patch).some((v) => v === true)
    if (turningOn && supported && Notification.permission !== 'granted') {
      const p = await requestNotifyPermission()
      setPerm(p)
      if (p !== 'granted') {
        toast(p === 'denied' ? 'Permiso de notificaciones bloqueado en el navegador' : 'Permiso no concedido', 'err')
        return
      }
    }
    setS(setNotify(patch))
  }

  const test = () => {
    if (perm !== 'granted' || !s.enabled) { toast('Activa primero los avisos web', 'err'); return }
    fireNotification('WhatsApp Business', 'Esto es una notificación de prueba ✅')
    toast('Notificación enviada')
  }

  const banner = !supported
    ? { cls: 'err', t: 'Tu navegador no admite notificaciones web.', d: 'Prueba con Chrome, Edge o Firefox de escritorio.' }
    : perm === 'granted'
      ? { cls: 'ok', t: 'Permiso de notificaciones concedido.', d: 'Recibirás avisos según los interruptores de abajo.' }
      : perm === 'denied'
        ? { cls: 'err', t: 'Notificaciones bloqueadas en el navegador.', d: 'Actívalas en el candado de la barra de direcciones → Notificaciones → Permitir.' }
        : { cls: 'info', t: 'Permiso de notificaciones sin configurar.', d: 'Activa cualquier interruptor de abajo y el navegador te pedirá permiso.' }

  const master = !!s.enabled
  const lockSub = !master || perm !== 'granted'

  return (
    <>
      <header className="page-head">
        <span className="ic" style={{ width: 30, height: 30, borderRadius: 9, background: 'var(--primary-soft)', display: 'grid', placeItems: 'center' }}><Icon.bell style={{ width: 17, height: 17, fill: 'var(--primary)' }} /></span>
        <div><h1>Notificaciones</h1></div>
        <span className="sub">· Avisos del navegador y por correo</span>
        <div className="spacer" />
        <button className="btn ghost" onClick={test}><Icon.bell /> Probar</button>
      </header>

      <div className="page-scroll">
        <div className="page" style={{ maxWidth: 820 }}>
          <div className={`wn-banner ${banner.cls}`}>
            <Icon.bell />
            <div><b>{banner.t}</b><span>{banner.d}</span></div>
          </div>

          <div className="wn-row">
            <span className="wn-ico"><Icon.bell /></span>
            <div className="wn-meta"><b>Activar avisos web</b><span>Interruptor maestro de las notificaciones del navegador en este dispositivo</span></div>
            <Toggle on={master} disabled={!supported} onChange={(v) => update({ enabled: v })} />
          </div>

          <div className={`wn-row ${lockSub ? 'locked' : ''}`}>
            <span className="wn-ico"><Icon.message /></span>
            <div className="wn-meta"><b>Mensajes nuevos del Inbox</b><span>Avisa cuando llega un mensaje y la app no está en primer plano</span></div>
            <Toggle on={!!s.messages} disabled={lockSub} onChange={(v) => update({ messages: v })} />
          </div>

          <div className={`wn-row ${lockSub ? 'locked' : ''}`}>
            <span className="wn-ico"><Icon.bell /></span>
            <div className="wn-meta"><b>Sonido</b><span>Reproduce un pitido corto junto al aviso</span></div>
            <Toggle on={!!s.sound} disabled={lockSub} onChange={(v) => update({ sound: v })} />
          </div>

          <p className="muted" style={{ fontSize: 12.5, marginTop: 16 }}>
            Los avisos funcionan mientras la app esté abierta en una pestaña (aunque sea en segundo plano). Para recibirlos con la pestaña cerrada haría falta Web Push real (Service Worker + servidor), que podemos añadir más adelante.
          </p>

          {/* Avisos por CORREO: cada agente elige los suyos (opt-out personal de las
              plantillas globales). Solo llegan si además están activados a nivel global. */}
          <h2 style={{ fontSize: 15, margin: '28px 0 4px' }}>Avisos por correo</h2>
          <p className="muted" style={{ fontSize: 12.5, marginTop: 0, marginBottom: 12 }}>
            Elige qué avisos por correo quieres recibir. Estos avisos llegan aunque la app esté cerrada, pero solo si el administrador los tiene activados.
          </p>
          {MAIL_PREFS.map(([key, titulo, desc]) => (
            <div className="wn-row" key={key}>
              <span className="wn-ico"><Icon.mail /></span>
              <div className="wn-meta"><b>{titulo}</b><span>{desc}</span></div>
              <Toggle on={mail[key]} onChange={(v) => guardarMail(key, v)} />
            </div>
          ))}
        </div>
      </div>
    </>
  )
}
