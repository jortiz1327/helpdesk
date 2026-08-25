import { useState, useEffect, useRef, useCallback } from 'react'
import { portal, getPass, setPass, getSeen, markSeen } from './portalApi.js'
import { LangProvider, useLang, LANGS, LOCALES } from './i18n.js'
import logo from '../assets/logo.png'

/* Banderas del selector de idioma en SVG en línea. Los emoji de bandera (🇪🇸…) NO se
   renderizan como banderas en Windows —se ven las siglas—, así que van dibujadas. */
const FLAGS = {
  es: (
    <svg viewBox="0 0 20 14" aria-hidden="true"><rect width="20" height="14" fill="#c60b1e" />
      <rect y="3.5" width="20" height="7" fill="#ffc400" /></svg>
  ),
  en: (
    <svg viewBox="0 0 60 30" aria-hidden="true">
      <clipPath id="uk-t"><path d="M30 15h30v15zv15H0zH0V0zV0h30z" /></clipPath>
      <path d="M0 0v30h60V0z" fill="#012169" />
      <path d="M0 0 60 30m0-30L0 30" stroke="#fff" strokeWidth="6" />
      <path d="M0 0 60 30m0-30L0 30" clipPath="url(#uk-t)" stroke="#c8102e" strokeWidth="4" />
      <path d="M30 0v30M0 15h60" stroke="#fff" strokeWidth="10" />
      <path d="M30 0v30M0 15h60" stroke="#c8102e" strokeWidth="6" />
    </svg>
  ),
  pt: (
    <svg viewBox="0 0 20 14" aria-hidden="true"><rect width="20" height="14" fill="#da291c" />
      <rect width="8" height="14" fill="#046a38" />
      <circle cx="8" cy="7" r="2.4" fill="none" stroke="#ffcd00" strokeWidth="1" /></svg>
  ),
}
const LANG_NAMES = { es: 'Español', en: 'English', pt: 'Português' }

/* Pinta una cadena traducida que puede llevar énfasis <b>…</b>. El texto es
   nuestro y estático (del diccionario), así que dangerouslySetInnerHTML es seguro. */
function Rich({ tag = 'span', html, className, style }) {
  const Tag = tag
  return <Tag className={className} style={style} dangerouslySetInnerHTML={{ __html: html }} />
}

/* ---------------------------------------------------------------------------
 * PORTAL PÚBLICO — la cara del cliente.
 *
 * Reverso del login de agentes: aquel oscuro y hermético; este claro, con aire y
 * guiando en cada paso. Lo primero es AYUDA (buscador + dudas frecuentes); crear o
 * ver incidencias va debajo, porque cada duda resuelta aquí es un ticket que no
 * entra. La identidad es el correo + un código de un solo uso: nadie se registra.
 * ------------------------------------------------------------------------- */

/* Iconos mínimos (los mismos trazos del boceto aprobado). */
const I = {
  mag: <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2"><circle cx="11" cy="11" r="7" /><path d="M21 21l-4-4" /></svg>,
  plus: <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2"><path d="M12 5v14M5 12h14" /></svg>,
  tickets: <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2"><path d="M9 5H7a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2h-2" /><rect x="9" y="3" width="6" height="4" rx="1" /><path d="M9 12h6M9 16h4" /></svg>,
  arrow: <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2"><path d="M5 12h14M13 6l6 6-6 6" /></svg>,
  back: <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2"><path d="M15 18l-6-6 6-6" /></svg>,
  lock: <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2"><rect x="3" y="11" width="18" height="10" rx="2" /><path d="M7 11V7a5 5 0 0 1 10 0v4" /></svg>,
  send: <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2"><path d="M22 2L11 13M22 2l-7 20-4-9-9-4 20-7z" /></svg>,
  check: <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.5"><path d="M20 6L9 17l-5-5" /></svg>,
  ext: <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2"><path d="M7 17L17 7M8 7h9v9" /></svg>,
  clip: <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2"><path d="M21 8v10a4 4 0 0 1-8 0V6a2.5 2.5 0 0 1 5 0v10a1 1 0 0 1-2 0V8" /></svg>,
  file: <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2"><path d="M14 3v5h5M14 3H6a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z" /></svg>,
  x: <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2"><path d="M6 6l12 12M18 6L6 18" /></svg>,
  down: <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2"><path d="M12 4v12m0 0l-5-5m5 5l5-5M5 20h14" /></svg>,
  copy2: <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2"><rect x="9" y="9" width="11" height="11" rx="2" /><path d="M5 15V5a2 2 0 0 1 2-2h10" /></svg>,
  mail: <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2"><rect x="3" y="5" width="18" height="14" rx="2" /><path d="M3 7l9 6 9-6" /></svg>,
  clock: <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2"><circle cx="12" cy="12" r="9" /><path d="M12 7v5l3 2" /></svg>,
  phone: <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2"><path d="M4 4h4l2 5-2.5 1.5a11 11 0 0 0 5 5L16 14l5 2v4a2 2 0 0 1-2 2A16 16 0 0 1 2 6a2 2 0 0 1 2-2z" /></svg>,
  info: <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2"><circle cx="12" cy="12" r="9" /><path d="M12 11v5M12 7.5v.5" /></svg>,
  // Estrella para la valoración (CSAT). Sin fill/stroke fijos: los pone el CSS
  // según esté marcada o no, para poder rellenarla en dorado.
  star: <svg viewBox="0 0 24 24"><path d="M12 2.5l2.9 6.3 6.9.6-5.2 4.5 1.6 6.7L12 17.6 5.8 21.1l1.6-6.7L2.2 9.4l6.9-.6L12 2.5z" /></svg>,
}

/* Icono de la ficha del Centro de atención según su título (horario / correo /
   teléfono), con uno genérico de reserva. */
function iconoInfo(title) {
  const t = (title || '').toLowerCase()
  if (/horario|hora|atenci[oó]n/.test(t)) return I.clock
  if (/correo|email|mail/.test(t)) return I.mail
  if (/tel[eé]fono|llama|whats/.test(t)) return I.phone
  return I.info
}

const humanSize = (b) => b >= 1048576 ? (b / 1048576).toFixed(1) + ' MB' : Math.max(1, Math.round(b / 1024)) + ' KB'

/*
 * Zona de adjuntos: se puede pulsar para elegir o ARRASTRAR archivos encima. Tope
 * de 5, igual que el backend. `compacta` la deja en una sola línea para el editor
 * de respuesta; en el formulario de crear va la zona grande.
 */
function Adjuntar({ files, setFiles, compacta }) {
  const { t } = useLang()
  const ref = useRef(null)
  const [drag, setDrag] = useState(false)
  const add = (nuevos) => setFiles((s) => [...s, ...nuevos.filter((f) => f && f.size)].slice(0, 5))
  const soltar = (e) => { e.preventDefault(); setDrag(false); add([...e.dataTransfer.files]) }
  return (
    <div className="adj">
      <input ref={ref} type="file" multiple hidden
        onChange={(e) => { add([...e.target.files]); e.target.value = '' }} />
      <button type="button" className={`adj-zone ${drag ? 'drag' : ''} ${compacta ? 'sm' : ''}`}
        onClick={() => ref.current?.click()}
        onDragOver={(e) => { e.preventDefault(); setDrag(true) }}
        onDragLeave={() => setDrag(false)} onDrop={soltar}>
        <span className="adj-ic">{I.clip}</span>
        <span className="adj-tx">
          <b>{compacta ? t('attach_short') : t('attach_a_file')}</b>
          {!compacta && <small> {t('attach_hint')}</small>}
        </span>
      </button>
      {files.length > 0 && (
        <div className="adj-list">
          {files.map((f, i) => (
            <span key={i} className="adj-chip">
              {I.file}<span className="adj-name">{f.name}</span><span className="adj-size">{humanSize(f.size)}</span>
              <button type="button" onClick={() => setFiles((s) => s.filter((_, j) => j !== i))} title={t('remove')}>{I.x}</button>
            </span>
          ))}
        </div>
      )}
    </div>
  )
}

/*
 * Un mensaje que ES un correo (firma, tablas, imágenes, estilos propios) se pinta
 * DENTRO DE UN IFRAME aislado —como hacen los clientes de correo—. Así su CSS y sus
 * tablas de ancho fijo no pueden romper el portal, y su JavaScript ni se ejecuta
 * (el sandbox no incluye `allow-scripts`: es también protección contra XSS). El
 * alto se ajusta al contenido midiéndolo al cargar.
 */
const CORREO_TOPE = 320   // px: por encima, el correo se colapsa con «ver más»
function CorreoFrame({ html }) {
  const { t } = useLang()
  const ref = useRef(null)
  const [alto, setAlto] = useState(80)     // alto real del contenido
  const [abierto, setAbierto] = useState(false)
  const doc = `<!doctype html><html><head><base target="_blank"><meta charset="utf-8">
    <style>html,body{margin:0}body{font:14px/1.6 -apple-system,"Segoe UI",Roboto,sans-serif;color:#1a2230;
    background:#fff;padding:15px 17px;overflow-x:auto;word-break:break-word}
    img{max-width:100%;height:auto}table{max-width:100%}*{box-sizing:border-box;max-width:100%}
    a{color:#1a4fd0}</style></head><body>${html}</body></html>`
  const medir = () => {
    try { setAlto(Math.min(4000, ref.current.contentWindow.document.body.scrollHeight + 4)) } catch { /* cross-origin */ }
  }
  const largo = alto > CORREO_TOPE
  const visible = largo && !abierto ? CORREO_TOPE : alto

  return (
    <div className={`correo ${largo && !abierto ? 'colapsado' : ''}`}>
      <iframe ref={ref} className="mailframe" title={t('email_frame_title')} srcDoc={doc} onLoad={medir}
        style={{ height: visible }} sandbox="allow-same-origin allow-popups" scrolling="no" />
      {largo && (
        <button type="button" className="correo-mas" onClick={() => setAbierto((v) => !v)}>
          {abierto ? t('see_less') : t('see_full_email')}
        </button>
      )}
    </div>
  )
}

/* ¿El cuerpo es un correo con formato, o texto simple escrito en el portal? */
const esCorreo = (html) => /<(img|table|div|style|font|blockquote)[\s>/]/i.test(html) || /\sstyle=/i.test(html)

/* Adjuntos de un mensaje del hilo: imágenes como miniatura, el resto como descarga. */
function Adjuntos({ items }) {
  if (!items?.length) return null
  return (
    <div className="msg-adj">
      {items.map((a, i) => a.image ? (
        <a key={i} href={a.url} target="_blank" rel="noreferrer" className="msg-img" title={a.name}>
          <img src={a.url} alt={a.name} loading="lazy" />
        </a>
      ) : (
        <a key={i} href={a.url} target="_blank" rel="noreferrer" className="msg-file">
          {I.file}<span className="msg-file-n">{a.name}</span><span className="msg-file-s">{humanSize(a.size)}</span>{I.down}
        </a>
      ))}
    </div>
  )
}


const mask = (m) => { const [u, d] = (m || '').split('@'); return (u?.[0] || '') + '***@' + (d || '') }
const fmtDate = (iso, lang = 'es') => { try { return new Date(iso).toLocaleDateString(LOCALES[lang] || 'es-ES', { day: 'numeric', month: 'short', year: 'numeric' }) } catch { return '' } }
const fmtHora = (iso, lang = 'es') => { try { return new Date(iso).toLocaleString(LOCALES[lang] || 'es-ES', { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' }) } catch { return '' } }
/* «hace 3 días», «hoy», «ahora mismo»… en el idioma activo (recibe `t` y `lang`). */
const relTime = (iso, t, lang = 'es') => {
  const s = (Date.now() - new Date(iso).getTime()) / 1000
  if (s < 60) return t('rel_now')
  if (s < 3600) { const m = Math.floor(s / 60); return t('rel_min', { n: m }) }
  if (s < 86400) { const h = Math.floor(s / 3600); return t('rel_hour', { n: h }) }
  const d = Math.floor(s / 86400)
  if (d === 1) return t('rel_yesterday')
  if (d < 30) return t('rel_days', { n: d })
  return fmtDate(iso, lang)
}
const CHIP = { recibido: 'nuevo', en_proceso: 'proceso', resuelto: 'resuelto' }
/* Estado del ticket → cómo se ve. El estado es el centro de la pantalla. Las
   etiquetas y subtítulos son CLAVES del diccionario (se resuelven con `t`). */
const FASE = {
  recibido:   { cls: 'recibido', labelKey: 'phase_received', subKey: 'phase_received_sub' },
  en_proceso: { cls: 'proceso', labelKey: 'phase_progress', subKey: 'phase_progress_sub' },
  resuelto:   { cls: 'resuelto', labelKey: 'phase_resolved', subKey: 'phase_resolved_sub' },
}

export default function Portal() {
  // Provee el idioma a todo el portal (selector en la cabecera + t()).
  return <LangProvider><PortalApp /></LangProvider>
}

function PortalApp() {
  // Pantalla: home | crear | mis | ticket. El «pase» decide si hay que pedir código.
  const [view, setView] = useState('home')
  const [pass, setPassState] = useState(getPass())
  const [openCode, setOpenCode] = useState(null)   // código del ticket abierto
  const [caducado, setCaducado] = useState(false)  // el pase dejó de valer: re-autenticar
  const [prefill, setPrefill] = useState(null)     // datos que precargan el formulario (CTA de una FAQ)

  // Al volver a la home se olvida cualquier pre-relleno de una FAQ, para que un
  // «Crear» genérico posterior salga en blanco.
  const go = (v) => { if (v === 'home') setPrefill(null); setView(v); window.scrollTo({ top: 0, behavior: 'smooth' }) }
  // Ir a crear, opcionalmente con asunto/categoría ya puestos (desde una FAQ).
  const irCrear = (data = null) => { setPrefill(data); setView('crear'); window.scrollTo({ top: 0, behavior: 'smooth' }) }
  const onPass = (t) => { setPass(t); setPassState(t); setCaducado(false) }
  /*
   * El pase ya no vale (caducado, o borrado en el servidor). NO se tira al cliente
   * a la home —eso era el bug: «Ver mis incidencias» desaparecía sin más, mientras
   * que Crear sí abría porque sus categorías son públicas—. Se limpia el pase y se
   * queda en la MISMA vista: con el pase vacío, esa vista pide el código otra vez.
   * El detalle no comprueba el pase, así que se lleva a «mis», que sí lo pedirá.
   */
  const caduco = () => {
    setPass(''); setPassState(''); setCaducado(true)
    setView((v) => (v === 'ticket' ? 'mis' : v))
  }

  return (
    <>
      <Top onLogo={() => go('home')} />
      {view === 'home' && <Home go={go} irCrear={irCrear} />}
      {/* Crear ya NO pide código: es público. Al crearse, el ticket se abre con el
          token que devuelve (verlo/responderlo sin código). */}
      {view === 'crear' && <Crear go={go} prefill={prefill} onOpen={(c) => { setOpenCode(c); go('ticket') }} onExpire={caduco} />}
      {view === 'mis' && (pass
        ? <Mis go={go} onOpen={(c) => { setOpenCode(c); go('ticket') }} onExpire={caduco} />
        : <Acceso intent="mis" go={go} onReady={onPass} caducado={caducado} />)}
      {view === 'ticket' && <Detalle code={openCode} back={() => go('mis')} onExpire={caduco} />}
      {view === 'estado' && <Estado go={go} />}
      <Footer />
    </>
  )
}

/* -------------------------------- Barra ---------------------------------- */
function Top({ onLogo }) {
  const { lang, setLang, t } = useLang()
  const [scr, setScr] = useState(false)
  useEffect(() => {
    const h = () => setScr(window.scrollY > 8)
    addEventListener('scroll', h); return () => removeEventListener('scroll', h)
  }, [])
  return (
    <div className={`top ${scr ? 'scrolled' : ''}`}>
      <div className="top-in">
        {/* El logo lleva al inicio: es un botón de verdad (área de clic clara y
            accesible por teclado), no un <img> con onClick. */}
        <button className="logo-btn" onClick={onLogo} title={t('top_home_title')} aria-label={t('top_home_aria')}>
          <img className="logo" src={logo} alt="AEME Group" />
        </button>
        <div className="spacer" />
        {/* Selector de idioma: banderas España / Reino Unido / Portugal. Recuerda la elección. */}
        <div className="lang-switch" role="group" aria-label={t('lang_label')}>
          {LANGS.map(([code]) => (
            <button key={code} type="button" className={`lang-flag ${lang === code ? 'on' : ''}`}
              aria-pressed={lang === code} aria-label={LANG_NAMES[code]} title={LANG_NAMES[code]}
              onClick={() => setLang(code)}>{FLAGS[code]}</button>
          ))}
        </div>
        <a className="ghostlink" href="/agentes">{I.lock} {t('agent_access')}</a>
      </div>
    </div>
  )
}

/* -------------------------------- Home ----------------------------------- */
/* Sugerencias de búsqueda: rellenan el buscador de un toque. Son CLAVES del
   diccionario (el texto se traduce en el render). */
const SUGERENCIAS = ['sug_1', 'sug_2', 'sug_3', 'sug_4']

/* Convierte correos y teléfonos del texto en enlaces «mailto:» / «tel:» clicables;
   el resto queda tal cual (los saltos de línea los respeta el CSS con pre-line). */
function linkify(text) {
  const re = /([\w.+-]+@[\w-]+\.[\w.-]+)|(\+?\d[\d\s]{7,}\d)/g
  const out = []; let last = 0, m, k = 0
  while ((m = re.exec(text)) !== null) {
    if (m.index > last) out.push(text.slice(last, m.index))
    const tok = m[0]
    if (m[1]) out.push(<a key={k++} href={`mailto:${tok}`}>{tok}</a>)
    else out.push(<a key={k++} href={`tel:${tok.replace(/\s+/g, '')}`}>{tok}</a>)
    last = m.index + tok.length
  }
  if (last < text.length) out.push(text.slice(last))
  return out
}
function Home({ go, irCrear }) {
  const { t } = useLang()
  const [q, setQ] = useState('')
  const [open, setOpen] = useState(-1)         // id de la FAQ abierta (-1 = ninguna)
  const [faqs, setFaqs] = useState([])
  const [info, setInfo] = useState([])         // Centro de atención (horario, correos, teléfonos)
  const [voted, setVoted] = useState(() => { try { return JSON.parse(localStorage.getItem('faq_voted') || '{}') } catch { return {} } })
  const faqRef = useRef(null)
  const rootRef = useRef(null)
  const vistas = useRef(new Set())             // FAQ ya contabilizadas en esta sesión

  // FAQ y Centro de atención llegan de la BD (configurables desde Agentes). Solo lo publicado.
  useEffect(() => { portal.faqs().then((r) => { if (r.ok) setFaqs(r.faqs) }) }, [])
  useEffect(() => { portal.info().then((r) => { if (r.ok) setInfo(r.info) }) }, [])

  // Aparición al hacer scroll: cada bloque marcado `.reveal` se desvela al entrar en
  // pantalla. Blindado para que NUNCA quede algo invisible (es la cara de la empresa):
  //  · lo que ya está en pantalla se muestra al momento (sin esperar al observer);
  //  · el resto, al hacer scroll;
  //  · red de seguridad por tiempo: si el observer fallara, se muestra todo igual;
  //  · sin observer o con «menos movimiento», se muestra todo directamente.
  useEffect(() => {
    const root = rootRef.current
    if (!root) return
    const els = [...root.querySelectorAll('.reveal:not(.in)')]
    const mostrar = (e) => e.classList.add('in')
    if (!('IntersectionObserver' in window) || matchMedia('(prefers-reduced-motion: reduce)').matches) {
      els.forEach(mostrar); return
    }
    // Lo que ya se ve al cargar, sin depender del callback del observer.
    const vh = window.innerHeight || 800
    els.forEach((e) => { if (e.getBoundingClientRect().top < vh * 0.92) mostrar(e) })
    const io = new IntersectionObserver((ents) => {
      ents.forEach((e) => { if (e.isIntersecting) { mostrar(e.target); io.unobserve(e.target) } })
    }, { threshold: 0.12, rootMargin: '0px 0px -8% 0px' })
    els.filter((e) => !e.classList.contains('in')).forEach((e) => io.observe(e))
    const red = setTimeout(() => els.forEach(mostrar), 4000)   // red de seguridad
    return () => { io.disconnect(); clearTimeout(red) }
  }, [faqs, info])

  const filtro = q.trim().toLowerCase()
  // Búsqueda por PALABRAS (>3 letras, se ignoran «las», «no», «el»…): así «las
  // etiquetas no cargan» encuentra «no han cargado las etiquetas» aunque no sea la
  // frase literal. Además cruza las PALABRAS CLAVE de cada FAQ (cómo lo dice el
  // cliente aunque no aparezca en el título), que es justo lo que las hace útiles.
  const palabras = filtro.split(/\s+/).filter((w) => w.length > 3)
  const items = faqs.filter((f) => {
    if (!palabras.length) return true
    const texto = (f.question + ' ' + f.answer + ' ' + (f.keywords || []).join(' ')).toLowerCase()
    return palabras.some((w) => texto.includes(w))
  })

  // ¿Estamos atendiendo AHORA? Lun–Vie 07:00–21:00 (ver [[helpdesk-turnos]]). Es la
  // hora del cliente, que es un dato honesto: si son las 11 de la noche, lo sabe.
  const ahora = new Date()
  const abierto = ahora.getDay() >= 1 && ahora.getDay() <= 5 && ahora.getHours() >= 7 && ahora.getHours() < 21

  // Al elegir una sugerencia se baja a las «Dudas frecuentes». OJO: el scroll va en
  // un efecto (tras el render), no aquí: si se hiciera ahora, mediría la posición
  // VIEJA —con las sugerencias y las 8 FAQ aún puestas— y, al encogerse la página por
  // el filtro, ese punto acabaría en el pie. Por eso te «mandaba abajo del todo».
  const [scrollTick, setScrollTick] = useState(0)
  const buscar = (t) => { setQ(t); setOpen(-1); setScrollTick((x) => x + 1) }
  useEffect(() => { if (scrollTick) faqRef.current?.scrollIntoView({ behavior: 'smooth', block: 'start' }) }, [scrollTick])

  // Abrir una FAQ suma una vista (una vez por sesión, sin bloquear la UI).
  const abrir = (f) => {
    const nueva = open === f.id ? -1 : f.id
    setOpen(nueva)
    if (nueva !== -1 && !vistas.current.has(f.id)) { vistas.current.add(f.id); portal.faqView(f.id) }
  }
  // Voto de utilidad: uno por navegador y FAQ (se recuerda en localStorage).
  const votar = (f, util) => {
    if (voted[f.id]) return
    const next = { ...voted, [f.id]: util ? 'y' : 'n' }
    setVoted(next); localStorage.setItem('faq_voted', JSON.stringify(next))
    portal.faqVote(f.id, util)
  }
  // «No me sirve → abrir incidencia»: precarga asunto (la pregunta) y la categoría
  // vinculada de la FAQ, para que el cliente escriba lo menos posible.
  const abrirIncidencia = (f) => irCrear({ subject: f.question, category_id: f.category_id || null })

  return (
    <section className="screen on" ref={rootRef}>
      <div className="wrap">
        <div className="hero">
          <svg className="hero-waves" viewBox="0 0 640 300" fill="none" aria-hidden="true">
            <g stroke="var(--brand)" strokeWidth="1.5" opacity=".5">
              <circle cx="320" cy="150" r="60" /><circle cx="320" cy="150" r="110" />
              <circle cx="320" cy="150" r="165" /><circle cx="320" cy="150" r="225" />
            </g>
            <rect x="286" y="132" width="68" height="36" rx="6" fill="var(--brand)" opacity=".9" />
            <rect x="293" y="139" width="42" height="7" rx="2" fill="#fff" opacity=".9" />
            <rect x="293" y="150" width="30" height="5" rx="2" fill="#fff" opacity=".6" />
          </svg>
          {/* Estado en vivo: honesto y tranquilizador. Verde si atendemos ahora. */}
          <span className={`eyebrow ${abierto ? '' : 'cerrado'}`}>
            <span className="dot" />
            {abierto ? t('status_open') : t('status_closed')}
          </span>
          <h1><span className="h1-l1">{t('hero_greeting')} <span className="wave">👋</span>,</span>{t('hero_help')}</h1>
          <p className="sub">
            {t('hero_sub_1')}
            <span className="sub-2">{t('hero_sub_2')}</span>
          </p>
          <div className="search">
            <div className="search-box">
              <span className="mag" style={{ display: 'flex' }}>{I.mag}</span>
              <input value={q} onChange={(e) => setQ(e.target.value)} autoComplete="off"
                placeholder={t('search_placeholder')} />
              {q && <button className="search-x" onClick={() => setQ('')} title={t('clear')}>{I.x}</button>}
            </div>
            {!filtro && (
              <div className="sugs">
                <span>{t('try_with')}</span>
                {SUGERENCIAS.map((s) => <button key={s} onClick={() => buscar(t(s))}>{t(s)}</button>)}
              </div>
            )}
          </div>
        </div>

        <div ref={faqRef} className="section-h reveal">
          <h2>{t('faq_title')}</h2>
          {filtro && <span className="section-n">{items.length} {items.length === 1 ? t('result_one') : t('result_many')}</span>}
          <span className="rule" />
        </div>
        <div className="faq">
          {items.map((f, i) => (
            <div key={f.id} className={`qa ${open === f.id ? 'open' : ''}`} style={{ '--r': i }}>
              <button className="qa-q" onClick={() => abrir(f)}
                aria-expanded={open === f.id} aria-controls={`qa-a-${f.id}`}>
                <span className="qmark">?</span>{f.question}
                <span className="plus">{I.plus}</span>
              </button>
              {/* `inert` cuando está cerrada: sus botones (👍/👎, «abrir incidencia») no
                  quedan en el orden de tabulación ni los anuncia el lector de pantalla. */}
              <div id={`qa-a-${f.id}`} className="qa-a" style={{ maxHeight: open === f.id ? '600px' : 0 }}
                {...(open === f.id ? {} : { inert: '' })}>
                <div className="qa-a-in">
                  {f.answer}{f.hint && <div className="tip">💡 {f.hint}</div>}
                  {/* Pie de la respuesta: ¿te ha servido? + salida a incidencia. */}
                  <div className="qa-foot">
                    {voted[f.id]
                      ? <span className="qa-thanks">{I.check} {t('vote_thanks')}</span>
                      : <span className="qa-vote"><b>{t('vote_q')}</b>
                          <button onClick={() => votar(f, true)} aria-label={t('vote_yes_aria')}>👍</button>
                          <button onClick={() => votar(f, false)} aria-label={t('vote_no_aria')}>👎</button>
                        </span>}
                    <button className="qa-cta" onClick={() => abrirIncidencia(f)}>{t('faq_cta')} {I.arrow}</button>
                  </div>
                </div>
              </div>
            </div>
          ))}
        </div>
        {!items.length && (
          <div className="faq-empty">
            <b>{filtro ? t('empty_search', { q }) : t('empty_no_faq')}</b>
            <p>{filtro ? t('empty_search_sub') : t('empty_no_faq_sub')}</p>
            <button className="btn" style={{ width: 'auto', margin: '4px auto 0' }} onClick={() => irCrear()}>{I.plus} {t('create_ticket')}</button>
          </div>
        )}

        <div className="actions">
          <div className="act-sep reveal"><span>{t('not_here')}</span></div>
          <div className="act-grid">
            <button className="act primary reveal" style={{ '--r': 0 }} onClick={() => irCrear()}>
              <span className="ic">{I.plus}</span>
              <span><h3>{t('create_ticket')}</h3><p>{t('create_ticket_desc')}</p></span>
              <span className="arrow">{I.arrow}</span>
            </button>
            <button className="act ghost reveal" style={{ '--r': 1 }} onClick={() => go('mis')}>
              <span className="ic">{I.tickets}</span>
              <span><h3>{t('my_tickets')}</h3><p>{t('my_tickets_desc')}</p></span>
              <span className="arrow">{I.arrow}</span>
            </button>
          </div>
          {/* Atajo sin correo: solo ver cómo va, por número. */}
          <button className="est-link reveal" onClick={() => go('estado')}>
            {I.mag}<Rich html={t('est_link')} />
            <span className="est-link-arw">{I.arrow}</span>
          </button>
        </div>

      </div>

      {/* Centro de atención: banda de contacto. Va FUERA del .wrap para tener más
          ancho —así los correos largos respiran y las columnas quedan equilibradas—.
          Iconos por tipo, correos y teléfonos clicables. Editable desde Agentes. */}
      {info.length > 0 && (
        <div className="centro-band reveal">
          <div className="centro-inner">
            <span className="centro-eyebrow">{t('help_center')}</span>
            <div className="centro-cols">
              {info.map((a) => (
                <div key={a.id} className="centro-col">
                  <div className="centro-col-h">
                    <span className="centro-ic">{iconoInfo(a.title)}</span>
                    <h3>{a.title}</h3>
                  </div>
                  <div className="info-body">{linkify(a.body)}</div>
                </div>
              ))}
            </div>
          </div>
        </div>
      )}
    </section>
  )
}

/* --------------------- Acceso: correo → código → pase --------------------- */
function Acceso({ intent, go, onReady, caducado }) {
  const { t } = useLang()
  const [paso, setPaso] = useState('mail')   // mail | code
  const [mail, setMail] = useState('')
  const [busy, setBusy] = useState(false)
  const [err, setErr] = useState('')
  const [espera, setEspera] = useState(0)   // segundos hasta poder pedir otro código
  const valido = /^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(mail)
  const titulo = intent === 'crear' ? t('create_ticket') : t('my_tickets')

  // Cuenta atrás del enfriamiento: sin esto, el botón de reenviar se puede
  // machacar hasta topar con el límite del servidor. 60 s es el estándar.
  useEffect(() => {
    if (!espera) return
    const t = setTimeout(() => setEspera((s) => s - 1), 1000)
    return () => clearTimeout(t)
  }, [espera])

  const pedir = async () => {
    if (busy || espera) return
    setBusy(true); setErr('')
    const r = await portal.requestCode(mail.trim())
    setBusy(false)
    if (r.ok) { setPaso('code'); setEspera(60) }
    else setErr(r.error || t('err_send_code'))
  }
  // Cambiar de correo apunta a otro buzón (otro cupo en el servidor): se reinicia
  // el enfriamiento para no bloquear un envío legítimo a una dirección distinta.
  const cambiarMail = (v) => { setMail(v); if (espera) setEspera(0) }

  const verificar = async (code) => {
    const r = await portal.verifyCode(mail.trim(), code)
    if (r.ok) { onReady(r.token); return true }
    setErr(r.error || t('err_bad_code'))
    return false
  }

  return (
    <section className="screen on"><div className="flow"><div className="card">
      <button className="back" onClick={() => paso === 'code' ? setPaso('mail') : go('home')}>
        {I.back}{paso === 'code' ? t('change_email') : t('back_home')}
      </button>
      <div className="steps"><i className={paso === 'mail' ? 'on' : 'done'} /><i className={paso === 'code' ? 'on' : ''} /></div>

      {/* Medallón que ancla el momento: sobre (paso correo) → candado (paso código).
          Le da un foco a la pantalla en vez de empezar con un título a secas. */}
      <div className={`acc-ico ${paso}`} key={paso}>{paso === 'mail' ? I.mail : I.lock}</div>

      {paso === 'mail' ? (
        // <form>: así el Enter envía de forma nativa (y sin recargar la página).
        <form onSubmit={(e) => { e.preventDefault(); if (valido && !busy) pedir() }}>
          <h3 className="ttl">{titulo}</h3>
          {caducado && <div className="acc-aviso">{t('session_expired')}</div>}
          <Rich tag="p" className="desc" html={t('acc_email_desc')} />
          <label className="f"><span className="lab">{t('your_email')}</span>
            <input className="inp" type="email" value={mail} autoFocus autoComplete="username"
              onChange={(e) => cambiarMail(e.target.value)} placeholder={t('email_placeholder')} /></label>
          {err && <p className="hint" style={{ color: 'var(--danger)' }}>{err}</p>}
          <button className="btn" type="submit" disabled={!valido || busy || espera > 0}>
            {busy ? t('sending') : espera > 0 ? t('wait_seconds', { n: espera }) : t('send_code')}{!busy && !espera && I.arrow}
          </button>
          {/* Si ya tienes uno de hace un rato (viven 10 min), no hace falta pedir
              otro: pasas directo a introducirlo. */}
          <button type="button" className="linkbtn" disabled={!valido}
            onClick={() => { setErr(''); setPaso('code') }}>
            {t('have_code')}
          </button>
          <p className="acc-trust">{I.lock}<Rich html={t('acc_trust_email')} /></p>
        </form>
      ) : (
        <>
          <h3 className="ttl">{t('check_email')}</h3>
          <Rich tag="p" className="desc" html={t('code_desc', { mail: mask(mail) })} />
          <Otp onComplete={verificar} error={err} clearError={() => setErr('')} />
          <p className="resend">{t('resend_q')}{' '}
            {espera > 0
              ? <span className="resend-wait">{t('resend_wait', { n: espera })}</span>
              : <button onClick={pedir} disabled={busy}>{busy ? t('sending') : t('resend_new')}</button>}
          </p>
          <p className="acc-trust"><Rich html={t('code_trust')} /></p>
        </>
      )}
    </div></div></section>
  )
}

/* Seis casillas con auto-avance. Al completarse, valida; si falla, se vacía. */
function Otp({ onComplete, error, clearError }) {
  const [vals, setVals] = useState(['', '', '', '', '', ''])
  const refs = useRef([])
  const [checking, setChecking] = useState(false)

  const set = (i, v) => {
    v = v.replace(/\D/g, '').slice(-1)
    setVals((s) => { const n = [...s]; n[i] = v; return n })
    if (v && i < 5) refs.current[i + 1]?.focus()
    clearError()
  }
  useEffect(() => {
    const code = vals.join('')
    if (code.length === 6 && !checking) {
      setChecking(true)
      Promise.resolve(onComplete(code)).then((ok) => {
        setChecking(false)
        if (!ok) { setVals(['', '', '', '', '', '']); refs.current[0]?.focus() }
      })
    }
  }, [vals]) // eslint-disable-line react-hooks/exhaustive-deps

  return (
    <>
      <div className="otp">
        {vals.map((v, i) => (
          <input key={i} ref={(el) => (refs.current[i] = el)} value={v} inputMode="numeric" autoFocus={i === 0}
            className={v ? 'filled' : ''} disabled={checking}
            onChange={(e) => set(i, e.target.value)}
            onKeyDown={(e) => { if (e.key === 'Backspace' && !v && i > 0) refs.current[i - 1]?.focus() }} />
        ))}
      </div>
      {error && <p className="hint" style={{ color: 'var(--danger)', textAlign: 'center' }}>{error}</p>}
    </>
  )
}

/* ----------------------------- Crear ticket ------------------------------ */
function Crear({ go, prefill, onOpen, onExpire }) {
  const { t } = useLang()
  const [cats, setCats] = useState([])
  const [email, setEmail] = useState('')
  const [subject, setSubject] = useState(prefill?.subject || '')
  const [catId, setCatId] = useState('')
  const [body, setBody] = useState('')
  const [files, setFiles] = useState([])
  const [busy, setBusy] = useState(false)
  const [err, setErr] = useState('')
  const [okCode, setOkCode] = useState(null)
  const [copiado, setCopiado] = useState(false)
  const [abiertas, setAbiertas] = useState([])   // incidencias abiertas del cliente (si ya está identificado)
  const emailOk = /^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(email.trim())

  // Aviso de duplicado: solo para quien YA está identificado (con pase). No se sondea
  // por un correo suelto —sería filtrar si un email tiene incidencias— sino sus propias.
  useEffect(() => {
    if (!getPass()) return
    portal.tickets()
      .then((r) => { if (r.ok) setAbiertas((r.tickets || []).filter((x) => x.fase !== 'resuelto')) })
      .catch(() => {})
  }, [])

  // Al cargar las categorías se elige la vinculada de la FAQ (si vino de un CTA) o,
  // en su defecto, la primera de la lista.
  useEffect(() => {
    portal.categories().then((r) => {
      if (!r.ok) return
      setCats(r.categories)
      const porFaq = prefill?.category_id && r.categories.some((c) => c.id === prefill.category_id)
      setCatId(String(porFaq ? prefill.category_id : (r.categories[0]?.id || '')))
    })
  }, [])
  // Si ya hay pase (el cliente entró antes), rellenamos su correo para no pedirlo.
  useEffect(() => { if (getPass()) portal.me().then((r) => { if (r.ok && r.email) setEmail(r.email) }) }, [])

  const enviar = async () => {
    setBusy(true); setErr('')
    const r = await portal.create({ email: email.trim(), subject, category_id: catId || null, body, files })
    setBusy(false)
    if (r.reauth) return onExpire()
    if (r.ok) setOkCode(r.code); else setErr(r.error || t('err_create'))
  }

  const copiar = () => {
    try { navigator.clipboard?.writeText(okCode) } catch { /* sin portapapeles */ }
    setCopiado(true); setTimeout(() => setCopiado(false), 1800)
  }

  const cortoDeMas = body.trim().length > 0 && body.trim().length < 5

  if (okCode) return (
    <section className="screen on"><div className="flow"><div className="card card-ok">
      <div className="ok-mark">{I.check}</div>
      <h3 className="ttl">{t('created_title')}</h3>
      <p className="desc">{t('created_desc')}</p>
      <div className="tk-box">
        <span className="tk-lb">{t('your_ticket_number')}</span>
        <div className="tk-row">
          <span className="tk-num">{okCode}</span>
          <button className="tk-copy" onClick={copiar}>{copiado ? <>{I.check} {t('copied')}</> : <>{I.copy2} {t('copy')}</>}</button>
        </div>
      </div>
      <p className="ok-mail">{I.mail} {t('created_mail_note')}</p>
      <button className="btn" onClick={() => onOpen(okCode)}>{t('view_ticket')} {I.arrow}</button>
      <button className="btn sec" style={{ marginTop: 10 }} onClick={() => go('home')}>{t('back_home')}</button>
    </div></div></section>
  )

  return (
    <section className="screen on"><div className="flow"><div className="card">
      <button className="back" onClick={() => go('home')}>{I.back}{t('back_home')}</button>
      <h3 className="ttl">{t('create_form_title')}</h3>
      <p className="desc">{t('create_form_desc')}</p>

      {abiertas.length > 0 && (
        <div className="dup-open">
          <b>{t('dup_open_title')}</b>
          <p>{t('dup_open_sub')}</p>
          <div className="dup-open-list">
            {abiertas.slice(0, 4).map((tk) => (
              <button key={tk.code} type="button" className="dup-open-item" onClick={() => onOpen(tk.code)}>
                <b>{tk.code}</b><span>{tk.subject}</span>
              </button>
            ))}
          </div>
          <button type="button" className="linkbtn" onClick={() => go('mis')}>{t('my_tickets')} {I.arrow}</button>
        </div>
      )}

      <label className="f"><span className="lab">{t('your_email')}</span>
        <input className="inp" type="email" value={email} autoFocus autoComplete="email"
          onChange={(e) => setEmail(e.target.value)} placeholder={t('email_placeholder')} />
        <span className="hint">{t('email_hint')}</span></label>

      <label className="f"><span className="lab">{t('subject')}</span>
        <input className="inp" value={subject} onChange={(e) => setSubject(e.target.value)}
          placeholder={t('subject_placeholder')} /></label>

      {/* Categoría como CHIPS: se ven todas las opciones y se elige de un toque. */}
      <div className="f"><span className="lab">{t('category')}</span>
        <div className="catchips">
          {cats.map((c) => (
            <button key={c.id} type="button" className={`catchip ${String(c.id) === catId ? 'on' : ''}`}
              onClick={() => setCatId(String(c.id))}>{c.name}</button>
          ))}
        </div></div>

      <label className="f"><span className="lab">{t('description')}</span>
        <textarea className="inp" rows={5} value={body} onChange={(e) => setBody(e.target.value)}
          placeholder={t('desc_placeholder')} />
        {cortoDeMas && <span className="hint" style={{ color: 'var(--wait)' }}>{t('too_short')}</span>}</label>

      <div className="f"><span className="lab">{t('attach_label')} <span className="hint" style={{ fontWeight: 400 }}>{t('attach_optional')}</span></span>
        <Adjuntar files={files} setFiles={setFiles} /></div>

      {err && <p className="hint" style={{ color: 'var(--danger)' }}>{err}</p>}
      <button className="btn" disabled={busy || !emailOk || !subject.trim() || body.trim().length < 5} onClick={enviar}>
        {busy ? t('sending') : <>{I.send} {t('send_ticket')}</>}
      </button>
    </div></div></section>
  )
}

/* ---------------- Ver estado por número (público, solo lectura) ----------
 * Se consulta sabiendo solo el número: por eso el backend NO devuelve nada
 * sensible (ni asunto ni mensajes), solo la fase y las fechas. Para leer la
 * conversación o responder, el cliente entra con su correo.
 * ------------------------------------------------------------------------- */
function Estado({ go }) {
  const { t, lang } = useLang()
  const [code, setCode] = useState('')
  const [busy, setBusy] = useState(false)
  const [err, setErr] = useState('')
  const [data, setData] = useState(null)   // null = formulario · objeto = resultado

  const consultar = async () => {
    const c = code.trim()
    if (!c || busy) return
    setBusy(true); setErr('')
    const r = await portal.estado(c)
    setBusy(false)
    if (r.ok) setData(r.status)
    else setErr(r.error || t('err_not_found'))
  }

  const dotCur = <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.5"><circle cx="12" cy="12" r="3.5" /></svg>

  // ---- Resultado (solo lectura) ----
  if (data) {
    const idx = ['recibido', 'en_proceso', 'resuelto'].indexOf(data.fase)
    const resuelto = data.fase === 'resuelto'
    const fase = FASE[data.fase] || FASE.recibido
    return (
      <section className="screen on"><div className="flow">
        <button className="back" onClick={() => go('home')}>{I.back}{t('back_home')}</button>

        <div className={`thero ${fase.cls}`}>
          <div className="thero-top">
            <span className="thero-eyebrow">{data.code}</span>
            <span className="thero-pill"><span className="cd" />{t('updated', { time: relTime(data.updated, t, lang) })}</span>
          </div>
          <h2 className="thero-title">{t(fase.labelKey)}</h2>
          <p className="thero-sub">{resuelto && data.resuelto_en ? t('resolved_sub', { time: relTime(data.resuelto_en, t, lang) }) : t(fase.subKey)}</p>

          <div className="prog">
            {[['recibido', 'phase_received'], ['en_proceso', 'phase_progress'], ['resuelto', 'phase_resolved']].map(([k, lb], i) => {
              const done = i < idx || (i === idx && resuelto)
              const cur = i === idx && !resuelto
              return (
                <div key={k} className={`prog-st ${done ? 'done' : ''} ${cur ? 'cur' : ''}`}>
                  {i > 0 && <span className="prog-bar" />}
                  <span className="prog-dot">{done ? I.check : cur ? dotCur : null}</span>
                  <span className="prog-lb">{t(lb)}</span>
                </div>
              )
            })}
          </div>
        </div>

        <div className="est-meta">
          <div><span>{t('created_label')}</span><b>{fmtDate(data.created, lang)}</b></div>
          <div><span>{resuelto ? t('resolved_label') : t('last_update')}</span><b>{relTime(resuelto && data.resuelto_en ? data.resuelto_en : data.updated, t, lang)}</b></div>
        </div>

        <div className="est-foot">
          <p>{t('status_only_note')}</p>
          <button className="btn sec" onClick={() => go('mis')}>{I.lock} {t('enter_with_email')}</button>
          <button className="linkbtn" onClick={() => { setData(null); setCode(''); setErr('') }}>{t('check_another')}</button>
        </div>
      </div></section>
    )
  }

  // ---- Formulario ----
  return (
    <section className="screen on"><div className="flow"><div className="card">
      <button className="back" onClick={() => go('home')}>{I.back}{t('back_home')}</button>
      <div className="acc-ico">{I.tickets}</div>
      <form onSubmit={(e) => { e.preventDefault(); consultar() }}>
        <h3 className="ttl">{t('status_form_title')}</h3>
        <p className="desc">{t('status_form_desc')}</p>
        <label className="f"><span className="lab">{t('ticket_number_label')}</span>
          <input className="inp est-code" value={code} autoFocus autoComplete="off"
            onChange={(e) => setCode(e.target.value.toUpperCase())} placeholder="TK-2607-0025" /></label>
        {err && <p className="hint" style={{ color: 'var(--danger)' }}>{err}</p>}
        <button className="btn" type="submit" disabled={busy || !code.trim()}>
          {busy ? t('checking') : <>{I.mag} {t('view_status')}</>}
        </button>
        <p className="acc-trust">{I.lock}<Rich html={t('status_trust')} /></p>
      </form>
    </div></div></section>
  )
}

/* ----------------------------- Mis tickets ------------------------------- */
/* Qué significa «quién habló el último» para el cliente (texto por clave). */
const ULTIMO = {
  soporte: { key: 'last_support', cls: 'resp' },
  cliente: { key: 'last_waiting', cls: 'wait' },
  cerrado: { key: '', cls: '' },
}

/* Una tarjeta de ticket. `apagada` = resuelta: se ve más calmada (es archivo).
   OJO: `ticket` es el ticket; el traductor viene como `tr` para no chocar. */
function TicketCard({ ticket, onOpen, apagada }) {
  const { t: tr, lang } = useLang()
  const u = ULTIMO[ticket.ultimo] || ULTIMO.cliente
  const fase = FASE[ticket.fase] || FASE.recibido
  // «Respuesta nueva»: soporte fue el último en hablar y el cliente aún no lo ha
  // visto (su última visita a este ticket es anterior al último mensaje).
  const seen = getSeen(ticket.code)
  const nuevo = ticket.ultimo === 'soporte' && (!seen || new Date(ticket.fecha) > new Date(seen))
  return (
    <button className={`tcard ${ticket.fase} ${apagada ? 'apagada' : ''} ${nuevo ? 'nuevo' : ''}`} onClick={() => onOpen(ticket.code)}>
      <div className="tcard-top">
        <span className={`chip ${CHIP[ticket.fase]}`}><span className="cd" />{tr(fase.labelKey)}</span>
        {nuevo && <span className="tcard-new">{tr('new_reply')}</span>}
        <span className="tcard-code">{ticket.code}</span>
      </div>
      <h3 className="tcard-subj">{ticket.subject}</h3>
      {ticket.preview && <p className="tcard-prev">{ticket.preview}</p>}
      <div className="tcard-foot">
        {u.key && <span className={`tcard-last ${u.cls}`}><span className="tcard-last-dot" />{tr(u.key)}</span>}
        <span className="tcard-when">{relTime(ticket.fecha, tr, lang)}</span>
        <span className="tcard-go">{I.arrow}</span>
      </div>
    </button>
  )
}

function Mis({ go, onOpen, onExpire }) {
  const { t } = useLang()
  const [rows, setRows] = useState(null)
  const [filtro, setFiltro] = useState('todas')   // todas | abiertas | resueltas
  useEffect(() => {
    portal.tickets().then((r) => { if (r.reauth) return onExpire(); setRows(r.ok ? r.tickets : []) })
  }, []) // eslint-disable-line react-hooks/exhaustive-deps

  const abiertasList = (rows || []).filter((t) => t.fase !== 'resuelto')
  const resueltasList = (rows || []).filter((t) => t.fase === 'resuelto')
  const abiertas = abiertasList.length
  const resueltas = resueltasList.length

  return (
    <section className="screen on"><div className="wrap mislist">
      <button className="back" onClick={() => go('home')}>{I.back}{t('home')}</button>

      <div className="mis-head">
        <div>
          <h1>{t('my_tickets_title')}</h1>
          <p>{rows === null ? '' : rows.length === 0 ? t('none_yet')
            : t('mis_summary', { total: rows.length, open: abiertas, openWord: abiertas === 1 ? t('open_one') : t('open_many') })}</p>
        </div>
        <button className="mis-nueva" onClick={() => go('crear')}>{I.plus} {t('create_ticket_short')}</button>
      </div>

      {/* Filtro: solo si hay de sobra para que aporte. */}
      {rows && rows.length > 1 && (
        <div className="mis-filtro">
          {[['todas', t('filter_all'), rows.length], ['abiertas', t('filter_open'), abiertas], ['resueltas', t('filter_resolved'), resueltas]].map(([k, lb, n]) => (
            <button key={k} className={filtro === k ? 'on' : ''} onClick={() => setFiltro(k)}>{lb} <em>{n}</em></button>
          ))}
        </div>
      )}

      {rows === null ? <div className="mis-cargando">{t('loading')}</div>
        : rows.length === 0 ? (
          <div className="mis-vacia">
            <div className="mis-vacia-ic">{I.tickets}</div>
            <b>{t('mis_empty_title')}</b>
            <p>{t('mis_empty_sub')}</p>
            <button className="btn" style={{ width: 'auto', margin: '4px auto 0' }} onClick={() => go('crear')}>{I.plus} {t('create_ticket')}</button>
          </div>
        ) : (
          <>
            {/* ABIERTAS primero, con prioridad: son las que el cliente necesita
                seguir. Solo se ocultan si el filtro pide ver solo las resueltas. */}
            {filtro !== 'resueltas' && abiertasList.length > 0 && (
              <div className="mis-grupo">
                <div className="mis-grupo-h abre"><span className="mis-grupo-pt" />{t('group_open')}
                  <em>{abiertas}</em><small>{t('group_open_sub')}</small></div>
                <div className="mlist">
                  {abiertasList.map((ti) => <TicketCard key={ti.code} ticket={ti} onOpen={onOpen} />)}
                </div>
              </div>
            )}

            {/* RESUELTAS: archivo, más calmadas y debajo. */}
            {filtro !== 'abiertas' && resueltasList.length > 0 && (
              <div className="mis-grupo">
                <div className="mis-grupo-h"><span className="mis-grupo-pt done" />{t('group_resolved')}
                  <em>{resueltas}</em><small>{t('group_resolved_sub')}</small></div>
                <div className="mlist">
                  {resueltasList.map((ti) => <TicketCard key={ti.code} ticket={ti} onOpen={onOpen} apagada />)}
                </div>
              </div>
            )}

            {filtro === 'abiertas' && !abiertasList.length && <div className="mis-cargando">{t('no_open')}</div>}
            {filtro === 'resueltas' && !resueltasList.length && <div className="mis-cargando">{t('no_resolved')}</div>}
          </>
        )}
    </div></section>
  )
}

/* ---------------------------- Detalle ticket ----------------------------- */
function Detalle({ code, back, onExpire }) {
  // `t` es el ticket; el traductor viene como `tr` para no chocar con él.
  const { t: tr, lang } = useLang()
  const [t, setT] = useState(null)
  const [txt, setTxt] = useState('')
  const [files, setFiles] = useState([])
  const [busy, setBusy] = useState(false)
  const [marking, setMarking] = useState(false)
  const endRef = useRef(null)
  // Solo bajamos al final tras ENVIAR algo; al abrir se ve la cabecera y el estado.
  const bajarAlFinal = useRef(false)

  const load = useCallback(() => {
    portal.ticket(code).then((r) => { if (r.reauth) return onExpire(); setT(r.ok ? r.ticket : false) })
  }, [code]) // eslint-disable-line react-hooks/exhaustive-deps
  useEffect(() => { load() }, [load])
  useEffect(() => {
    if (bajarAlFinal.current) { endRef.current?.scrollIntoView({ behavior: 'smooth' }); bajarAlFinal.current = false }
  }, [t])

  // Refresco EN VIVO: mientras el cliente mira el ticket, si el técnico responde,
  // aparece solo. Sondea cada 15 s (pausa si la pestaña está oculta y refresca al
  // volver a ella). En el sondeo se ignoran los fallos: un 401 puntual no debe
  // expulsar de la vista (si el token caducó de verdad, saltará al intentar responder).
  useEffect(() => {
    const tick = () => { if (!document.hidden) portal.ticket(code).then((r) => { if (r.ok && r.ticket) setT(r.ticket) }) }
    const iv = setInterval(tick, 15000)
    const onVis = () => { if (!document.hidden) tick() }
    document.addEventListener('visibilitychange', onVis)
    return () => { clearInterval(iv); document.removeEventListener('visibilitychange', onVis) }
  }, [code])

  // Al ver el ticket, se marca como «visto» hasta su último mensaje: así deja de salir
  // como «respuesta nueva» en la lista.
  useEffect(() => {
    if (t && t.mensajes?.length) markSeen(code, t.mensajes.reduce((mx, m) => (m.fecha > mx ? m.fecha : mx), t.mensajes[0].fecha))
  }, [t, code])

  const responder = async () => {
    if (!txt.trim() && !files.length) return
    setBusy(true)
    const r = await portal.reply(code, txt, files)
    setBusy(false)
    if (r.reauth) return onExpire()
    if (r.ok) { setTxt(''); setFiles([]); bajarAlFinal.current = true; load() }
  }

  const resolver = async () => {
    setMarking(true)
    const r = await portal.resolve(code)
    setMarking(false)
    if (r.reauth) return onExpire()
    if (r.ok) load()
  }

  if (t === null) return <section className="screen on"><div className="wrap" style={{ maxWidth: 600, paddingTop: 34, textAlign: 'center', color: 'var(--ink-3)' }}>{tr('loading')}</div></section>
  if (t === false) return <section className="screen on"><div className="wrap" style={{ maxWidth: 600, paddingTop: 34 }}>
    <button className="back" onClick={back}>{I.back}{tr('my_tickets_back')}</button>
    <div className="faq-empty">{tr('err_not_found')}</div>
  </div></section>

  const idx = ['recibido', 'en_proceso', 'resuelto'].indexOf(t.fase)
  const resuelto = t.fase === 'resuelto'
  const fase = FASE[t.fase] || FASE.recibido
  const dotCur = <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.5"><circle cx="12" cy="12" r="3.5" /></svg>

  // La conversación y los hitos de estado, ENTRELAZADOS por fecha: así el cliente
  // ve la historia completa (cuándo se puso en marcha, cuándo se resolvió…), no
  // solo el estado final.
  const linea = [
    ...(t.mensajes || []).map((m) => ({ tipo: 'msg', fecha: m.fecha, m })),
    ...(t.hitos || []).map((h) => ({ tipo: 'hito', fecha: h.fecha, h })),
  ].sort((a, b) => new Date(a.fecha) - new Date(b.fecha))

  const subResuelto = resuelto && t.resuelto_en
    ? tr('resolved_sub', { time: relTime(t.resuelto_en, tr, lang) })
    : tr(fase.subKey)

  return (
    <section className="screen on"><div className="wrap tdetail">
      <button className="back" onClick={back}>{I.back}{tr('my_tickets_back')}</button>

      {/* HERO del ticket: el estado es el protagonista. Todo el bloque se tiñe
          según la fase (azul recibida · ámbar en proceso · verde resuelta). */}
      <div className={`thero ${fase.cls}`}>
        <div className="thero-top">
          <span className="thero-eyebrow">{tr('opened_rel', { code: t.code, time: relTime(t.fecha, tr, lang) })}</span>
          <span className="thero-pill"><span className="cd" />{tr(fase.labelKey)}{resuelto && t.resuelto_en ? ` · ${relTime(t.resuelto_en, tr, lang)}` : ''}</span>
        </div>
        <h2 className="thero-title">{t.subject}</h2>
        <p className="thero-sub">{subResuelto}</p>

        <div className="prog">
          {[['recibido', 'phase_received'], ['en_proceso', 'phase_progress'], ['resuelto', 'phase_resolved']].map(([k, lb], i) => {
            const done = i < idx || (i === idx && resuelto)
            const cur = i === idx && !resuelto
            return (
              <div key={k} className={`prog-st ${done ? 'done' : ''} ${cur ? 'cur' : ''}`}>
                {i > 0 && <span className="prog-bar" />}
                <span className="prog-dot">{done ? I.check : cur ? dotCur : null}</span>
                <span className="prog-lb">{tr(lb)}</span>
              </div>
            )
          })}
        </div>

        {!resuelto && (
          <div className="thero-act">
            <button className="btn-ok" disabled={marking} onClick={resolver}>
              {I.check}{marking ? tr('marking') : tr('resolved_for_me')}
            </button>
          </div>
        )}
      </div>

      {/* ENCUESTA DE SATISFACCIÓN (CSAT). El backend solo la manda cuando aplica
          (incidencia del portal, resuelta y con la encuesta activada). */}
      {t.csat && <Csat code={code} csat={t.csat} />}

      {/* CONVERSACIÓN + HITOS como línea de tiempo (una columna, con espina). */}
      <div className="tl">
        {linea.map((it, i) => {
          if (it.tipo === 'hito') {
            // Un hito de estado: línea de sistema centrada, con el color de la fase.
            return (
              <div key={i} className={`tl-hito ${it.h.fase}`}>
                <span className="tl-hito-dot" />
                <span className="tl-hito-body">
                  <b className="tl-hito-lb">{it.h.label}</b>
                  <span className="tl-hito-time" title={fmtHora(it.h.fecha, lang)}>{relTime(it.h.fecha, tr, lang)}</span>
                </span>
              </div>
            )
          }
          const m = it.m
          const yo = m.de === 'cliente'
          return (
            <div key={i} className={`tl-item ${yo ? 'yo' : 'sop'}`}>
              <div className="tl-av">{yo ? tr('me_avatar') : 'AE'}</div>
              <div className="tl-content">
                <div className="tl-head">
                  <b>{yo ? tr('me_name') : (m.autor || tr('support_name'))}</b>
                  <span className="tl-time" title={fmtHora(m.fecha, lang)}>{relTime(m.fecha, tr, lang)}</span>
                </div>
                {m.html && esCorreo(m.cuerpo) ? (
                  // Es un correo (venga de soporte o del propio cliente por su buzón):
                  // aislado en iframe para que su HTML no rompa nada.
                  <div className="tl-paper"><CorreoFrame html={m.cuerpo} /></div>
                ) : (
                  // Texto simple escrito en el portal: burbuja limpia.
                  <div className="tl-bubble" dangerouslySetInnerHTML={{ __html: m.html ? m.cuerpo : escapeHtml(m.cuerpo) }} />
                )}
                <Adjuntos items={m.adjuntos} />
              </div>
            </div>
          )
        })}
        <div ref={endRef} />
      </div>

      {/* Responder. El recuadro está SIEMPRE: responder un ticket resuelto lo
          reabre, que es justo lo que quiere quien vuelve a escribir. */}
      <div className="reply">
        {resuelto && <p className="reply-note">{tr('reply_reopen_note')}</p>}
        <span className="reply-lab">{resuelto ? tr('reply_again') : tr('reply_to_support')}</span>
        <textarea value={txt} onChange={(e) => setTxt(e.target.value)}
          placeholder={resuelto ? tr('reply_ph_reopen') : tr('reply_ph')} />
        <Adjuntar files={files} setFiles={setFiles} compacta />
        <div className="reply-foot">
          <button className="btn" style={{ width: 'auto', marginLeft: 'auto', padding: '11px 22px' }}
            disabled={busy || (!txt.trim() && !files.length)} onClick={responder}>{busy ? tr('sending') : tr('reply_btn')}</button>
        </div>
      </div>
    </div></section>
  )
}

/*
 * ENCUESTA DE SATISFACCIÓN (CSAT) del portal. Al pulsar una estrella se guarda al
 * instante (un clic y listo) y aparece el comentario opcional. La nota se puede
 * cambiar durante unos días; el backend actualiza sin duplicar.
 */
function Csat({ code, csat }) {
  const { t } = useLang()
  const [score, setScore] = useState(csat.score || 0)
  const [hover, setHover] = useState(0)
  // La caja del comentario SIEMPRE nace vacía (con su placeholder): no se precarga
  // lo ya escrito, para que nunca parezca un texto por defecto.
  const [comment, setComment] = useState('')
  const [rated, setRated] = useState(csat.rated)
  const [busy, setBusy] = useState(false)
  const [saved, setSaved] = useState(false)

  const pulsarEstrella = async (n) => {
    setScore(n)
    setBusy(true)
    // Solo la nota: el comentario NO se toca (así cambiar de estrella no borra
    // un comentario que ya hubiera dejado).
    const r = await portal.rate(code, n)
    setBusy(false)
    if (r.ok) setRated(true)
  }

  const guardarComentario = async () => {
    setBusy(true)
    const r = await portal.rate(code, score, comment)   // aquí sí manda el comentario
    setBusy(false)
    if (r.ok) { setSaved(true); setTimeout(() => setSaved(false), 2600) }
  }

  const marcadas = hover || score

  return (
    <div className={`csat ${rated ? 'done' : ''}`}>
      <div className="csat-title">{rated ? t('csat_thanks') : t('csat_q')}</div>
      {!rated && <p className="csat-sub">{t('csat_sub')}</p>}

      <div className="csat-stars" onMouseLeave={() => setHover(0)}>
        {[1, 2, 3, 4, 5].map((n) => (
          <button key={n} type="button" className={`csat-star ${n <= marcadas ? 'on' : ''}`} disabled={busy}
            onMouseEnter={() => setHover(n)} onClick={() => pulsarEstrella(n)} aria-label={t('csat_star_aria', { n })}>
            {I.star}
          </button>
        ))}
      </div>

      {rated && (
        <div className="csat-more">
          <textarea value={comment} onChange={(e) => setComment(e.target.value)} maxLength={2000}
            placeholder={t('csat_comment_ph')} />
          <div className="csat-foot">
            {saved && <span className="csat-saved">{I.check} {t('saved')}</span>}
            <button className="btn" style={{ width: 'auto', padding: '10px 20px' }} disabled={busy} onClick={guardarComentario}>
              {busy ? t('saving') : t('csat_send')}
            </button>
          </div>
        </div>
      )}
    </div>
  )
}

function Footer() {
  const { t } = useLang()
  return (
    <footer>
      <div className="foot-in">
        <div className="foot brand-blurb">
          <h4>AEME Group</h4>
          <p>{t('foot_blurb')}</p>
          <a className="weblink" href="https://etiquetaselectronicas.com/" target="_blank" rel="noopener noreferrer">{t('visit_web')} {I.ext}</a>
        </div>
        <div className="foot">
          <h4>{t('sectors')}</h4>
          <ul><li>{t('sector_hotels')}</li><li>{t('sector_pharmacies')}</li><li>{t('sector_supermarkets')}</li><li>{t('sector_butchers')}</li><li>{t('sector_gas')}</li></ul>
        </div>
      </div>
      <div className="foot-bar">
        <span>© 2026 AEME Group</span><span className="spacer" />
        <span>{t('foot_hours')}</span>
      </div>
    </footer>
  )
}

function escapeHtml(s) { return (s || '').replace(/[<>&]/g, (c) => ({ '<': '&lt;', '>': '&gt;', '&': '&amp;' }[c])) }
