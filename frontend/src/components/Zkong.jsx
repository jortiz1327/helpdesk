import { useState, useEffect, useCallback, useRef } from 'react'
import { createPortal } from 'react-dom'
import { api } from '../api.js'
import { csvCell } from '../csv.js'
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
const num = (n) => Number(n || 0).toLocaleString('es-ES')
const dec = (n) => (n == null ? '—' : String(n).replace('.', ','))
const plural = (n, uno, varios) => `${n} ${n === 1 ? uno : varios}`
const eur = (n) => (n == null ? '—' : Number(n).toLocaleString('es-ES', { style: 'currency', currency: 'EUR', minimumFractionDigits: 2 }))
const minus = (t) => t.charAt(0).toLowerCase() + t.slice(1)   // «Recibida por ZKONG» → «recibida por ZKONG»
const VUELTA = ['recibida', 'respondida', 'cerrada']   // ZKONG ya la tiene: puede haber reposiciones de vuelta

/* Lo que vale lo enviado: recuperado (reposición o abono), asumido (rechazadas) y lo que
   aún no tiene respuesta. Coste del portal (precio de coste o, si falta, el del proveedor). */
function Costes({ c, compacto }) {
  if (!c) return null
  const base = c.recovered + c.assumed + c.pending
  const w = (v) => (base ? `${(v / base) * 100}%` : '0%')
  const avisos = [c.no_cost ? `${plural(c.no_cost, 'etiqueta', 'etiquetas')} sin coste en el portal` : null,
    c.estimated ? `${c.estimated} con el coste del proveedor ($ a 1,04 €)` : null].filter(Boolean)
  if (compacto) {
    return base ? (
      <div className="zk-cst-c" title={`Recuperado ${eur(c.recovered)} · Asumido ${eur(c.assumed)} · Sin respuesta ${eur(c.pending)}${avisos.length ? `\n${avisos.join(' · ')}` : ''}`}>
        <span className="zk-cst-bar"><i className="rec" style={{ width: w(c.recovered) }} /><i className="asu" style={{ width: w(c.assumed) }} /></span>
        <small><b className="rec">{eur(c.recovered)}</b> · <b className="asu">{eur(c.assumed)}</b></small>
      </div>
    ) : <span className="dev-mute" title={avisos.join(' · ') || undefined}>{c.no_cost ? 'Sin coste' : '—'}</span>
  }
  return (
    <div className="zk-cst">
      <dl className="zk-cst-n">
        <div className="rec"><dt>Recuperado</dt><dd><b>{eur(c.recovered)}</b><small>Reposiciones y abonos</small></dd></div>
        <div className="asu"><dt>Asumido</dt><dd><b>{eur(c.assumed)}</b><small>Rechazadas por ZKONG</small></dd></div>
        <div className="pen"><dt>Sin respuesta aún</dt><dd><b>{eur(c.pending)}</b><small>De {eur(c.total)} enviados</small></dd></div>
      </dl>
      {base ? <span className="zk-cst-bar big" role="img" aria-label={`Recuperado ${eur(c.recovered)}, asumido ${eur(c.assumed)}, sin respuesta ${eur(c.pending)}`}>
        <i className="rec" style={{ width: w(c.recovered) }} /><i className="asu" style={{ width: w(c.assumed) }} /></span> : null}
      {avisos.length ? <p className="zk-cst-av"><Icon.warn /> {avisos.join('; ')}. Coste de compra del portal, sin portes.</p> : <p className="zk-cst-av mute">Coste de compra del portal, sin portes.</p>}
    </div>
  )
}

/* «⋯» de la cabecera de la remesa: lo que se usa poco o no tiene vuelta atrás, para que
   arriba quede una sola acción principal (como en la ficha de garantía). */
function MasAcciones({ code, items }) {
  const [pos, setPos] = useState(null)
  const btn = useRef(null)
  const menu = useRef(null)
  useEffect(() => {
    if (!pos) return
    menu.current?.querySelector('[role="menuitem"]')?.focus()
    const fuera = (e) => { if (!menu.current?.contains(e.target) && !btn.current?.contains(e.target)) setPos(null) }
    const tecla = (e) => {
      if (e.key === 'Escape') { e.stopPropagation(); setPos(null); btn.current?.focus() }
      if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
        e.preventDefault()
        const l = [...menu.current.querySelectorAll('[role="menuitem"]')]
        const i = l.indexOf(document.activeElement)
        l[(i + (e.key === 'ArrowDown' ? 1 : -1) + l.length) % l.length]?.focus()
      }
    }
    const cerrar = () => setPos(null)
    document.addEventListener('mousedown', fuera)
    document.addEventListener('keydown', tecla)
    window.addEventListener('scroll', cerrar, true)
    window.addEventListener('resize', cerrar)
    return () => {
      document.removeEventListener('mousedown', fuera)
      document.removeEventListener('keydown', tecla)
      window.removeEventListener('scroll', cerrar, true)
      window.removeEventListener('resize', cerrar)
    }
  }, [pos])
  const lista = items.filter(Boolean)
  if (!lista.length) return null
  const abrir = () => {
    if (pos) { setPos(null); return }
    const r = btn.current.getBoundingClientRect()
    const right = Math.max(8, window.innerWidth - r.right)
    setPos(r.bottom + 60 * lista.length + 20 > window.innerHeight ? { bottom: window.innerHeight - r.top + 4, right } : { top: r.bottom + 4, right })
  }
  return (
    <>
      <button ref={btn} type="button" className={`icon-btn wf-more ${pos ? 'on' : ''}`} onClick={abrir}
        title="Más acciones" aria-label="Más acciones" aria-haspopup="menu" aria-expanded={!!pos}><Icon.dots /></button>
      {pos && createPortal(
        <div ref={menu} className="dev-acc-menu wf-more-menu" role="menu" aria-label={`Acciones de ${code}`} style={pos}>
          {lista.map((a) => (
            <button key={a.b} role="menuitem" className={a.bad ? 'bad' : ''} disabled={a.dis} onClick={() => { setPos(null); a.fn() }}>
              {a.icon}<span><b>{a.b}</b>{a.s ? <small>{a.s}</small> : null}</span>
            </button>
          ))}
        </div>,
        document.body,
      )}
    </>
  )
}
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

/* --- Bandeja --- */
// Una sola tabla: cada garantía es una cabecera con sus etiquetas debajo (las 5 primeras;
// «Ver las N restantes» despliega el resto). Elegir, buscar y crear la remesa en una barra.
const VISIBLES = 5
const ORDENES = [{ value: 'old', label: 'Más antigua primero' }, { value: 'new', label: 'Más reciente primero' }, { value: 'n', label: 'Más etiquetas primero' }]

function Bandeja({ rv, puede, onOpenWarranty, onBatch }) {
  const toast = useToast()
  const confirm = useConfirm()
  const [d, setD] = useState(null)
  const [orden, setOrden] = useState('old')
  const [q, setQ] = useState('')
  const [plegados, setPlegados] = useState(() => new Set())
  const [enteros, setEnteros] = useState(() => new Set())
  const [sel, setSel] = useState(() => new Set())
  const [ocupado, setOcupado] = useState(false)

  const cargar = useCallback(() => api.zkongInbox().then((r) => setD(r.ok ? r : { groups: [], undecided: [], drafts: [], error: r.error })), [])
  useEffect(() => { cargar() }, [cargar])

  if (!d) return <div className="center-load"><div className="spinner" /></div>

  const busca = q.trim().toLowerCase()
  const coincide = (g, i) => !busca || [g.code, g.client_name, g.project_name, i.code, i.model, i.product_name, i.issue].some((v) => String(v || '').toLowerCase().includes(busca))
  const grupos = d.groups
    .map((g) => ({ ...g, vistos: g.items.filter((i) => coincide(g, i)) }))
    .filter((g) => g.vistos.length)
    .sort((a, b) => orden === 'n' ? b.items.length - a.items.length
      : orden === 'new' ? String(b.queued_at).localeCompare(String(a.queued_at)) : String(a.queued_at).localeCompare(String(b.queued_at)))
  const todos = d.groups.flatMap((g) => g.items)
  const etiquetas = todos.length
  const maxDias = d.groups.reduce((m, g) => Math.max(m, dias(g.queued_at) ?? 0), 0)
  const sinFotosTot = todos.filter(sinFotos).length
  const elegidas = todos.filter((i) => sel.has(i.id))
  const sinFotosSel = elegidas.filter(sinFotos).length
  const gruposSel = new Set(elegidas.map((i) => i.warranty_id)).size
  const vistas = grupos.flatMap((g) => g.vistos)
  const todasSel = vistas.length > 0 && vistas.every((i) => sel.has(i.id))

  const toggle = (set, id) => set((s) => { const n = new Set(s); n.has(id) ? n.delete(id) : n.add(id); return n })
  const marcar = (items) => setSel((s) => {
    const n = new Set(s); const todas = items.every((i) => n.has(i.id))
    items.forEach((i) => (todas ? n.delete(i.id) : n.add(i.id))); return n
  })

  const meter = async (batchId) => {
    if (sinFotosSel && !(await confirm({ title: 'Faltan fotos', message: `${plural(sinFotosSel, 'etiqueta no tiene', 'etiquetas no tienen')} todas las fotos y ZKONG las pide. ¿Seguir igualmente? Se pueden subir después, antes de enviar la remesa.`, confirmText: 'Seguir' }))) return
    setOcupado(true)
    const r = batchId ? await api.zkongRma('add', { id: batchId, item_ids: [...sel] }) : await api.zkongRma('create', { item_ids: [...sel] })
    setOcupado(false)
    if (!r.ok) { toast(r.error || 'Error', 'err'); return }
    toast(batchId ? `${plural(sel.size, 'etiqueta añadida', 'etiquetas añadidas')} a ${r.code}` : `Remesa ${r.code} creada con ${plural(sel.size, 'etiqueta', 'etiquetas')}`)
    setSel(new Set()); onBatch(r.id)
  }

  const exportar = () => {
    const esc = csvCell
    const filas = [['Garantía', 'Cliente', 'Proyecto', 'Nº de serie', 'Tipo', 'Modelo', 'Producto', 'Fallo', 'Fotos', 'En la bandeja desde', 'Días']]
    d.groups.forEach((g) => g.items.forEach((i) => filas.push([g.code, g.client_name, g.project_name, i.code, i.kind === 'ap' ? 'Antena' : 'Etiqueta', i.model, i.product_name, i.issue,
      `${i.photos.length}/${i.photos_total}`, fecha(i.queued_at || g.queued_at), dias(i.queued_at || g.queued_at) ?? ''])))
    const blob = new Blob(['﻿' + filas.map((l) => l.map(esc).join(';')).join('\r\n')], { type: 'text/csv;charset=utf-8' })
    const a = document.createElement('a')
    a.href = URL.createObjectURL(blob); a.download = `bandeja-zkong-${hoyISO()}.csv`; a.click()
    setTimeout(() => URL.revokeObjectURL(a.href), 1000)
  }

  return (
    <>
      <div className="wi-kpis zk-kpis2">
        <div className="wi-kpi">
          <div className="v">{num(d.groups.length)}<span className="wi-de">{d.groups.length === 1 ? 'garantía' : 'garantías'}</span></div>
          <div className="k">Con algo para enviar a ZKONG</div>
          <div className="h">{d.drafts.length ? `${plural(d.drafts.length, 'remesa en borrador abierta', 'remesas en borrador abiertas')}` : 'Ninguna remesa en borrador'}</div>
        </div>
        <div className="wi-kpi">
          <div className="v">{num(etiquetas)}<span className="wi-de">{etiquetas === 1 ? 'dispositivo' : 'dispositivos'}</span></div>
          <div className="k">Esperando a ir en una remesa</div>
          <div className="h">{sinFotosTot ? <span className="zk-warn-t">{plural(sinFotosTot, 'sin todas las fotos', 'sin todas las fotos')}</span> : etiquetas ? 'Todos con sus fotos' : '—'}</div>
        </div>
        <div className="wi-kpi">
          <div className={`v zk-age ${tono(etiquetas ? maxDias : null)}`}>{etiquetas ? num(maxDias) : '—'}{etiquetas ? <span className="wi-de">{maxDias === 1 ? 'día' : 'días'}</span> : null}</div>
          <div className="k">Lo que más lleva esperando</div>
          <div className="h">{maxDias >= 30 ? 'Conviene mandar ya una remesa' : 'Desde que se marcó «Enviar a ZKONG»'}</div>
        </div>
      </div>

      {d.undecided.length > 0 && (
        <div className="zk-aviso">
          <Icon.warn />
          <div>
            <b>{plural(d.undecided.reduce((n, u) => n + Number(u.n), 0), 'dispositivo espera', 'dispositivos esperan')} a que se decida si va a ZKONG</b>
            <p>No entran en la bandeja hasta que se marque «Enviar a ZKONG» en su revisión: {d.undecided.map((u, k) => <span key={u.warranty_id}>{k ? ', ' : ''}<button className="link" onClick={() => onOpenWarranty(u.warranty_id)}>{u.code} ({u.n})</button></span>)}</p>
          </div>
        </div>
      )}

      <div className="dev-card zk-bandeja">
        <div className="zk-bar">
          <div className="dev-search wl-search">
            <Icon.search />
            <input value={q} onChange={(e) => setQ(e.target.value)} placeholder="Buscar garantía, cliente, nº de serie, modelo o fallo…" />
            {q ? <button className="dev-x" onClick={() => setQ('')} title="Borrar"><Icon.x className="ic-x" aria-hidden="true" /></button> : null}
          </div>
          <Select sm value={orden} onChange={setOrden} options={ORDENES} />
          <span className="spacer" />
          <button className="dev-btn" disabled={!etiquetas} onClick={exportar} title="Descargar la bandeja en CSV"><Icon.download /> Exportar</button>
          {elegidas.length ? (
            <span className="zk-elegidas">
              <b>{plural(elegidas.length, 'elegida', 'elegidas')}</b> de {plural(gruposSel, 'garantía', 'garantías')}
              {sinFotosSel ? <span className="zk-falta" title="Sin todas las fotos"><Icon.image /> {sinFotosSel}</span> : null}
              <button className="icon-btn" title="Quitar la selección" onClick={() => setSel(new Set())}><Icon.x className="ic-x" aria-hidden="true" /></button>
            </span>
          ) : <span className="dev-mute zk-ayuda">Marca las etiquetas que van juntas</span>}
          {puede && d.drafts.length > 0 && elegidas.length > 0 && (
            <Select sm value="" placeholder="Añadir a un borrador…" onChange={(v) => v && meter(v)}
              options={d.drafts.map((b) => ({ value: b.id, label: `Añadir a ${b.code}` }))} />
          )}
          {puede ? <button className="btn" disabled={ocupado || !elegidas.length} onClick={() => meter(null)}><Icon.send /> Crear remesa{elegidas.length ? ` con ${elegidas.length}` : ''}</button> : null}
        </div>

        <div className="dev-table-wrap keep">
          <table className="dev-table zk-t">
            <thead><tr>
              <th className="zk-ck"><input type="checkbox" checked={todasSel} disabled={!vistas.length} onChange={() => marcar(vistas)} aria-label="Elegir todas" /></th>
              <th>Nº de serie</th><th>Modelo</th><th>Fallo que se envía a ZKONG</th><th>Fotos</th><th className="r">Días</th>
            </tr></thead>
            {grupos.map((g) => {
              const n = dias(g.queued_at)
              const plegado = plegados.has(g.warranty_id)
              const marcadas = g.vistos.filter((i) => sel.has(i.id)).length
              const lista = enteros.has(g.warranty_id) || busca ? g.vistos : g.vistos.slice(0, VISIBLES)
              const resto = g.vistos.length - lista.length
              return (
                <tbody key={g.warranty_id} className="zk-g">
                  <tr className="zk-gh">
                    <td className="zk-ck"><input type="checkbox" checked={marcadas === g.vistos.length} ref={(el) => { if (el) el.indeterminate = marcadas > 0 && marcadas < g.vistos.length }} onChange={() => marcar(g.vistos)} aria-label={`Elegir ${g.code}`} /></td>
                    <td colSpan={5}>
                      <div className="zk-gh-in">
                        <button className="zk-chev" onClick={() => toggle(setPlegados, g.warranty_id)} aria-label={plegado ? 'Desplegar' : 'Plegar'}><Icon.chevron style={{ transform: plegado ? 'rotate(-90deg)' : 'none' }} /></button>
                        <button className="link wr-code" onClick={() => onOpenWarranty(g.warranty_id)}>{g.code}</button>
                        <span className="zk-cli">{g.client_name || 'Sin cliente'}{g.project_name ? <span className="dev-mute"> · {g.project_name}</span> : null}</span>
                        <span className="spacer" />
                        <span className="zk-chip">{plural(g.items.length, 'etiqueta', 'etiquetas')}</span>
                        <span className={`zk-chip dias ${tono(n)}`}><i />{n == null ? '—' : `${plural(n, 'día', 'días')} en la bandeja`}</span>
                      </div>
                    </td>
                  </tr>
                  {!plegado && lista.map((i) => {
                    const ni = dias(i.queued_at || g.queued_at)
                    return (
                      <tr key={i.id} className={`zk-it ${sel.has(i.id) ? 'zk-on' : ''}`} onClick={(e) => { if (!e.target.closest('a,button,input')) toggle(setSel, i.id) }}>
                        <td className="zk-ck"><input type="checkbox" checked={sel.has(i.id)} onChange={() => toggle(setSel, i.id)} aria-label={`Elegir ${i.code}`} /></td>
                        <td><span className="mono zk-sn">{i.code}</span>{i.kind === 'ap' ? <em className="wr-kind">Antena</em> : null}</td>
                        <td className="zk-mod"><b>{i.model || <span className="dev-mute">Sin modelo</span>}</b>{i.product_name ? <small>{i.product_name}</small> : null}</td>
                        <td className="zk-fallo">
                          {i.issue ? <span title={i.issue}>{i.issue}</span> : <span className="zk-warn-t">Sin fallo descrito</span>}
                          {rv && i.resolution !== 'defectuosa' ? <small>{rv.resolutions?.[i.resolution]?.label}</small> : null}
                        </td>
                        <td><Fotos it={i} /></td>
                        <td className="r"><span className={`zk-chip dias ${tono(ni)}`}>{ni == null ? '—' : `${ni} d`}</span></td>
                      </tr>
                    )
                  })}
                  {!plegado && (resto > 0 || (enteros.has(g.warranty_id) && g.vistos.length > VISIBLES && !busca)) ? (
                    <tr className="zk-mas"><td />
                      <td colSpan={5}><div className="zk-mas-in">
                        <span className="dev-mute">Se ven {lista.length} de {g.vistos.length} etiquetas de {g.code}</span>
                        <button className="wf-link" onClick={() => toggle(setEnteros, g.warranty_id)}>{resto > 0 ? <>Ver las {resto} restantes <Icon.arrowRight /></> : 'Ver menos'}</button>
                      </div></td>
                    </tr>
                  ) : null}
                </tbody>
              )
            })}
          </table>
          {!grupos.length && (
            <div className="act-empty"><Icon.zkong style={{ width: 30, height: 30, fill: 'var(--ink-3)' }} />
              <p>{etiquetas ? `Nada coincide con «${q}».` : 'La bandeja está vacía. Aquí llegan los dispositivos revisados como «No reproduce el fallo» o «Defectuosa» con «Enviar a ZKONG».'}</p></div>
          )}
        </div>
        {etiquetas ? (
          <div className="zk-pie">
            <span>En la bandeja: <b>{plural(etiquetas, 'dispositivo', 'dispositivos')}</b> de <b>{plural(d.groups.length, 'garantía', 'garantías')}</b></span>
            <span className="dev-mute">Días: <span className="zk-chip dias">menos de 15</span> <span className="zk-chip dias warn">15 o más</span> <span className="zk-chip dias bad">30 o más</span></span>
          </div>
        ) : null}
      </div>
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
        <div className="modal-h"><h3>{titulo}</h3><button className="icon-btn" onClick={onClose} title="Cerrar (Esc)"><Icon.x className="ic-x" aria-hidden="true" /></button></div>
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
// Cifras de lo que está fuera, filtros por estado y transportista, y una fila por remesa.
const POR_PAGINA = 25
const ABIERTAS = ['borrador', 'enviada', 'recibida', 'respondida']

function Remesas({ rv, onOpen, onOpenWarranty }) {
  const [d, setD] = useState(null)
  const [filtro, setFiltro] = useState('abiertas')
  const [q, setQ] = useState('')
  const [transp, setTransp] = useState('')
  const [pag, setPag] = useState(0)
  useEffect(() => { api.zkongBatches().then((r) => setD(r.ok ? r.batches : [])) }, [])
  useEffect(() => { setPag(0) }, [filtro, q, transp])
  if (!d) return <div className="center-load"><div className="spinner" /></div>

  const estados = rv?.batch_statuses || {}
  const cuenta = (s) => d.filter((b) => b.status === s).length
  const abiertas = d.filter((b) => ABIERTAS.includes(b.status))
  const fuera = d.filter((b) => ['enviada', 'recibida', 'respondida'].includes(b.status))
  const enZkong = fuera.reduce((s, b) => s + b.items, 0)
  const sinResp = fuera.reduce((s, b) => s + (b.items - b.answered), 0)
  const respondidas = d.reduce((s, b) => s + b.answered, 0)
  const aceptadas = d.reduce((s, b) => s + (b.answered - b.rejected), 0)
  const coste = d.reduce((s, b) => (b.costs ? { rec: s.rec + b.costs.recovered, asu: s.asu + b.costs.assumed } : s), { rec: 0, asu: 0 })
  const llegadas = d.filter((b) => b.sent_at && b.received_at)
  const mediaLlegada = llegadas.length ? Math.round((llegadas.reduce((s, b) => s + Math.max(0, (aFecha(b.received_at) - aFecha(b.sent_at)) / 86400000), 0) / llegadas.length) * 10) / 10 : null
  const transportistas = [...new Set(d.map((b) => b.carrier).filter(Boolean))].sort()

  const busca = q.trim().toLowerCase()
  const lista = d.filter((b) => (filtro === 'abiertas' ? ABIERTAS.includes(b.status) : !filtro || b.status === filtro)
    && (!transp || b.carrier === transp)
    && (!busca || [b.code, b.rma_number, b.tracking, b.return_tracking, b.shipping_ref, b.carrier, b.note, ...(b.warranty_list || []).flatMap((w) => [w.code, w.client_name])].some((v) => String(v || '').toLowerCase().includes(busca))))
  const paginas = Math.max(1, Math.ceil(lista.length / POR_PAGINA))
  const visibles = lista.slice(pag * POR_PAGINA, (pag + 1) * POR_PAGINA)

  const exportar = () => {
    const esc = csvCell
    const imp = (v) => (v == null ? '' : String(v.toFixed(2)).replace('.', ','))
    const filas = [['Remesa', 'Nº de RMA', 'Estado', 'Etiquetas', 'Garantías', 'Con respuesta', 'Rechazadas', 'Creada', 'Enviada', 'Transportista', 'Tracking', 'Referencia', 'Recibida por ZKONG',
      'Vuelta', 'Unidades de vuelta', 'Tracking de vuelta', 'Coste recuperado (€)', 'Coste asumido (€)', 'Sin respuesta (€)', 'Cerrada']]
    lista.forEach((b) => filas.push([b.code, b.rma_number, estados[b.status] || b.status, b.status === 'anulada' ? (b.units_sent ?? '') : b.items, (b.warranty_list || []).map((w) => w.code).join(', '),
      b.answered, b.rejected, fecha(b.created_at), b.sent_at ? fecha(b.sent_at) : '', b.carrier, b.tracking, b.shipping_ref, b.received_at ? fecha(b.received_at) : '',
      b.returned_at ? fecha(b.returned_at) : '', b.units_returned ?? '', b.return_tracking, imp(b.costs?.recovered), imp(b.costs?.assumed), imp(b.costs?.pending), b.closed_at ? fecha(b.closed_at) : '']))
    const blob = new Blob(['﻿' + filas.map((l) => l.map(esc).join(';')).join('\r\n')], { type: 'text/csv;charset=utf-8' })
    const a = document.createElement('a')
    a.href = URL.createObjectURL(blob); a.download = `remesas-zkong-${hoyISO()}.csv`; a.click()
    setTimeout(() => URL.revokeObjectURL(a.href), 1000)
  }

  const pestañas = [['abiertas', 'Abiertas', abiertas.length], ['', 'Todas', d.length],
    ...Object.entries(estados).map(([k, l]) => [k, l, cuenta(k)]).filter(([, , n]) => n)]

  return (
    <>
      <div className="wi-kpis zk-kpis4">
        <div className="wi-kpi">
          <div className="v">{num(abiertas.length)}<span className="wi-de">{abiertas.length === 1 ? 'remesa abierta' : 'remesas abiertas'}</span></div>
          <div className="k">Sin cerrar todavía</div>
          <div className="h">{abiertas.length ? ABIERTAS.map((s) => [s, cuenta(s)]).filter(([, n]) => n).map(([s, n]) => `${n} ${minus(estados[s] || s)}`).join(' · ') : 'Todas cerradas'}</div>
        </div>
        <div className="wi-kpi">
          <div className="v">{num(enZkong)}<span className="wi-de">{enZkong === 1 ? 'dispositivo' : 'dispositivos'}</span></div>
          <div className="k">Enviados a ZKONG</div>
          <div className="h">{enZkong ? (sinResp ? <span className="zk-warn-t">{num(sinResp)} sin respuesta aún</span> : 'Todos con respuesta') : 'Nada enviado ahora mismo'}</div>
        </div>
        <div className="wi-kpi">
          <div className="v zk-v-rec">{coste.rec || coste.asu ? eur(coste.rec) : '—'}</div>
          <div className="k">Coste recuperado</div>
          <div className="h">{coste.rec || coste.asu ? <><span className="zk-asu-t">{eur(coste.asu)} asumido</span> · </> : null}
            {respondidas ? `${Math.round((aceptadas / respondidas) * 100)} % aceptadas (${num(aceptadas)} de ${num(respondidas)})` : 'ZKONG aún no ha respondido nada'}</div>
        </div>
        <div className="wi-kpi">
          <div className="v">{mediaLlegada == null ? '—' : dec(mediaLlegada)}{mediaLlegada == null ? null : <span className="wi-de">días</span>}</div>
          <div className="k">Tardan en llegar a ZKONG</div>
          <div className="h">{llegadas.length ? `De media, desde el envío hasta que ZKONG confirma (${plural(llegadas.length, 'remesa', 'remesas')})` : 'Sin recepciones confirmadas todavía'}</div>
        </div>
      </div>

      <div className="dev-card zk-bandeja">
        <div className="zk-bar">
          <div className="dev-search wl-search">
            <Icon.search />
            <input value={q} onChange={(e) => setQ(e.target.value)} placeholder="Buscar remesa, garantía, cliente, tracking o albarán…" />
            {q ? <button className="dev-x" onClick={() => setQ('')} title="Borrar"><Icon.x className="ic-x" aria-hidden="true" /></button> : null}
          </div>
          <div className="dc-seg zk-seg">{pestañas.map(([k, l, n]) => <button key={k || 'todas'} className={filtro === k ? 'on' : ''} onClick={() => setFiltro(k)}>{l}<em>{n}</em></button>)}</div>
          {transportistas.length > 1 ? <Select sm value={transp} onChange={setTransp} options={[{ value: '', label: 'Todos los transportistas' }, ...transportistas.map((t) => ({ value: t, label: t }))]} /> : null}
          <span className="spacer" />
          <button className="dev-btn" disabled={!lista.length} onClick={exportar} title="Descargar lo que se ve en CSV"><Icon.download /> Exportar</button>
        </div>

        <div className="dev-table-wrap keep">
          <table className="dev-table zk-rt">
            <thead><tr>
              <th>Remesa</th><th>Estado</th><th className="r">Etiquetas</th><th>Garantías</th><th>Respuesta de ZKONG</th><th title="Recuperado (reposición o abono) · asumido (rechazadas)">Coste</th><th>Envío</th><th>Llegada a ZKONG</th><th>Vuelta</th><th />
            </tr></thead>
            <tbody>
              {visibles.map((b) => {
                const anulada = b.status === 'anulada'
                const gs = b.warranty_list || []
                const pct = b.answered ? Math.round(((b.answered - b.rejected) / b.answered) * 100) : null
                return (
                  <tr key={b.id} className={`wr-row ${anulada ? 'zk-anul' : ''}`} onClick={(e) => { if (!e.target.closest('button.link')) onOpen(b.id) }}>
                    <td><span className="wr-code">{b.code}</span>{b.rma_number ? <small className="zk-sub zk-rma-s" title="Nº de RMA de ZKONG">RMA <span className="mono">{b.rma_number}</span></small> : null}
                      <small className="zk-sub">Creada el {fecha(b.created_at)}{b.created_by_name ? ` · ${b.created_by_name}` : ''}</small></td>
                    <td><Pill cls={ESTADO_CLS[b.status]}>{estados[b.status] || b.status}</Pill></td>
                    <td className="r"><b className="wt-num">{anulada ? (b.units_sent ?? '—') : b.items}</b></td>
                    <td className="zk-gs">
                      {anulada ? <span className="dev-mute">Volvieron a la bandeja</span> : gs.length ? (
                        <>
                          <span>{gs.slice(0, 2).map((w, k) => <span key={w.id}>{k ? ', ' : ''}<button className="link" onClick={() => onOpenWarranty(w.id)}>{w.code}</button></span>)}{gs.length > 2 ? <span className="dev-mute"> +{gs.length - 2}</span> : null}</span>
                          <small className="zk-sub" title={gs.map((w) => w.client_name).filter(Boolean).join(', ')}>{[...new Set(gs.map((w) => w.client_name).filter(Boolean))].join(', ') || '—'}</small>
                        </>
                      ) : <span className="dev-mute">—</span>}
                    </td>
                    <td>
                      {['borrador', 'anulada'].includes(b.status) || !b.items ? <span className="dev-mute">—</span> : (
                        <div className="zk-resp">
                          <span className="zk-prog"><span><i style={{ width: `${(b.answered / b.items) * 100}%` }} /></span>{b.answered}/{b.items}</span>
                          <small className="zk-sub">{pct == null ? 'Sin respuestas aún' : `${pct} % aceptadas`}{b.rejected ? <em className="zk-rech"> · {b.rejected} rechazadas</em> : null}</small>
                        </div>
                      )}
                    </td>
                    <td>{b.costs ? <Costes c={b.costs} compacto /> : <span className="dev-mute">—</span>}</td>
                    <td>{b.sent_at ? <><span>{fecha(b.sent_at)}</span><small className="zk-sub">{b.carrier || 'Sin transportista'}</small>{b.tracking ? <small className="zk-sub mono zk-track" title="Tracking">{b.tracking}</small> : null}</> : <span className="dev-mute">{b.status === 'borrador' ? 'Sin enviar' : '—'}</span>}</td>
                    <td>
                      {b.received_at ? <span className="zk-ok"><Icon.check /> {fecha(b.received_at)}</span>
                        : b.waiting_days != null ? <span className={`zk-chip dias ${tono(b.waiting_days) || 'warn'}`} title="Días desde el envío sin que ZKONG confirme que le ha llegado"><i />Esperando · {plural(b.waiting_days, 'día', 'días')}</span>
                        : <span className="dev-mute">—</span>}
                    </td>
                    <td>{b.returned_at ? <><span className="zk-ok"><Icon.check /> {fecha(b.returned_at)}</span>{b.units_returned != null ? <small className="zk-sub">{plural(b.units_returned, 'unidad', 'unidades')}</small> : null}</>
                      : <span className="dev-mute">{VUELTA.includes(b.status) ? 'Sin llegar' : '—'}</span>}</td>
                    <td className="wt-ver"><span className="wf-link">Ver <Icon.arrowRight /></span></td>
                  </tr>
                )
              })}
            </tbody>
          </table>
          {!lista.length && (
            <div className="act-empty"><Icon.list style={{ width: 30, height: 30, fill: 'var(--ink-3)' }} />
              <p>{d.length ? 'Ninguna remesa con este filtro.' : 'Todavía no hay remesas. Se crean desde la bandeja, eligiendo las etiquetas que se mandan juntas.'}</p></div>
          )}
        </div>
        {lista.length ? (
          <div className="zk-pie">
            <span>Se ven <b>{visibles.length}</b> de <b>{plural(lista.length, 'remesa', 'remesas')}</b></span>
            {paginas > 1 ? (
              <span className="zk-pag">
                <button className="dev-btn" disabled={!pag} onClick={() => setPag(pag - 1)}>‹ Anterior</button>
                <span className="dev-mute">Página {pag + 1} de {paginas}</span>
                <button className="dev-btn" disabled={pag >= paginas - 1} onClick={() => setPag(pag + 1)}>Siguiente ›</button>
              </span>
            ) : null}
          </div>
        ) : null}
      </div>
    </>
  )
}

function Remesa({ id, rv, puede, onBack, onOpenWarranty }) {
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
      : tipo === 'rma' ? await accion('rma', v, v.rma_number?.trim() ? `RMA ${v.rma_number.trim()} apuntado` : 'Nº de RMA quitado')
      : tipo === 'vuelta' ? await accion('return', v, v.returned_on ? `${b.code}: vuelta apuntada` : `${b.code}: vuelta quitada`)
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
  const cerrarRemesa = async () => {
    if (await confirm({ title: 'Cerrar remesa', message: respondidas < items.length ? `Quedan ${items.length - respondidas} sin respuesta de ZKONG. ¿Cerrar igualmente?` : `¿Cerrar ${b.code}?`, confirmText: 'Cerrar remesa' }) && await accion('close', {}, `${b.code} cerrada`)) cargar()
  }
  // Una sola acción principal: el siguiente paso de la remesa.
  const reposiciones = items.filter((i) => i.result === 'reposicion').length
  const principal = !puede ? null
    : borrador ? 'envio'
    : b.status === 'enviada' ? 'recepcion'
    : conRespuesta && !b.returned_at && reposiciones ? 'vuelta'
    : conRespuesta ? 'cerrar' : null
  const guardarNota = () => { if (nota !== (b.note || '')) accion('note', { note: nota }, 'Nota guardada').then((ok) => ok && cargar()) }

  return (
    <>
      <button className="wr-back" onClick={onBack}><Icon.chevron style={{ transform: 'rotate(90deg)' }} /> Remesas</button>
      <div className="wr-hero">
        <div className="wr-hero-top">
          <div className="wr-hero-id">
            <h2>{b.code}</h2>
            <div className="wr-hero-pills"><Pill cls={ESTADO_CLS[b.status]}>{rv?.batch_statuses?.[b.status]}</Pill>
              {b.rma_number ? (puede && b.status !== 'anulada'
                ? <button className="zk-rma" title="Cambiar el nº de RMA de ZKONG" onClick={() => setVentana('rma')}>RMA <b className="mono">{b.rma_number}</b><Icon.pencil /></button>
                : <span className="zk-rma" title="Nº de RMA de ZKONG">RMA <b className="mono">{b.rma_number}</b></span>)
                : puede && b.status !== 'anulada' ? <button className="zk-rma add" onClick={() => setVentana('rma')}><Icon.plus /> Nº de RMA</button> : null}</div>
            <p>{plural(items.length, 'etiqueta', 'etiquetas')} de {plural(grupos.length, 'garantía', 'garantías')}, creada el {fecha(b.created_at)}{b.created_by_name ? ` por ${b.created_by_name}` : ''}</p>
          </div>
          <div className="wr-acts">
            <button className="dev-btn" disabled={!items.length} onClick={() => descargar('xlsx', b.id, toast)} title="El Excel que pide ZKONG para el RMA"><Icon.download /> Excel RMA</button>
            {principal === 'vuelta' && puede && conRespuesta ? <button className="dev-btn" disabled={ocupado} onClick={cerrarRemesa}><Icon.lock /> Cerrar remesa</button> : null}
            {principal === 'envio' && <button className="btn" disabled={ocupado || !items.length} onClick={enviar}><Icon.send /> Registrar envío</button>}
            {principal === 'recepcion' && <button className="btn" disabled={ocupado} onClick={() => setVentana('recepcion')}><Icon.check /> Registrar recepción</button>}
            {principal === 'vuelta' && <button className="btn" disabled={ocupado} onClick={() => setVentana('vuelta')}><Icon.check /> Registrar vuelta</button>}
            {principal === 'cerrar' && <button className="btn" disabled={ocupado} onClick={cerrarRemesa}><Icon.lock /> Cerrar remesa</button>}
            <MasAcciones code={b.code} items={[
              items.length ? { b: 'Fotos (ZIP)', s: 'Las fotos de cada etiqueta, para ZKONG', icon: <Icon.image />, fn: () => descargar('zip', b.id, toast) } : null,
              puede && conRespuesta ? { b: 'Datos del envío', s: 'Fecha, unidades, transportista, tracking', icon: <Icon.pencil />, fn: () => setVentana('editar'), dis: ocupado } : null,
              puede && VUELTA.includes(b.status) && principal !== 'vuelta' ? { b: b.returned_at ? 'Cambiar la vuelta' : 'Registrar vuelta', s: 'Cuándo llegan las reposiciones', icon: <Icon.refresh />, fn: () => setVentana('vuelta'), dis: ocupado } : null,
              puede && b.status === 'enviada' ? { b: 'Cerrar remesa', s: 'Sin esperar a que ZKONG confirme', icon: <Icon.lock />, fn: cerrarRemesa, dis: ocupado } : null,
              puede && b.status === 'cerrada' ? { b: 'Reabrir remesa', s: 'Para apuntar más respuestas de ZKONG', icon: <Icon.refresh />, fn: async () => { if (await accion('reopen', {}, `${b.code} reabierta`)) cargar() }, dis: ocupado } : null,
              puede && ['enviada', 'recibida'].includes(b.status) ? { b: 'Anular remesa', s: 'Paquete perdido o devuelto: las etiquetas vuelven a la bandeja', icon: <Icon.x />, fn: () => setVentana('anular'), bad: true, dis: ocupado } : null,
              puede && borrador ? { b: 'Descartar remesa', s: 'Pide confirmación; las etiquetas vuelven a la bandeja', icon: <Icon.trash />, fn: descartar, bad: true } : null,
            ]} />
          </div>
        </div>
        {b.status === 'anulada' ? (
          <p className="zk-anulada"><Icon.warn /> Anulada el {fecha(b.cancelled_at)}{b.cancel_reason ? `: ${b.cancel_reason}` : ''}. Sus etiquetas volvieron a la bandeja.</p>
        ) : (
          <ol className="zk-pasos">
            {PASOS.map((p, n) => {
              const actual = PASOS.indexOf(b.status)
              return <li key={p} className={n < actual ? 'hecho' : n === actual ? 'on' : ''}><span>{n < actual ? <Icon.check /> : n + 1}</span><em>{rv?.batch_statuses?.[p]}</em></li>
            })}
          </ol>
        )}
        {!borrador && (
          <div className="zk-ruta">
            <section className={b.sent_at ? 'hecho' : ''}>
              <h5><Icon.send /> Ida</h5>
              <b>{b.sent_at ? `Enviada el ${fecha(b.sent_at)}` : 'Sin enviar'}</b>
              {b.sent_at ? <span>{[b.units_sent != null ? plural(b.units_sent, 'unidad', 'unidades') : null, b.carrier].filter(Boolean).join(' por ') || 'Sin transportista'}</span> : null}
              {b.tracking ? <span className="mono zk-track">{b.tracking}</span> : null}
              {b.shipping_ref ? <span>Albarán {b.shipping_ref}</span> : null}
            </section>
            <section className={b.received_at ? 'hecho' : ''}>
              <h5><Icon.inbox /> En ZKONG</h5>
              <b>{b.received_at ? `Recibida el ${fecha(b.received_at)}` : b.sent_at ? 'Sin confirmar' : 'Sin enviar'}</b>
              {b.received_at && b.units_received != null ? <span>{plural(b.units_received, 'unidad', 'unidades')}{b.units_sent != null && b.units_received !== b.units_sent ? <em className="zk-warn-t"> de {b.units_sent} enviadas</em> : null}</span> : null}
              {b.waiting_days != null ? <em className={`zk-espera ${tono(b.waiting_days)}`}>Esperando confirmación, {plural(b.waiting_days, 'día', 'días')}</em> : null}
            </section>
            <section className={b.returned_at ? 'hecho' : ''}>
              <h5><Icon.refresh /> Vuelta</h5>
              <b>{b.returned_at ? `Llegó el ${fecha(b.returned_at)}` : VUELTA.includes(b.status) ? 'Sin llegar todavía' : 'Cuando ZKONG reponga'}</b>
              {b.returned_at && b.units_returned != null ? <span>{plural(b.units_returned, 'unidad', 'unidades')}{b.return_carrier ? ` por ${b.return_carrier}` : ''}</span> : null}
              {b.returned_at && b.return_tracking ? <span className="mono zk-track">{b.return_tracking}</span> : null}
              {!b.returned_at && reposiciones ? <span>{plural(reposiciones, 'reposición', 'reposiciones')} por llegar</span> : null}
            </section>
          </div>
        )}
        {borrador && faltan > 0 && <p className="zk-falta-txt"><Icon.image /> {plural(faltan, 'etiqueta no tiene', 'etiquetas no tienen')} todas las fotos. Súbelas desde la revisión de su garantía antes de mandar la remesa.</p>}
        {!borrador && b.status !== 'anulada' && items.length > 0 && (
          <div className="zk-respb">
            <div className="zk-respb-h">
              <b>Respuesta de ZKONG</b>
              <span className="dev-mute">{respondidas === items.length ? `Las ${items.length} con respuesta` : `${respondidas} de ${plural(items.length, 'etiqueta', 'etiquetas')}`}</span>
              <span className="zk-respb-p">{Object.entries(porResultado).map(([k, n]) => <Pill key={k} cls={RESULT_CLS[k]}>{rv?.zkong_results?.[k]} {n}</Pill>)}</span>
            </div>
            <Costes c={d.costs} />
          </div>
        )}
        <textarea className="zk-nota" rows={2} value={nota} readOnly={!puede} onChange={(e) => setNota(e.target.value)} onBlur={guardarNota}
          placeholder="Notas de la remesa: contacto de ZKONG, incidencias…" />
      </div>

      <div className="dev-card zk-bandeja zk-det-card">
        <div className="zk-bar">
          <b className="zk-bar-t">Dispositivos de la remesa</b>
          <span className="dev-mute">{plural(items.length, 'etiqueta', 'etiquetas')} de {plural(grupos.length, 'garantía', 'garantías')}</span>
          <span className="spacer" />
          {sel.size > 0 ? (
            <>
              <span className="zk-elegidas"><b>{plural(sel.size, 'elegida', 'elegidas')}</b><button className="icon-btn" title="Quitar la selección" onClick={() => setSel(new Set())}><Icon.x className="ic-x" aria-hidden="true" /></button></span>
              {puede && borrador && <button className="dev-btn" disabled={ocupado} onClick={quitar}><Icon.x /> Quitar de la remesa</button>}
              {puede && conRespuesta && (
                <>
                  <input className="zk-resnota" value={resNota} maxLength={500} onChange={(e) => setResNota(e.target.value)} placeholder="Nota (opcional): nº de abono, motivo del rechazo…" />
                  {Object.entries(rv?.zkong_results || {}).map(([k, l]) => <button key={k} className={`wr-res c-${RESULT_CLS[k]}`} disabled={ocupado} onClick={() => responder(k)}><span>{l}</span></button>)}
                  <button className="dev-btn" disabled={ocupado} onClick={() => responder('')}>Sin respuesta</button>
                </>
              )}
            </>
          ) : (borrador || conRespuesta) && items.length ? <span className="dev-mute zk-ayuda">{borrador ? 'Marca etiquetas para quitarlas de la remesa' : 'Marca etiquetas para apuntar lo que ha respondido ZKONG'}</span> : null}
        </div>
        {items.length ? (
          <div className="dev-table-wrap keep">
            <table className={`dev-table zk-t${borrador || conRespuesta ? '' : ' sin-ck'}${borrador ? ' sin-res' : ''}`}>
              <thead><tr>
                {borrador || conRespuesta ? <th className="zk-ck"><label className="zk-todas"><input type="checkbox" checked={todas} onChange={() => setSel(todas ? new Set() : new Set(items.map((i) => i.id)))} aria-label="Elegir todas" /><span>Elegir todas</span></label></th> : null}
                <th>Nº de serie</th><th>Modelo</th><th>Fallo enviado a ZKONG</th><th>Fotos</th>{!borrador ? <><th>Respuesta de ZKONG</th><th className="r">Coste</th></> : null}
              </tr></thead>
              {grupos.map((g) => (
                <tbody key={g.warranty_id} className="zk-g">
                  <tr className="zk-gh"><td colSpan={(borrador || conRespuesta ? 1 : 0) + (borrador ? 4 : 6)}>
                    <div className="zk-gh-in">
                      <button className="link wr-code" onClick={() => onOpenWarranty(g.warranty_id)}>{g.code}</button>
                      <span className="zk-cli">{g.client_name || 'Sin cliente'}</span>
                      <span className="spacer" />
                      <span className="zk-chip">{plural(g.items.length, 'etiqueta', 'etiquetas')}</span>
                    </div>
                  </td></tr>
                  {g.items.map((i) => {
                    const elegible = borrador || conRespuesta
                    return (
                      <tr key={i.id} className={`zk-it ${sel.has(i.id) ? 'zk-on' : ''}`} style={elegible ? null : { cursor: 'default' }} onClick={(e) => { if (elegible && !e.target.closest('a,button,input')) toggle(i.id) }}>
                        {elegible ? <td className="zk-ck"><input type="checkbox" checked={sel.has(i.id)} onChange={() => toggle(i.id)} aria-label={`Elegir ${i.code}`} /></td> : null}
                        <td className="zk-c-sn"><span className="mono zk-sn">{i.code}</span>{i.kind === 'ap' ? <em className="wr-kind">Antena</em> : null}</td>
                        <td className="zk-mod"><b>{i.model || <span className="dev-mute">Sin modelo</span>}</b>{i.product_name ? <small>{i.product_name}</small> : null}</td>
                        <td className="zk-fallo">
                          {i.issue ? <span title={i.issue}>{i.issue}</span> : <span className="zk-warn-t">Sin fallo descrito</span>}
                          {rv && i.resolution !== 'defectuosa' ? <small>{rv.resolutions?.[i.resolution]?.label}</small> : null}
                        </td>
                        <td className="zk-c-fotos"><Fotos it={i} /></td>
                        {!borrador ? (
                          <>
                            <td className="zk-c-res"><span className="zk-res zk-res-l">{i.result ? <Pill cls={RESULT_CLS[i.result]}>{rv?.zkong_results?.[i.result]}</Pill> : <span className="dev-mute">Sin respuesta</span>}{i.result_note ? <small title={i.result_note}>{i.result_note}</small> : null}</span></td>
                            <td className={`r zk-cst-i ${i.result === 'rechazada' ? 'asu' : i.result ? 'rec' : ''}`}>{i.cost == null
                              ? <span className="dev-mute" title="Este producto no tiene coste en el portal">Sin coste</span>
                              : <span title={i.cost_source === 'proveedor' ? 'Coste del proveedor ($ a 1,04 €): el producto no tiene precio de coste en el portal' : 'Precio de coste del portal'}>{eur(i.cost)}{i.cost_source === 'proveedor' ? <sup>$</sup> : null}</span>}</td>
                          </>
                        ) : null}
                      </tr>
                    )
                  })}
                </tbody>
              ))}
            </table>
          </div>
        ) : <p className="dev-mute zk-vacia">{b.status === 'anulada' ? 'Anulada: sus etiquetas volvieron a la bandeja.' : 'La remesa no tiene etiquetas.'}</p>}
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
          inicial={{ sent_on: hoyISO(), units_sent: items.length, rma_number: b.rma_number || '' }}
          campos={[{ k: 'sent_on', l: 'Fecha de envío', tipo: 'date', req: true }, { k: 'units_sent', l: 'Unidades enviadas', tipo: 'number', min: 1, req: true },
            { k: 'rma_number', l: 'Nº de RMA de ZKONG', max: 60, hint: 'si ya lo tienes', ancho: true },
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
      {ventana === 'rma' && (
        <Ventana titulo={`Nº de RMA · ${b.code}`} boton="Guardar" onClose={() => setVentana(null)} onOk={(v) => guardarVentana('rma', v)}
          inicial={{ rma_number: b.rma_number || '' }}
          campos={[{ k: 'rma_number', l: 'Nº de RMA de ZKONG', max: 60, ancho: true, hint: 'vacío para quitarlo', ph: 'El que da ZKONG al aceptar la solicitud' }]} />
      )}
      {ventana === 'vuelta' && (
        <Ventana titulo={`Vuelta de ZKONG · ${b.code}`} boton="Guardar" onClose={() => setVentana(null)} onOk={(v) => guardarVentana('vuelta', v)}
          inicial={{ returned_on: b.returned_at ? aISO(b.returned_at) : hoyISO(), units_returned: b.units_returned ?? (items.filter((i) => i.result === 'reposicion').length || ''),
            return_carrier: b.return_carrier || '', return_tracking: b.return_tracking || '' }}
          campos={[{ k: 'returned_on', l: 'Llegan las reposiciones el', tipo: 'date', req: !b.returned_at, min: aISO(b.received_at), hint: b.returned_at ? 'vacía para quitar la vuelta' : null },
            { k: 'units_returned', l: 'Unidades que vuelven', tipo: 'number', min: 0 },
            { k: 'return_carrier', l: 'Transportista', max: 80, ph: 'DHL, SEUR, TNT…' }, { k: 'return_tracking', l: 'Tracking', max: 120 },
            { k: 'note', l: 'Observaciones', tipo: 'area', hint: 'opcional', ancho: true }]} />
      )}
      {ventana === 'anular' && (
        <Ventana titulo={`Anular ${b.code}`} boton="Anular remesa" peligro onClose={() => setVentana(null)} onOk={(v) => guardarVentana('anular', v)}
          inicial={{ reason: '' }}
          campos={[{ k: 'reason', l: 'Motivo de la anulación', tipo: 'area', req: true, ancho: true, ph: 'Paquete perdido, devuelto por el transportista…' }]} />
      )}

    </>
  )
}

export default function Zkong({ onOpenWarranty }) {
  const [tab, setTab] = useState('bandeja')
  const [remesa, setRemesa] = useState(null)
  const [rv, setRv] = useState(null)
  const [puede, setPuede] = useState(false)   // warranties.zkong: crear y mover remesas
  const [n, setN] = useState({ bandeja: null, abiertas: null })
  const [reload, setReload] = useState(0)

  useEffect(() => { api.warrantyOptions().then((r) => { if (r.ok) { setRv(r.review); setPuede(!!r.perms?.zkong) } }) }, [])
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
        {tab === 'bandeja' && <Bandeja rv={rv} puede={puede} onOpenWarranty={onOpenWarranty} onBatch={abrirRemesa} />}
        {tab === 'remesas' && (remesa
          ? <Remesa key={`${remesa}-${reload}`} id={remesa} rv={rv} puede={puede} onBack={() => { setRemesa(null); setReload((x) => x + 1) }} onOpenWarranty={onOpenWarranty} />
          : <Remesas key={reload} rv={rv} onOpen={setRemesa} onOpenWarranty={onOpenWarranty} />)}
      </div></div>
    </>
  )
}
