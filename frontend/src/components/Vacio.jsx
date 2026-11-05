/**
 * Estado vacío con salida: qué falta, por qué importa y el botón para resolverlo.
 * `actions` son botones ya hechos; sin acciones queda solo el texto (p. ej. sin permisos).
 */
export default function Vacio({ icon: Ic, title, children, actions, compact = false, className = '' }) {
  return (
    <div className={`vacio${compact ? ' compact' : ''} ${className}`} role="status">
      {Ic ? <span className="vacio-ic" aria-hidden="true"><Ic /></span> : null}
      {title ? <b className="vacio-t">{title}</b> : null}
      {children ? <p className="vacio-p">{children}</p> : null}
      {actions ? <div className="vacio-a">{actions}</div> : null}
    </div>
  )
}
