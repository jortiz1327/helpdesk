import { useState, useEffect, useCallback, useMemo, useRef } from 'react'
import { api } from '../api.js'
import { Icon } from '../icons.jsx'
import { useToast, useConfirm } from '../App.jsx'
import Select from './Select.jsx'

/* -------------------------------------------------------------------------
 * CENTRO DE AYUDA de Gestión de garantías: artículos por apartado, con buscador y
 * relacionados. Los de serie vienen del servidor (App\Services\WarrantyHelp); quien
 * tiene la configuración los cambia aquí mismo, añade otros y los oculta. El tour
 * enlaza cada paso con su artículo («Más información»).
 * Formato del texto: párrafos separados por una línea en blanco, «## » subtítulo,
 * «- » lista, «1. » lista numerada y **negrita**.
 * ---------------------------------------------------------------------- */

const APARTADO = {
  primeros: [Icon.play, '#2563eb'], garantias: [Icon.shield, '#12925a'], revision: [Icon.tag, '#c27803'],
  documentos: [Icon.doc, '#7c3aed'], zkong: [Icon.zkong, '#0d64c0'], equipo: [Icon.chart, '#e11d48'], config: [Icon.settings, '#64748b'],
}
const fechaCorta = (s) => new Date(String(s).replace(' ', 'T')).toLocaleString('es-ES', { day: 'numeric', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' })
const sinAcentos = (s) => (s || '').normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase()

function ApartadoIc({ section, sm }) {
  const [Ic, color] = APARTADO[section] || [Icon.help, '#64748b']
  return <span className={`gd-ic${sm ? ' sm' : ''}`} style={{ '--gc': color }}><Ic /></span>
}

/* **negrita** dentro de una línea. */
const enLinea = (t) => t.split(/(\*\*[^*]+\*\*)/g).map((x, i) => (/^\*\*[^*]+\*\*$/.test(x) ? <b key={i}>{x.slice(2, -2)}</b> : x))

/** El texto de un artículo, sin HTML: párrafos, subtítulos y listas. */
export function TextoAyuda({ body }) {
  const bloques = []
  let lista = null
  const cerrar = () => { if (lista) { bloques.push(lista); lista = null } }
  for (const linea of body.split('\n')) {
    const l = linea.trim()
    const ul = /^- (.*)/.exec(l), ol = /^\d+\. (.*)/.exec(l)
    if (ul || ol) {
      const tipo = ul ? 'ul' : 'ol'
      if (!lista || lista.tipo !== tipo) { cerrar(); lista = { tipo, items: [] } }
      lista.items.push((ul || ol)[1])
      continue
    }
    cerrar()
    const ult = bloques[bloques.length - 1]
    if (!l) { if (ult) ult.fin = true; continue }   // una línea en blanco corta el párrafo
    if (l.startsWith('## ')) bloques.push({ tipo: 'h', t: l.slice(3) })
    else if (ult?.tipo === 'p' && !ult.fin) ult.t += ` ${l}`
    else bloques.push({ tipo: 'p', t: l })
  }
  cerrar()
  return (
    <div className="hc-texto">
      {bloques.map((b, i) => (b.tipo === 'h' ? <h3 key={i}>{b.t}</h3>
        : b.tipo === 'p' ? <p key={i}>{enLinea(b.t)}</p>
        : b.tipo === 'ul' ? <ul key={i}>{b.items.map((x, k) => <li key={k}>{enLinea(x)}</li>)}</ul>
        : <ol key={i}>{b.items.map((x, k) => <li key={k}>{enLinea(x)}</li>)}</ol>))}
    </div>
  )
}

/** Cambiar un artículo (de serie o añadido) o escribir uno nuevo. */
function Editor({ a, d, onClose, onSaved }) {
  const toast = useToast()
  const confirm = useConfirm()
  const [f, setF] = useState(() => ({ key: '', section: 'primeros', title: '', summary: '', body: '', related: [], active: true, ...(a || {}) }))
  const [busy, setBusy] = useState(false)
  const set = (k) => (v) => setF((s) => ({ ...s, [k]: v?.target ? v.target.value : v }))
  const guardar = async () => {
    setBusy(true)
    const r = await api.warrantyHelpDo('save', { key: f.key, section: f.section, title: f.title, summary: f.summary, body: f.body, related: f.related, active: f.active })
    setBusy(false)
    if (!r.ok) { toast(r.error || 'No se pudo guardar', 'err'); return }
    toast(a ? 'Artículo guardado' : 'Artículo añadido')
    onSaved(r.key)
  }
  const quitar = async () => {
    const original = !a.custom
    if (!(await confirm(original
      ? { title: 'Texto original', message: `¿Volver «${a.title}» al texto de serie? Se pierden los cambios.`, confirmText: 'Volver al original' }
      : { title: 'Quitar artículo', message: `¿Quitar «${a.title}»? No se puede deshacer.`, danger: true, confirmText: 'Quitar' }))) return
    const r = await api.warrantyHelpDo('remove', { key: a.key })
    if (!r.ok) { toast(r.error || 'No se pudo', 'err'); return }
    toast(original ? 'Texto original recuperado' : 'Artículo quitado')
    onSaved(original ? a.key : null)
  }
  const otros = d.articles.filter((x) => x.key !== f.key)
  return (
    <div className="modal-bg wr-over" onMouseDown={(e) => e.target.classList.contains('modal-bg') && onClose()}>
      <div className="modal" style={{ maxWidth: 760 }}>
        <div className="modal-head"><h3 className="hc-ed-h"><Icon.pencil /> {a ? 'Editar artículo' : 'Nuevo artículo'}</h3><button className="x" onClick={onClose}>×</button></div>
        <div className="modal-body gd-form">
          <div className="grid2">
            <label className="field"><span className="lbl">Título <em>*</em></span>
              <input value={f.title} maxLength={120} onChange={set('title')} placeholder="Cómo se prepara una remesa" /></label>
            <div className="field"><span className="lbl">Apartado</span>
              <Select block value={f.section} onChange={set('section')} options={Object.entries(d.sections).map(([k, l]) => ({ value: k, label: l }))} /></div>
          </div>
          <label className="field"><span className="lbl">Resumen <span className="hint">(una línea; sale en la lista y en el buscador)</span></span>
            <input value={f.summary || ''} maxLength={300} onChange={set('summary')} placeholder="Para qué sirve y cuándo se usa" /></label>
          <label className="field"><span className="lbl hc-lbl-f">Texto <em>*</em><span className="hint">Formato: texto con marcas sencillas</span></span>
            <textarea className="hc-body-ed" rows={9} maxLength={20000} value={f.body} onChange={set('body')} /></label>
          <div className="hc-formato">
            <code>Línea en blanco: párrafo</code><i>·</i><code>## subtítulo</code><i>·</i><code>- lista</code><i>·</i><code>1. lista numerada</code><i>·</i><code>**así** negrita</code>
          </div>
          <div className="field"><span className="lbl">Artículos relacionados <span className="hint">(hasta 8)</span></span>
            <div className="hc-rels">
              {f.related.map((k) => {
                const x = d.articles.find((y) => y.key === k)
                return x ? <span key={k} className="hc-chip">{x.title}<button type="button" title="Quitar" onClick={() => setF((s) => ({ ...s, related: s.related.filter((y) => y !== k) }))}><Icon.x /></button></span> : null
              })}
              {f.related.length < 8 ? (
                <Select value="" searchable placeholder="+ Añadir artículo…" onChange={(v) => v && setF((s) => ({ ...s, related: [...s.related, v] }))}
                  options={otros.filter((x) => !f.related.includes(x.key)).map((x) => ({ value: x.key, label: `${x.title} · ${d.sections[x.section]}` }))} />
              ) : null}
            </div>
          </div>
          <label className="wr-check"><input type="checkbox" checked={f.active} onChange={(e) => setF((s) => ({ ...s, active: e.target.checked }))} /> Visible para todos <span className="dev-mute">(si no, solo lo ve quien gestiona la configuración)</span></label>
        </div>
        <div className="modal-foot">
          {a?.custom ? <button className="btn ghost" style={{ color: 'var(--danger)' }} onClick={quitar}><Icon.trash /> Quitar</button> : null}
          {a && !a.custom && a.edited ? <button className="btn ghost" onClick={quitar}><Icon.refresh /> Texto original</button> : null}
          <span className="spacer" />
          <button className="btn ghost" onClick={onClose}>Cancelar</button>
          <button className="btn" disabled={busy || !f.title.trim() || !f.body.trim()} onClick={guardar}><Icon.save /> {busy ? 'Guardando…' : a ? 'Guardar cambios' : 'Guardar'}</button>
        </div>
      </div>
    </div>
  )
}

/** articulo: clave a abrir al entrar (desde el tour) · onOpened: ya se ha abierto. */
export default function WarrantyHelpCenter({ articulo = null, onOpened }) {
  const [d, setD] = useState(null)
  const [abierto, setAbierto] = useState(articulo)
  const [q, setQ] = useState('')
  const [ed, setEd] = useState(undefined)   // undefined cerrado · null nuevo · artículo
  const main = useRef(null)
  const cargar = useCallback(() => api.warrantyHelp().then((r) => setD(r.ok ? r : { error: r.error || 'No se pudo cargar' })), [])
  useEffect(() => { cargar() }, [cargar])
  useEffect(() => { if (articulo) { setAbierto(articulo); setQ(''); onOpened?.() } }, [articulo])

  const porClave = useMemo(() => Object.fromEntries((d?.articles || []).map((a) => [a.key, a])), [d])
  const porApartado = useMemo(() => (d ? Object.keys(d.sections).map((s) => [s, d.articles.filter((a) => a.section === s)]).filter(([, l]) => l.length) : []), [d])
  const resultados = useMemo(() => {
    const t = sinAcentos(q.trim())
    if (!t || !d) return []
    const palabras = t.split(/\s+/)
    return d.articles
      .map((a) => {
        const tit = sinAcentos(a.title), resto = sinAcentos(`${a.summary} ${a.body} ${d.sections[a.section]}`)
        if (!palabras.every((p) => tit.includes(p) || resto.includes(p))) return null
        return [a, palabras.filter((p) => tit.includes(p)).length]
      })
      .filter(Boolean).sort((x, y) => y[1] - x[1]).map(([a]) => a)
  }, [q, d])

  const a = abierto ? porClave[abierto] : null
  const ir = (k) => { setAbierto(k); setQ(''); main.current?.scrollTo?.({ top: 0 }) }
  const lista = d?.articles || []
  const pos = a ? lista.indexOf(a) : -1
  const apartados = (
    <div className="hc-grid">
      {porApartado.map(([s, l]) => (
        <section key={s} className="dev-card hc-sec">
          <h3><ApartadoIc section={s} /> <span>{d.sections[s]}<small>{l.length} {l.length === 1 ? 'artículo' : 'artículos'}</small></span></h3>
          <ul>
            {l.map((x) => (
              <li key={x.key}><button className={`${x.active ? '' : 'off'}${abierto === x.key ? ' on' : ''}`} onClick={() => ir(x.key)}>{x.title}</button></li>
            ))}
          </ul>
        </section>
      ))}
    </div>
  )

  return (
    <>
      <header className="page-head">
        <span className="sc-ic"><Icon.help style={{ width: 18, height: 18, fill: 'var(--primary)' }} /></span>
        <div><h1>Centro de ayuda</h1></div>
        <span className="sub">· Cómo se usa Gestión de garantías, apartado por apartado</span>
        <span className="spacer" />
        {d?.manage ? <button className="btn" onClick={() => setEd(null)}><Icon.plus /> Nuevo artículo</button> : null}
      </header>
      <div className="page-scroll" ref={main}><div className="page dev-page wr-page">
        {!d ? <div className="center-load"><div className="spinner" /></div> : d.error ? <div className="dev-error"><Icon.warn /> {d.error}</div> : (
          <div className="hc-layout">
            <nav className="dev-card hc-nav" aria-label="Apartados">
              <button className={`hc-nav-home${!a && !q ? ' on' : ''}`} onClick={() => { setAbierto(null); setQ('') }}><Icon.home /> Inicio</button>
              {porApartado.map(([s, l], n) => (
                <div key={s}>
                  <div className={`ws-nav-h${a?.section === s ? ' on' : ''}`}>{n + 1}. {d.sections[s]}</div>
                  {l.map((x) => (
                    <button key={x.key} className={`${abierto === x.key ? 'on' : ''}${x.active ? '' : ' off'}`} onClick={() => ir(x.key)}>
                      <span>{x.title}</span>{abierto === x.key ? <i /> : null}
                    </button>
                  ))}
                </div>
              ))}
            </nav>

            <div className="hc-main">
              <div className="dev-card hc-buscar">
                <div className="dev-search"><Icon.search />
                  <input value={q} onChange={(e) => setQ(e.target.value)} placeholder="Busca en la ayuda: cerrar, remesa, sustitución, permisos…" />
                  {q ? <button className="dev-x" onClick={() => setQ('')} title="Borrar">×</button> : null}
                </div>
              </div>

              {q.trim() ? (
                <section className="dev-card hc-res">
                  <h3>{resultados.length ? `${resultados.length} ${resultados.length === 1 ? 'artículo' : 'artículos'}` : 'Nada coincide con la búsqueda'}</h3>
                  {resultados.map((x) => (
                    <button key={x.key} className="hc-fila" onClick={() => ir(x.key)}>
                      <ApartadoIc section={x.section} sm />
                      <span><b>{x.title}</b><small>{d.sections[x.section]}{x.summary ? ` · ${x.summary}` : ''}</small></span>
                    </button>
                  ))}
                </section>
              ) : a ? (
                <article className="dev-card hc-art">
                  <div className="hc-migas">
                    <button className="wf-link" onClick={() => setAbierto(null)}>Centro de ayuda</button>
                    <Icon.chevron style={{ transform: 'rotate(-90deg)' }} />
                    <span>{d.sections[a.section]}</span>
                    <span className="spacer" />
                    {!a.active ? <em className="ws-tag off">Oculto</em> : null}
                    {d.manage ? <button className="dev-btn sm" onClick={() => setEd(a)}><Icon.pencil /> Editar</button> : null}
                  </div>
                  <h1>{a.title}</h1>
                  {a.summary ? <p className="hc-lead">{a.summary}</p> : null}
                  {a.edited && a.updated_at ? <p className="hc-edit-info">Última edición: {fechaCorta(a.updated_at)}{a.updated_by ? ` por ${a.updated_by}` : ''}</p> : null}
                  <hr className="hc-sep" />
                  <TextoAyuda body={a.body} />
                  {a.related.length ? (
                    <div className="hc-rel">
                      <h4>Artículos relacionados</h4>
                      <div className="hc-rel-list">
                        {a.related.map((k) => porClave[k]).filter(Boolean).map((x) => (
                          <button key={x.key} className="hc-rel-i" onClick={() => ir(x.key)}>
                            <ApartadoIc section={x.section} />
                            <span><b>{x.title}</b><small><em>{d.sections[x.section]} · </em>{x.summary}</small></span>
                            <Icon.arrowRight />
                          </button>
                        ))}
                      </div>
                    </div>
                  ) : null}
                  <div className="hc-pie">
                    {pos > 0 ? (
                      <button className="hc-paso" onClick={() => ir(lista[pos - 1].key)}>
                        <Icon.arrowRight style={{ transform: 'rotate(180deg)' }} />
                        <span><small>Anterior</small><b>{lista[pos - 1].title}</b></span>
                      </button>
                    ) : <span />}
                    {pos < lista.length - 1 ? (
                      <button className="hc-paso sig" onClick={() => ir(lista[pos + 1].key)}>
                        <span><small>Siguiente</small><b>{lista[pos + 1].title}</b></span>
                        <Icon.arrowRight />
                      </button>
                    ) : <span />}
                  </div>
                </article>
              ) : apartados}
              {/* En móvil no hay índice: debajo del artículo, los apartados. */}
              {a && !q.trim() ? <div className="hc-movil"><h4>Apartados de ayuda</h4>{apartados}</div> : null}
            </div>
          </div>
        )}
      </div></div>
      {ed !== undefined ? <Editor a={ed} d={d} onClose={() => setEd(undefined)} onSaved={(k) => { setEd(undefined); cargar(); setAbierto(k) }} /> : null}
    </>
  )
}
