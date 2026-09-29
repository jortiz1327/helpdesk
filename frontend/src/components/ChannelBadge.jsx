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

export default function ChannelBadge({ channel, source, compact = false }) {
  // El ORIGEN manda sobre el canal de entrega: los creados EN LA WEB —por el cliente en
  // el portal (source='portal') o a mano por un agente en la plataforma (source='manual')—
  // son «Web», aunque por dentro usen el correo (canal 'email') para responder por SMTP.
  let key = channel
  if (source === 'portal' || source === 'manual') key = 'web'

  const c = CH[key]
  if (!c) return null
  const I = c.icon

  return (
    <span className={`chip ch ch-${key}`} title={c.title}>
      <I />
      {!compact && c.label}
    </span>
  )
}
