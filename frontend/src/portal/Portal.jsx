import { useState, useEffect, useRef, useCallback } from 'react'
import { portal, getPass, setPass, getSeen, markSeen } from './portalApi.js'
import logo from '../assets/logo.png'

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
          <b>{compacta ? 'Adjuntar' : 'Adjunta un archivo'}</b>
          {!compacta && <small> o arrástralo aquí · imágenes, PDF o documentos (máx. 10 MB)</small>}
        </span>
      </button>
      {files.length > 0 && (
        <div className="adj-list">
          {files.map((f, i) => (
            <span key={i} className="adj-chip">
              {I.file}<span className="adj-name">{f.name}</span><span className="adj-size">{humanSize(f.size)}</span>
              <button type="button" onClick={() => setFiles((s) => s.filter((_, j) => j !== i))} title="Quitar">{I.x}</button>
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
      <iframe ref={ref} className="mailframe" title="Mensaje" srcDoc={doc} onLoad={medir}
        style={{ height: visible }} sandbox="allow-same-origin allow-popups" scrolling="no" />
      {largo && (
        <button type="button" className="correo-mas" onClick={() => setAbierto((v) => !v)}>
          {abierto ? 'Ver menos' : 'Ver el correo completo'}
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
const fmtDate = (iso) => { try { return new Date(iso).toLocaleDateString('es-ES', { day: 'numeric', month: 'short', year: 'numeric' }) } catch { return '' } }
const fmtHora = (iso) => { try { return new Date(iso).toLocaleString('es-ES', { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' }) } catch { return '' } }
/* «hace 3 días», «hoy», «ahora mismo»… en cristiano. */
const relTime = (iso) => {
  const s = (Date.now() - new Date(iso).getTime()) / 1000
  if (s < 60) return 'ahora mismo'
  if (s < 3600) { const m = Math.floor(s / 60); return `hace ${m} min` }
  if (s < 86400) { const h = Math.floor(s / 3600); return `hace ${h} h` }
  const d = Math.floor(s / 86400)
  if (d === 1) return 'ayer'
  if (d < 30) return `hace ${d} días`
  return fmtDate(iso)
}
const CHIP = { recibido: 'nuevo', en_proceso: 'proceso', resuelto: 'resuelto' }
/* Estado del ticket → cómo se ve. El estado es el centro de la pantalla. */
const FASE = {
  recibido:   { cls: 'recibido', label: 'Recibida', sub: 'La hemos recibido y la revisaremos en breve.' },
  en_proceso: { cls: 'proceso', label: 'En proceso', sub: 'Nuestro equipo está trabajando en ella.' },
  resuelto:   { cls: 'resuelto', label: 'Resuelta', sub: 'Se ha dado por resuelta. Si vuelve, respóndenos.' },
}

export default function Portal() {
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
        <button className="logo-btn" onClick={onLogo} title="Volver al inicio" aria-label="Ir al inicio">
          <img className="logo" src={logo} alt="AEME Group" />
        </button>
        <div className="spacer" />
        <a className="ghostlink" href="/agentes">{I.lock} Acceso agentes</a>
      </div>
    </div>
  )
}

/* -------------------------------- Home ----------------------------------- */
/* Sugerencias de búsqueda: rellenan el buscador de un toque. */
const SUGERENCIAS = ['las etiquetas no cargan', 'cambiar el menú', 'repetidor apagado', 'etiqueta rota']

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
            {abierto ? 'Estamos atendiendo ahora' : 'Ahora fuera de horario · te leemos pronto'}
          </span>
          <h1><span className="h1-l1">Buenas <span className="wave">👋</span>,</span>¿En qué podemos ayudarte?</h1>
          <p className="sub">
            Busca tu duda o abre una incidencia.
            <span className="sub-2">Un técnico te responde por correo, de lunes a viernes de 7:00 a 21:00.</span>
          </p>
          <div className="search">
            <div className="search-box">
              <span className="mag" style={{ display: 'flex' }}>{I.mag}</span>
              <input value={q} onChange={(e) => setQ(e.target.value)} autoComplete="off"
                placeholder="Escribe tu duda… ej: las etiquetas no cargan" />
              {q && <button className="search-x" onClick={() => setQ('')} title="Borrar">{I.x}</button>}
            </div>
            {!filtro && (
              <div className="sugs">
                <span>Prueba con</span>
                {SUGERENCIAS.map((s) => <button key={s} onClick={() => buscar(s)}>{s}</button>)}
              </div>
            )}
          </div>
        </div>

        <div ref={faqRef} className="section-h reveal">
          <h2>Dudas frecuentes</h2>
          {filtro && <span className="section-n">{items.length} {items.length === 1 ? 'resultado' : 'resultados'}</span>}
          <span className="rule" />
        </div>
        <div className="faq">
          {items.map((f, i) => (
            <div key={f.id} className={`qa ${open === f.id ? 'open' : ''}`} style={{ '--r': i }}>
              <button className="qa-q" onClick={() => abrir(f)}>
                <span className="qmark">?</span>{f.question}
                <span className="plus">{I.plus}</span>
              </button>
              <div className="qa-a" style={{ maxHeight: open === f.id ? '600px' : 0 }}>
                <div className="qa-a-in">
                  {f.answer}{f.hint && <div className="tip">💡 {f.hint}</div>}
                  {/* Pie de la respuesta: ¿te ha servido? + salida a incidencia. */}
                  <div className="qa-foot">
                    {voted[f.id]
                      ? <span className="qa-thanks">{I.check} ¡Gracias por tu voto!</span>
                      : <span className="qa-vote"><b>¿Te ha servido?</b>
                          <button onClick={() => votar(f, true)} aria-label="Sí, me ha servido">👍</button>
                          <button onClick={() => votar(f, false)} aria-label="No me ha servido">👎</button>
                        </span>}
                    <button className="qa-cta" onClick={() => abrirIncidencia(f)}>No me sirve, abrir incidencia {I.arrow}</button>
                  </div>
                </div>
              </div>
            </div>
          ))}
        </div>
        {!items.length && (
          <div className="faq-empty">
            <b>{filtro ? `No encontramos nada con «${q}».` : 'Aún no hay preguntas frecuentes.'}</b>
            <p>{filtro ? 'Prueba con otras palabras, o cuéntanoslo y lo resolvemos contigo.' : 'Cuéntanos tu caso y lo resolvemos contigo.'}</p>
            <button className="btn" style={{ width: 'auto', margin: '4px auto 0' }} onClick={() => irCrear()}>{I.plus} Crear una incidencia</button>
          </div>
        )}

        <div className="actions">
          <div className="act-sep reveal"><span>¿No lo encuentras aquí?</span></div>
          <div className="act-grid">
            <button className="act primary reveal" style={{ '--r': 0 }} onClick={() => irCrear()}>
              <span className="ic">{I.plus}</span>
              <span><h3>Crear una incidencia</h3><p>Cuéntanos el problema y te asignamos un técnico.</p></span>
              <span className="arrow">{I.arrow}</span>
            </button>
            <button className="act ghost reveal" style={{ '--r': 1 }} onClick={() => go('mis')}>
              <span className="ic">{I.tickets}</span>
              <span><h3>Ver mis incidencias</h3><p>Consulta el estado y responde a las tuyas.</p></span>
              <span className="arrow">{I.arrow}</span>
            </button>
          </div>
          {/* Atajo sin correo: solo ver cómo va, por número. */}
          <button className="est-link reveal" onClick={() => go('estado')}>
            {I.mag}<span>¿Ya tienes tu número? <b>Consulta el estado</b> sin correo</span>
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
            <span className="centro-eyebrow">Centro de atención</span>
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
  const [paso, setPaso] = useState('mail')   // mail | code
  const [mail, setMail] = useState('')
  const [busy, setBusy] = useState(false)
  const [err, setErr] = useState('')
  const [espera, setEspera] = useState(0)   // segundos hasta poder pedir otro código
  const valido = /^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(mail)
  const titulo = intent === 'crear' ? 'Crear una incidencia' : 'Ver mis incidencias'

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
    else setErr(r.error || 'No se pudo enviar el código')
  }
  // Cambiar de correo apunta a otro buzón (otro cupo en el servidor): se reinicia
  // el enfriamiento para no bloquear un envío legítimo a una dirección distinta.
  const cambiarMail = (v) => { setMail(v); if (espera) setEspera(0) }

  const verificar = async (code) => {
    const r = await portal.verifyCode(mail.trim(), code)
    if (r.ok) { onReady(r.token); return true }
    setErr(r.error || 'Código incorrecto')
    return false
  }

  return (
    <section className="screen on"><div className="flow"><div className="card">
      <button className="back" onClick={() => paso === 'code' ? setPaso('mail') : go('home')}>
        {I.back}{paso === 'code' ? 'Cambiar de correo' : 'Volver al inicio'}
      </button>
      <div className="steps"><i className={paso === 'mail' ? 'on' : 'done'} /><i className={paso === 'code' ? 'on' : ''} /></div>

      {/* Medallón que ancla el momento: sobre (paso correo) → candado (paso código).
          Le da un foco a la pantalla en vez de empezar con un título a secas. */}
      <div className={`acc-ico ${paso}`} key={paso}>{paso === 'mail' ? I.mail : I.lock}</div>

      {paso === 'mail' ? (
        // <form>: así el Enter envía de forma nativa (y sin recargar la página).
        <form onSubmit={(e) => { e.preventDefault(); if (valido && !busy) pedir() }}>
          <h3 className="ttl">{titulo}</h3>
          {caducado && <div className="acc-aviso">Tu sesión ha caducado por seguridad. Confirma tu correo otra vez.</div>}
          <p className="desc">Escribe tu <b>correo</b> y te enviamos un código para confirmar que eres tú.</p>
          <label className="f"><span className="lab">Tu correo</span>
            <input className="inp" type="email" value={mail} autoFocus autoComplete="username"
              onChange={(e) => cambiarMail(e.target.value)} placeholder="nombre@tuempresa.com" /></label>
          {err && <p className="hint" style={{ color: 'var(--danger)' }}>{err}</p>}
          <button className="btn" type="submit" disabled={!valido || busy || espera > 0}>
            {busy ? 'Enviando…' : espera > 0 ? `Espera ${espera}s` : 'Enviarme el código'}{!busy && !espera && I.arrow}
          </button>
          {/* Si ya tienes uno de hace un rato (viven 10 min), no hace falta pedir
              otro: pasas directo a introducirlo. */}
          <button type="button" className="linkbtn" disabled={!valido}
            onClick={() => { setErr(''); setPaso('code') }}>
            Ya tengo un código →
          </button>
          <p className="acc-trust">{I.lock}<span>No hace falta registrarse. El código solo confirma que <b>este correo es tuyo</b> — así nadie más puede ver tus incidencias.</span></p>
        </form>
      ) : (
        <>
          <h3 className="ttl">Revisa tu correo</h3>
          <p className="desc">Escribe el código de 6 dígitos que enviamos a <b>{mask(mail)}</b>.</p>
          <Otp onComplete={verificar} error={err} clearError={() => setErr('')} />
          <p className="resend">¿No te llega o ha caducado?{' '}
            {espera > 0
              ? <span className="resend-wait">Puedes pedir otro en {espera}s</span>
              : <button onClick={pedir} disabled={busy}>{busy ? 'Enviando…' : 'Enviar uno nuevo'}</button>}
          </p>
          <p className="acc-trust"><span>Mira también en <b>spam</b> o «no deseado». El código caduca a los <b>10 minutos</b>.</span></p>
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
  const emailOk = /^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(email.trim())

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
    if (r.ok) setOkCode(r.code); else setErr(r.error || 'No se pudo crear la incidencia')
  }

  const copiar = () => {
    try { navigator.clipboard?.writeText(okCode) } catch { /* sin portapapeles */ }
    setCopiado(true); setTimeout(() => setCopiado(false), 1800)
  }

  const cortoDeMas = body.trim().length > 0 && body.trim().length < 5

  if (okCode) return (
    <section className="screen on"><div className="flow"><div className="card card-ok">
      <div className="ok-mark">{I.check}</div>
      <h3 className="ttl">¡Incidencia creada!</h3>
      <p className="desc">Guarda este número: con él y tu correo puedes seguir su estado cuando quieras.</p>
      <div className="tk-box">
        <span className="tk-lb">Tu número de incidencia</span>
        <div className="tk-row">
          <span className="tk-num">{okCode}</span>
          <button className="tk-copy" onClick={copiar}>{copiado ? <>{I.check} Copiado</> : <>{I.copy2} Copiar</>}</button>
        </div>
      </div>
      <p className="ok-mail">{I.mail} Te avisaremos por correo en cuanto un técnico responda.</p>
      <button className="btn" onClick={() => onOpen(okCode)}>Ver la incidencia {I.arrow}</button>
      <button className="btn sec" style={{ marginTop: 10 }} onClick={() => go('home')}>Volver al inicio</button>
    </div></div></section>
  )

  return (
    <section className="screen on"><div className="flow"><div className="card">
      <button className="back" onClick={() => go('home')}>{I.back}Volver al inicio</button>
      <h3 className="ttl">Cuéntanos qué pasa</h3>
      <p className="desc">Sin registros ni contraseñas. Rellénalo y verás tu incidencia al instante.</p>

      <label className="f"><span className="lab">Tu correo</span>
        <input className="inp" type="email" value={email} autoFocus autoComplete="email"
          onChange={(e) => setEmail(e.target.value)} placeholder="nombre@tuempresa.com" />
        <span className="hint">Aquí te avisamos cuando un técnico responda.</span></label>

      <label className="f"><span className="lab">Asunto</span>
        <input className="inp" value={subject} onChange={(e) => setSubject(e.target.value)}
          placeholder="Ej: Las etiquetas de la tienda no cargan" /></label>

      {/* Categoría como CHIPS: se ven todas las opciones y se elige de un toque. */}
      <div className="f"><span className="lab">Categoría</span>
        <div className="catchips">
          {cats.map((c) => (
            <button key={c.id} type="button" className={`catchip ${String(c.id) === catId ? 'on' : ''}`}
              onClick={() => setCatId(String(c.id))}>{c.name}</button>
          ))}
        </div></div>

      <label className="f"><span className="lab">Descripción</span>
        <textarea className="inp" rows={5} value={body} onChange={(e) => setBody(e.target.value)}
          placeholder="¿Qué ocurre? ¿Desde cuándo? ¿Qué has probado ya?" />
        {cortoDeMas && <span className="hint" style={{ color: 'var(--wait)' }}>Cuéntanos un poco más para poder ayudarte.</span>}</label>

      <div className="f"><span className="lab">Adjuntar <span className="hint" style={{ fontWeight: 400 }}>(opcional, ayuda mucho una captura)</span></span>
        <Adjuntar files={files} setFiles={setFiles} /></div>

      {err && <p className="hint" style={{ color: 'var(--danger)' }}>{err}</p>}
      <button className="btn" disabled={busy || !emailOk || !subject.trim() || body.trim().length < 5} onClick={enviar}>
        {busy ? 'Enviando…' : <>{I.send} Enviar incidencia</>}
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
    else setErr(r.error || 'No encontramos esa incidencia')
  }

  const dotCur = <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.5"><circle cx="12" cy="12" r="3.5" /></svg>

  // ---- Resultado (solo lectura) ----
  if (data) {
    const idx = ['recibido', 'en_proceso', 'resuelto'].indexOf(data.fase)
    const resuelto = data.fase === 'resuelto'
    const fase = FASE[data.fase] || FASE.recibido
    return (
      <section className="screen on"><div className="flow">
        <button className="back" onClick={() => go('home')}>{I.back}Volver al inicio</button>

        <div className={`thero ${fase.cls}`}>
          <div className="thero-top">
            <span className="thero-eyebrow">{data.code}</span>
            <span className="thero-pill"><span className="cd" />Actualizada {relTime(data.updated)}</span>
          </div>
          <h2 className="thero-title">{fase.label}</h2>
          <p className="thero-sub">{resuelto && data.resuelto_en ? `Se resolvió ${relTime(data.resuelto_en)}. Si el problema vuelve, respóndenos y la reabrimos.` : fase.sub}</p>

          <div className="prog">
            {[['recibido', 'Recibida'], ['en_proceso', 'En proceso'], ['resuelto', 'Resuelta']].map(([k, lb], i) => {
              const done = i < idx || (i === idx && resuelto)
              const cur = i === idx && !resuelto
              return (
                <div key={k} className={`prog-st ${done ? 'done' : ''} ${cur ? 'cur' : ''}`}>
                  {i > 0 && <span className="prog-bar" />}
                  <span className="prog-dot">{done ? I.check : cur ? dotCur : null}</span>
                  <span className="prog-lb">{lb}</span>
                </div>
              )
            })}
          </div>
        </div>

        <div className="est-meta">
          <div><span>Creada</span><b>{fmtDate(data.created)}</b></div>
          <div><span>{resuelto ? 'Resuelta' : 'Última novedad'}</span><b>{relTime(resuelto && data.resuelto_en ? data.resuelto_en : data.updated)}</b></div>
        </div>

        <div className="est-foot">
          <p>Aquí solo ves el estado. Para leer la conversación o responder:</p>
          <button className="btn sec" onClick={() => go('mis')}>{I.lock} Entrar con mi correo</button>
          <button className="linkbtn" onClick={() => { setData(null); setCode(''); setErr('') }}>Consultar otro número</button>
        </div>
      </div></section>
    )
  }

  // ---- Formulario ----
  return (
    <section className="screen on"><div className="flow"><div className="card">
      <button className="back" onClick={() => go('home')}>{I.back}Volver al inicio</button>
      <div className="acc-ico">{I.tickets}</div>
      <form onSubmit={(e) => { e.preventDefault(); consultar() }}>
        <h3 className="ttl">Ver el estado de tu incidencia</h3>
        <p className="desc">Escribe tu número y te decimos cómo va. Sin contraseñas ni esperas.</p>
        <label className="f"><span className="lab">Número de incidencia</span>
          <input className="inp est-code" value={code} autoFocus autoComplete="off"
            onChange={(e) => setCode(e.target.value.toUpperCase())} placeholder="TK-2607-0025" /></label>
        {err && <p className="hint" style={{ color: 'var(--danger)' }}>{err}</p>}
        <button className="btn" type="submit" disabled={busy || !code.trim()}>
          {busy ? 'Consultando…' : <>{I.mag} Ver estado</>}
        </button>
        <p className="acc-trust">{I.lock}<span>Aquí solo se ve el <b>estado</b>. Para leer la conversación o responder, se entra con el correo.</span></p>
      </form>
    </div></div></section>
  )
}

/* ----------------------------- Mis tickets ------------------------------- */
/* Qué significa «quién habló el último» para el cliente. */
const ULTIMO = {
  soporte: { txt: 'Soporte te respondió', cls: 'resp' },
  cliente: { txt: 'Enviado · esperando respuesta', cls: 'wait' },
  cerrado: { txt: '', cls: '' },
}

/* Una tarjeta de ticket. `apagada` = resuelta: se ve más calmada (es archivo). */
function TicketCard({ t, onOpen, apagada }) {
  const u = ULTIMO[t.ultimo] || ULTIMO.cliente
  // «Respuesta nueva»: soporte fue el último en hablar y el cliente aún no lo ha
  // visto (su última visita a este ticket es anterior al último mensaje).
  const seen = getSeen(t.code)
  const nuevo = t.ultimo === 'soporte' && (!seen || new Date(t.fecha) > new Date(seen))
  return (
    <button className={`tcard ${t.fase} ${apagada ? 'apagada' : ''} ${nuevo ? 'nuevo' : ''}`} onClick={() => onOpen(t.code)}>
      <div className="tcard-top">
        <span className={`chip ${CHIP[t.fase]}`}><span className="cd" />{t.estado}</span>
        {nuevo && <span className="tcard-new">Respuesta nueva</span>}
        <span className="tcard-code">{t.code}</span>
      </div>
      <h3 className="tcard-subj">{t.subject}</h3>
      {t.preview && <p className="tcard-prev">{t.preview}</p>}
      <div className="tcard-foot">
        {u.txt && <span className={`tcard-last ${u.cls}`}><span className="tcard-last-dot" />{u.txt}</span>}
        <span className="tcard-when">{relTime(t.fecha)}</span>
        <span className="tcard-go">{I.arrow}</span>
      </div>
    </button>
  )
}

function Mis({ go, onOpen, onExpire }) {
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
      <button className="back" onClick={() => go('home')}>{I.back}Inicio</button>

      <div className="mis-head">
        <div>
          <h1>Tus incidencias</h1>
          <p>{rows === null ? '' : rows.length === 0 ? 'Aún no has abierto ninguna'
            : `${rows.length} en total · ${abiertas} ${abiertas === 1 ? 'abierta' : 'abiertas'}`}</p>
        </div>
        <button className="mis-nueva" onClick={() => go('crear')}>{I.plus} Crear incidencia</button>
      </div>

      {/* Filtro: solo si hay de sobra para que aporte. */}
      {rows && rows.length > 1 && (
        <div className="mis-filtro">
          {[['todas', 'Todas', rows.length], ['abiertas', 'Abiertas', abiertas], ['resueltas', 'Resueltas', resueltas]].map(([k, lb, n]) => (
            <button key={k} className={filtro === k ? 'on' : ''} onClick={() => setFiltro(k)}>{lb} <em>{n}</em></button>
          ))}
        </div>
      )}

      {rows === null ? <div className="mis-cargando">Cargando…</div>
        : rows.length === 0 ? (
          <div className="mis-vacia">
            <div className="mis-vacia-ic">{I.tickets}</div>
            <b>Aún no tienes incidencias</b>
            <p>Cuando abras una, aquí verás su estado y podrás responder.</p>
            <button className="btn" style={{ width: 'auto', margin: '4px auto 0' }} onClick={() => go('crear')}>{I.plus} Crear una incidencia</button>
          </div>
        ) : (
          <>
            {/* ABIERTAS primero, con prioridad: son las que el cliente necesita
                seguir. Solo se ocultan si el filtro pide ver solo las resueltas. */}
            {filtro !== 'resueltas' && abiertasList.length > 0 && (
              <div className="mis-grupo">
                <div className="mis-grupo-h abre"><span className="mis-grupo-pt" />Abiertas
                  <em>{abiertas}</em><small>en seguimiento</small></div>
                <div className="mlist">
                  {abiertasList.map((t) => <TicketCard key={t.code} t={t} onOpen={onOpen} />)}
                </div>
              </div>
            )}

            {/* RESUELTAS: archivo, más calmadas y debajo. */}
            {filtro !== 'abiertas' && resueltasList.length > 0 && (
              <div className="mis-grupo">
                <div className="mis-grupo-h"><span className="mis-grupo-pt done" />Resueltas
                  <em>{resueltas}</em><small>cerradas</small></div>
                <div className="mlist">
                  {resueltasList.map((t) => <TicketCard key={t.code} t={t} onOpen={onOpen} apagada />)}
                </div>
              </div>
            )}

            {filtro === 'abiertas' && !abiertasList.length && <div className="mis-cargando">No tienes incidencias abiertas. 🎉</div>}
            {filtro === 'resueltas' && !resueltasList.length && <div className="mis-cargando">Aún no tienes incidencias resueltas.</div>}
          </>
        )}
    </div></section>
  )
}

/* ---------------------------- Detalle ticket ----------------------------- */
function Detalle({ code, back, onExpire }) {
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

  if (t === null) return <section className="screen on"><div className="wrap" style={{ maxWidth: 600, paddingTop: 34, textAlign: 'center', color: 'var(--ink-3)' }}>Cargando…</div></section>
  if (t === false) return <section className="screen on"><div className="wrap" style={{ maxWidth: 600, paddingTop: 34 }}>
    <button className="back" onClick={back}>{I.back}Mis incidencias</button>
    <div className="faq-empty">No encontramos esa incidencia.</div>
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
    ? `Se resolvió ${relTime(t.resuelto_en)}. Si el problema vuelve, respóndenos y la reabrimos.`
    : fase.sub

  return (
    <section className="screen on"><div className="wrap tdetail">
      <button className="back" onClick={back}>{I.back}Mis incidencias</button>

      {/* HERO del ticket: el estado es el protagonista. Todo el bloque se tiñe
          según la fase (azul recibida · ámbar en proceso · verde resuelta). */}
      <div className={`thero ${fase.cls}`}>
        <div className="thero-top">
          <span className="thero-eyebrow">{t.code} · abierta {relTime(t.fecha)}</span>
          <span className="thero-pill"><span className="cd" />{fase.label}{resuelto && t.resuelto_en ? ` · ${relTime(t.resuelto_en)}` : ''}</span>
        </div>
        <h2 className="thero-title">{t.subject}</h2>
        <p className="thero-sub">{subResuelto}</p>

        <div className="prog">
          {[['recibido', 'Recibida'], ['en_proceso', 'En proceso'], ['resuelto', 'Resuelta']].map(([k, lb], i) => {
            const done = i < idx || (i === idx && resuelto)
            const cur = i === idx && !resuelto
            return (
              <div key={k} className={`prog-st ${done ? 'done' : ''} ${cur ? 'cur' : ''}`}>
                {i > 0 && <span className="prog-bar" />}
                <span className="prog-dot">{done ? I.check : cur ? dotCur : null}</span>
                <span className="prog-lb">{lb}</span>
              </div>
            )
          })}
        </div>

        {!resuelto && (
          <div className="thero-act">
            <button className="btn-ok" disabled={marking} onClick={resolver}>
              {I.check}{marking ? 'Marcando…' : 'Ya está resuelto para mí'}
            </button>
          </div>
        )}
      </div>

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
                  <span className="tl-hito-time" title={fmtHora(it.h.fecha)}>{relTime(it.h.fecha)}</span>
                </span>
              </div>
            )
          }
          const m = it.m
          const yo = m.de === 'cliente'
          return (
            <div key={i} className={`tl-item ${yo ? 'yo' : 'sop'}`}>
              <div className="tl-av">{yo ? 'Tú' : 'AE'}</div>
              <div className="tl-content">
                <div className="tl-head">
                  <b>{yo ? 'Tú' : (m.autor || 'Soporte AEME')}</b>
                  <span className="tl-time" title={fmtHora(m.fecha)}>{relTime(m.fecha)}</span>
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
        {resuelto && <p className="reply-note">¿El problema ha vuelto? Respóndenos y reabrimos la incidencia.</p>}
        <span className="reply-lab">{resuelto ? 'Escribir de nuevo' : 'Responder a soporte'}</span>
        <textarea value={txt} onChange={(e) => setTxt(e.target.value)}
          placeholder={resuelto ? 'Cuéntanos si el problema ha vuelto y lo retomamos…' : 'Escribe aquí tu mensaje para el equipo de soporte…'} />
        <Adjuntar files={files} setFiles={setFiles} compacta />
        <div className="reply-foot">
          <button className="btn" style={{ width: 'auto', marginLeft: 'auto', padding: '11px 22px' }}
            disabled={busy || (!txt.trim() && !files.length)} onClick={responder}>{busy ? 'Enviando…' : 'Responder'}</button>
        </div>
      </div>
    </div></section>
  )
}

function Footer() {
  return (
    <footer>
      <div className="foot-in">
        <div className="foot brand-blurb">
          <h4>AEME Group</h4>
          <p>Soluciones tecnológicas para el retail: etiquetas electrónicas, menús digitales y consultoría para el punto de venta.</p>
          <a className="weblink" href="https://etiquetaselectronicas.com/" target="_blank" rel="noopener noreferrer">Visita nuestra web {I.ext}</a>
        </div>
        <div className="foot">
          <h4>Sectores</h4>
          <ul><li>Hoteles</li><li>Farmacias</li><li>Supermercados</li><li>Carnicerías</li><li>Gasolineras</li></ul>
        </div>
      </div>
      <div className="foot-bar">
        <span>© 2026 AEME Group</span><span className="spacer" />
        <span>Soporte · Lun–Vie 07:00–21:00</span>
      </div>
    </footer>
  )
}

function escapeHtml(s) { return (s || '').replace(/[<>&]/g, (c) => ({ '<': '&lt;', '>': '&gt;', '&': '&amp;' }[c])) }
