import { useCallback, useEffect, useLayoutEffect, useRef, useState } from 'react'
import { createPortal } from 'react-dom'
import { api } from '../api.js'
import { Icon } from '../icons.jsx'

/* -------------------------------------------------------------------------
 * TOUR GUIADO de Gestión de garantías. Oscurece la pantalla, resalta un elemento y
 * explica qué es; va cambiando de pantalla solo. Los textos vienen del servidor
 * (editables en Configuración); aquí se decide dónde está cada paso:
 *   view: la pantalla · lista / ficha: la lista de garantías o una de ejemplo
 *   sel: lo que se resalta (sin sel, el recuadro sale centrado) · perm: hace falta
 * Lo que no aparece en pantalla (no tiene permiso, no hay pasos…) se salta.
 * ---------------------------------------------------------------------- */

const DONDE = {
  inicio: { view: 'garantias', lista: true },
  tickets: { view: 'garantias_tickets', sel: 'nav-garantias_tickets', perm: ['helpdesk.access'] },
  garantias: { view: 'garantias', lista: true, sel: 'nav-garantias' },
  filtros: { view: 'garantias', lista: true, sel: 'wl-filtros' },
  acciones: { view: 'garantias', lista: true, sel: '#wr-head-acts' },
  ficha: { view: 'garantias', ficha: true, sel: 'wf-head' },
  etiquetas: { view: 'garantias', ficha: true, sel: 'wf-tabs' },
  pasos: { view: 'garantias', ficha: true, sel: '.wf-pasos' },
  historial: { view: 'garantias', ficha: true, sel: 'wf-hist' },
  equipo: { view: 'garantias_equipo', sel: 'nav-garantias_equipo' },
  informes: { view: 'garantias_informes', sel: 'nav-garantias_informes' },
  clientes: { view: 'garantias_clientes', sel: 'nav-garantias_clientes' },
  zkong: { view: 'zkong', sel: 'nav-zkong' },
  dispositivos: { view: 'devices', sel: 'nav-devices' },
  catalogo: { view: 'device_catalog', sel: 'nav-device_catalog' },
  config: { view: 'warranty_settings', sel: 'nav-warranty_settings', perm: ['warranties.config'] },
  fin: { sel: 'nav-tour' },
}
const selector = (s) => (/^[.#[]/.test(s) ? s : `[data-tour="${s}"]`)
const ESPERA = 4000   // ms que se espera a que aparezca lo que se resalta antes de saltarlo
const HUECO = 6       // margen del resalte alrededor del elemento
const SEP = 14        // del resalte al recuadro

// Visto o rechazado: no se vuelve a ofrecer (también en el servidor, por usuario).
let visto = false
const marcarVisto = () => { if (!visto) { visto = true; api.warrantyTourSeen() } }

/** Aviso para quien entra por primera vez al área: empezar o «ahora no». */
export function TourOffer({ onStart }) {
  const [ver, setVer] = useState(false)
  useEffect(() => {
    if (visto) return
    let vivo = true
    api.warrantyTour().then((r) => { if (!vivo || !r.ok) return; if (r.seen) visto = true; else setVer(r.steps.length > 0) })
    return () => { vivo = false }
  }, [])
  if (!ver || visto) return null
  return (
    <div className="tour-offer" role="dialog" aria-label="Tour guiado">
      <span className="tour-offer-ic"><Icon.play /></span>
      <div>
        <b>¿Primera vez en Gestión de garantías?</b>
        <p>Un tour de un par de minutos te enseña cada apartado.</p>
        <div className="tour-offer-acts">
          <button className="btn sm" onClick={() => { setVer(false); onStart() }}><Icon.play /> Iniciar tour</button>
          <button className="btn ghost sm" onClick={() => { setVer(false); marcarVisto() }}>Ahora no</button>
        </div>
      </div>
    </div>
  )
}

/** El tour. can(perm) · onGo(view, {lista, ficha}) cambia de pantalla · onClose al terminar. */
export default function WarrantyTour({ can, onGo, onClose }) {
  const [pasos, setPasos] = useState(null)
  const [i, setI] = useState(0)
  const [rect, setRect] = useState(null)       // lo resaltado, en pantalla; null: centrado
  const [listo, setListo] = useState(false)    // el paso ya se ha colocado
  const dir = useRef(1)                        // hacia dónde se iba, para saltar lo que falta
  const lugar = useRef('')                     // la última pantalla pedida
  const pop = useRef(null)
  const [pos, setPos] = useState(null)

  // Textos y una garantía de ejemplo para enseñar la ficha (si no hay ninguna, sin esos pasos).
  useEffect(() => {
    Promise.all([api.warrantyTour(), api.warranties({ chip: 'open', size: 1 }).then((r) => (r.rows?.length ? r : api.warranties({ chip: '', size: 1 })))])
      .then(([t, l]) => {
        const ej = l?.rows?.[0]?.id || null
        const ps = (t.ok ? t.steps : []).map((s) => ({ ...s, ...(DONDE[s.id] || {}) }))
          .filter((s) => (s.perm || []).every(can) && (!s.ficha || ej))
          .map((s) => (s.ficha ? { ...s, ejemplo: ej } : s))
        if (!ps.length) { onClose(); return }
        setPasos(ps)
      })
  }, [])

  const cerrar = useCallback(() => { marcarVisto(); onClose() }, [onClose])
  const ir = useCallback((n) => {
    if (!pasos) return
    if (n < 0) return
    if (n >= pasos.length) { cerrar(); return }
    dir.current = n >= i ? 1 : -1
    setI(n)
  }, [pasos, i, cerrar])

  // Colocar el paso: cambiar de pantalla si hace falta, esperar al elemento y centrarlo.
  const p = pasos?.[i]
  useEffect(() => {
    if (!p) return
    setListo(false)
    setRect(null)
    setPos(null)
    const donde = p.ficha ? `ficha:${p.ejemplo}` : p.view ? `${p.view}${p.lista ? ':lista' : ''}` : lugar.current
    if (donde !== lugar.current) { lugar.current = donde; onGo(p.view, { lista: !!p.lista, ficha: p.ficha ? p.ejemplo : null }) }
    if (!p.sel) { setRect(null); setListo(true); return }
    const t0 = Date.now()
    let timer
    const buscar = () => {
      const el = document.querySelector(selector(p.sel))
      const r = el?.getBoundingClientRect()
      if (r && r.width && r.height) {
        // También dentro de lo que tiene scroll propio (el menú lateral, la ficha).
        el.scrollIntoView({ block: r.height > window.innerHeight * 0.8 ? 'start' : 'nearest' })
        setListo(true)
        return
      }
      if (Date.now() - t0 > ESPERA) {
        // No está (sin permiso, sin pasos…): al siguiente en la misma dirección.
        const n = i + dir.current
        if (n < 0 || n >= pasos.length) cerrar(); else setI(n)
        return
      }
      timer = setTimeout(buscar, 120)
    }
    buscar()
    return () => clearTimeout(timer)
  }, [p, i])

  // Seguir al elemento mientras se ve el paso (scroll, cambios de tamaño, cargas).
  useEffect(() => {
    if (!listo || !p?.sel) return
    const medir = () => {
      const el = document.querySelector(selector(p.sel))
      if (!el) return
      const r = el.getBoundingClientRect()
      const top = Math.max(r.top - HUECO, 4), left = Math.max(r.left - HUECO, 4)
      const bottom = Math.min(r.bottom + HUECO, window.innerHeight - 4), right = Math.min(r.right + HUECO, window.innerWidth - 4)
      setRect((o) => (o && o.top === top && o.left === left && o.bottom === bottom && o.right === right ? o : { top, left, bottom, right }))
    }
    medir()
    const t = setInterval(medir, 200)
    window.addEventListener('resize', medir)
    window.addEventListener('scroll', medir, true)
    return () => { clearInterval(t); window.removeEventListener('resize', medir); window.removeEventListener('scroll', medir, true) }
  }, [listo, p])

  // El recuadro: a la derecha, debajo, encima o a la izquierda de lo resaltado; si no cabe, centrado.
  useLayoutEffect(() => {
    if (!listo || !pop.current) return
    const w = pop.current.offsetWidth, h = pop.current.offsetHeight
    const vw = window.innerWidth, vh = window.innerHeight
    const dentro = (x, y) => ({ left: Math.max(12, Math.min(vw - w - 12, x)), top: Math.max(12, Math.min(vh - h - 12, y)) })
    if (!p?.sel) { setPos({ left: (vw - w) / 2, top: (vh - h) / 2 }); return }
    if (!rect) return
    const cy = (rect.top + rect.bottom) / 2, cx = (rect.left + rect.right) / 2
    if (rect.right + SEP + w <= vw - 12) setPos(dentro(rect.right + SEP, cy - h / 2))
    else if (rect.bottom + SEP + h <= vh - 12) setPos(dentro(cx - w / 2, rect.bottom + SEP))
    else if (rect.top - SEP - h >= 12) setPos(dentro(cx - w / 2, rect.top - SEP - h))
    else if (rect.left - SEP - w >= 12) setPos(dentro(rect.left - SEP - w, cy - h / 2))
    else setPos(dentro((vw - w) / 2, vh - h - 24))
  }, [listo, rect, p, i])

  // Teclado: flechas para moverse, Esc para salir.
  useEffect(() => {
    const k = (e) => {
      if (e.key === 'Escape') { e.preventDefault(); cerrar() }
      else if (e.key === 'ArrowRight' || e.key === 'Enter') { e.preventDefault(); ir(i + 1) }
      else if (e.key === 'ArrowLeft') { e.preventDefault(); ir(i - 1) }
    }
    window.addEventListener('keydown', k, true)
    return () => window.removeEventListener('keydown', k, true)
  }, [i, ir, cerrar])

  if (!pasos) return null
  const ultimo = i === pasos.length - 1
  return createPortal(
    <div className="tour" role="dialog" aria-modal="true" aria-label="Tour guiado">
      <div className="tour-block" />
      {p.sel && rect && listo
        ? <div className="tour-spot" style={{ top: rect.top, left: rect.left, width: rect.right - rect.left, height: rect.bottom - rect.top }} />
        : <div className="tour-dim" />}
      <div ref={pop} className={`tour-pop${listo && pos ? ' on' : ''}`} style={pos || { left: -9999, top: -9999 }} key={p.id}>
        <div className="tour-bar"><i style={{ width: `${((i + 1) / pasos.length) * 100}%` }} /></div>
        <div className="tour-h">
          <small>Paso {i + 1} de {pasos.length}</small>
          <button className="icon-btn" title="Cerrar el tour (Esc)" onClick={cerrar}><Icon.x /></button>
        </div>
        <h3>{p.title}</h3>
        <p>{p.text}</p>
        <div className="tour-f">
          <button className="tour-prev" disabled={i === 0} onClick={() => ir(i - 1)}><Icon.chevron style={{ transform: 'rotate(90deg)' }} /> Anterior</button>
          <span className="spacer" />
          <button className="btn sm" onClick={() => ir(i + 1)}>{ultimo ? 'Terminar' : <>Siguiente <Icon.chevron style={{ transform: 'rotate(-90deg)' }} /></>}</button>
        </div>
      </div>
    </div>,
    document.body,
  )
}
