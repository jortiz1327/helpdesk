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
 *   sec: el apartado; si le quedan pasos por delante, «Saltar apartado»
 * Lo que no aparece en pantalla (no tiene permiso, no hay fases…) se salta. Mientras la
 * pantalla del paso se carga (esqueletos, spinner), «Cargando…» en lugar de los botones.
 * ---------------------------------------------------------------------- */

const DONDE = {
  inicio: { view: 'garantias', lista: true },
  tickets: { view: 'garantias_tickets', sel: 'nav-garantias_tickets', perm: ['helpdesk.access'] },
  garantias: { sec: 'garantias', view: 'garantias', lista: true, sel: 'nav-garantias' },
  filtros: { sec: 'garantias', view: 'garantias', lista: true, sel: 'wl-filtros' },
  acciones: { sec: 'garantias', view: 'garantias', lista: true, sel: '#wr-head-acts' },
  ficha: { sec: 'ficha', view: 'garantias', ficha: true, sel: '.wf-h-top' },
  datos: { sec: 'ficha', view: 'garantias', ficha: true, sel: '.wf-facts' },
  recorrido: { sec: 'ficha', view: 'garantias', ficha: true, sel: '.wf-steps' },
  etiquetas: { sec: 'ficha', view: 'garantias', ficha: true, sel: 'wf-tabs' },
  pasos: { sec: 'ficha', view: 'garantias', ficha: true, sel: '.wf-pasos' },
  historial: { sec: 'ficha', view: 'garantias', ficha: true, sel: 'wf-hist' },
  equipo: { view: 'garantias_equipo', sel: 'nav-garantias_equipo' },
  informes: { view: 'garantias_informes', sel: 'nav-garantias_informes' },
  clientes: { view: 'garantias_clientes', sel: 'nav-garantias_clientes' },
  zkong: { view: 'zkong', sel: 'nav-zkong' },
  guias: { view: 'garantias_guias', sel: 'nav-garantias_guias' },
  dispositivos: { view: 'devices', sel: 'nav-devices' },
  catalogo: { view: 'device_catalog', sel: 'nav-device_catalog' },
  config: { view: 'warranty_settings', sel: 'nav-warranty_settings', perm: ['warranties.config'] },
  ayuda: { view: 'garantias_ayuda', sel: 'nav-garantias_ayuda' },
  fin: { sel: 'nav-tour' },
}
const selector = (s) => (/^[.#[]/.test(s) ? s : `[data-tour="${s}"]`)
// La pantalla aún se está cargando: esqueletos, filas de carga o el spinner central.
const CARGANDO = '[aria-busy="true"], .wl-skel, .center-load'
const cargando = () => [...document.querySelectorAll(CARGANDO)].some((el) => !el.closest('.tour') && el.getClientRects().length)
const ESPERA = 6000   // ms como mucho esperando a la pantalla; después se enseña o se salta
const FALTA = 900     // la pantalla ya cargó y no está lo que se resalta: se salta tras este margen
const HUECO = 6       // margen del resalte alrededor del elemento
const SEP = 14        // del resalte al recuadro
const derecha = { transform: 'rotate(-90deg)' }

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

/** El tour. can(perm) · onGo(view, {lista, ficha}) cambia de pantalla · onClose al terminar
 *  · onHelp(clave) sale del tour y abre ese artículo del Centro de ayuda. */
export default function WarrantyTour({ can, onGo, onClose, onHelp }) {
  const [pasos, setPasos] = useState(null)
  const [i, setI] = useState(0)
  const [rect, setRect] = useState(null)       // lo resaltado, en pantalla; null: centrado
  const [listo, setListo] = useState(false)    // la pantalla del paso ya cargó y el paso está colocado
  const dir = useRef(1)                        // hacia dónde se iba, para saltar lo que falta
  const lugar = useRef('')                     // la última pantalla pedida
  const pop = useRef(null)
  const sigRef = useRef(null)
  const [pos, setPos] = useState(null)

  // Textos y una garantía de ejemplo para enseñar la ficha (si no hay ninguna, sin esos pasos).
  useEffect(() => {
    Promise.all([api.warrantyTour(), api.warranties({ chip: 'open', size: 1 }).then((r) => (r.rows?.length ? r : api.warranties({ chip: '', size: 1 })))])
      .then(([t, l]) => {
        const ej = l?.rows?.[0]?.id || null
        const ps = (t.ok ? t.steps : []).map((s) => ({ ...s, ...(DONDE[s.id] || {}) }))
          .filter((s) => (s.perm || []).every(can) && (!s.ficha || ej))
          .map((s) => ({ ...s, sec: s.sec || s.id, ...(s.ficha ? { ejemplo: ej } : null) }))
        if (!ps.length) { onClose(); return }
        // El nombre del apartado: el título de su primer paso.
        ps.forEach((s) => { s.secTitle = ps.find((x) => x.sec === s.sec).title })
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

  // Colocar el paso: cambiar de pantalla si hace falta, esperar a que cargue y a lo que se resalta.
  const p = pasos?.[i]
  useEffect(() => {
    if (!p) return
    setListo(false)
    setRect(null)   // el recuadro se queda donde estaba, con «Cargando…», hasta colocarse
    const donde = p.ficha ? `ficha:${p.ejemplo}` : p.view ? `${p.view}${p.lista ? ':lista' : ''}` : lugar.current
    if (donde !== lugar.current) { lugar.current = donde; onGo(p.view, { lista: !!p.lista, ficha: p.ficha ? p.ejemplo : null }) }
    const t0 = Date.now()
    // La pantalla del paso ya está puesta: la ficha de ejemplo o la lista (las demás, al momento).
    const pantalla = p.ficha ? `.wf-head .wf-code` : p.lista ? '[data-tour="wl-filtros"]' : null
    let timer, libre = 0   // desde cuándo la pantalla ya no está cargando
    const buscar = () => {
      const ocupada = cargando() || (pantalla && !document.querySelector(pantalla))
      if (ocupada) libre = 0
      else if (!libre) libre = Date.now()
      const tarde = Date.now() - t0 > ESPERA
      if (!p.sel) {
        if (!ocupada || tarde) { setListo(true); return }
        timer = setTimeout(buscar, 120)
        return
      }
      const el = document.querySelector(selector(p.sel))
      const r = el?.getBoundingClientRect()
      if (r && r.width && r.height && (!ocupada || tarde)) {
        // También dentro de lo que tiene scroll propio (el menú lateral, la ficha).
        el.scrollIntoView({ block: r.height > window.innerHeight * 0.8 ? 'start' : 'nearest' })
        setListo(true)
        return
      }
      if (tarde || (libre && Date.now() - libre > FALTA)) {
        // No está (sin permiso, sin fases…): al siguiente en la misma dirección.
        const n = i + dir.current
        if (n < 0 || n >= pasos.length) cerrar(); else setI(n)
        return
      }
      timer = setTimeout(buscar, 120)
    }
    timer = setTimeout(buscar, 60)   // tras el cambio de pantalla
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

  // El recuadro: a la derecha, debajo, encima o a la izquierda de lo resaltado; si no cabe,
  // abajo en el centro. Mientras carga, se queda donde estaba (el primero, centrado).
  useLayoutEffect(() => {
    if (!pop.current || !p) return
    const w = pop.current.offsetWidth, h = pop.current.offsetHeight
    const vw = window.innerWidth, vh = window.innerHeight
    const dentro = (x, y) => ({ left: Math.max(12, Math.min(vw - w - 12, x)), top: Math.max(12, Math.min(vh - h - 12, y)) })
    const igual = (n) => setPos((o) => (o && o.left === n.left && o.top === n.top ? o : n))
    if (!listo) { setPos((o) => (o ? dentro(o.left, o.top) : dentro((vw - w) / 2, (vh - h) / 2))); return }
    if (!p.sel) { igual(dentro((vw - w) / 2, (vh - h) / 2)); return }
    if (!rect) return
    const cy = (rect.top + rect.bottom) / 2, cx = (rect.left + rect.right) / 2
    if (rect.right + SEP + w <= vw - 12) igual(dentro(rect.right + SEP, cy - h / 2))
    else if (rect.bottom + SEP + h <= vh - 12) igual(dentro(cx - w / 2, rect.bottom + SEP))
    else if (rect.top - SEP - h >= 12) igual(dentro(cx - w / 2, rect.top - SEP - h))
    else if (rect.left - SEP - w >= 12) igual(dentro(rect.left - SEP - w, cy - h / 2))
    else igual(dentro((vw - w) / 2, vh - h - 24))
  }, [listo, rect, p, i])

  // Con el paso listo, el foco a «Siguiente» (Intro avanza también desde el teclado).
  useEffect(() => { if (listo) sigRef.current?.focus({ preventScroll: true }) }, [listo, i])

  // Primer paso del apartado siguiente, si al actual le quedan pasos después de este.
  let saltarA = null
  if (pasos && p && pasos[i + 1]?.sec === p.sec) {
    const j = pasos.findIndex((s, k) => k > i && s.sec !== p.sec)
    saltarA = j === -1 ? null : j
  }

  // Teclado: flechas para moverse (adelante, solo con la pantalla cargada), Esc para salir.
  useEffect(() => {
    const k = (e) => {
      if (e.key === 'Escape') { e.preventDefault(); cerrar() }
      else if (e.key === 'ArrowRight' || e.key === 'Enter') { e.preventDefault(); if (listo) ir(i + 1) }
      else if (e.key === 'ArrowLeft') { e.preventDefault(); ir(i - 1) }
    }
    window.addEventListener('keydown', k, true)
    return () => window.removeEventListener('keydown', k, true)
  }, [i, ir, cerrar, listo])

  if (!pasos || !p) return null
  const ultimo = i === pasos.length - 1
  const enApartado = pasos.filter((s) => s.sec === p.sec)
  return createPortal(
    <div className="tour" role="dialog" aria-modal="true" aria-label="Tour guiado">
      <div className="tour-block" />
      {p.sel && rect && listo
        ? <div className="tour-spot" style={{ transform: `translate(${rect.left}px, ${rect.top}px)`, width: rect.right - rect.left, height: rect.bottom - rect.top }} />
        : <div className="tour-dim" />}
      <div ref={pop} className={`tour-pop${pos ? ' on' : ''}`} style={pos || { left: -9999, top: -9999 }}>
        <div className="tour-bar"><i style={{ width: `${((i + 1) / pasos.length) * 100}%` }} /></div>
        <div className="tour-h">
          <small>Paso {i + 1} de {pasos.length}</small>
          <button className="icon-btn" title="Cerrar el tour (Esc)" aria-label="Cerrar el tour" onClick={cerrar}><Icon.x /></button>
        </div>
        <div className="tour-body" key={p.id}>
          {enApartado.length > 1 ? <small className="tour-sec">{p.secTitle} · {enApartado.indexOf(p) + 1} de {enApartado.length}</small> : null}
          <h3>{p.title}</h3>
          <p>{p.text}</p>
          {p.article && onHelp ? <button className="tour-mas" onClick={() => { marcarVisto(); onHelp(p.article) }}>Más información <Icon.arrowRight className="ic-arrow" aria-hidden="true" /></button> : null}
        </div>
        <div className="tour-f">
          <button className="tour-prev" disabled={i === 0} onClick={() => ir(i - 1)}><Icon.chevron style={{ transform: 'rotate(90deg)' }} aria-hidden="true" /> Anterior</button>
          <span className="spacer" />
          {/* Mientras carga la pantalla o lo que se enseña, sin Siguiente ni Saltar. */}
          {!listo ? <span className="tour-load" role="status"><span className="spinner xs" /> Cargando…</span> : <>
            {saltarA !== null
              ? <button className="tour-skip" onClick={() => ir(saltarA)} title={`Saltar lo que queda de «${p.secTitle}»`}>Saltar apartado <Icon.chevron style={derecha} aria-hidden="true" /></button>
              : null}
            <button ref={sigRef} className="btn sm" onClick={() => ir(i + 1)}>{ultimo ? 'Terminar' : <>Siguiente <Icon.chevron style={derecha} aria-hidden="true" /></>}</button>
          </>}
        </div>
      </div>
    </div>,
    document.body,
  )
}
