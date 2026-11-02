import { useState, useEffect, useCallback } from 'react'
import { api } from '../api.js'
import { Icon } from '../icons.jsx'
import { useToast, useConfirm } from '../App.jsx'
import Select from './Select.jsx'

/* -------------------------------------------------------------------------
 * ZKONG (Gestión de garantías). Bandeja: lo revisado con «Enviar a ZKONG» que aún no
 * va en ninguna remesa, por expediente y con cuánto lleva esperando. Remesas (R-AAAA-NNN):
 * borrador → enviada (Excel del RMA y ZIP de fotos) → respondida (ZKONG contesta por
 * etiqueta: reposición, abono o rechazada) → cerrada. Las etiquetas que salen de un
 * borrador vuelven a la bandeja.
 * ---------------------------------------------------------------------- */

const ESTADO_CLS = { borrador: 'gray', enviada: 'blue', recibida: 'indigo', respondida: 'purple', cerrada: 'green', anulada: 'red' }
const RESULT_CLS = { reposicion: 'green', abono: 'indigo', rechazada: 'red' }
const PASOS = ['borrador', 'enviada', 'recibida', 'respondida', 'cerrada']
const hoyISO = () => new Date().toLocaleDateString('sv-SE')
const aISO = (v) => (v ? String(v).slice(0, 10) : hoyISO())

const aFecha = (v) => (v ? new Date(String(v).replace(' ', 'T')) : null)
const fecha = (v) => { const d = aFecha(v); return d && !isNaN(d) ? d.toLocaleDateString('es-ES', { day: '2-digit', month: '2-digit', year: 'numeric' }) : '—' }
const dias = (v) => { const d = aFecha(v); return d ? Math.max(0, Math.floor((Date.now() - d) / 86400000)) : null }
const tono = (n) => (n == null ? '' : n >= 30 ? 'bad' : n >= 15 ? 'warn' : '')
const plural = (n, uno, varios) => `${n} ${n === 1 ? uno : varios}`
const sinFotos = (i) => i.photos.length < i.photos_total

function Pill({ cls, children }) { return <span className={`wr-pill ${cls}`}><i />{children}</span> }

const descargar = async (kind, id, toast) => {
  const r = await api.zkongDownload(kind, id)
  if (!r.ok) { toast(r.error, 'err'); return }
  const a = document.createElement('a')
  a.href = URL.createObjectURL(r.blob); a.download = r.filename; a.click()
  setTimeout(() => URL.revokeObjectURL(a.href), 1000)
}

/* Miniaturas de las fotos del dispositivo, con «x/y» si faltan. */
function Fotos({ it }) {
  return (
    <span className={`zk-fotos${sinFotos(it) ? ' falta' : ''}`} title={sinFotos(it) ? 'Faltan fotos: ZKONG las pide' : 'Todas las fotos'}>
      {it.photos.map((p) => <a key={p.id} href={api.warrantyPhotoUrl(p.id)} target="_blank" rel="noreferrer"><img src={api.warrantyPhotoUrl(p.id)} alt="" loading="lazy" /></a>)}
      <em><Icon.image /> {it.photos.length}/{it.photos_total}</em>
    </span>
  )
}

/* Filas de dispositivos (en la bandeja, desplegadas bajo su expediente; en la remesa, todas). */
function Dispositivo({ it, sel, onPick, rv, extra }) {
  return (
    <div className={`zk-item${sel ? ' sel' : ''}`}>
      {onPick ? <input type="checkbox" checked={sel} onChange={onPick} aria-label={`Seleccionar ${it.code}`} /> : null}
      <span className="mono zk-sn">{it.code}{it.kind === 'ap' ? <em className="wr-kind">Antena</em> : null}</span>
      <span className="zk-model" title={it.product_name || ''}>{it.model || <span className="dev-mute">Sin modelo</span>}</span>
      <span className="zk-issue" title={it.issue || ''}>{it.issue || <span className="dev-mute">Sin fallo descrito</span>}</span>
      <Fotos it={it} />
      {extra}
      {rv && it.resolution !== 'defectuosa' ? <span className="zk-tag">{rv.resolutions?.[it.resolution]?.label}</span> : null}
    </div>
  )
}

/* --- Bandeja --- */
function Bandeja({ rv, onOpenWarranty, onBatch }) {
  const toast = useToast()
  const confirm = useConfirm()
  const [d, setD] = useState(null)
  const [orden, setOrden] = useState('old')
  const [abiertos, setAbiertos] = useState(() => new Set())
  const [sel, setSel] = useState(() => new Set())
  const [ocupado, setOcupado] = useState(false)

  const cargar = useCallback(() => api.zkongInbox().then((r) => setD(r.ok ? r : { groups: [], undecided: [], drafts: [], error: r.error })), [])
  useEffect(() => { cargar() }, [cargar])

  if (!d) return <div className="center-load"><div className="spinner" /></div>

  const grupos = [...d.groups].sort((a, b) => orden === 'n' ? b.items.length - a.items.length
    : orden === 'new' ? String(b.queued_at).localeCompare(String(a.queued_at)) : String(a.queued_at).localeCompare(String(b.queued_at)))
  const etiquetas = d.groups.reduce((n, g) => n + g.items.length, 0)
  const maxDias = d.groups.reduce((m, g) => Math.max(m, dias(g.queued_at) ?? 0), 0)
  const elegidas = d.groups.flatMap((g) => g.items).filter((i) => sel.has(i.id))
  const sinFotosSel = elegidas.filter(sinFotos).length

  const toggle = (set, id) => set((s) => { const n = new Set(s); n.has(id) ? n.delete(id) : n.add(id); return n })
  const marcarGrupo = (g) => setSel((s) => {
    const n = new Set(s); const todas = g.items.every((i) => n.has(i.id))
    g.items.forEach((i) => (todas ? n.delete(i.id) : n.add(i.id))); return n
  })
  const todasSel = etiquetas > 0 && elegidas.length === etiquetas
  const marcarTodas = () => setSel(todasSel ? new Set() : new Set(d.groups.flatMap((g) => g.items.map((i) => i.id))))

  const meter = async (batchId) => {
    if (sinFotosSel && !(await confirm({ title: 'Faltan fotos', message: `${plural(sinFotosSel, 'etiqueta no tiene', 'etiquetas no tienen')} todas las fotos y ZKONG las pide. ¿Seguir igualmente? Se pueden subir después, antes de enviar la remesa.`, confirmText: 'Seguir' }))) return
    setOcupado(true)
    const r = batchId ? await api.zkongRma('add', { id: batchId, item_ids: [...sel] }) : await api.zkongRma('create', { item_ids: [...sel] })
    setOcupado(false)
    if (!r.ok) { toast(r.error || 'Error', 'err'); return }
    toast(batchId ? `${plural(sel.size, 'etiqueta añadida', 'etiquetas añadidas')} a ${r.code}` : `Remesa ${r.code} creada con ${plural(sel.size, 'etiqueta', 'etiquetas')}`)
    setSel(new Set()); onBatch(r.id)
  }

  return (
    <>
      <div className="dev-kpis n3 zk-kpis">
        <button className="dev-kpi k-total" disabled><span className="k"><Icon.shield /> Garantías pendientes</span><span className="v">{d.groups.length}</span><span className="h">con algo para enviar</span></button>
        <button className="dev-kpi k-total" disabled><span className="k"><Icon.tag /> Etiquetas pendientes</span><span className="v">{etiquetas}</span><span className="h">{d.drafts.length ? `${plural(d.drafts.length, 'borrador', 'borradores')} de remesa abiertos` : 'sin remesa todavía'}</span></button>
        <button className={`dev-kpi ${maxDias >= 30 ? 'k-offline' : maxDias >= 15 ? 'k-low_battery' : 'k-online'}`} disabled><span className="k"><Icon.clock /> Antigüedad más alta</span><span className={`v zk-age ${tono(etiquetas ? maxDias : null)}`}>{etiquetas ? plural(maxDias, 'día', 'días') : '—'}</span><span className="h">{maxDias >= 30 ? 'Conviene mandar ya una remesa' : 'desde que entró en la bandeja'}</span></button>
      </div>

      {d.undecided.length > 0 && (
        <div className="zk-aviso">
          <Icon.warn />
          <div>
            <b>{plural(d.undecided.reduce((n, u) => n + Number(u.n), 0), 'dispositivo pendiente', 'dispositivos pendientes')} de decidir si va a ZKONG</b>
            <p>No entran en la bandeja hasta que alguien marque «Enviar a ZKONG» en su revisión.</p>
            <div className="zk-links">{d.undecided.map((u) => <button key={u.warranty_id} className="link" onClick={() => onOpenWarranty(u.warranty_id)}>{u.code} ({u.n})</button>)}</div>
          </div>
        </div>
      )}

      <div className="zk-orden">
        <span className="dev-mute">Ordenar:</span>
        <div className="wr-chips sm">
          {[['old', 'Más antigua'], ['new', 'Más reciente'], ['n', 'Nº etiquetas']].map(([k, l]) => <button key={k} className={orden === k ? 'on' : ''} onClick={() => setOrden(k)}>{l}</button>)}
        </div>
      </div>

      <div className="dev-card">
        <div className="dev-table-wrap keep">
          <table className="dev-table zk-table">
            <thead><tr>
              <th className="zk-ck"><input type="checkbox" checked={todasSel} disabled={!etiquetas} onChange={marcarTodas} aria-label="Seleccionar todas" /></th>
              <th>Expediente</th><th>Cliente</th><th>Etiquetas</th><th>Entrada en bandeja</th><th>Días pendiente</th><th className="r">Acción</th>
            </tr></thead>
            <tbody>
              {grupos.map((g) => {
                const n = dias(g.queued_at)
                const marcadas = g.items.filter((i) => sel.has(i.id)).length
                const abierto = abiertos.has(g.warranty_id)
                const faltan = g.items.filter(sinFotos).length
                return [
                  <tr key={g.warranty_id} className={abierto ? 'zk-open' : ''}>
                    <td className="zk-ck"><input type="checkbox" checked={marcadas === g.items.length} ref={(el) => { if (el) el.indeterminate = marcadas > 0 && marcadas < g.items.length }} onChange={() => marcarGrupo(g)} aria-label={`Seleccionar ${g.code}`} /></td>
                    <td><button className="link wr-code" onClick={() => onOpenWarranty(g.warranty_id)}>{g.code}</button></td>
                    <td className="wr-ell">{g.client_name || <span className="dev-mute">—</span>}{g.project_name ? <span className="dev-mute"> · {g.project_name}</span> : null}</td>
                    <td><span className="zk-count"><Icon.tag /> {g.items.length}</span>{faltan ? <span className="zk-falta" title="Sin todas las fotos"><Icon.image /> {faltan}</span> : null}</td>
                    <td>{fecha(g.queued_at)}</td>
                    <td><b className={`zk-days ${tono(n)}`}>{n ?? '—'}d</b></td>
                    <td className="r"><button className="dev-btn" onClick={() => toggle(setAbiertos, g.warranty_id)}><Icon.eye /> {abierto ? 'Ocultar' : 'Ver etiquetas'}</button></td>
                  </tr>,
                  abierto && (
                    <tr key={`${g.warranty_id}-d`} className="zk-det"><td colSpan={7}>
                      {g.items.map((i) => <Dispositivo key={i.id} it={i} rv={rv} sel={sel.has(i.id)} onPick={() => toggle(setSel, i.id)} />)}
                    </td></tr>
                  ),
                ]
              })}
            </tbody>
          </table>
          {!grupos.length && (
            <div className="act-empty"><Icon.zkong style={{ width: 30, height: 30, fill: 'var(--ink-3)' }} />
              <p>La bandeja está vacía. Aquí llegan las etiquetas revisadas como «No reproduce el fallo» o «Defectuosa» con «Enviar a ZKONG».</p></div>
          )}
        </div>
      </div>

      {elegidas.length > 0 && (
        <div className="wr-selbar">
          <b>{plural(elegidas.length, 'etiqueta', 'etiquetas')}{sinFotosSel ? <span className="zk-falta"><Icon.image /> {sinFotosSel} sin fotos</span> : null}</b>
          <button className="btn" disabled={ocupado} onClick={() => meter(null)}><Icon.plus /> Crear remesa</button>
          {d.drafts.length > 0 && (
            <Select sm value="" placeholder="Añadir a un borrador…" onChange={(v) => v && meter(v)}
              options={d.drafts.map((b) => ({ value: b.id, label: `Añadir a ${b.code}` }))} />
          )}
          <button className="icon-btn" title="Quitar la selección" onClick={() => setSel(new Set())}>✕</button>
        </div>
      )}
    </>
  )
}

/* Formulario en ventana: envío, recepción, anulación. Todo a mano (no hay API del transportista). */
function Ventana({ titulo, campos, inicial, boton, peligro, onClose, onOk }) {
  const [v, setV] = useState(inicial)
  const [ocupado, setOcupado] = useState(false)
  const set = (k, x) => setV((o) => ({ ...o, [k]: x }))
  const enviar = async () => { setOcupado(true); const ok = await onOk(v); setOcupado(false); if (ok) onClose() }
  return (
    <div className="modal-bg wr-over" onClick={(e) => e.target.classList.contains('modal-bg') && onClose()}>
      <div className="modal wd-modal zk-ventana" onKeyDown={(e) => { if (e.key === 'Escape') onClose() }}>
        <div className="modal-h"><h3>{titulo}</h3><button className="icon-btn" onClick={onClose} title="Cerrar (Esc)">✕</button></div>
        <div className="modal-body">
          <div className="zk-form">
            {campos.map((c) => (
              <label key={c.k} className={`field${c.ancho ? ' ancho' : ''}`}>
                <span className="lbl">{c.l}{c.req ? <b className="zk-req"> *</b> : null}{c.hint ? <span className="hint"> — {c.hint}</span> : null}</span>
                {c.tipo === 'area'
                  ? <textarea rows={3} value={v[c.k] || ''} maxLength={c.max || 500} placeholder={c.ph || ''} onChange={(e) => set(c.k, e.target.value)} />
                  : <input type={c.tipo || 'text'} value={v[c.k] ?? ''} max={c.tipo === 'date' ? hoyISO() : undefined} min={c.min} maxLength={c.max} placeholder={c.ph || ''} onChange={(e) => set(c.k, e.target.value)} />}
              </label>
            ))}
          </div>
        </div>
        <div className="modal-foot">
          <button className="btn ghost" onClick={onClose}>Cancelar</button>
          <button className={`btn${peligro ? ' danger' : ''}`} disabled={ocupado} onClick={enviar}>{ocupado ? 'Guardando…' : boton}</button>
        </div>
      </div>
    </div>
  )
}

const CAMPOS_TRANSPORTE = [
  { k: 'carrier', l: 'Transportista', max: 80, ph: 'DHL, SEUR, TNT…' },
  { k: 'tracking', l: 'Tracking', max: 120 },
  { k: 'shipping_ref', l: 'Referencia / albarán', max: 120, ancho: true },
]

/* --- Remesas --- */
function Remesas({ rv, onOpen }) {
  const [d, setD] = useState(null)
  const [filtro, setFiltro] = useState('')
  useEffect(() => { api.zkongBatches().then((r) => setD(r.ok ? r.batches : [])) }, [])
  if (!d) return <div className="center-load"><div className="spinner" /></div>

  const lista = d.filter((b) => !filtro || (filtro === 'abiertas' ? !['cerrada', 'anulada'].includes(b.status) : b.status === filtro))
  const cuenta = (s) => d.filter((b) => b.status === s).length
  return (
    <>
      <div className="wr-chips">
        <button className={!filtro ? 'on' : ''} onClick={() => setFiltro('')}>Todas ({d.length})</button>
        <button className={filtro === 'abiertas' ? 'on' : ''} onClick={() => setFiltro('abiertas')}>Abiertas ({d.filter((b) => !['cerrada', 'anulada'].includes(b.status)).length})</button>
        {Object.entries(rv?.batch_statuses || {}).map(([k, l]) => cuenta(k) ? <button key={k} className={filtro === k ? 'on' : ''} onClick={() => setFiltro(filtro === k ? '' : k)}>{l} ({cuenta(k)})</button> : null)}
      </div>
      <div className="dev-card">
        <div className="dev-table-wrap keep">
          <table className="dev-table zk-table">
            <thead><tr><th>Remesa</th><th>Estado</th><th>Etiquetas</th><th>Garantías</th><th>Respuesta de ZKONG</th><th>Enviada</th><th>Transportista</th><th>Tracking</th><th>Recibida</th></tr></thead>
            <tbody>
              {lista.map((b) => (
                <tr key={b.id} className="wr-row" onClick={() => onOpen(b.id)}>
                  <td><span className="wr-code">{b.code}</span></td>
                  <td>
                    <Pill cls={ESTADO_CLS[b.status]}>{rv?.batch_statuses?.[b.status] || b.status}</Pill>
                    {b.waiting_days != null ? <span className={`zk-espera ${tono(b.waiting_days)}`} title="Días desde el envío sin que ZKONG confirme la recepción">{plural(b.waiting_days, 'día', 'días')}</span> : null}
                  </td>
                  <td><b>{b.status === 'anulada' ? (b.units_sent ?? '—') : b.items}</b></td>
                  <td>{b.status === 'anulada' ? <span className="dev-mute">—</span> : b.warranties}</td>
                  <td>{['borrador', 'anulada'].includes(b.status) ? <span className="dev-mute">—</span> : (
                    <span className="zk-prog"><span><i style={{ width: `${b.items ? (b.answered / b.items) * 100 : 0}%` }} /></span>{b.answered}/{b.items}{b.rejected ? <em>{b.rejected} rechazadas</em> : null}</span>
                  )}</td>
                  <td>{b.sent_at ? fecha(b.sent_at) : <span className="dev-mute">—</span>}</td>
                  <td>{b.carrier || <span className="dev-mute">—</span>}</td>
                  <td>{b.tracking ? <span className="mono">{b.tracking}</span> : <span className="dev-mute">—</span>}</td>
                  <td>{b.received_at ? fecha(b.received_at) : <span className="dev-mute">—</span>}</td>
                </tr>
              ))}
            </tbody>
          </table>
          {!lista.length && (
            <div className="act-empty"><Icon.list style={{ width: 30, height: 30, fill: 'var(--ink-3)' }} />
              <p>{d.length ? 'Ninguna remesa con este filtro.' : 'Todavía no hay remesas. Se crean desde la bandeja, eligiendo las etiquetas que se mandan juntas.'}</p></div>
          )}
        </div>
      </div>
    </>
  )
}

function Remesa({ id, rv, onBack, onOpenWarranty }) {
  const toast = useToast()
  const confirm = useConfirm()
  const [d, setD] = useState(null)
  const [sel, setSel] = useState(() => new Set())
  const [nota, setNota] = useState('')
  const [resNota, setResNota] = useState('')
  const [ocupado, setOcupado] = useState(false)
  const [ventana, setVentana] = useState(null)   // envio | editar | recepcion | anular

  const cargar = useCallback(() => api.zkongBatch(id).then((r) => {
    setD(r.ok ? r : { error: r.error || 'No se pudo cargar' })
    if (r.ok) setNota(r.batch.note || '')
  }), [id])
  useEffect(() => { cargar() }, [cargar])

  if (!d) return <div className="center-load"><div className="spinner" /></div>
  if (d.error) return <><button className="wr-back" onClick={onBack}><Icon.chevron style={{ transform: 'rotate(90deg)' }} /> Remesas</button><div className="dev-error"><Icon.warn /> {d.error}</div></>

  const b = d.batch
  const items = d.items
  const borrador = b.status === 'borrador'
  const conRespuesta = ['enviada', 'recibida', 'respondida'].includes(b.status)
  const faltan = items.filter(sinFotos).length
  const respondidas = items.filter((i) => i.result).length
  const porResultado = {}
  items.forEach((i) => { if (i.result) porResultado[i.result] = (porResultado[i.result] || 0) + 1 })
  const grupos = []
  items.forEach((i) => { const g = grupos.find((x) => x.warranty_id === i.warranty_id); g ? g.items.push(i) : grupos.push({ warranty_id: i.warranty_id, code: i.warranty_code, client_name: i.client_name, items: [i] }) })

  const toggle = (iid) => setSel((s) => { const n = new Set(s); n.has(iid) ? n.delete(iid) : n.add(iid); return n })
  const todas = items.length > 0 && items.every((i) => sel.has(i.id))
  const accion = async (action, payload = {}, msg) => {
    setOcupado(true)
    const r = await api.zkongRma(action, { id: b.id, ...payload })
    setOcupado(false)
    if (!r.ok) { toast(r.error || 'Error', 'err'); return false }
    if (msg) toast(msg)
    return true
  }

  const enviar = () => setVentana('envio')
  const guardarVentana = async (tipo, v) => {
    const ok = tipo === 'envio' ? await accion('send', v, `${b.code} enviada a ZKONG`)
      : tipo === 'editar' ? await accion('shipping', v, 'Envío actualizado')
      : tipo === 'recepcion' ? await accion('receive', v, `${b.code}: recepción de ZKONG apuntada`)
      : await accion('cancel', v, `${b.code} anulada: sus etiquetas vuelven a la bandeja`)
    if (ok) cargar()
    return ok
  }
  const descartar = async () => {
    if (!(await confirm({ title: 'Descartar remesa', message: `¿Descartar ${b.code}? Sus ${plural(items.length, 'etiqueta vuelve', 'etiquetas vuelven')} a la bandeja.`, danger: true, confirmText: 'Descartar' }))) return
    if (await accion('delete', {}, `${b.code} descartada`)) onBack()
  }
  const quitar = async () => {
    if (await accion('remove', { item_ids: [...sel] }, `${plural(sel.size, 'etiqueta vuelve', 'etiquetas vuelven')} a la bandeja`)) { setSel(new Set()); cargar() }
  }
  const responder = async (result) => {
    if (await accion('result', { item_ids: [...sel], result, note: resNota }, result ? `${rv?.zkong_results?.[result]}: ${plural(sel.size, 'etiqueta', 'etiquetas')}` : 'Respuesta quitada')) { setSel(new Set()); setResNota(''); cargar() }
  }
  const guardarNota = () => { if (nota !== (b.note || '')) accion('note', { note: nota }, 'Nota guardada').then((ok) => ok && cargar()) }

  return (
    <>
      <button className="wr-back" onClick={onBack}><Icon.chevron style={{ transform: 'rotate(90deg)' }} /> Remesas</button>
      <div className="wr-hero">
        <div className="wr-hero-top">
          <div className="wr-hero-id">
            <h2>{b.code}</h2>
            <div className="wr-hero-pills"><Pill cls={ESTADO_CLS[b.status]}>{rv?.batch_statuses?.[b.status]}</Pill></div>
            <p>{plural(items.length, 'etiqueta', 'etiquetas')} de {plural(grupos.length, 'garantía', 'garantías')} · creada el {fecha(b.created_at)}{b.created_by_name ? ` por ${b.created_by_name}` : ''}{b.sent_at ? ` · enviada el ${fecha(b.sent_at)}` : ''}</p>
          </div>
          <div className="wr-acts">
            <button className="dev-btn" disabled={!items.length} onClick={() => descargar('xlsx', b.id, toast)}><Icon.download /> Excel RMA</button>
            <button className="dev-btn" disabled={!items.length} onClick={() => descargar('zip', b.id, toast)}><Icon.image /> Fotos (ZIP)</button>
            {borrador && <button className="btn" disabled={ocupado || !items.length} onClick={enviar}><Icon.send /> Registrar envío</button>}
            {b.status === 'enviada' && <button className="btn" disabled={ocupado} onClick={() => setVentana('recepcion')}><Icon.check /> Registrar recepción</button>}
            {conRespuesta && <button className="dev-btn" disabled={ocupado} onClick={() => setVentana('editar')}><Icon.pencil /> Envío</button>}
            {['enviada', 'recibida'].includes(b.status) && <button className="btn ghost zk-anular" disabled={ocupado} onClick={() => setVentana('anular')}>Anular</button>}
            {conRespuesta && <button className="btn dark" disabled={ocupado} onClick={async () => { if (await confirm({ title: 'Cerrar remesa', message: respondidas < items.length ? `Quedan ${items.length - respondidas} sin respuesta de ZKONG. ¿Cerrar igualmente?` : `¿Cerrar ${b.code}?`, confirmText: 'Cerrar remesa' }) && await accion('close', {}, `${b.code} cerrada`)) cargar() }}><Icon.lock /> Cerrar remesa</button>}
            {b.status === 'cerrada' && <button className="btn ghost" disabled={ocupado} onClick={async () => { if (await accion('reopen', {}, `${b.code} reabierta`)) cargar() }}><Icon.refresh /> Reabrir</button>}
            {borrador && <button className="icon-btn" title="Descartar remesa" style={{ color: 'var(--danger)' }} onClick={descartar}><Icon.trash /></button>}
          </div>
        </div>
        {b.status === 'anulada' ? (
          <p className="zk-anulada"><Icon.warn /> Anulada el {fecha(b.cancelled_at)}{b.cancel_reason ? `: ${b.cancel_reason}` : ''}. Sus etiquetas volvieron a la bandeja.</p>
        ) : (
          <ol className="zk-pasos">
            {PASOS.map((p, n) => {
              const actual = PASOS.indexOf(b.status)
              return <li key={p} className={n < actual ? 'hecho' : n === actual ? 'on' : ''}><span>{n < actual ? <Icon.check /> : n + 1}</span>{rv?.batch_statuses?.[p]}</li>
            })}
          </ol>
        )}
        {!borrador && (
          <div className="zk-envio">
            <div><span>Fecha de envío</span><b>{fecha(b.sent_at)}</b></div>
            <div><span>Unidades enviadas</span><b>{b.units_sent ?? '—'}</b></div>
            <div><span>Transportista</span><b>{b.carrier || '—'}</b></div>
            <div><span>Tracking</span><b className="mono">{b.tracking || '—'}</b></div>
            <div><span>Referencia / albarán</span><b>{b.shipping_ref || '—'}</b></div>
            <div><span>Recibida por ZKONG</span><b>{b.received_at ? `${fecha(b.received_at)}${b.units_received != null ? ` · ${plural(b.units_received, 'unidad', 'unidades')}` : ''}` : '—'}</b>
              {b.waiting_days != null ? <em className={`zk-espera ${tono(b.waiting_days)}`}>Esperando confirmación · {plural(b.waiting_days, 'día', 'días')}</em> : null}</div>
          </div>
        )}
        {borrador && faltan > 0 && <p className="zk-falta-txt"><Icon.image /> {plural(faltan, 'etiqueta no tiene', 'etiquetas no tienen')} todas las fotos. Súbelas desde la revisión de su garantía antes de mandar la remesa.</p>}
        {!borrador && items.length > 0 && (
          <div className="zk-resumen">
            <span className="zk-prog big"><span><i style={{ width: `${(respondidas / items.length) * 100}%` }} /></span><b>{respondidas}/{items.length}</b> con respuesta de ZKONG</span>
            {Object.entries(porResultado).map(([k, n]) => <Pill key={k} cls={RESULT_CLS[k]}>{rv?.zkong_results?.[k]} · {n}</Pill>)}
          </div>
        )}
        <textarea className="zk-nota" rows={2} value={nota} onChange={(e) => setNota(e.target.value)} onBlur={guardarNota}
          placeholder="Notas de la remesa: nº de RMA de ZKONG, contacto, incidencias…" />
      </div>

      <div className="wr-panel">
        {items.length > 0 && (borrador || conRespuesta) && (
          <label className="wr-selall"><input type="checkbox" checked={todas} onChange={() => setSel(todas ? new Set() : new Set(items.map((i) => i.id)))} />{todas ? 'Quitar la selección' : 'Seleccionar todas'}</label>
        )}
        {grupos.map((g) => (
          <div key={g.warranty_id} className="zk-grupo">
            <div className="zk-grupo-h"><button className="link wr-code" onClick={() => onOpenWarranty(g.warranty_id)}>{g.code}</button><span className="dev-mute">{g.client_name || ''}</span><span className="dev-mute">· {plural(g.items.length, 'etiqueta', 'etiquetas')}</span></div>
            {g.items.map((i) => (
              <Dispositivo key={i.id} it={i} rv={rv} sel={sel.has(i.id)} onPick={borrador || conRespuesta ? () => toggle(i.id) : null}
                extra={!borrador ? (
                  <span className="zk-res">{i.result ? <Pill cls={RESULT_CLS[i.result]}>{rv?.zkong_results?.[i.result]}</Pill> : <span className="dev-mute">Sin respuesta</span>}{i.result_note ? <small title={i.result_note}>{i.result_note}</small> : null}</span>
                ) : null} />
            ))}
          </div>
        ))}
        {!items.length && <p className="dev-mute">{b.status === 'anulada' ? 'Anulada: sus etiquetas volvieron a la bandeja.' : 'La remesa no tiene etiquetas.'}</p>}
      </div>

      {d.events?.length > 0 && (
        <div className="wr-panel zk-tl">
          <h4 className="ws-sub">Línea de tiempo</h4>
          <ol>
            {d.events.map((e, i) => (
              <li key={i} className={`t-${e.type}`}>
                <b>{e.text}</b>
                <span className="dev-mute">{fecha(e.at)}{e.user_name ? ` · ${e.user_name}` : ''}</span>
                {e.note ? <p>{e.note}</p> : null}
              </li>
            ))}
          </ol>
        </div>
      )}

      {ventana === 'envio' && (
        <Ventana titulo={`Registrar envío · ${b.code}`} boton="Registrar envío" onClose={() => setVentana(null)} onOk={(v) => guardarVentana('envio', v)}
          inicial={{ sent_on: hoyISO(), units_sent: items.length }}
          campos={[{ k: 'sent_on', l: 'Fecha de envío', tipo: 'date', req: true }, { k: 'units_sent', l: 'Unidades enviadas', tipo: 'number', min: 1, req: true },
            ...CAMPOS_TRANSPORTE, { k: 'note', l: 'Observaciones', tipo: 'area', hint: 'opcional', ancho: true }]} />
      )}
      {ventana === 'editar' && (
        <Ventana titulo={`Datos del envío · ${b.code}`} boton="Guardar" onClose={() => setVentana(null)} onOk={(v) => guardarVentana('editar', v)}
          inicial={{ sent_on: aISO(b.sent_at), units_sent: b.units_sent ?? items.length, carrier: b.carrier || '', tracking: b.tracking || '', shipping_ref: b.shipping_ref || '' }}
          campos={[{ k: 'sent_on', l: 'Fecha de envío', tipo: 'date', req: true }, { k: 'units_sent', l: 'Unidades enviadas', tipo: 'number', min: 1, req: true }, ...CAMPOS_TRANSPORTE]} />
      )}
      {ventana === 'recepcion' && (
        <Ventana titulo={`Registrar recepción de ZKONG · ${b.code}`} boton="Registrar recepción" onClose={() => setVentana(null)} onOk={(v) => guardarVentana('recepcion', v)}
          inicial={{ received_on: hoyISO(), units_received: b.units_sent ?? '' }}
          campos={[{ k: 'received_on', l: 'Fecha de recepción', tipo: 'date', req: true, min: aISO(b.sent_at) }, { k: 'units_received', l: 'Unidades recibidas', tipo: 'number', min: 0 },
            { k: 'note', l: 'Observaciones', tipo: 'area', hint: 'opcional', ancho: true, ph: 'Cómo lo confirmó ZKONG (correo, nº de RMA…)' }]} />
      )}
      {ventana === 'anular' && (
        <Ventana titulo={`Anular ${b.code}`} boton="Anular remesa" peligro onClose={() => setVentana(null)} onOk={(v) => guardarVentana('anular', v)}
          inicial={{ reason: '' }}
          campos={[{ k: 'reason', l: 'Motivo de la anulación', tipo: 'area', req: true, ancho: true, ph: 'Paquete perdido, devuelto por el transportista…' }]} />
      )}

      {sel.size > 0 && borrador && (
        <div className="wr-selbar">
          <b>{plural(sel.size, 'seleccionada', 'seleccionadas')}:</b>
          <button className="dev-btn" disabled={ocupado} onClick={quitar}><Icon.x /> Quitar de la remesa</button>
          <button className="icon-btn" title="Quitar la selección" onClick={() => setSel(new Set())}>✕</button>
        </div>
      )}
      {sel.size > 0 && conRespuesta && (
        <div className="wr-selbar">
          <b>{plural(sel.size, 'seleccionada', 'seleccionadas')}:</b>
          <input className="zk-resnota" value={resNota} maxLength={500} onChange={(e) => setResNota(e.target.value)} placeholder="Nota (opcional): nº de abono, motivo del rechazo…" />
          {Object.entries(rv?.zkong_results || {}).map(([k, l]) => <button key={k} className={`wr-res c-${RESULT_CLS[k]}`} disabled={ocupado} onClick={() => responder(k)}><span>{l}</span></button>)}
          <button className="dev-btn" disabled={ocupado} onClick={() => responder('')}>Sin respuesta</button>
          <button className="icon-btn" title="Quitar la selección" onClick={() => setSel(new Set())}>✕</button>
        </div>
      )}
    </>
  )
}

export default function Zkong({ onOpenWarranty }) {
  const [tab, setTab] = useState('bandeja')
  const [remesa, setRemesa] = useState(null)
  const [rv, setRv] = useState(null)
  const [n, setN] = useState({ bandeja: null, abiertas: null })
  const [reload, setReload] = useState(0)

  useEffect(() => { api.warrantyOptions().then((r) => r.ok && setRv(r.review)) }, [])
  // Contadores de las pestañas.
  useEffect(() => {
    Promise.all([api.zkongInbox(), api.zkongBatches()]).then(([a, b]) => setN({
      bandeja: a.ok ? a.groups.reduce((s, g) => s + g.items.length, 0) : null,
      abiertas: b.ok ? b.batches.filter((x) => x.status !== 'cerrada').length : null,
    }))
  }, [tab, remesa, reload])

  const abrirRemesa = (id) => { setTab('remesas'); setRemesa(id) }

  return (
    <>
      <header className="page-head">
        <span className="sc-ic"><Icon.zkong style={{ width: 18, height: 18, fill: '#0d64c0' }} /></span>
        <div><h1>ZKONG</h1></div>
        <span className="sub">· Bandeja de garantías pendientes y remesas de RMA a ZKONG</span>
      </header>
      <div className="page-scroll"><div className="page dev-page wr-page">
        <div className="wr-tabs" role="tablist">
          <button className={tab === 'bandeja' ? 'on' : ''} onClick={() => { setTab('bandeja'); setRemesa(null) }}><Icon.inbox /> Bandeja{n.bandeja ? <em className="zk-n">{n.bandeja}</em> : null}</button>
          <button className={tab === 'remesas' ? 'on' : ''} onClick={() => { setTab('remesas'); setRemesa(null) }}><Icon.list /> Remesas{n.abiertas ? <em className="zk-n">{n.abiertas}</em> : null}</button>
        </div>
        {tab === 'bandeja' && <Bandeja rv={rv} onOpenWarranty={onOpenWarranty} onBatch={abrirRemesa} />}
        {tab === 'remesas' && (remesa
          ? <Remesa key={`${remesa}-${reload}`} id={remesa} rv={rv} onBack={() => { setRemesa(null); setReload((x) => x + 1) }} onOpenWarranty={onOpenWarranty} />
          : <Remesas key={reload} rv={rv} onOpen={setRemesa} />)}
      </div></div>
    </>
  )
}
