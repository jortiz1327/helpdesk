import { useState, useEffect, useRef, useMemo } from 'react'
import { portal } from './portalApi.js'
import { useLang } from './i18n.js'
import { I, Rich, Adjuntar, Adjuntos, CorreoFrame, esCorreo, relTime, fmtDate, fmtHora, escapeHtml } from './Portal.jsx'
import { leerCodigos, reducirFoto } from '../barcode.js'
import './garantias.css'

/* ---------------------------------------------------------------------------
 * GARANTÍAS en el portal del cliente.
 *
 * Quien entra ve las garantías de SU EMPRESA (el servidor decide cuáles por su correo):
 * en qué fase está cada una, el resultado de cada etiqueta, sus documentos y la
 * conversación con el técnico. «Solicitar» es un formulario guiado que crea un ticket
 * con todo ordenado (dónde, qué etiquetas, qué les pasa y sus fotos); la garantía la
 * crea el equipo desde ese ticket.
 * ------------------------------------------------------------------------- */

const G = {
  shield: <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2"><path d="M12 3l8 3v6c0 4.5-3.4 8.3-8 9-4.6-.7-8-4.5-8-9V6l8-3z" /><path d="M8.5 12l2.5 2.5 4.5-5" /></svg>,
  tag: <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2"><path d="M3 7a2 2 0 0 1 2-2h9l7 7-7 7H5a2 2 0 0 1-2-2z" /><circle cx="8" cy="12" r="1.3" /></svg>,
  ap: <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2"><rect x="4" y="13" width="16" height="7" rx="2" /><path d="M8 13l-1-4M16 13l1-4M8.5 6.5a5 5 0 0 1 7 0M6 4a8.5 8.5 0 0 1 12 0" /></svg>,
  building: <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2"><rect x="4" y="3" width="16" height="18" rx="2" /><path d="M9 7h1M14 7h1M9 11h1M14 11h1M9 15h1M14 15h1M10 21v-3h4v3" /></svg>,
  camera: <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2"><path d="M4 8h3l2-3h6l2 3h3v11H4z" /><circle cx="12" cy="13" r="3.5" /></svg>,
  barcode: <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2"><path d="M4 6v12M7 6v12M10 6v12M14 6v12M16 6v12M20 6v12" /></svg>,
  trash: <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2"><path d="M4 7h16M9 7V4h6v3M6 7l1 13h10l1-13" /></svg>,
  edit: <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2"><path d="M4 20h4L19 9l-4-4L4 16z" /></svg>,
  doc: <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2"><path d="M14 3v5h5M14 3H6a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z" /><path d="M8 13h8M8 17h5" /></svg>,
  warn: <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2"><path d="M12 3l10 18H2z" /><path d="M12 10v5M12 18v.5" /></svg>,
  box: <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2"><path d="M3 7l9-4 9 4v10l-9 4-9-4z" /><path d="M3 7l9 4 9-4M12 11v10" /></svg>,
  pin: <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2"><path d="M12 21s-7-6.2-7-11.5a7 7 0 0 1 14 0C19 14.8 12 21 12 21z" /><circle cx="12" cy="9.5" r="2.5" /></svg>,
  chev: <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2"><path d="M6 9l6 6 6-6" /></svg>,
  // Lo que le pasa a un dispositivo (en el desplegable).
  no_enciende: <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2"><path d="M12 3v8M7.5 6.5a7 7 0 1 0 9 0" /></svg>,
  pantalla: <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2"><rect x="3" y="5" width="18" height="13" rx="2" /><path d="M10 5l2 5-3 3 2 5" /></svg>,
  sin_conexion: <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2"><path d="M2 8.5a15 15 0 0 1 20 0M5.5 12a10 10 0 0 1 13 0M9 15.5a5 5 0 0 1 6 0M12 19h.01M3 3l18 18" /></svg>,
  bateria: <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2"><rect x="2" y="7" width="17" height="10" rx="2" /><path d="M22 11v2M6 10v4" /></svg>,
  otro: <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2"><circle cx="12" cy="12" r="9" /><path d="M9.5 9.5a2.5 2.5 0 1 1 3.5 2.3c-.7.3-1 .9-1 1.6M12 17h.01" /></svg>,
}

/*
 * Desplegable con el aspecto del portal (en vez del <select> del sistema): botón con lo
 * elegido y una lista con icono y explicación de cada opción. Teclado: flechas, Inicio,
 * Fin, Intro/espacio para elegir y Esc para cerrar.
 * options: [{ value, label, desc?, icon? }]
 */
function Desplegable({ value, options, onChange, placeholder, label, className = '', invalid = false }) {
  const [abierto, setAbierto] = useState(false)
  const [activo, setActivo] = useState(0)
  const raiz = useRef(null)
  const boton = useRef(null)
  const lista = useRef(null)
  const sel = options.find((o) => o.value === value)

  useEffect(() => {
    if (!abierto) return
    const fuera = (e) => { if (!raiz.current?.contains(e.target)) setAbierto(false) }
    document.addEventListener('pointerdown', fuera)
    return () => document.removeEventListener('pointerdown', fuera)
  }, [abierto])
  useEffect(() => { if (abierto) lista.current?.children[activo]?.scrollIntoView({ block: 'nearest' }) }, [abierto, activo])

  const abrir = () => { setActivo(Math.max(0, options.findIndex((o) => o.value === value))); setAbierto(true) }
  const elegir = (o) => { onChange(o.value); setAbierto(false); boton.current?.focus() }
  const tecla = (e) => {
    if (!abierto) {
      if (['ArrowDown', 'ArrowUp', 'Enter', ' '].includes(e.key)) { e.preventDefault(); abrir() }
      return
    }
    const mover = { ArrowDown: (a) => Math.min(options.length - 1, a + 1), ArrowUp: (a) => Math.max(0, a - 1), Home: () => 0, End: () => options.length - 1 }[e.key]
    if (mover) { e.preventDefault(); setActivo(mover) }
    else if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); if (options[activo]) elegir(options[activo]) }
    else if (e.key === 'Escape') { e.preventDefault(); setAbierto(false) }
    else if (e.key === 'Tab') setAbierto(false)
  }

  return (
    <div className={`gw-dd ${abierto ? 'on' : ''} ${className}`} ref={raiz}>
      <button type="button" ref={boton} className={`gw-dd-b ${sel ? '' : 'vacio'} ${invalid ? 'mal' : ''}`} aria-haspopup="listbox" aria-expanded={abierto}
        aria-label={label} onClick={() => (abierto ? setAbierto(false) : abrir())} onKeyDown={tecla}>
        {sel?.icon && <span className="gw-dd-ic">{sel.icon}</span>}
        <span className="gw-dd-v">{sel ? sel.label : placeholder}</span>
        <span className="gw-dd-chev">{G.chev}</span>
      </button>
      {abierto && (
        <ul className="gw-dd-l" role="listbox" ref={lista} aria-label={label}>
          {options.map((o, k) => (
            <li key={o.value || '_'} role="option" aria-selected={o.value === value}
              className={`${k === activo ? 'act' : ''} ${o.value === value ? 'sel' : ''}`}
              onPointerEnter={() => setActivo(k)} onPointerDown={(e) => e.preventDefault()} onClick={() => elegir(o)}>
              {o.icon && <span className="gw-dd-ic">{o.icon}</span>}
              <span className="gw-dd-t"><b>{o.label}</b>{o.desc && <small>{o.desc}</small>}</span>
              {o.value === value && <span className="gw-dd-ok">{I.check}</span>}
            </li>
          ))}
        </ul>
      )}
    </div>
  )
}

// Fotos de cada dispositivo: las mismas que pide la revisión del equipo.
const FOTOS = { esl: ['front', 'back_batteries', 'back_no_batteries'], ap: ['front', 'back'] }
const ISSUES = ['no_enciende', 'pantalla', 'sin_conexion', 'bateria', 'otro']
// Con un fallo de la lista basta; con «Otro» tiene que contar qué le pasa, como mínimo
// esto (lo mismo exige el servidor): sin explicación no se sabe si es un fallo o un uso
// que no cubre la garantía.
const DETALLE_MIN = 15
// «Batería» o «No enciende» en una etiqueta: antes de pedirla, que pruebe a reiniciarla y
// con otras pilas (lo que diría soporte). Muchas vuelven a funcionar y no hace falta enviarlas.
const PRUEBA_ANTES = ['no_enciende', 'bateria']
const pidePrueba = (i) => i.kind === 'esl' && PRUEBA_ANTES.includes(i.issue)
const faltaPrueba = (i) => pidePrueba(i) && !i.tried
// Ya está en una garantía o solicitud en curso: i.ocupada es su referencia ('' si es de otro cliente).
const estaOcupada = (i) => typeof i.ocupada === 'string'
const faltaDetalle = (i) => i.issue === 'otro' && i.detail.trim().length < DETALLE_MIN
const explicado = (i) => !!i.issue && !faltaDetalle(i)
// Un código que no está en nuestros servidores y se añade igualmente: con sus fotos.
const fotosObligatorias = (i) => i.existe === false
const ESL = /^[A-Z0-9]{9}$/
const AP = /^[0-9A-F]{12}$/
const limpiar = (s) => String(s || '').toUpperCase().replace(/[\s:\-]/g, '')
const tipoDe = (c) => (AP.test(c) && !/^\d{9}$/.test(c) ? 'ap' : 'esl')

// Resultado de cada etiqueta → tono de la píldora.
const TONO = {
  pendiente: 'nuevo', pendiente_envio: 'nuevo', correcta: 'ok', recuperada: 'ok', reparada: 'ok', no_reproduce: 'ok',
  cubierta: 'ok', sustituida: 'brand', fabricante: 'wait', bateria: 'wait', defectuosa: 'gris', no_cubierta: 'gris', desechada: 'gris',
}

/* «8 etiquetas · 1 antena» en su idioma. */
function cuantos(t, labels, aps) {
  return [labels ? t(labels === 1 ? 'g_n_label' : 'g_n_labels', { n: labels }) : null,
    aps ? t(aps === 1 ? 'g_n_ap' : 'g_n_aps', { n: aps }) : null].filter(Boolean).join(' · ') || t('g_no_devices')
}

/* La línea de fases (lista y ficha). `grande` añade la fecha prevista de cada paso. */
function Fases({ steps, grande }) {
  const { t, lang } = useLang()
  return (
    <div className={`gw-fases ${grande ? 'big' : ''}`}>
      {steps.map((s, i) => (
        <div key={s.key} className={`gw-fase ${s.state}`}>
          {i > 0 && <span className="gw-fase-bar" />}
          <span className="gw-fase-dot">{s.state === 'done' ? I.check : s.state === 'current' ? <i /> : null}</span>
          <span className="gw-fase-lb">{t('g_phase_' + s.key)}</span>
          <span className="gw-fase-dt">{s.state === 'skipped' ? t('g_phase_skipped') : s.date ? fmtDate(s.date, lang) : s.state === 'current' ? t('g_now') : '—'}</span>
        </div>
      ))}
    </div>
  )
}

function Pildora({ w }) {
  const { t } = useLang()
  if (w.hint === 'tu_respuesta') return <span className="gw-pill wait"><span className="cd" />{t('g_hint_tu_respuesta_short')}</span>
  if (w.hint === 'fabricante') return <span className="gw-pill wait"><span className="cd" />{t('g_phase_fabricante')}</span>
  const tono = { solicitada: 'nuevo', recibida: 'brand', revision: 'brand', fabricante: 'wait', resuelta: 'ok' }[w.phase] || 'brand'
  return <span className={`gw-pill ${tono}`}><span className="cd" />{w.hint && w.hint !== 'material' ? t('g_hint_' + w.hint + '_short') : t('g_phase_' + w.phase)}</span>
}

/* ------------------------------ Mis garantías ----------------------------- */
export function MisGarantias({ go, onOpen, onExpire }) {
  const { t, lang } = useLang()
  const [d, setD] = useState(null)
  const [tab, setTab] = useState('curso')
  const [q, setQ] = useState('')
  const [sitio, setSitio] = useState('')

  useEffect(() => {
    portal.warranties().then((r) => { if (r.reauth) return onExpire(); setD(r.ok ? r : { warranties: [] }) })
  }, []) // eslint-disable-line react-hooks/exhaustive-deps

  const todas = d?.warranties || []
  const sitios = useMemo(() => [...new Set(todas.map((w) => w.place).filter(Boolean))].sort(), [todas])
  const enCurso = todas.filter((w) => w.open)
  const resueltas = todas.filter((w) => !w.open)
  const base = tab === 'curso' ? enCurso : tab === 'resueltas' ? resueltas : todas
  const filtro = limpiar(q)
  const rows = base.filter((w) => (!sitio || w.place === sitio)
    && (!filtro || limpiar(`${w.code}${w.ticket || ''}${w.place}`).includes(filtro)))

  return (
    <section className="screen on"><div className="gw-wrap">
      <button className="back" onClick={() => go('home')}>{I.back}{t('home')}</button>
      <div className="gw-head">
        <div>
          <h1>{d?.company ? t('g_list_title_company', { company: d.company }) : t('g_list_title')}</h1>
          <p>{d?.company ? t('g_list_sub_company') : t('g_list_sub')}</p>
        </div>
        <button className="btn gw-cta" onClick={() => go('g_solicitar')}>{G.shield} {t('g_request_short')}</button>
      </div>

      {todas.length > 0 && (
        <div className="gw-tools">
          <div className="mis-filtro">
            {[['curso', t('g_tab_open'), enCurso.length], ['resueltas', t('g_tab_done'), resueltas.length], ['todas', t('g_tab_all'), todas.length]].map(([k, lb, n]) => (
              <button key={k} className={tab === k ? 'on' : ''} onClick={() => setTab(k)}>{lb} <em>{n}</em></button>
            ))}
          </div>
          <label className="gw-search">{I.mag}
            <input value={q} onChange={(e) => setQ(e.target.value)} placeholder={t('g_search_ph')} /></label>
          {sitios.length > 1 && (
            <Desplegable className="gw-sitio" value={sitio} onChange={setSitio} label={t('g_all_places')}
              options={[{ value: '', label: t('g_all_places'), icon: G.building }, ...sitios.map((s) => ({ value: s, label: s, icon: G.building }))]} />
          )}
        </div>
      )}

      {d === null ? <div className="mis-cargando">{t('loading')}</div>
        : todas.length === 0 ? (
          <div className="mis-vacia">
            <div className="mis-vacia-ic">{G.shield}</div>
            <b>{t('g_empty_title')}</b>
            <p>{t('g_empty_sub')}</p>
            <button className="btn" style={{ width: 'auto', margin: '4px auto 0' }} onClick={() => go('g_solicitar')}>{G.shield} {t('g_request')}</button>
          </div>
        ) : rows.length === 0 ? <div className="mis-cargando">{t('g_none_filter')}</div> : (
          <div className="gw-list">
            {rows.map((w) => (
              <button key={w.code} className={`gw-card ${w.open ? '' : 'apagada'}`} onClick={() => onOpen(w.code)}>
                <div className="gw-card-top">
                  <div className="gw-card-id">
                    <span className="gw-code">{w.code}</span>
                    <Pildora w={w} />
                  </div>
                  <span className="gw-when">{t('g_updated', { time: relTime(w.updated, t, lang) })}</span>
                </div>
                <div className="gw-meta">
                  {w.place && <span>{G.building}{w.place}</span>}
                  {w.labels > 0 && <span className="gw-chip">{G.tag}{t(w.labels === 1 ? 'g_n_label' : 'g_n_labels', { n: w.labels })}</span>}
                  {w.aps > 0 && <span className="gw-chip">{G.ap}{t(w.aps === 1 ? 'g_n_ap' : 'g_n_aps', { n: w.aps })}</span>}
                  {w.type === 'request' && <span className="gw-tk">{t('g_ticket')} {w.ticket}</span>}
                </div>
                <Fases steps={w.steps} />
                {w.hint && ['tu_respuesta', 'material', 'preparando_envio', 'devuelta'].includes(w.hint) && (
                  <div className={`gw-aviso ${w.hint === 'tu_respuesta' ? 'wait' : ''}`}>{I.info}<span>{t('g_hint_' + w.hint)}</span></div>
                )}
                <span className="gw-go">{t('g_view')} {I.arrow}</span>
              </button>
            ))}
          </div>
        )}
    </div></section>
  )
}

/* -------------------------------- La ficha -------------------------------- */
function Resultado({ r }) {
  const { t } = useLang()
  return (
    <span className="gw-res">
      <span className={`gw-pill ${TONO[r.key] || 'gris'}`}><span className="cd" />{t('g_res_' + r.key)}</span>
      {r.reasons?.length > 0 && <small>{r.reasons.map((k) => t('g_reason_' + k)).join(', ')}</small>}
    </span>
  )
}

/* «Ya lo he enviado»: el cliente avisa de que nos ha mandado el material. No es
   obligatorio (el paquete llega igual), pero con el seguimiento lo esperamos y lo
   localizamos antes. Lo avisado se queda a la vista, también para sus compañeros. */
const TRANSPORTISTAS = ['SEUR', 'MRW', 'Correos', 'Correos Express', 'GLS', 'Nacex', 'DHL', 'UPS', 'FedEx', 'TNT', 'CTT']
const hoyISO = () => { const d = new Date(); d.setMinutes(d.getMinutes() - d.getTimezoneOffset()); return d.toISOString().slice(0, 10) }
function EnvioMaterial({ w, onDone, onExpire }) {
  const { t, lang } = useLang()
  const vacio = () => ({ shipped_on: hoyISO(), carrier: '', tracking: '', packages: '1', units: String((w.labels || 0) + (w.aps || 0) || ''), note: '' })
  const [abierto, setAbierto] = useState(false)
  const [f, setF] = useState(vacio)
  const [busy, setBusy] = useState(false)
  const [err, setErr] = useState('')
  const [ok, setOk] = useState(false)
  const set = (c) => setF((x) => ({ ...x, ...c }))
  const envios = w.shipments || []
  if (!w.can_ship && !envios.length) return null

  const enviar = async (e) => {
    e.preventDefault()
    setBusy(true); setErr('')
    const r = await portal.warrantyShipment(w.code, f)
    setBusy(false)
    if (r.reauth) return onExpire()
    if (!r.ok) { setErr(r.error || t('err_create')); return }
    setAbierto(false); setF(vacio()); setOk(true)
    onDone()
  }

  return (
    <div className="gw-box gw-envio">
      <div className="gw-box-h"><h2>{G.box} {t('g_ship_title')}</h2>
        {w.can_ship && !abierto && <button className="btn sec gw-envio-b" onClick={() => { setAbierto(true); setOk(false) }}>{envios.length ? t('g_ship_another') : t('g_ship_btn')}</button>}
      </div>
      {!envios.length && !abierto && <p className="gw-mute">{t('g_ship_intro')}</p>}
      {ok && <div className="gw-aviso">{I.check}<span>{t('g_ship_ok')}</span></div>}
      {envios.length > 0 && (
        <ul className="gw-envios">
          {envios.map((s, i) => (
            <li key={i}>
              <b>{t('g_ship_sent_on', { date: fmtDate(s.shipped_on, lang) })}</b>
              <span>{[s.carrier, s.tracking ? `${t('g_ship_tracking')}: ${s.tracking}` : null].filter(Boolean).join(' · ') || t('g_ship_no_tracking')}</span>
              <small>{t(s.packages === 1 ? 'g_ship_n_package' : 'g_ship_n_packages', { n: s.packages })}{s.units ? ` · ${t('g_ship_n_units', { n: s.units })}` : ''}{s.email ? ` · ${s.email}` : ''}</small>
              {s.note && <small className="gw-envio-nota">{s.note}</small>}
            </li>
          ))}
        </ul>
      )}
      {abierto && (
        <form className="gw-envio-f" onSubmit={enviar}>
          <p className="gw-mute">{t('g_ship_form_intro')}</p>
          <div className="gw-grid2">
            <label className="f"><span className="lab">{t('g_ship_date')}</span>
              <input className="inp" type="date" required value={f.shipped_on} max={hoyISO()} onChange={(e) => set({ shipped_on: e.target.value })} /></label>
            <label className="f"><span className="lab">{t('g_ship_carrier')} <span className="hint" style={{ fontWeight: 400 }}>{t('g_optional')}</span></span>
              <input className="inp" list="gw-transportistas" maxLength={60} value={f.carrier} onChange={(e) => set({ carrier: e.target.value })} placeholder="SEUR, MRW, Correos…" />
              <datalist id="gw-transportistas">{TRANSPORTISTAS.map((x) => <option key={x} value={x} />)}</datalist></label>
          </div>
          <label className="f"><span className="lab">{t('g_ship_tracking')} <span className="hint" style={{ fontWeight: 400 }}>{t('g_ship_tracking_hint')}</span></span>
            <input className="inp gw-mono" maxLength={80} value={f.tracking} onChange={(e) => set({ tracking: e.target.value })} autoComplete="off" /></label>
          <div className="gw-grid2">
            <label className="f"><span className="lab">{t('g_ship_packages')}</span>
              <input className="inp" type="number" min={1} max={99} required value={f.packages} onChange={(e) => set({ packages: e.target.value })} /></label>
            <label className="f"><span className="lab">{t('g_ship_units')} <span className="hint" style={{ fontWeight: 400 }}>{t('g_optional')}</span></span>
              <input className="inp" type="number" min={1} max={5000} value={f.units} onChange={(e) => set({ units: e.target.value })} /></label>
          </div>
          <label className="f"><span className="lab">{t('g_ship_note')} <span className="hint" style={{ fontWeight: 400 }}>{t('g_optional')}</span></span>
            <input className="inp" maxLength={300} value={f.note} onChange={(e) => set({ note: e.target.value })} placeholder={t('g_ship_note_ph')} /></label>
          {err && <p className="gw-err">{err}</p>}
          <div className="gw-envio-acts">
            <button type="button" className="btn sec" onClick={() => { setAbierto(false); setErr('') }}>{t('g_ship_cancel')}</button>
            <button className="btn" disabled={busy}>{busy ? t('sending') : t('g_ship_send')}</button>
          </div>
        </form>
      )}
    </div>
  )
}

export function DetalleGarantia({ code, back, onExpire }) {
  const { t, lang } = useLang()
  const [w, setW] = useState(null)
  const [txt, setTxt] = useState('')
  const [files, setFiles] = useState([])
  const [busy, setBusy] = useState(false)
  const [err, setErr] = useState('')
  const [bajando, setBajando] = useState('')
  const endRef = useRef(null)

  const load = () => portal.warranty(code).then((r) => { if (r.reauth) return onExpire(); setW(r.ok ? r.warranty : false) })
  useEffect(() => { load() }, [code]) // eslint-disable-line react-hooks/exhaustive-deps

  const responder = async () => {
    if (!txt.trim() && !files.length) return
    setBusy(true); setErr('')
    const r = await portal.warrantyReply(code, txt, files)
    setBusy(false)
    if (r.reauth) return onExpire()
    if (!r.ok) { setErr(r.error || t('err_create')); return }
    setTxt(''); setFiles([])
    await load()
    endRef.current?.scrollIntoView({ behavior: 'smooth' })
  }

  const descargar = async (doc) => {
    setBajando(doc)
    const r = await portal.warrantyPdf(code, doc)
    setBajando('')
    if (!r.ok) return
    const url = URL.createObjectURL(r.blob)
    const a = document.createElement('a')
    a.href = url; a.download = `${doc === 'sticker' ? 'pegatina' : 'informe'}-${code}.pdf`
    document.body.appendChild(a); a.click(); a.remove()
    URL.revokeObjectURL(url)
  }

  if (w === null) return <section className="screen on"><div className="gw-wrap gw-cargando">{t('loading')}</div></section>
  if (w === false) return <section className="screen on"><div className="gw-wrap">
    <button className="back" onClick={back}>{I.back}{t('g_back_list')}</button>
    <div className="faq-empty">{t('g_not_found')}</div>
  </div></section>

  const actual = w.steps.find((s) => s.state === 'current')?.key || w.phase
  const explica = w.hint && ['tu_respuesta', 'material', 'preparando_envio', 'devuelta'].includes(w.hint) ? t('g_hint_' + w.hint) : t('g_explain_' + actual)

  return (
    <section className="screen on"><div className="gw-wrap gw-det">
      <div className="gw-migas">
        <button onClick={back}>{t('g_my_warranties')}</button>{I.back}<span>{w.code}</span>
        <button className="gw-volver" onClick={back}>{I.back} {t('g_back_list')}</button>
      </div>
      <div className="gw-det-h">
        <h1>{w.type === 'request' ? t('g_request_of', { code: w.code }) : t('g_warranty_of', { code: w.code })}{w.place ? ` · ${w.place}` : ''}</h1>
        <Pildora w={w} />
      </div>
      <p className="gw-det-sub">{cuantos(t, w.labels, w.aps)} · {t('g_updated', { time: relTime(w.updated, t, lang) })}</p>

      <div className="gw-box">
        <div className="gw-box-h"><h2>{t('g_progress')}</h2></div>
        <Fases steps={w.steps} grande />
        <div className={`gw-aviso ${w.hint === 'tu_respuesta' ? 'wait' : ''}`}>{I.info}<span>{explica}</span></div>
        {w.phase !== 'resuelta' && (
          <details className="gw-como">
            <summary>{t('g_how_it_works')}</summary>
            <ProcesoGarantia code={w.code} />
          </details>
        )}
      </div>

      <EnvioMaterial w={w} onDone={load} onExpire={onExpire} />

      <div className="gw-cols">
        <div className="gw-box">
          <div className="gw-box-h"><h2>{G.tag} {t('g_your_devices', { n: w.items.length })}</h2></div>
          {w.items.length === 0 ? <p className="gw-mute">{t('g_no_devices_yet')}</p> : (
            <>
              <table className="gw-tabla">
                <thead><tr><th>{t('g_col_code')}</th><th>{t('g_col_model')}</th><th>{t('g_col_issue')}</th><th>{t('g_col_result')}</th></tr></thead>
                <tbody>
                  {w.items.map((i) => (
                    <tr key={i.code}>
                      <td><span className="gw-mono">{i.code}</span>{i.kind === 'ap' && <small className="gw-kind">{t('g_antenna')}</small>}</td>
                      <td>{i.model || <span className="gw-mute">—</span>}</td>
                      <td>{i.issue_key ? `${t('g_issue_' + i.issue_key)}${i.detail ? ' — ' + i.detail : ''}` : i.issue || <span className="gw-mute">—</span>}</td>
                      <td><Resultado r={i.result} /></td>
                    </tr>
                  ))}
                </tbody>
              </table>
              <div className="gw-tarjetas">
                {w.items.map((i) => (
                  <div key={i.code} className="gw-tarjeta">
                    <div><span className="gw-mono">{i.code}</span>{i.kind === 'ap' && <small className="gw-kind">{t('g_antenna')}</small>}</div>
                    {i.model && <small>{i.model}</small>}
                    {(i.issue_key || i.issue) && <p>{i.issue_key ? `${t('g_issue_' + i.issue_key)}${i.detail ? ' — ' + i.detail : ''}` : i.issue}</p>}
                    <Resultado r={i.result} />
                  </div>
                ))}
              </div>
            </>
          )}
        </div>

        <div className="gw-lado">
          {w.documents.length > 0 && (
            <div className="gw-box">
              <div className="gw-box-h"><h2>{G.doc} {t('g_documents')}</h2></div>
              {w.documents.map((doc) => (
                <button key={doc.key} className="gw-doc" onClick={() => descargar(doc.key)} disabled={!!bajando}>
                  <span className="gw-doc-ic">{doc.key === 'sticker' ? G.box : G.doc}</span>
                  <span className="gw-doc-tx">
                    <b>{t(doc.key === 'sticker' ? 'g_doc_sticker' : 'g_doc_report')}</b>
                    <small>{bajando === doc.key ? t('pdf_generating') : doc.key === 'sticker' ? t('g_doc_sticker_hint') : doc.number || 'PDF'}</small>
                  </span>
                  <span className="gw-doc-dl">{I.down}</span>
                </button>
              ))}
            </div>
          )}
          <div className="gw-box">
            <div className="gw-box-h"><h2>{G.building} {t('g_data')}</h2></div>
            <dl className="gw-datos">
              {w.company && <><dt>{t('g_company')}</dt><dd>{w.company}</dd></>}
              {w.place && <><dt>{t('g_place')}</dt><dd>{w.place}</dd></>}
              {w.ticket && <><dt>{t('g_ticket')}</dt><dd className="gw-mono">{w.ticket}</dd></>}
              <dt>{w.type === 'request' ? t('g_requested_on') : t('g_received_on')}</dt><dd>{fmtDate(w.received_at || w.created_at, lang)}</dd>
              {w.closed_at && <><dt>{t('g_closed_on')}</dt><dd>{fmtDate(w.closed_at, lang)}</dd></>}
            </dl>
          </div>
        </div>
      </div>

      <div className="gw-box gw-conv">
        <div className="gw-box-h"><h2>{t('g_conversation')}</h2></div>
        {w.messages.length === 0 && <p className="gw-mute">{w.can_reply ? t('g_conv_empty') : t('g_conv_none')}</p>}
        <div className="tl">
          {w.messages.map((m, i) => {
            const yo = m.de === 'cliente'
            return (
              <div key={i} className={`tl-item ${yo ? 'yo' : 'sop'}`}>
                <div className="tl-av">{yo ? t('me_avatar') : 'AE'}</div>
                <div className="tl-content">
                  <div className="tl-head">
                    <b>{yo ? t('g_your_company') : (m.autor || t('support_name'))}</b>
                    <span className="tl-time" title={fmtHora(m.fecha, lang)}>{relTime(m.fecha, t, lang)}</span>
                  </div>
                  {m.html && esCorreo(m.cuerpo)
                    ? <div className="tl-paper"><CorreoFrame html={m.cuerpo} /></div>
                    : <div className="tl-bubble" dangerouslySetInnerHTML={{ __html: m.html ? m.cuerpo : escapeHtml(m.cuerpo) }} />}
                  <Adjuntos items={m.adjuntos} />
                </div>
              </div>
            )
          })}
          <div ref={endRef} />
        </div>
        {w.can_reply && (
          <div className="reply">
            <span className="reply-lab">{t('reply_to_support')}</span>
            <textarea value={txt} onChange={(e) => setTxt(e.target.value)} placeholder={t('g_reply_ph')} />
            <Adjuntar files={files} setFiles={setFiles} compacta />
            {err && <p className="hint" style={{ color: 'var(--danger)' }}>{err}</p>}
            <div className="reply-foot">
              <button className="btn" style={{ width: 'auto', marginLeft: 'auto', padding: '11px 22px' }}
                disabled={busy || (!txt.trim() && !files.length)} onClick={responder}>{busy ? t('sending') : <>{I.send} {t('reply_btn')}</>}</button>
            </div>
          </div>
        )}
      </div>
    </div></section>
  )
}

/* --------------------------- Solicitar garantía --------------------------- */

const hayCamara = typeof navigator !== 'undefined' && !!navigator.mediaDevices?.getUserMedia

/*
 * Cámara en directo: lee los códigos de barras de lo que enfoca, uno tras otro, sin hacer
 * fotos. Cada código nuevo se añade al momento (con ese fotograma como foto frontal) y
 * la cámara sigue abierta para el siguiente. `ya`: los códigos que ya están en la lista.
 */
function EscanerCamara({ ya, onCodes, onClose }) {
  const { t } = useLang()
  const video = useRef(null)
  const vistos = useRef(new Set(ya))
  const avisar = useRef(onCodes)
  avisar.current = onCodes
  const [err, setErr] = useState('')
  const [nuevos, setNuevos] = useState([])
  const [nota, setNota] = useState(null)   // {code, repe}

  useEffect(() => {
    let vivo = true, stream = null, quitarNota = null
    const espera = (ms) => new Promise((r) => setTimeout(r, ms))
    ;(async () => {
      try {
        stream = await navigator.mediaDevices.getUserMedia({
          audio: false, video: { facingMode: { ideal: 'environment' }, width: { ideal: 1920 }, height: { ideal: 1080 } },
        })
      } catch (e) {
        if (vivo) setErr(e?.name === 'NotAllowedError' || e?.name === 'SecurityError' ? t('g_cam_denied') : t('g_cam_fail'))
        return
      }
      if (!vivo) { stream.getTracks().forEach((k) => k.stop()); return }
      const v = video.current
      v.srcObject = stream
      try { await v.play() } catch { /* algunos navegadores ya lo reproducen solo */ }
      const c = document.createElement('canvas')
      while (vivo) {
        await espera(200)
        if (!vivo || v.readyState < 2 || !v.videoWidth) continue
        c.width = v.videoWidth; c.height = v.videoHeight
        c.getContext('2d').drawImage(v, 0, 0)
        const blob = await new Promise((ok) => c.toBlob(ok, 'image/jpeg', 0.9))
        if (!blob || !vivo) continue
        const file = new File([blob], `camara-${Date.now()}.jpg`, { type: 'image/jpeg' })
        const leidos = await leerCodigos(file, { rapido: true })
        if (!vivo || !leidos.length) continue
        const frescos = leidos.filter((x) => !vistos.current.has(x))
        if (!frescos.length) { setNota({ code: leidos[0], repe: true }) } else {
          frescos.forEach((x) => vistos.current.add(x))
          avisar.current(frescos, frescos.length === 1 ? file : null)
          setNuevos((s) => [...s, ...frescos])
          setNota({ code: frescos.join(', '), repe: false })
          navigator.vibrate?.(60)
        }
        clearTimeout(quitarNota); quitarNota = setTimeout(() => vivo && setNota(null), 2200)
        await espera(700)   // un respiro para apartar la etiqueta antes de leer otra
      }
    })()
    const tecla = (e) => { if (e.key === 'Escape') onClose() }
    window.addEventListener('keydown', tecla)
    return () => {
      vivo = false; clearTimeout(quitarNota)
      window.removeEventListener('keydown', tecla)
      stream?.getTracks().forEach((k) => k.stop())
    }
  }, []) // eslint-disable-line react-hooks/exhaustive-deps

  return (
    <div className="gw-cam" role="dialog" aria-modal="true" aria-label={t('g_cam_title')}>
      <div className="gw-cam-box">
        <div className="gw-cam-h">
          <div><b>{t('g_cam_title')}</b><span>{t('g_cam_sub')}</span></div>
          <button type="button" className="gw-cam-x" onClick={onClose} aria-label={t('g_cam_done')}>{I.x}</button>
        </div>
        {err ? <p className="gw-err">{G.warn} {err}</p> : (
          <div className="gw-cam-v">
            <video ref={video} playsInline muted autoPlay />
            <span className="gw-cam-marco" aria-hidden="true" />
            {nota && <span className={`gw-cam-nota ${nota.repe ? 'repe' : ''}`} role="status">
              {nota.repe ? t('g_cam_already', { code: nota.code }) : t('g_cam_added', { code: nota.code })}</span>}
          </div>
        )}
        <div className="gw-cam-pie">
          <span>{nuevos.length ? t('g_cam_n', { n: nuevos.length }) : ''}</span>
          <button type="button" className="btn" onClick={onClose}>{t('g_cam_done')}</button>
        </div>
      </div>
    </div>
  )
}

/* Dónde está el código: una etiqueta mostrando un producto, con su código de barras (el
   pequeño del borde, sin número: el número solo sale si está desvinculada) marcado. */
function AyudaCodigo({ onClose }) {
  const { t } = useLang()
  return (
    <div className="gw-ayuda">
      <svg className="gw-ayuda-img" viewBox="0 0 220 150" aria-hidden="true">
        <rect x="6" y="6" width="208" height="138" rx="10" fill="#fff" stroke="#c9d3e0" strokeWidth="3" />
        <rect x="20" y="20" width="148" height="110" rx="4" fill="#eef3f9" />
        <rect x="30" y="32" width="96" height="9" rx="2" fill="#9aabbf" />
        <rect x="30" y="47" width="64" height="7" rx="2" fill="#c3cfdc" />
        <text x="30" y="108" fontSize="30" fontWeight="800" fill="#46586e">3,95€</text>
        {[0, 3, 5, 9, 12, 14, 18, 21, 25, 27, 30, 34, 37, 40, 44, 46, 50, 53, 57, 60, 63, 67, 70, 74, 77, 80].map((y, i) => (
          <rect key={i} x="181" y={34 + y} width="14" height={i % 3 ? 1.6 : 2.6} fill="#0e1e33" />
        ))}
        <rect x="175" y="28" width="26" height="94" rx="4" fill="none" stroke="#e5484d" strokeWidth="2.5" />
      </svg>
      <div className="gw-ayuda-tx">
        <b>{t('g_help_code_title')}</b>
        <Rich tag="p" html={t('g_help_code_1')} />
        <Rich tag="p" html={t('g_help_code_2')} />
        <Rich tag="p" html={t('g_help_code_3')} />
        {onClose && <button className="linkbtn" onClick={onClose}>{t('g_got_it')}</button>}
      </div>
    </div>
  )
}

/* Una casilla de foto: vacía (subir) o con la miniatura (cambiar o quitar). */
/* Prueba antes de pedir: cómo reiniciarla y con otras pilas. «Ya lo he probado y sigue
   igual» deja seguir; «Ya funciona» la quita de la solicitud. Los pasos se abren solos en
   la primera que lo necesita; en las demás, plegados (son los mismos). */
const PASOS_REINICIO = 8
function PruebaAntes({ abierta, onTried, onWorks, marcar }) {
  const { t } = useLang()
  const [ver, setVer] = useState(abierta)
  return (
    <div className={`gw-prueba ${marcar ? 'mal' : ''}`} role="group" aria-label={t('g_try_title')}>
      <div className="gw-prueba-h">
        <span className="gw-prueba-ic">{G.bateria}</span>
        <p><b>{t('g_try_title')}</b><small>{t('g_try_sub')}</small></p>
        <button type="button" className={`gw-prueba-tg ${ver ? 'on' : ''}`} aria-expanded={ver} onClick={() => setVer((v) => !v)}>
          {ver ? t('g_try_hide') : t('g_try_how')} {G.chev}</button>
      </div>
      {/* Se despliega animando la altura (filas 0fr → 1fr), sin saltos. */}
      <div className={`gw-prueba-cuerpo ${ver ? 'on' : ''}`} aria-hidden={!ver}>
        <div>
          <ol className="gw-prueba-pasos">{Array.from({ length: PASOS_REINICIO }, (_, n) => <li key={n}>{t('g_try_step_' + (n + 1))}</li>)}</ol>
          <div className="gw-prueba-notas">
            <p>{G.ap}<span>{t('g_try_range')}</span></p>
            <p>{G.bateria}<span>{t('g_try_batteries')}</span></p>
            <p className="aviso">{G.warn}<span>{t('g_try_brands')}</span></p>
          </div>
        </div>
      </div>
      <div className="gw-prueba-acts">
        <button type="button" className="btn sec" onClick={onWorks}>{I.check} {t('g_try_works')}</button>
        <button type="button" className="btn" onClick={onTried}>{t('g_try_still')}</button>
      </div>
    </div>
  )
}

function FotoSlot({ tipo, kind, foto, onPick, onRemove }) {
  const { t } = useLang()
  const ref = useRef(null)
  const url = useMemo(() => (foto ? URL.createObjectURL(foto.file) : null), [foto])
  useEffect(() => () => url && URL.revokeObjectURL(url), [url])
  return (
    <div className={`gw-foto ${foto ? 'llena' : ''}`}>
      <input ref={ref} type="file" accept="image/*" capture="environment" hidden
        onChange={(e) => { const f = e.target.files?.[0]; e.target.value = ''; if (f) onPick(f) }} />
      <button type="button" className="gw-foto-b" onClick={() => ref.current?.click()} title={t(`g_photo_${kind}_${tipo}`)}>
        {url ? <img src={url} alt="" /> : G.camera}
      </button>
      <span className="gw-foto-lb">{t(`g_photo_${kind}_${tipo}`)}</span>
      {foto && <button type="button" className="gw-foto-x" onClick={onRemove} title={t('remove')}>{I.x}</button>}
    </div>
  )
}

/*
 * Buscador de direcciones como el de los mapas: escribe calle, hotel o tienda y elige una
 * sugerencia; rellena la dirección (que sigue editable para piso, puerta…). Si no sale o
 * se pasa del cupo, se escribe a mano. Una «sesión» por búsqueda (Google la cobra así).
 */
function BuscarDireccion({ onPick, onManual, invalid }) {
  const { t, lang } = useLang()
  const [q, setQ] = useState('')
  const [res, setRes] = useState(null)      // null | [] | [{id, main, secondary, address?}]
  const [act, setAct] = useState(0)
  const [abierto, setAbierto] = useState(false)
  const [cargando, setCargando] = useState(false)
  const [msg, setMsg] = useState('')
  const sesion = useRef('')
  const caja = useRef(null)
  const ultima = useRef(0)

  useEffect(() => {
    const txt = q.trim()
    if (txt.length < 3) { setRes(null); setCargando(false); return }
    if (!sesion.current) sesion.current = crypto.randomUUID?.() || String(Math.random()).slice(2)
    const n = ++ultima.current
    setCargando(true)
    const h = setTimeout(async () => {
      const r = await portal.addressSearch(txt, lang, sesion.current)
      if (n !== ultima.current) return
      setCargando(false)
      // Buscador caído o sin cupo: no se puede dejar al cliente sin poder seguir.
      if (!r.ok) { setRes(null); setMsg(r.error || t('g_addr_fail')); onManual(); return }
      setMsg(''); setRes(r.results || []); setAct(0); setAbierto(true)
    }, 300)
    return () => clearTimeout(h)
  }, [q, lang])   // eslint-disable-line react-hooks/exhaustive-deps

  useEffect(() => {
    if (!abierto) return
    const fuera = (e) => { if (!caja.current?.contains(e.target)) setAbierto(false) }
    document.addEventListener('pointerdown', fuera)
    return () => document.removeEventListener('pointerdown', fuera)
  }, [abierto])

  const elegir = async (s) => {
    setAbierto(false)
    let dir = s.address
    if (!dir) {
      setCargando(true)
      const r = await portal.addressPlace(s.id, lang, sesion.current)
      setCargando(false)
      dir = r.ok ? r.address : [s.main, s.secondary].filter(Boolean).join('\n')
    }
    sesion.current = ''
    setQ(''); setRes(null)
    onPick(dir)
  }

  const tecla = (e) => {
    if (!abierto || !res?.length) { if (e.key === 'ArrowDown' && res?.length) { e.preventDefault(); setAbierto(true) } return }
    if (e.key === 'ArrowDown') { e.preventDefault(); setAct((a) => (a + 1) % res.length) }
    else if (e.key === 'ArrowUp') { e.preventDefault(); setAct((a) => (a - 1 + res.length) % res.length) }
    else if (e.key === 'Enter') { e.preventDefault(); elegir(res[act]) }
    else if (e.key === 'Escape') { e.preventDefault(); setAbierto(false) }
  }

  const lista = abierto && res
  return (
    <div className={`gw-dir ${lista ? 'on' : ''}`} ref={caja}>
      <label className={`gw-search gw-dir-q ${invalid ? 'mal' : ''}`}>{I.mag}
        <input value={q} onChange={(e) => { setQ(e.target.value); setMsg('') }} onKeyDown={tecla} onFocus={() => res && setAbierto(true)}
          placeholder={t('g_addr_search_ph')} autoComplete="off" role="combobox" aria-expanded={!!lista} aria-autocomplete="list"
          aria-label={t('g_addr_search_ph')} />
        {cargando && <span className="gw-dir-spin" aria-hidden="true" />}
      </label>
      {lista && (
        <ul className="gw-dd-l gw-dir-l" role="listbox">
          {res.length === 0 && (
            <li className="gw-dir-nada" onPointerDown={(e) => e.preventDefault()}
              onClick={() => { setAbierto(false); onManual() }}>
              {t('g_addr_none')} <span className="gw-dir-mano">{t('g_addr_manual')}</span>
            </li>
          )}
          {res.map((s, i) => (
            <li key={s.id + i} role="option" aria-selected={i === act} className={i === act ? 'act' : ''}
              onPointerEnter={() => setAct(i)} onPointerDown={(e) => e.preventDefault()} onClick={() => elegir(s)}>
              <span className="gw-dd-ic">{G.pin}</span>
              <span className="gw-dd-t"><b>{s.main}</b>{s.secondary && <small>{s.secondary}</small>}</span>
            </li>
          ))}
        </ul>
      )}
      {msg && <span className="hint gw-dir-msg">{msg}</span>}
    </div>
  )
}

export function SolicitarGarantia({ go, onDone, onExpire }) {
  const { t } = useLang()
  const [paso, setPaso] = useState(1)
  const [lugares, setLugares] = useState(null)
  const [empresa, setEmpresa] = useState(null)
  const [email, setEmail] = useState('')
  const [placeId, setPlaceId] = useState(null)
  const [otro, setOtro] = useState('')
  const [pegar, setPegar] = useState('')
  const [items, setItems] = useState([])        // [{code, kind, issue, detail, fotos: {tipo: {file}}, aviso}]
  const [aviso, setAviso] = useState('')
  const [leyendo, setLeyendo] = useState(false)
  const [camara, setCamara] = useState(false)
  const [ayuda, setAyuda] = useState(false)
  const [nombre, setNombre] = useState('')
  const [tel, setTel] = useState('')
  const [dir, setDir] = useState('')
  const [dirLibre, setDirLibre] = useState(false)   // se puede escribir: ya eligió una o no la encontró
  const [enviando, setEnviando] = useState(null)  // null | {hechas, total}
  const [err, setErr] = useState('')
  const fotoCodigo = useRef(null)
  const [confirmarLugar, setConfirmarLugar] = useState('')   // cadena: «¿otra vez en X?» hasta que conteste

  useEffect(() => {
    portal.warrantyPlaces().then((r) => {
      if (r.reauth) return onExpire()
      setLugares(r.ok ? r.places : []); setEmpresa(r.ok ? r.company : null)
      if (!r.ok) return
      // Lo de la última vez. Si es una cadena (su empresa tiene varios hoteles o ya ha
      // pedido desde sitios distintos) se deja puesto y se pregunta; si no, se pone sin más.
      const ult = (r.recent || [])[0]
      const enLista = ult?.id ? r.places.find((p) => p.id === ult.id) : null
      if (enLista) setPlaceId(enLista.id)
      else if (ult) { setOtro(ult.name); setPlaceId(null) }
      else if (r.places.length === 1) setPlaceId(r.places[0].id)
      if (ult && (r.places.length > 1 || (r.recent || []).length > 1)) setConfirmarLugar(enLista ? enLista.name : ult.name)
    })
    portal.me().then((r) => { if (r.ok) setEmail(r.email) })
  }, []) // eslint-disable-line react-hooks/exhaustive-deps

  const sitio = placeId ? lugares?.find((p) => p.id === placeId)?.name : otro.trim()

  /* Añade códigos (pegados, escritos o leídos de una foto). Los que no tienen forma de
     etiqueta (9) ni de antena (12) se avisan y no entran. */
  const anadir = (lista, fotoFrontal = null) => {
    const malos = [], nuevos = []
    for (const raw of lista) {
      const c = limpiar(raw)
      if (!c) continue
      if (!ESL.test(c) && !AP.test(c)) { malos.push(c); continue }
      if (items.some((i) => i.code === c) || nuevos.some((i) => i.code === c)) continue
      nuevos.push({ code: c, kind: tipoDe(c), issue: '', detail: '', existe: 'comprobando', forzada: false, tried: false,
        fotos: fotoFrontal ? { front: { file: fotoFrontal, leido: c } } : {} })
    }
    setAviso(malos.length ? t('g_bad_codes', { codes: malos.slice(0, 5).join(', ') }) : '')
    // Contra la lista del momento: la cámara añade varios seguidos antes de volver a pintar.
    if (nuevos.length) {
      setItems((s) => [...s, ...nuevos.filter((n) => !s.some((i) => i.code === n.code))])
      comprobar(nuevos.map((n) => n.code))
    }
    return nuevos.length
  }

  /* ¿Existen en nuestros servidores (app, app02)? Uno que no aparece se pregunta si se
     añade igualmente, y entonces sus fotos son obligatorias. Si no se puede comprobar,
     se deja pasar (null). */
  const comprobar = async (codes) => {
    const r = await portal.warrantyCheck(codes)
    if (r.reauth) return onExpire()
    // busy: ya está en una garantía o solicitud en curso (G-…/TK-… si es suya, '' si no).
    setItems((s) => s.map((i) => (codes.includes(i.code) && i.existe === 'comprobando'
      ? { ...i, existe: r.exists?.[i.code] ?? null, ocupada: typeof r.busy?.[i.code] === 'string' ? r.busy[i.code] : null } : i)))
  }
  const anadirPegados = () => { if (anadir(pegar.split(/[\s,;]+/))) setPegar('') }

  /* «Leer el código de una foto»: con la frontal se lee el código de barras y la foto
     queda ya puesta como frontal de esa etiqueta. */
  const leerDeFoto = async (file) => {
    setLeyendo(true); setAviso('')
    const leidos = await leerCodigos(file)
    setLeyendo(false)
    if (!leidos.length) { setAviso(t('g_read_fail')); setAyuda(true); return }
    anadir(leidos, leidos.length === 1 ? file : null)
  }

  /* Foto de un dispositivo. La frontal se lee: si su código no es el de la fila, se avisa. */
  const ponerFoto = async (code, tipo, file) => {
    setItems((s) => s.map((i) => (i.code === code ? { ...i, fotos: { ...i.fotos, [tipo]: { file } }, aviso: '' } : i)))
    if (tipo !== 'front' || tipoDe(code) !== 'esl') return
    const leidos = await leerCodigos(file)
    const msg = !leidos.length ? '' : leidos.includes(code) ? '' : t('g_photo_other_code', { read: leidos[0] })
    setItems((s) => s.map((i) => (i.code === code ? { ...i, aviso: msg } : i)))
  }
  const quitarFoto = (code, tipo) => setItems((s) => s.map((i) => {
    if (i.code !== code) return i
    const fotos = { ...i.fotos }; delete fotos[tipo]; return { ...i, fotos }
  }))
  const cambiar = (code, k, v) => setItems((s) => s.map((i) => (i.code === code ? { ...i, [k]: v } : i)))

  // Las fotos solo son obligatorias en un código que no aparece y que añadió igualmente.
  // Las que ya están en otra garantía o solicitud en curso no se piden otra vez: hay que quitarlas.
  const ocupadas = items.filter(estaOcupada)
  const libres = items.filter((i) => !estaOcupada(i))
  const faltanFotos = libres.filter((i) => fotosObligatorias(i) && FOTOS[i.kind].some((f) => !i.fotos[f]))
  const sinExplicar = libres.filter((i) => !explicado(i))
  const comprobando = items.filter((i) => i.existe === 'comprobando')
  const sinConfirmar = libres.filter((i) => i.existe === false && !i.forzada)
  const quitarOcupadas = () => setItems((s) => s.filter((i) => !estaOcupada(i)))
  const nE = items.filter((i) => i.kind === 'esl').length
  const nA = items.filter((i) => i.kind === 'ap').length
  // Los avisos de lo que falta en una fila salen cuando intenta seguir (no al añadirla).
  const [marcar, setMarcar] = useState(false)
  const issueOpts = ISSUES.map((k) => ({ value: k, label: t('g_issue_' + k), desc: t('g_issue_desc_' + k), icon: G[k] }))

  const sinProbar = libres.filter(faltaPrueba)
  const puede = { 1: !!sitio && !confirmarLugar, 2: items.length > 0 && !ocupadas.length && faltanFotos.length === 0 && sinExplicar.length === 0 && !comprobando.length && !sinConfirmar.length && !sinProbar.length, 3: nombre.trim() && dir.trim(), 4: true }
  // «Ya funciona»: se quita de la solicitud (y se le dice, para que no la busque).
  const [arregladas, setArregladas] = useState([])
  const yaFunciona = (code) => { setItems((s) => s.filter((x) => x.code !== code)); setArregladas((a) => [...a, code]) }
  const probadas = (codes) => setItems((s) => s.map((x) => (codes.includes(x.code) ? { ...x, tried: true } : x)))
  const siguiente = () => { setErr(''); setMarcar(false); setPaso((p) => Math.min(4, p + 1)); window.scrollTo({ top: 0, behavior: 'smooth' }) }
  const atras = () => { setErr(''); setPaso((p) => Math.max(1, p - 1)); window.scrollTo({ top: 0, behavior: 'smooth' }) }
  const ir = (p) => { setErr(''); setPaso(p); window.scrollTo({ top: 0, behavior: 'smooth' }) }

  /* Enviar: primero el ticket con todo; luego las fotos, una a una (reducidas), para que
     una solicitud con muchas etiquetas no choque con el tamaño máximo de una subida. */
  const enviar = async () => {
    setErr('')
    const total = items.reduce((n, i) => n + Object.keys(i.fotos).length, 0)
    setEnviando({ hechas: 0, total })
    const r = await portal.warrantyRequest({
      place_id: placeId || null, place_other: placeId ? '' : otro.trim(),
      items: items.map((i) => ({ code: i.code, issue: i.issue, detail: i.detail, forced: i.forzada, tried: pidePrueba(i) && !!i.tried })),
      name: nombre.trim(), phone: tel.trim(), address: dir.trim(),
    })
    if (r.reauth) { setEnviando(null); return onExpire() }
    if (!r.ok) { setEnviando(null); setErr(r.error || t('err_create')); return }
    const fallidas = []
    let hechas = 0
    for (const i of items) {
      for (const [tipo, f] of Object.entries(i.fotos)) {
        const file = await reducirFoto(f.file)
        let ok = false
        for (let intento = 0; intento < 2 && !ok; intento++) ok = (await portal.warrantyPhoto(r.code, i.code, tipo, file)).ok
        if (!ok) fallidas.push(`${i.code} (${t(`g_photo_${i.kind}_${tipo}`)})`)
        setEnviando({ hechas: ++hechas, total })
      }
    }
    onDone({ code: r.code, place: sitio, labels: nE, aps: nA, fallidas })
  }

  const PASOS = [[1, t('g_step_where')], [2, t('g_step_what')], [3, t('g_step_contact')], [4, t('g_step_review')]]

  return (
    <section className="screen on"><div className="gw-wrap gw-sol">
      <button className="back" onClick={() => (paso === 1 ? go('g_mis') : atras())}>{I.back}{paso === 1 ? t('g_back_list') : t('g_back')}</button>
      <div className="gw-sol-h">
        {empresa && <span className="gw-empresa">{empresa}</span>}
        <h1>{t('g_request_title')}</h1>
        <p>{t('g_request_sub')}</p>
      </div>

      <ol className="gw-pasos">
        {PASOS.map(([n, lb]) => (
          <li key={n} className={n < paso ? 'done' : n === paso ? 'on' : ''}>
            <button type="button" disabled={n > paso || !!enviando} onClick={() => ir(n)}>
              <b>{n < paso ? I.check : n}</b><span><small>{t('g_step_n', { n })}</small>{lb}</span>
            </button>
          </li>
        ))}
      </ol>

      <div className="gw-box gw-paso">
        {paso === 1 && (
          <>
            <h2>{t('g_where_title')}</h2>
            <p className="gw-paso-sub">{lugares?.length ? t('g_where_sub') : t('g_where_sub_free')}</p>
            {/* Cadena: se deja puesto el de la última vez, pero se pregunta (puede ser otro hotel). */}
            {confirmarLugar && (
              <div className="gw-mismo">
                <span>{G.building}</span>
                <p><b>{t('g_same_place_q', { place: confirmarLugar })}</b><small>{t('g_same_place_sub')}</small></p>
                <div className="gw-mismo-b">
                  <button type="button" className="btn sec" onClick={() => { setConfirmarLugar(''); setPlaceId(null); setOtro('') }}>{t('g_same_place_no')}</button>
                  <button type="button" className="btn" onClick={() => { setConfirmarLugar(''); siguiente() }}>{t('g_same_place_yes')} {I.arrow}</button>
                </div>
              </div>
            )}
            {lugares === null ? <p className="gw-mute">{t('loading')}</p> : (
              <div className="gw-lugares">
                {lugares.map((p) => (
                  <button key={p.id} type="button" className={`gw-lugar ${placeId === p.id ? 'on' : ''}`} onClick={() => { setPlaceId(p.id); setConfirmarLugar('') }}>
                    <span className="gw-lugar-ic">{G.building}</span><b>{p.name}</b>
                    <span className="gw-radio" />
                  </button>
                ))}
                <div className={`gw-lugar otro ${!placeId ? 'on' : ''}`} onClick={() => setPlaceId(null)}>
                  <span className="gw-lugar-ic">{I.plus}</span>
                  <span className="gw-lugar-otro"><b>{lugares.length ? t('g_other_place') : t('g_place_name')}</b>
                    <input className="inp" value={otro} onChange={(e) => { setOtro(e.target.value); setPlaceId(null); setConfirmarLugar('') }}
                      placeholder={t('g_other_place_ph')} /></span>
                  {lugares.length > 0 && <span className="gw-radio" />}
                </div>
              </div>
            )}
          </>
        )}

        {paso === 2 && (
          <>
            <div className="gw-paso-hh">
              <div><h2>{t('g_what_title')}</h2><p className="gw-paso-sub">{t('g_what_sub')}</p></div>
              {items.length > 0 && <span className="gw-cuenta">{cuantos(t, nE, nA)}</span>}
            </div>

            <div className="gw-entrada">
              <label className="gw-entrada-l">
                <span className="lab">{t('g_paste_label')} <button type="button" className="linkbtn" onClick={() => setAyuda((v) => !v)}>{t('g_where_is_code')}</button></span>
                <textarea className="inp gw-mono" rows={3} value={pegar} onChange={(e) => setPegar(e.target.value)}
                  placeholder={t('g_paste_ph')}
                  onKeyDown={(e) => { if (e.key === 'Enter' && (e.ctrlKey || e.metaKey)) anadirPegados() }} />
              </label>
              <div className="gw-entrada-b">
                <button type="button" className="btn" disabled={!pegar.trim()} onClick={anadirPegados}>{I.plus} {t('g_add')}</button>
                <input ref={fotoCodigo} type="file" accept="image/*" capture="environment" hidden
                  onChange={(e) => { const f = e.target.files?.[0]; e.target.value = ''; if (f) leerDeFoto(f) }} />
                <div className="gw-entrada-dos">
                  <button type="button" className="btn sec" disabled={leyendo} onClick={() => fotoCodigo.current?.click()}>
                    {G.barcode} {leyendo ? t('g_reading') : t('g_read_photo')}</button>
                  {hayCamara && <button type="button" className="btn sec" onClick={() => { setAviso(''); setCamara(true) }}>
                    {G.camera} {t('g_cam')}</button>}
                </div>
              </div>
            </div>
            {camara && <EscanerCamara ya={items.map((i) => i.code)} onClose={() => setCamara(false)}
              onCodes={(codes, file) => anadir(codes, file)} />}
            {aviso && <p className="gw-err">{G.warn} {aviso}</p>}
            {ayuda && <AyudaCodigo onClose={() => setAyuda(false)} />}

            {items.length > 0 && (
              <>
                <div className="gw-lista-h">
                  <b>{t('g_devices_added', { n: items.length })}</b>
                  <span>{t('g_photos_needed')}</span>
                </div>
                {arregladas.length > 0 && (
                  <div className="gw-aviso ok">{I.check}<span>{t(arregladas.length === 1 ? 'g_try_fixed_1' : 'g_try_fixed', { n: arregladas.length, codes: arregladas.join(', ') })}</span></div>
                )}
                {ocupadas.length > 1 && (
                  <div className="gw-prueba-todas">
                    <span>{t('g_busy_many', { n: ocupadas.length })}</span>
                    <button type="button" className="btn sec" onClick={quitarOcupadas}>{t('g_busy_remove_all', { n: ocupadas.length })}</button>
                  </div>
                )}
                {sinProbar.length > 1 && (
                  <div className="gw-prueba-todas">
                    <span>{t('g_try_many', { n: sinProbar.length })}</span>
                    <button type="button" className="btn sec" onClick={() => probadas(sinProbar.map((x) => x.code))}>{t('g_try_still_all', { n: sinProbar.length })}</button>
                  </div>
                )}
                <div className="gw-items">
                  {items.map((i) => {
                    const faltan = FOTOS[i.kind].filter((f) => !i.fotos[f]).length
                    const obligan = fotosObligatorias(i)
                    const quitar = () => setItems((s) => s.filter((x) => x.code !== i.code))
                    if (estaOcupada(i)) return (
                      <div key={i.code} className="gw-item ocupada">
                        <div className="gw-item-id">
                          <span className="gw-item-ic">{i.kind === 'ap' ? G.ap : G.tag}</span>
                          <span><b className="gw-mono">{i.code}</b><small>{i.kind === 'ap' ? t('g_antenna') : t('g_label')}</small></span>
                        </div>
                        <div className={`gw-ocupada ${marcar ? 'mal' : ''}`} role="alert">
                          {G.warn}
                          <span><b>{i.ocupada ? t('g_busy_t', { ref: i.ocupada }) : t('g_busy_t_other')}</b>
                            {i.ocupada ? t('g_busy') : t('g_busy_other')}</span>
                          <button type="button" className="btn sec" onClick={quitar}>{G.trash} {t('g_busy_remove')}</button>
                        </div>
                      </div>
                    )
                    return (
                      <div key={i.code} className={`gw-item ${(obligan && faltan) || (i.existe === false && !i.forzada) ? 'falta' : i.existe === true ? 'existe' : ''}`}>
                        <div className="gw-item-id">
                          <span className="gw-item-ic">{i.kind === 'ap' ? G.ap : G.tag}</span>
                          <span><b className="gw-mono">{i.code}</b><small>{i.kind === 'ap' ? t('g_antenna') : t('g_label')}</small>
                            {i.existe === 'comprobando' && <small className="gw-item-chk">{t('g_checking')}</small>}
                            {i.existe === true && <small className="gw-item-chk si">{I.check} {t('g_found_short')}</small>}
                            {i.existe === false && i.forzada && <small className="gw-item-chk no">{t('g_not_found_short')}</small>}</span>
                          <button type="button" className="gw-quitar" onClick={quitar} title={t('remove')}>{G.trash}</button>
                        </div>
                        <div className="gw-item-f">
                          <Desplegable value={i.issue} options={issueOpts} onChange={(v) => cambiar(i.code, 'issue', v)}
                            placeholder={t('g_issue_pick')} label={t('g_issue_q')} invalid={marcar && !i.issue} />
                          <textarea className={`inp gw-detalle ${marcar && faltaDetalle(i) ? 'mal' : ''}`} rows={2} value={i.detail} maxLength={300}
                            onChange={(e) => cambiar(i.code, 'detail', e.target.value)}
                            placeholder={t(i.issue ? 'g_detail_ph_' + i.issue : 'g_detail_ph') + (i.issue && i.issue !== 'otro' ? ` ${t('g_optional')}` : '')}
                            aria-label={t('g_detail_label')} />
                          {i.issue === 'pantalla' && <p className="gw-item-excl">{G.warn}<span>{t('g_issue_pantalla_excl')}</span></p>}
                          {pidePrueba(i) && i.tried && (
                            <p className="gw-prueba-ok">{I.check}<span>{t('g_try_done')}</span>
                              <button type="button" className="linkbtn" onClick={() => cambiar(i.code, 'tried', false)}>{t('g_try_undo')}</button></p>
                          )}
                          {faltaDetalle(i) && (
                            <small className={`gw-detalle-n ${marcar ? 'mal' : ''}`}>{t('g_detail_min', { n: DETALLE_MIN - i.detail.trim().length })}</small>
                          )}
                        </div>
                        <div className="gw-fotos">
                          {FOTOS[i.kind].map((tipo) => (
                            <FotoSlot key={tipo} tipo={tipo} kind={i.kind} foto={i.fotos[tipo]}
                              onPick={(f) => ponerFoto(i.code, tipo, f)} onRemove={() => quitarFoto(i.code, tipo)} />
                          ))}
                          <span className={`gw-fotos-n ${!faltan ? 'ok' : obligan ? '' : 'opc'}`}>
                            {!faltan ? <>{I.check} {t('g_photos_ok')}</> : obligan ? t('g_photos_missing', { n: faltan }) : t('g_photos_optional')}</span>
                        </div>
                        {/* Ocupa todo el ancho de la tarjeta, debajo (no aprieta la columna del fallo). */}
                        {faltaPrueba(i) && <PruebaAntes abierta={sinProbar[0]?.code === i.code} marcar={marcar}
                          onTried={() => probadas([i.code])} onWorks={() => yaFunciona(i.code)} />}
                        {i.existe === false && !i.forzada && (
                          <div className={`gw-noexiste ${marcar ? 'mal' : ''}`} role="alert">
                            {G.warn}
                            <span><b>{t('g_not_found_t')}</b> {t('g_not_found')}</span>
                            <span className="gw-noexiste-b">
                              <button type="button" className="btn sec" onClick={quitar}>{t('remove')}</button>
                              <button type="button" className="btn" onClick={() => cambiar(i.code, 'forzada', true)}>{t('g_add_anyway')}</button>
                            </span>
                          </div>
                        )}
                        {i.existe === false && i.forzada && faltan > 0 && (
                          <p className="gw-noexiste-f">{G.camera} {t('g_not_found_photos')}</p>
                        )}
                        {i.aviso && <p className="gw-err">{G.warn} {i.aviso}</p>}
                      </div>
                    )
                  })}
                </div>
              </>
            )}
            {items.length === 0 && !ayuda && <AyudaCodigo />}
          </>
        )}

        {paso === 3 && (
          <>
            <h2>{t('g_contact_title')}</h2>
            <p className="gw-paso-sub">{t('g_contact_sub')}</p>
            <div className="gw-grid2">
              <label className="f"><span className="lab">{t('g_name')}</span>
                <input className={`inp ${marcar && !nombre.trim() ? 'mal' : ''}`} value={nombre} onChange={(e) => setNombre(e.target.value)} autoComplete="name" autoFocus /></label>
              <label className="f"><span className="lab">{t('phone')} <span className="hint" style={{ fontWeight: 400 }}>{t('phone_optional')}</span></span>
                <input className="inp" type="tel" value={tel} onChange={(e) => setTel(e.target.value)} autoComplete="tel" inputMode="tel" placeholder={t('phone_placeholder')} /></label>
            </div>
            <label className="f"><span className="lab">{t('your_email')}</span>
              <input className="inp" value={email} disabled /></label>
            {/* La dirección sale del buscador: hasta elegir una no se escribe (sí se retoca
                después, para piso o puerta). Solo si el buscador no la encuentra, a mano. */}
            <div className="f"><span className="lab">{t('g_address')}</span>
              <BuscarDireccion onPick={(d) => { setDir(d); setDirLibre(true) }} onManual={() => setDirLibre(true)}
                invalid={marcar && !dir.trim()} />
              <textarea className={`inp gw-dir-txt ${marcar && !dir.trim() ? 'mal' : ''}`} rows={4} value={dir} aria-label={t('g_address')}
                readOnly={!dirLibre} onChange={(e) => setDir(e.target.value)} autoComplete="street-address"
                placeholder={dirLibre ? t('g_address_ph') : t('g_address_locked')} />
              <span className="hint">{t('g_address_hint')}</span></div>
          </>
        )}

        {paso === 4 && (
          <>
            <h2>{t('g_review_title')}</h2>
            <p className="gw-paso-sub">{t('g_review_sub')}</p>
            <div className="gw-resumen">
              <div className="gw-res-b">
                <div className="gw-res-h"><b>{t('g_step_where')}</b><button type="button" className="linkbtn" onClick={() => ir(1)}>{G.edit} {t('g_edit')}</button></div>
                <p>{sitio}{empresa ? <small> · {empresa}</small> : null}</p>
              </div>
              <div className="gw-res-b">
                <div className="gw-res-h"><b>{t('g_step_what')} · {cuantos(t, nE, nA)}</b><button type="button" className="linkbtn" onClick={() => ir(2)}>{G.edit} {t('g_edit')}</button></div>
                <ul className="gw-res-l">
                  {items.map((i) => <li key={i.code}><span className="gw-mono">{i.code}</span> {t('g_issue_' + i.issue)}{i.detail ? ` — ${i.detail}` : ''} <small>· {t('g_n_photos', { n: Object.keys(i.fotos).length })}</small></li>)}
                </ul>
              </div>
              <div className="gw-res-b">
                <div className="gw-res-h"><b>{t('g_step_contact')}</b><button type="button" className="linkbtn" onClick={() => ir(3)}>{G.edit} {t('g_edit')}</button></div>
                <p>{nombre}{tel ? ` · ${tel}` : ''} · {email}</p>
                <p className="gw-pre">{dir}</p>
              </div>
            </div>
          </>
        )}

        {err && <p className="gw-err">{G.warn} {err}</p>}
        {enviando && (
          <div className="gw-subiendo">
            <span>{enviando.total ? t('g_uploading', { done: enviando.hechas, total: enviando.total }) : t('sending')}</span>
            <i style={{ width: `${enviando.total ? (enviando.hechas / enviando.total) * 100 : 30}%` }} />
          </div>
        )}

        <div className="gw-paso-pie">
          {paso > 1 ? <button type="button" className="btn sec gw-atras" disabled={!!enviando} onClick={atras}>{I.back} {t('g_back')}</button> : <span />}
          {paso === 2 && items.length > 0 && (ocupadas.length > 0 || faltanFotos.length > 0 || sinExplicar.length > 0 || comprobando.length > 0 || sinConfirmar.length > 0 || sinProbar.length > 0) && (
            <span className="gw-pie-why">{[
              comprobando.length ? t('g_checking') : '',
              ocupadas.length ? t(ocupadas.length === 1 ? 'g_busy_block_1' : 'g_busy_block', { n: ocupadas.length }) : '',
              sinConfirmar.length ? t(sinConfirmar.length === 1 ? 'g_confirm_block_1' : 'g_confirm_block', { n: sinConfirmar.length }) : '',
              sinExplicar.length ? t(sinExplicar.length === 1 ? 'g_details_block_1' : 'g_details_block', { n: sinExplicar.length }) : '',
              sinProbar.length ? t(sinProbar.length === 1 ? 'g_try_block_1' : 'g_try_block', { n: sinProbar.length }) : '',
              faltanFotos.length ? t(faltanFotos.length === 1 ? 'g_photos_block_1' : 'g_photos_block', { n: faltanFotos.length }) : '',
            ].filter(Boolean).join(' · ')}</span>
          )}
          {paso < 4
            ? <button type="button" className={`btn gw-sig ${!puede[paso] && ((paso === 2 && items.length) || paso === 3) ? 'casi' : ''}`} aria-disabled={!puede[paso]}
                disabled={!puede[paso] && !(paso === 2 && items.length)}
                onClick={() => { if (puede[paso]) siguiente(); else setMarcar(true) }}>{t('g_next')} {I.arrow}</button>
            : <button type="button" className="btn gw-sig" disabled={!!enviando} onClick={enviar}>{enviando ? t('sending') : <>{I.send} {t('g_send_request')}</>}</button>}
        </div>
      </div>
    </div></section>
  )
}

/* El protocolo de garantías, paso a paso: primero revisamos las fotos y, según lo que
   veamos, se sigue (o no hace falta) con el envío, la revisión técnica y el presupuesto.
   Debajo, lo que no cubre la garantía. En la pantalla de «enviada» y en cada solicitud. */
function ProcesoGarantia({ code }) {
  const { t } = useLang()
  return (
    <div className="gw-proc">
      <ol className="gw-ok-pasos">
        {[1, 2, 3, 4].map((n) => (
          <li key={n}><b>{n}</b><div><strong>{t(`g_next_${n}_t`)}</strong><Rich tag="div" html={t(`g_next_${n}`, { code })} /></div></li>
        ))}
      </ol>
      <details className="gw-excl">
        <summary>{G.warn} {t('g_excl_t')}</summary>
        <p>{t('g_excl_intro')}</p>
        <ul>{[1, 2, 3, 4, 5].map((n) => <li key={n}>{t('g_excl_' + n)}</li>)}</ul>
        <Rich tag="p" html={t('g_excl_fisico')} />
        <Rich tag="p" html={t('g_excl_plazo')} />
      </details>
    </div>
  )
}

/* ------------------------------ Solicitud enviada ------------------------- */
export function GarantiaEnviada({ res, go, onOpen }) {
  const { t } = useLang()
  const [copiado, setCopiado] = useState(false)
  const copiar = () => {
    try { navigator.clipboard?.writeText(res.code) } catch { /* sin portapapeles */ }
    setCopiado(true); setTimeout(() => setCopiado(false), 1800)
  }
  return (
    <section className="screen on"><div className="gw-wrap gw-ok">
      <div className="gw-box">
        <div className="ok-mark">{I.check}</div>
        <h1>{t('g_sent_title')}</h1>
        <Rich tag="p" className="gw-ok-sub" html={t('g_sent_sub', { what: cuantos(t, res.labels, res.aps), place: escapeHtml(res.place || '') })} />
        <div className="gw-ok-num">
          <div><small>{t('your_ticket_number')}</small><b>{res.code}</b></div>
          <button className="tk-copy" onClick={copiar}>{copiado ? <>{I.check} {t('copied')}</> : <>{I.copy2} {t('copy')}</>}</button>
        </div>
        {res.fallidas.length > 0 && (
          <p className="gw-err">{G.warn} {t('g_photos_failed', { list: res.fallidas.slice(0, 6).join(', ') })}</p>
        )}
        <h2 className="gw-ok-h">{t('g_next_steps')}</h2>
        <ProcesoGarantia code={res.code} />
        <p className="ok-mail">{I.mail} {t('created_mail_note')}</p>
        <div className="gw-ok-b">
          <button className="btn" onClick={() => onOpen(res.code)}>{t('g_view_request')} {I.arrow}</button>
          <button className="btn sec" onClick={() => go('home')}>{t('back_home')}</button>
        </div>
      </div>
    </div></section>
  )
}

/* Entrada de garantías en la portada: solicitar y ver las de su empresa. */
export function GarantiasCard({ go }) {
  const { t } = useLang()
  return (
    <div className="gw-home reveal">
      <span className="gw-home-ic">{G.shield}</span>
      <div className="gw-home-tx">
        <h3>{t('g_home_title')}</h3>
        <p>{t('g_home_desc')}</p>
      </div>
      <div className="gw-home-b">
        <button className="btn" onClick={() => go('g_solicitar')}>{G.shield} {t('g_request')}</button>
        <button className="btn sec" onClick={() => go('g_mis')}>{I.tickets} {t('g_my_warranties')}</button>
      </div>
    </div>
  )
}

