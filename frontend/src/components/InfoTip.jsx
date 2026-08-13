/*
 * Pista informativa: un «?» que, al pasar el ratón o enfocarlo con el teclado, muestra
 * una burbuja explicativa. Pensado para que cualquiera pueda configurar algo a mano sin
 * tener que preguntar (p. ej. de dónde sale cada credencial de Meta).
 */
export default function InfoTip({ text, wide = false }) {
  return (
    <span className={`info-tip ${wide ? 'wide' : ''}`} tabIndex={0} role="note" aria-label={text}>
      <span className="info-tip-q" aria-hidden="true">?</span>
      <span className="info-tip-bubble">{text}</span>
    </span>
  )
}
