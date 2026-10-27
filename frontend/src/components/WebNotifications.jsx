import { useState, useEffect, useRef } from 'react'
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

  // Pausa entre avisos del mismo ticket, por canal de entrada (minutos, dentro del rango del admin).
  const [minRango, maxRango] = user?.notify_throttle_range || [1, 30]
  const [pausa, setPausa] = useState(user?.notify_throttle || { web: minRango, email: minRango, whatsapp: minRango })
  const [canales, setCanales] = useState(user?.notify_channels || { web: true, email: true, whatsapp: true })
  const guardarCanales = async (patch) => {
    const r = await api.saveNotifyPrefs({ ...mail, ...patch })
    if (r?.ok) { onUser?.(r.user); setPausa(r.user.notify_throttle); setCanales(r.user.notify_channels); return true }
    toast(r?.error || 'No se pudo guardar', 'err')
    return false
  }
  const activarCanal = async (canal, on) => {
    const antes = canales
    setCanales({ ...canales, [canal]: on })
    if (await guardarCanales({ notify_channels: { [canal]: on } })) toast(on ? 'Avisos activados' : 'Avisos desactivados')
    else setCanales(antes)
  }
  // El deslizador guarda al soltarlo (no en cada paso): así no son 20 peticiones por arrastre.
  const timerPausa = useRef(null)
  const moverPausa = (canal, val) => {
    const next = { ...pausa, [canal]: Number(val) }
    setPausa(next)
    clearTimeout(timerPausa.current)
    timerPausa.current = setTimeout(async () => {
      if (await guardarCanales({ notify_throttle: next })) toast('Pausa guardada')
    }, 600)
  }
  useEffect(() => () => clearTimeout(timerPausa.current), [])
  const minutos = (n) => (n === 0 ? 'Sin pausa' : n === 1 ? '1 minuto' : `${n} minutos`)

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

          {/* Pausa entre avisos del MISMO ticket: el cliente de WhatsApp escribe «hola», «cómo
              va», «todo bien?» seguidos y eran tres correos y tres pitidos. */}
          <h2 style={{ fontSize: 15, margin: '28px 0 4px' }}>Avisos por canal</h2>
          <p className="muted" style={{ fontSize: 12.5, marginTop: 0, marginBottom: 12 }}>
            Elige de qué canales quieres avisos de tickets nuevos y respuestas del cliente (por correo y en el navegador).
            Con el canal activo, desliza para fijar cuántos minutos esperar antes de volver a avisarte <b>del mismo ticket</b>;
            los demás tickets avisan igual. Que te asignen un ticket se avisa siempre (interruptor de arriba).
          </p>
          {[['whatsapp', 'WhatsApp', 'Los clientes suelen mandar varios mensajes seguidos', Icon.whatsapp],
            ['email', 'Correo', 'Tickets y respuestas que llegan por correo', Icon.mail],
            ['web', 'Web', 'Tickets del portal y formularios', Icon.globe]].map(([canal, titulo, desc, Ico]) => (
            <div className={`wn-row wn-canal ${canales[canal] ? '' : 'off'}`} key={canal}>
              <div className="wn-canal-top">
                <span className="wn-ico"><Ico /></span>
                <div className="wn-meta"><b>{titulo}</b><span>{canales[canal] ? desc : 'Sin avisos de este canal'}</span></div>
                <Toggle on={!!canales[canal]} onChange={(v) => activarCanal(canal, v)} />
              </div>
              {canales[canal] && maxRango > minRango && (
                <div className="wn-slider">
                  <span className="wn-slider-lbl">Pausa entre avisos del mismo ticket</span>
                  <input type="range" min={minRango} max={maxRango} step="1" value={pausa[canal]}
                    style={{ '--p': `${((pausa[canal] - minRango) / (maxRango - minRango)) * 100}%` }}
                    onChange={(e) => moverPausa(canal, e.target.value)} aria-label={`Pausa de ${titulo} en minutos`} />
                  <b className="wn-slider-val">{minutos(Number(pausa[canal]))}</b>
                  <span className="wn-slider-ends"><span>{minutos(minRango)}</span><span>{maxRango} min</span></span>
                </div>
              )}
            </div>
          ))}
        </div>
      </div>
    </>
  )
}
