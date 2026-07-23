import { useState, useRef, useEffect } from 'react'
import { createPortal } from 'react-dom'
import { Icon } from '../icons.jsx'

/**
 * Desplegable propio (sustituye al <select> nativo).
 * El menú se renderiza en un portal al <body> con posición fija, así no lo
 * recorta ningún contenedor con overflow (tarjetas, nodos de React Flow…).
 * Con muchas opciones (p. ej. 200 etiquetas) muestra un BUSCADOR para filtrar.
 */
export default function Select({ value, onChange, options = [], placeholder = 'Selecciona…', sm = false, block = false, disabled = false, searchable }) {
  const [open, setOpen] = useState(false)
  const [pos, setPos] = useState(null) // { left, top, width, up }
  const [q, setQ] = useState('')
  const ref = useRef(null)
  const menuRef = useRef(null)
  const searchRef = useRef(null)

  // Buscador: por defecto se activa solo cuando hay bastantes opciones.
  const canSearch = searchable ?? options.length > 8

  useEffect(() => {
    if (!open) return
    if (canSearch) setTimeout(() => searchRef.current?.focus(), 10)
    const onDoc = (e) => {
      if (ref.current?.contains(e.target) || menuRef.current?.contains(e.target)) return
      setOpen(false)
    }
    const onEsc = (e) => { if (e.key === 'Escape') setOpen(false) }
    const close = () => setOpen(false)
    document.addEventListener('mousedown', onDoc)
    document.addEventListener('keydown', onEsc)
    window.addEventListener('resize', close)
    return () => { document.removeEventListener('mousedown', onDoc); document.removeEventListener('keydown', onEsc); window.removeEventListener('resize', close) }
  }, [open, canSearch])

  const sel = options.find((o) => String(o.value) === String(value))
  const needle = q.trim().toLowerCase()
  const filtered = needle
    ? options.filter((o) => (o.label || '').toLowerCase().includes(needle) || (o.sub || '').toLowerCase().includes(needle))
    : options

  const toggle = () => {
    if (disabled) return
    if (!open && ref.current) {
      const r = ref.current.getBoundingClientRect()
      const up = window.innerHeight - r.bottom < 320 && r.top > 320
      setPos({ left: r.left, top: up ? r.top - 6 : r.bottom + 6, width: r.width, up })
      setQ('')
    }
    setOpen((o) => !o)
  }
  const pick = (o) => { onChange(o.value); setOpen(false); setQ('') }
  const onSearchKey = (e) => {
    if (e.key === 'Enter' && filtered.length) { e.preventDefault(); pick(filtered[0]) }
  }

  return (
    <div className={`sel ${sm ? 'sm' : ''} ${block ? 'block' : ''} ${open ? 'open' : ''} ${disabled ? 'disabled' : ''}`} ref={ref}>
      <button type="button" className="sel-trigger" onClick={toggle} disabled={disabled}>
        {sel?.color && <span className="sel-dot" style={{ background: sel.color }} />}
        <span className={`sel-val ${sel ? '' : 'ph'}`}>{sel ? sel.label : placeholder}</span>
        <Icon.chevron className="sel-caret" />
      </button>
      {open && pos && createPortal(
        <div ref={menuRef} className={`sel-menu ${pos.up ? 'up' : ''}`} style={{ left: pos.left, top: pos.top, width: pos.width, transform: pos.up ? 'translateY(-100%)' : 'none' }}>
          {canSearch && (
            <div className="sel-search">
              <Icon.search />
              <input ref={searchRef} value={q} onChange={(e) => setQ(e.target.value)} onKeyDown={onSearchKey} placeholder="Buscar…" />
            </div>
          )}
          <div className="sel-opts">
            {filtered.length === 0 ? (
              <div className="sel-empty">Sin resultados</div>
            ) : filtered.map((o) => (
              <button type="button" key={String(o.value)} className={`sel-opt ${String(o.value) === String(value) ? 'on' : ''}`} onClick={() => pick(o)}>
                {o.color && <span className="sel-dot" style={{ background: o.color }} />}
                <span className="sel-opt-t">{o.label}{o.sub && <span className="sel-opt-sub">{o.sub}</span>}</span>
                {String(o.value) === String(value) && <Icon.check className="sel-check" />}
              </button>
            ))}
          </div>
        </div>,
        document.body
      )}
    </div>
  )
}
