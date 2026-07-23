import { useState, useEffect, useCallback, useMemo, createContext, useContext, useRef } from 'react'
import ReactFlow, { Background, Controls, MiniMap, Handle, Position, useNodesState, useEdgesState, addEdge, MarkerType } from 'reactflow'
import 'reactflow/dist/style.css'
import { api } from '../api.js'
import { Icon } from '../icons.jsx'
import { useToast, useConfirm } from '../App.jsx'
import Select from './Select.jsx'

// ---- Definición de los nodos ----
export const NODE_DEFS = {
  initial: { label: 'Nodo inicial', cat: 'start', color: '#00a884', icon: Icon.dashboard, fixed: true,
    fields: [] },
  send_message: { label: 'Enviar mensaje', cat: 'message', color: '#25d366', icon: Icon.send,
    fields: [{ k: 'message', t: 'textarea', label: 'Mensaje', ph: 'Escribe el mensaje… usa {{{senderName}}}' }] },
  send_template: { label: 'Enviar plantilla', cat: 'message', color: '#00a884', icon: Icon.templates,
    fields: [{ k: 'template', t: 'template', label: 'Plantilla aprobada' }] },
  send_buttons: { label: 'Enviar botones / lista', cat: 'message', color: '#25d366', icon: Icon.checkSquare, interactive: true, fields: [] },
  send_form: { label: 'Enviar formulario WA', cat: 'message', color: '#00a884', icon: Icon.forms,
    fields: [
      { k: 'form_id', t: 'form', label: 'Formulario publicado' },
      { k: 'body', t: 'textarea', label: 'Mensaje de invitación (opcional)', ph: 'Por defecto usa la Descripción del formulario' },
      { k: 'cta', t: 'text', label: 'Texto del botón (máx. 30)', ph: 'Ver formulario' },
    ] },
  http_request: { label: 'Petición API (HTTP)', cat: 'logic', color: '#4a9bff', icon: Icon.link, http: true, fields: [] },
  agent_transfer: { label: 'Transferir a agente', cat: 'logic', color: '#f4b740', icon: Icon.user,
    fields: [
      { k: 'target', t: 'select', label: 'Asignar a', options: [['specific', 'Un agente concreto'], ['any', 'Reparto entre agentes']] },
      { k: 'user_id', t: 'agent', label: 'Agente', showIf: (d) => (d.target || 'specific') === 'specific' },
      { k: 'auto', t: 'select', label: 'Modo de reparto', options: [['auto', 'Automático (el menos cargado)'], ['manual', 'Sin asignar (lo coge cualquiera)']], showIf: (d) => d.target === 'any' },
    ] },
  response_saver: { label: 'Guardar respuesta', cat: 'input', color: '#4a9bff', icon: Icon.note,
    fields: [{ k: 'prompt', t: 'textarea', label: 'Pregunta (opcional)', ph: '¿Cuál es tu email?' }, { k: 'variable', t: 'text', label: 'Guardar en variable', ph: 'ej. email' }] },
  route_type: { label: 'Según tipo de mensaje', cat: 'logic', color: '#9b6dff', icon: Icon.list, fields: [],
    outputs: [
      { id: 'text', label: '💬 Texto' }, { id: 'image', label: '🖼️ Imagen' }, { id: 'audio', label: '🎤 Audio' },
      { id: 'video', label: '🎬 Vídeo' }, { id: 'document', label: '📄 Documento' }, { id: 'other', label: '➕ Otro' },
    ] },
  business_hours: { label: 'Horario de atención', cat: 'logic', color: '#f4b740', icon: Icon.clock,
    fields: [
      { k: 'days', t: 'weekdays', label: 'Días de atención' },
      { k: 'from', t: 'time', label: 'Desde' },
      { k: 'to', t: 'time', label: 'Hasta' },
    ],
    outputs: [
      { id: 'in', label: '🟢 Dentro de horario' },
      { id: 'out', label: '🔴 Fuera de horario' },
    ] },
  condition: { label: 'Condición', cat: 'logic', color: '#a06bff', icon: Icon.bolt, branches: true,
    fields: [
      { k: 'variable', t: 'text', label: 'Variable', ph: '{{{senderMessage}}}' },
      { k: 'operator', t: 'select', label: 'Operador', options: [['contains', 'contiene'], ['equals', 'es igual a'], ['not_equals', 'no es igual a'], ['exists', 'existe']] },
      { k: 'keywords', t: 'keywords', label: 'Palabras clave (coincide con cualquiera)', showIf: (d) => (d.operator || 'contains') === 'contains' },
      { k: 'value', t: 'text', label: 'Valor', ph: 'hola', showIf: (d) => d.operator === 'equals' || d.operator === 'not_equals' },
    ] },
  set_labels: { label: 'Etiquetas del chat', cat: 'logic', color: '#f4b740', icon: Icon.tag,
    fields: [{ k: 'action', t: 'select', label: 'Acción', options: [['add', 'Añadir'], ['remove', 'Quitar']] }, { k: 'labels', t: 'labels', label: 'Etiquetas' }] },
  disable_autoreply: { label: 'Desactivar auto-respuesta', cat: 'logic', color: '#f25c54', icon: Icon.lock, fields: [] },
  delay: { label: 'Retraso', cat: 'logic', color: '#f4b740', icon: Icon.clock,
    fields: [{ k: 'seconds', t: 'number', label: 'Segundos', ph: '30' }] },
  reset_session: { label: 'Reiniciar sesión', cat: 'logic', color: '#25d366', icon: Icon.refresh, fields: [] },
  mysql_query: { label: 'Consultar base de datos', cat: 'logic', color: '#4a9bff', icon: Icon.dashboard, mysql: true, fields: [] },
}
const CATS = [['all', 'Todos'], ['message', 'Mensaje'], ['input', 'Entrada'], ['logic', 'Lógica']]
const SYSTEM_VARS = ['senderName', 'senderMessage', 'senderMobile', 'messageType']

// Ids de nodos "sueltos": los que NO son alcanzables desde el nodo inicial
// siguiendo las conexiones (nunca se ejecutarían en el flujo).
function flowLooseIds(nodes, edges) {
  const adj = {}
  edges.forEach((e) => { (adj[e.source] = adj[e.source] || []).push(e.target) })
  const seen = new Set(['initial'])
  const stack = ['initial']
  while (stack.length) {
    const id = stack.pop();
    (adj[id] || []).forEach((t) => { if (!seen.has(t)) { seen.add(t); stack.push(t) } })
  }
  return new Set(nodes.filter((n) => !seen.has(n.id)).map((n) => n.id))
}

// Variables {{{x}}} referenciadas en cualquier campo de los nodos pero que el
// flujo no produce (ni del sistema ni guardadas en otro nodo). Las internas
// (con guion bajo, p. ej. {{{_httpStatus}}}) se ignoran.
function flowBadTokens(nodes, flowVars) {
  const bad = new Set()
  const known = new Set([...SYSTEM_VARS, ...flowVars])
  const scan = (v) => {
    if (typeof v === 'string') {
      for (const m of v.matchAll(/\{\{+\w*\}*|\}\}+/g)) {
        const ok = m[0].match(/^\{\{\{(\w+)\}\}\}$/)
        if (!ok) bad.add(m[0])                                              // llaves mal escritas ({{{x}}, }}} sueltas…)
        else if (ok[1][0] !== '_' && !known.has(ok[1])) bad.add(m[0])       // variable que el flujo no genera
      }
    } else if (Array.isArray(v)) v.forEach(scan)
    else if (v && typeof v === 'object') Object.values(v).forEach(scan)
  }
  nodes.forEach((n) => scan(n.data))
  return [...bad]
}

// Clasifica una variable: del sistema (azul), creada por el usuario (morado) o desconocida (roja).
const varKind = (v, flowVars) => (SYSTEM_VARS.includes(v) ? 'sys' : (flowVars || []).includes(v) ? 'custom' : 'bad')

// Trocea un texto resaltando cada {{{variable}}} según su tipo (para la capa de fondo).
function renderVarHL(text, flowVars) {
  const out = []
  const re = /\{\{+\w*\}*|\}\}+/g
  let last = 0, m, k = 0
  while ((m = re.exec(text)) !== null) {
    if (m.index > last) out.push(text.slice(last, m.index))
    const ok = m[0].match(/^\{\{\{(\w+)\}\}\}$/)
    out.push(<span key={k++} className={`hl-${ok ? varKind(ok[1], flowVars) : 'bad'}`}>{m[0]}</span>)
    last = m.index + m[0].length
  }
  out.push(text.slice(last))
  return out
}

// ---- Coloreado de aristas por rama (legibilidad del grafo) ----
const EDGE_BRANCH = { yes: { c: '#25d366', l: 'Sí' }, no: { c: '#f25c54', l: 'No' }, fallback: { c: '#f4b740', l: '↩︎ Sin coincidencia' } }
// (sin #f4b740: reservado para la rama "Sin coincidencia"/fallback)
const OPT_PALETTE = ['#4a9bff', '#9b6dff', '#ff7ab6', '#37c8c3', '#ff9f43', '#a0d911', '#e056fd', '#54a0ff', '#2dd4bf', '#f472b6']
const ROUTE_LABEL = { text: '💬 Texto', image: '🖼️ Imagen', audio: '🎤 Audio', video: '🎬 Vídeo', document: '📄 Doc', sticker: '🏷️ Sticker', other: '➕ Otro' }
const EDGE_DEFAULT_COLOR = '#5b7083'

// Calcula color + etiqueta de una arista según el nodo origen y su handle.
function edgeBranch(srcNode, handle) {
  const t = srcNode?.data?.type
  if (t === 'condition') { const b = EDGE_BRANCH[handle]; if (b) return b }
  if (t === 'send_buttons') {
    if (handle === 'fallback') return EDGE_BRANCH.fallback
    if (handle && handle.startsWith('opt_')) {
      const oid = handle.slice(4), idx = (parseInt(oid, 10) || 1) - 1
      const o = (srcNode.data.options || []).find((x) => String(x.oid) === String(oid))
      return { c: OPT_PALETTE[idx % OPT_PALETTE.length], l: (o?.title || '').trim() || `Opción ${oid}` }
    }
  }
  if (t === 'route_type') {
    const order = ['text', 'image', 'audio', 'video', 'document', 'other'], idx = Math.max(0, order.indexOf(handle))
    return { c: OPT_PALETTE[idx % OPT_PALETTE.length], l: ROUTE_LABEL[handle] || handle }
  }
  return { c: EDGE_DEFAULT_COLOR, l: '' }
}

const FlowCtx = createContext({})

// Campo de texto del nodo: estado local (cursor estable), nodrag (no arrastra
// el nodo al seleccionar) y auto-alto para que el texto se vea completo.
function NodeInput({ as = 'input', type = 'text', value, placeholder, onChange }) {
  const { allVars = [], flowVars = [] } = useContext(FlowCtx)
  const [local, setLocal] = useState(value ?? '')
  const [sug, setSug] = useState(null) // { items, index, start, end }
  const ref = useRef(null)
  const hlRef = useRef(null)
  const syncScroll = () => { if (hlRef.current && ref.current) { hlRef.current.scrollTop = ref.current.scrollTop; hlRef.current.scrollLeft = ref.current.scrollLeft } }
  useEffect(() => { if (document.activeElement !== ref.current) setLocal(value ?? '') }, [value])
  useEffect(() => {
    if (as === 'textarea' && ref.current) { ref.current.style.height = 'auto'; ref.current.style.height = ref.current.scrollHeight + 'px' }
  }, [local, as])

  // Detecta un token de variable abierto antes del cursor: "{{" o "{{{" + parcial.
  const refreshSug = (el) => {
    const pos = el.selectionStart ?? el.value.length
    const m = el.value.slice(0, pos).match(/\{\{\{?(\w*)$/)
    if (!m || !allVars.length) return setSug(null)
    const partial = m[1].toLowerCase()
    const items = allVars.filter((v) => v.toLowerCase().includes(partial)).slice(0, 8)
    setSug(items.length ? { items, index: 0, start: pos - m[0].length, end: pos } : null)
  }
  const handle = (e) => { setLocal(e.target.value); onChange(e.target.value); refreshSug(e.target) }

  const insert = (v) => {
    const el = ref.current
    if (!sug || !el) return
    const next = el.value.slice(0, sug.start) + `{{{${v}}}}` + el.value.slice(sug.end)
    setLocal(next); onChange(next); setSug(null)
    requestAnimationFrame(() => { const p = sug.start + v.length + 6; el.focus(); el.setSelectionRange(p, p) })
  }
  const onKey = (e) => {
    if (!sug) return
    if (e.key === 'ArrowDown') { e.preventDefault(); setSug((s) => ({ ...s, index: (s.index + 1) % s.items.length })) }
    else if (e.key === 'ArrowUp') { e.preventDefault(); setSug((s) => ({ ...s, index: (s.index - 1 + s.items.length) % s.items.length })) }
    else if (e.key === 'Enter' || e.key === 'Tab') { e.preventDefault(); insert(sug.items[sug.index]) }
    else if (e.key === 'Escape') { e.stopPropagation(); setSug(null) }
  }

  const common = { ref, className: 'nd-in nodrag nd-in-ovl', value: local, placeholder, onChange: handle, onKeyDown: onKey, onScroll: syncScroll, onBlur: () => setTimeout(() => setSug(null), 130), spellCheck: false }
  return (
    <div className="nd-in-wrap nodrag">
      <div ref={hlRef} className={`nd-hl ${as === 'textarea' ? 'ta' : 'one'}`} aria-hidden="true">{renderVarHL(local, flowVars)}{'​'}</div>
      {as === 'textarea'
        ? <textarea {...common} rows={2} style={{ resize: 'none', overflow: 'hidden' }} />
        : <input {...common} type={type} />}
      {sug && (
        <div className="nd-ac nodrag">
          {sug.items.map((v, i) => (
            <div key={v} className={`nd-ac-item ac-${varKind(v, flowVars)} ${i === sug.index ? 'on' : ''}`} onMouseDown={(e) => { e.preventDefault(); insert(v) }}>
              <span className="nd-ac-brace">{'{{{'}</span>{v}<span className="nd-ac-brace">{'}}}'}</span>
            </div>
          ))}
        </div>
      )}
    </div>
  )
}

// Lista de palabras clave con botón "+" (para la condición "contiene")
function NodeKeywords({ value, onChange }) {
  const [list, setList] = useState(() => {
    if (Array.isArray(value) && value.length) return value
    if (typeof value === 'string' && value) return value.split(',').map((s) => s.trim()).filter(Boolean)
    return ['']
  })
  const upd = (next) => { setList(next); onChange(next) }
  return (
    <div className="nd-kw nodrag">
      {list.map((w, i) => (
        <div className="nd-kw-row" key={i}>
          <input className="nd-in nodrag" value={w} placeholder="palabra…" onChange={(e) => upd(list.map((x, j) => (j === i ? e.target.value : x)))} />
          <button className="nd-kw-x" onClick={() => upd(list.length > 1 ? list.filter((_, j) => j !== i) : [''])} title="Quitar">×</button>
        </div>
      ))}
      <button className="nd-kw-add" onClick={() => upd([...list, ''])}>+ palabra clave</button>
    </div>
  )
}

// Selector de días de la semana (L M X J V S D) -> [1..7] (ISO, lunes=1)
const WEEKDAYS = [[1, 'L'], [2, 'M'], [3, 'X'], [4, 'J'], [5, 'V'], [6, 'S'], [7, 'D']]
function NodeWeekdays({ value, onChange }) {
  const sel = Array.isArray(value) ? value.map(Number) : [1, 2, 3, 4, 5]
  const toggle = (n) => onChange((sel.includes(n) ? sel.filter((x) => x !== n) : [...sel, n]).sort((a, b) => a - b))
  return (
    <div className="nodrag" style={{ display: 'flex', gap: 4 }}>
      {WEEKDAYS.map(([n, lbl]) => {
        const on = sel.includes(n)
        return (
          <button key={n} onClick={() => toggle(n)} title={lbl}
            style={{ width: 26, height: 26, borderRadius: 6, fontSize: 12, fontWeight: 700, cursor: 'pointer', border: '1px solid var(--nc)', background: on ? 'var(--nc)' : 'transparent', color: on ? '#04130c' : 'var(--ink-2)' }}>{lbl}</button>
        )
      })}
    </div>
  )
}

// Editor del nodo interactivo (botones / lista) dentro del propio nodo
function InteractiveNodeBody({ data, set }) {
  const isList = (data.mode || 'button') === 'list'
  const opts = Array.isArray(data.options) && data.options.length ? data.options : [{ oid: 1, title: '' }]
  const max = isList ? 10 : 3
  const setOpt = (i, patch) => set('options', opts.map((o, k) => (k === i ? { ...o, ...patch } : o)))
  const addOpt = () => { const oid = Math.max(0, ...opts.map((o) => o.oid || 0)) + 1; set('options', [...opts, { oid, title: '' }]) }
  const delOpt = (i) => set('options', opts.length > 1 ? opts.filter((_, k) => k !== i) : opts)
  return (
    <>
      <div className="nd-field">
        <div className="nd-flabel">Tipo de menú</div>
        <div className="nodrag"><Select sm block value={data.mode || 'button'} onChange={(v) => set('mode', v)}
          options={[{ value: 'button', label: 'Botones (máx. 3)' }, { value: 'list', label: 'Lista (hasta 10)' }]} /></div>
      </div>
      <div className="nd-field"><div className="nd-flabel">Mensaje</div>
        <NodeInput as="textarea" value={data.body ?? ''} placeholder="Texto del menú… usa {{{senderName}}}" onChange={(v) => set('body', v)} /></div>
      {isList && (
        <div className="nd-field"><div className="nd-flabel">Texto del botón de la lista</div>
          <NodeInput value={data.listButton ?? ''} placeholder="Ver opciones" onChange={(v) => set('listButton', v)} /></div>
      )}
      <div className="nd-field"><div className="nd-flabel">Opciones</div>
        <div className="nd-opts nodrag">
          {opts.map((o, i) => (
            <div className="nd-opt-edit" key={o.oid ?? i}>
              <div className="nd-opt-fields">
                <input className="nd-in nodrag" value={o.title ?? ''} placeholder={`Opción ${i + 1}`} onChange={(e) => setOpt(i, { title: e.target.value })} />
                {isList && <input className="nd-in nodrag nd-opt-desc" value={o.description ?? ''} placeholder="Descripción (opcional)" onChange={(e) => setOpt(i, { description: e.target.value })} />}
              </div>
              {opts.length > 1 && <button className="nd-kw-x" title="Quitar" onClick={() => delOpt(i)}>×</button>}
            </div>
          ))}
          {opts.length < max && <button className="nd-kw-add" onClick={addOpt}>+ opción</button>}
        </div>
      </div>
      <div className="nd-field"><div className="nd-flabel">Guardar elección en variable (opcional)</div>
        <NodeInput value={data.saveTo ?? ''} placeholder="ej. opcion" onChange={(v) => set('saveTo', v)} /></div>
    </>
  )
}

// Editor del nodo de petición HTTP (cabeceras + guardar respuesta en variables)
function HttpNodeBody({ data, set }) {
  const headers = Array.isArray(data.headers) ? data.headers : []
  const saveTo = Array.isArray(data.saveTo) ? data.saveTo : []
  const hasBody = ['POST', 'PUT', 'PATCH'].includes(data.method || 'GET')
  const setH = (i, patch) => set('headers', headers.map((h, k) => (k === i ? { ...h, ...patch } : h)))
  const setS = (i, patch) => set('saveTo', saveTo.map((s, k) => (k === i ? { ...s, ...patch } : s)))
  return (
    <>
      <div className="nd-field"><div className="nd-flabel">Método</div>
        <div className="nodrag"><Select sm block value={data.method || 'GET'} onChange={(v) => set('method', v)}
          options={['GET', 'POST', 'PUT', 'PATCH', 'DELETE'].map((m) => ({ value: m, label: m }))} /></div>
      </div>
      <div className="nd-field"><div className="nd-flabel">URL</div>
        <NodeInput value={data.url ?? ''} placeholder="https://api.ejemplo.com/..." onChange={(v) => set('url', v)} /></div>
      <div className="nd-field"><div className="nd-flabel">Cabeceras</div>
        <div className="nd-opts nodrag">
          {headers.map((h, i) => (
            <div className="nd-opt-edit" key={i}>
              <div className="nd-opt-fields">
                <input className="nd-in nodrag" value={h.key ?? ''} placeholder="Clave (ej. Authorization)" onChange={(e) => setH(i, { key: e.target.value })} />
                <input className="nd-in nodrag nd-opt-desc" value={h.value ?? ''} placeholder="Valor" onChange={(e) => setH(i, { value: e.target.value })} />
              </div>
              <button className="nd-kw-x" title="Quitar" onClick={() => set('headers', headers.filter((_, k) => k !== i))}>×</button>
            </div>
          ))}
          <button className="nd-kw-add" onClick={() => set('headers', [...headers, { key: '', value: '' }])}>+ cabecera</button>
        </div>
      </div>
      {hasBody && (
        <div className="nd-field"><div className="nd-flabel">Cuerpo (body)</div>
          <NodeInput as="textarea" value={data.body ?? ''} placeholder='{"clave": "{{{senderMessage}}}"}' onChange={(v) => set('body', v)} /></div>
      )}
      <div className="nd-field"><div className="nd-flabel">Guardar respuesta en variables</div>
        <div className="nd-opts nodrag">
          {saveTo.map((s, i) => (
            <div className="nd-opt-edit" key={i}>
              <div className="nd-opt-fields">
                <input className="nd-in nodrag" value={s.variable ?? ''} placeholder="Variable (ej. precio)" onChange={(e) => setS(i, { variable: e.target.value })} />
                <input className="nd-in nodrag nd-opt-desc" value={s.path ?? ''} placeholder="Ruta JSON (ej. data.precio) — vacío = todo" onChange={(e) => setS(i, { path: e.target.value })} />
              </div>
              <button className="nd-kw-x" title="Quitar" onClick={() => set('saveTo', saveTo.filter((_, k) => k !== i))}>×</button>
            </div>
          ))}
          <button className="nd-kw-add" onClick={() => set('saveTo', [...saveTo, { variable: '', path: '' }])}>+ variable</button>
        </div>
      </div>
    </>
  )
}

// Editor visual del nodo "Consultar base de datos": respuesta guardada / tabla / SQL
const MYSQL_OPS = [['eq', 'es igual a'], ['ne', 'no es igual a'], ['contains', 'contiene'], ['gt', 'mayor que'], ['lt', 'menor que'], ['gte', 'mayor o igual'], ['lte', 'menor o igual']]
const MYSQL_MODES = [['response', '⭐ Respuesta guardada del cliente'], ['builder', 'Buscar en una tabla'], ['sql', 'SQL avanzado']]
function MysqlNodeBody({ data, set, schema, flowVars = [] }) {
  const mode = data.mode || (data.query ? 'sql' : (data.table ? 'builder' : 'response'))
  const tables = Object.keys(schema || {})
  const cols = (schema && schema[data.table]) || []
  return (
    <>
      <div className="nd-field"><div className="nd-flabel">Tipo de consulta</div>
        <div className="nodrag"><Select sm block value={mode} onChange={(v) => set('mode', v)} options={MYSQL_MODES.map(([v, t]) => ({ value: v, label: t }))} /></div></div>

      {mode === 'response' && (
        <>
          <div className="nd-field"><div className="nd-flabel">Variable guardada a recuperar</div>
            {flowVars.length ? (
              <div className="nodrag"><Select sm block value={data.responseVar || ''} placeholder="Elige variable…" onChange={(v) => set('responseVar', v)} options={flowVars.map((v) => ({ value: v, label: v }))} /></div>
            ) : (
              <NodeInput value={data.responseVar ?? ''} placeholder="ej. Telefono" onChange={(v) => set('responseVar', v)} />
            )}
          </div>
          <div className="nd-hint" style={{ padding: '0 2px' }}>Recupera el último valor que el bot guardó para este cliente y lo deja disponible como <code>{'{{{' + (data.responseVar || 'variable') + '}}}'}</code>.</div>
        </>
      )}

      {mode === 'builder' && (
        <>
          <div className="nd-field"><div className="nd-flabel">Tabla</div>
            <div className="nodrag"><Select sm block value={data.table || ''} placeholder={tables.length ? 'Elige tabla…' : 'cargando…'} onChange={(v) => set('table', v)} options={tables.map((t) => ({ value: t, label: t }))} /></div></div>
          <div className="nd-field"><div className="nd-flabel">Devolver</div>
            <div className="nodrag"><Select sm block value={data.column || '*'} onChange={(v) => set('column', v)}
              options={[{ value: '*', label: 'Toda la fila' }, ...cols.map((c) => ({ value: c, label: c }))]} /></div></div>
          <div className="nd-field"><div className="nd-flabel">Condición (opcional)</div>
            <div className="nd-mysql-cond nodrag">
              <Select sm block value={data.whereColumn || ''} placeholder="— sin condición —" onChange={(v) => set('whereColumn', v)}
                options={[{ value: '', label: '— sin condición —' }, ...cols.map((c) => ({ value: c, label: c }))]} />
              {data.whereColumn && (
                <>
                  <Select sm block value={data.operator || 'eq'} onChange={(v) => set('operator', v)} options={MYSQL_OPS.map(([v, t]) => ({ value: v, label: t }))} />
                  <NodeInput value={data.whereValue ?? ''} placeholder="valor o {{{senderMobile}}}" onChange={(v) => set('whereValue', v)} />
                </>
              )}
            </div></div>
        </>
      )}

      {mode === 'sql' && (
        <div className="nd-field"><div className="nd-flabel">Consulta SQL (solo SELECT)</div>
          <NodeInput as="textarea" value={data.query ?? ''} placeholder="SELECT name FROM contacts WHERE wa_id = '{{{senderMobile}}}'" onChange={(v) => set('query', v)} /></div>
      )}

      {mode !== 'response' && (
        <div className="nd-field"><div className="nd-flabel">Guardar resultado en variable</div>
          <NodeInput value={data.saveTo ?? ''} placeholder="ej. estadoPedido" onChange={(v) => set('saveTo', v)} /></div>
      )}
    </>
  )
}

// ---- Nodo personalizado ----
function CustomNode({ id, data }) {
  const def = NODE_DEFS[data.type]
  const { updateNode, removeNode, duplicateNode, templates, labels, forms, agents, schema, flowVars, allVars = [], looseIds } = useContext(FlowCtx)
  const isLoose = data.type !== 'initial' && looseIds?.has(id)
  const set = (k, v) => updateNode(id, { [k]: v })
  const Ico = def.icon

  const renderField = (fl) => {
    const val = data[fl.k] ?? ''
    if (fl.t === 'textarea') return <NodeInput as="textarea" value={val} placeholder={fl.ph} onChange={(v) => set(fl.k, v)} />
    if (fl.t === 'number') return <NodeInput type="number" value={val} placeholder={fl.ph} onChange={(v) => set(fl.k, v)} />
    if (fl.t === 'select') return <div className="nodrag"><Select sm block value={val} placeholder="—" onChange={(v) => set(fl.k, v)} options={fl.options.map(([v, t]) => ({ value: v, label: t }))} /></div>
    if (fl.t === 'template') return <div className="nodrag"><Select sm block value={val} placeholder="Elige…" onChange={(v) => set(fl.k, v)} options={templates.map((t) => ({ value: `${t.name}|${t.language}`, label: `${t.name} (${t.language})` }))} /></div>
    if (fl.t === 'form') return <div className="nodrag"><Select sm block value={val} placeholder={forms.length ? 'Elige formulario…' : 'No hay formularios publicados'} onChange={(v) => set(fl.k, v)} options={forms.map((f) => ({ value: f.id, label: f.name }))} /></div>
    if (fl.t === 'agent') return <div className="nodrag"><Select sm block value={val} placeholder="Elige agente…" onChange={(v) => set(fl.k, v)} options={agents.map((a) => ({ value: a.id, label: a.name || a.email }))} /></div>
    if (fl.t === 'keywords') return <NodeKeywords value={data.keywords} onChange={(v) => set('keywords', v)} />
    if (fl.t === 'time') return <input className="nd-in nodrag" type="time" value={val} onChange={(e) => set(fl.k, e.target.value)} />
    if (fl.t === 'weekdays') return <NodeWeekdays value={data.days} onChange={(v) => set('days', v)} />
    if (fl.t === 'labels') return (
      <div className="nd-labels nodrag">
        {labels.length === 0 && <span className="nd-hint">No hay etiquetas</span>}
        {labels.map((l) => {
          const on = (data.labels || []).includes(l.id)
          return <span key={l.id} className="nd-lbl" style={{ background: on ? l.color : 'transparent', color: on ? '#04130c' : l.color, borderColor: l.color }}
            onClick={() => set('labels', on ? (data.labels || []).filter((x) => x !== l.id) : [...(data.labels || []), l.id])}>{l.name}</span>
        })}
      </div>
    )
    return <NodeInput value={val} placeholder={fl.ph} onChange={(v) => set(fl.k, v)} />
  }

  return (
    <div className={`flow-node ${isLoose ? 'loose' : ''}`} style={{ '--nc': def.color }}>
      {isLoose && <span className="nd-loose-badge" title="Este nodo no está conectado al flujo">⚠ sin conectar</span>}
      {!def.fixed && <Handle type="target" position={Position.Left} />}
      <div className="nd-head">
        <span className="nd-ic" style={{ background: def.color + '22' }}><Ico style={{ width: 16, height: 16, fill: def.color }} /></span>
        <b>{def.label}</b>
        {data.type === 'initial' ? <span className="nd-badge">WA Chatbot</span>
          : (
            <span className="nd-head-actions">
              <button className="nd-copy" title="Duplicar nodo" onClick={() => duplicateNode?.(id)}><Icon.copy style={{ width: 14, height: 14, fill: 'currentColor' }} /></button>
              <button className="nd-del" onClick={() => removeNode(id)}>✕</button>
            </span>
          )}
      </div>
      <div className="nd-body">
        {data.type === 'initial' && (
          <>
            <div className="nd-flabel">Se activa con</div>
            <div className="nd-trigger"><Icon.chat style={{ width: 14, height: 14, fill: 'var(--primary)' }} /> Mensaje entrante de WhatsApp</div>
            <div className="nd-vars"><div className="nd-vars-t"><Icon.bolt style={{ width: 13, height: 13, fill: '#4a9bff' }} /> Variables disponibles</div>
              <div className="nd-vars-list">{(allVars.length ? allVars : SYSTEM_VARS).map((v) => <span key={v} className={`nd-var ${varKind(v, flowVars)}`}>{`{{{${v}}}}`}</span>)}</div>
              <div className="nd-vars-legend"><span className="nd-var sys">del sistema</span><span className="nd-var custom">creadas por ti</span></div>
            </div>
          </>
        )}
        {def.fields.filter((fl) => !fl.showIf || fl.showIf(data)).map((fl) => <div key={fl.k} className="nd-field"><div className="nd-flabel">{fl.label}</div>{renderField(fl)}</div>)}
        {def.interactive && <InteractiveNodeBody data={data} set={set} />}
        {def.http && <HttpNodeBody data={data} set={set} />}
        {def.mysql && <MysqlNodeBody data={data} set={set} schema={schema} flowVars={flowVars} />}
        {def.outputs && (
          <div className="nd-outs">
            {def.outputs.map((o) => (
              <div className="nd-out" key={o.id}>
                <span>{o.label}</span>
                <Handle type="source" position={Position.Right} id={o.id} />
              </div>
            ))}
          </div>
        )}
        {def.interactive && (
          <div className="nd-outs">
            {(Array.isArray(data.options) && data.options.length ? data.options : [{ oid: 1, title: '' }]).map((o, i) => (
              <div className="nd-out" key={o.oid ?? i}>
                <span>{(o.title || '').trim() || `Opción ${i + 1}`}</span>
                <Handle type="source" position={Position.Right} id={`opt_${o.oid ?? i + 1}`} />
              </div>
            ))}
            <div className="nd-out fallback">
              <span>↩︎ Sin coincidencia</span>
              <Handle type="source" position={Position.Right} id="fallback" />
            </div>
          </div>
        )}
      </div>
      {def.branches ? (
        <>
          <Handle type="source" position={Position.Right} id="yes" style={{ top: '40%' }} />
          <Handle type="source" position={Position.Right} id="no" style={{ top: '70%' }} />
          <span className="nd-branch yes">Sí</span><span className="nd-branch no">No</span>
        </>
      ) : (def.outputs || def.interactive) ? null : <Handle type="source" position={Position.Right} />}
    </div>
  )
}
const nodeTypes = { custom: CustomNode }

// ---- Menú de nodos ----
function NodeMenu({ onAdd, onClose }) {
  const [cat, setCat] = useState('all')
  const [q, setQ] = useState('')
  const items = Object.entries(NODE_DEFS).filter(([k, d]) => !d.fixed
    && (cat === 'all' || d.cat === cat)
    && (!q || d.label.toLowerCase().includes(q.toLowerCase())))
  return (
    <div className="node-menu">
      <div className="nm-head"><span className="nm-title"><Icon.bolt style={{ width: 18, height: 18, fill: 'var(--primary)' }} /> Menú de nodos</span><button className="x" onClick={onClose}>✕</button></div>
      <div className="nm-search"><Icon.search /><input placeholder="Buscar nodos…" value={q} onChange={(e) => setQ(e.target.value)} /></div>
      <div className="nm-tabs">{CATS.map(([k, t]) => <button key={k} className={`nm-tab ${cat === k ? 'on' : ''}`} onClick={() => setCat(k)}>{t}</button>)}</div>
      <div className="nm-list">
        {items.map(([k, d]) => {
          const Ico = d.icon
          return <div key={k} className="nm-item" onClick={() => onAdd(k)}>
            <span className="nm-ic" style={{ background: d.color + '22' }}><Ico style={{ width: 18, height: 18, fill: d.color }} /></span>
            <div><b>{d.label}</b><span className="nm-cat" style={{ color: d.color }}>{CATS.find((c) => c[0] === d.cat)?.[1]}</span></div>
          </div>
        })}
        {items.length === 0 && <p className="nd-hint" style={{ padding: 16 }}>Sin resultados.</p>}
      </div>
    </div>
  )
}

// ---- Simulación (dry-run en el navegador) ----
const OP_LABEL = { contains: 'contiene', equals: 'es igual a', not_equals: 'no es igual a', exists: 'existe' }
function resolveVars(text, v) { return String(text || '').replace(/\{\{\{(\w+)\}\}\}/g, (_, k) => v[k] ?? '') }
// Normaliza: minúsculas + sin acentos (igual que el backend)
const norm = (s) => String(s ?? '').toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '').trim()
function condKeywords(d) { return Array.isArray(d.keywords) ? d.keywords.filter(Boolean) : (d.value ? [d.value] : []) }
function evalCond(d, v) {
  const a = norm(resolveVars(d.variable, v))
  const op = d.operator || 'contains'
  if (op === 'exists') return a !== ''
  if (op === 'equals') return a === norm(d.value)
  if (op === 'not_equals') return a !== norm(d.value)
  return condKeywords(d).some((w) => { const n = norm(w); return n && a.includes(n) })
}

// Versión JS del horario de atención (para la simulación; usa la hora del dispositivo)
function simInHours(d) {
  const days = (Array.isArray(d.days) && d.days.length ? d.days : [1, 2, 3, 4, 5]).map(Number)
  const now = new Date()
  const iso = now.getDay() === 0 ? 7 : now.getDay() // 1=Lun..7=Dom
  if (!days.includes(iso)) return false
  const from = (d.from || '').trim(), to = (d.to || '').trim()
  if (!from || !to) return true
  const hm = String(now.getHours()).padStart(2, '0') + ':' + String(now.getMinutes()).padStart(2, '0')
  return from <= to ? (hm >= from && hm < to) : (hm >= from || hm < to)
}

function SimPanel({ nodes, edges, labels, onClose }) {
  const [senderName, setSenderName] = useState('Juan')
  const [message, setMessage] = useState('Hola')
  const [log, setLog] = useState([])
  const [vars, setVars] = useState(null)
  const [waiting, setWaiting] = useState(null)
  const [reply, setReply] = useState('')
  const [started, setStarted] = useState(false)

  const findNode = (id) => nodes.find((n) => n.id === id)
  const nextId = (id, handle = null) => { const e = edges.find((e) => e.source === id && (handle == null || e.sourceHandle === handle)); return e ? e.target : null }
  const labelName = (id) => labels.find((l) => l.id === id)?.name || id

  function walk(startId, v, acc) {
    let cur = startId, guard = 0
    while (cur && guard++ < 60) {
      const node = findNode(cur); if (!node) { acc.push({ type: 'err', title: 'Nodo sin conexión', detail: 'El flujo se detiene aquí' }); return { waiting: null, vars: v } }
      const d = node.data, t = d.type
      if (t === 'initial') { cur = nextId(cur); continue }
      if (t === 'send_message') { acc.push({ type: t, title: 'Enviar mensaje', detail: resolveVars(d.message, v) || '(vacío)' }); cur = nextId(cur); continue }
      if (t === 'send_template') { acc.push({ type: t, title: 'Enviar plantilla', detail: String(d.template || '').split('|')[0] || '—' }); cur = nextId(cur); continue }
      if (t === 'response_saver') {
        if (d.prompt) acc.push({ type: t, title: 'Pregunta', detail: resolveVars(d.prompt, v) })
        acc.push({ type: 'wait', title: 'Esperar respuesta', detail: 'Se guardará en: ' + (d.variable || '—') })
        return { waiting: { nodeId: cur, variable: d.variable }, vars: v }
      }
      if (t === 'condition') { const ok = evalCond(d, v); const tgt = (d.operator === 'equals' || d.operator === 'not_equals') ? `"${d.value || ''}"` : `[${condKeywords(d).join(', ')}]`; acc.push({ type: t, title: 'Condición', detail: `"${resolveVars(d.variable, v)}" ${OP_LABEL[d.operator] || 'contiene'} ${tgt} → ${ok ? 'Sí' : 'No'}`, ok }); cur = nextId(cur, ok ? 'yes' : 'no'); continue }
      if (t === 'route_type') { const mt = v.messageType || 'text'; acc.push({ type: t, title: 'Según tipo de mensaje', detail: `Tipo «${mt}» → rama correspondiente` }); cur = nextId(cur, mt) ?? nextId(cur, 'other'); continue }
      if (t === 'send_buttons') {
        const opts = (d.options || []).filter((o) => (o.title || '').trim())
        acc.push({ type: 'send_buttons', title: d.mode === 'list' ? 'Enviar lista' : 'Enviar botones', detail: (resolveVars(d.body, v) || '(vacío)') + (opts.length ? ` — [${opts.map((o) => o.title).join(' · ')}]` : '') })
        return { waiting: { nodeId: cur, variable: d.saveTo, interactive: true }, vars: v }
      }
      if (t === 'set_labels') { acc.push({ type: t, title: 'Etiquetas', detail: (d.action === 'remove' ? 'Quitar: ' : 'Añadir: ') + (d.labels || []).map(labelName).join(', ') }); cur = nextId(cur); continue }
      if (t === 'send_form') { acc.push({ type: t, title: 'Enviar formulario WA', detail: d.form_id ? `Formulario #${d.form_id}` : 'Sin formulario seleccionado' }); cur = nextId(cur); continue }
      if (t === 'http_request') { acc.push({ type: t, title: 'Petición API', detail: `${d.method || 'GET'} ${resolveVars(d.url, v) || '—'} (no se ejecuta en simulación)` }); cur = nextId(cur); continue }
      if (t === 'agent_transfer') { acc.push({ type: t, title: 'Transferir a agente', detail: d.target === 'any' ? (d.auto === 'manual' ? 'Sin asignar (lo coge cualquiera)' : 'Reparto automático') : 'A un agente concreto', ok: true }); cur = nextId(cur); continue }
      if (t === 'disable_autoreply') { acc.push({ type: t, title: 'Desactivar auto-respuesta' }); cur = nextId(cur); continue }
      if (t === 'delay') { acc.push({ type: t, title: 'Retraso', detail: (d.seconds || 0) + ' s (omitido en simulación)' }); cur = nextId(cur); continue }
      if (t === 'reset_session') { v = { senderName: v.senderName, senderMobile: v.senderMobile }; acc.push({ type: t, title: 'Reiniciar sesión' }); cur = nextId(cur); continue }
      if (t === 'mysql_query') { acc.push({ type: t, title: 'Consulta MySQL', detail: 'No se ejecuta en simulación' }); cur = nextId(cur); continue }
      if (t === 'business_hours') { const open = simInHours(d); acc.push({ type: t, title: 'Horario de atención', detail: open ? 'Dentro de horario → continúa' : 'Fuera de horario', ok: open }); cur = nextId(cur, open ? 'in' : 'out'); continue }
      cur = nextId(cur)
    }
    acc.push({ type: 'end', title: 'Fin del flujo' })
    return { waiting: null, vars: v }
  }

  const run = () => {
    const v = { senderName, senderMessage: message, senderMobile: '34600000000', messageType: 'text' }
    const acc = [{ type: 'start', title: 'Mensaje entrante', detail: `"${message}" — de ${senderName}` }]
    const r = walk('initial', v, acc)
    setLog([...acc]); setVars(r.vars); setWaiting(r.waiting); setStarted(true)
  }
  const cont = () => {
    const v = { ...vars }; if (waiting.variable) v[waiting.variable] = reply; v.senderMessage = reply
    const acc = [...log, { type: 'start', title: 'Respuesta del usuario', detail: `"${reply}"` }]
    let startId
    if (waiting.interactive) {
      const node = findNode(waiting.nodeId)
      const opts = (node?.data.options || []).filter((o) => (o.title || '').trim())
      const m = opts.find((o) => { const n = norm(o.title); return n && norm(reply).includes(n) })
      const handle = m ? `opt_${m.oid}` : 'fallback'
      startId = nextId(waiting.nodeId, handle) ?? nextId(waiting.nodeId, 'fallback')
      acc.push({ type: 'route_type', title: 'Opción elegida', detail: m ? `«${m.title}»` : 'Sin coincidencia → fallback' })
    } else {
      startId = nextId(waiting.nodeId)
    }
    const r = walk(startId, v, acc)
    setLog([...acc]); setVars(r.vars); setWaiting(r.waiting); setReply('')
  }

  return (
    <div className="sim-panel">
      <div className="sim-head"><span><Icon.play style={{ width: 16, height: 16, fill: 'var(--primary)' }} /> Simulación</span><button className="x" onClick={onClose}>✕</button></div>
      <div className="sim-form">
        <label className="field" style={{ margin: 0 }}><span className="lbl">Nombre del remitente</span><input value={senderName} onChange={(e) => setSenderName(e.target.value)} /></label>
        <label className="field" style={{ margin: '12px 0 0' }}><span className="lbl">Mensaje entrante</span><input value={message} onChange={(e) => setMessage(e.target.value)} onKeyDown={(e) => e.key === 'Enter' && run()} /></label>
        <button className="btn" style={{ width: '100%', justifyContent: 'center', marginTop: 12 }} onClick={run}><Icon.play /> Ejecutar simulación</button>
      </div>
      <div className="sim-log">
        {!started && <p className="nd-hint" style={{ textAlign: 'center', padding: 20 }}>Escribe un mensaje y pulsa «Ejecutar» para ver el recorrido del flujo.</p>}
        {log.map((s, i) => (
          <div key={i} className={`sim-step ${s.type}`}>
            <span className="sim-dot" />
            <div><b>{s.title}</b>{s.detail && <span>{s.detail}</span>}</div>
          </div>
        ))}
        {waiting && (
          <div className="sim-reply">
            <span className="lbl">Responder como el usuario</span>
            <div className="add-row">
              <input value={reply} onChange={(e) => setReply(e.target.value)} placeholder="Escribe una respuesta…" onKeyDown={(e) => e.key === 'Enter' && reply.trim() && cont()} />
              <button className="btn" disabled={!reply.trim()} onClick={cont}>Enviar</button>
            </div>
          </div>
        )}
        {started && vars && !waiting && (
          <div className="sim-vars"><b>Variables finales</b>{Object.entries(vars).map(([k, val]) => <div key={k} className="sim-var"><span>{k}</span><code>{String(val) || '—'}</code></div>)}</div>
        )}
      </div>
    </div>
  )
}

// ---- Plantillas de flujo predefinidas ----
const T = (id, x, y, data) => ({ id, type: 'custom', position: { x, y }, data })
const E = (s, t, h) => ({ id: `e_${s}_${t}`, source: s, target: t, type: 'smoothstep', animated: true, ...(h ? { sourceHandle: h } : {}) })
const INIT = () => T('initial', 40, 200, { type: 'initial' })

const FLOW_TEMPLATES = [
  {
    name: 'Bienvenida simple', desc: 'Saluda al cliente cuando escribe por primera vez.',
    graph: { nodes: [INIT(), T('m1', 400, 200, { type: 'send_message', message: '¡Hola {{{senderName}}}! 👋 Gracias por escribirnos. ¿En qué podemos ayudarte?' })], edges: [E('initial', 'm1')] },
  },
  {
    name: 'Menú con botones', desc: 'Ofrece un menú de botones y enruta según la opción que pulse el cliente.',
    graph: { nodes: [
      INIT(),
      T('menu', 380, 240, { type: 'send_buttons', mode: 'button', body: '¡Hola {{{senderName}}}! 👋 ¿Qué necesitas?', options: [{ oid: 1, title: 'Ventas' }, { oid: 2, title: 'Soporte' }, { oid: 3, title: 'Horario' }] }),
      T('v', 820, 60, { type: 'send_message', message: '🛍️ Te paso con el equipo de ventas enseguida.' }),
      T('s', 820, 240, { type: 'send_message', message: '🛠️ Cuéntanos tu problema y te ayudamos.' }),
      T('h', 820, 420, { type: 'send_message', message: '🕒 Nuestro horario es L-V de 9:00 a 18:00.' }),
      T('o', 820, 600, { type: 'send_message', message: '🤔 No te he entendido. Pulsa uno de los botones, por favor.' }),
    ], edges: [
      E('initial', 'menu'),
      E('menu', 'v', 'opt_1'), E('menu', 's', 'opt_2'), E('menu', 'h', 'opt_3'), E('menu', 'o', 'fallback'),
    ] },
  },
  {
    name: 'Preguntas frecuentes', desc: 'Saluda, espera la pregunta y responde por palabras clave (en bucle).',
    graph: { nodes: [
      INIT(),
      T('w', 320, 320, { type: 'send_message', message: '¡Hola {{{senderName}}}! 👋 ¿En qué puedo ayudarte?\nEscribe *precio*, *horario*, o *agente* para hablar con una persona.' }),
      T('rs', 620, 320, { type: 'response_saver', variable: 'consulta' }),
      T('c1', 920, 320, { type: 'condition', variable: '{{{consulta}}}', operator: 'contains', value: 'precio' }),
      T('p', 1240, 120, { type: 'send_message', message: '💶 Nuestros precios empiezan desde 50€. ¿Algo más?' }),
      T('c2', 1240, 400, { type: 'condition', variable: '{{{consulta}}}', operator: 'contains', value: 'horario' }),
      T('h', 1560, 240, { type: 'send_message', message: '🕒 Nuestro horario es de Lunes a Viernes, de 9:00 a 18:00. ¿Algo más?' }),
      T('c3', 1560, 520, { type: 'condition', variable: '{{{consulta}}}', operator: 'contains', value: 'agente' }),
      T('ag', 1880, 380, { type: 'send_message', message: '👤 Te paso con un agente. En unos minutos te atenderá.' }),
      T('o', 1880, 660, { type: 'send_message', message: '🤔 No te he entendido. Prueba con *precio*, *horario* o *agente*.' }),
    ], edges: [
      E('initial', 'w'), E('w', 'rs'), E('rs', 'c1'),
      E('c1', 'p', 'yes'), E('c1', 'c2', 'no'),
      E('c2', 'h', 'yes'), E('c2', 'c3', 'no'),
      E('c3', 'ag', 'yes'), E('c3', 'o', 'no'),
      E('p', 'rs'), E('h', 'rs'), E('o', 'rs'), // vuelven a esperar la siguiente pregunta
    ] },
  },
  {
    name: 'Encuesta de satisfacción', desc: 'Pide una valoración y reacciona a la respuesta.',
    graph: { nodes: [
      INIT(),
      T('q', 380, 200, { type: 'response_saver', prompt: 'Del 1 al 5, ¿cómo valorarías nuestra atención? ⭐', variable: 'nota' }),
      T('c', 720, 200, { type: 'condition', variable: '{{{nota}}}', operator: 'contains', value: '5' }),
      T('ok', 1060, 80, { type: 'send_message', message: '¡Gracias por tu 5! 🌟 Nos alegra mucho.' }),
      T('mh', 1060, 340, { type: 'send_message', message: 'Gracias por tu opinión, seguiremos mejorando. 🙏' }),
    ], edges: [E('initial', 'q'), E('q', 'c'), E('c', 'ok', 'yes'), E('c', 'mh', 'no')] },
  },
  {
    name: 'Captar datos (lead)', desc: 'Recoge el nombre y el email del cliente.',
    graph: { nodes: [
      INIT(),
      T('n', 380, 200, { type: 'response_saver', prompt: '¡Hola! ¿Cuál es tu nombre?', variable: 'nombre' }),
      T('e', 720, 200, { type: 'response_saver', prompt: 'Gracias {{{nombre}}} 🙂 ¿Y tu email?', variable: 'email' }),
      T('f', 1060, 200, { type: 'send_message', message: '¡Perfecto {{{nombre}}}! Te contactaremos en {{{email}}}. ✅' }),
    ], edges: [E('initial', 'n'), E('n', 'e'), E('e', 'f')] },
  },
]

function ImportModal({ onClose, onPick }) {
  return (
    <div className="modal-bg" onClick={(e) => e.target.classList.contains('modal-bg') && onClose()}>
      <div className="modal" style={{ maxWidth: 520 }}>
        <div className="modal-head"><h3>Importar plantilla de flujo</h3><button className="x" onClick={onClose}>×</button></div>
        <div className="modal-body">
          {FLOW_TEMPLATES.map((t, i) => (
            <div key={i} className="pick" onClick={() => onPick(t)}>
              <div className="top"><b>{t.name}</b><span className="pill gray">{t.graph.nodes.length} nodos</span></div>
              <div className="body">{t.desc}</div>
            </div>
          ))}
        </div>
      </div>
    </div>
  )
}

// ---- Editor ----
function Editor({ flow, onBack }) {
  const toast = useToast()
  const confirm = useConfirm()
  const [nodes, setNodes, onNodesChange] = useNodesState(flow?.graph?.nodes || [{ id: 'initial', type: 'custom', position: { x: 250, y: 150 }, data: { type: 'initial' } }])
  const [edges, setEdges, onEdgesChange] = useEdgesState(flow?.graph?.edges || [])
  const [name, setName] = useState(flow?.name || 'Sin título')
  const [active, setActive] = useState(!!flow?.active)
  const [menuOpen, setMenuOpen] = useState(false)
  const [simOpen, setSimOpen] = useState(false)
  const [importOpen, setImportOpen] = useState(false)
  const [saving, setSaving] = useState(false)
  const [templates, setTemplates] = useState([])
  const [labels, setLabels] = useState([])
  const [forms, setForms] = useState([])
  const [agents, setAgents] = useState([])
  const [schema, setSchema] = useState({})
  const idRef = useRef(1)
  const flowIdRef = useRef(flow?.id || 0)

  useEffect(() => {
    api.listTemplates().then((d) => setTemplates((d.templates || []).filter((t) => t.status === 'APPROVED')))
    api.listLabels().then((d) => setLabels(d.labels || []))
    api.listForms().then((d) => setForms((d.forms || []).filter((f) => f.meta_flow_id)))
    api.listAgents().then((d) => setAgents(d.agents || []))
    api.dbSchema().then((d) => setSchema(d.schema || {}))
  }, [])

  const onConnect = useCallback((c) => setEdges((eds) => addEdge({ ...c, type: 'smoothstep', animated: true }, eds)), [setEdges])
  const updateNode = useCallback((id, patch) => setNodes((nds) => nds.map((n) => (n.id === id ? { ...n, data: { ...n.data, ...patch } } : n))), [setNodes])
  const removeNode = useCallback((id) => { setNodes((nds) => nds.filter((n) => n.id !== id)); setEdges((eds) => eds.filter((e) => e.source !== id && e.target !== id)) }, [setNodes, setEdges])
  // Duplicar un nodo tal cual (mismos datos, id nuevo, ligeramente desplazado)
  const duplicateNode = useCallback((id) => setNodes((nds) => {
    const src = nds.find((n) => n.id === id)
    if (!src || src.data?.type === 'initial') return nds
    const nid = `n${Date.now()}_${idRef.current++}`
    return [...nds.map((n) => ({ ...n, selected: false })), { ...src, id: nid, selected: true, position: { x: (src.position?.x || 0) + 44, y: (src.position?.y || 0) + 44 }, data: JSON.parse(JSON.stringify(src.data)) }]
  }), [setNodes])
  // Valor de contexto memoizado: evita re-renderizar todos los nodos en cada tecla
  // Variables que el flujo guarda (para el modo "respuesta guardada" del nodo de consulta)
  const flowVars = useMemo(() => {
    const s = new Set()
    for (const n of nodes) {
      const d = n.data || {}
      if (d.type === 'response_saver' && d.variable) s.add(d.variable)
      if (d.type === 'send_buttons' && d.saveTo) s.add(d.saveTo)
      if (d.type === 'mysql_query' && d.saveTo) s.add(d.saveTo)
      if (d.type === 'http_request') (d.saveTo || []).forEach((m) => m.variable && s.add(m.variable))
    }
    return [...s]
  }, [nodes])
  const allVars = useMemo(() => [...new Set([...SYSTEM_VARS, ...flowVars])], [flowVars])
  const looseIds = useMemo(() => flowLooseIds(nodes, edges), [nodes, edges])
  const badTokens = useMemo(() => flowBadTokens(nodes, flowVars), [nodes, flowVars])

  const ctxValue = useMemo(() => ({ updateNode, removeNode, duplicateNode, templates, labels, forms, agents, schema, flowVars, allVars, looseIds }), [updateNode, removeNode, duplicateNode, templates, labels, forms, agents, schema, flowVars, allVars, looseIds])

  // Aristas decoradas: color + etiqueta por rama, flecha de dirección, y al
  // seleccionar un nodo se resaltan sus conexiones (las demás se atenúan).
  const decoratedEdges = useMemo(() => {
    const byId = Object.fromEntries(nodes.map((n) => [n.id, n]))
    const sel = new Set(nodes.filter((n) => n.selected).map((n) => n.id))
    const anySel = sel.size > 0
    return edges.map((e) => {
      const { c, l } = edgeBranch(byId[e.source], e.sourceHandle)
      const on = anySel && (sel.has(e.source) || sel.has(e.target))
      const dim = anySel && !on
      return {
        ...e,
        animated: on,
        style: { ...e.style, stroke: c, strokeWidth: on ? 3 : 1.8, opacity: dim ? 0.1 : 1 },
        markerEnd: { type: MarkerType.ArrowClosed, color: c, width: 16, height: 16 },
        label: l || undefined,
        labelShowBg: !!l,
        labelBgPadding: [6, 3],
        labelBgBorderRadius: 6,
        labelStyle: { fill: c, fontWeight: 700, fontSize: 10.5, opacity: dim ? 0.15 : 1 },
        labelBgStyle: { fill: '#0e1621', opacity: dim ? 0.15 : 0.92 },
      }
    })
  }, [edges, nodes])

  const addNode = (type) => {
    const id = `n${Date.now()}_${idRef.current++}`
    const extra = type === 'send_buttons'
      ? { mode: 'button', body: '', options: [{ oid: 1, title: '' }, { oid: 2, title: '' }] }
      : type === 'http_request'
        ? { method: 'GET', url: '', headers: [{ key: 'Content-Type', value: 'application/json' }], saveTo: [] }
        : type === 'agent_transfer'
          ? { target: 'specific', auto: 'auto' }
          : type === 'mysql_query'
            ? { mode: 'response', column: '*', operator: 'eq' }
            : {}
    setNodes((nds) => [...nds, { id, type: 'custom', position: { x: 420 + (nds.length % 3) * 60, y: 120 + nds.length * 40 }, data: { type, ...extra } }])
    setMenuOpen(false)
  }

  const save = async () => {
    // Un chatbot ACTIVO no puede tener nodos sueltos ni usar variables inexistentes.
    if (active) {
      if (looseIds.size) { toast(`No se puede activar: ${looseIds.size} nodo(s) sin conectar al flujo. Conéctalos o desactiva el chatbot.`, 'err'); return }
      if (badTokens.length) { toast(`No se puede activar: variable(s) mal escritas o inexistentes → ${badTokens.join('  ')}. Revisa las llaves {{{ }}}.`, 'err'); return }
    }
    setSaving(true)
    const res = await api.saveFlow({ id: flowIdRef.current || undefined, name, active: active ? 1 : 0, graph: { nodes, edges } })
    setSaving(false)
    if (res.ok) { flowIdRef.current = res.id; toast('Flujo guardado') } else toast(res.error || 'Error al guardar', 'err')
  }

  const newFlow = async () => {
    if (nodes.length > 1 && !(await confirm({ title: 'Nuevo flujo', message: 'Se descartará el flujo actual sin guardar. ¿Continuar?', confirmText: 'Nuevo flujo' }))) return
    setNodes([{ id: 'initial', type: 'custom', position: { x: 250, y: 150 }, data: { type: 'initial' } }])
    setEdges([]); setName('Sin título'); setActive(false); flowIdRef.current = 0
    toast('Nuevo flujo')
  }

  const importTemplate = async (t) => {
    if (nodes.length > 1 && !(await confirm({ title: 'Importar plantilla', message: 'Se reemplazará el flujo actual. ¿Continuar?', confirmText: 'Importar' }))) return
    const g = JSON.parse(JSON.stringify(t.graph))
    setNodes(g.nodes); setEdges(g.edges); setName(t.name); flowIdRef.current = 0
    setImportOpen(false); toast('Plantilla importada')
  }

  return (
    <FlowCtx.Provider value={ctxValue}>
      <div className="flow-wrap">
        <ReactFlow nodes={nodes} edges={decoratedEdges} onNodesChange={onNodesChange} onEdgesChange={onEdgesChange} onConnect={onConnect}
          nodeTypes={nodeTypes} fitView proOptions={{ hideAttribution: true }}
          snapToGrid snapGrid={[16, 16]} connectionLineType="smoothstep" connectionRadius={28}
          defaultEdgeOptions={{ type: 'smoothstep' }}>
          <Background color="#1b2730" gap={16} />
          <Controls />
          <MiniMap nodeColor={(n) => NODE_DEFS[n.data?.type]?.color || '#888'} maskColor="rgba(10,15,19,0.7)" />
        </ReactFlow>

        {/* Toolbar flotante */}
        <div className="flow-toolbar">
          <span className="ft-ico"><Icon.bolt /></span>
          <input className="ft-name" value={name} onChange={(e) => setName(e.target.value)} placeholder="Sin título" />
          <div className="ft-bot" title="Solo un chatbot puede estar activo a la vez. Al activar este, se desactiva el anterior.">
            <label className="ft-active"><input type="checkbox" checked={active} onChange={(e) => setActive(e.target.checked)} /><span /></label>
            <span className={`ft-bot-t ${active ? 'on' : ''}`}>{active ? 'Chatbot activo' : 'Chatbot inactivo'}</span>
          </div>
          <button className="ft-btn" onClick={onBack} title="Volver a la lista de flujos"><Icon.chevron style={{ transform: 'rotate(90deg)' }} /></button>
          <button className={`ft-btn ${simOpen ? 'on' : ''}`} onClick={() => setSimOpen((s) => !s)} title="Probar"><Icon.play /></button>
          <button className="ft-btn" onClick={save} disabled={saving} title="Guardar flujo"><Icon.save /></button>
          <button className="ft-btn warn" onClick={newFlow} title="Nuevo flujo"><Icon.refresh /></button>
          <button className="ft-btn" onClick={() => setImportOpen(true)} title="Importar plantilla"><Icon.download /></button>
          <button className={`ft-btn ${menuOpen ? 'on' : ''}`} onClick={() => setMenuOpen((o) => !o)} title="Panel de nodos">«</button>
        </div>

        <button className="flow-add" onClick={() => setMenuOpen(true)}><Icon.plus /> Añadir nodo</button>
        {menuOpen && <NodeMenu onAdd={addNode} onClose={() => setMenuOpen(false)} />}
        {simOpen && <SimPanel nodes={nodes} edges={edges} labels={labels} onClose={() => setSimOpen(false)} />}
        {importOpen && <ImportModal onClose={() => setImportOpen(false)} onPick={importTemplate} />}
      </div>
    </FlowCtx.Provider>
  )
}

// ---- Lista de flujos ----
export default function Automations() {
  const toast = useToast()
  const confirm = useConfirm()
  const [flows, setFlows] = useState(null)
  const [editing, setEditing] = useState(undefined) // undefined=list, null=new, object=edit

  const load = useCallback(() => api.listFlows().then((d) => setFlows(d.flows || [])), [])
  useEffect(() => { load() }, [load])

  const open = async (id) => { const d = await api.getFlow(id); if (d.ok) setEditing(d.flow) }
  const del = async (id) => { if (!(await confirm({ title: 'Eliminar flujo', message: '¿Eliminar este flujo de automatización?', danger: true, confirmText: 'Eliminar' }))) return; const r = await api.deleteFlow(id); if (r.ok) { toast('Flujo eliminado'); load() } }

  if (editing !== undefined) {
    return <Editor flow={editing} onBack={() => { setEditing(undefined); load() }} />
  }

  return (
    <>
      <header className="page-head">
        <span className="ic" style={{ width: 30, height: 30, borderRadius: 9, background: 'var(--primary-soft)', display: 'grid', placeItems: 'center' }}><Icon.bolt style={{ width: 18, height: 18, fill: 'var(--primary)' }} /></span>
        <h1>Automatizaciones</h1>
        <span className="sub">· Flujos de chatbot con nodos</span>
        <div className="spacer" />
        <button className="btn" onClick={() => setEditing(null)}><Icon.plus /> Nuevo flujo</button>
      </header>
      <div className="page-scroll">
        <div className="page">
          {flows === null && <div className="center-load"><div className="spinner" /></div>}
          {flows?.length === 0 && (
            <div className="empty"><div className="ico"><Icon.bolt /></div><p>No tienes flujos todavía.<br />Crea el primero con «Nuevo flujo».</p></div>
          )}
          {flows?.length > 0 && (
            <div className="flow-grid">
              {flows.map((fl) => (
                <div className="flow-card" key={fl.id} onClick={() => open(fl.id)}>
                  <div className="fc-top"><span className="fc-ic"><Icon.bolt /></span><span className={`pill ${fl.active == 1 ? 'ok' : 'gray'}`}><span className="dot" />{fl.active == 1 ? 'Chatbot activo' : 'Inactivo'}</span></div>
                  <div className="fc-name">{fl.name}</div>
                  <div className="fc-foot"><span className="muted">Actualizado {new Date(fl.updated_at.replace(' ', 'T')).toLocaleDateString('es-ES')}</span>
                    <button className="btn ghost sm" style={{ color: 'var(--danger)' }} onClick={(e) => { e.stopPropagation(); del(fl.id) }}><Icon.trash /></button>
                  </div>
                </div>
              ))}
            </div>
          )}
        </div>
      </div>
    </>
  )
}
