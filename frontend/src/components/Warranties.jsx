import { Icon } from '../icons.jsx'

/*
 * Portada del área «Gestión de garantías». De momento el área existe (permiso
 * `warranties.access`, sitio en la pantalla de elección y en el selector del menú)
 * pero todavía no tiene pantallas: esto es solo el hueco donde irán.
 */
export default function Warranties() {
  return (
    <>
      <header className="page-head">
        <span className="ic" style={{ width: 30, height: 30, borderRadius: 9, background: 'var(--primary-soft)', display: 'grid', placeItems: 'center' }}><Icon.shield style={{ width: 18, height: 18, fill: 'var(--primary)' }} /></span>
        <h1>Gestión de garantías</h1>
      </header>

      <div className="page-scroll">
        <div className="page" style={{ maxWidth: 920 }}>
          <div className="empty">
            <div className="ico"><Icon.shield /></div>
            <p>Esta área todavía está vacía.</p>
          </div>
        </div>
      </div>
    </>
  )
}
