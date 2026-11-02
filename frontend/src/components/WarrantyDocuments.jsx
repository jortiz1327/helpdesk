import { useState, useEffect, useCallback, useRef } from 'react'
import { api } from '../api.js'
import { Icon } from '../icons.jsx'
import { useToast, useConfirm } from '../App.jsx'
import Select from './Select.jsx'

/* -------------------------------------------------------------------------
 * DOCUMENTOS de una garantía (pestaña de la ficha). Todo lo que va al cliente:
 *  - Presupuestos y albaranes generados desde los dispositivos revisados, uno por cada
 *    «quién paga» (garantía, cliente, pilas) con los precios de la tarifa. Se editan
 *    línea a línea; de un presupuesto sale su albarán.
 *  - Documentos hechos fuera (ERP, portal): se suben con su tipo, número e importe.
 *  - El informe de resolución: solo con todo revisado; se guarda tal cual quedó.
 *  - El correo de resolución, rellenado con la plantilla, para copiarlo.
 * ---------------------------------------------------------------------- */

const eur = (n) => (n == null ? '—' : Number(n).toLocaleString('es-ES', { style: 'currency', currency: 'EUR' }))
const fecha = (s) => (s ? new Date(s.replace(' ', 'T')).toLocaleDateString('es-ES', { day: '2-digit', month: '2-digit', year: 'numeric' }) : '—')
const peso = (b) => (!b ? '' : b < 1024 * 1024 ? `${Math.max(1, Math.round(b / 1024))} KB` : `${(b / 1024 / 1024).toFixed(1).replace('.', ',')} MB`)
const SUBIBLES = ['presupuesto', 'albaran', 'factura', 'otro']
const COV_ORDEN = ['cliente', 'pilas', 'garantia']
const PORTAL = ['presupuesto', 'albaran']   // los que se podrán crear en el portal
const PLURAL = { presupuesto: 'Presupuestos', albaran: 'Albaranes', factura: 'Facturas', informe: 'Informes', otro: 'Otros' }

async function descargar(doc, toast) {
  const r = await api.warrantyDocDownload(doc.id)
  if (!r.ok) { toast(r.error, 'err'); return }
  const a = document.createElement('a')
  a.href = URL.createObjectURL(r.blob); a.download = r.filename; a.click()
  setTimeout(() => URL.revokeObjectURL(a.href), 1000)
}

function CovPill({ c, opts }) {
  return c ? <span className={`wr-cov cov-${c}`}>{opts?.review?.coverages?.[c] || c}</span> : <span className="dev-mute">—</span>
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

/* --- Generar presupuestos o albaranes por «quién paga» --- */
function Generar({ w, kind, opts, summary, docs, onClose, onDone }) {
  const toast = useToast()
  const cov = summary.coverage
  const [sel, setSel] = useState(() => new Set(COV_ORDEN.filter((c) => cov[c]?.items && !docs.some((d) => d.kind === kind && d.coverage === c && d.status !== 'anulado'))))
  const [ocupado, setOcupado] = useState(false)
  const label = opts.documents.kinds[kind].label
  const plural = kind === 'albaran' ? 'albaranes' : 'presupuestos'
  const marcar = (c) => setSel((s) => { const n = new Set(s); n.has(c) ? n.delete(c) : n.add(c); return n })
  const generar = async () => {
    setOcupado(true)
    const r = await api.warrantyDocsDo('generate', { id: w.id, kind, coverages: [...sel] })
    setOcupado(false)
    if (!r.ok) { toast(r.error, 'err'); return }
    toast(`${r.ids.length} ${r.ids.length === 1 ? label.toLowerCase() : plural} en borrador`)
    onDone(r.ids)
  }
  return (
    <Modal title={`Generar ${plural}`} onClose={onClose} foot={<>
      <button className="btn ghost" onClick={onClose}>Cancelar</button>
      <button className="btn" disabled={!sel.size || ocupado} onClick={generar}>{ocupado ? 'Generando…' : `Generar ${sel.size || ''} ${sel.size === 1 ? label.toLowerCase() : plural}`}</button>
    </>}>
      <p className="dev-mute wd-tip">Uno por cada «quién paga», con los dispositivos ya revisados y el precio de la tarifa. Salen en borrador para repasarlos.</p>
      {summary.pending ? <p className="wd-warn"><Icon.warn /> Quedan {summary.pending} sin revisar: no entran.</p> : null}
      <div className="wd-covs">
        {COV_ORDEN.map((c) => {
          const n = cov[c]?.items || 0
          const ya = docs.filter((d) => d.kind === kind && d.coverage === c && d.status !== 'anulado')
          return (
            <label key={c} className={`wd-cov-opt${!n ? ' off' : ''}${sel.has(c) ? ' on' : ''}`}>
              <input type="checkbox" disabled={!n} checked={sel.has(c)} onChange={() => marcar(c)} />
              <span className="tx">
                <span className="t"><CovPill c={c} opts={opts} /> <b>{n}</b> {n === 1 ? 'dispositivo' : 'dispositivos'}</span>
                {c === 'garantia' && n ? <small>Se listan a 0 €: no tienen cargo para el cliente.</small> : null}
                {cov[c]?.no_price ? <small className="bad"><Icon.warn /> {cov[c].no_price} sin precio en la tarifa: saldrán a 0 €</small> : null}
                {ya.length ? <small>Ya hay {ya.map((d) => d.number || d.file_name).join(', ')}</small> : null}
              </span>
            </label>
          )
        })}
      </div>
    </Modal>
  )
}

/* --- Editar un presupuesto o albarán generado: debajo de la lista, sin tapar la ficha --- */
function Editor({ id, opts, services, onClose, onSaved }) {
  const toast = useToast()
  const confirm = useConfirm()
  const [d, setD] = useState(null)
  const [guardando, setGuardando] = useState(false)
  const [abiertos, setAbiertos] = useState(() => new Set())
  const original = useRef('')
  const caja = useRef(null)
  useEffect(() => {
    setD(null)
    api.warrantyDoc(id).then((r) => {
      if (!r.ok) { toast(r.error, 'err'); onClose(); return }
      original.current = JSON.stringify(r.document)
      setD(r.document)
    })
  }, [id])
  useEffect(() => { if (d) caja.current?.scrollIntoView({ behavior: 'smooth', block: 'nearest' }) }, [!!d])
  if (!d) return <section className="wd-ed"><div className="center-load"><div className="spinner" /></div></section>

  const kind = opts.documents.kinds[d.kind]
  const cobra = d.coverage !== 'garantia'
  const set = (c) => setD((x) => ({ ...x, ...c }))
  const setLinea = (i, c) => set({ lines: d.lines.map((l, k) => (k === i ? { ...l, ...c } : l)) })
  const quitar = (i) => set({ lines: d.lines.filter((_, k) => k !== i) })
  const anadir = (l) => set({ lines: [...d.lines, { ref: '', description: '', qty: 1, price: 0, codes: [], ...l }] })
  const base = cobra ? d.lines.reduce((s, l) => s + (Number(l.qty) || 0) * (Number(l.price) || 0), 0) : 0
  const iva = Math.round(base * (Number(d.vat) || 0)) / 100
  // Los de garantía salen a 0 €, pero se ve cuánto vale lo que cubre (precio de la tarifa).
  const valorado = d.lines.reduce((s, l) => s + (Number(l.qty) || 0) * (Number(l.price) || 0), 0)
  const guardar = async () => {
    setGuardando(true)
    const r = await api.warrantyDocsDo('save', { id: d.id, vat: d.vat, note: d.note || '', status: d.status, lines: d.lines })
    setGuardando(false)
    if (!r.ok) { toast(r.error, 'err'); return }
    toast(`${d.number} guardado`); onSaved()
  }
  const cerrar = async () => {
    if (JSON.stringify(d) !== original.current && !(await confirm({ title: 'Cambios sin guardar', message: `¿Cerrar ${d.number} sin guardar los cambios?`, confirmText: 'Cerrar sin guardar', danger: true }))) return
    onClose()
  }
  return (
    <section className="wd-ed" ref={caja}>
      <header className="wd-ed-h">
        <div>
          <h3>{kind.label} <span className="mono">{d.number}</span> <CovPill c={d.coverage} opts={opts} /></h3>
          <p className="dev-mute">{cobra ? 'Las líneas que paga el cliente, con el precio de la tarifa.' : 'Lo que cubre la garantía: sale a 0 € para el cliente, con lo que vale a tarifa como referencia.'}{d.from_number ? ` Sale del presupuesto ${d.from_number}.` : ''}</p>
        </div>
        <label>Estado <Select sm value={d.status} onChange={(v) => set({ status: v })} options={kind.statuses.map((s) => ({ value: s, label: opts.documents.statuses[s] }))} /></label>
        <button className="icon-btn" title="Cerrar el editor" onClick={cerrar}>✕</button>
      </header>
      <div className="wd-add">
        {services.length ? <Select sm value="" placeholder="Añadir concepto de la tarifa…" onChange={(ref) => { const s = services.find((x) => x.ref === ref); if (s) anadir({ ref: s.ref, description: s.label, price: s.price }) }}
          options={services.map((s) => ({ value: s.ref, label: `${s.label} · ${eur(s.price)}` }))} /> : null}
        <button className="dev-btn" onClick={() => anadir({})}><Icon.plus /> Línea manual</button>
        <span className="spacer" />
        <span className="dev-mute">{d.lines.length} {d.lines.length === 1 ? 'línea' : 'líneas'}</span>
      </div>
      <div className="dev-table-wrap keep wt-wrap">
        <table className="dev-table wd-lines">
          <thead><tr><th style={{ width: '16%' }}>Referencia</th><th>Descripción</th><th className="r" style={{ width: 86 }}>Cant.</th><th className="r" style={{ width: 104 }}>Precio</th><th className="r" style={{ width: 96 }}>Importe</th><th style={{ width: 34 }} /></tr></thead>
          <tbody>
            {d.lines.map((l, i) => (
              <tr key={i}>
                <td><input value={l.ref || ''} maxLength={80} onChange={(e) => setLinea(i, { ref: e.target.value })} placeholder="—" /></td>
                <td>
                  <input value={l.description} maxLength={300} onChange={(e) => setLinea(i, { description: e.target.value })} placeholder="Descripción" />
                  {l.codes?.length ? (
                    <button type="button" className="wd-codes" onClick={() => setAbiertos((s) => { const n = new Set(s); n.has(i) ? n.delete(i) : n.add(i); return n })}>
                      {abiertos.has(i) ? l.codes.join(' · ') : `${l.codes.length} ${l.codes.length === 1 ? 'código' : 'códigos'}: ${l.codes.slice(0, 3).join(' · ')}${l.codes.length > 3 ? '…' : ''}`}
                    </button>
                  ) : null}
                </td>
                <td><input type="number" min="0" step="1" className="r" value={l.qty} onChange={(e) => setLinea(i, { qty: e.target.value })} /></td>
                <td><input type="number" min="0" step="0.01" className="r" value={l.price} onChange={(e) => setLinea(i, { price: e.target.value })} /></td>
                <td className="r">{cobra ? eur((Number(l.qty) || 0) * (Number(l.price) || 0)) : (
                  <span className="wd-cub"><span className="wd-free">0,00 €</span>{Number(l.price) ? <s>{eur((Number(l.qty) || 0) * Number(l.price))}</s> : null}</span>
                )}</td>
                <td><button className="icon-btn" title="Quitar línea" onClick={() => quitar(i)}><Icon.trash /></button></td>
              </tr>
            ))}
          </tbody>
        </table>
        {!d.lines.length ? <p className="dev-mute" style={{ padding: 12 }}>Sin líneas.</p> : null}
      </div>
      <div className="wd-ed-f">
        <div className="field">
          <span className="lbl">Nota del documento <span className="hint">— sale al pie (condiciones, plazos…)</span></span>
          <textarea rows={4} value={d.note || ''} maxLength={5000} onChange={(e) => set({ note: e.target.value })} />
        </div>
        <div className="wd-tot">
          {cobra ? (
            <>
              <div><span>Base imponible</span><b>{eur(base)}</b></div>
              <div><span>IVA <input type="number" min="0" max="100" step="0.01" value={d.vat} onChange={(e) => set({ vat: e.target.value })} /> %</span><b>{eur(iva)}</b></div>
              <div className="big"><span>Total cliente</span><b>{eur(base + iva)}</b></div>
            </>
          ) : (
            <>
              <div><span>Valorado a tarifa</span><b>{eur(valorado)}</b></div>
              <div className="wd-cubre"><span>Cubierto por la garantía</span><b>−{eur(valorado)}</b></div>
              <div className="big"><span>Total cliente</span><b className="wd-free">0,00 €</b></div>
            </>
          )}
          <div className="wd-tot-acts">
            <button className="btn ghost" onClick={cerrar}>Cancelar</button>
            <button className="btn" disabled={guardando} onClick={guardar}>{guardando ? 'Guardando…' : 'Guardar'}</button>
          </div>
        </div>
      </div>
    </section>
  )
}

/* --- Subir un documento hecho fuera, o editar sus datos --- */
function Subir({ w, opts, doc, onClose, onDone }) {
  const toast = useToast()
  const input = useRef(null)
  const [file, setFile] = useState(null)
  const [encima, setEncima] = useState(false)
  const [f, setF] = useState(() => ({ kind: doc?.kind || 'presupuesto', coverage: doc?.coverage || '', number: doc?.number || '', total: doc?.total ?? '', status: doc?.status || '', note: doc?.note || '' }))
  const [ocupado, setOcupado] = useState(false)
  const set = (c) => setF((s) => ({ ...s, ...c }))
  const estados = opts.documents.kinds[f.kind]?.statuses || []
  const guardar = async () => {
    setOcupado(true)
    const datos = { ...f, total: String(f.total ?? '') }
    const r = doc ? await api.warrantyDocsDo('save', { id: doc.id, ...datos }) : await api.uploadWarrantyDoc({ id: w.id, ...datos }, file)
    setOcupado(false)
    if (!r.ok) { toast(r.error, 'err'); return }
    toast(doc ? 'Documento guardado' : 'Documento subido'); onDone()
  }
  return (
    <Modal title={doc ? `Editar ${doc.file_name || 'documento'}` : 'Subir documento'} onClose={onClose} foot={<>
      <button className="btn ghost" onClick={onClose}>Cancelar</button>
      <button className="btn" disabled={ocupado || (!doc && !file)} onClick={guardar}>{ocupado ? 'Guardando…' : doc ? 'Guardar' : 'Subir'}</button>
    </>}>
      {!doc && (
        <div className={`wd-drop${encima ? ' over' : ''}${file ? ' has' : ''}`} onClick={() => input.current?.click()}
          onDragOver={(e) => { e.preventDefault(); setEncima(true) }} onDragLeave={() => setEncima(false)}
          onDrop={(e) => { e.preventDefault(); setEncima(false); if (e.dataTransfer.files?.[0]) setFile(e.dataTransfer.files[0]) }}>
          <Icon.upload />
          {file ? <span><b>{file.name}</b> <span className="dev-mute">{peso(file.size)}</span></span> : <span>Arrastra el fichero o haz clic <small className="dev-mute">PDF, imagen, Excel, Word… hasta 20 MB</small></span>}
          <input ref={input} type="file" hidden accept=".pdf,.jpg,.jpeg,.png,.webp,.xlsx,.xls,.docx,.doc,.csv,.txt,.zip" onChange={(e) => { const x = e.target.files?.[0]; e.target.value = ''; if (x) setFile(x) }} />
        </div>
      )}
      <div className="grid2">
        <div className="field"><span className="lbl">Tipo</span>
          <Select block value={f.kind} onChange={(v) => set({ kind: v, status: '' })} options={SUBIBLES.map((k) => ({ value: k, label: opts.documents.kinds[k].label }))} /></div>
        <div className="field"><span className="lbl">Quién paga <span className="hint">— opcional</span></span>
          <Select block value={f.coverage} onChange={(v) => set({ coverage: v })} options={[{ value: '', label: 'Sin indicar' }, ...Object.entries(opts.review.coverages).map(([k, l]) => ({ value: k, label: l }))]} /></div>
        <div className="field"><span className="lbl">Número</span><input value={f.number} maxLength={40} onChange={(e) => set({ number: e.target.value })} placeholder="El del ERP, si tiene" /></div>
        <div className="field"><span className="lbl">Importe total <span className="hint">— con IVA</span></span><input inputMode="decimal" value={f.total} onChange={(e) => set({ total: e.target.value })} placeholder="0,00" /></div>
      </div>
      {estados.length ? (
        <div className="field"><span className="lbl">Estado</span>
          <Select block value={f.status || estados[0]} onChange={(v) => set({ status: v })} options={estados.map((s) => ({ value: s, label: opts.documents.statuses[s] }))} /></div>
      ) : null}
      <div className="field" style={{ marginBottom: 0 }}><span className="lbl">Nota</span><textarea rows={2} value={f.note} maxLength={5000} onChange={(e) => set({ note: e.target.value })} /></div>
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
    descargar({ id: r.id }, toast)
    onDone()
  }
  return (
    <Modal title="Informe de resolución" onClose={onClose} foot={<>
      <button className="btn ghost" onClick={onClose}>Cancelar</button>
      <button className="btn" disabled={ocupado || summary.pending > 0} onClick={generar}>{ocupado ? 'Generando…' : actual ? 'Generar de nuevo' : 'Generar informe'}</button>
    </>}>
      <p className="wd-tip">El PDF con el resultado de cada dispositivo, quién paga y los documentos emitidos. Se guarda tal cual queda; si cambia algo, se genera otra vez{actual ? <> y sustituye a <b>{actual.number}</b> (mismo número)</> : null}.</p>
      {summary.pending ? <p className="wd-warn"><Icon.warn /> Quedan {summary.pending} {summary.pending === 1 ? 'dispositivo' : 'dispositivos'} sin revisar: el informe se genera con todo revisado.</p> : null}
      <label className="wr-check"><input type="checkbox" checked={fotos} onChange={(e) => setFotos(e.target.checked)} /> Incluir las fotos de los dispositivos</label>
    </Modal>
  )
}

/* --- Validar para el portal: lo que el portal pide para darlo de alta y cómo quedaría.
 *     Solo comprueba; crear el documento en el portal aún no está activado. --- */
function Portal({ doc, opts, onClose, onEditWarranty }) {
  const toast = useToast()
  const [r, setR] = useState(null)
  const validar = useCallback(() => { setR(null); api.warrantyDocsDo('portal_check', { id: doc.id }).then((x) => (x.ok ? setR(x) : (toast(x.error, 'err'), onClose()))) }, [doc.id])
  useEffect(() => { validar() }, [validar])
  const p = r?.preview
  const malos = r ? r.checks.filter((c) => c.tone === 'bad').length : 0
  return (
    <Modal wide title={<>Validar para el portal <span className="mono">{doc.number}</span> <CovPill c={doc.coverage} opts={opts} /></>} onClose={onClose} foot={<>
      <button className="dev-btn" onClick={validar} disabled={!r}><Icon.refresh /> Volver a validar</button>
      <span className="spacer" />
      <button className="btn ghost" onClick={onClose}>Cerrar</button>
      <button className="btn" disabled title={r?.enabled ? '' : 'La creación en el portal todavía no está activada'}><Icon.send /> Crear en el portal</button>
    </>}>
      {!r ? <div className="center-load"><div className="spinner" /></div> : (
        <>
          <div className={`wd-pv-st ${r.ready ? 'ok' : 'bad'}`}>
            {r.ready ? <Icon.check /> : <Icon.warn />}
            <span>{r.ready ? 'Listo para crearlo en el portal.' : `Hay ${malos} ${malos === 1 ? 'cosa' : 'cosas'} que ${malos === 1 ? 'impide' : 'impiden'} crearlo en el portal.`}
              <small>La creación en el portal todavía no está activada: esto solo comprueba.</small></span>
          </div>
          <ul className="wd-pv-checks">
            {r.checks.map((c, i) => <li key={i} className={`t-${c.tone}`}>{c.tone === 'ok' ? <Icon.check /> : <Icon.warn />}<span>{c.text}</span></li>)}
          </ul>
          {r.checks.some((c) => c.tone === 'bad' && /cliente del portal|proyecto/.test(c.text)) && onEditWarranty
            ? <button className="dev-btn wd-pv-fix" onClick={onEditWarranty}><Icon.pencil /> Editar la garantía para enlazar cliente y proyecto</button> : null}
          {p ? (
            <div className="wd-pv">
              <div className="wd-pv-h">
                <div><small>Se creará como</small><b>{p.type}</b><span className="dev-mute">{p.prefix}-…… (lo numera el portal) · borrador</span></div>
                <div><small>Cliente</small><b>{p.client || <span className="wf-warn">Sin enlazar</span>}</b><span className="dev-mute">{p.project || 'Sin proyecto'}</span></div>
                <div><small>Fecha</small><b>{fecha(p.date)}</b></div>
              </div>
              <div className="dev-table-wrap keep wt-wrap">
                <table className="dev-table wd-pv-t">
                  <thead><tr><th>Ref.</th><th>Nombre / descripción</th><th className="r">Cant.</th><th>Unidad</th>{p.prices ? <><th className="r">Precio</th><th className="r">Total</th></> : null}</tr></thead>
                  <tbody>
                    {p.lines.map((l, i) => (
                      <tr key={i} className={l.found ? '' : 'libre'}>
                        <td className="mono">{l.ref || <span className="dev-mute">Libre</span>}</td>
                        <td><b>{l.name || l.description}</b>{l.name && l.name !== l.description ? <small>{l.description}</small> : null}</td>
                        <td className="r">{String(l.qty).replace('.', ',')}</td>
                        <td>{l.unit}</td>
                        {p.prices ? <><td className="r">{eur(l.rate)}</td><td className="r">{eur(l.total)}</td></> : null}
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
              {p.prices ? (
                <div className="wd-pv-tot">
                  <span>Subtotal <b>{eur(p.base)}</b></span>
                  {p.tax ? <span>{p.tax} <b>{eur(p.tax_amount)}</b></span> : null}
                  <span className="big">Total <b>{eur(p.total)}</b></span>
                </div>
              ) : null}
              {p.terms ? <p className="wd-pv-obs"><small>Observaciones</small>{p.terms}</p> : null}
            </div>
          ) : null}
        </>
      )}
    </Modal>
  )
}

/* --- Correo de resolución: la plantilla rellenada, para copiar --- */
function Correo({ w, docs, onClose }) {
  const toast = useToast()
  const [m, setM] = useState(null)
  useEffect(() => { api.warrantyResolutionEmail(w.id).then((r) => (r.ok ? setM(r) : (toast(r.error, 'err'), onClose()))) }, [w.id])
  const copiar = async (t, que) => { try { await navigator.clipboard.writeText(t); toast(`${que} copiado`) } catch { toast('No se pudo copiar', 'err') } }
  const adjuntos = docs.filter((d) => d.kind === 'informe' || (d.status !== 'anulado' && d.status !== 'borrador'))
  return (
    <Modal wide title="Correo de resolución" onClose={onClose} foot={<button className="btn" onClick={onClose}>Listo</button>}>
      {!m ? <div className="center-load"><div className="spinner" /></div> : (
        <>
          <p className="dev-mute wd-tip">Rellenado con la plantilla de Configuración. Cópialo en tu correo o en la respuesta del ticket y adjunta los documentos.</p>
          {m.to ? <div className="wd-mail-row"><span>Para</span><b>{m.to}</b><button className="dev-btn" onClick={() => copiar(m.to, 'Correo')}><Icon.copy /> Copiar</button></div> : null}
          <div className="wd-mail-row"><span>Asunto</span><input value={m.subject} onChange={(e) => setM({ ...m, subject: e.target.value })} /><button className="dev-btn" onClick={() => copiar(m.subject, 'Asunto')}><Icon.copy /> Copiar</button></div>
          <textarea className="wd-mail-body" rows={12} value={m.body} onChange={(e) => setM({ ...m, body: e.target.value })} />
          <div className="wd-mail-f">
            <button className="dev-btn" onClick={() => copiar(m.body, 'Texto')}><Icon.copy /> Copiar el texto</button>
            <span className="spacer" />
            {adjuntos.length ? <span className="dev-mute">Adjuntos:</span> : <span className="dev-mute">Sin informe todavía: genéralo para adjuntarlo.</span>}
            {adjuntos.map((d) => <button key={d.id} className="dev-btn" onClick={() => descargar(d, toast)}><Icon.download /> {d.number || d.file_name}</button>)}
          </div>
        </>
      )}
    </Modal>
  )
}

export default function WarrantyDocuments({ w, opts, onGoReview, onEditWarranty }) {
  const toast = useToast()
  const confirm = useConfirm()
  const [d, setD] = useState(null)
  const [modal, setModal] = useState(null)   // {t: 'gen'|'edit'|'up'|'report'|'mail', ...}
  const [filtro, setFiltro] = useState('')
  const cargar = useCallback(() => api.warrantyDocs(w.id).then((r) => setD(r.ok ? r : { error: r.error })), [w.id])
  useEffect(() => { cargar() }, [cargar])

  if (!d || !opts) return <div className="center-load"><div className="spinner" /></div>
  if (d.error) return <div className="dev-error"><Icon.warn /> {d.error}</div>

  const { documents: docs, summary } = d
  const kinds = opts.documents.kinds
  const informe = docs.find((x) => x.kind === 'informe' && x.source === 'generado')
  const cerrar = () => setModal(null)
  const tras = () => { setModal(null); cargar() }

  const estado = async (doc, s) => {
    const r = await api.warrantyDocsDo('status', { id: doc.id, status: s })
    if (r.ok) { toast(`${doc.number || doc.file_name}: ${opts.documents.statuses[s]}`); cargar() } else toast(r.error, 'err')
  }
  const albaran = async (doc) => {
    if (!(await confirm({ title: 'Hacer albarán', message: `¿Crear el albarán con las líneas de ${doc.number}?`, confirmText: 'Crear albarán' }))) return
    const r = await api.warrantyDocsDo('to_albaran', { id: doc.id })
    if (r.ok) { toast(`Albarán ${r.number} en borrador`); cargar(); setModal({ t: 'edit', id: r.id }) } else toast(r.error, 'err')
  }
  const borrar = async (doc) => {
    if (!(await confirm({ title: 'Borrar documento', message: `¿Borrar ${doc.number || doc.file_name}? No se puede deshacer.`, danger: true, confirmText: 'Borrar' }))) return
    const r = await api.warrantyDocsDo('delete', { id: doc.id })
    if (r.ok) { toast('Documento borrado'); cargar() } else toast(r.error, 'err')
  }
  const revisados = summary.total - summary.pending
  const porTipo = docs.reduce((m, x) => ({ ...m, [x.kind]: (m[x.kind] || 0) + 1 }), {})
  const tipos = Object.keys(kinds).filter((k) => porTipo[k])
  const lista = filtro && porTipo[filtro] ? docs.filter((x) => x.kind === filtro) : docs

  return (
    <>
      <div className="wd-resumen">
        {COV_ORDEN.map((c) => {
          const x = summary.coverage[c]
          return (
            <span key={c} className={`wd-rs cov-${c}${!x.items ? ' cero' : ''}`}>
              <i />{opts.review.coverages[c]} <b>{x.items}</b>
              {x.no_price ? <em title="Sin precio en la tarifa (Configuración)">{x.no_price} sin precio</em> : null}
            </span>
          )
        })}
        <span className={`wd-rs rev${summary.pending ? ' pend' : ''}`}>
          Revisión <b>{revisados}/{summary.total}</b>
          {summary.pending ? <button className="wf-link" onClick={onGoReview}>Quedan {summary.pending} sin revisar</button> : null}
        </span>
      </div>

      <div className="wd-acts">
        <button className="btn sm" disabled={!revisados} onClick={() => setModal({ t: 'gen', kind: 'presupuesto' })}><Icon.doc /> Generar presupuestos</button>
        <button className="dev-btn" disabled={!revisados} onClick={() => setModal({ t: 'gen', kind: 'albaran' })}><Icon.list /> Generar albaranes</button>
        <button className="dev-btn" onClick={() => setModal({ t: 'up' })}><Icon.upload /> Subir documento</button>
        <span className="spacer" />
        <button className="dev-btn" disabled={!summary.total} title={summary.pending ? 'Se genera con todo revisado' : ''} onClick={() => setModal({ t: 'report' })}><Icon.file /> Informe de resolución</button>
        <button className="dev-btn" onClick={() => setModal({ t: 'mail' })}><Icon.mail /> Correo de resolución</button>
      </div>

      {tipos.length > 1 ? (
        <div className="dc-seg wd-filtro">
          <button className={!filtro || !porTipo[filtro] ? 'on' : ''} onClick={() => setFiltro('')}>Todos <small>{docs.length}</small></button>
          {tipos.map((k) => <button key={k} className={filtro === k ? 'on' : ''} onClick={() => setFiltro(k)}>{PLURAL[k] || kinds[k].label} <small>{porTipo[k]}</small></button>)}
        </div>
      ) : null}

      {!docs.length ? (
        <div className="act-empty wd-empty"><Icon.file style={{ width: 30, height: 30, fill: 'var(--ink-3)' }} />
          <p>Todavía no hay documentos.{revisados ? ' Genera los presupuestos desde los dispositivos revisados o sube los que tengas.' : ' Revisa los dispositivos para generar presupuestos y albaranes, o sube los que tengas.'}</p></div>
      ) : (
        <div className="dev-card">
          <div className="dev-table-wrap keep wt-wrap">
            <table className="dev-table wd-table">
              <thead><tr><th>Documento</th><th>Estado</th><th className="r">Importe</th><th>Fecha</th><th /></tr></thead>
              <tbody>
                {lista.map((x) => {
                  const k = kinds[x.kind]
                  const generado = x.source === 'generado' && k.generated
                  const editando = modal?.t === 'edit' && modal.id === x.id
                  return (
                    <tr key={x.id} className={`${x.status === 'anulado' ? 'off' : ''}${editando ? ' wd-on' : ''}`}>
                      <td>
                        <div className="wd-doc">
                          <span className={`wd-ic k-${x.kind}`}>{x.kind === 'informe' ? <Icon.file /> : x.kind === 'albaran' ? <Icon.list /> : <Icon.doc />}</span>
                          <div>
                            <b>{k.label}{x.number ? <> <span className="mono">{x.number}</span></> : null}{x.coverage ? <> <CovPill c={x.coverage} opts={opts} /></> : null}</b>
                            <small className="dev-mute">
                              {x.source === 'subido' ? <>Subido · {x.file_name} {peso(x.size)}</> : x.kind === 'informe' ? <>Generado{x.note ? ` · ${x.note.toLowerCase()}` : ''}</> : <>{x.lines_count} {x.lines_count === 1 ? 'línea' : 'líneas'}{x.from_number ? ` · desde ${x.from_number}` : ''}</>}
                            </small>
                          </div>
                        </div>
                      </td>
                      <td>{k.statuses.length ? <Select sm value={x.status} onChange={(s) => estado(x, s)} options={k.statuses.map((s) => ({ value: s, label: opts.documents.statuses[s] }))} /> : <span className="dev-mute">—</span>}</td>
                      <td className="r"><b>{x.coverage === 'garantia' && generado ? <span className="wd-free">Sin cargo</span> : eur(x.total)}</b></td>
                      <td><span title={x.created_by_name || ''}>{fecha(x.kind === 'informe' ? x.updated_at : x.created_at)}</span></td>
                      <td className="wd-row-acts">
                        {generado ? <button className={`icon-btn${editando ? ' wd-editing' : ''}`} title={editando ? 'Editando: las líneas están debajo' : 'Editar líneas'} onClick={() => setModal({ t: 'edit', id: x.id })}><Icon.pencil /></button> : null}
                        {x.source === 'subido' ? <button className="icon-btn" title="Editar datos" onClick={() => setModal({ t: 'up', doc: x })}><Icon.pencil /></button> : null}
                        {generado && PORTAL.includes(x.kind) ? <button className="icon-btn" title="Validar para el portal" onClick={() => setModal({ t: 'portal', doc: x })}><Icon.globe /></button> : null}
                        <button className="icon-btn" title="Descargar" onClick={() => descargar(x, toast)}><Icon.download /></button>
                        {generado && x.kind === 'presupuesto' && !['rechazado', 'anulado'].includes(x.status) ? <button className="icon-btn" title="Hacer albarán" onClick={() => albaran(x)}><Icon.arrowRight /></button> : null}
                        <button className="icon-btn" title={generado && !['borrador', 'anulado'].includes(x.status) ? 'Anúlalo para poder borrarlo' : 'Borrar'} disabled={generado && !['borrador', 'anulado'].includes(x.status)} onClick={() => borrar(x)}><Icon.trash /></button>
                      </td>
                    </tr>
                  )
                })}
              </tbody>
            </table>
          </div>
        </div>
      )}

      {modal?.t === 'gen' && <Generar w={w} kind={modal.kind} opts={opts} summary={summary} docs={docs} onClose={cerrar}
        onDone={(ids) => { cargar(); setModal(ids.length === 1 ? { t: 'edit', id: ids[0] } : null) }} />}
      {modal?.t === 'edit' && <Editor key={modal.id} id={modal.id} opts={opts} services={d.services || []} onClose={() => { cerrar(); cargar() }} onSaved={tras} />}
      {modal?.t === 'up' && <Subir w={w} opts={opts} doc={modal.doc} onClose={cerrar} onDone={tras} />}
      {modal?.t === 'report' && <Informe w={w} summary={summary} actual={informe} onClose={cerrar} onDone={tras} />}
      {modal?.t === 'mail' && <Correo w={w} docs={docs} onClose={cerrar} />}
      {modal?.t === 'portal' && <Portal doc={modal.doc} opts={opts} onClose={cerrar} onEditWarranty={onEditWarranty ? () => { cerrar(); onEditWarranty() } : null} />}
    </>
  )
}
