import { useState, useEffect, useCallback } from 'react'
import { api } from '../api.js'
import { Icon } from '../icons.jsx'
import { useToast, useConfirm } from '../App.jsx'

/* -------------------------------------------------------------------------
 * CONFIGURACIÓN de Gestión de garantías (permiso warranties.config).
 * Estados (nombre, color, si cuentan como cerrada, orden), categorías, reglas de alerta
 * (días de aviso y de crítico) y plantillas de diagnóstico para la revisión.
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
        <span className="sub">· Estados, categorías, alertas y plantillas de Gestión de garantías</span>
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
          </>
        )}
      </div></div>
    </>
  )
}
