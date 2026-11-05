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
// Tintas reales de cada pantalla y color de cada carcasa.
const TINTAS = { BW: ['#111827', '#ffffff'], BWR: ['#111827', '#ffffff', '#dc2626'], BWRY: ['#111827', '#ffffff', '#dc2626', '#eab308'] }
const EINK_CORTO = { BW: 'B/W', BWR: 'B/W/R', BWRY: 'B/W/R/Y' }
const CARCASA = { white: '#ffffff', black: '#18181b' }
const CARCASA_F = { white: 'Blanca', black: 'Negra' }
// La ZKONG es la misma en blanco y en negro: el «(White)»/«(Black)» lo añadió quien la
// dio de alta. Se enseña sin él; el color va en la muestra de la carcasa.
const baseZk = (z) => String(z || '').replace(/\s*\((white|black|blanco|negro)\)/gi, '').trim()

const Tintas = ({ eink }) => <span className="dc-ink">{(TINTAS[eink] || TINTAS.BW).map((c, i) => <i key={i} style={{ background: c }} />)}</span>
const Sw = ({ color }) => <i className="dc-sw" style={{ background: CARCASA[color] || '#94a3b8' }} />

/**
 * Una fila por tamaño + pantalla. Dentro, las referencias por ZKONG: si una misma ZKONG
 * está en carcasa blanca y negra, sale una vez con una rama por carcasa.
 */
function agrupar(models) {
  const grupos = new Map()
  for (const m of models) {
    const k = m.size == null ? `x${m.id}` : `${m.size}|${m.eink_colors}`
    if (!grupos.has(k)) grupos.set(k, { key: k, size: m.size, eink: m.eink_colors, models: [], zk: new Map() })
    const g = grupos.get(k)
    g.models.push(m)
    for (const r of m.refs) {
      const zk = baseZk(r.zkong)
      const fk = zk ? zk.toUpperCase() : `~${r.aeme}`
      if (!g.zk.has(fk)) g.zk.set(fk, { key: fk, zk, vars: [] })
      g.zk.get(fk).vars.push({ m, r })
    }
  }
  const orden = { white: 0, black: 1 }
  return [...grupos.values()].map((g) => {
    g.models.sort((a, b) => (orden[a.color] ?? 2) - (orden[b.color] ?? 2))
    const refs = [...g.zk.values()]
    refs.forEach((f) => f.vars.sort((a, b) => (orden[a.m.color] ?? 2) - (orden[b.m.color] ?? 2)))
    const pilasDe = [...new Set(g.models.map((m) => pilas(m) || ''))]
    return { ...g, refs, colores: [...new Set(g.models.map((m) => m.color))], pilasDe, used: g.models.reduce((s, m) => s + m.used, 0) }
  })
}
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
        <div className="modal-h"><h3>{m.id ? 'Editar modelo' : 'Nuevo modelo'}</h3><button className="icon-btn" onClick={onClose} title="Cerrar (Esc)"><Icon.x className="ic-x" aria-hidden="true" /></button></div>
        <div className="modal-body">
          <div className="dc-grid">
            <div className="field"><span className="lbl">Tamaño <span className="hint">— pulgadas</span></span><input inputMode="decimal" value={m.size} placeholder="2,6" onChange={(e) => set({ size: e.target.value })} /></div>
            <div className="field"><span className="lbl">Pantalla</span><Select block value={m.eink_colors} onChange={(v) => set({ eink_colors: v })} options={Object.entries(d.eink).map(([value, label]) => ({ value, label: `${label} (${EINK_CORTO[value] || value})` }))} />
              <span className="dc-hint"><Tintas eink={m.eink_colors} /> {['Negro', 'blanco', 'rojo', 'amarillo'].slice(0, (TINTAS[m.eink_colors] || TINTAS.BW).length).join(', ')}</span></div>
            <div className="field"><span className="lbl">Carcasa</span>
              <div className="dc-seg">
                {Object.keys(d.colors).map((c) => (
                  <button key={c} type="button" className={m.color === c ? 'on' : ''} onClick={() => set({ color: c })}><Sw color={c} />{CARCASA_F[c] || d.colors[c]}</button>
                ))}
              </div></div>
            <div className="field"><span className="lbl">Variante <span className="hint">— opcional</span></span><input value={m.variant || ''} maxLength={60} placeholder="NFC, congelado…" onChange={(e) => set({ variant: e.target.value })} /></div>
          </div>

          <div className="dc-pilas">
            <label className="wr-check"><input type="checkbox" checked={m.has_batteries} onChange={(e) => set(e.target.checked ? { has_batteries: true, battery_type: m.battery_type || 'CR2450', battery_quantity: m.battery_quantity || 1 } : { has_batteries: false })} /> Lleva pilas</label>
            {m.has_batteries ? (
              <>
                <label>Tipo <input value={m.battery_type || ''} maxLength={30} placeholder="CR2450" onChange={(e) => set({ battery_type: e.target.value.toUpperCase() })} /></label>
                {d.battery_types.filter((t) => t !== m.battery_type).map((t) => <button key={t} type="button" className="dc-chip" onClick={() => set({ battery_type: t })}>{t}</button>)}
                <span className="dc-qty">Cantidad
                  <button type="button" onClick={() => set({ battery_quantity: Math.max(1, (Number(m.battery_quantity) || 1) - 1) })}>−</button>
                  <input type="number" min={1} max={9} value={m.battery_quantity || ''} onChange={(e) => set({ battery_quantity: e.target.value })} />
                  <button type="button" onClick={() => set({ battery_quantity: Math.min(9, (Number(m.battery_quantity) || 0) + 1) })}>+</button>
                </span>
              </>
            ) : <span className="dev-mute">Sin pilas: no entra en los presupuestos de pilas.</span>}
          </div>

          <h4 className="ws-sub">Referencias</h4>
          <p className="dev-mute dc-tip">Por parejas: la ZKONG es como la da el servidor (se casa por el principio: «ZKC42V-N» encuentra «ZKC42V-NE4 (White)»); la AEME es el producto del portal. Puede faltar un lado.</p>
          <div className="dc-refs">
            <div className="dc-refs-h"><span>ZKONG</span><i /><span>AEME</span><b /></div>
            {m.refs.map((r, i) => (
              <div key={i} className="dc-ref">
                <input value={r.zkong} maxLength={80} placeholder="ZKC26V-NE4" onChange={(e) => setRef(i, { zkong: e.target.value })} />
                <span className="dc-link">⇄</span>
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
  const pasa = (m, k) => (k === 'pilas' ? m.has_batteries : k === 'sin_pilas' ? !m.has_batteries : k === 'sin_aeme' ? !m.refs.some((r) => r.aeme) : k === 'fuera' ? m.refs.some(fuera) : true)
  const busca = (m) => !texto || m.refs.some((r) => r.zkong.toUpperCase().includes(texto) || r.aeme.includes(texto))
    || m.description.toUpperCase().includes(texto) || (m.battery_type || '').includes(texto) || pulgadas(m.size).startsWith(texto.replace('.', ','))
  const lista = agrupar((d?.models || []).filter((m) => busca(m) && pasa(m, filtro)))
  const n = (k) => agrupar((d?.models || []).filter((m) => pasa(m, k))).length
  const portalDe = (r) => (r.aeme ? d.portal?.[r.aeme]?.name : null)
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
                  <thead><tr><th>Modelo y pantalla</th><th>Pilas</th><th><div className="dc-list-h"><span>Ref. ZKONG</span><span><Sw color="white" /> AEME blanca</span><span><Sw color="black" /> AEME negra</span><span>Portal</span></div></th><th className="c">En garantías</th>{d.can_edit ? <th className="r">Editar</th> : null}</tr></thead>
                  <tbody>
                    {lista.map((g) => {
                      const sinTam = g.size == null
                      const variantes = [...new Set(g.models.map((m) => m.variant).filter(Boolean))]
                      return (
                        <tr key={g.key} className={sinTam || !g.refs.length ? 'dc-bad-row' : ''}>
                          <td>
                            <div className="dc-mod">
                              <span className="dc-size">{sinTam ? '¿?' : pulgadas(g.size)}</span>
                              <div className="dc-mod-t">
                                <span className="dc-tag"><Tintas eink={g.eink} />{d.eink[g.eink] || g.eink} <small>({EINK_CORTO[g.eink] || g.eink})</small></span>
                                {variantes.map((v) => <span key={v} className="dc-tag var">{v}</span>)}
                                {sinTam ? <span className="dc-warn"><Icon.warn /> Sin tamaño{g.refs.length ? '' : ' ni referencias'}</span> : null}
                              </div>
                            </div>
                          </td>
                          <td>
                            {g.pilasDe.length === 1
                              ? (g.pilasDe[0] ? <span className="dc-bat"><Icon.bolt /> {g.pilasDe[0]}</span> : <span className="dc-nobat">Sin pilas</span>)
                              : g.models.map((m) => <div key={m.id} className="dc-bat-v"><Sw color={m.color} />{pilas(m) ? <span className="dc-bat"><Icon.bolt /> {pilas(m)}</span> : <span className="dc-nobat">Sin pilas</span>}</div>)}
                          </td>
                          <td>
                            {/* Lista: una línea por referencia ZKONG; a la derecha, su AEME en carcasa
                                blanca y en negra (columnas fijas para leerlo de un vistazo). */}
                            <ul className="dc-list">
                              {g.refs.map((f) => {
                                const por = (c) => f.vars.filter((v) => v.m.color === c)
                                const nombre = f.vars.map((v) => portalDe(v.r)).find(Boolean)
                                const celda = (c) => por(c).map(({ m, r }, i) => (
                                  <button key={i} type="button" className={`dc-ae-b${fuera(r) ? ' out' : ''}`} disabled={!d.can_edit} onClick={() => setEd(m)}
                                    title={[`Carcasa ${(CARCASA_F[c] || c).toLowerCase()}`, portalDe(r) || (fuera(r) ? 'No está en el portal AEME' : '')].filter(Boolean).join(' · ')}>
                                    <Sw color={c} /><span className="dc-ae">{r.aeme || '—'}</span>{fuera(r) ? <Icon.warn /> : null}
                                  </button>
                                ))
                                return (
                                  <li key={f.key}>
                                    <span className={`dc-zk${f.zk ? '' : ' none'}`}>{f.zk || 'Sin ZKONG'}</span>
                                    <span className="dc-c">{celda('white')}</span>
                                    <span className="dc-c">{celda('black')}</span>
                                    <span className="dc-pname" title={nombre || ''}>{nombre || ''}</span>
                                  </li>
                                )
                              })}
                              {!g.refs.length ? <li className="dc-empty"><span className="dc-nobat">Sin referencias</span></li> : null}
                            </ul>
                          </td>
                          <td className="c">{g.used ? <span className="dc-used on"><i />{g.used} {g.used === 1 ? 'etiqueta' : 'etiquetas'}</span> : <span className="dc-used">0</span>}</td>
                          {d.can_edit ? (
                            <td className="r">
                              <div className="dc-acts">
                                {g.models.map((m) => (
                                  <button key={m.id} className="icon-btn" title={g.models.length > 1 ? `Editar carcasa ${(CARCASA_F[m.color] || '').toLowerCase()}` : 'Editar modelo'} onClick={() => setEd(m)}>
                                    <Icon.pencil />{g.models.length > 1 ? <Sw color={m.color} /> : null}
                                  </button>
                                ))}
                              </div>
                            </td>
                          ) : null}
                        </tr>
                      )
                    })}
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
