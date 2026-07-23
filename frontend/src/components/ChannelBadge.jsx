import { Icon } from '../icons.jsx'

/* ---------------------------------------------------------------------------
 * Badge de CANAL: de dónde llegó el ticket (web · correo · WhatsApp).
 *
 * Ojo con el icono de WhatsApp: aquí deja de ser el logo de la app y pasa a ser
 * lo que debe ser, un indicador de canal más, junto a web y correo.
 * ------------------------------------------------------------------------- */

const CH = {
  web:      { label: 'Web',      icon: Icon.globe },
  email:    { label: 'Correo',   icon: Icon.mail },
  whatsapp: { label: 'WhatsApp', icon: Icon.logo },
}

export default function ChannelBadge({ channel, compact = false }) {
  const c = CH[channel]
  if (!c) return null
  const I = c.icon

  return (
    <span className={`chip ch ch-${channel}`} title={`Llegó por ${c.label}`}>
      <I />
      {!compact && c.label}
    </span>
  )
}
