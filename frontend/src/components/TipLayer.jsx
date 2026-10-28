import { useEffect, useRef, useState } from 'react'

/* -------------------------------------------------------------------------
 * Tooltips propios para TODA la web, sin tocar cada botón: cualquier elemento con
 * `title` (o `data-tip`) muestra este tooltip en vez del nativo del navegador.
 *
 * Al entrar con el ratón se guarda el `title` en `data-tip-tmp` y se quita (así el
 * navegador no pinta el suyo); al salir se devuelve, para que React siga viendo el
 * DOM como lo dejó. Se coloca encima del elemento (o debajo si no cabe) y nunca se
 * sale de la pantalla.
 * ---------------------------------------------------------------------- */

const RETARDO = 350   // ms antes de mostrarse: que no parpadee al cruzar la pantalla
const MARGEN = 8

export default function TipLayer() {
  const [tip, setTip] = useState(null)   // { text, x, y, abajo }
  const ref = useRef(null)
  const actual = useRef(null)            // elemento con tooltip bajo el ratón
  const timer = useRef(null)

  useEffect(() => {
    const restaurar = (el) => {
      if (el && el.hasAttribute('data-tip-tmp')) {
        if (!el.hasAttribute('title')) el.setAttribute('title', el.getAttribute('data-tip-tmp'))
        el.removeAttribute('data-tip-tmp')
      }
    }
    const ocultar = () => {
      clearTimeout(timer.current)
      restaurar(actual.current)
      actual.current = null
      setTip(null)
    }

    const sobre = (e) => {
      const el = e.target instanceof Element ? e.target.closest('[title], [data-tip], [data-tip-tmp]') : null
      if (el === actual.current) return
      ocultar()
      if (!el || el.tagName === 'IFRAME') return
      const text = (el.getAttribute('title') || el.getAttribute('data-tip') || el.getAttribute('data-tip-tmp') || '').trim()
      if (!text) return
      if (el.hasAttribute('title')) {
        el.setAttribute('data-tip-tmp', el.getAttribute('title'))
        el.removeAttribute('title')
      }
      actual.current = el
      timer.current = setTimeout(() => {
        if (actual.current !== el || !el.isConnected) return
        const r = el.getBoundingClientRect()
        setTip({ text, r })
      }, RETARDO)
    }
    const fuera = (e) => {
      if (!actual.current) return
      const a = e.relatedTarget
      if (a instanceof Node && actual.current.contains(a)) return
      ocultar()
    }

    document.addEventListener('pointerover', sobre, true)
    document.addEventListener('pointerout', fuera, true)
    document.addEventListener('pointerdown', ocultar, true)
    document.addEventListener('keydown', ocultar, true)
    window.addEventListener('scroll', ocultar, true)
    window.addEventListener('blur', ocultar)
    return () => {
      ocultar()
      document.removeEventListener('pointerover', sobre, true)
      document.removeEventListener('pointerout', fuera, true)
      document.removeEventListener('pointerdown', ocultar, true)
      document.removeEventListener('keydown', ocultar, true)
      window.removeEventListener('scroll', ocultar, true)
      window.removeEventListener('blur', ocultar)
    }
  }, [])

  // Colocación tras medir el propio tooltip: centrado, arriba si cabe, y dentro de la pantalla.
  const [pos, setPos] = useState(null)
  useEffect(() => {
    if (!tip || !ref.current) { setPos(null); return }
    const t = ref.current.getBoundingClientRect()
    const { r } = tip
    const abajo = r.top - t.height - MARGEN < 4
    const y = abajo ? r.bottom + MARGEN : r.top - t.height - MARGEN
    const centro = r.left + r.width / 2
    const x = Math.max(6, Math.min(window.innerWidth - t.width - 6, centro - t.width / 2))
    setPos({ x, y, abajo, flecha: Math.max(10, Math.min(t.width - 10, centro - x)) })
  }, [tip])

  if (!tip) return null
  return (
    <div ref={ref} role="tooltip"
      className={`tip-layer${pos ? ' on' : ''}${pos?.abajo ? ' abajo' : ''}`}
      style={{ left: pos?.x ?? -9999, top: pos?.y ?? -9999, '--flecha': `${pos?.flecha ?? 0}px` }}>
      {tip.text}
    </div>
  )
}
