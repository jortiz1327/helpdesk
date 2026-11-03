import { useState, useEffect, useCallback } from 'react'
import { api } from '../api.js'
import { Icon } from '../icons.jsx'
import { useToast, useConfirm } from '../App.jsx'
import Select from './Select.jsx'


/* -------------------------------------------------------------------------
 * CONFIGURACIÓN de Gestión de garantías (permiso warranties.config).
 * Menú a la izquierda y un apartado cada vez: estados (nombre, color, si cuentan como
 * cerrada, orden), categorías, urgencias, reglas de alerta (días de aviso y de crítico),
 * plantillas de diagnóstico para la revisión, y la empresa del informe y el correo de
 * resolución. Los precios están en el portal AEME.
 * ---------------------------------------------------------------------- */

const PALETA = ['#64748b', '#94a3b8', '#2563eb', '#0891b2', '#4f46e5', '#7c3aed', '#9333ea', '#c27803', '#ea580c', '#e11d48', '#dc2626', '#12925a', '#16a34a']
const URG_COLOR = { muy_urgente: '#dc2626', alta: '#ea580c', media: '#c27803', baja: '#64748b' }
const usos = (n) => (n ? `${n} ${n === 1 ? 'garantía' : 'garantías'}` : 'Sin garantías')

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

function Card({ title, count, sub, action, children, foot }) {
  return (
    <section className="ws-card ws-panel">
      <div className="ws-card-h">
        <div>
          <h3>{title}{count ? <span className="ws-count">{count}</span> : null}</h3>
          {sub ? <p className="dev-mute">{sub}</p> : null}
        </div>
        {action}
      </div>
      {children}
      {foot ? <div className="ws-panel-f">{foot}</div> : null}
    </section>
  )
}

const Txt = ({ danger, ...p }) => <button className={`ws-txt ${danger ? 'danger' : ''}`} {...p} />

function Estados({ list, hacer }) {
  const [ed, setEd] = useState(null)   // { key, label, color, closed, active, fixed }
  const guardar = async () => { if (await hacer('status_save', ed, 'Estado guardado')) setEd(null) }
  const mover = (i, d) => { const ids = list.map((s) => s.key); [ids[i], ids[i + d]] = [ids[i + d], ids[i]]; hacer('order', { what: 'statuses', ids }) }
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
    <Card title="Estados" count={`${list.filter((s) => s.active).length} activos`} sub="Por los que pasa una garantía, en el orden en que avanza. Usa las flechas para cambiar el orden."
      action={<button className="btn sm" onClick={() => setEd({ key: '', label: '', color: PALETA[2], closed: false, active: true })}><Icon.plus /> Nuevo estado</button>}>
      {ed && !ed.key && editor}
      <ul className="ws-list">
        {list.map((s, i) => (
          <li key={s.key} className={s.active ? '' : 'off'}>
            <Orden i={i} n={list.length} onMove={mover} />
            {ed?.key === s.key ? editor : (
              <>
                <Pill color={s.color}>{s.label}</Pill>
                <span className="ws-tags">
                  {s.closed ? <em className="ws-tag">Cuenta como cerrada</em> : null}
                  {s.fixed ? <em className="ws-tag" title="Lo usa la aplicación: no se borra ni se desactiva">De la aplicación</em> : null}
                  {!s.active ? <em className="ws-tag off">Desactivado</em> : null}
                </span>
                <span className="ws-uso">{usos(s.used)}</span>
                <span className="ws-acts">
                  <Txt onClick={() => setEd({ ...s, closed: !!s.closed, active: !!s.active })}>Editar</Txt>
                  {!s.system ? <Txt danger onClick={() => hacer('status_delete', { key: s.key }, 'Estado borrado', `¿Borrar el estado «${s.label}»?`)}>Borrar</Txt> : <span className="ws-txt-ph" />}
                </span>
              </>
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
    <Card title="Categorías" count={`${list.filter((c) => c.active).length} activas`} sub="El tipo de cliente de la garantía (hotel, farmacia…). Renombrar una cambia también las garantías que la tienen."
      foot={(
        <div className="ws-add">
          <Icon.plus className="ws-add-ic" />
          <input value={nueva} maxLength={60} placeholder="Nueva categoría…" onChange={(e) => setNueva(e.target.value)} onKeyDown={(e) => e.key === 'Enter' && crear()} />
          <button className="btn sm" disabled={!nueva.trim()} onClick={crear}>Añadir categoría</button>
        </div>
      )}>
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
                <span className="ws-tags">{!c.active ? <em className="ws-tag off">Desactivada</em> : null}</span>
                <span className="ws-uso">{usos(c.used)}</span>
                <span className="ws-acts">
                  <Txt onClick={() => setEd({ id: c.id, name: c.name })}>Renombrar</Txt>
                  <Txt onClick={() => hacer('category_save', { id: c.id, name: c.name, active: !c.active }, c.active ? 'Categoría desactivada' : 'Categoría activada')}>{c.active ? 'Desactivar' : 'Activar'}</Txt>
                  <Txt danger onClick={() => hacer('category_delete', { id: c.id }, 'Categoría borrada', `¿Borrar la categoría «${c.name}»?`)}>Borrar</Txt>
                </span>
              </>
            )}
          </li>
        ))}
      </ul>
      {!list.length ? <p className="dev-mute">Sin categorías todavía.</p> : null}
    </Card>
  )
}

function Urgencias({ list }) {
  return (
    <Card title="Urgencias" count={`${list.length} fijas`} sub="No se cambian: las usan las alertas («Muy urgente» es crítica al momento) y el filtro «Urgentes» de la lista (Alta y Muy urgente).">
      <ul className="ws-list">
        {list.map((u) => (
          <li key={u.key}>
            <Pill color={URG_COLOR[u.key]}>{u.label}</Pill>
            <span className="ws-tags">{['alta', 'muy_urgente'].includes(u.key) ? <em className="ws-tag">Cuenta como urgente</em> : null}</span>
            <span className="ws-uso">{u.open ? `${u.open} ${u.open === 1 ? 'abierta' : 'abiertas'}` : 'Ninguna abierta'}</span>
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
    <Card title="Alertas" count={`${list.filter((r) => r.active).length} activas`} sub="Cuándo una garantía abierta pasa a aviso (ámbar) o a crítica (rojo). Se ven en la lista, en la ficha y con los filtros «Con alertas» y «Críticas»."
      foot={(
        <div className="ws-foot">
          <span className="dev-mute">Los días cuentan desde que la garantía entró en ese estado (inactividad: desde el último movimiento; RMA: desde que se envió la remesa).</span>
          {cambiado ? <button className="btn ghost sm" onClick={() => setReglas(list)}>Descartar</button> : null}
          <button className="btn" disabled={!cambiado} onClick={() => hacer('alerts_save', { rules: reglas }, 'Alertas guardadas')}><Icon.save /> Guardar alertas</button>
        </div>
      )}>
      <div className="dev-table-wrap keep">
        <table className="dev-table ws-alertas">
          <thead><tr><th>Regla</th><th className="c">Activa</th><th className="c" title="Días hasta que se marca en ámbar">Aviso a los (días)</th><th className="c" title="Días hasta que se marca en rojo">Crítica a los (días)</th></tr></thead>
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
      <label className="wr-check"><input type="checkbox" checked={ed?.active !== false} onChange={(e) => setEd({ ...ed, active: e.target.checked })} /> Activa <span className="dev-mute">(si no, no sale al revisar)</span></label>
      <div className="ws-edit-f"><span /><button className="btn ghost sm" onClick={() => setEd(null)}>Cancelar</button><button className="btn sm" disabled={!ed?.title?.trim() || !ed?.body?.trim()} onClick={guardar}>Guardar</button></div>
    </div>
  )
  return (
    <Card title="Plantillas de diagnóstico" count={`${list.filter((t) => t.active).length} activas`} sub="Textos técnicos para rellenar el comentario al revisar un dispositivo, con un clic."
      action={<button className="btn sm" onClick={() => setEd({ id: 0, title: '', body: '', active: true })}><Icon.plus /> Nueva plantilla</button>}>
      {ed && !ed.id && editor}
      <ul className="ws-list ws-tpl">
        {list.map((t, i) => (
          <li key={t.id} className={t.active ? '' : 'off'}>
            <Orden i={i} n={list.length} onMove={mover} />
            {ed?.id === t.id ? editor : (
              <>
                <div className="ws-tpl-tx"><b>{t.title}</b>{t.active ? null : <em className="ws-tag off">Inactiva</em>}<p>{t.body}</p></div>
                <span className="ws-acts">
                  <Txt onClick={() => setEd({ ...t, active: !!t.active })}>Editar</Txt>
                  <Txt danger onClick={() => hacer('template_delete', { id: t.id }, 'Plantilla borrada', `¿Borrar la plantilla «${t.title}»?`)}>Borrar</Txt>
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

const VARIABLES = [['{codigo}', 'Nº de garantía'], ['{cliente}', 'Cliente'], ['{proyecto}', 'Hotel o tienda'], ['{detalle}', 'Los bloques de abajo que tengan dispositivos'], ['{resumen}', 'Recuento por solución'],
  ['{plazo_hardware}', 'Plazo de garantía'], ['{plazo_pilas}', 'Plazo de las pilas'], ['{ciclos}', 'Refrescos de las pilas'], ['{agente}', 'Quien lo prepara'], ['{fecha}', 'Hoy']]
const VARS_BLOQUE = [['{n}', 'Cuántos'], ['{codigos}', 'Sus códigos'], ['{motivos}', 'Motivos del daño'], ['{plazo_hardware}', 'Plazo de garantía'], ['{plazo_pilas}', 'Plazo de las pilas'], ['{ciclos}', 'Refrescos de las pilas']]

/* Plazos de garantía: los generales y, si hace falta, los de cada categoría. */
function Plazos({ terms, refs, categorias, hacer }) {
  const [v, setV] = useState(terms)
  useEffect(() => setV(terms), [terms])
  const set = (c) => setV((x) => ({ ...x, ...c }))
  const setCat = (i, c) => set({ categories: v.categories.map((x, j) => (j === i ? { ...x, ...c } : x)) })
  const libres = categorias.filter((c) => !v.categories.some((x) => x.category === c.name))
  const cambiado = JSON.stringify(v) !== JSON.stringify(terms)
  const meses = (val, on) => <input type="number" min={1} max={240} value={val} onChange={(e) => on(e.target.value)} />
  return (
    <Card title="Plazos de garantía" sub="Cuánto cubre la garantía desde la instalación. Cada dispositivo cuenta desde su alta en ZKONG, salvo que la garantía indique su fecha de instalación."
      foot={(
        <div className="ws-foot">
          <span className="dev-mute">Fuera de plazo se marca en cada dispositivo y tiene su filtro en la garantía.</span>
          {cambiado ? <button className="btn ghost sm" onClick={() => setV(terms)}>Descartar</button> : null}
          <button className="btn" disabled={!cambiado} onClick={() => hacer('terms_save', v, 'Plazos guardados')}><Icon.save /> Guardar plazos</button>
        </div>
      )}>
      <div className="ws-body">
      <div className="ws-plazos">
        <label className="field"><span className="lbl">Hardware (meses)</span>{meses(v.hardware_months, (x) => set({ hardware_months: x }))}</label>
        <label className="field"><span className="lbl">Pilas (meses)</span>{meses(v.battery_months, (x) => set({ battery_months: x }))}</label>
        <label className="field"><span className="lbl">Refrescos de las pilas</span><input type="number" min={0} value={v.battery_cycles} onChange={(e) => set({ battery_cycles: e.target.value })} />
          <small className="dev-mute">Solo para el correo: los servidores no dan el contador.</small></label>
        <div className="field"><span className="lbl">Se compara con</span>
          <Select block value={v.reference} onChange={(x) => set({ reference: x })} options={Object.entries(refs).map(([k, l]) => ({ value: k, label: l }))} /></div>
      </div>
      <label className="wr-check"><input type="checkbox" checked={!!v.out_of_term_to_client} onChange={(e) => set({ out_of_term_to_client: e.target.checked })} /> Al revisar uno fuera de plazo, proponer «Paga el cliente» en lugar de «Garantía» <span className="dev-mute">(el técnico puede cambiarlo)</span></label>

      <h4 className="ws-sub">Plazos propios de una categoría</h4>
      {v.categories.length ? (
        <div className="dev-table-wrap keep">
          <table className="dev-table ws-alertas">
            <thead><tr><th>Categoría</th><th className="c">Hardware (meses)</th><th className="c">Pilas (meses)</th><th /></tr></thead>
            <tbody>
              {v.categories.map((c, i) => (
                <tr key={c.category}>
                  <td><b>{c.category}</b></td>
                  <td className="c">{meses(c.hardware_months, (x) => setCat(i, { hardware_months: x }))}</td>
                  <td className="c">{meses(c.battery_months, (x) => setCat(i, { battery_months: x }))}</td>
                  <td className="r"><Txt danger onClick={() => set({ categories: v.categories.filter((_, j) => j !== i) })}>Quitar</Txt></td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      ) : <p className="dev-mute">Todas usan los plazos generales.</p>}
      {libres.length ? (
        <div className="ws-add ws-add-cat">
          <Select value="" placeholder="Añadir plazos propios para…" options={libres.map((c) => ({ value: c.name, label: c.name }))}
            onChange={(x) => x && set({ categories: [...v.categories, { category: x, hardware_months: v.hardware_months, battery_months: v.battery_months }] })} />
        </div>
      ) : null}
      </div>
    </Card>
  )
}

/* Texto para Finanzas: cabecera y un presupuesto por grupo. */
function Finanzas({ finance, grupos, soluciones, hacer }) {
  const [v, setV] = useState(finance)
  useEffect(() => setV(finance), [finance])
  const set = (c) => setV((x) => ({ ...x, ...c }))
  const setG = (k, c) => set({ groups: { ...v.groups, [k]: { ...v.groups[k], ...c } } })
  const cambiado = JSON.stringify(v) !== JSON.stringify(finance)
  const toggleSol = (k) => set({ replaced: v.replaced.includes(k) ? v.replaced.filter((x) => x !== k) : [...v.replaced, k] })
  return (
    <Card title="Texto para Finanzas" sub="El aviso para que Finanzas cree los presupuestos (botón «Texto para Finanzas» en Documentos de cada garantía). Un presupuesto por grupo con dispositivos."
      foot={(
        <div className="ws-foot">
          <span className="dev-mute">Cada línea sale como «13 etiquetas AMC42V a coste 0».</span>
          {cambiado ? <button className="btn ghost sm" onClick={() => setV(finance)}>Descartar</button> : null}
          <button className="btn" disabled={!cambiado || !v.header.trim()} onClick={() => hacer('finance_save', v, 'Texto para Finanzas guardado')}><Icon.save /> Guardar</button>
        </div>
      )}>
      <div className="ws-body">
      <div className="field"><span className="lbl">Cabecera</span><textarea rows={2} maxLength={500} value={v.header} onChange={(e) => set({ header: e.target.value })} /></div>
      <div className="ws-vars">{[['{cliente}', 'Hotel o tienda'], ['{empresa}', 'Cliente'], ['{codigo}', 'G-26-0001'], ['{enlace}', 'Proyecto en el portal'], ['{fecha}', 'Hoy']].map(([k, l]) => <span key={k} title={l}><code>{k}</code> {l}</span>)}</div>

      <h4 className="ws-sub">Grupos</h4>
      <div className="dev-table-wrap keep">
        <table className="dev-table ws-fin">
          <thead><tr><th>Grupo</th><th className="c">Sale</th><th>Stock</th><th>Coste</th><th>Nota</th></tr></thead>
          <tbody>
            {Object.entries(grupos).map(([k, l]) => (
              <tr key={k} className={v.groups[k]?.active ? '' : 'off'}>
                <td><b>{l}</b></td>
                <td className="c"><input type="checkbox" checked={!!v.groups[k]?.active} onChange={(e) => setG(k, { active: e.target.checked })} aria-label={`Incluir ${l}`} /></td>
                <td><input value={v.groups[k]?.stock || ''} maxLength={60} placeholder="cuenta stock" onChange={(e) => setG(k, { stock: e.target.value })} /></td>
                <td><input value={v.groups[k]?.cost || ''} maxLength={60} placeholder="a coste 0" onChange={(e) => setG(k, { cost: e.target.value })} /></td>
                <td><input value={v.groups[k]?.note || ''} maxLength={200} onChange={(e) => setG(k, { note: e.target.value })} /></td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>

      <h4 className="ws-sub">Se sustituyen por unidades nuevas</h4>
      <p className="dev-mute">Con estas soluciones el dispositivo no se devuelve: va a «Sustituidas en garantía» (o «Paga el cliente») y cuenta stock. También decide el bloque del correo.</p>
      <div className="ws-sols">{Object.entries(soluciones).map(([k, l]) => (
        <label key={k} className="wr-check"><input type="checkbox" checked={v.replaced.includes(k)} onChange={() => toggleSol(k)} /> {l}</label>
      ))}</div>

      <div className="field ws-bat"><span className="lbl">Referencia de las pilas</span><input value={v.battery_ref || ''} maxLength={40} onChange={(e) => set({ battery_ref: e.target.value })} />
        <small className="dev-mute"><code>{'{numero}'}</code> los números del tipo de pila del catálogo (CR2450 → 2450), <code>{'{tipo}'}</code> el tipo entero.</small></div>
      </div>
    </Card>
  )
}

function Documentos({ docs, grupos, hacer }) {
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
    <Card title="Documentos y correo de resolución" sub="Lo que sale en la cabecera y el pie del informe de resolución, y la plantilla del correo al cliente."
      foot={(
        <div className="ws-foot">
          <span className="dev-mute">El correo se rellena en la pestaña Documentos de cada garantía, para copiarlo.</span>
          {cambiado ? <button className="btn ghost sm" onClick={() => setV(docs)}>Descartar</button> : null}
          <button className="btn" disabled={!cambiado} onClick={() => hacer('docs_save', v, 'Ajustes de documentos guardados')}><Icon.save /> Guardar</button>
        </div>
      )}>
      <div className="ws-docs">
        <div>
          <h4 className="ws-sub">Empresa</h4>
          <div className="grid2">{campo('company', 'name', 'Nombre', { max: 120 })}{campo('company', 'tax_id', 'CIF', { max: 30 })}</div>
          {campo('company', 'address', 'Dirección', { rows: 2, max: 300 })}
          <div className="grid2">{campo('company', 'email', 'Correo', { max: 120 })}{campo('company', 'phone', 'Teléfono', { max: 40 })}</div>
        </div>
        <div>
          <h4 className="ws-sub">Correo de resolución</h4>
          {campo('resolution_email', 'subject', 'Asunto', { max: 200 })}
          {campo('resolution_email', 'body', 'Texto', { rows: 11, max: 5000 })}
          <div className="ws-vars">{VARIABLES.map(([k, l]) => <span key={k} title={l}><code>{k}</code> {l}</span>)}</div>
        </div>
      </div>
      <div className="ws-body">
      <h4 className="ws-sub">Bloques de <code>{'{detalle}'}</code></h4>
      <p className="dev-mute">Un párrafo por grupo de dispositivos, solo si la garantía tiene alguno de ese grupo y en este orden.</p>
      <div className="ws-bloques">
        {Object.entries(grupos).map(([k, l]) => {
          const b = v.resolution_blocks?.[k] || {}
          const setB = (c) => set('resolution_blocks', { [k]: { ...b, ...c } })
          return (
            <div key={k} className={`ws-bloque${b.active ? '' : ' off'}`}>
              <label className="wr-check"><input type="checkbox" checked={!!b.active} onChange={(e) => setB({ active: e.target.checked })} /> <b>{l}</b></label>
              <textarea rows={4} maxLength={3000} value={b.text || ''} disabled={!b.active} onChange={(e) => setB({ text: e.target.value })} />
            </div>
          )
        })}
      </div>
      <div className="ws-vars">{VARS_BLOQUE.map(([k, l]) => <span key={k} title={l}><code>{k}</code> {l}</span>)}</div>
      </div>
    </Card>
  )
}

/*
 * Pasos por tipo de cliente: listas que se marcan en cada garantía. Una vale para todas,
 * para unas categorías (Hoteles) o para clientes cuyo nombre o proyecto contiene un texto
 * (Expofarm). El id de cada paso se conserva al cambiar su texto (lo marcado no se pierde).
 */
const nuevoId = (pre) => `${pre}${Math.random().toString(36).slice(2, 10)}`
function PasosCliente({ lists, categorias, hacer }) {
  const [v, setV] = useState(lists)
  useEffect(() => setV(lists), [lists])
  const cambiado = JSON.stringify(v) !== JSON.stringify(lists)
  const setL = (i, c) => setV((xs) => xs.map((x, j) => (j === i ? { ...x, ...c } : x)))
  const mover = (i, d) => setV((xs) => { const ys = [...xs]; [ys[i], ys[i + d]] = [ys[i + d], ys[i]]; return ys })
  const nueva = () => setV((xs) => [...xs, { id: nuevoId('l'), name: '', active: true, all: false, categories: [], clients: [], steps: [{ id: nuevoId('s'), text: '' }] }])
  return (
    <Card title="Pasos por tipo de cliente" sub="Listas de pasos que se marcan en cada garantía (queda quién y cuándo). A una garantía le tocan todas las listas que coinciden con ella, en este orden."
      foot={(
        <div className="ws-foot">
          <span className="dev-mute">Marcar pasos pide poder editar garantías.</span>
          {cambiado ? <button className="btn ghost sm" onClick={() => setV(lists)}>Descartar</button> : null}
          <button className="btn" disabled={!cambiado} onClick={() => hacer('steps_save', { lists: v }, 'Pasos guardados')}><Icon.save /> Guardar</button>
        </div>
      )}>
      <div className="ws-body">
        {!v.length ? <p className="dev-mute">Sin listas: las garantías no tienen pasos.</p> : null}
        <div className="ws-pasos">
          {v.map((l, i) => (
            <div key={l.id} className={`ws-pl${l.active ? '' : ' off'}`}>
              <div className="ws-pl-h">
                <input className="ws-pl-name" value={l.name} maxLength={60} placeholder="Nombre de la lista (p. ej. Expofarm)" onChange={(e) => setL(i, { name: e.target.value })} />
                <label className="wr-check"><input type="checkbox" checked={!!l.active} onChange={(e) => setL(i, { active: e.target.checked })} /> Activa</label>
                <button className="icon-btn" disabled={!i} title="Subir" onClick={() => mover(i, -1)}><Icon.chevron style={{ transform: 'rotate(180deg)' }} /></button>
                <button className="icon-btn" disabled={i === v.length - 1} title="Bajar" onClick={() => mover(i, 1)}><Icon.chevron /></button>
                <Txt danger onClick={() => setV((xs) => xs.filter((_, j) => j !== i))}>Quitar</Txt>
              </div>
              <div className="ws-pl-a">
                <div className="dc-seg">
                  <button className={l.all ? 'on' : ''} onClick={() => setL(i, { all: true })}>Todas las garantías</button>
                  <button className={l.all ? '' : 'on'} onClick={() => setL(i, { all: false })}>Solo algunas</button>
                </div>
                {!l.all ? (
                  <div className="ws-pl-q">
                    <div className="field"><span className="lbl">Categorías</span>
                      <Select block multiple value={l.categories} placeholder="Ninguna" onChange={(x) => setL(i, { categories: x })}
                        options={categorias.map((c) => ({ value: c.name, label: c.name }))} /></div>
                    <div className="field"><span className="lbl">Clientes que contienen</span>
                      <input value={(l.clients || []).join(', ')} placeholder="Expofarm, …" onChange={(e) => setL(i, { clients: e.target.value.split(',').map((x) => x.trimStart()) })} /></div>
                  </div>
                ) : null}
              </div>
              <ol className="ws-pl-s">
                {l.steps.map((s, k) => (
                  <li key={s.id}><div className="ws-pl-row">
                    <input value={s.text} maxLength={160} placeholder="Qué hay que hacer" onChange={(e) => setL(i, { steps: l.steps.map((x, j) => (j === k ? { ...x, text: e.target.value } : x)) })} />
                    <button className="icon-btn" title="Quitar el paso" onClick={() => setL(i, { steps: l.steps.filter((_, j) => j !== k) })}>✕</button>
                  </div></li>
                ))}
              </ol>
              <button className="wf-link" onClick={() => setL(i, { steps: [...l.steps, { id: nuevoId('s'), text: '' }] })}><Icon.plus /> Añadir paso</button>
            </div>
          ))}
        </div>
        <button className="dev-btn" onClick={nueva}><Icon.plus /> Nueva lista</button>
      </div>
    </Card>
  )
}

/* Motivos de cierre: lo que se elige al cerrar una garantía. Las claves no cambian al renombrar. */
function MotivosCierre({ list, hacer }) {
  const limpia = (l) => l.map(({ key, label, active }) => ({ key, label, active }))
  const [v, setV] = useState(() => limpia(list))
  useEffect(() => setV(limpia(list)), [list])
  const usos = Object.fromEntries(list.map((r) => [r.key, r.used]))
  const set = (i, c) => setV((x) => x.map((r, k) => (k === i ? { ...r, ...c } : r)))
  const mover = (i, d) => setV((x) => { const n = [...x]; [n[i], n[i + d]] = [n[i + d], n[i]]; return n })
  const cambiado = JSON.stringify(v) !== JSON.stringify(limpia(list))
  return (
    <Card title="Motivos de cierre" count={v.filter((r) => r.active).length}
      sub="Al cerrar una garantía (o pasarla a cualquier estado que cuente como cerrada) se elige uno de estos motivos y, si se quiere, cómo se resolvió. Al reabrirla se pide por qué. En Informes se puede agrupar por motivo."
      foot={(
        <div className="ws-foot">
          <span className="dev-mute">Los que ya usa alguna garantía no se pueden quitar: se desactivan.</span>
          {cambiado ? <button className="btn ghost sm" onClick={() => setV(limpia(list))}>Descartar</button> : null}
          <button className="btn" disabled={!cambiado || v.some((r) => !r.label.trim())} onClick={() => hacer('close_reasons_save', { list: v }, 'Motivos guardados')}><Icon.save /> Guardar</button>
        </div>
      )}>
      <div className="ws-body">
        <ul className="ws-motivos">
          {v.map((r, i) => (
            <li key={r.key || i} className={r.active ? '' : 'off'}>
              <input value={r.label} maxLength={60} placeholder="Nombre del motivo" onChange={(e) => set(i, { label: e.target.value })} />
              <span className="dev-mute ws-motivo-n">{usos[r.key] ? usos[r.key] + ' ' + (usos[r.key] === 1 ? 'garantía' : 'garantías') : ''}</span>
              <label className="wr-check"><input type="checkbox" checked={r.active} onChange={(e) => set(i, { active: e.target.checked })} /> Activo</label>
              <button className="icon-btn" disabled={!i} title="Subir" onClick={() => mover(i, -1)}><Icon.chevron style={{ transform: 'rotate(180deg)' }} /></button>
              <button className="icon-btn" disabled={i === v.length - 1} title="Bajar" onClick={() => mover(i, 1)}><Icon.chevron /></button>
              <button className="icon-btn" disabled={!!usos[r.key]} title={usos[r.key] ? 'Lo usa alguna garantía: desactívalo' : 'Quitar'} onClick={() => setV((x) => x.filter((_, k) => k !== i))}><Icon.trash /></button>
            </li>
          ))}
        </ul>
        <button className="wf-link" onClick={() => setV((x) => [...x, { key: '', label: '', active: true }])}><Icon.plus /> Añadir motivo</button>
      </div>
    </Card>
  )
}

/* Tour guiado: el título y el texto de cada paso (qué se señala lo fija la app). */
function TourTextos({ steps, hacer }) {
  const [v, setV] = useState(steps)
  useEffect(() => setV(steps), [steps])
  const set = (i, c) => setV((x) => x.map((s, k) => (k === i ? { ...s, ...c } : s)))
  const cambiado = JSON.stringify(v) !== JSON.stringify(steps)
  const vacio = v.some((s) => !s.title.trim() || !s.text.trim())
  return (
    <Card title="Tour guiado" count={v.filter((s) => s.active).length}
      sub="El recorrido que se ofrece la primera vez que alguien entra a Gestión de garantías y que se repite desde «Tour guiado» en el menú. Cada uno ve solo los pasos de lo que puede usar."
      foot={(
        <div className="ws-foot">
          <button className="btn ghost sm" onClick={() => hacer('tour_save', { steps: [] }, 'Textos de serie recuperados')}><Icon.refresh /> Textos de serie</button>
          <span className="spacer" />
          {cambiado ? <button className="btn ghost sm" onClick={() => setV(steps)}>Descartar</button> : null}
          <button className="btn" disabled={!cambiado || vacio} onClick={() => hacer('tour_save', { steps: v }, 'Tour guardado')}><Icon.save /> Guardar</button>
        </div>
      )}>
      <div className="ws-body ws-pasos">
        {v.map((s, i) => (
          <div key={s.id} className={`ws-pl${s.active ? '' : ' off'}`}>
            <div className="ws-pl-h">
              <span className="ws-tour-n">{i + 1}</span>
              <input className="ws-pl-name" value={s.title} maxLength={80} placeholder="Título" onChange={(e) => set(i, { title: e.target.value })} />
              <label className="wr-check"><input type="checkbox" checked={s.active} onChange={(e) => set(i, { active: e.target.checked })} /> Se enseña</label>
            </div>
            <textarea className="ws-tour-t" rows={2} value={s.text} maxLength={600} placeholder="Qué se explica en este paso" onChange={(e) => set(i, { text: e.target.value })} />
          </div>
        ))}
      </div>
    </Card>
  )
}

/* Sustituciones: en qué categorías se ofrece el ticket de posiciones y cómo sale. */
function Sustituciones({ cfg, categorias, ticketCats, hacer }) {
  const [v, setV] = useState(cfg)
  useEffect(() => setV(cfg), [cfg])
  const set = (c) => setV((x) => ({ ...x, ...c }))
  const cambiado = JSON.stringify(v) !== JSON.stringify(cfg)
  return (
    <Card title="Sustituciones" sub="Cuando una etiqueta no se devuelve y se cambia por una nueva (las soluciones de Texto para Finanzas), se apunta su posición (el artículo vinculado en ZKONG) y la nueva. En estas categorías se pregunta si crear un ticket con las posiciones a nombre del cliente."
      foot={(
        <div className="ws-foot">
          <span className="dev-mute">El ticket no le escribe nada al cliente: queda en el Helpdesk para vincularlas.</span>
          {cambiado ? <button className="btn ghost sm" onClick={() => setV(cfg)}>Descartar</button> : null}
          <button className="btn" disabled={!cambiado || !v.subject.trim()} onClick={() => hacer('replacements_save', v, 'Sustituciones guardadas')}><Icon.save /> Guardar</button>
        </div>
      )}>
      <div className="ws-body">
        <div className="ws-sus">
          <div className="field"><span className="lbl">Preguntar por el ticket en</span>
            <Select block multiple value={v.categories} placeholder="Ninguna categoría" onChange={(x) => set({ categories: x })}
              options={categorias.map((c) => ({ value: c.name, label: c.name }))} /></div>
          <div className="field"><span className="lbl">Categoría del ticket</span>
            <Select block value={v.ticket_category_id ?? ''} onChange={(x) => set({ ticket_category_id: x ? Number(x) : null })}
              options={[{ value: '', label: 'Sin categoría (la ponen las reglas)' }, ...ticketCats.map((c) => ({ value: c.id, label: c.name }))]} /></div>
        </div>
        <div className="field"><span className="lbl">Asunto</span><input value={v.subject} maxLength={200} onChange={(e) => set({ subject: e.target.value })} /></div>
        <div className="field"><span className="lbl">Texto antes de la tabla</span><textarea rows={3} maxLength={2000} value={v.intro} onChange={(e) => set({ intro: e.target.value })} /></div>
        <div className="ws-vars">{[['{codigo}', 'Nº de garantía'], ['{cliente}', 'Cliente'], ['{proyecto}', 'Hotel o tienda'], ['{n}', 'Etiquetas nuevas'], ['{agente}', 'Quien lo crea']].map(([k, l]) => <span key={k} title={l}><code>{k}</code> {l}</span>)}</div>
        <p className="dev-mute">Debajo va la tabla <b>Posición · Etiqueta antigua · Etiqueta nueva</b>.</p>
      </div>
    </Card>
  )
}

const SECCIONES = [
  ['Cómo avanza una garantía', [
    ['estados', 'Estados', Icon.list, (d) => d.statuses.filter((s) => s.active).length],
    ['categorias', 'Categorías', Icon.tag, (d) => d.categories.filter((c) => c.active).length],
    ['urgencias', 'Urgencias', Icon.bolt, (d) => d.urgencies.length],
    ['alertas', 'Alertas', Icon.bell, (d) => d.alerts.filter((r) => r.active).length],
    ['pasos', 'Pasos por tipo de cliente', Icon.check, (d) => d.checklists.filter((l) => l.active).length],
    ['motivos', 'Motivos de cierre', Icon.lock, (d) => d.close_reasons.filter((r) => r.active).length],
  ]],
  ['Revisión y documentos', [
    ['plantillas', 'Plantillas de diagnóstico', Icon.doc, (d) => d.templates.filter((t) => t.active).length],
    ['plazos', 'Plazos de garantía', Icon.clock, null],
    ['documentos', 'Documentos y correo', Icon.file, null],
    ['finanzas', 'Texto para Finanzas', Icon.copy, null],
    ['sustituciones', 'Sustituciones', Icon.swap, null],
  ]],
  ['Ayuda', [
    ['tour', 'Tour guiado', Icon.help, (d) => d.tour.filter((s) => s.active).length],
  ]],
]
const MEMO = 'warranty_settings.section'

export default function WarrantySettings() {
  const toast = useToast()
  const confirm = useConfirm()
  const [d, setD] = useState(null)
  const [sec, setSec] = useState(() => { try { return localStorage.getItem(MEMO) || 'estados' } catch { return 'estados' } })
  const cargar = useCallback(() => api.warrantySettings().then((r) => setD(r.ok ? r : { error: r.error || 'No se pudo cargar' })), [])
  useEffect(() => { cargar() }, [cargar])
  const ir = (k) => { setSec(k); try { localStorage.setItem(MEMO, k) } catch { /* sin almacenamiento */ } }

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
        <span className="sub">· Estados, categorías, urgencias, alertas, pasos, motivos de cierre, plantillas, plazos, documentos, texto para Finanzas, sustituciones y tour guiado de Gestión de garantías</span>
      </header>
      <div className="page-scroll"><div className="page dev-page wr-page">
        {!d ? <div className="center-load"><div className="spinner" /></div> : d.error ? <div className="dev-error"><Icon.warn /> {d.error}</div> : (
          <div className="ws-layout">
            <nav className="dev-card ws-nav">
              {SECCIONES.map(([grupo, items]) => (
                <div key={grupo}>
                  <div className="ws-nav-h">{grupo}</div>
                  {items.map(([k, l, Ic, n]) => (
                    <button key={k} className={sec === k ? 'on' : ''} onClick={() => ir(k)}>
                      <Ic /><span>{l}</span>{n ? <em>{n(d)}</em> : null}
                    </button>
                  ))}
                </div>
              ))}
              <div className="ws-nav-note">
                <b>Precios y tarifas</b>
                <p>Están en el portal AEME (tarifa de cada cliente o catálogo): aquí no se tocan.</p>
              </div>
            </nav>
            <div className="ws-main">
              {sec === 'estados' && <Estados list={d.statuses} hacer={hacer} />}
              {sec === 'categorias' && <Categorias list={d.categories} hacer={hacer} />}
              {sec === 'urgencias' && <Urgencias list={d.urgencies} />}
              {sec === 'alertas' && <Alertas list={d.alerts} hacer={hacer} />}
              {sec === 'plantillas' && <Plantillas list={d.templates} hacer={hacer} />}
              {sec === 'plazos' && <Plazos terms={d.terms} refs={d.term_references} categorias={d.categories} hacer={hacer} />}
              {sec === 'documentos' && <Documentos docs={d.docs} grupos={d.block_groups} hacer={hacer} />}
              {sec === 'motivos' && <MotivosCierre list={d.close_reasons} hacer={hacer} />}
              {sec === 'tour' && <TourTextos steps={d.tour} hacer={hacer} />}
              {sec === 'pasos' && <PasosCliente lists={d.checklists} categorias={d.categories} hacer={hacer} />}
              {sec === 'sustituciones' && <Sustituciones cfg={d.replacements} categorias={d.categories} ticketCats={d.ticket_categories} hacer={hacer} />}
              {sec === 'finanzas' && <Finanzas finance={d.finance} grupos={d.finance_groups} soluciones={d.resolutions} hacer={hacer} />}
            </div>
          </div>
        )}
      </div></div>
    </>
  )
}
