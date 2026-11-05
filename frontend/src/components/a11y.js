import { useEffect, useRef } from 'react'

// Ventanas abiertas, de abajo arriba: Esc cierra solo la de encima.
const pila = []

/**
 * Props de accesibilidad para la caja de una ventana (.modal): rol de diálogo,
 * Esc cierra (aunque el foco esté fuera) y el foco entra al abrir y vuelve al salir.
 */
export function useDialog(onClose, label) {
  const ref = useRef(null)
  const cerrar = useRef(onClose)
  cerrar.current = onClose

  useEffect(() => {
    const yo = {}
    pila.push(yo)
    const antes = document.activeElement
    const caja = ref.current
    if (caja && !caja.contains(document.activeElement)) caja.focus({ preventScroll: true })
    const tecla = (e) => {
      if (e.key !== 'Escape' || pila[pila.length - 1] !== yo) return
      e.stopPropagation()
      cerrar.current?.()
    }
    window.addEventListener('keydown', tecla)
    return () => {
      window.removeEventListener('keydown', tecla)
      pila.splice(pila.indexOf(yo), 1)
      if (antes && document.contains(antes)) antes.focus?.({ preventScroll: true })
    }
  }, [])

  return { ref, role: 'dialog', 'aria-modal': true, 'aria-label': label, tabIndex: -1 }
}

/** Fila de tabla que abre algo: también con Intro o Espacio. */
export function filaClic(fn) {
  return {
    onClick: fn,
    tabIndex: 0,
    onKeyDown: (e) => {
      if (e.target !== e.currentTarget || (e.key !== 'Enter' && e.key !== ' ')) return
      e.preventDefault()
      fn()
    },
  }
}
