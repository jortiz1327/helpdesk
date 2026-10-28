import { Icon } from '../icons.jsx'

/* ---------------------------------------------------------------------------
 * Badge de CANAL: de dónde llegó el ticket (web · correo · WhatsApp).
 *
 * Ojo con el icono de WhatsApp: aquí deja de ser el logo de la app y pasa a ser
 * lo que debe ser, un indicador de canal más, junto a web y correo.
 * ------------------------------------------------------------------------- */

const CH = {
  web:      { label: 'Web',      icon: Icon.globe,  title: 'Creado desde la web' },
  email:    { label: 'Correo',   icon: Icon.mail,   title: 'Llegó por correo' },
  whatsapp: { label: 'WhatsApp', icon: Icon.logo,   title: 'Llegó por WhatsApp' },
}

/*
 * WhatsApp con la ventana de 24 h CERRADA (el cliente lleva más de 24 h sin escribir):
 * el badge pasa a ámbar y el tooltip explica por qué. Ya no se le puede contestar desde
 * aquí: hay que ir al móvil u otro canal. En cuanto el cliente vuelve a escribir, se
 * reabre otras 24 h. `lastIn` = último mensaje del cliente (ISO); null = nunca escribió.
 */
const fmt = (d) => d.toLocaleString('es-ES', { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' })

export default function ChannelBadge({ channel, source, compact = false, lastIn }) {
  // El ORIGEN manda sobre el canal de entrega: los creados EN LA WEB —por el cliente en
  // el portal (source='portal') o a mano por un agente en la plataforma (source='manual')—
  // son «Web», aunque por dentro usen el correo (canal 'email') para responder por SMTP.
  let key = channel
  if (source === 'portal' || source === 'manual') key = 'web'

  const c = CH[key]
  if (!c) return null
  const I = c.icon

  // Solo se evalúa si nos pasan el dato (undefined = no se sabe: badge normal).
  let cerrada = false, title = c.title
  if (key === 'whatsapp' && lastIn !== undefined) {
    const ultimo = lastIn ? new Date(lastIn) : null
    cerrada = !ultimo || Date.now() - ultimo.getTime() > 24 * 3600 * 1000
    if (cerrada) {
      title = ultimo
        ? `Ventana de 24 h cerrada: el cliente escribió por última vez el ${fmt(ultimo)}. ` +
          'Ya no se le puede contestar desde aquí; usa el móvil u otro canal. Se reabre cuando vuelva a escribir.'
        : 'Ventana de 24 h cerrada: no hay mensajes del cliente. Usa el móvil u otro canal.'
    } else if (ultimo) {
      title = `Llegó por WhatsApp · se puede contestar hasta el ${fmt(new Date(ultimo.getTime() + 24 * 3600 * 1000))}`
    }
  }

  return (
    <span className={`chip ch ch-${key}${cerrada ? ' ch-wa-cerrada' : ''}`} title={title}>
      <I />
      {!compact && c.label}
    </span>
  )
}
