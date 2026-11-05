import { useState, useEffect, useCallback, useMemo, useRef } from 'react'
import { api } from '../api.js'
import { Icon } from '../icons.jsx'
import { useToast, useConfirm } from '../App.jsx'
import Select from './Select.jsx'
import { useDialog } from './a11y.js'

/* -------------------------------------------------------------------------
 * PROTOCOLOS Y GUÍAS de garantías: documentación operativa (cómo se trabaja, condiciones
 * de garantía, guías técnicas, plantillas…), no la ayuda de la app. Cada documento es
 * general o de unas categorías o clientes; la ficha de cada garantía enseña los suyos.
 * Consultar: quien entra a garantías · subir y cambiar: quien tiene la configuración.
 * ---------------------------------------------------------------------- */

const TIPO = {
  protocolos: [Icon.list, '#2563eb'], condiciones: [Icon.shield, '#12925a'], tecnicas: [Icon.settings, '#c27803'],
  plantillas: [Icon.mail, '#7c3aed'], clientes: [Icon.building, '#e11d48'], operativa: [Icon.checkSquare, '#0891b2'],
}
const fechaCorta = (s) => (s ? new Date(s.replace(' ', 'T')).toLocaleDateString('es-ES', { day: 'numeric', month: 'short', year: 'numeric' }) : '')
const peso = (b) => (b >= 1048576 ? `${(b / 1048576).toFixed(1)} MB` : `${Math.max(1, Math.round(b / 1024))} KB`)
const ambito = (g) => (g.categories.length || g.clients.length ? [...g.categories, ...g.clients].join(' · ') : 'General')
const seVe = (g) => g.mime === 'application/pdf' || g.mime.startsWith('image/')

function TipoIc({ kind, sm }) {
  const [Ic, color] = TIPO[kind] || [Icon.file, '#64748b']
  return <span className={`gd-ic${sm ? ' sm' : ''}`} style={{ '--gc': color }}><Ic /></span>
}

/** Visor: PDF e imágenes aquí mismo; lo demás, a descargar. */
export function VisorGuia({ g, onClose }) {
  useEffect(() => {
    const k = (e) => e.key === 'Escape' && onClose()
    window.addEventListener('keydown', k)
    return () => window.removeEventListener('keydown', k)
  }, [onClose])
  const url = api.warrantyGuideUrl(g.id)
  return (
    <div className="modal-bg wr-over" onMouseDown={(e) => e.target.classList.contains('modal-bg') && onClose()}>
      <div className="modal gd-visor" role="dialog" aria-modal="true" aria-label={g.title}>
        <div className="modal-head">
          <h3>{g.title}{g.version ? <small className="dev-mute"> · v{g.version}</small> : null}</h3>
          <a className="dev-btn" href={api.warrantyGuideUrl(g.id, true)}><Icon.download /> Descargar</a>
          <button className="x" onClick={onClose}>×</button>
        </div>
        {seVe(g)
          ? (g.mime === 'application/pdf' ? <iframe title={g.title} src={url} /> : <div className="gd-visor-img"><img src={url} alt={g.title} /></div>)
          : <div className="gd-visor-no"><TipoIc kind={g.kind} /><p>Este tipo de archivo ({g.file_name}) no se puede ver aquí.</p>
              <a className="btn" href={api.warrantyGuideUrl(g.id, true)}><Icon.download /> Descargar</a></div>}
      </div>
    </div>
  )
}

/** Alta o cambio de un documento. */
function Formulario({ g, meta, onClose, onSaved }) {
  const toast = useToast()
  const dlg = useDialog(null, g?.id ? 'Editar guía' : 'Nueva guía')
  const confirm = useConfirm()
  const [f, setF] = useState(() => ({ title: '', description: '', kind: 'protocolos', language: 'es', categories: [], clients: [], version: '', keywords: '', active: true, ...(g || {}) }))
  const [algunas, setAlgunas] = useState(() => !!(g && (g.categories.length || g.clients.length)))
  const [archivo, setArchivo] = useState(null)
  const [busy, setBusy] = useState(false)
  const input = useRef(null)
  const set = (k) => (v) => setF((s) => ({ ...s, [k]: v?.target ? v.target.value : v }))
  const guardar = async () => {
    const fd = new FormData()
    if (g?.id) fd.append('id', g.id)
    for (const k of ['title', 'description', 'kind', 'language', 'version', 'keywords']) fd.append(k, f[k] || '')
    fd.append('active', f.active ? '1' : '0')
    fd.append('categories', JSON.stringify(algunas ? f.categories : []))
    fd.append('clients', JSON.stringify(algunas ? f.clients.map((c) => c.trim()).filter(Boolean) : []))
    if (archivo) fd.append('file', archivo)
    setBusy(true)
    const r = await api.saveWarrantyGuide(fd)
    setBusy(false)
    if (!r.ok) { toast(r.error || 'No se pudo guardar', 'err'); return }
    toast(g?.id ? 'Documento guardado' : 'Documento subido')
    onSaved()
  }
  const borrar = async () => {
    if (!(await confirm({ title: 'Quitar documento', message: `¿Quitar «${g.title}» y su archivo? No se puede deshacer.`, danger: true, confirmText: 'Quitar' }))) return
    const r = await api.deleteWarrantyGuide(g.id)
    if (!r.ok) { toast(r.error || 'No se pudo quitar', 'err'); return }
    toast('Documento quitado')
    onSaved()
  }
  const falta = !f.title.trim() || (!g?.id && !archivo) || (algunas && !f.categories.length && !f.clients.some((c) => c.trim()))
  return (
    <div className="modal-bg wr-over" onMouseDown={(e) => e.target.classList.contains('modal-bg') && onClose()}>
      <div className="modal" style={{ maxWidth: 640 }} {...dlg}>
        <div className="modal-head"><h3>{g?.id ? 'Editar documento' : 'Nuevo documento'}</h3><button className="x" onClick={onClose}>×</button></div>
        <div className="modal-body gd-form">
          <label className="field"><span className="lbl">Título <em>*</em></span>
            <input value={f.title} maxLength={160} onChange={set('title')} placeholder="Procedimiento de garantías Expofarm" /></label>
          <div className="grid2">
            <div className="field"><span className="lbl">Tipo</span>
              <Select block value={f.kind} onChange={set('kind')} options={Object.entries(meta.kinds).map(([k, l]) => ({ value: k, label: l }))} /></div>
            <div className="grid2">
              <div className="field"><span className="lbl">Idioma</span>
                <Select block value={f.language} onChange={set('language')} options={Object.entries(meta.languages).map(([k, l]) => ({ value: k, label: l }))} /></div>
              <label className="field"><span className="lbl">Versión</span>
                <input value={f.version || ''} maxLength={20} onChange={set('version')} placeholder="1.0" /></label>
            </div>
          </div>
          <label className="field"><span className="lbl">Descripción</span>
            <textarea rows={2} maxLength={1000} value={f.description || ''} onChange={set('description')} placeholder="Para qué sirve y cuándo se usa" /></label>
          <div className="field"><span className="lbl">Para qué garantías</span>
            <div className="dc-seg">
              <button type="button" className={!algunas ? 'on' : ''} onClick={() => setAlgunas(false)}>Todas (general)</button>
              <button type="button" className={algunas ? 'on' : ''} onClick={() => setAlgunas(true)}>Solo algunas</button>
            </div>
          </div>
          {algunas ? (
            <div className="grid2">
              <div className="field"><span className="lbl">Categorías</span>
                <Select block multiple value={f.categories} placeholder="Ninguna" onChange={set('categories')} options={meta.categories.map((c) => ({ value: c, label: c }))} /></div>
              <label className="field"><span className="lbl">Clientes que contienen</span>
                <input value={f.clients.join(', ')} placeholder="Expofarm, …" onChange={(e) => setF((s) => ({ ...s, clients: e.target.value.split(',').map((x) => x.trimStart()) }))} /></label>
            </div>
          ) : null}
          <label className="field"><span className="lbl">Palabras clave <span className="hint">(para el buscador)</span></span>
            <input value={f.keywords || ''} maxLength={500} onChange={set('keywords')} placeholder="rma, devolución, pilas" /></label>
          <div className="field"><span className="lbl">Archivo {g?.id ? null : <em>*</em>} <span className="hint">(PDF, Word, Excel, PowerPoint, imagen o texto; hasta {meta.max_mb} MB)</span></span>
            <div className="gd-file">
              <input ref={input} type="file" hidden accept={meta.extensions.map((e) => `.${e}`).join(',')} onChange={(e) => setArchivo(e.target.files?.[0] || null)} />
              <button type="button" className="dev-btn" onClick={() => input.current?.click()}><Icon.upload /> {archivo || g?.id ? 'Cambiar archivo' : 'Elegir archivo'}</button>
              <span className="dev-mute">{archivo ? `${archivo.name} · ${peso(archivo.size)}` : g?.id ? `${g.file_name} · ${peso(g.size)}` : 'Ninguno elegido'}</span>
            </div>
          </div>
          <label className="wr-check"><input type="checkbox" checked={f.active} onChange={(e) => setF((s) => ({ ...s, active: e.target.checked }))} /> Visible para todos <span className="dev-mute">(si no, solo lo ve quien gestiona la documentación)</span></label>
        </div>
        <div className="modal-foot">
          {g?.id ? <button className="btn ghost" style={{ color: 'var(--danger)' }} onClick={borrar}><Icon.trash /> Quitar</button> : null}
          <span className="spacer" />
          <button className="btn ghost" onClick={onClose}>Cancelar</button>
          <button className="btn" disabled={busy || falta} onClick={guardar}><Icon.save /> {busy ? 'Guardando…' : g?.id ? 'Guardar' : 'Subir'}</button>
        </div>
      </div>
    </div>
  )
}

function Tarjeta({ g, kinds, manage, onVer, onEdit }) {
  return (
    <article className={`gd-card${g.active ? '' : ' off'}`}>
      <div className="gd-card-h">
        <TipoIc kind={g.kind} />
        <div>
          <h4>{g.title}</h4>
          {g.description ? <p>{g.description}</p> : null}
        </div>
        {manage ? <button className="icon-btn" title="Editar" onClick={onEdit}><Icon.pencil /></button> : null}
      </div>
      <div className="gd-tags">
        <em className="k" style={{ '--gc': (TIPO[g.kind] || [])[1] }}>{kinds[g.kind] || g.kind}</em>
        <em>{ambito(g)}</em>
        <em>{g.language.toUpperCase()}</em>
        {g.version ? <em>v{g.version}</em> : null}
        {!g.active ? <em className="off">Oculto</em> : null}
      </div>
      <div className="gd-card-f">
        <span className="dev-mute">Actualizado {fechaCorta(g.updated_at)}</span>
        <span className="spacer" />
        <button className="dev-btn sm" onClick={onVer}><Icon.eye /> Ver</button>
        <a className="dev-btn sm" href={api.warrantyGuideUrl(g.id, true)}><Icon.download /> Descargar</a>
      </div>
    </article>
  )
}

export default function WarrantyGuides() {
  const [d, setD] = useState(null)
  const [q, setQ] = useState('')
  const [tipo, setTipo] = useState('')
  const [donde, setDonde] = useState('')
  const [idioma, setIdioma] = useState('')
  const [form, setForm] = useState(undefined)   // undefined cerrado; null nuevo; documento editar
  const [ver, setVer] = useState(null)
  const cargar = useCallback(() => api.warrantyGuides().then((r) => setD(r.ok ? r : { error: r.error || 'No se pudo cargar' })), [])
  useEffect(() => { cargar() }, [cargar])

  const ambitos = useMemo(() => {
    const s = new Set()
    for (const g of d?.guides || []) [...g.categories, ...g.clients].forEach((x) => s.add(x))
    return [...s].sort((a, b) => a.localeCompare(b, 'es'))
  }, [d])
  const filtradas = useMemo(() => {
    const t = q.trim().toLowerCase()
    return (d?.guides || []).filter((g) => (!tipo || g.kind === tipo) && (!idioma || g.language === idioma)
      && (!donde || (donde === '_general' ? !g.categories.length && !g.clients.length : [...g.categories, ...g.clients].includes(donde)))
      && (!t || [g.title, g.description, g.keywords, d.kinds[g.kind], ambito(g)].join(' ').toLowerCase().includes(t)))
  }, [d, q, tipo, donde, idioma])
  const grupos = d ? Object.keys(d.kinds).map((k) => [k, filtradas.filter((g) => g.kind === k)]).filter(([, l]) => l.length) : []
  const filtrando = q || tipo || donde || idioma

  return (
    <>
      <header className="page-head">
        <span className="sc-ic"><Icon.file style={{ width: 18, height: 18, fill: 'var(--primary)' }} /></span>
        <div><h1>Protocolos y guías</h1></div>
        <span className="sub">· Cómo se trabaja: procedimientos, condiciones de garantía, guías técnicas y plantillas</span>
        <span className="spacer" />
        {d?.manage ? <button className="btn" onClick={() => setForm(null)}><Icon.plus /> Nuevo documento</button> : null}
      </header>
      <div className="page-scroll"><div className="page dev-page wr-page">
        {!d ? <div className="center-load"><div className="spinner" /></div> : d.error ? <div className="dev-error"><Icon.warn /> {d.error}</div> : (
          <>
            <div className="dev-card gd-filtros">
              <div className="wi-bar">
                <div className="dev-search"><Icon.search />
                  <input value={q} onChange={(e) => setQ(e.target.value)} placeholder="Buscar por título, descripción, palabras clave o cliente…" />
                  {q ? <button className="dev-x" onClick={() => setQ('')} title="Borrar">×</button> : null}
                </div>
                <Select value={tipo} onChange={setTipo} options={[{ value: '', label: 'Todos los tipos' }, ...Object.entries(d.kinds).map(([k, l]) => ({ value: k, label: l }))]} />
                <Select value={donde} onChange={setDonde} searchable options={[{ value: '', label: 'Cualquier ámbito' }, { value: '_general', label: 'General' }, ...ambitos.map((a) => ({ value: a, label: a }))]} />
                <Select value={idioma} onChange={setIdioma} options={[{ value: '', label: 'Todos los idiomas' }, ...Object.entries(d.languages).map(([k, l]) => ({ value: k, label: l }))]} />
                {filtrando ? <button className="dev-clear" onClick={() => { setQ(''); setTipo(''); setDonde(''); setIdioma('') }}><Icon.x /> Limpiar</button> : null}
              </div>
            </div>
            {!d.guides.length ? (
              <div className="dev-card gd-vacio"><Icon.file /><p>Aún no hay documentación.{d.manage ? ' Sube el primer documento con «Nuevo documento».' : ''}</p></div>
            ) : !filtradas.length ? (
              <div className="dev-card gd-vacio"><Icon.search /><p>Ningún documento coincide con el filtro.</p></div>
            ) : grupos.map(([k, l]) => (
              <section key={k} className="gd-grupo">
                <h3><TipoIc kind={k} sm /> {d.kinds[k]} <span className="dev-mute">{l.length}</span></h3>
                <div className="gd-grid">
                  {l.map((g) => <Tarjeta key={g.id} g={g} kinds={d.kinds} manage={d.manage} onVer={() => setVer(g)} onEdit={() => setForm(g)} />)}
                </div>
              </section>
            ))}
          </>
        )}
      </div></div>
      {form !== undefined ? <Formulario g={form} meta={d} onClose={() => setForm(undefined)} onSaved={() => { setForm(undefined); cargar() }} /> : null}
      {ver ? <VisorGuia g={ver} onClose={() => setVer(null)} /> : null}
    </>
  )
}

/** En la ficha: los documentos de su caso (categoría o cliente) y los generales. */
export function GuiasRelacionadas({ w, onGoAll }) {
  const [d, setD] = useState(null)
  const [ver, setVer] = useState(null)
  const [todas, setTodas] = useState(false)
  useEffect(() => { api.warrantyGuidesFor(w.id).then((r) => r.ok && setD(r)) }, [w.id])
  if (!d || (!d.specific.length && !d.general.length)) return null
  const MAX_GEN = 4
  const generales = todas ? d.general : d.general.slice(0, MAX_GEN)
  const fila = (g, suyo) => (
    <li key={g.id}>
      <button type="button" onClick={() => setVer(g)} title={g.description || g.title}>
        <TipoIc kind={g.kind} sm />
        <span><b>{g.title}</b><small>{d.kinds[g.kind]}{suyo ? ` · ${ambito(g)}` : ''}</small></span>
      </button>
      <a className="icon-btn" href={api.warrantyGuideUrl(g.id, true)} title="Descargar"><Icon.download /></a>
    </li>
  )
  return (
    <section className="wf-card gd-rel" data-tour="wf-guias">
      <header><h3><Icon.file /> Documentación</h3></header>
      {d.specific.length ? <><h4 className="gd-rel-h">Para este caso</h4><ul>{d.specific.map((g) => fila(g, true))}</ul></> : null}
      {d.general.length ? <><h4 className="gd-rel-h">General</h4><ul>{generales.map((g) => fila(g, false))}</ul></> : null}
      <div className="gd-rel-f">
        {d.general.length > MAX_GEN && !todas ? <button className="wf-link" onClick={() => setTodas(true)}>{d.general.length - MAX_GEN} más</button> : null}
        {onGoAll ? <button className="wf-link" onClick={onGoAll}>Ver toda la documentación →</button> : null}
      </div>
      {ver ? <VisorGuia g={ver} onClose={() => setVer(null)} /> : null}
    </section>
  )
}
