import { useState, useEffect, useCallback } from 'react'
import { api } from '../api.js'
import { Icon } from '../icons.jsx'
import { useToast, useConfirm } from '../App.jsx'

/* -------------------------------------------------------------------------
 * DOCUMENTOS de una garantía (pestaña de la ficha). Presupuestos, pedidos y albaranes
 * viven en el portal AEME: aquí solo se consultan (los de garantía del cliente, con la
 * cadena presupuesto → pedido → expedición → albarán) y se preparan los presupuestos
 * para crearlos allí, uno por «quién paga», con los precios del portal. Crear todavía
 * no está activado. Lo propio de la garantía: el informe y el correo de resolución.
 * ---------------------------------------------------------------------- */

const eur = (n) => (n == null ? '—' : Number(n).toLocaleString('es-ES', { style: 'currency', currency: 'EUR' }))
const fecha = (s) => (s ? new Date(String(s).replace(' ', 'T')).toLocaleDateString('es-ES', { day: '2-digit', month: '2-digit', year: 'numeric' }) : '—')
const COV_ORDEN = ['cliente', 'pilas', 'garantia']

// Como los nombra el portal.
const EST_PPTO = { draft: 'Borrador', new: 'Nuevo', accepted: 'Aceptado', declined: 'Rechazado', revised: 'Revisado', expired: 'Caducado', delivered: 'Entregado', for_review: 'En revisión' }
const EST_ALB = { pending_shipping: 'Pendiente de envío', sent: 'Enviado', returned_sample: 'Muestra devuelta', delivered_and_signed: 'Entregado y firmado', delivered_by_carrier: 'Entregado por transportista' }
const ETAPA = { none: ['Sin pedido', 'off'], ordered: ['Pedido', 'mid'], shipping: ['En envío', 'mid'], done: ['Entregado', 'ok'] }
const TIPO = { garantia: 'En garantía', pilas: 'Pilas', cliente: 'Fuera de garantía', mano_obra: 'Mano de obra', otro: 'Otro' }

async function descargar(id, toast) {
  const r = await api.warrantyDocDownload(id)
  if (!r.ok) { toast(r.error, 'err'); return }
  const a = document.createElement('a')
  a.href = URL.createObjectURL(r.blob); a.download = r.filename; a.click()
  setTimeout(() => URL.revokeObjectURL(a.href), 1000)
}

function Modal({ title, wide, onClose, children, foot }) {
  return (
    <div className="modal-bg wr-over" onClick={(e) => e.target.classList.contains('modal-bg') && onClose()}>
      <div className={`modal wd-modal${wide ? ' wide' : ''}`} onKeyDown={(e) => { if (e.key === 'Escape') onClose() }}>
        <div className="modal-h"><h3>{title}</h3><button className="icon-btn" onClick={onClose} title="Cerrar (Esc)">✕</button></div>
        <div className="modal-body">{children}</div>
        {foot ? <div className="modal-foot">{foot}</div> : null}
      </div>
    </div>
  )
}

function Avisos({ list }) {
  return list.length ? (
    <ul className="wd-pv-checks">
      {list.map((c, i) => <li key={i} className={`t-${c.tone}`}>{c.tone === 'ok' ? <Icon.check /> : <Icon.warn />}<span>{c.text}</span></li>)}
    </ul>
  ) : null
}

/* --- Presupuestos para el portal: uno por «quién paga», con los precios del portal --- */
function Preparar({ w, onClose, onEditWarranty }) {
  const toast = useToast()
  const [r, setR] = useState(null)
  const cargar = useCallback(() => { setR(null); api.warrantyDocsDo('portal_drafts', { id: w.id }).then((x) => (x.ok ? setR(x) : (toast(x.error, 'err'), onClose()))) }, [w.id])
  useEffect(() => { cargar() }, [cargar])
  const malos = r ? [...r.checks, ...r.drafts.flatMap((d) => d.checks)].filter((c) => c.tone === 'bad').length : 0
  const n = r?.drafts.length || 0
  return (
    <Modal wide title="Presupuestos para el portal" onClose={onClose} foot={<>
      <button className="dev-btn" onClick={cargar} disabled={!r}><Icon.refresh /> Volver a comprobar</button>
      <span className="spacer" />
      <button className="btn ghost" onClick={onClose}>Cerrar</button>
      <button className="btn" disabled title={r?.enabled ? '' : 'La creación en el portal todavía no está activada'}><Icon.send /> Crear {n > 1 ? `${n} presupuestos` : 'presupuesto'} en el portal</button>
    </>}>
      {!r ? <div className="center-load"><div className="spinner" /></div> : (
        <>
          <div className={`wd-pv-st ${r.ready ? 'ok' : 'bad'}`}>
            {r.ready ? <Icon.check /> : <Icon.warn />}
            <span>{r.ready ? `Listo para crear ${n > 1 ? `los ${n} presupuestos` : 'el presupuesto'} en el portal.` : `Hay ${malos} ${malos === 1 ? 'cosa que impide' : 'cosas que impiden'} crearlos en el portal.`}
              <small>Se crean en borrador con los precios del portal (tarifa del cliente o catálogo); lo que cubre la garantía va a 0 €. La creación todavía no está activada: esto solo comprueba.</small></span>
          </div>
          <Avisos list={r.checks} />
          {r.checks.some((c) => c.tone === 'bad' && /cliente del portal/.test(c.text)) && onEditWarranty
            ? <button className="dev-btn wd-pv-fix" onClick={onEditWarranty}><Icon.pencil /> Editar la garantía para enlazar el cliente</button> : null}
          {r.drafts.map((d) => (
            <section key={d.coverage} className="wd-pv">
              <div className="wd-pv-h">
                <div><small>Presupuesto</small><b><span className={`wr-cov cov-${d.coverage}`}>{d.type}</span></b><span className="dev-mute">{r.prefix}-…… (lo numera el portal) · borrador</span></div>
                <div><small>Cliente</small><b>{r.client || <span className="wf-warn">Sin enlazar</span>}</b></div>
                <div><small>Dispositivos</small><b>{d.items}</b></div>
              </div>
              {d.checks.length ? <div className="wd-pv-av"><Avisos list={d.checks} /></div> : null}
              <div className="dev-table-wrap keep wt-wrap">
                <table className="dev-table wd-pv-t">
                  <thead><tr><th>Ref.</th><th>Producto</th><th className="r">Cant.</th><th>Unidad</th><th className="r">Precio</th><th className="r">Total</th></tr></thead>
                  <tbody>
                    {d.lines.map((l, i) => (
                      <tr key={i} className={l.found ? '' : 'libre'}>
                        <td className="mono">{l.ref || <span className="dev-mute">Libre</span>}</td>
                        <td><b>{l.name}</b><small className="mono" title={l.codes.join(' · ')}>{l.codes.length} {l.codes.length === 1 ? 'código' : 'códigos'}: {l.codes.slice(0, 3).join(' · ')}{l.codes.length > 3 ? '…' : ''}</small></td>
                        <td className="r">{String(l.qty).replace('.', ',')}</td>
                        <td>{l.unit}</td>
                        <td className="r">{eur(l.rate)}{l.from ? <small>{l.from}</small> : null}</td>
                        <td className="r">{eur(l.total)}</td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
              <div className="wd-pv-tot">
                <span>Subtotal <b>{eur(d.base)}</b></span>
                {d.tax ? <span>{d.tax} <b>{eur(d.tax_amount)}</b></span> : null}
                <span className="big">Total <b>{eur(d.total)}</b></span>
              </div>
            </section>
          ))}
        </>
      )}
    </Modal>
  )
}

/* --- Informe de resolución --- */
function Informe({ w, summary, actual, onClose, onDone }) {
  const toast = useToast()
  const [fotos, setFotos] = useState(true)
  const [ocupado, setOcupado] = useState(false)
  const generar = async () => {
    setOcupado(true)
    const r = await api.warrantyDocsDo('report', { id: w.id, photos: fotos })
    setOcupado(false)
    if (!r.ok) { toast(r.error, 'err'); return }
    toast(actual ? 'Informe actualizado' : 'Informe generado')
    descargar(r.id, toast)
    onDone()
  }
  return (
    <Modal title="Informe de resolución" onClose={onClose} foot={<>
      <button className="btn ghost" onClick={onClose}>Cancelar</button>
      <button className="btn" disabled={ocupado || summary.pending > 0} onClick={generar}>{ocupado ? 'Generando…' : actual ? 'Generar de nuevo' : 'Generar informe'}</button>
    </>}>
      <p className="wd-tip">El PDF con el resultado de cada dispositivo y quién paga. Se guarda tal cual queda; si cambia algo, se genera otra vez{actual ? <> y sustituye a <b>{actual.number}</b> (mismo número)</> : null}.</p>
      {summary.pending ? <p className="wd-warn"><Icon.warn /> Quedan {summary.pending} {summary.pending === 1 ? 'dispositivo' : 'dispositivos'} sin revisar: el informe se genera con todo revisado.</p> : null}
      <label className="wr-check"><input type="checkbox" checked={fotos} onChange={(e) => setFotos(e.target.checked)} /> Incluir las fotos de los dispositivos</label>
    </Modal>
  )
}

/*
 * --- Correo de resolución: la plantilla rellenada. Se envía desde el ticket de la garantía
 * (queda en la conversación con el cliente) o se copia. La resolución va siempre por
 * correo: si el cliente escribió por WhatsApp, hay que pedirle su correo.
 */
const EMAIL_OK = /^[^\s@]+@[^\s@]+\.[^\s@]+$/
function Correo({ w, informe, onClose, onSent }) {
  const toast = useToast()
  const confirm = useConfirm()
  const [m, setM] = useState(null)
  const [adjuntar, setAdjuntar] = useState(!!informe)
  const [enviando, setEnviando] = useState(false)
  useEffect(() => { api.warrantyResolutionEmail(w.id).then((r) => (r.ok ? setM({ ...r, to: r.to || '' }) : (toast(r.error, 'err'), onClose()))) }, [w.id])
  const copiar = async (t, que) => { try { await navigator.clipboard.writeText(t); toast(`${que} copiado`) } catch { toast('No se pudo copiar', 'err') } }
  const correoOk = EMAIL_OK.test((m?.to || '').trim())
  const porWhatsApp = m?.ticket?.channel === 'whatsapp'

  const enviar = async () => {
    const extra = adjuntar && informe ? ` con el informe ${informe.number}` : ''
    if (!(await confirm({ title: 'Enviar correo de resolución', message: `¿Enviar a ${m.to.trim()}${extra}? Saldrá como respuesta del ticket ${m.ticket.code} y quedará en la conversación con el cliente.`, confirmText: 'Enviar' }))) return
    setEnviando(true)
    const r = await api.warrantyDocsDo('send_email', { id: w.id, to: m.to.trim(), subject: m.subject, body: m.body, attach_report: adjuntar && !!informe })
    setEnviando(false)
    if (!r.ok) { toast(r.error || 'No se pudo enviar', 'err'); return }
    toast(`Correo enviado desde ${r.ticket}`)
    onSent?.()
    onClose()
  }

  const pie = m?.can_send
    ? <>
        <button className="btn ghost" onClick={onClose}>Cancelar</button>
        <button className="btn" disabled={enviando || !correoOk || !m.subject.trim() || !m.body.trim()} onClick={enviar}><Icon.send /> {enviando ? 'Enviando…' : `Enviar desde ${m.ticket.code}`}</button>
      </>
    : <button className="btn" onClick={onClose}>Listo</button>

  return (
    <Modal wide title="Correo de resolución" onClose={onClose} foot={pie}>
      {!m ? <div className="center-load"><div className="spinner" /></div> : (
        <>
          <p className="dev-mute wd-tip">{m.can_send
            ? <>Rellenado con la plantilla de Configuración. Se envía por correo como respuesta del ticket <b className="mono">{m.ticket.code}</b>: el cliente lo recibe en su correo y queda en la conversación.</>
            : <>{m.cannot_send} Puedes copiarlo y enviarlo desde tu correo.</>}</p>
          {porWhatsApp && m.can_send ? <p className="wd-warn"><Icon.whatsapp /> El cliente escribió por WhatsApp y la resolución va por correo: {m.to ? 'comprueba que este es su correo.' : 'pídele su correo y escríbelo aquí.'}</p> : null}
          <div className="wd-mail-row">
            <span>Para</span>
            <input type="email" list="wd-mail-sug" value={m.to} onChange={(e) => setM({ ...m, to: e.target.value })} placeholder="correo@cliente.com"
              className={m.to && !correoOk ? 'bad' : ''} />
            <datalist id="wd-mail-sug">{(m.suggestions || []).map((s) => <option key={s.email} value={s.email}>{s.label}</option>)}</datalist>
            <button className="dev-btn" disabled={!m.to} onClick={() => copiar(m.to, 'Correo')}><Icon.copy /> Copiar</button>
          </div>
          {m.suggestions?.length ? (
            <div className="wd-sug">{m.suggestions.filter((s) => s.email !== m.to.trim().toLowerCase()).map((s) => (
              <button key={s.email} type="button" onClick={() => setM({ ...m, to: s.email })} title={s.label}>{s.email} <small>{s.label}</small></button>
            ))}</div>
          ) : null}
          <div className="wd-mail-row"><span>Asunto</span><input value={m.subject} onChange={(e) => setM({ ...m, subject: e.target.value })} /><button className="dev-btn" onClick={() => copiar(m.subject, 'Asunto')}><Icon.copy /> Copiar</button></div>
          <textarea className="wd-mail-body" rows={12} value={m.body} onChange={(e) => setM({ ...m, body: e.target.value })} />
          <div className="wd-mail-f">
            <button className="dev-btn" onClick={() => copiar(m.body, 'Texto')}><Icon.copy /> Copiar el texto</button>
            <span className="spacer" />
            {informe
              ? <>{m.can_send ? <label className="wr-check"><input type="checkbox" checked={adjuntar} onChange={(e) => setAdjuntar(e.target.checked)} /> Adjuntar el informe</label> : null}
                  <button className="dev-btn" onClick={() => descargar(informe.id, toast)}><Icon.download /> {informe.number}</button></>
              : <span className="dev-mute">Sin informe todavía: genéralo para adjuntarlo.</span>}
          </div>
        </>
      )}
    </Modal>
  )
}

/* --- Aviso a Finanzas de los presupuestos que hay que crear (formato en Configuración) --- */
function Finanzas({ w, summary, onClose }) {
  const toast = useToast()
  const [t, setT] = useState(null)
  useEffect(() => { api.warrantyFinanceText(w.id).then((r) => (r.ok ? setT(r.text) : (toast(r.error, 'err'), onClose()))) }, [w.id])
  const copiar = async () => { try { await navigator.clipboard.writeText(t); toast('Texto copiado') } catch { toast('No se pudo copiar', 'err') } }
  return (
    <Modal title="Texto para Finanzas" onClose={onClose} foot={<>
      <button className="btn ghost" onClick={onClose}>Cerrar</button>
      <button className="btn" disabled={!t} onClick={copiar}><Icon.copy /> Copiar</button>
    </>}>
      <p className="dev-mute wd-tip">Los presupuestos que hay que crear, con las unidades de cada modelo, si cuentan stock y a qué coste. Puedes retocarlo antes de copiarlo; el formato se cambia en Configuración → Texto para Finanzas.</p>
      {summary.pending ? <p className="wd-warn"><Icon.warn /> Quedan {summary.pending} {summary.pending === 1 ? 'dispositivo' : 'dispositivos'} sin revisar: no entran.</p> : null}
      {t == null ? <div className="center-load"><div className="spinner" /></div> : <textarea className="wd-mail-body" rows={14} value={t} onChange={(e) => setT(e.target.value)} />}
    </Modal>
  )
}

/*
 * --- Pegatina del paquete (la de la app antigua, 105 × 74 mm): se retoca aquí y sale en
 * PDF, suelta o en las posiciones libres de una hoja A4 de 8 (lo que antes se hacía en Publisher).
 */
function Pegatina({ w, onClose }) {
  const toast = useToast()
  const [d, setD] = useState(null)
  const [formato, setFormato] = useState('label')
  const [slots, setSlots] = useState([1])
  const [ocupado, setOcupado] = useState(false)
  useEffect(() => { api.warrantyStickerData(w.id).then((r) => (r.ok ? setD({ ...r, products: r.products.length ? r.products : [{ name: '', qty: 1 }] }) : (toast(r.error, 'err'), onClose()))) }, [w.id])
  const set = (c) => setD((x) => ({ ...x, ...c }))
  const setP = (i, c) => set({ products: d.products.map((p, j) => (j === i ? { ...p, ...c } : p)) })
  const toggle = (s) => setSlots((x) => (x.includes(s) ? x.filter((y) => y !== s) : [...x, s]))
  const productos = (d?.products || []).filter((p) => p.name.trim())
  const listo = d && d.code.trim() && (formato === 'label' || slots.length) && productos.every((p) => Number(p.qty) >= 1)

  const generar = async (abrir) => {
    setOcupado(true)
    const r = await api.warrantySticker({ id: w.id, code: d.code.trim(), client: d.client, company: d.company, shipped_at: d.shipped_at || null,
      received_at: d.received_at || null, products: productos.map((p) => ({ name: p.name.trim(), qty: Number(p.qty) })), format: formato, slots })
    setOcupado(false)
    if (!r.ok) { toast(r.error, 'err'); return }
    const url = URL.createObjectURL(r.blob)
    if (abrir) window.open(url, '_blank', 'noopener')
    else { const a = document.createElement('a'); a.href = url; a.download = `Pegatina-${d.code.trim()}.pdf`; a.click() }
    setTimeout(() => URL.revokeObjectURL(url), 60000)
  }

  return (
    <Modal wide title="Pegatina del paquete" onClose={onClose} foot={<>
      <button className="btn ghost" onClick={onClose}>Cerrar</button>
      <button className="dev-btn" disabled={!listo || ocupado} onClick={() => generar(false)}><Icon.download /> Descargar</button>
      <button className="btn" disabled={!listo || ocupado} onClick={() => generar(true)}><Icon.print /> {ocupado ? 'Generando…' : 'Abrir para imprimir'}</button>
    </>}>
      {!d ? <div className="center-load"><div className="spinner" /></div> : (
        <div className="wd-peg">
          <div>
            <div className="grid2">
              <label className="field"><span className="lbl">Garantía</span><input value={d.code} maxLength={30} onChange={(e) => set({ code: e.target.value })} /></label>
              <span />
              <label className="field"><span className="lbl">Enviado por el cliente el</span><input type="date" value={d.shipped_at || ''} onChange={(e) => set({ shipped_at: e.target.value })} /></label>
              <label className="field"><span className="lbl">Recibido en almacén</span><input type="date" value={d.received_at || ''} onChange={(e) => set({ received_at: e.target.value })} /></label>
              <label className="field"><span className="lbl">Cliente</span><input value={d.client} maxLength={120} placeholder="Hotel o tienda" onChange={(e) => set({ client: e.target.value })} /></label>
              <label className="field"><span className="lbl">Empresa</span><input value={d.company} maxLength={120} onChange={(e) => set({ company: e.target.value })} /></label>
            </div>
            <p className="dev-mute wd-tip">«Revisado el», «Pte. creación presupuesto» y «Resolución enviada el» salen con su casilla y una línea para rellenar a mano.</p>
          </div>
          <div>
            <h4 className="wd-peg-h">Productos <small className="dev-mute">{productos.length > 4 ? 'a dos columnas' : 'hasta 4 a tamaño normal'}</small></h4>
            <div className="wd-peg-prods">
              {d.products.map((p, i) => (
                <div key={i} className="wd-peg-prod">
                  <input value={p.name} maxLength={40} placeholder="Referencia o modelo" onChange={(e) => setP(i, { name: e.target.value })} />
                  <input type="number" min={1} value={p.qty} onChange={(e) => setP(i, { qty: e.target.value })} aria-label="Cantidad" />
                  <button className="icon-btn" title="Quitar" onClick={() => set({ products: d.products.filter((_, j) => j !== i) })}>✕</button>
                </div>
              ))}
            </div>
            {d.products.length < 16 ? <button className="wf-link" onClick={() => set({ products: [...d.products, { name: '', qty: 1 }] })}><Icon.plus /> Añadir producto</button> : null}

            <h4 className="wd-peg-h">Formato</h4>
            <div className="wd-peg-fmt">
              <label className={formato === 'label' ? 'on' : ''}><input type="radio" checked={formato === 'label'} onChange={() => setFormato('label')} /> <b>Etiqueta suelta</b><small>105 × 74 mm, para la impresora de etiquetas</small></label>
              <label className={formato === 'a4' ? 'on' : ''}><input type="radio" checked={formato === 'a4'} onChange={() => setFormato('a4')} /> <b>Hoja A4 de {d.slots}</b><small>Elige dónde va (las posiciones libres de la hoja)</small></label>
            </div>
            {formato === 'a4' ? (
              <div className="wd-peg-hoja" role="group" aria-label="Posiciones de la hoja">
                {Array.from({ length: d.slots }, (_, i) => i + 1).map((s) => (
                  <button key={s} type="button" className={slots.includes(s) ? 'on' : ''} onClick={() => toggle(s)} aria-pressed={slots.includes(s)}>{slots.includes(s) ? <Icon.check /> : s}</button>
                ))}
              </div>
            ) : null}
          </div>
        </div>
      )}
    </Modal>
  )
}

/* --- Lo que hay en el portal: proyectos de garantía del cliente y sus presupuestos --- */
const Enlace = ({ href, children, className = '' }) => <a className={`wd-link ${className}`} href={href} target="_blank" rel="noopener noreferrer">{children}</a>

function Portal({ w, onEditWarranty }) {
  const [p, setP] = useState(null)
  const [todos, setTodos] = useState(false)
  const cargar = useCallback((fresh) => { setP(null); api.warrantyPortalDocs(w.id, fresh).then((r) => setP(r.ok ? r : { error: r.error })) }, [w.id])
  useEffect(() => { cargar(false) }, [cargar])

  const cabecera = (
    <header className="wd-sec-h">
      <h4><Icon.globe /> En el portal <span className="dev-mute">· documentos de garantía de {w.client_name || 'este cliente'}</span></h4>
      {p && !p.error && p.linked ? <button className="icon-btn" title="Volver a leer del portal" onClick={() => cargar(true)}><Icon.refresh /></button> : null}
    </header>
  )
  if (!p) return <section className="wd-sec">{cabecera}<div className="center-load"><div className="spinner" /></div></section>
  if (p.error) return <section className="wd-sec">{cabecera}<div className="dev-error"><Icon.warn /> {p.error} <button className="wf-link" onClick={() => cargar(true)}>Reintentar</button></div></section>
  if (!p.linked) return (
    <section className="wd-sec">{cabecera}
      <p className="wd-empty-p">La garantía no tiene enlazado el cliente del portal, así que no se pueden ver sus documentos. {onEditWarranty ? <button className="wf-link" onClick={onEditWarranty}>Enlazar el cliente</button> : null}</p>
    </section>
  )

  const proyectos = p.projects
  const visibles = todos ? proyectos : proyectos.slice(0, 4)
  const nPres = proyectos.reduce((s, x) => s + x.estimates.length, 0)
  return (
    <section className="wd-sec">{cabecera}
      {!proyectos.length ? <p className="wd-empty-p">El cliente no tiene garantías en el portal desde julio de 2024.</p> : (
        <>
          <p className="dev-mute wd-sec-sub">{proyectos.length} {proyectos.length === 1 ? 'garantía' : 'garantías'} · {nPres} {nPres === 1 ? 'presupuesto' : 'presupuestos'}</p>
          <div className="wd-proys">
            {visibles.map((x) => (
              <div key={x.id} className="wd-proy">
                <div className="wd-proy-h">
                  {x.code ? <span className="mono wd-gcode">{x.code}</span> : null}
                  <b>{x.title}</b>
                  <span className="dev-mute">{fecha(x.created)}</span>
                  <Enlace href={x.url} className="end">Abrir proyecto <Icon.arrowRight /></Enlace>
                </div>
                {!x.estimates.length ? <p className="dev-mute wd-proy-none">Sin presupuestos.</p> : (
                  <table className="dev-table wd-est">
                    <tbody>
                      {x.estimates.map((e) => {
                        const [et, tono] = ETAPA[e.stage] || [e.stage, 'off']
                        const resto = e.products.length - 2
                        return (
                          <tr key={e.id}>
                            <td className="nw"><Enlace href={e.url}><span className="mono">{e.code}</span></Enlace><small>{fecha(e.date)} · {EST_PPTO[e.status] || e.status}</small></td>
                            <td><span className={`wr-cov cov-${e.type}`}>{TIPO[e.type] || e.type}</span></td>
                            <td className="wd-prods">{e.products.slice(0, 2).map((pr, i) => <span key={i}><b className="mono">{pr.ref || '—'}</b> ×{String(pr.qty).replace('.', ',')}</span>)}{resto > 0 ? <span className="dev-mute">+{resto}</span> : null}{!e.products.length ? <span className="dev-mute">Sin líneas</span> : null}</td>
                            <td className="r nw"><b>{e.amount ? eur(e.amount) : <span className="wd-free">0,00 €</span>}</b></td>
                            <td className="wd-cadena">
                              <span className={`wd-etapa t-${tono}`}>{et}</span>
                              {[...e.orders, ...e.expeditions, ...e.deliveries].map((c) => <Enlace key={c.code} href={c.url}><span className="mono">{c.code}</span>{EST_ALB[c.status] ? <small>{EST_ALB[c.status]}</small> : null}</Enlace>)}
                            </td>
                          </tr>
                        )
                      })}
                    </tbody>
                  </table>
                )}
              </div>
            ))}
          </div>
          {proyectos.length > 4 ? <button className="wf-link wd-mas" onClick={() => setTodos(!todos)}>{todos ? 'Ver menos' : `Ver las ${proyectos.length} garantías`}</button> : null}
        </>
      )}
    </section>
  )
}

export default function WarrantyDocuments({ w, opts, onGoReview, onEditWarranty, onChanged }) {
  const toast = useToast()
  const [d, setD] = useState(null)
  const [modal, setModal] = useState(null)   // 'preparar' | 'finanzas' | 'pegatina' | 'informe' | 'correo'
  const cargar = useCallback(() => api.warrantyDocs(w.id).then((r) => setD(r.ok ? r : { error: r.error })), [w.id])
  useEffect(() => { cargar() }, [cargar])

  if (!d || !opts) return <div className="center-load"><div className="spinner" /></div>
  if (d.error) return <div className="dev-error"><Icon.warn /> {d.error}</div>

  const { summary, report } = d
  const revisados = summary.total - summary.pending
  const cerrar = () => setModal(null)
  const editar = onEditWarranty ? () => { cerrar(); onEditWarranty() } : null

  return (
    <>
      <div className="wd-resumen">
        {COV_ORDEN.map((c) => (
          <span key={c} className={`wd-rs cov-${c}${!summary.coverage[c].items ? ' cero' : ''}`}><i />{opts.review.coverages[c]} <b>{summary.coverage[c].items}</b></span>
        ))}
        <span className={`wd-rs rev${summary.pending ? ' pend' : ''}`}>
          Revisión <b>{revisados}/{summary.total}</b>
          {summary.pending ? <button className="wf-link" onClick={onGoReview}>Quedan {summary.pending} sin revisar</button> : null}
        </span>
      </div>

      <div className="wd-acts">
        <button className="btn sm" disabled={!revisados} title={revisados ? '' : 'Revisa los dispositivos primero'} onClick={() => setModal('preparar')}><Icon.doc /> Preparar presupuestos para el portal</button>
        <button className="dev-btn" disabled={!revisados} title={revisados ? 'Los presupuestos que hay que crear, para avisar a Finanzas' : 'Revisa los dispositivos primero'} onClick={() => setModal('finanzas')}><Icon.copy /> Texto para Finanzas</button>
        <span className="spacer" />
        <button className="dev-btn" title="La pegatina para el paquete, en PDF para imprimir" onClick={() => setModal('pegatina')}><Icon.print /> Pegatina del paquete</button>
        <button className="dev-btn" disabled={!summary.total} title={summary.pending ? 'Se genera con todo revisado' : ''} onClick={() => setModal('informe')}><Icon.file /> {report ? 'Informe de resolución' : 'Generar informe'}</button>
        {report ? <button className="dev-btn" title={`Descargar el informe${report.note ? ` (${report.note.toLowerCase()})` : ''}`} onClick={() => descargar(report.id, toast)}><Icon.download /> <span className="mono">{report.number}</span></button> : null}
        <button className="dev-btn" onClick={() => setModal('correo')}><Icon.mail /> Correo de resolución</button>
      </div>

      <Portal w={w} onEditWarranty={editar} />

      {modal === 'preparar' && <Preparar w={w} onClose={cerrar} onEditWarranty={editar} />}
      {modal === 'pegatina' && <Pegatina w={w} onClose={cerrar} />}
      {modal === 'finanzas' && <Finanzas w={w} summary={summary} onClose={cerrar} />}
      {modal === 'informe' && <Informe w={w} summary={summary} actual={report} onClose={cerrar} onDone={() => { cerrar(); cargar() }} />}
      {modal === 'correo' && <Correo w={w} informe={report} onClose={cerrar} onSent={onChanged} />}
    </>
  )
}
