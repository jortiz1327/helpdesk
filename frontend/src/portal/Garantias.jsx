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
}

// Fotos de cada dispositivo: las mismas que pide la revisión del equipo.
const FOTOS = { esl: ['front', 'back_batteries', 'back_no_batteries'], ap: ['front', 'back'] }
const ISSUES = ['no_enciende', 'pantalla', 'sin_conexion', 'bateria', 'otro']
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
            <select className="inp gw-sitio" value={sitio} onChange={(e) => setSitio(e.target.value)}>
              <option value="">{t('g_all_places')}</option>
              {sitios.map((s) => <option key={s} value={s}>{s}</option>)}
            </select>
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
      </div>

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

/* Dónde está el código: la etiqueta dibujada con el número bajo las barras marcado. */
function AyudaCodigo({ onClose }) {
  const { t } = useLang()
  return (
    <div className="gw-ayuda">
      <svg className="gw-ayuda-img" viewBox="0 0 220 150" aria-hidden="true">
        <rect x="6" y="6" width="208" height="138" rx="10" fill="#fff" stroke="#c9d3e0" strokeWidth="3" />
        <rect x="24" y="22" width="172" height="42" rx="5" fill="#eef3f9" />
        <text x="110" y="49" textAnchor="middle" fontSize="15" fontWeight="700" fill="#46586e">ESL</text>
        <rect x="68" y="76" width="84" height="20" rx="3" fill="none" stroke="#e5484d" strokeWidth="2.5" />
        <text x="110" y="91" textAnchor="middle" fontSize="14" fontFamily="monospace" fill="#0e1e33">812345679</text>
        {[0, 4, 7, 12, 15, 19, 24, 27, 31, 36, 39, 44, 48, 52, 57, 60, 64, 69, 72, 77].map((x, i) => (
          <rect key={i} x={68 + x} y="102" width={i % 3 ? 2 : 3} height="28" fill="#0e1e33" />
        ))}
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
  const [ayuda, setAyuda] = useState(false)
  const [nombre, setNombre] = useState('')
  const [tel, setTel] = useState('')
  const [dir, setDir] = useState('')
  const [enviando, setEnviando] = useState(null)  // null | {hechas, total}
  const [err, setErr] = useState('')
  const fotoCodigo = useRef(null)

  useEffect(() => {
    portal.warrantyPlaces().then((r) => {
      if (r.reauth) return onExpire()
      setLugares(r.ok ? r.places : []); setEmpresa(r.ok ? r.company : null)
      if (r.ok && r.places.length === 1) setPlaceId(r.places[0].id)
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
      nuevos.push({ code: c, kind: tipoDe(c), issue: 'no_enciende', detail: '', fotos: fotoFrontal ? { front: { file: fotoFrontal, leido: c } } : {} })
    }
    setAviso(malos.length ? t('g_bad_codes', { codes: malos.slice(0, 5).join(', ') }) : '')
    if (nuevos.length) setItems((s) => [...s, ...nuevos])
    return nuevos.length
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

  const faltanFotos = items.filter((i) => FOTOS[i.kind].some((f) => !i.fotos[f]))
  const nE = items.filter((i) => i.kind === 'esl').length
  const nA = items.filter((i) => i.kind === 'ap').length

  const puede = { 1: !!sitio, 2: items.length > 0 && faltanFotos.length === 0, 3: nombre.trim() && dir.trim(), 4: true }
  const siguiente = () => { setErr(''); setPaso((p) => Math.min(4, p + 1)); window.scrollTo({ top: 0, behavior: 'smooth' }) }
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
      items: items.map((i) => ({ code: i.code, issue: i.issue, detail: i.detail })),
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
            {lugares === null ? <p className="gw-mute">{t('loading')}</p> : (
              <div className="gw-lugares">
                {lugares.map((p) => (
                  <button key={p.id} type="button" className={`gw-lugar ${placeId === p.id ? 'on' : ''}`} onClick={() => setPlaceId(p.id)}>
                    <span className="gw-lugar-ic">{G.building}</span><b>{p.name}</b>
                    <span className="gw-radio" />
                  </button>
                ))}
                <div className={`gw-lugar otro ${!placeId ? 'on' : ''}`} onClick={() => setPlaceId(null)}>
                  <span className="gw-lugar-ic">{I.plus}</span>
                  <span className="gw-lugar-otro"><b>{lugares.length ? t('g_other_place') : t('g_place_name')}</b>
                    <input className="inp" value={otro} onChange={(e) => { setOtro(e.target.value); setPlaceId(null) }}
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
                <button type="button" className="btn sec" disabled={leyendo} onClick={() => fotoCodigo.current?.click()}>
                  {G.barcode} {leyendo ? t('g_reading') : t('g_read_photo')}</button>
              </div>
            </div>
            {aviso && <p className="gw-err">{G.warn} {aviso}</p>}
            {ayuda && <AyudaCodigo onClose={() => setAyuda(false)} />}

            {items.length > 0 && (
              <>
                <div className="gw-lista-h">
                  <b>{t('g_devices_added', { n: items.length })}</b>
                  <span>{t('g_photos_needed')}</span>
                </div>
                <div className="gw-items">
                  {items.map((i) => {
                    const faltan = FOTOS[i.kind].filter((f) => !i.fotos[f]).length
                    return (
                      <div key={i.code} className={`gw-item ${faltan ? 'falta' : ''}`}>
                        <div className="gw-item-id">
                          <span className="gw-item-ic">{i.kind === 'ap' ? G.ap : G.tag}</span>
                          <span><b className="gw-mono">{i.code}</b><small>{i.kind === 'ap' ? t('g_antenna') : t('g_label')}</small></span>
                          <button type="button" className="gw-quitar" onClick={() => setItems((s) => s.filter((x) => x.code !== i.code))} title={t('remove')}>{G.trash}</button>
                        </div>
                        <div className="gw-item-f">
                          <select className="inp" value={i.issue} onChange={(e) => cambiar(i.code, 'issue', e.target.value)} aria-label={t('g_issue_q')}>
                            {ISSUES.map((k) => <option key={k} value={k}>{t('g_issue_' + k)}</option>)}
                          </select>
                          <input className="inp" value={i.detail} maxLength={200} onChange={(e) => cambiar(i.code, 'detail', e.target.value)}
                            placeholder={t('g_detail_ph')} />
                        </div>
                        <div className="gw-fotos">
                          {FOTOS[i.kind].map((tipo) => (
                            <FotoSlot key={tipo} tipo={tipo} kind={i.kind} foto={i.fotos[tipo]}
                              onPick={(f) => ponerFoto(i.code, tipo, f)} onRemove={() => quitarFoto(i.code, tipo)} />
                          ))}
                          <span className={`gw-fotos-n ${faltan ? '' : 'ok'}`}>{faltan ? t('g_photos_missing', { n: faltan }) : <>{I.check} {t('g_photos_ok')}</>}</span>
                        </div>
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
                <input className="inp" value={nombre} onChange={(e) => setNombre(e.target.value)} autoComplete="name" autoFocus /></label>
              <label className="f"><span className="lab">{t('phone')} <span className="hint" style={{ fontWeight: 400 }}>{t('phone_optional')}</span></span>
                <input className="inp" type="tel" value={tel} onChange={(e) => setTel(e.target.value)} autoComplete="tel" inputMode="tel" placeholder={t('phone_placeholder')} /></label>
            </div>
            <label className="f"><span className="lab">{t('your_email')}</span>
              <input className="inp" value={email} disabled /></label>
            <label className="f"><span className="lab">{t('g_address')}</span>
              <textarea className="inp" rows={3} value={dir} onChange={(e) => setDir(e.target.value)} autoComplete="street-address"
                placeholder={t('g_address_ph')} />
              <span className="hint">{t('g_address_hint')}</span></label>
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
          {paso === 2 && items.length > 0 && faltanFotos.length > 0 && <span className="gw-pie-why">{t('g_photos_block', { n: faltanFotos.length })}</span>}
          {paso < 4
            ? <button type="button" className="btn gw-sig" disabled={!puede[paso]} onClick={siguiente}>{t('g_next')} {I.arrow}</button>
            : <button type="button" className="btn gw-sig" disabled={!!enviando} onClick={enviar}>{enviando ? t('sending') : <>{I.send} {t('g_send_request')}</>}</button>}
        </div>
      </div>
    </div></section>
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
        <ol className="gw-ok-pasos">
          <li><b>1</b><span><strong>{t('g_next_1_t')}</strong>{t('g_next_1')}</span></li>
          <li><b>2</b><span><strong>{t('g_next_2_t')}</strong><Rich html={t('g_next_2', { code: res.code })} /></span></li>
          <li><b>3</b><span><strong>{t('g_next_3_t')}</strong>{t('g_next_3')}</span></li>
        </ol>
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

