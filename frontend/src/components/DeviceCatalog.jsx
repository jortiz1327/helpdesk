import { useState, useEffect, useCallback } from 'react'
import { api } from '../api.js'
import { Icon } from '../icons.jsx'
import { useToast, useConfirm } from '../App.jsx'
import Select from './Select.jsx'

/* -------------------------------------------------------------------------
 * CATÁLOGO de modelos de etiqueta (Gestión de garantías).
 * Cada modelo: tamaño, colores de la pantalla, color de la carcasa, pilas (tipo y
 * cantidad: de aquí sacan los presupuestos de pilas el tipo) y sus referencias por
 * parejas ZKONG ↔ AEME. La ZKONG se casa con el modelo que da el servidor; la AEME es
 * el producto del portal. Abajo, las etiquetas del portal que aún no tienen modelo.
 * Lo consulta cualquiera de garantías; lo cambia quien tiene Configuración.
 * ---------------------------------------------------------------------- */

const pulgadas = (s) => (s == null ? '—' : `${String(Number(s)).replace('.', ',')}"`)
const pilas = (m) => (m.has_batteries ? `${m.battery_type || '¿tipo?'}${m.battery_quantity > 1 ? ` ×${m.battery_quantity}` : ''}` : null)
const EINK_C = { BW: '#64748b', BWR: '#dc2626', BWRY: '#c27803' }
const FILTROS = [['', 'Todos'], ['pilas', 'Con pilas'], ['sin_pilas', 'Sin pilas'], ['sin_aeme', 'Sin referencia AEME'], ['fuera', 'AEME que no está en el portal']]
const VACIO = { id: 0, size: '', eink_colors: 'BWR', color: 'white', variant: '', has_batteries: true, battery_type: 'CR2450', battery_quantity: 2, refs: [{ zkong: '', aeme: '' }] }

function Editor({ inicial, d, onClose, onSaved }) {
  const toast = useToast()
  const confirm = useConfirm()
  const [m, setM] = useState(() => ({ ...inicial, size: inicial.size == null ? '' : String(inicial.size).replace('.', ','), refs: inicial.refs.length ? inicial.refs : [{ zkong: '', aeme: '' }] }))
  const [ocupado, setOcupado] = useState(false)
  const set = (c) => setM((x) => ({ ...x, ...c }))
  const setRef = (i, c) => set({ refs: m.refs.map((r, k) => (k === i ? { ...r, ...c } : r)) })
  const portal = d.portal
  const opcionesAeme = (actual) => {
    const lista = Object.entries(portal || {}).filter(([, p]) => p.kind === 'esl').map(([ref, p]) => ({ value: ref, label: `${ref} · ${p.name}` }))
    return actual && !portal?.[actual] ? [{ value: actual, label: `${actual} (no está en el portal)` }, ...lista] : lista
  }

  const guardar = async () => {
    setOcupado(true)
    const r = await api.deviceCatalogDo('save', { ...m, size: String(m.size) })
    setOcupado(false)
    if (!r.ok) { toast(r.error, 'err'); return }
    toast(r.relinked ? `Modelo guardado · ${r.relinked} ${r.relinked === 1 ? 'etiqueta pasa' : 'etiquetas pasan'} a este modelo` : 'Modelo guardado')
    onSaved()
  }
  const borrar = async () => {
    if (!(await confirm({ title: 'Borrar modelo', message: `¿Borrar el modelo ${pulgadas(m.size)} con sus referencias?`, danger: true, confirmText: 'Borrar' }))) return
    let r = await api.deviceCatalogDo('delete', { id: m.id })
    if (!r.ok && r.used) {
      if (!(await confirm({ title: 'Modelo en uso', message: `Lo tienen ${r.used} etiquetas de garantías: se quedarán sin modelo (y sin tipo de pila). ¿Borrarlo igualmente?`, danger: true, confirmText: 'Borrar igualmente' }))) return
      r = await api.deviceCatalogDo('delete', { id: m.id, force: true })
    }
    if (!r.ok) { toast(r.error, 'err'); return }
    toast('Modelo borrado'); onSaved()
  }

  return (
    <div className="modal-bg wr-over" onClick={(e) => e.target.classList.contains('modal-bg') && onClose()}>
      <div className="modal wd-modal wide dc-modal" onKeyDown={(e) => { if (e.key === 'Escape') onClose() }}>
        <div className="modal-h"><h3>{m.id ? 'Editar modelo' : 'Nuevo modelo'}</h3><button className="icon-btn" onClick={onClose} title="Cerrar (Esc)">✕</button></div>
        <div className="modal-body">
          <div className="dc-grid">
            <div className="field"><span className="lbl">Tamaño <span className="hint">— pulgadas</span></span><input inputMode="decimal" value={m.size} placeholder="2,6" onChange={(e) => set({ size: e.target.value })} /></div>
            <div className="field"><span className="lbl">Pantalla</span><Select block value={m.eink_colors} onChange={(v) => set({ eink_colors: v })} options={Object.entries(d.eink).map(([value, label]) => ({ value, label: `${label} (${value})` }))} /></div>
            <div className="field"><span className="lbl">Carcasa</span><Select block value={m.color} onChange={(v) => set({ color: v })} options={Object.entries(d.colors).map(([value, label]) => ({ value, label }))} /></div>
            <div className="field"><span className="lbl">Variante <span className="hint">— opcional</span></span><input value={m.variant || ''} maxLength={60} placeholder="NFC, congelado…" onChange={(e) => set({ variant: e.target.value })} /></div>
          </div>

          <div className="dc-pilas">
            <label className="wr-check"><input type="checkbox" checked={m.has_batteries} onChange={(e) => set({ has_batteries: e.target.checked })} /> Lleva pilas</label>
            {m.has_batteries ? (
              <>
                <label>Tipo <input value={m.battery_type || ''} maxLength={30} placeholder="CR2450" onChange={(e) => set({ battery_type: e.target.value.toUpperCase() })} /></label>
                {d.battery_types.filter((t) => t !== m.battery_type).map((t) => <button key={t} type="button" className="dc-chip" onClick={() => set({ battery_type: t })}>{t}</button>)}
                <label>Cantidad <input type="number" min={1} max={9} value={m.battery_quantity || ''} onChange={(e) => set({ battery_quantity: e.target.value })} /></label>
              </>
            ) : <span className="dev-mute">Sin pilas: no entra en los presupuestos de pilas.</span>}
          </div>

          <h4 className="ws-sub">Referencias</h4>
          <p className="dev-mute dc-tip">Por parejas: la ZKONG es como la da el servidor (se casa por el principio: «ZKC42V-N» encuentra «ZKC42V-NE4 (White)»); la AEME es el producto del portal. Puede faltar un lado.</p>
          <div className="dc-refs">
            <div className="dc-refs-h"><span>ZKONG</span><i /><span>AEME</span><b /></div>
            {m.refs.map((r, i) => (
              <div key={i} className="dc-ref">
                <input value={r.zkong} maxLength={80} placeholder="ZKC26V-NE4 (White)" onChange={(e) => setRef(i, { zkong: e.target.value })} />
                <Icon.link className="dc-link" />
                {portal ? <Select block value={r.aeme} placeholder="Producto del portal…" onChange={(v) => setRef(i, { aeme: v })} options={[{ value: '', label: 'Sin referencia AEME' }, ...opcionesAeme(r.aeme)]} searchable />
                  : <input value={r.aeme} maxLength={80} placeholder="AMC26V" onChange={(e) => setRef(i, { aeme: e.target.value.toUpperCase() })} />}
                <button className="icon-btn" title="Quitar" disabled={m.refs.length === 1} onClick={() => set({ refs: m.refs.filter((_, k) => k !== i) })}><Icon.trash /></button>
              </div>
            ))}
            <button className="dev-btn" onClick={() => set({ refs: [...m.refs, { zkong: '', aeme: '' }] })}><Icon.plus /> Añadir pareja</button>
          </div>
          {!portal ? <p className="wd-warn" style={{ marginTop: 12 }}><Icon.warn /> El portal AEME no responde: escribe la referencia AEME a mano.</p> : null}
        </div>
        <div className="modal-foot">
          {m.id ? <button className="btn ghost dc-del" onClick={borrar}><Icon.trash /> Borrar modelo</button> : null}
          <button className="btn ghost" onClick={onClose}>Cancelar</button>
          <button className="btn" disabled={ocupado} onClick={guardar}>{ocupado ? 'Guardando…' : 'Guardar'}</button>
        </div>
      </div>
    </div>
  )
}

export default function DeviceCatalog() {
  const [d, setD] = useState(null)
  const [q, setQ] = useState('')
  const [filtro, setFiltro] = useState('')
  const [ed, setEd] = useState(null)
  const cargar = useCallback(() => api.deviceCatalog().then((r) => setD(r.ok ? r : { error: r.error })), [])
  useEffect(() => { cargar() }, [cargar])

  const fuera = (r) => r.aeme && d?.portal && !d.portal[r.aeme]
  const texto = q.trim().toUpperCase()
  const lista = (d?.models || []).filter((m) =>
    (!texto || m.refs.some((r) => r.zkong.toUpperCase().includes(texto) || r.aeme.includes(texto)) || m.description.toUpperCase().includes(texto) || (m.battery_type || '').includes(texto))
    && (!filtro || (filtro === 'pilas' ? m.has_batteries : filtro === 'sin_pilas' ? !m.has_batteries : filtro === 'sin_aeme' ? !m.refs.some((r) => r.aeme) : m.refs.some(fuera))))
  const n = (k) => (d?.models || []).filter((m) => (k === 'pilas' ? m.has_batteries : k === 'sin_pilas' ? !m.has_batteries : k === 'sin_aeme' ? !m.refs.some((r) => r.aeme) : k === 'fuera' ? m.refs.some(fuera) : true)).length
  const anadirA = (id, ref) => { const m = d.models.find((x) => x.id === id); if (m) setEd({ ...m, refs: [...m.refs, { zkong: '', aeme: ref }] }) }

  return (
    <>
      <header className="page-head">
        <span className="sc-ic"><Icon.catalog style={{ width: 18, height: 18, fill: 'var(--primary)' }} /></span>
        <div><h1>Catálogo</h1></div>
        <span className="sub">· Modelos de etiqueta, pilas y referencias ZKONG ↔ AEME</span>
      </header>
      <div className="page-scroll"><div className="page dev-page wr-page">
        {!d ? <div className="center-load"><div className="spinner" /></div> : d.error ? <div className="dev-error"><Icon.warn /> {d.error}</div> : (
          <>
            <div className="dc-bar">
              <div className="dev-search dc-search"><Icon.search /><input value={q} placeholder="Buscar referencia, tamaño o pila…" onChange={(e) => setQ(e.target.value)} /></div>
              <span className="spacer" />
              {d.can_edit ? <button className="btn" onClick={() => setEd(VACIO)}><Icon.plus /> Nuevo modelo</button> : <span className="dev-mute">Solo consulta: lo cambia quien tiene Configuración de garantías.</span>}
            </div>
            <div className="wr-chips sm">
              {FILTROS.map(([k, l]) => (k === '' || n(k)) ? <button key={k} className={filtro === k ? 'on' : ''} onClick={() => setFiltro(k)}>{l} ({n(k)})</button> : null)}
            </div>

            <div className="dev-card">
              <div className="dev-table-wrap keep wt-wrap">
                <table className="dev-table dc-table">
                  <thead><tr><th>Modelo</th><th>Pilas</th><th>ZKONG ↔ AEME</th><th className="c">En garantías</th>{d.can_edit ? <th /> : null}</tr></thead>
                  <tbody>
                    {lista.map((m) => (
                      <tr key={m.id}>
                        <td>
                          <div className="dc-mod">
                            <b>{pulgadas(m.size)}</b>
                            <span className="wr-pill" style={{ '--pc': EINK_C[m.eink_colors] || '#64748b' }}><i />{d.eink[m.eink_colors] || m.eink_colors}</span>
                            <span className="dev-mute">{d.colors[m.color] || m.color}{m.variant ? ` · ${m.variant}` : ''}</span>
                          </div>
                        </td>
                        <td>{pilas(m) ? <span className="dc-bat"><Icon.bolt /> {pilas(m)}</span> : <span className="dev-mute">Sin pilas</span>}</td>
                        <td>
                          <ul className="dc-pairs">
                            {m.refs.map((r, i) => (
                              <li key={i}>
                                <span className="mono">{r.zkong || <span className="dev-mute">—</span>}</span>
                                <Icon.link />
                                <span className={`mono${fuera(r) ? ' dc-out' : ''}`} title={r.aeme ? (d.portal?.[r.aeme]?.name || (d.portal ? 'No está en el portal AEME' : '')) : ''}>{r.aeme || <span className="dev-mute">—</span>}{fuera(r) ? <Icon.warn /> : null}</span>
                                {r.aeme && d.portal?.[r.aeme] ? <span className="dev-mute dc-pname">{d.portal[r.aeme].name}</span> : null}
                              </li>
                            ))}
                          </ul>
                        </td>
                        <td className="c">{m.used ? <b>{m.used}</b> : <span className="wt-0">0</span>}</td>
                        {d.can_edit ? <td className="wd-row-acts"><button className="icon-btn" title="Editar" onClick={() => setEd(m)}><Icon.pencil /></button></td> : null}
                      </tr>
                    ))}
                  </tbody>
                </table>
                {!lista.length ? <p className="dev-mute" style={{ padding: 14 }}>Ningún modelo con este filtro.</p> : null}
              </div>
            </div>

            {d.portal === null ? <p className="wd-warn"><Icon.warn /> El portal AEME no responde: no se puede ver qué etiquetas del portal faltan en el catálogo.</p> : d.unmapped.length ? (
              <section className="ws-card dc-unmapped">
                <div className="ws-card-h"><div><h3><Icon.warn /> Etiquetas del portal sin modelo ({d.unmapped.length})</h3>
                  <p className="dev-mute">Sin modelo no se sabe su tamaño ni su pila: en los presupuestos de pilas salen «sin tipo». Las que ya están en garantías, primero.</p></div></div>
                <ul className="dc-un-list">
                  {d.unmapped.map((p) => (
                    <li key={p.ref}>
                      <b className="mono">{p.ref}</b><span className="dc-pname">{p.name}</span>
                      {p.used ? <em className="ws-tag">En {p.used} {p.used === 1 ? 'etiqueta' : 'etiquetas'}</em> : null}
                      {d.can_edit ? (
                        <span className="dc-un-acts">
                          <Select sm value="" placeholder="Añadir a un modelo…" searchable onChange={(id) => anadirA(id, p.ref)}
                            options={d.models.map((m) => ({ value: m.id, label: `${pulgadas(m.size)} ${d.eink[m.eink_colors] || ''} ${d.colors[m.color] || ''} · ${m.refs.map((r) => r.aeme || r.zkong).filter(Boolean).join(', ')}` }))} />
                          <button className="dev-btn" onClick={() => setEd({ ...VACIO, refs: [{ zkong: '', aeme: p.ref }] })}><Icon.plus /> Modelo nuevo</button>
                        </span>
                      ) : null}
                    </li>
                  ))}
                </ul>
              </section>
            ) : null}
          </>
        )}
      </div></div>
      {ed && <Editor inicial={ed} d={d} onClose={() => setEd(null)} onSaved={() => { setEd(null); cargar() }} />}
    </>
  )
}
