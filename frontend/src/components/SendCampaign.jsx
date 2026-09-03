import { useState, useEffect, useMemo, useRef } from 'react'
import { api } from '../api.js'
import { Icon } from '../icons.jsx'
import { useToast } from '../App.jsx'
import Select from './Select.jsx'
import RichInput from './RichInput.jsx'

// Contacto de ejemplo para la vista previa de personalización
const SAMPLE = { name: 'María García', wa_id: '34600112233' }
const SOURCE_OPTS = [
  { value: 'fixed', label: 'Valor fijo' },
  { value: 'name', label: 'Nombre del contacto' },
  { value: 'phone', label: 'Teléfono del contacto' },
]
const langName = (c) => ({ es: 'Español', es_ES: 'Español', en: 'Inglés', en_US: 'Inglés (US)', pt_BR: 'Portugués' }[c] || c)
const catColor = (c) => ({ MARKETING: 'var(--warn)', UTILITY: 'var(--primary)', AUTHENTICATION: 'var(--info)' }[c] || 'var(--secondary)')
const ROW = { display: 'flex', justifyContent: 'space-between', alignItems: 'baseline', fontSize: 14, gap: 12 }
const eur = (n, d = 2) => '€' + Number(n).toFixed(d).replace('.', ',')

// Extrae los textos de body/header de los componentes de Meta
function templateText(t) {
  const body = (t.components || []).find((c) => c.type === 'BODY')
  return body?.text || ''
}
// Cuenta variables {{n}} en un texto
function varCount(text) {
  const m = [...(text || '').matchAll(/\{\{(\d+)\}\}/g)].map((x) => parseInt(x[1], 10))
  return m.length ? Math.max(...m) : 0
}

export default function SendCampaign({ onDone }) {
  const toast = useToast()
  const [channel, setChannel] = useState('whatsapp')   // 'whatsapp' | 'email'
  const [step, setStep] = useState(1)
  const [gate, setGate] = useState(null)
  useEffect(() => { api.gating().then((d) => setGate(d?.ok ? d : null)) }, [])
  const waLocked = gate?.features?.wa_campaign

  // --- Campaña por CORREO (canal 'email') ---
  const [emailSubject, setEmailSubject] = useState('')
  const emailBody = useRef(null)          // RichInput
  const [emailLabelId, setEmailLabelId] = useState('')
  const [emailTitle, setEmailTitle] = useState('')
  const [emailImmediate, setEmailImmediate] = useState(true)
  const [emailWhen, setEmailWhen] = useState('')
  const [emailSending, setEmailSending] = useState(false)
  const [emailCfg, setEmailCfg] = useState(null)   // { ready } del remitente de campañas
  useEffect(() => { api.getEmailAccount('campanas').then((d) => setEmailCfg({ ready: !!(d.account && d.account.active && d.account.smtp_host) })) }, [])

  const sendEmail = async () => {
    if (!emailTitle.trim()) { toast('Ponle un título a la campaña', 'err'); return }
    if (!emailSubject.trim()) { toast('Ponle un asunto al correo', 'err'); return }
    const body = emailBody.current?.getHtml() || ''
    if (!body.replace(/<[^>]*>/g, '').replace(/&nbsp;/g, ' ').trim()) { toast('Escribe el cuerpo del correo', 'err'); return }
    if (!emailLabelId) { toast('Elige una etiqueta de destino', 'err'); return }
    if (!emailImmediate && !emailWhen) { toast('Indica la fecha y hora de envío', 'err'); return }
    setEmailSending(true)
    const r = await api.createCampaign({
      channel: 'email', title: emailTitle, subject: emailSubject, body_html: body,
      label_id: Number(emailLabelId),
      schedule: emailImmediate ? { mode: 'now' } : { mode: 'later', at: emailWhen },
    })
    setEmailSending(false)
    if (!r.ok) { toast(r.error || 'No se pudo crear la campaña', 'err'); return }
    const exc = r.excluded ? ` · ${r.excluded} excluido${r.excluded > 1 ? 's' : ''} por baja` : ''
    if (r.immediate) toast(`Campaña lanzada · ${r.stats?.sent ?? 0} enviados${r.stats?.failed ? `, ${r.stats.failed} fallidos` : ''}${r.stats?.pending ? `, ${r.stats.pending} en cola` : ''}${exc}`)
    else toast(`Campaña programada${exc}`)
    onDone?.()
  }
  const [templates, setTemplates] = useState(null)
  const [q, setQ] = useState('')
  const [filter, setFilter] = useState('APPROVED')
  const [picked, setPicked] = useState(null)

  // Paso 2
  const [phonebooks, setPhonebooks] = useState([])
  const [labels, setLabels] = useState([])
  const [destType, setDestType] = useState('label') // 'label' | 'phonebook'
  const [title, setTitle] = useState('')
  const [pbId, setPbId] = useState('')
  const [labelId, setLabelId] = useState('')
  const [vars, setVars] = useState({ header: [], body: [] })
  const [immediate, setImmediate] = useState(true)
  const [when, setWhen] = useState('')
  const [sending, setSending] = useState(false)
  const [estimate, setEstimate] = useState(null)   // { recipients, excluded, rate, cost } | { loading }

  // Estimación de coste al elegir plantilla + destino (destinatarios × tarifa categoría)
  useEffect(() => {
    if (step !== 2 || !picked) { setEstimate(null); return }
    const dest = destType === 'label' ? { label_id: Number(labelId) } : { phonebook_id: Number(pbId) }
    if (!dest.label_id && !dest.phonebook_id) { setEstimate(null); return }
    let alive = true
    setEstimate({ loading: true })
    api.estimateCampaign({ category: picked.category, ...dest })
      .then((d) => { if (alive) setEstimate(d?.ok ? d : null) })
      .catch(() => { if (alive) setEstimate(null) })
    return () => { alive = false }
  }, [step, picked, destType, labelId, pbId])

  const loadTemplates = () => {
    setTemplates(null)
    api.listTemplates().then((d) => setTemplates(d.ok ? (d.templates || []) : []))
  }
  useEffect(() => {
    loadTemplates()
    api.listPhonebooks().then((d) => setPhonebooks(d.phonebooks || []))
    api.listLabels().then((d) => setLabels(d.labels || []))
  }, [])

  const list = useMemo(() => {
    let r = templates || []
    if (filter !== 'ALL') r = r.filter((t) => (t.status || '').toUpperCase() === filter)
    if (q.trim()) r = r.filter((t) => t.name.toLowerCase().includes(q.toLowerCase()))
    return r
  }, [templates, q, filter])

  const headerComp = picked && (picked.components || []).find((c) => c.type === 'HEADER' && c.format === 'TEXT')
  const bodyVars = picked ? varCount(templateText(picked)) : 0
  const headerVars = headerComp ? varCount(headerComp.text) : 0

  const use = (t) => {
    setPicked(t)
    const hN = varCount((t.components || []).find((c) => c.type === 'HEADER' && c.format === 'TEXT')?.text)
    const bN = varCount(templateText(t))
    setVars({
      header: Array.from({ length: hN }, () => ({ source: 'fixed', value: '' })),
      // Por defecto, la primera variable del cuerpo = nombre del contacto (con respaldo)
      body: Array.from({ length: bN }, (_, i) => (i === 0 ? { source: 'name', value: 'cliente' } : { source: 'fixed', value: '' })),
    })
    setTitle(t.name.replace(/_/g, ' '))
    setStep(2)
  }

  // Cómo quedaría una variable resuelta con el contacto de ejemplo
  const exampleValue = (v) => {
    if (!v) return ''
    if (v.source === 'name') return SAMPLE.name || v.value || 'cliente'
    if (v.source === 'phone') return '+' + SAMPLE.wa_id
    return v.value || '⟨vacío⟩'
  }
  const renderExample = (text, scope) => (text || '').replace(/\{\{(\d+)\}\}/g, (_, n) => exampleValue(vars[scope][parseInt(n, 10) - 1]))

  const buildComponents = () => {
    const comps = []
    if (headerVars > 0) comps.push({ type: 'header', parameters: vars.header.map((v) => ({ source: v.source, value: v.value })) })
    if (bodyVars > 0) comps.push({ type: 'body', parameters: vars.body.map((v) => ({ source: v.source, value: v.value })) })
    return comps
  }

  const send = async () => {
    if (!title.trim()) { toast('Ponle un título a la campaña', 'err'); return }
    if (destType === 'phonebook' && !pbId) { toast('Elige una agenda de contactos', 'err'); return }
    if (destType === 'label' && !labelId) { toast('Elige una etiqueta de destino', 'err'); return }
    const missingFixed = (arr) => arr.some((v) => v.source === 'fixed' && !v.value.trim())
    if (headerVars > 0 && missingFixed(vars.header)) { toast('Rellena las variables de valor fijo de la cabecera', 'err'); return }
    if (bodyVars > 0 && missingFixed(vars.body)) { toast('Rellena las variables de valor fijo del mensaje', 'err'); return }
    if (!immediate && !when) { toast('Indica la fecha y hora de envío', 'err'); return }
    setSending(true)
    const r = await api.createCampaign({
      title, template_name: picked.name, language: picked.language || 'es',
      category: picked.category,
      components: buildComponents(),
      phonebook_id: destType === 'phonebook' ? Number(pbId) : 0,
      label_id: destType === 'label' ? Number(labelId) : 0,
      schedule: immediate ? { mode: 'now' } : { mode: 'later', at: when },
    })
    setSending(false)
    if (!r.ok) { toast(r.error || 'No se pudo crear la campaña', 'err'); return }
    const exc = r.excluded ? ` · ${r.excluded} excluido${r.excluded > 1 ? 's' : ''} por baja` : ''
    if (r.immediate) toast(`Campaña lanzada · ${r.stats?.sent ?? 0} enviados${r.stats?.failed ? `, ${r.stats.failed} fallidos` : ''}${r.stats?.pending ? `, ${r.stats.pending} en cola` : ''}${exc}`)
    else toast(`Campaña programada${exc}`)
    onDone?.()
  }

  return (
    <>
      <header className="page-head">
        <span className="ic" style={{ width: 30, height: 30, borderRadius: 9, background: 'var(--primary-soft)', display: 'grid', placeItems: 'center' }}><Icon.send style={{ width: 17, height: 17, fill: 'var(--primary)' }} /></span>
        <div><h1>Enviar campaña</h1></div>
        <span className="sub">· {channel === 'email' ? 'Difusión por correo electrónico' : 'Difusión masiva con plantillas de Meta'}</span>
        <div className="spacer" />
      </header>

      <div className="page-scroll">
        <div className="page" style={{ maxWidth: 1080 }}>
          {/* Selector de canal */}
          <div className="field" style={{ marginBottom: 16 }}>
            <span className="lbl">Canal de la campaña</span>
            <div className="seg" style={{ maxWidth: 420 }}>
              <button type="button" className={channel === 'whatsapp' ? 'on' : ''} onClick={() => setChannel('whatsapp')}><Icon.whatsapp /> WhatsApp</button>
              <button type="button" className={channel === 'email' ? 'on' : ''} onClick={() => setChannel('email')}><Icon.mail /> Correo</button>
            </div>
          </div>

          {channel === 'email' && (
            <>
              {emailCfg && !emailCfg.ready && (
                <div className="card" style={{ borderColor: 'var(--warn)', background: 'color-mix(in srgb, var(--warn) 8%, transparent)' }}>
                  <p className="desc" style={{ margin: 0 }}>
                    <b>Aún no hay un remitente de campañas por correo.</b> Configúralo en <b>Ajustes → Correo de campañas</b> antes de enviar.
                    Es un correo <b>distinto</b> del de soporte, para que las respuestas no se conviertan en tickets.
                  </p>
                </div>
              )}
              <div className="card" style={{ padding: 18 }}>
                <div className="fb-set-t" style={{ marginBottom: 14 }}>Redactar el correo</div>

                <label className="field"><span className="lbl">Título de la campaña <span className="hint">(interno, para identificarla)</span></span>
                  <input value={emailTitle} onChange={(e) => setEmailTitle(e.target.value)} placeholder="p. ej. Novedades septiembre" /></label>

                <label className="field" style={{ marginTop: 14 }}><span className="lbl">Asunto del correo</span>
                  <input value={emailSubject} onChange={(e) => setEmailSubject(e.target.value)} placeholder="Lo que verá el destinatario en su bandeja" /></label>

                <div className="field" style={{ marginTop: 14 }}>
                  <span className="lbl">Cuerpo del correo</span>
                  <RichInput ref={emailBody} minHeight={200} placeholder="Escribe el mensaje de la campaña…" />
                  <span className="hint" style={{ marginTop: 6 }}>Se añade automáticamente un pie con el enlace de baja (obligatorio en comunicaciones comerciales).</span>
                </div>

                <div className="field" style={{ marginTop: 16 }}>
                  <span className="lbl">Destino</span>
                  <div style={{ marginTop: 4 }}>
                    <Select block value={emailLabelId} onChange={setEmailLabelId} placeholder="Selecciona una etiqueta…"
                      options={labels.map((l) => ({ value: l.id, label: l.name, color: l.color }))} />
                  </div>
                  <span className="hint" style={{ marginTop: 6 }}>Se enviará a los contactos con esta etiqueta que <b>tengan correo</b> y no estén dados de baja.{labels.length === 0 && ' No tienes etiquetas: créalas en Contactos.'}</span>
                </div>

                <div style={{ marginTop: 16 }}>
                  <span className="lbl">Programación</span>
                  <label className="fb-req-row" style={{ marginTop: 8 }}>
                    <span className="fb-switch"><input type="checkbox" checked={emailImmediate} onChange={(e) => setEmailImmediate(e.target.checked)} /><span className={`fb-toggle ${emailImmediate ? 'on' : ''}`} /></span>
                    <span className="fb-req-label">Enviar inmediatamente</span>
                  </label>
                  {!emailImmediate && <input className="cmp-var" type="datetime-local" value={emailWhen} onChange={(e) => setEmailWhen(e.target.value)} style={{ marginTop: 10, maxWidth: 280 }} />}
                </div>

                <div className="fb-actions">
                  <button className="btn" disabled={emailSending || (emailCfg && !emailCfg.ready)} onClick={sendEmail}>
                    <Icon.send /> {emailSending ? 'Enviando…' : (emailImmediate ? 'Enviar campaña' : 'Programar campaña')}
                  </button>
                </div>
              </div>
            </>
          )}

          {channel === 'whatsapp' && (<>
          {/* Stepper */}
          <div className="cmp-steps">
            <div className={`cmp-step ${step >= 1 ? 'done' : ''}`}><span className="n">{step > 1 ? <Icon.check /> : '1'}</span> Elegir plantilla</div>
            <div className="cmp-line" />
            <div className={`cmp-step ${step >= 2 ? 'on' : ''}`}><span className="n">2</span> Configurar y enviar</div>
          </div>

          {step === 1 && (
            <>
              <div className="cmp-toolbar">
                <div className="search-box" style={{ flex: 1 }}><Icon.search /><input placeholder="Buscar plantillas…" value={q} onChange={(e) => setQ(e.target.value)} /></div>
                <Select value={filter} onChange={setFilter} options={[
                  { value: 'APPROVED', label: 'Aprobadas' },
                  { value: 'PENDING', label: 'Pendientes' },
                  { value: 'REJECTED', label: 'Rechazadas' },
                  { value: 'ALL', label: 'Todas' },
                ]} />
                <button className="btn ghost" onClick={loadTemplates}><Icon.refresh /> Refrescar</button>
              </div>

              {templates === null ? <div className="center-load"><div className="spinner" /></div> :
                list.length === 0 ? (
                  <div className="empty"><div className="ico"><Icon.templates /></div><p>No hay plantillas {filter === 'APPROVED' ? 'aprobadas' : ''}. Créalas en <b>Plantillas</b>.</p></div>
                ) : (
                  <div className="cmp-tpl-list">
                    {list.map((t) => (
                      <div key={t.id || t.name} className="cmp-tpl">
                        <div className="cmp-tpl-head">
                          <b>{t.name}</b>
                          <span className={`pill sm ${(t.status || '').toUpperCase() === 'APPROVED' ? 'ok' : 'gray'}`}>{t.status}</span>
                        </div>
                        <div className="cmp-tpl-tags">
                          <span className="pill gray sm" style={{ color: catColor(t.category) }}>{t.category}</span>
                          <span className="pill gray sm">{langName(t.language)}</span>
                        </div>
                        <p className="cmp-tpl-body">{templateText(t) || <i className="muted">Sin cuerpo de texto</i>}</p>
                        <button className="btn cmp-use" onClick={() => use(t)}>Usar plantilla</button>
                      </div>
                    ))}
                  </div>
                )}
            </>
          )}

          {step === 2 && picked && (
            <>
              <button className="btn ghost sm" onClick={() => setStep(1)} style={{ marginBottom: 14 }}><Icon.send style={{ transform: 'rotate(180deg)' }} /> Volver</button>

              <div className="card" style={{ padding: 0, marginBottom: 16 }}>
                <div className="fb-set-t" style={{ padding: '14px 16px 0' }}>Plantilla</div>
                <div style={{ padding: '6px 16px 16px' }}>
                  <div className="cmp-tpl-head"><b>{picked.name}</b><span className="pill ok sm">{picked.status}</span><span className="pill gray sm" style={{ color: catColor(picked.category) }}>{picked.category}</span><span className="pill gray sm">{langName(picked.language)}</span></div>
                  <p className="cmp-tpl-body" style={{ marginTop: 8 }}>{templateText(picked)}</p>
                </div>
              </div>

              <div className="card" style={{ padding: 18 }}>
                <div className="fb-set-t" style={{ marginBottom: 14 }}>Ajustes de la campaña</div>

                <label className="field"><span className="lbl">Título de la campaña</span><input value={title} onChange={(e) => setTitle(e.target.value)} placeholder="p. ej. Promo verano 2026" /></label>

                <div className="field" style={{ marginTop: 14 }}>
                  <span className="lbl">Destino</span>
                  <div className="seg">
                    <button type="button" className={destType === 'label' ? 'on' : ''} onClick={() => setDestType('label')}><Icon.tag /> Etiqueta / sector</button>
                    <button type="button" className={destType === 'phonebook' ? 'on' : ''} onClick={() => setDestType('phonebook')}><Icon.user /> Agenda</button>
                  </div>
                  {destType === 'label' ? (
                    <>
                      <div style={{ marginTop: 10 }}>
                        <Select block value={labelId} onChange={setLabelId} placeholder="Selecciona una etiqueta…"
                          options={labels.map((l) => ({ value: l.id, label: l.name, color: l.color }))} />
                      </div>
                      <span className="hint" style={{ marginTop: 6 }}>Se enviará a todos los contactos con esta etiqueta (dinámico).{labels.length === 0 && ' No tienes etiquetas: créalas en Contactos.'}</span>
                    </>
                  ) : (
                    <>
                      <div style={{ marginTop: 10 }}>
                        <Select block value={pbId} onChange={setPbId} placeholder="Selecciona una agenda…"
                          options={phonebooks.map((p) => ({ value: p.id, label: p.name, sub: `${p.contacts} contactos` }))} />
                      </div>
                      {phonebooks.length === 0 && <span className="hint" style={{ marginTop: 6, color: 'var(--warn, #f4b740)' }}>No tienes agendas. Crea una en «Agenda de contactos».</span>}
                    </>
                  )}
                </div>

                {(headerVars > 0 || bodyVars > 0) && (
                  <div style={{ marginTop: 16 }}>
                    <span className="lbl">Variables de la plantilla</span>
                    <span className="hint" style={{ marginBottom: 4 }}>Elige de dónde sale cada hueco. Con «Nombre del contacto», cada destinatario recibe el suyo.</span>
                    {['header', 'body'].flatMap((scope) => Array.from({ length: scope === 'header' ? headerVars : bodyVars }).map((_, i) => {
                      const v = vars[scope][i] || { source: 'fixed', value: '' }
                      const upd = (patch) => setVars((s) => ({ ...s, [scope]: s[scope].map((x, j) => (j === i ? { ...x, ...patch } : x)) }))
                      return (
                        <div className="var-row" key={scope + i}>
                          <span className="var-tag">{scope === 'header' ? 'Cabecera' : 'Mensaje'} · {`{{${i + 1}}}`}</span>
                          <Select sm value={v.source} onChange={(source) => upd({ source })} options={SOURCE_OPTS} />
                          {v.source !== 'phone' && (
                            <input className="cmp-var var-val" value={v.value}
                              placeholder={v.source === 'name' ? 'Texto si el contacto no tiene nombre (ej. cliente)' : 'Escribe el valor'}
                              onChange={(e) => upd({ value: e.target.value })} />
                          )}
                        </div>
                      )
                    }))}

                    <div className="var-preview">
                      <span className="vp-lbl">Ejemplo · cómo le llega a «{SAMPLE.name}»</span>
                      <div className="pbubble" style={{ maxWidth: '100%' }}>
                        {headerComp && <div style={{ fontWeight: 700, marginBottom: 4 }}>{renderExample(headerComp.text, 'header')}</div>}
                        {renderExample(templateText(picked), 'body')}
                      </div>
                    </div>
                  </div>
                )}

                <div style={{ marginTop: 16 }}>
                  <span className="lbl">Programación</span>
                  <label className="fb-req-row" style={{ marginTop: 8 }}>
                    <span className="fb-switch"><input type="checkbox" checked={immediate} onChange={(e) => setImmediate(e.target.checked)} /><span className={`fb-toggle ${immediate ? 'on' : ''}`} /></span>
                    <span className="fb-req-label">Enviar inmediatamente</span>
                  </label>
                  {!immediate && <input className="cmp-var" type="datetime-local" value={when} onChange={(e) => setWhen(e.target.value)} style={{ marginTop: 10, maxWidth: 280 }} />}
                </div>

                {estimate && !estimate.loading && (
                  <div className="card" style={{ padding: 16, marginTop: 16, background: 'var(--primary-soft)', border: '1px solid var(--line)' }}>
                    <div className="fb-set-t" style={{ marginBottom: 10 }}>Resumen del envío</div>
                    <div style={{ display: 'flex', flexDirection: 'column', gap: 6 }}>
                      <div style={ROW}><span>Destinatarios</span><b style={{ fontVariantNumeric: 'tabular-nums' }}>{estimate.recipients.toLocaleString('es-ES')}</b></div>
                      {estimate.excluded > 0 && <div style={{ ...ROW, color: 'var(--ink-3, #888)', fontSize: 12.5 }}><span>Excluidos por baja</span><span>{estimate.excluded}</span></div>}
                      <div style={ROW}><span>Plantilla</span><span>{picked.name}</span></div>
                      <div style={ROW}><span>Categoría</span><span style={{ color: catColor(picked.category), fontWeight: 600 }}>{picked.category}{estimate.show_cost ? ` · ${estimate.rate > 0 ? `${eur(estimate.rate, 4)}/msg` : 'gratis'}` : ''}</span></div>
                      {estimate.show_cost && (
                        <div style={{ ...ROW, borderTop: '1px solid var(--line)', paddingTop: 8, marginTop: 2 }}>
                          <span style={{ fontWeight: 700 }}>Coste estimado</span>
                          <b style={{ fontSize: 18, color: estimate.cost > 0 ? 'var(--ink)' : 'var(--secondary)' }}>{estimate.cost > 0 ? `≈ ${eur(estimate.cost)}` : 'Gratis'}</b>
                        </div>
                      )}
                    </div>
                    {estimate.show_cost && <p className="hint" style={{ marginTop: 8 }}>Estimación orientativa. Meta cobra según país y ventana de 24 h; los mensajes de servicio/utilidad dentro de la ventana son gratis.</p>}
                  </div>
                )}

                <div className="fb-actions">
                  {waLocked
                    ? <button className="btn gated" disabled><Icon.lock /> WhatsApp no configurado</button>
                    : <button className="btn" disabled={sending} onClick={send}><Icon.send /> {sending ? 'Enviando…' : (immediate ? 'Enviar campaña' : 'Programar campaña')}</button>}
                </div>
              </div>
            </>
          )}
          </>)}
        </div>
      </div>
    </>
  )
}
