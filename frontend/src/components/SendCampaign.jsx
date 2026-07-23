import { useState, useEffect, useMemo } from 'react'
import { api } from '../api.js'
import { Icon } from '../icons.jsx'
import { useToast } from '../App.jsx'
import Select from './Select.jsx'

// Contacto de ejemplo para la vista previa de personalización
const SAMPLE = { name: 'María García', wa_id: '34600112233' }
const SOURCE_OPTS = [
  { value: 'fixed', label: 'Valor fijo' },
  { value: 'name', label: 'Nombre del contacto' },
  { value: 'phone', label: 'Teléfono del contacto' },
]
const langName = (c) => ({ es: 'Español', es_ES: 'Español', en: 'Inglés', en_US: 'Inglés (US)', pt_BR: 'Portugués' }[c] || c)
const catColor = (c) => ({ MARKETING: '#f4b740', UTILITY: '#4a9bff', AUTHENTICATION: '#9b6dff' }[c] || '#00a884')

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
  const [step, setStep] = useState(1)
  const [gate, setGate] = useState(null)
  useEffect(() => { api.gating().then((d) => setGate(d?.ok ? d : null)) }, [])
  const waLocked = gate?.features?.wa_campaign
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
      components: buildComponents(),
      phonebook_id: destType === 'phonebook' ? Number(pbId) : 0,
      label_id: destType === 'label' ? Number(labelId) : 0,
      schedule: immediate ? { mode: 'now' } : { mode: 'later', at: when },
    })
    setSending(false)
    if (!r.ok) { toast(r.error || 'No se pudo crear la campaña', 'err'); return }
    const exc = r.excluded ? ` · ${r.excluded} excluido${r.excluded > 1 ? 's' : ''} por baja` : ''
    if (r.immediate) toast(`Campaña lanzada · ${r.stats.sent} enviados${r.stats.failed ? `, ${r.stats.failed} fallidos` : ''}${r.stats.pending ? `, ${r.stats.pending} en cola` : ''}${exc}`)
    else toast(`Campaña programada${exc}`)
    onDone?.()
  }

  return (
    <>
      <header className="page-head">
        <span className="ic" style={{ width: 30, height: 30, borderRadius: 9, background: 'var(--primary-soft)', display: 'grid', placeItems: 'center' }}><Icon.send style={{ width: 17, height: 17, fill: 'var(--primary)' }} /></span>
        <div><h1>Enviar campaña</h1></div>
        <span className="sub">· Difusión masiva con plantillas de Meta</span>
        <div className="spacer" />
      </header>

      <div className="page-scroll">
        <div className="page" style={{ maxWidth: 1080 }}>
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

                <div className="fb-actions">
                  {waLocked
                    ? <button className="btn gated" disabled><Icon.lock /> WhatsApp no configurado</button>
                    : <button className="btn" disabled={sending} onClick={send}><Icon.send /> {sending ? 'Enviando…' : (immediate ? 'Enviar campaña' : 'Programar campaña')}</button>}
                </div>
              </div>
            </>
          )}
        </div>
      </div>
    </>
  )
}
