import { useState, useRef } from 'react'
import { createPortal } from 'react-dom'
import { Icon } from '../icons.jsx'

/**
 * Candado con tooltip de motivos para funciones bloqueadas por Meta.
 * El globo se renderiza en un portal al <body> para que no lo recorte
 * ninguna tarjeta con overflow.
 * props: info = { title, reasons: [] } (de api.gating). Si es null, no renderiza.
 */
export default function LockTip({ info }) {
  const [pos, setPos] = useState(null)
  const ref = useRef(null)

  const show = () => {
    if (!ref.current) return
    const r = ref.current.getBoundingClientRect()
    const left = Math.max(155, Math.min(r.left + r.width / 2, window.innerWidth - 155))
    setPos({ left, top: r.top })
  }
  const hide = () => setPos(null)

  if (!info) return null

  return (
    <span className="lock-tip" tabIndex={0} ref={ref} onMouseEnter={show} onMouseLeave={hide} onFocus={show} onBlur={hide}>
      <Icon.lock />
      {pos && createPortal(
        <span className="lock-bubble" style={{ left: pos.left, top: pos.top }}>
          <b><Icon.lock /> {info.title}</b>
          <span className="lock-sub">No disponible hasta verificar la cuenta de Meta:</span>
          <ul>{info.reasons.map((r, i) => <li key={i}>{r}</li>)}</ul>
        </span>,
        document.body
      )}
    </span>
  )
}
