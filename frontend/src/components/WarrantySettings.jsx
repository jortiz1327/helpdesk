import { useState, useEffect, useCallback } from 'react'
import { api } from '../api.js'
import { Icon } from '../icons.jsx'
import { useToast, useConfirm } from '../App.jsx'
import Select from './Select.jsx'

/* -------------------------------------------------------------------------
 * CONFIGURACIÓN de Gestión de garantías (permiso warranties.config).
 * Estados (nombre, color, si cuentan como cerrada, orden), categorías, reglas de alerta
 * (días de aviso y de crítico), plantillas de diagnóstico para la revisión, tarifa de
 * los documentos y ajustes de los documentos y del correo de resolución.
 * ---------------------------------------------------------------------- */

const PALETA = ['#64748b', '#94a3b8', '#2563eb', '#0891b2', '#4f46e5', '#7c3aed', '#9333ea', '#c27803', '#ea580c', '#e11d48', '#dc2626', '#12925a', '#16a34a']
const URGENCIAS = [['Muy urgente', '#dc2626'], ['Alta', '#ea580c'], ['Media', '#c27803'], ['Baja', '#64748b']]

function Pill({ color, children }) { return <span className="wr-pill" style={{ '--pc': color }}><i />{children}</span> }

function Paleta({ value, onChange }) {
  return (
    <div className="ws-paleta">
      {PALETA.map((c) => <button key={c} type="button" className={value === c ? 'on' : ''} style={{ background: c }} onClick={() => onChange(c)} aria-label={c} />)}
    </div>
  )
}

/* Subir y bajar en la lista (el orden se guarda al momento). */
function Orden({ i, n, onMove }) {
  return (
    <span className="ws-orden">
      <button className="icon-btn" disabled={i === 0} onClick={() => onMove(i, -1)} title="Subir"><Icon.chevron style={{ transform: 'rotate(180deg)' }} /></button>
      <button className="icon-btn" disabled={i === n - 1} onClick={() => onMove(i, 1)} title="Bajar"><Icon.chevron /></button>
    </span>
  )
}

function Card({ icon: Ic, title, sub, action, children }) {
  return (
    <section className="ws-card">
      <div className="ws-card-h">
        <div><h3><Ic /> {title}</h3>{sub ? <p className="dev-mute">{sub}</p> : null}</div>
        {action}
      </div>
      {children}
    </section>
  )
}

function Estados({ list, hacer }) {
  const [ed, setEd] = useState(null)   // { key, label, color, closed, active, fixed }
  const guardar = async () => { if (await hacer('status_save', ed, 'Estado guardado')) setEd(null) }
  const mover = (i, d) => { const ids = list.map((s) => s.key); [ids[i], ids[i + d]] = [ids[i + d], ids[i]]; hacer('order', { what: 'statuses', ids }) }
  const fila = (s) => ed?.key === s.key ? null : (
    <>
      <Pill color={s.color}>{s.label}</Pill>
      <span className="ws-tags">
        {s.closed ? <em className="ws-tag">Cuenta como cerrada</em> : null}
        {!s.active ? <em className="ws-tag off">Desactivado</em> : null}
        {s.used ? <span className="dev-mute">{s.used} {s.used === 1 ? 'garantía' : 'garantías'}</span> : null}
      </span>
    </>
  )
  const editor = (
    <div className="ws-edit">
      <input value={ed?.label || ''} maxLength={60} autoFocus placeholder="Nombre del estado" onChange={(e) => setEd({ ...ed, label: e.target.value })}
        onKeyDown={(e) => { if (e.key === 'Enter') guardar(); if (e.key === 'Escape') setEd(null) }} />
      <Paleta value={ed?.color} onChange={(color) => setEd({ ...ed, color })} />
      <label className="wr-check"><input type="checkbox" checked={!!ed?.closed} disabled={ed?.fixed} onChange={(e) => setEd({ ...ed, closed: e.target.checked })} /> Cuenta como cerrada <span className="dev-mute">(pone fecha de cierre y sale de «abiertas»)</span></label>
      <label className="wr-check"><input type="checkbox" checked={ed?.active !== false} disabled={ed?.fixed} onChange={(e) => setEd({ ...ed, active: e.target.checked })} /> Activo <span className="dev-mute">(si no, no se puede elegir, pero las garantías que lo tienen lo conservan)</span></label>
      <div className="ws-edit-f">
        {ed?.fixed ? <span className="dev-mute">Lo usa la aplicación: solo cambian el nombre y el color.</span> : <span />}
        <button className="btn ghost sm" onClick={() => setEd(null)}>Cancelar</button>
        <button className="btn sm" disabled={!ed?.label?.trim()} onClick={guardar}>Guardar</button>
      </div>
    </div>
  )
  return (
    <Card icon={Icon.list} title="Estados" sub="En el orden en que avanza una garantía."
      action={<button className="btn sm" onClick={() => setEd({ key: '', label: '', color: PALETA[2], closed: false, active: true })}><Icon.plus /> Nuevo estado</button>}>
      {ed && !ed.key && editor}
      <ul className="ws-list">
        {list.map((s, i) => (
          <li key={s.key} className={s.active ? '' : 'off'}>
            <Orden i={i} n={list.length} onMove={mover} />
            {ed?.key === s.key ? editor : fila(s)}
            {ed?.key === s.key ? null : (
              <span className="ws-acts">
                <button className="icon-btn" title="Editar" onClick={() => setEd({ ...s, closed: !!s.closed, active: !!s.active })}><Icon.pencil /></button>
                {!s.system ? <button className="icon-btn" title="Borrar" onClick={() => hacer('status_delete', { key: s.key }, 'Estado borrado', `¿Borrar el estado «${s.label}»?`)}><Icon.trash /></button> : null}
              </span>
            )}
          </li>
        ))}
      </ul>
    </Card>
  )
}

function Categorias({ list, hacer }) {
  const [nueva, setNueva] = useState('')
  const [ed, setEd] = useState(null)   // { id, name }
  const crear = async () => { if (nueva.trim() && await hacer('category_save', { name: nueva, active: true }, 'Categoría añadida')) setNueva('') }
  const renombrar = async () => { if (await hacer('category_save', { ...ed, active: list.find((c) => c.id === ed.id)?.active }, 'Categoría guardada')) setEd(null) }
  const mover = (i, d) => { const ids = list.map((c) => c.id); [ids[i], ids[i + d]] = [ids[i + d], ids[i]]; hacer('order', { what: 'categories', ids }) }
  return (
    <Card icon={Icon.tag} title="Categorías" sub="Tipo de cliente de la garantía. Renombrar una cambia también las garantías que la tienen.">
      <div className="ws-add">
        <input value={nueva} maxLength={60} placeholder="Nueva categoría…" onChange={(e) => setNueva(e.target.value)} onKeyDown={(e) => e.key === 'Enter' && crear()} />
        <button className="btn sm" disabled={!nueva.trim()} onClick={crear}><Icon.plus /> Añadir</button>
      </div>
      <ul className="ws-list">
        {list.map((c, i) => (
          <li key={c.id} className={c.active ? '' : 'off'}>
            <Orden i={i} n={list.length} onMove={mover} />
            {ed?.id === c.id ? (
              <div className="ws-add grow">
                <input value={ed.name} maxLength={60} autoFocus onChange={(e) => setEd({ ...ed, name: e.target.value })}
                  onKeyDown={(e) => { if (e.key === 'Enter') renombrar(); if (e.key === 'Escape') setEd(null) }} />
                <button className="btn ghost sm" onClick={() => setEd(null)}>Cancelar</button>
                <button className="btn sm" onClick={renombrar}>Guardar</button>
              </div>
            ) : (
              <>
                <b className="ws-name">{c.name}</b>
                <span className="ws-tags">
                  {!c.active ? <em className="ws-tag off">Desactivada</em> : null}
                  {c.used ? <span className="dev-mute">{c.used} {c.used === 1 ? 'garantía' : 'garantías'}</span> : null}
                </span>
                <span className="ws-acts">
                  <button className="icon-btn" title="Renombrar" onClick={() => setEd({ id: c.id, name: c.name })}><Icon.pencil /></button>
                  <button className="icon-btn" title={c.active ? 'Desactivar' : 'Activar'} onClick={() => hacer('category_save', { id: c.id, name: c.name, active: !c.active }, c.active ? 'Categoría desactivada' : 'Categoría activada')}>{c.active ? <Icon.eyeOff /> : <Icon.eye />}</button>
                  <button className="icon-btn" title="Borrar" onClick={() => hacer('category_delete', { id: c.id }, 'Categoría borrada', `¿Borrar la categoría «${c.name}»?`)}><Icon.trash /></button>
                </span>
              </>
            )}
          </li>
        ))}
      </ul>
    </Card>
  )
}

function Alertas({ list, hacer }) {
  const [reglas, setReglas] = useState(list)
  useEffect(() => setReglas(list), [list])
  const cambiar = (k, c) => setReglas((rs) => rs.map((r) => (r.key === k ? { ...r, ...c } : r)))
  const cambiado = JSON.stringify(reglas) !== JSON.stringify(list)
  return (
    <Card icon={Icon.warn} title="Alertas" sub="Cuándo una garantía abierta pasa a aviso (ámbar) o a crítica (rojo). Se ven en la lista, en la ficha y con los filtros «Con alertas» y «Críticas».">
      <div className="dev-table-wrap keep">
        <table className="dev-table ws-alertas">
          <thead><tr><th>Regla</th><th className="c">Activa</th><th className="c">Aviso (días)</th><th className="c">Crítico (días)</th></tr></thead>
          <tbody>
            {reglas.map((r) => (
              <tr key={r.key} className={r.active ? '' : 'off'}>
                <td><b>{r.label}</b><small>{r.description}</small></td>
                <td className="c"><input type="checkbox" checked={r.active} onChange={(e) => cambiar(r.key, { active: e.target.checked })} aria-label={`Activar ${r.label}`} /></td>
                {r.instant ? <td className="c" colSpan={2}><span className="ws-tag">Crítica al momento</span></td> : (
                  <>
                    <td className="c"><input type="number" min={0} max={365} value={r.warn_days} disabled={!r.active} onChange={(e) => cambiar(r.key, { warn_days: e.target.value })} /></td>
                    <td className="c"><input type="number" min={0} max={365} value={r.crit_days} disabled={!r.active} onChange={(e) => cambiar(r.key, { crit_days: e.target.value })} /></td>
                  </>
                )}
              </tr>
            ))}
          </tbody>
        </table>
      </div>
      <div className="ws-foot">
        <span className="dev-mute">Los días cuentan desde que la garantía entró en ese estado (inactividad: desde el último movimiento; RMA: desde que se envió la remesa).</span>
        <button className="btn" disabled={!cambiado} onClick={() => hacer('alerts_save', { rules: reglas }, 'Alertas guardadas')}><Icon.save /> Guardar alertas</button>
      </div>
    </Card>
  )
}

function Plantillas({ list, hacer }) {
  const [ed, setEd] = useState(null)   // { id, title, body, active }
  const guardar = async () => { if (await hacer('template_save', ed, 'Plantilla guardada')) setEd(null) }
  const mover = (i, d) => { const ids = list.map((t) => t.id); [ids[i], ids[i + d]] = [ids[i + d], ids[i]]; hacer('order', { what: 'templates', ids }) }
  const editor = (
    <div className="ws-edit">
      <input value={ed?.title || ''} maxLength={120} autoFocus placeholder="Título (p. ej. Filtración de líquidos)" onChange={(e) => setEd({ ...ed, title: e.target.value })} />
      <textarea rows={4} value={ed?.body || ''} maxLength={2000} placeholder="Durante la revisión visual del dispositivo se ha detectado…" onChange={(e) => setEd({ ...ed, body: e.target.value })} />
      <label className="wr-check"><input type="checkbox" checked={ed?.active !== false} onChange={(e) => setEd({ ...ed, active: e.target.checked })} /> Activa</label>
      <div className="ws-edit-f"><span /><button className="btn ghost sm" onClick={() => setEd(null)}>Cancelar</button><button className="btn sm" disabled={!ed?.title?.trim() || !ed?.body?.trim()} onClick={guardar}>Guardar</button></div>
    </div>
  )
  return (
    <Card icon={Icon.doc} title="Plantillas de diagnóstico" sub="Textos técnicos para rellenar el comentario al revisar un dispositivo, con un clic."
      action={<button className="btn sm" onClick={() => setEd({ id: 0, title: '', body: '', active: true })}><Icon.plus /> Nueva plantilla</button>}>
      {ed && !ed.id && editor}
      <ul className="ws-list ws-tpl">
        {list.map((t, i) => (
          <li key={t.id} className={t.active ? '' : 'off'}>
            <Orden i={i} n={list.length} onMove={mover} />
            {ed?.id === t.id ? editor : (
              <>
                <div className="ws-tpl-tx"><b>{t.title}</b>{t.active ? <em className="ws-tag ok">Activa</em> : <em className="ws-tag off">Inactiva</em>}<p>{t.body}</p></div>
                <span className="ws-acts">
                  <button className="icon-btn" title="Editar" onClick={() => setEd({ ...t, active: !!t.active })}><Icon.pencil /></button>
                  <button className="icon-btn" title="Borrar" onClick={() => hacer('template_delete', { id: t.id }, 'Plantilla borrada', `¿Borrar la plantilla «${t.title}»?`)}><Icon.trash /></button>
                </span>
              </>
            )}
          </li>
        ))}
      </ul>
      {!list.length && !ed ? <p className="dev-mute">Sin plantillas todavía.</p> : null}
    </Card>
  )
}

/* Tarifa de los documentos: precio por referencia AEME (etiquetas y antenas), por tipo
   de pila y conceptos sueltos. Las referencias que salen en las garantías y aún no
   tienen precio aparecen debajo para ponérselo. El precio se guarda al salir del campo. */
const TIPOS_PRECIO = { product: 'Producto', battery: 'Pila', service: 'Concepto' }
const eur = (n) => Number(n || 0).toLocaleString('es-ES', { style: 'currency', currency: 'EUR' })

function Precio({ value, onSave }) {
  const [v, setV] = useState(value == null ? '' : String(value).replace('.', ','))
  useEffect(() => setV(value == null ? '' : String(value).replace('.', ',')), [value])
  const guardar = () => { const n = v.trim().replace(',', '.'); if (n !== '' && Number(n) !== Number(value)) onSave(n) }
  return <span className="ws-precio"><input inputMode="decimal" value={v} placeholder="0,00" onChange={(e) => setV(e.target.value)} onBlur={guardar} onKeyDown={(e) => e.key === 'Enter' && e.currentTarget.blur()} /> €</span>
}

function Tarifa({ list, hints, hacer }) {
  const [filtro, setFiltro] = useState('')
  const [nuevo, setNuevo] = useState(null)   // { kind, ref, label, price }
  const crear = async () => { if (await hacer('price_save', nuevo, 'Precio guardado')) setNuevo(null) }
  const filas = [...list, ...hints.map((h) => ({ ...h, id: 0, price: null }))].filter((p) => !filtro || p.kind === filtro)
  const n = (k) => list.filter((p) => p.kind === k).length + hints.filter((h) => h.kind === k).length
  return (
    <Card icon={Icon.tag} title="Tarifa" sub="Precios de los presupuestos y albaranes. Lo que cubre la garantía sale a 0 € aunque tenga precio."
      action={<button className="btn sm" onClick={() => setNuevo({ kind: 'service', ref: '', label: '', price: '' })}><Icon.plus /> Nuevo precio</button>}>
      {nuevo && (
        <div className="ws-edit ws-precio-new">
          <div className="ws-add grow">
            <Select sm value={nuevo.kind} onChange={(k) => setNuevo({ ...nuevo, kind: k })} options={Object.entries(TIPOS_PRECIO).map(([value, label]) => ({ value, label }))} />
            {nuevo.kind !== 'service' ? <input value={nuevo.ref} maxLength={80} placeholder={nuevo.kind === 'battery' ? 'Tipo (CR2450)' : 'Referencia AEME'} onChange={(e) => setNuevo({ ...nuevo, ref: e.target.value })} /> : null}
            <input value={nuevo.label} maxLength={160} placeholder={nuevo.kind === 'service' ? 'Concepto (Mano de obra…)' : 'Descripción (opcional)'} onChange={(e) => setNuevo({ ...nuevo, label: e.target.value })} />
            <input className="ws-precio-in" inputMode="decimal" value={nuevo.price} placeholder="0,00 €" onChange={(e) => setNuevo({ ...nuevo, price: e.target.value })} onKeyDown={(e) => e.key === 'Enter' && crear()} />
          </div>
          <div className="ws-edit-f"><span /><button className="btn ghost sm" onClick={() => setNuevo(null)}>Cancelar</button><button className="btn sm" disabled={!String(nuevo.price).trim() || !(nuevo.kind === 'service' ? nuevo.label : nuevo.ref).trim()} onClick={crear}>Guardar</button></div>
        </div>
      )}
      <div className="wr-chips sm">
        <button className={!filtro ? 'on' : ''} onClick={() => setFiltro('')}>Todos</button>
        {Object.entries(TIPOS_PRECIO).map(([k, l]) => n(k) ? <button key={k} className={filtro === k ? 'on' : ''} onClick={() => setFiltro(filtro === k ? '' : k)}>{l}s ({n(k)})</button> : null)}
        {hints.length ? <span className="ws-hint-n"><Icon.warn /> {hints.length} sin precio</span> : null}
      </div>
      <div className="dev-table-wrap keep wt-wrap">
        <table className="dev-table ws-tarifa">
          <thead><tr><th>Tipo</th><th>Referencia</th><th>Descripción</th><th className="r">Precio sin IVA</th><th /></tr></thead>
          <tbody>
            {filas.map((p) => (
              <tr key={`${p.kind}|${p.ref}`} className={p.id ? '' : 'hint'}>
                <td><span className="dev-mute">{TIPOS_PRECIO[p.kind]}</span></td>
                <td><b className={p.kind === 'service' ? '' : 'mono'}>{p.kind === 'service' ? '—' : p.ref}</b></td>
                <td>{p.label || <span className="dev-mute">—</span>}{!p.id && p.used ? <span className="dev-mute"> · en {p.used} {p.used === 1 ? 'dispositivo' : 'dispositivos'}</span> : null}</td>
                <td className="r"><Precio value={p.price} onSave={(price) => hacer('price_save', { id: p.id, kind: p.kind, ref: p.ref, label: p.label || '', price }, 'Precio guardado')} /></td>
                <td className="ws-acts">{p.id ? <button className="icon-btn" title="Quitar de la tarifa" onClick={() => hacer('price_delete', { id: p.id }, 'Precio quitado', `¿Quitar ${p.kind === 'service' ? `«${p.label}»` : p.ref} de la tarifa?`)}><Icon.trash /></button> : <em className="ws-tag off">Sin precio</em>}</td>
              </tr>
            ))}
          </tbody>
        </table>
        {!filas.length ? <p className="dev-mute" style={{ padding: 12 }}>Nada en la tarifa todavía.</p> : null}
      </div>
    </Card>
  )
}

const VARIABLES = [['{codigo}', 'Nº de garantía'], ['{cliente}', 'Cliente'], ['{proyecto}', 'Hotel o tienda'], ['{resumen}', 'Resultado de la revisión'], ['{documentos}', 'Presupuestos y albaranes'], ['{agente}', 'Quien lo prepara'], ['{fecha}', 'Hoy']]

function Documentos({ docs, hacer }) {
  const [v, setV] = useState(docs)
  useEffect(() => setV(docs), [docs])
  const set = (grupo, c) => setV((x) => ({ ...x, [grupo]: { ...x[grupo], ...c } }))
  const cambiado = JSON.stringify(v) !== JSON.stringify(docs)
  const campo = (grupo, k, label, props = {}) => (
    <div className="field"><span className="lbl">{label}</span>
      {props.rows ? <textarea rows={props.rows} value={v[grupo]?.[k] ?? ''} maxLength={props.max} onChange={(e) => set(grupo, { [k]: e.target.value })} />
        : <input value={v[grupo]?.[k] ?? ''} maxLength={props.max} type={props.type || 'text'} onChange={(e) => set(grupo, { [k]: e.target.value })} />}
    </div>
  )
  return (
    <Card icon={Icon.file} title="Documentos y correo de resolución" sub="Lo que sale en la cabecera y el pie de los presupuestos, albaranes e informes, y la plantilla del correo al cliente.">
      <div className="ws-docs">
        <div>
          <h4 className="ws-sub">Empresa</h4>
          <div className="grid2">{campo('company', 'name', 'Nombre', { max: 120 })}{campo('company', 'tax_id', 'CIF', { max: 30 })}</div>
          {campo('company', 'address', 'Dirección', { rows: 2, max: 300 })}
          <div className="grid2">{campo('company', 'email', 'Correo', { max: 120 })}{campo('company', 'phone', 'Teléfono', { max: 40 })}</div>
          <h4 className="ws-sub">Presupuestos</h4>
          <div className="grid2">{campo('documents', 'vat', 'IVA (%)', { type: 'number' })}{campo('documents', 'validity_days', 'Validez (días)', { type: 'number' })}</div>
          {campo('documents', 'conditions', 'Condiciones (nota de cada presupuesto nuevo)', { rows: 3, max: 3000 })}
        </div>
        <div>
          <h4 className="ws-sub">Correo de resolución</h4>
          {campo('resolution_email', 'subject', 'Asunto', { max: 200 })}
          {campo('resolution_email', 'body', 'Texto', { rows: 11, max: 5000 })}
          <div className="ws-vars">{VARIABLES.map(([k, l]) => <span key={k} title={l}><code>{k}</code> {l}</span>)}</div>
        </div>
      </div>
      <div className="ws-foot">
        <span className="dev-mute">El correo se rellena en la pestaña Documentos de cada garantía, para copiarlo.</span>
        <button className="btn" disabled={!cambiado} onClick={() => hacer('docs_save', v, 'Ajustes de documentos guardados')}><Icon.save /> Guardar</button>
      </div>
    </Card>
  )
}

export default function WarrantySettings() {
  const toast = useToast()
  const confirm = useConfirm()
  const [d, setD] = useState(null)
  const cargar = useCallback(() => api.warrantySettings().then((r) => setD(r.ok ? r : { error: r.error || 'No se pudo cargar' })), [])
  useEffect(() => { cargar() }, [cargar])

  // Guarda, avisa y recarga. pregunta: confirmación antes (borrados).
  const hacer = async (action, payload, msg, pregunta) => {
    if (pregunta && !(await confirm({ title: 'Confirmar', message: pregunta, danger: true, confirmText: 'Borrar' }))) return false
    const r = await api.warrantySettingsDo(action, payload)
    if (!r.ok) { toast(r.error || 'Error', 'err'); return false }
    if (msg) toast(msg)
    cargar()
    return true
  }

  return (
    <>
      <header className="page-head">
        <span className="sc-ic"><Icon.settings style={{ width: 18, height: 18, fill: 'var(--primary)' }} /></span>
        <div><h1>Configuración</h1></div>
        <span className="sub">· Estados, categorías, alertas, plantillas, tarifa y documentos de Gestión de garantías</span>
      </header>
      <div className="page-scroll"><div className="page dev-page wr-page">
        {!d ? <div className="center-load"><div className="spinner" /></div> : d.error ? <div className="dev-error"><Icon.warn /> {d.error}</div> : (
          <>
            <div className="ws-grid">
              <Estados list={d.statuses} hacer={hacer} />
              <div>
                <Categorias list={d.categories} hacer={hacer} />
                <Card icon={Icon.bolt} title="Urgencias" sub="Fijas: las usan las alertas y los filtros.">
                  <div className="ws-urg">{URGENCIAS.map(([l, c]) => <Pill key={l} color={c}>{l}</Pill>)}<Pill color="#94a3b8">No disponible</Pill></div>
                </Card>
              </div>
            </div>
            <Alertas list={d.alerts} hacer={hacer} />
            <Plantillas list={d.templates} hacer={hacer} />
            <Tarifa list={d.prices} hints={d.price_hints} hacer={hacer} />
            <Documentos docs={d.docs} hacer={hacer} />
          </>
        )}
      </div></div>
    </>
  )
}
