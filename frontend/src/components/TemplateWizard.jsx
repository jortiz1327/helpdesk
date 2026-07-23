import { useState, useMemo } from 'react'
import { api } from '../api.js'
import { Icon } from '../icons.jsx'
import { useToast } from '../App.jsx'
import Select from './Select.jsx'

// Texto estándar de aviso de baja (opt-out) para el pie de las plantillas
const OPTOUT_FOOTER = 'Responde BAJA para no recibir más mensajes.'

const LANGS = [
  ['es', 'Español'], ['es_ES', 'Español (España)'], ['es_MX', 'Español (México)'],
  ['es_AR', 'Español (Argentina)'], ['en', 'Inglés'], ['en_US', 'Inglés (EE.UU.)'],
]
const CATS = [['UTILITY', 'Utilidad'], ['MARKETING', 'Marketing'], ['AUTHENTICATION', 'Autenticación']]
const TYPES = [
  { k: 'STANDARD', t: 'Estándar', d: 'Texto, media, botones', icon: Icon.templates },
  { k: 'CAROUSEL', t: 'Carrusel', d: 'Varias tarjetas con imagen', icon: Icon.carousel },
  { k: 'CATALOG', t: 'Catálogo', d: 'Mensaje de catálogo', icon: Icon.catalog },
]
const STEP_DEFS = {
  STANDARD: ['Información', 'Encabezado', 'Cuerpo', 'Botones', 'Variables', 'Revisar'],
  CAROUSEL: ['Información', 'Tarjetas', 'Revisar'],
  CATALOG: ['Información', 'Catálogo', 'Revisar'],
}

const newCard = () => ({ handle: null, preview: null, uploading: false, body: '', buttons: [] })
const blank = {
  type: 'STANDARD', name: '', language: 'es', category: 'UTILITY',
  header: { format: 'NONE', text: '', mediaFormat: 'IMAGE', handle: null, preview: null, uploading: false },
  body: { text: '', examples: [] },
  footer: { text: '' },
  buttons: [],
  carouselBody: '',
  cards: [newCard(), newCard()],
  catalog: { thumbHandle: null, thumbPreview: null, thumbUploading: false, body: '', footer: '' },
}

const varsIn = (text) => {
  const m = [...(text || '').matchAll(/\{\{(\d+)\}\}/g)].map((x) => parseInt(x[1]))
  return m.length ? Math.max(...m) : 0
}

// Reconstruye el estado del wizard a partir de una plantilla existente (estándar)
function fromTemplate(t) {
  const get = (ty) => (t.components || []).find((c) => c.type === ty)
  const h = get('HEADER'), b = get('BODY'), foot = get('FOOTER'), btns = get('BUTTONS')
  const header = { format: 'NONE', text: '', mediaFormat: 'IMAGE', handle: null, preview: null, uploading: false }
  let headerExample = null
  if (h) {
    if (h.format === 'TEXT') { header.format = 'TEXT'; header.text = h.text || ''; headerExample = h.example?.header_text?.[0] ?? null }
    else { header.format = 'MEDIA'; header.mediaFormat = h.format }
  }
  const bodyExamples = b?.example?.body_text?.[0] || []
  const examples = []
  if (headerExample !== null) examples.push(headerExample)
  examples.push(...bodyExamples)
  const buttons = (btns?.buttons || []).map((x) =>
    x.type === 'URL' ? { type: 'URL', text: x.text, url: x.url }
      : x.type === 'PHONE_NUMBER' ? { type: 'PHONE_NUMBER', text: x.text, phone: x.phone_number }
        : { type: 'QUICK_REPLY', text: x.text })
  return { ...blank, type: 'STANDARD', name: t.name, language: t.language, category: t.category || 'UTILITY', header, body: { text: b?.text || '', examples }, footer: { text: foot?.text || '' }, buttons }
}

// ---- Añadir botones (reutilizable: tarjetas) ----
function ButtonAdder({ buttons, onChange, max = 2 }) {
  const [kind, setKind] = useState('QUICK_REPLY')
  const [text, setText] = useState('')
  const [value, setValue] = useState('')
  const add = () => {
    if (!text.trim() || buttons.length >= max) return
    if (kind !== 'QUICK_REPLY' && !value.trim()) return
    const b = kind === 'URL' ? { type: 'URL', text, url: value } : kind === 'PHONE_NUMBER' ? { type: 'PHONE_NUMBER', text, phone: value } : { type: 'QUICK_REPLY', text }
    onChange([...buttons, b]); setText(''); setValue('')
  }
  return (
    <div className="add-card" style={{ marginTop: 8 }}>
      <div className="radio-row" style={{ marginBottom: 12 }}>
        <label className={`radio ${kind === 'QUICK_REPLY' ? 'on' : ''}`} onClick={() => setKind('QUICK_REPLY')}><span className="rd" />Respuesta</label>
        <label className={`radio ${kind === 'URL' ? 'on' : ''}`} onClick={() => setKind('URL')}><span className="rd" /><Icon.link style={{ width: 14, height: 14, fill: 'currentColor' }} />URL</label>
        <label className={`radio ${kind === 'PHONE_NUMBER' ? 'on' : ''}`} onClick={() => setKind('PHONE_NUMBER')}><span className="rd" /><Icon.phone style={{ width: 14, height: 14, fill: 'currentColor' }} />Teléfono</label>
      </div>
      <div className="add-row">
        <input placeholder="Texto del botón…" maxLength={20} value={text} onChange={(e) => setText(e.target.value)} />
        {kind !== 'QUICK_REPLY' && <input placeholder={kind === 'URL' ? 'example.com' : '+34600000000'} value={value} onChange={(e) => setValue(e.target.value)} />}
        <button className="btn" disabled={!text.trim() || buttons.length >= max || (kind !== 'QUICK_REPLY' && !value.trim())} onClick={add}>Añadir</button>
      </div>
      {buttons.length > 0 && (
        <div className="btn-list">
          {buttons.map((b, i) => (
            <div className="btn-item" key={i}>
              <span className="pill gray">{b.type === 'URL' ? 'URL' : b.type === 'PHONE_NUMBER' ? 'Teléfono' : 'Respuesta'}</span>
              <b>{b.text}</b><span className="bv">{b.url || b.phone || ''}</span>
              <button className="icon-btn" style={{ color: 'var(--danger)', marginLeft: 'auto' }} onClick={() => onChange(buttons.filter((_, j) => j !== i))}><Icon.trash /></button>
            </div>
          ))}
        </div>
      )}
    </div>
  )
}

// ---- Preview de móvil ----
function PhoneShell({ children }) {
  return (
    <div className="phone">
      <div className="phone-top"><span>9:41</span><span>●●●</span></div>
      <div className="phone-head"><span className="pb">B</span><div><div className="pn">Business</div><div className="pst">en línea</div></div></div>
      <div className="phone-body"><div className="pday" />{children}</div>
      <div className="phone-input"><span>Escribe un mensaje</span><i><Icon.send /></i></div>
    </div>
  )
}

function Phone({ f }) {
  if (f.type === 'CAROUSEL') {
    return (
      <PhoneShell>
        <div className="pbubble"><div className="ph-body">{f.carouselBody || 'Mensaje del carrusel…'}</div><span className="ph-time">13:00</span></div>
        <div className="pcards">
          {f.cards.map((c, i) => (
            <div className="pcard" key={i}>
              <div className="pcard-img">{c.preview ? <img src={c.preview} alt="" /> : <Icon.image />}</div>
              <div className="pcard-body">{c.body || 'Texto de la tarjeta…'}</div>
              {c.buttons.map((b, j) => <div className="pcard-btn" key={j}>{b.type === 'URL' ? <Icon.link /> : b.type === 'PHONE_NUMBER' ? <Icon.phone /> : <Icon.message />}{b.text || 'Botón'}</div>)}
            </div>
          ))}
        </div>
      </PhoneShell>
    )
  }
  if (f.type === 'CATALOG') {
    return (
      <PhoneShell>
        <div className="pbubble">
          {f.catalog.thumbPreview && <div className="ph-media"><img src={f.catalog.thumbPreview} alt="" /></div>}
          <div className="ph-body">{f.catalog.body || 'Explora nuestro catálogo…'}</div>
          {f.catalog.footer && <div className="ph-foot">{f.catalog.footer}</div>}
          <span className="ph-time">13:00</span>
        </div>
        <div className="ph-btns"><div className="ph-btn"><Icon.catalog />Ver catálogo</div></div>
      </PhoneShell>
    )
  }
  // STANDARD
  const bv = varsIn(f.body.text)
  let bodyText = f.body.text || 'Tu mensaje aparecerá aquí…'
  for (let i = 1; i <= bv; i++) bodyText = bodyText.replaceAll(`{{${i}}}`, f.body.examples[i - 1] || `{{${i}}}`)
  const MIco = { IMAGE: Icon.image, VIDEO: Icon.video, DOCUMENT: Icon.file }[f.header.mediaFormat] || Icon.image
  return (
    <PhoneShell>
      <div className="pbubble">
        {f.header.format === 'TEXT' && f.header.text && <div className="ph-h">{f.header.text}</div>}
        {f.header.format === 'MEDIA' && <div className="ph-media">{f.header.preview && f.header.mediaFormat === 'IMAGE' ? <img src={f.header.preview} alt="" /> : <MIco />}</div>}
        <div className="ph-body">{bodyText}</div>
        {f.footer.text && <div className="ph-foot">{f.footer.text}</div>}
        <span className="ph-time">13:00</span>
      </div>
      {f.buttons.length > 0 && (
        <div className="ph-btns">{f.buttons.map((b, i) => <div className="ph-btn" key={i}>{b.type === 'URL' && <Icon.link />}{b.type === 'PHONE_NUMBER' && <Icon.phone />}{b.text || 'Botón'}</div>)}</div>
      )}
    </PhoneShell>
  )
}

export default function TemplateWizard({ onClose, onCreated, editing }) {
  const toast = useToast()
  const isEdit = !!editing
  const [f, setF] = useState(() => (editing ? fromTemplate(editing) : blank))
  const [step, setStep] = useState(0)
  const [submitting, setSubmitting] = useState(false)
  const [showPreview, setShowPreview] = useState(true)
  const [btnMode, setBtnMode] = useState(() => {
    const bs = editing ? ((editing.components || []).find((c) => c.type === 'BUTTONS')?.buttons || []) : []
    return bs.length ? (bs[0].type === 'QUICK_REPLY' ? 'QUICK_REPLY' : 'CTA') : 'NONE'
  })
  const [ctaKind, setCtaKind] = useState('URL')
  const [draft, setDraft] = useState({ text: '', value: '' })

  const STEPS = STEP_DEFS[f.type]
  const upd = (patch) => setF((s) => ({ ...s, ...patch }))
  const updH = (patch) => setF((s) => ({ ...s, header: { ...s.header, ...patch } }))
  const updB = (patch) => setF((s) => ({ ...s, body: { ...s.body, ...patch } }))
  const updCat = (patch) => setF((s) => ({ ...s, catalog: { ...s.catalog, ...patch } }))
  const setType = (t) => { setF((s) => ({ ...s, type: t, category: t === 'CATALOG' ? 'MARKETING' : s.category })); setStep(0) }

  // ---- tarjetas (carrusel) ----
  const updCard = (i, patch) => upd({ cards: f.cards.map((c, j) => (j === i ? { ...c, ...patch } : c)) })
  const addCard = () => f.cards.length < 10 && upd({ cards: [...f.cards, newCard()] })
  const delCard = (i) => f.cards.length > 2 && upd({ cards: f.cards.filter((_, j) => j !== i) })
  const onCardFile = async (i, e) => {
    const file = e.target.files?.[0]; if (!file) return
    updCard(i, { uploading: true, preview: URL.createObjectURL(file) })
    const res = await api.uploadMedia(file)
    updCard(i, { uploading: false, handle: res.ok ? res.handle : null })
    toast(res.ok ? 'Imagen subida' : (res.error || 'Error al subir'), res.ok ? 'ok' : 'err')
  }

  // ---- subida media (estándar / catálogo) ----
  const onFile = async (e, kind) => {
    const file = e.target.files?.[0]; if (!file) return
    const prev = file.type.startsWith('image') ? URL.createObjectURL(file) : null
    if (kind === 'header') updH({ uploading: true, preview: prev })
    else updCat({ thumbUploading: true, thumbPreview: prev })
    const res = await api.uploadMedia(file)
    if (kind === 'header') updH({ uploading: false, handle: res.ok ? res.handle : null })
    else updCat({ thumbUploading: false, thumbHandle: res.ok ? res.handle : null })
    toast(res.ok ? 'Archivo subido' : (res.error || 'Error al subir'), res.ok ? 'ok' : 'err')
  }

  // ---- botones estándar ----
  const setMode = (m) => { setBtnMode(m); upd({ buttons: [] }); setDraft({ text: '', value: '' }) }
  const addDraft = () => {
    const text = draft.text.trim(); const cap = btnMode === 'QUICK_REPLY' ? 3 : 2
    if (!text || f.buttons.length >= cap) return
    if (btnMode === 'QUICK_REPLY') upd({ buttons: [...f.buttons, { type: 'QUICK_REPLY', text }] })
    else { const v = draft.value.trim(); if (!v) return; upd({ buttons: [...f.buttons, ctaKind === 'URL' ? { type: 'URL', text, url: v } : { type: 'PHONE_NUMBER', text, phone: v }] }) }
    setDraft({ text: '', value: '' })
  }
  const delButton = (i) => upd({ buttons: f.buttons.filter((_, j) => j !== i) })

  const totalVars = useMemo(() => {
    const arr = []
    if (f.header.format === 'TEXT' && varsIn(f.header.text)) arr.push({ where: 'Encabezado', n: 1, key: 'h1' })
    const b = varsIn(f.body.text)
    for (let i = 1; i <= b; i++) arr.push({ where: 'Cuerpo', n: i, key: 'b' + i })
    return arr
  }, [f.header.format, f.header.text, f.body.text])
  const setExample = (idx, val) => { const ex = [...f.body.examples]; ex[idx] = val; updB({ examples: ex }) }

  // ---- validación ----
  const stepName = STEPS[step]
  const stepValid = () => {
    if (stepName === 'Información') return /^[a-z0-9_]+$/.test(f.name.trim())
    if (stepName === 'Encabezado') return f.header.format !== 'MEDIA' || !!f.header.handle
    if (stepName === 'Cuerpo') return f.body.text.trim().length > 0
    if (stepName === 'Variables') return totalVars.every((v, idx) => (f.body.examples[idx] || '').trim())
    if (stepName === 'Tarjetas') return f.carouselBody.trim() && f.cards.length >= 2 && f.cards.every((c) => c.handle && c.body.trim())
    if (stepName === 'Catálogo') return f.catalog.body.trim().length > 0
    return true
  }
  const next = () => { if (!stepValid()) { toast('Completa los campos requeridos', 'err'); return } setStep((s) => Math.min(s + 1, STEPS.length - 1)) }
  const back = () => (step === 0 ? onClose() : setStep((s) => s - 1))

  const submit = async () => {
    setSubmitting(true)
    let payload
    if (f.type === 'CAROUSEL') {
      payload = { template_type: 'CAROUSEL', name: f.name, language: f.language, category: f.category, carousel: { body: f.carouselBody, cards: f.cards.map((c) => ({ handle: c.handle, body: c.body, buttons: c.buttons })) } }
    } else if (f.type === 'CATALOG') {
      payload = { template_type: 'CATALOG', name: f.name, language: f.language, catalog: { thumb_handle: f.catalog.thumbHandle, body: f.catalog.body, footer: f.catalog.footer } }
    } else {
      const headerHasVar = f.header.format === 'TEXT' && varsIn(f.header.text)
      let headerExample = ''; let bodyExamples = [...f.body.examples]
      if (headerHasVar) { headerExample = bodyExamples[0] || ''; bodyExamples = bodyExamples.slice(1) }
      payload = {
        template_type: 'STANDARD', name: f.name, language: f.language, category: f.category,
        header: f.header.format === 'TEXT' ? { format: 'TEXT', text: f.header.text, example: headerExample }
          : f.header.format === 'MEDIA' ? { format: 'MEDIA', media_format: f.header.mediaFormat, media_handle: f.header.handle }
            : { format: 'NONE' },
        body: { text: f.body.text, examples: bodyExamples }, footer: { text: f.footer.text }, buttons: f.buttons,
      }
    }
    const res = isEdit ? await api.editTemplate(editing.id, payload) : await api.createTemplate(payload)
    setSubmitting(false)
    if (res.ok) { toast(isEdit ? 'Cambios guardados · vuelve a revisión' : 'Plantilla enviada a revisión'); onCreated() }
    else toast(res.error || (isEdit ? 'Error al editar' : 'Error al crear'), 'err')
  }

  return (
    <>
      <header className="page-head">
        <button className="icon-btn" onClick={onClose} title="Volver">‹</button>
        <h1>{isEdit ? 'Editar plantilla' : 'Crear plantilla de Meta'}</h1>
        <span className="sub">· {isEdit ? 'El nombre y el idioma no se pueden cambiar' : 'Construye y envía plantillas de WhatsApp'}</span>
        <div className="spacer" />
        <button className="btn ghost sm" onClick={() => setShowPreview((p) => !p)}>{showPreview ? 'Ocultar vista previa' : 'Mostrar vista previa'}</button>
      </header>

      <div className="wizard">
        <div className="wiz-main">
          <div className="stepper">
            {STEPS.map((s, i) => (
              <div className={`stp ${i === step ? 'cur' : ''} ${i < step ? 'done' : ''}`} key={s} onClick={() => i < step && setStep(i)}>
                <span className="stp-dot">{i < step ? '✓' : i + 1}</span><span className="stp-lbl">{s}</span>
              </div>
            ))}
          </div>

          <div className="wiz-body">
            {/* ---- Información (común) ---- */}
            {stepName === 'Información' && (
              <>
                <div className="lbl">Tipo de plantilla</div>
                <div className="type-grid">
                  {TYPES.map((t) => (
                    <div key={t.k} className={`type-card ${f.type === t.k ? 'sel' : ''} ${isEdit ? 'off' : ''}`} onClick={() => !isEdit && setType(t.k)}>
                      <span className="ti"><t.icon /></span><b>{t.t}</b><span>{t.d}</span>
                    </div>
                  ))}
                </div>
                <label className="field"><span className="lbl">Nombre de la plantilla {isEdit && <span className="hint">(no editable)</span>}</span>
                  <input value={f.name} disabled={isEdit} onChange={(e) => upd({ name: e.target.value.toLowerCase().replace(/[^a-z0-9_]/g, '_') })} placeholder="ej. confirmacion_pedido" />
                  {!isEdit && <span className="hint">Solo minúsculas, números y guiones bajos.</span>}
                </label>
                <div className="grid2">
                  <div className="field"><span className="lbl">Idioma {isEdit && <span className="hint">(no editable)</span>}</span>
                    <Select block disabled={isEdit} value={f.language} onChange={(v) => upd({ language: v })} options={LANGS.map(([v, t]) => ({ value: v, label: t }))} />
                  </div>
                  <div className="field"><span className="lbl">Categoría</span>
                    <Select block disabled={f.type === 'CATALOG'} value={f.category} onChange={(v) => upd({ category: v })} options={CATS.map(([v, t]) => ({ value: v, label: t }))} />
                    {f.type === 'CATALOG' && <span className="hint">Los catálogos usan la categoría Marketing.</span>}
                  </div>
                </div>
              </>
            )}

            {/* ---- Encabezado (standard) ---- */}
            {stepName === 'Encabezado' && (
              <>
                <div className="lbl">Formato del encabezado</div>
                <div className="radio-row">
                  {[['NONE', 'Ninguno'], ['TEXT', 'Texto'], ['MEDIA', 'Media']].map(([v, t]) => (
                    <label key={v} className={`radio ${f.header.format === v ? 'on' : ''}`} onClick={() => updH({ format: v })}><span className="rd" />{t}</label>
                  ))}
                </div>
                {f.header.format === 'TEXT' && (
                  <label className="field" style={{ marginTop: 18 }}><span className="lbl">Texto del encabezado</span>
                    <input maxLength={60} value={f.header.text} onChange={(e) => updH({ text: e.target.value })} placeholder="Escribe el encabezado…" />
                    <span className="hint">{f.header.text.length}/60 · puedes usar {'{{1}}'}</span>
                  </label>
                )}
                {f.header.format === 'MEDIA' && (
                  <>
                    <div className="lbl" style={{ marginTop: 18 }}>Tipo de media</div>
                    <div className="radio-row">
                      {[['IMAGE', 'Imagen', Icon.image], ['VIDEO', 'Vídeo', Icon.video], ['DOCUMENT', 'Documento', Icon.file]].map(([v, t, I]) => (
                        <label key={v} className={`radio ${f.header.mediaFormat === v ? 'on' : ''}`} onClick={() => updH({ mediaFormat: v, handle: null, preview: null })}><span className="rd" /><I style={{ width: 16, height: 16, fill: 'currentColor', marginRight: 4 }} />{t}</label>
                      ))}
                    </div>
                    <div className="upload-zone" style={{ marginTop: 16 }}>
                      <input type="file" id="mediafile" hidden onChange={(e) => onFile(e, 'header')} accept={f.header.mediaFormat === 'IMAGE' ? 'image/*' : f.header.mediaFormat === 'VIDEO' ? 'video/mp4' : 'application/pdf'} />
                      <label htmlFor="mediafile" className="btn ghost"><Icon.upload /> {f.header.uploading ? 'Subiendo…' : f.header.handle ? 'Cambiar archivo' : 'Subir archivo'}</label>
                      {f.header.handle && <span className="ok-tag">✓ Subido</span>}
                    </div>
                  </>
                )}
                <div className="guide"><Icon.info /><div><b>Recomendaciones</b><ul><li>Texto: máximo 60 caracteres.</li><li>Imágenes claras y de buena calidad.</li><li>Vídeo MP4 (máx 16MB) · PDF para documentos.</li></ul></div></div>
              </>
            )}

            {/* ---- Cuerpo (standard) ---- */}
            {stepName === 'Cuerpo' && (
              <>
                <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
                  <span className="lbl">Cuerpo del mensaje <span style={{ color: 'var(--danger)' }}>*</span></span>
                  <button className="link-btn" onClick={() => updB({ text: f.body.text + `{{${varsIn(f.body.text) + 1}}}` })}><Icon.plus /> Añadir variable</button>
                </div>
                <textarea className="big-area" maxLength={1024} value={f.body.text} onChange={(e) => updB({ text: e.target.value })} placeholder="Tu mensaje aparecerá aquí…" />
                <span className="hint">{f.body.text.length}/1024</span>
                <label className="field" style={{ marginTop: 16 }}><span className="lbl">Pie de página <span className="hint">(opcional)</span></span>
                  <input maxLength={60} value={f.footer.text} onChange={(e) => upd({ footer: { text: e.target.value } })} placeholder="ej. Responde BAJA para no recibir más mensajes" />
                  <span className="hint">{f.footer.text.length}/60</span>
                </label>
                <div className="optout-row">
                  <label className="optout-toggle">
                    <span className="optout-check"><input type="checkbox" checked={f.footer.text.trim() === OPTOUT_FOOTER} onChange={(e) => upd({ footer: { text: e.target.checked ? OPTOUT_FOOTER : '' } })} /><span className="optout-box" /></span>
                    <span className="optout-label">Añadir aviso de baja en el pie</span>
                  </label>
                  <span className="help-tip" tabIndex={0}>?
                    <span className="help-bubble">Al marcarlo, el pie del mensaje pasa a ser <b>«{OPTOUT_FOOTER}»</b>. Así, cuando envíes esta plantilla en una campaña, el cliente verá cómo darse de baja; si responde <b>BAJA</b>, queda excluido automáticamente de tus difusiones. Recomendado en plantillas de <b>Marketing</b>.</span>
                  </span>
                </div>
              </>
            )}

            {/* ---- Botones (standard) ---- */}
            {stepName === 'Botones' && (
              <>
                <div className="lbl">Tipo de botón</div>
                <div className="radio-row">
                  {[['NONE', 'Ninguno'], ['QUICK_REPLY', 'Respuesta rápida'], ['CTA', 'Llamada a la acción']].map(([v, t]) => (
                    <label key={v} className={`radio ${btnMode === v ? 'on' : ''}`} onClick={() => setMode(v)}><span className="rd" />{t}</label>
                  ))}
                </div>
                {btnMode === 'QUICK_REPLY' && (
                  <><div className="lbl" style={{ marginTop: 20 }}>Botones de respuesta rápida <span className="hint">(máx 3)</span></div>
                    <div className="add-card"><div className="add-row">
                      <input placeholder="Texto del botón…" maxLength={20} value={draft.text} onChange={(e) => setDraft({ ...draft, text: e.target.value })} onKeyDown={(e) => e.key === 'Enter' && addDraft()} />
                      <button className="btn" disabled={!draft.text.trim() || f.buttons.length >= 3} onClick={addDraft}>Añadir</button>
                    </div><span className="hint">{draft.text.length}/20</span></div></>
                )}
                {btnMode === 'CTA' && (
                  <><div className="lbl" style={{ marginTop: 20 }}>Botones de llamada a la acción <span className="hint">(máx 2)</span></div>
                    <div className="add-card">
                      <div className="radio-row" style={{ marginBottom: 14 }}>
                        <label className={`radio ${ctaKind === 'URL' ? 'on' : ''}`} onClick={() => setCtaKind('URL')}><span className="rd" /><Icon.link style={{ width: 15, height: 15, fill: 'currentColor' }} /> Visitar web</label>
                        <label className={`radio ${ctaKind === 'PHONE_NUMBER' ? 'on' : ''}`} onClick={() => setCtaKind('PHONE_NUMBER')}><span className="rd" /><Icon.phone style={{ width: 15, height: 15, fill: 'currentColor' }} /> Llamar</label>
                      </div>
                      <div className="add-row">
                        <input placeholder="Texto del botón…" maxLength={20} value={draft.text} onChange={(e) => setDraft({ ...draft, text: e.target.value })} />
                        <input placeholder={ctaKind === 'URL' ? 'example.com' : '+34600000000'} value={draft.value} onChange={(e) => setDraft({ ...draft, value: e.target.value })} onKeyDown={(e) => e.key === 'Enter' && addDraft()} />
                        <button className="btn" disabled={!draft.text.trim() || !draft.value.trim() || f.buttons.length >= 2} onClick={addDraft}>Añadir</button>
                      </div><span className="hint">{draft.text.length}/20</span>
                    </div></>
                )}
                {btnMode !== 'NONE' && f.buttons.length > 0 && (
                  <div className="btn-list">{f.buttons.map((b, i) => (
                    <div className="btn-item" key={i}><span className="pill gray">{b.type === 'URL' ? 'URL' : b.type === 'PHONE_NUMBER' ? 'Teléfono' : 'Respuesta'}</span><b>{b.text}</b><span className="bv">{b.url || b.phone || ''}</span><button className="icon-btn" onClick={() => delButton(i)} style={{ color: 'var(--danger)', marginLeft: 'auto' }}><Icon.trash /></button></div>
                  ))}</div>
                )}
                <div className="guide"><Icon.info /><div><b>Recomendaciones</b><ul><li>Máximo 3 botones de respuesta rápida.</li><li>Máximo 2 botones de llamada a la acción.</li><li>Texto del botón: máximo 20 caracteres.</li></ul></div></div>
              </>
            )}

            {/* ---- Variables (standard) ---- */}
            {stepName === 'Variables' && (
              <>
                <div className="lbl">Ejemplos de variables</div>
                {totalVars.length === 0 ? (
                  <div className="guide"><Icon.info /><div>No se detectaron variables. Añade {'{{1}}'}, {'{{2}}'} etc. en el paso <b>Cuerpo</b>.</div></div>
                ) : (
                  <>
                    <p className="hint" style={{ marginBottom: 14 }}>Meta exige un ejemplo para cada variable usada.</p>
                    {totalVars.map((v, idx) => (
                      <label className="field" key={v.key}><span className="lbl">{v.where} · {`{{${v.n}}}`}</span>
                        <input value={f.body.examples[idx] || ''} onChange={(e) => setExample(idx, e.target.value)} placeholder="Ejemplo…" />
                      </label>
                    ))}
                  </>
                )}
              </>
            )}

            {/* ---- Tarjetas (carrusel) ---- */}
            {stepName === 'Tarjetas' && (
              <>
                <label className="field"><span className="lbl">Mensaje (encima de las tarjetas) <span style={{ color: 'var(--danger)' }}>*</span></span>
                  <textarea className="big-area" style={{ minHeight: 90 }} maxLength={1024} value={f.carouselBody} onChange={(e) => upd({ carouselBody: e.target.value })} placeholder="ej. ¡Mira nuestros últimos productos!" />
                  <span className="hint">{f.carouselBody.length}/1024</span>
                </label>
                <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', margin: '20px 0 12px' }}>
                  <span className="lbl" style={{ margin: 0 }}>🛒 Tarjetas <span className="hint">({f.cards.length}/10 · mín 2)</span></span>
                  <button className="btn ghost sm" disabled={f.cards.length >= 10} onClick={addCard}><Icon.plus /> Añadir tarjeta</button>
                </div>
                {f.cards.map((c, i) => (
                  <div className="cardbox" key={i}>
                    <div className="cardbox-head"><b>Tarjeta {i + 1}</b>{f.cards.length > 2 && <button className="icon-btn" style={{ color: 'var(--danger)' }} onClick={() => delCard(i)}><Icon.trash /></button>}</div>
                    <div style={{ padding: 16 }}>
                      <div className="lbl">Imagen de la tarjeta <span style={{ color: 'var(--danger)' }}>*</span></div>
                      <div className="upload-zone">
                        <input type="file" id={`card${i}`} hidden accept="image/png,image/jpeg" onChange={(e) => onCardFile(i, e)} />
                        <label htmlFor={`card${i}`} className="btn ghost"><Icon.upload /> {c.uploading ? 'Subiendo…' : c.handle ? 'Cambiar imagen' : 'Subir imagen'}</label>
                        {c.handle && <span className="ok-tag">✓ Subida</span>}
                      </div>
                      <label className="field" style={{ marginTop: 14 }}><span className="lbl">Texto de la tarjeta <span style={{ color: 'var(--danger)' }}>*</span></span>
                        <textarea maxLength={160} value={c.body} onChange={(e) => updCard(i, { body: e.target.value })} placeholder="Describe esta tarjeta…" style={{ minHeight: 70 }} />
                        <span className="hint">{c.body.length}/160</span>
                      </label>
                      <div className="lbl" style={{ marginTop: 6 }}>Botones <span className="hint">(máx 2)</span></div>
                      <ButtonAdder buttons={c.buttons} max={2} onChange={(bs) => updCard(i, { buttons: bs })} />
                    </div>
                  </div>
                ))}
                <div className="guide"><Icon.info /><div><b>Recomendaciones del carrusel</b><ul><li>Mínimo 2 tarjetas, máximo 10.</li><li>Cada tarjeta necesita una imagen JPEG/PNG (máx 5MB).</li><li>Texto de tarjeta máximo 160 caracteres.</li><li>Todas las tarjetas deben tener los mismos tipos de botón.</li></ul></div></div>
              </>
            )}

            {/* ---- Catálogo ---- */}
            {stepName === 'Catálogo' && (
              <>
                <div className="guide" style={{ marginTop: 0, background: 'var(--primary-soft)', borderColor: 'rgba(0,168,132,.3)' }}><Icon.catalog style={{ fill: 'var(--primary)' }} /><div><b style={{ color: 'var(--primary)' }}>Plantilla de catálogo</b><div style={{ marginTop: 4 }}>Permite a los clientes ver tu catálogo en WhatsApp. El botón «Ver catálogo» se añade automáticamente.</div></div></div>
                <div className="lbl" style={{ marginTop: 18 }}>Miniatura del catálogo <span className="hint">(opcional)</span></div>
                <div className="upload-zone">
                  <input type="file" id="thumb" hidden accept="image/*" onChange={(e) => onFile(e, 'thumb')} />
                  <label htmlFor="thumb" className="btn ghost"><Icon.upload /> {f.catalog.thumbUploading ? 'Subiendo…' : f.catalog.thumbHandle ? 'Cambiar miniatura' : 'Subir miniatura'}</label>
                  {f.catalog.thumbHandle && <span className="ok-tag">✓ Subida</span>}
                </div>
                <label className="field" style={{ marginTop: 18 }}><span className="lbl">Cuerpo del mensaje <span style={{ color: 'var(--danger)' }}>*</span></span>
                  <textarea className="big-area" style={{ minHeight: 110 }} maxLength={1024} value={f.catalog.body} onChange={(e) => updCat({ body: e.target.value })} placeholder="ej. Explora nuestra colección y compra directamente en WhatsApp." />
                  <span className="hint">{f.catalog.body.length}/1024</span>
                </label>
                <label className="field"><span className="lbl">Pie de página <span className="hint">(opcional)</span></span>
                  <input maxLength={60} value={f.catalog.footer} onChange={(e) => updCat({ footer: e.target.value })} placeholder="ej. Responde BAJA para no recibir más mensajes" />
                </label>
                <div className="guide"><Icon.info /><div><b>Recomendaciones del catálogo</b><ul><li>Tu cuenta de WhatsApp Business debe tener un catálogo vinculado.</li><li>El botón «Ver catálogo» lo añade Meta automáticamente.</li><li>La miniatura es opcional pero mejora la interacción.</li><li>Los catálogos usan la categoría MARKETING.</li></ul></div></div>
              </>
            )}

            {/* ---- Revisar (común) ---- */}
            {stepName === 'Revisar' && (
              <>
                <div className="guide" style={{ marginTop: 0 }}><Icon.info /><div><b>Antes de enviar</b><ul><li>Revisa todos los detalles de la plantilla.</li><li>Asegúrate de que cada variable tiene un ejemplo realista.</li><li>Comprueba que el contenido cumple las políticas de Meta.</li></ul></div></div>
                <div className="rev-card"><div className="rev-head">Detalles de la plantilla</div><div className="rev-body">
                  <div className="rv"><span>Nombre</span><b>{f.name || '—'}</b></div>
                  <div className="rv"><span>Idioma</span><b>{LANGS.find((l) => l[0] === f.language)?.[1]}</b></div>
                  <div className="rv"><span>Categoría</span><b>{f.type === 'CATALOG' ? 'Marketing' : CATS.find((c) => c[0] === f.category)?.[1]}</b></div>
                  <div className="rv"><span>Tipo</span><b>{TYPES.find((t) => t.k === f.type)?.t}</b></div>
                </div></div>
                <div className="rev-card"><div className="rev-head">Contenido</div><div className="rev-body">
                  {f.type === 'CAROUSEL' && <>
                    <div className="rev-block"><span className="rev-lbl">Mensaje</span><div>{f.carouselBody || '—'}</div></div>
                    <div className="rev-block"><span className="rev-lbl">Tarjetas</span><div>{f.cards.length} tarjetas</div></div>
                  </>}
                  {f.type === 'CATALOG' && <>
                    <div className="rev-block"><span className="rev-lbl">Cuerpo del mensaje *</span><div>{f.catalog.body || '—'}</div></div>
                    {f.catalog.footer && <div className="rev-block"><span className="rev-lbl">Pie</span><div>{f.catalog.footer}</div></div>}
                    <div className="rev-block"><span className="rev-lbl">Botón</span><div>Ver catálogo (automático)</div></div>
                  </>}
                  {f.type === 'STANDARD' && <>
                    {f.header.format === 'TEXT' && f.header.text && <div className="rev-block"><span className="rev-lbl">Encabezado</span><div>{f.header.text}</div></div>}
                    {f.header.format === 'MEDIA' && <div className="rev-block"><span className="rev-lbl">Encabezado</span><div>Media · {f.header.mediaFormat}</div></div>}
                    <div className="rev-block"><span className="rev-lbl">Cuerpo del mensaje *</span><div>{f.body.text || '—'}</div></div>
                    {f.footer.text && <div className="rev-block"><span className="rev-lbl">Pie de página</span><div>{f.footer.text}</div></div>}
                    {f.buttons.length > 0 && <div className="rev-block"><span className="rev-lbl">Botones</span><div>{f.buttons.map((b, i) => <span key={i} className="pill gray" style={{ marginRight: 6 }}>{b.text}</span>)}</div></div>}
                  </>}
                </div></div>
              </>
            )}
          </div>

          <div className="wiz-foot">
            <button className="btn ghost" onClick={back}>‹ {step === 0 ? 'Cancelar' : 'Atrás'}</button>
            <div className="spacer" style={{ flex: 1 }} />
            {step < STEPS.length - 1
              ? <button className="btn" onClick={next}>Siguiente ›</button>
              : <button className="btn" disabled={submitting} onClick={submit}>{submitting ? 'Guardando…' : isEdit ? 'Guardar cambios' : 'Enviar a aprobación'}</button>}
          </div>
        </div>

        {showPreview && <div className="wiz-preview"><Phone f={f} /></div>}
      </div>
    </>
  )
}
