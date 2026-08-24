import { Icon } from '../icons.jsx'

/*
 * Estado de error de carga, reutilizable. Cuando una llamada falla, en vez de dejar
 * el spinner/skeleton girando para siempre (el `.then()` sin `.catch`), se muestra
 * esto con un botón para reintentar. `role="alert"` para que lo anuncie el lector.
 */
export default function LoadError({ onRetry, msg = 'No se pudo cargar' }) {
  return (
    <div className="load-err" role="alert">
      <Icon.warn />
      <p>{msg}</p>
      {onRetry && <button className="btn ghost sm" onClick={onRetry}>Reintentar</button>}
    </div>
  )
}
