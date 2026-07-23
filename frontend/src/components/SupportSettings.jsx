import { useState, useEffect, useCallback } from 'react'
import { api } from '../api.js'
import { Icon } from '../icons.jsx'
import { useToast, useConfirm } from '../App.jsx'
import Select from './Select.jsx'

/* ---------------------------------------------------------------------------
 * CONFIGURACIÓN DE SOPORTE — solo superadmin / encargado (permiso support.config).
 * Dos apartados: CATEGORÍAS y RESPUESTAS PREDEFINIDAS.
 * (Departamentos: descartado. Automatización: más adelante, va con campañas.)
 * ------------------------------------------------------------------------- */

const COLORS = [
  { value: '#8b5cf6', label: 'Púrpura' }, { value: '#2563eb', label: 'Azul' },
  { value: '#10b981', label: 'Verde' },   { value: '#f59e0b', label: 'Ámbar' },
  { value: '#ef4444', label: 'Rojo' },     { value: '#ec4899', label: 'Rosa' },
  { value: '#06b6d4', label: 'Cian' },     { value: '#64748b', label: 'Gris' },
]

/*
 * Secciones de la configuración, agrupadas por a QUÉ pertenecen. Ocho pestañas en
 * fila ya no se leían, y además «Seguridad» y «Cron» no son cosa del soporte sino
 * del sistema: aquí quedan separadas de verdad.
 */
const SECCIONES = [
  { grupo: 'Tickets', items: [
    { key: 'categories', label: 'Categorías',            icon: Icon.tag,      desc: 'Áreas de soporte, su color y su SLA.' },
    { key: 'prio',       label: 'Prioridades',           icon: Icon.warn,     desc: 'Niveles de urgencia y sus colores.' },
    { key: 'canned',     label: 'Respuestas predefinidas', icon: Icon.note,   desc: 'Textos que se insertan con «/» al responder.' },
    { key: 'faqs',       label: 'Base de conocimiento',  icon: Icon.search,   desc: 'Lo que ve el cliente en el portal: Centro de atención y Preguntas frecuentes.' },
    { key: 'rules',      label: 'Reglas automáticas',    icon: Icon.settings, desc: 'Asignar, categorizar y priorizar solo.' },
    { key: 'behavior',   label: 'Comportamiento',        icon: Icon.ticket,   desc: 'Estado inicial, bloqueo entre agentes y cierre automático.' },
    { key: 'hours',      label: 'Horario de atención',   icon: Icon.clock,    desc: 'Cuándo se atiende. Sobre esto corre el reloj del SLA.' },
  ] },
  { grupo: 'Correo', items: [
    { key: 'email', label: 'Buzón y envío',       icon: Icon.mail,  desc: 'Entrada, salida, pie y diagnóstico.' },
    { key: 'tpl',   label: 'Avisos automáticos',  icon: Icon.send,  desc: 'Qué se envía y a quién cuando algo pasa.' },
    { key: 'bans',  label: 'Correos bloqueados',  icon: Icon.lock,  desc: 'Remitentes que no generan ticket.' },
  ] },
  { grupo: 'Sistema', items: [
    { key: 'security', label: 'Seguridad',          icon: Icon.lock,  desc: 'Protección del acceso frente a intentos.' },
    { key: 'cron',     label: 'Tareas programadas', icon: Icon.clock, desc: 'El planificador que mueve el correo y los cierres.' },
  ] },
]

export default function SupportSettings() {
  const [tab, setTab] = useState('categories')
  const actual = SECCIONES.flatMap((s) => s.items).find((i) => i.key === tab)

  return (
    <>
      <header className="page-head">
        <span className="sc-ic"><Icon.settings style={{ width: 18, height: 18, fill: 'var(--primary)' }} /></span>
        <div><h1>Configuración de Soporte</h1></div>
        {actual && <span className="sub">· {actual.desc}</span>}
        <div className="spacer" />
      </header>

      <div className="cfg-layout">
        <nav className="cfg-nav">
          {SECCIONES.map((s) => (
            <div key={s.grupo} className="cfg-nav-group">
              <span className="cfg-nav-t">{s.grupo}</span>
              {s.items.map((i) => (
                <button key={i.key} className={`cfg-nav-item ${tab === i.key ? 'on' : ''}`} onClick={() => setTab(i.key)}>
                  <i.icon /> <span>{i.label}</span>
                </button>
              ))}
            </div>
          ))}
        </nav>

        <div className="cfg-body">
          {tab === 'categories' && <Categories />}
          {tab === 'canned' && <Canned />}
          {tab === 'faqs' && <Faqs />}
          {tab === 'email' && <EmailChannel />}
          {tab === 'bans' && <EmailBans />}
          {tab === 'tpl' && <EmailTemplates />}
          {tab === 'prio' && <Priorities />}
          {tab === 'behavior' && <TicketBehavior />}
          {tab === 'hours' && <BusinessHours />}
          {tab === 'security' && <SecuritySettings />}
          {tab === 'cron' && <CronStatus />}
          {tab === 'rules' && <TicketRules />}
        </div>
      </div>
    </>
  )
}

/* ------------------------------- Categorías ------------------------------- */

function Categories() {
  const toast = useToast()
  const confirm = useConfirm()
  const [rows, setRows] = useState(null)
  const [form, setForm] = useState(null)   // null | {id,name,description,color,sla_hours}

  const load = useCallback(() => { api.supCategories().then((d) => setRows(d.categories || [])) }, [])
  useEffect(() => { load() }, [load])

  const blank = { id: 0, name: '', description: '', color: '#8b5cf6', sla_response_hours: 4, sla_resolve_hours: 24, use_shift: false }

  const save = async () => {
    if (!form.name.trim()) { toast('El nombre es obligatorio', 'err'); return }
    const r = await api.supSaveCategory(form)
    if (r.ok) { toast(form.id ? 'Categoría actualizada' : 'Categoría creada'); setForm(null); load() }
    else toast(r.error || 'Error', 'err')
  }
  const del = async (c) => {
    if (!(await confirm({ title: 'Eliminar categoría', message: `¿Eliminar «${c.name}»?`, danger: true, confirmText: 'Eliminar' }))) return
    const r = await api.supDeleteCategory(c.id)
    if (r.ok) { toast('Categoría eliminada'); load() } else toast(r.error || 'Error', 'err')
  }

  return (
    <>
      <div className="cfg-head">
        <h2>Categorías de tickets</h2>
        <button className="btn" onClick={() => setForm({ ...blank })}><Icon.plus /> Nueva categoría</button>
      </div>

      {rows === null ? <div className="center-load"><div className="spinner" /></div> : (
        <div className="cfg-grid">
          {rows.map((c) => (
            <div key={c.id} className="card cfg-card">
              <div className="cfg-card-h">
                <span className="cfg-tag"><Icon.tag style={{ fill: c.color }} /></span>
                <b>{c.name}</b>
                <span className={`chip ${Number(c.active) ? 'abierto' : 'cerrado'} sm`}>{Number(c.active) ? 'Activa' : 'Inactiva'}</span>
              </div>
              <p className="cfg-desc">{c.description || 'Sin descripción'}</p>
              {/* Los dos relojes del SLA, en horas LABORABLES. */}
              <div className="cfg-sla">
                <span>Responder</span>
                <b>{c.sla_response_hours ? `${c.sla_response_hours} h` : '—'}</b>
              </div>
              <div className="cfg-sla">
                <span>Resolver</span>
                <b>{c.sla_resolve_hours ? `${c.sla_resolve_hours} h` : '—'}</b>
              </div>
              <div className="cfg-actions">
                <button className="icon-btn" title="Editar" onClick={() => setForm({ id: c.id, name: c.name, description: c.description || '', color: c.color, sla_response_hours: c.sla_response_hours || '', sla_resolve_hours: c.sla_resolve_hours || '', use_shift: !!Number(c.use_shift) })}><Icon.pencil /></button>
                <button className="icon-btn" title="Eliminar" style={{ color: 'var(--danger)' }} onClick={() => del(c)}><Icon.trash /></button>
              </div>
            </div>
          ))}
        </div>
      )}

      {form && (
        <Modal title={form.id ? 'Editar categoría' : 'Nueva categoría'} onClose={() => setForm(null)} onSave={save} saveLabel={form.id ? 'Actualizar' : 'Crear'}>
          <div className="grid2">
            <label className="field"><span className="lbl">Nombre <em>*</em></span>
              <input value={form.name} onChange={(e) => setForm((f) => ({ ...f, name: e.target.value }))} placeholder="p. ej. Soporte Técnico" autoFocus /></label>
            <div className="field"><span className="lbl">Color</span>
              <Select block value={form.color} onChange={(color) => setForm((f) => ({ ...f, color }))}
                options={COLORS.map((c) => ({ ...c, color: c.value }))} /></div>
          </div>
          <label className="field"><span className="lbl">Descripción</span>
            <textarea rows={2} value={form.description} onChange={(e) => setForm((f) => ({ ...f, description: e.target.value }))} placeholder="Para qué es esta categoría" /></label>
          {/* Dos compromisos distintos: contestar y resolver. */}
          <div className="grid2">
            <label className="field"><span className="lbl">Primera respuesta <span className="hint">(horas)</span></span>
              <input type="number" min="1" value={form.sla_response_hours}
                onChange={(e) => setForm((f) => ({ ...f, sla_response_hours: e.target.value }))} placeholder="4" /></label>
            <label className="field"><span className="lbl">Resolución <span className="hint">(horas)</span></span>
              <input type="number" min="1" value={form.sla_resolve_hours}
                onChange={(e) => setForm((f) => ({ ...f, sla_resolve_hours: e.target.value }))} placeholder="24" /></label>
          </div>
          <p className="ct-hint">
            Se cuentan en <b>horas laborables</b>: un plazo de 4 h que entra a las 20:00 vence a la mañana siguiente,
            no de madrugada. Déjalo vacío si esta categoría no tiene ese compromiso.
          </p>

          {/*
            Solo el soporte que ROTA se reparte por turno. Facturas o garantías tienen
            responsable fijo, así que su interruptor se queda apagado.
          */}
          <div className="field" style={{ marginBottom: 0 }}>
            <span className="lbl">Reparto de los tickets nuevos</span>
            <label className="fb-req-row" style={{ marginTop: 6 }}>
              <span className="fb-switch"><input type="checkbox" checked={!!form.use_shift} onChange={(e) => setForm((f) => ({ ...f, use_shift: e.target.checked }))} /><span className={`fb-toggle ${form.use_shift ? 'on' : ''}`} /></span>
              <span className="fb-req-label">{form.use_shift ? 'Al agente de guardia (según el cuadrante de turnos)' : 'Sin asignar (o lo que digan las reglas automáticas)'}</span>
            </label>
          </div>
        </Modal>
      )}
    </>
  )
}

/* ------------------------- Respuestas predefinidas ------------------------- */

function Canned() {
  const toast = useToast()
  const confirm = useConfirm()
  const [rows, setRows] = useState(null)
  const [form, setForm] = useState(null)

  const load = useCallback(() => { api.supCanned().then((d) => setRows(d.canned || [])) }, [])
  useEffect(() => { load() }, [load])

  const blank = { id: 0, shortcut: '', title: '', body: '' }

  const save = async () => {
    if (!form.title.trim() || !form.shortcut.trim() || !form.body.trim()) { toast('Completa atajo, título y texto', 'err'); return }
    const r = await api.supSaveCanned(form)
    if (r.ok) { toast(form.id ? 'Respuesta actualizada' : 'Respuesta creada'); setForm(null); load() }
    else toast(r.error || 'Error', 'err')
  }
  const del = async (c) => {
    if (!(await confirm({ title: 'Eliminar respuesta', message: `¿Eliminar «${c.title}»?`, danger: true, confirmText: 'Eliminar' }))) return
    const r = await api.supDeleteCanned(c.id)
    if (r.ok) { toast('Respuesta eliminada'); load() } else toast(r.error || 'Error', 'err')
  }

  return (
    <>
      <div className="cfg-head">
        <div>
          <h2>Respuestas predefinidas</h2>
          <p className="cfg-hint">Textos reutilizables. El agente los inserta escribiendo <b>/atajo</b> en el editor de respuestas, o eligiéndolos manualmente.</p>
        </div>
        <button className="btn" onClick={() => setForm({ ...blank })}><Icon.plus /> Nueva respuesta</button>
      </div>

      {rows === null ? <div className="center-load"><div className="spinner" /></div> : rows.length === 0 ? (
        <div className="card tk-empty">
          <div className="e-ic"><Icon.note style={{ width: 26, height: 26, fill: 'var(--ink-2)' }} /></div>
          <h3>Aún no hay respuestas</h3>
          <p>Crea atajos para no reescribir lo de siempre.</p>
        </div>
      ) : (
        <div className="card" style={{ padding: 0 }}>
          {rows.map((c) => (
            <div key={c.id} className="cnd-row">
              <span className="cnd-short">/{c.shortcut}</span>
              <div className="cnd-tx">
                <b>{c.title}</b>
                <small>{c.body}</small>
              </div>
              <span style={{ flex: 1 }} />
              <button className="icon-btn" title="Editar" onClick={() => setForm({ id: c.id, shortcut: c.shortcut, title: c.title, body: c.body })}><Icon.pencil /></button>
              <button className="icon-btn" title="Eliminar" style={{ color: 'var(--danger)' }} onClick={() => del(c)}><Icon.trash /></button>
            </div>
          ))}
        </div>
      )}

      {form && (
        <Modal title={form.id ? 'Editar respuesta' : 'Nueva respuesta'} onClose={() => setForm(null)} onSave={save} saveLabel={form.id ? 'Actualizar' : 'Crear'}>
          <div className="grid2">
            <label className="field"><span className="lbl">Atajo <em>*</em></span>
              <div className="cnd-input"><span>/</span>
                <input value={form.shortcut} onChange={(e) => setForm((f) => ({ ...f, shortcut: e.target.value.replace(/[^a-z0-9_]/gi, '').toLowerCase() }))} placeholder="saludo" autoFocus />
              </div>
              <span className="hint">Se escribe /{form.shortcut || 'atajo'} en el editor</span>
            </label>
            <label className="field"><span className="lbl">Título <em>*</em></span>
              <input value={form.title} onChange={(e) => setForm((f) => ({ ...f, title: e.target.value }))} placeholder="Saludo inicial" /></label>
          </div>
          <label className="field"><span className="lbl">Texto <em>*</em></span>
            <textarea rows={5} value={form.body} onChange={(e) => setForm((f) => ({ ...f, body: e.target.value }))} placeholder="El texto que se insertará en la respuesta…" /></label>
        </Modal>
      )}
    </>
  )
}

/* ---------------------------- Base de conocimiento -----------------------
 * Lo que el cliente ve en el portal, en dos secciones (como la «Knowledge base»
 * de osTicket):
 *   · «Preguntas frecuentes» → Q&A, con PALABRAS CLAVE (cómo lo dice el cliente
 *      aunque no salga en el título), categoría para el CTA y votos 👍/👎.
 *   · «Centro de atención» → fichas de info de la empresa (horario, correos,
 *      teléfonos). Solo título + contenido.
 * ------------------------------------------------------------------------- */
const KB_SECS = [
  { key: 'info', label: 'Centro de atención' },
  { key: 'faq', label: 'Preguntas frecuentes' },
]
function Faqs() {
  const toast = useToast()
  const confirm = useConfirm()
  const [rows, setRows] = useState(null)
  const [cats, setCats] = useState([])
  const [form, setForm] = useState(null)
  const [sec, setSec] = useState('info')   // sección visible

  const load = useCallback(() => { api.listFaqs().then((d) => setRows(d.faqs || [])) }, [])
  useEffect(() => { load() }, [load])
  useEffect(() => { api.supCategories().then((d) => setCats(d.categories || [])) }, [])

  const esInfo = sec === 'info'
  const visibles = (rows || []).filter((r) => (r.section || 'faq') === sec)
  const blank = { id: 0, section: sec, question: '', answer: '', hint: '', keywords: '', category_id: '', active: true }
  const editando = form && form.section === 'info'   // el modal abierto es de una ficha de info

  const save = async () => {
    const info = form.section === 'info'
    if (!form.question.trim()) { toast(info ? 'El título es obligatorio' : 'La pregunta es obligatoria', 'err'); return }
    if (!form.answer.trim()) { toast(info ? 'El contenido es obligatorio' : 'La respuesta es obligatoria', 'err'); return }
    const r = await api.saveFaq(form)
    if (r.ok) { toast(form.id ? 'Guardado' : 'Creado'); setForm(null); load() }
    else toast(r.error || 'Error', 'err')
  }
  const del = async (f) => {
    if (!(await confirm({ title: 'Eliminar', message: `¿Eliminar «${f.question}»?`, danger: true, confirmText: 'Eliminar' }))) return
    const r = await api.deleteFaq(f.id)
    if (r.ok) { toast('Eliminado'); load() } else toast(r.error || 'Error', 'err')
  }
  // Mover arriba/abajo DENTRO de la sección visible; se manda su nuevo orden de ids.
  const mover = async (i, dir) => {
    const j = i + dir
    if (j < 0 || j >= visibles.length) return
    const nuevo = [...visibles]
    ;[nuevo[i], nuevo[j]] = [nuevo[j], nuevo[i]]
    const otras = rows.filter((r) => (r.section || 'faq') !== sec)
    setRows([...otras, ...nuevo])   // optimista (el orden entre secciones no importa, se filtra)
    const r = await api.reorderFaqs(nuevo.map((f) => f.id))
    if (!r.ok) { toast(r.error || 'No se pudo reordenar', 'err'); load() }
  }

  const edit = (f) => setForm({
    id: f.id, section: f.section || 'faq', question: f.question, answer: f.answer, hint: f.hint || '',
    keywords: f.keywords || '', category_id: f.category_id ? String(f.category_id) : '', active: !!f.active,
  })

  return (
    <>
      <div className="cfg-head">
        <div>
          <h2>Base de conocimiento</h2>
          <p className="cfg-hint">Lo que el cliente ve en el portal, en dos secciones: el <b>Centro de atención</b> (horario, correos, teléfonos) y las <b>Preguntas frecuentes</b>.</p>
        </div>
        <button className="btn" onClick={() => setForm({ ...blank })}><Icon.plus /> {esInfo ? 'Nueva ficha' : 'Nueva pregunta'}</button>
      </div>

      {/* Selector de sección */}
      <div className="kb-seg">
        {KB_SECS.map((s) => (
          <button key={s.key} className={sec === s.key ? 'on' : ''} onClick={() => setSec(s.key)}>
            {s.label}<span className="kb-n">{(rows || []).filter((r) => (r.section || 'faq') === s.key).length}</span>
          </button>
        ))}
      </div>

      {rows === null ? <div className="center-load"><div className="spinner" /></div> : visibles.length === 0 ? (
        <div className="card tk-empty">
          <div className="e-ic"><Icon.search style={{ width: 26, height: 26, fill: 'var(--ink-2)' }} /></div>
          <h3>{esInfo ? 'Aún no hay fichas' : 'Aún no hay preguntas'}</h3>
          <p>{esInfo ? 'Añade la info que el cliente busca: horario, contacto…' : 'Añade las dudas que más se repiten para desviar tickets.'}</p>
        </div>
      ) : (
        <div className="card" style={{ padding: 0 }}>
          {visibles.map((f, i) => {
            const votos = f.helpful_yes + f.helpful_no
            const util = votos ? Math.round((f.helpful_yes / votos) * 100) : null
            return (
              <div key={f.id} className={`faq-row ${f.active ? '' : 'off'}`}>
                <div className="faq-ord">
                  <button className="icon-btn xs" title="Subir" disabled={i === 0} onClick={() => mover(i, -1)}><Icon.chevron style={{ transform: 'rotate(180deg)' }} /></button>
                  <button className="icon-btn xs" title="Bajar" disabled={i === visibles.length - 1} onClick={() => mover(i, 1)}><Icon.chevron /></button>
                </div>
                <div className="faq-main">
                  <div className="faq-q">
                    {f.question}
                    {!f.active && <span className="chip cerrado sm">Borrador</span>}
                    {!esInfo && f.category_name && <span className="chip sm faq-cat">{f.category_name}</span>}
                  </div>
                  {esInfo
                    ? <div className="faq-body">{f.answer}</div>
                    : f.keywords
                      ? <div className="faq-kw">{f.keywords.split(',').map((k) => k.trim()).filter(Boolean).map((k) => <span key={k} className="kw-tag">{k}</span>)}</div>
                      : <div className="faq-kw none">Sin palabras clave · el cliente podría no encontrarla</div>}
                </div>
                {!esInfo && (
                  <div className="faq-stats" title="Vistas y votos de utilidad en el portal">
                    <span>{f.views} vista{f.views === 1 ? '' : 's'}</span>
                    {util !== null
                      ? <span className={util >= 50 ? 'ok' : 'bad'}>👍 {util}% <small>({votos})</small></span>
                      : <span className="muted">Sin votos</span>}
                  </div>
                )}
                <div className="faq-act">
                  <button className="icon-btn" title="Editar" onClick={() => edit(f)}><Icon.pencil /></button>
                  <button className="icon-btn" title="Eliminar" style={{ color: 'var(--danger)' }} onClick={() => del(f)}><Icon.trash /></button>
                </div>
              </div>
            )
          })}
        </div>
      )}

      {form && (
        <Modal title={`${form.id ? 'Editar' : 'Nueva'} ${editando ? 'ficha' : 'pregunta'}`} onClose={() => setForm(null)} onSave={save} saveLabel={form.id ? 'Actualizar' : 'Crear'} width={620}>
          <label className="field"><span className="lbl">{editando ? 'Título' : 'Pregunta'} <em>*</em></span>
            <input value={form.question} onChange={(e) => setForm((f) => ({ ...f, question: e.target.value }))} placeholder={editando ? 'p. ej. Horario de servicio' : 'p. ej. Hoy no cargan las etiquetas'} autoFocus /></label>
          <label className="field"><span className="lbl">{editando ? 'Contenido' : 'Respuesta'} <em>*</em></span>
            <textarea rows={editando ? 3 : 4} value={form.answer} onChange={(e) => setForm((f) => ({ ...f, answer: e.target.value }))} placeholder={editando ? 'p. ej. De lunes a viernes, de 7:00 a 21:00 h.' : 'Qué debe comprobar o hacer el cliente…'} /></label>

          {/* La pista, palabras clave y categoría solo tienen sentido en una FAQ. */}
          {!editando && (
            <>
              <label className="field"><span className="lbl">Pista corta <span className="hint">· resumen de una línea (opcional)</span></span>
                <input value={form.hint} onChange={(e) => setForm((f) => ({ ...f, hint: e.target.value }))} placeholder="p. ej. Suele ser un repetidor caído" /></label>
              <div className="field"><span className="lbl">Palabras clave <span className="hint">· cómo lo dice el cliente. Enter o coma para añadir</span></span>
                <KeywordsInput value={form.keywords} onChange={(keywords) => setForm((f) => ({ ...f, keywords }))} /></div>
              <div className="field"><span className="lbl">Categoría vinculada <span className="hint">· para el botón «abrir incidencia» desde esta FAQ</span></span>
                <Select block value={form.category_id} onChange={(v) => setForm((f) => ({ ...f, category_id: v }))}
                  options={[{ value: '', label: 'Ninguna' }, ...cats.map((c) => ({ value: String(c.id), label: c.name }))]} /></div>
            </>
          )}
          <label className="fb-req-row">
            <span className="fb-switch"><input type="checkbox" checked={form.active} onChange={(e) => setForm((f) => ({ ...f, active: e.target.checked }))} /><span className={`fb-toggle ${form.active ? 'on' : ''}`} /></span>
            <span className="fb-req-label">Publicada <span className="hint">· si no, queda como borrador y el cliente no la ve</span></span>
          </label>
        </Modal>
      )}
    </>
  )
}

/* Entrada de palabras clave en «chips»: escribe y pulsa Enter o coma para añadir;
   la × quita. Por dentro es una cadena separada por comas (lo que espera el back). */
function KeywordsInput({ value, onChange }) {
  const [txt, setTxt] = useState('')
  const tags = (value || '').split(',').map((t) => t.trim()).filter(Boolean)

  const add = () => {
    const t = txt.trim().toLowerCase()
    if (t && !tags.includes(t)) onChange([...tags, t].join(', '))
    setTxt('')
  }
  const remove = (t) => onChange(tags.filter((x) => x !== t).join(', '))

  return (
    <div className="kw-input" onClick={(e) => e.currentTarget.querySelector('input')?.focus()}>
      {tags.map((t) => (
        <span key={t} className="kw-chip">{t}<button type="button" onClick={() => remove(t)} aria-label={`Quitar ${t}`}>✕</button></span>
      ))}
      <input value={txt} placeholder={tags.length ? '' : 'no cargan, en blanco, piloto rojo…'}
        onChange={(e) => setTxt(e.target.value)}
        onKeyDown={(e) => {
          if (e.key === 'Enter' || e.key === ',') { e.preventDefault(); add() }
          else if (e.key === 'Backspace' && !txt && tags.length) remove(tags[tags.length - 1])
        }}
        onBlur={add} />
    </div>
  )
}

/* --------------------------- Correos bloqueados --------------------------- */

function EmailBans() {
  const toast = useToast()
  const confirm = useConfirm()
  const [rows, setRows] = useState(null)
  const [form, setForm] = useState(null)   // null | {id,email,active,notes}

  const load = useCallback(() => { api.listEmailBans().then((d) => setRows(d.bans || [])) }, [])
  useEffect(() => { load() }, [load])

  const blank = { id: 0, email: '', active: true, notes: '' }

  const save = async () => {
    if (!form.email.trim()) { toast('La dirección es obligatoria', 'err'); return }
    const r = await api.saveEmailBan(form)
    if (r.ok) { toast(form.id ? 'Bloqueo actualizado' : 'Correo bloqueado'); setForm(null); load() }
    else toast(r.error || 'Error', 'err')
  }
  const del = async (b) => {
    if (!(await confirm({ title: 'Quitar de la lista', message: `¿Desbloquear «${b.email}»?`, danger: true, confirmText: 'Quitar' }))) return
    const r = await api.deleteEmailBan(b.id)
    if (r.ok) { toast('Desbloqueado'); load() } else toast(r.error || 'Error', 'err')
  }
  const fmt = (d) => d ? new Date(d.replace(' ', 'T')).toLocaleString('es-ES', { dateStyle: 'short', timeStyle: 'short' }) : '—'

  return (
    <>
      <div className="cfg-head">
        <h2>Correos bloqueados</h2>
        <button className="btn" onClick={() => setForm({ ...blank })}><Icon.plus /> Bloquear correo</button>
      </div>
      <p className="cfg-hint" style={{ margin: '0 0 14px', color: 'var(--ink-2)', fontSize: 13 }}>
        Los correos entrantes de estas direcciones (o dominios) no crean ticket: se descartan.
        Útil para spam y para los <b>MAILER-DAEMON</b> (rebotes). Escribe una dirección o un dominio (p. ej. <code>@spam.com</code>).
      </p>

      {rows === null ? <div className="center-load"><div className="spinner" /></div> : (
        rows.length === 0
          ? <div className="tk-empty"><p>No hay correos bloqueados.</p></div>
          : <div className="card" style={{ padding: 0, overflow: 'hidden' }}>
              <table className="tk-table">
                <thead><tr><th>Dirección de correo</th><th>Estado</th><th>Última actualización</th><th style={{ width: 96 }}>Acción</th></tr></thead>
                <tbody>
                  {rows.map((b) => (
                    <tr key={b.id}>
                      <td><b>{b.email}</b></td>
                      <td><span className={`chip ${Number(b.active) ? 'cerrado' : 'abierto'} sm`}>{Number(b.active) ? 'Activo' : 'Inactivo'}</span></td>
                      <td>{fmt(b.updated_at)}</td>
                      <td>
                        <div className="cfg-actions" style={{ margin: 0 }}>
                          <button className="icon-btn" title="Editar" onClick={() => setForm({ id: b.id, email: b.email, active: !!Number(b.active), notes: b.notes || '' })}><Icon.pencil /></button>
                          <button className="icon-btn" title="Quitar" style={{ color: 'var(--danger)' }} onClick={() => del(b)}><Icon.trash /></button>
                        </div>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
      )}

      {form && (
        <Modal title={form.id ? 'Editar bloqueo' : 'Bloquear correo'} onClose={() => setForm(null)} onSave={save} saveLabel={form.id ? 'Actualizar' : 'Bloquear'}>
          <div className="grid2">
            <label className="field"><span className="lbl">Dirección de correo <em>*</em></span>
              <input value={form.email} onChange={(e) => setForm((f) => ({ ...f, email: e.target.value }))} placeholder="spam@dominio.com  o  @dominio.com" autoFocus /></label>
            <div className="field"><span className="lbl">Estado</span>
              <label className="fb-req-row" style={{ marginTop: 6 }}>
                <span className="fb-switch"><input type="checkbox" checked={form.active} onChange={(e) => setForm((f) => ({ ...f, active: e.target.checked }))} /><span className={`fb-toggle ${form.active ? 'on' : ''}`} /></span>
                <span className="fb-req-label">{form.active ? 'Activo (bloquea)' : 'Inactivo (no bloquea)'}</span>
              </label>
            </div>
          </div>
          <label className="field"><span className="lbl">Notas internas</span>
            <textarea rows={3} value={form.notes} onChange={(e) => setForm((f) => ({ ...f, notes: e.target.value }))} placeholder="Motivo del bloqueo (opcional)" /></label>
        </Modal>
      )}
    </>
  )
}

/* ---------------------------- Plantillas de aviso ----------------------------
 * Correos automáticos al ocurrir algo en un ticket. Nacen DESACTIVADAS: no se
 * manda nada hasta que alguien active la plantilla a conciencia.
 * ------------------------------------------------------------------------- */
function EmailTemplates() {
  const toast = useToast()
  const [rows, setRows] = useState(null)
  const [vars, setVars] = useState([])
  const [dest, setDest] = useState({})
  const [form, setForm] = useState(null)

  const load = useCallback(() => {
    api.listEmailTemplates().then((d) => { setRows(d.templates || []); setVars(d.vars || []); setDest(d.recipients || {}) })
  }, [])
  useEffect(() => { load() }, [load])

  const save = async () => {
    const r = await api.saveEmailTemplate(form)
    if (r.ok) { toast('Plantilla guardada'); setForm(null); load() }
    else toast(r.error || 'Error', 'err')
  }
  // Activar/desactivar desde la propia lista, sin abrir la plantilla.
  // Se reenvían los destinatarios tal cual: si no, se guardarían vacíos.
  const toggle = async (t) => {
    const r = await api.saveEmailTemplate({
      id: t.id, subject: t.subject, body: t.body, active: !Number(t.active), recipients: t.recipients || {},
    })
    if (r.ok) { toast(Number(t.active) ? 'Plantilla desactivada' : 'Plantilla activada'); load() }
    else toast(r.error || 'Error', 'err')
  }
  const setDestino = (k) => (e) => setForm((f) => ({ ...f, recipients: { ...f.recipients, [k]: e.target.checked } }))
  // Resumen legible de a quién se avisa, para la tarjeta.
  const aQuien = (t) => Object.entries(t.recipients || {}).filter(([, v]) => v).map(([k]) => dest[k] || k).join(' · ') || '—'

  return (
    <>
      <div className="cfg-head"><h2>Plantillas de aviso</h2></div>
      <p className="cfg-hint" style={{ margin: '0 0 14px', color: 'var(--ink-2)', fontSize: 13 }}>
        Correos que se envían solos cuando ocurre algo en un ticket. Cada una se activa o desactiva por separado;
        <b> desactivada no envía nada</b>. Requiere tener el buzón de salida (SMTP) configurado.
      </p>

      {rows === null ? <div className="center-load"><div className="spinner" /></div> : (
        <div className="cfg-grid">
          {rows.map((t) => (
            <div key={t.id} className="card cfg-card">
              <div className="cfg-card-h">
                <b>{t.name}</b>
                <span className={`chip ${Number(t.active) ? 'abierto' : 'cerrado'} sm`}>{Number(t.active) ? 'Activa' : 'Inactiva'}</span>
              </div>
              <p className="cfg-desc">{t.description}</p>
              <p className="cfg-desc" style={{ opacity: 0.75 }}><b>Asunto:</b> {t.subject}</p>
              <p className="cfg-desc" style={{ opacity: 0.75 }}><b>Se avisa a:</b> {aQuien(t)}</p>
              <div className="cfg-actions">
                <button className="btn ghost sm" onClick={() => toggle(t)}>{Number(t.active) ? 'Desactivar' : 'Activar'}</button>
                <button className="icon-btn" title="Editar" onClick={() => setForm({
                  id: t.id, subject: t.subject, body: t.body, active: !!Number(t.active), recipients: { ...(t.recipients || {}) },
                })}><Icon.pencil /></button>
              </div>
            </div>
          ))}
        </div>
      )}

      {form && (
        <Modal title="Editar plantilla" onClose={() => setForm(null)} onSave={save} saveLabel="Actualizar">
          <label className="field"><span className="lbl">Asunto <em>*</em></span>
            <input value={form.subject} onChange={(e) => setForm((f) => ({ ...f, subject: e.target.value }))} autoFocus /></label>
          <label className="field"><span className="lbl">Contenido <em>*</em></span>
            <textarea rows={9} value={form.body} onChange={(e) => setForm((f) => ({ ...f, body: e.target.value }))} /></label>
          <div className="field">
            <span className="lbl">Variables disponibles</span>
            <div className="tpl-vars">
              {vars.map((v) => <code key={v} className="tpl-var">{v}</code>)}
            </div>
          </div>

          {/* A quién se avisa (equivale a «alertas y avisos» de osTicket). */}
          <div className="field">
            <span className="lbl">Se avisa a <em>*</em></span>
            <div className="tpl-dest">
              {Object.entries(dest).map(([k, label]) => (
                <label key={k} className="fb-req-row">
                  <span className="fb-switch"><input type="checkbox" checked={!!form.recipients?.[k]} onChange={setDestino(k)} /><span className={`fb-toggle ${form.recipients?.[k] ? 'on' : ''}`} /></span>
                  <span className="fb-req-label">{label}</span>
                </label>
              ))}
            </div>
            <p className="ct-hint">Cada uno recibe su propio correo. Si alguien encaja por dos vías, solo le llega una vez.</p>
          </div>

          <label className="fb-req-row" style={{ marginTop: 4 }}>
            <span className="fb-switch"><input type="checkbox" checked={form.active} onChange={(e) => setForm((f) => ({ ...f, active: e.target.checked }))} /><span className={`fb-toggle ${form.active ? 'on' : ''}`} /></span>
            <span className="fb-req-label">{form.active ? 'Activa (se envía)' : 'Inactiva (no se envía)'}</span>
          </label>
        </Modal>
      )}
    </>
  )
}

/*
 * Enganche común de las secciones de ajustes: carga, guarda SOLO sus campos y
 * avisa. Cada sección manda únicamente lo suyo (el servidor guarda lo que llega),
 * así guardar una no pisa los ajustes de las demás.
 */
function useAjustes(campos) {
  const toast = useToast()
  const [d, setD] = useState(null)
  const [f, setF] = useState(null)
  const [saving, setSaving] = useState(false)

  useEffect(() => { api.getTicketSettings().then((r) => { setD(r); setF({ ...r.settings }) }) }, [])

  const save = async () => {
    setSaving(true)
    const soloMios = Object.fromEntries(campos.map((k) => [k, f[k]]))
    const r = await api.saveTicketSettings(soloMios)
    setSaving(false)
    if (r.ok) {
      toast('Ajustes guardados')
      if (r.autoclose_pending !== undefined) setD((s) => ({ ...s, autoclose_pending: r.autoclose_pending }))
    } else toast(r.error || 'Error', 'err')
  }

  const set = (k) => (e) => setF((s) => ({ ...s, [k]: e.target.value }))
  return { d, f, setF, set, save, saving }
}

/** Botón de guardar de una sección de ajustes. */
function GuardarAjustes({ save, saving }) {
  return (
    <div style={{ marginTop: 16 }}>
      <button className="btn" disabled={saving} onClick={save}><Icon.save /> {saving ? 'Guardando…' : 'Guardar cambios'}</button>
    </div>
  )
}

/* --------------------- Comportamiento del ticket ---------------------
 * Equivale a «Ticket settings» de osTicket: con qué estado nace, el bloqueo
 * entre agentes y el cierre automático.
 * ------------------------------------------------------------------------- */
function TicketBehavior() {
  const { d, f, setF, save, saving } = useAjustes([
    'ticket_default_status', 'ticket_lock_minutes', 'ticket_autoclose_days', 'ticket_autoclose_notify',
  ])
  if (!d || !f) return <div className="center-load"><div className="spinner" /></div>

  // Un ticket no nace resuelto ni cerrado: no se ofrecen.
  const estados = Object.entries(d.statuses).filter(([k]) => !['resuelto', 'cerrado'].includes(k))

  return (
    <>
      <div className="cfg-head"><h2>Comportamiento del ticket</h2></div>

      <div className="card em-card">
        <h2>Al crearse</h2>
        <p className="em-desc">Con qué estado nace un ticket, venga de donde venga (correo, web o WhatsApp).</p>
        <div className="field" style={{ maxWidth: 320 }}><span className="lbl">Estado por defecto</span>
          <Select block value={f.ticket_default_status} onChange={(v) => setF((s) => ({ ...s, ticket_default_status: v }))}
            options={estados.map(([value, label]) => ({ value, label }))} /></div>
        <p className="ct-hint">La prioridad por defecto se elige en la pestaña <b>Prioridades</b>.</p>
      </div>

      <div className="card em-card" style={{ marginTop: 16 }}>
        <h2>Evitar colisión de agentes</h2>
        <p className="em-desc">
          Cuando un agente abre un ticket, queda <b>tomado</b> durante estos minutos: los demás lo ven ocupado
          y no pueden responder a la vez. Se libera solo al cerrarlo o al agotarse el tiempo, así que
          nadie deja un ticket atascado.
        </p>
        <label className="field" style={{ maxWidth: 220 }}><span className="lbl">Minutos de bloqueo</span>
          <input type="number" min="0" max="60" value={f.ticket_lock_minutes}
            onChange={(e) => setF((s) => ({ ...s, ticket_lock_minutes: e.target.value }))} /></label>
        <p className="ct-hint">
          {Number(f.ticket_lock_minutes) > 0
            ? `Un ticket abierto quedará tomado ${f.ticket_lock_minutes} minuto(s).`
            : 'Con 0 el bloqueo queda desactivado: cualquiera puede responder en cualquier momento.'}
        </p>
      </div>

      <div className="card em-card" style={{ marginTop: 16 }}>
        <h2>Cerrar tickets automáticamente</h2>
        <p className="em-desc">
          Cierra solo los tickets que llevan mucho tiempo <b>resueltos</b> sin moverse, para que la bandeja
          no acumule cola vieja. Un ticket abierto <b>nunca</b> se cierra solo: eso sería dar por atendido algo que no lo está.
        </p>
        <div className="grid2">
          <label className="field"><span className="lbl">Días tras resolverse</span>
            <input type="number" min="0" max="3650" value={f.ticket_autoclose_days}
              onChange={(e) => setF((s) => ({ ...s, ticket_autoclose_days: e.target.value }))} /></label>
        </div>
        <label className="fb-req-row" style={{ marginTop: 4 }}>
          <span className="fb-switch"><input type="checkbox" checked={!!f.ticket_autoclose_notify}
            onChange={(e) => setF((s) => ({ ...s, ticket_autoclose_notify: e.target.checked }))} /><span className={`fb-toggle ${f.ticket_autoclose_notify ? 'on' : ''}`} /></span>
          <span className="fb-req-label">Avisar al cliente al cerrarlo <span className="hint">· usa la plantilla «Ticket cerrado»</span></span>
        </label>
        <p className="ct-hint">
          {Number(f.ticket_autoclose_days) > 0
            ? <>Se cierran los resueltos con más de {f.ticket_autoclose_days} día(s) sin actividad. Ahora mismo afectaría a <b>{d.autoclose_pending}</b> ticket(s).</>
            : 'Con 0 el cierre automático queda desactivado.'}
        </p>
      </div>

      <GuardarAjustes save={save} saving={saving} />
    </>
  )
}

/* ---------------------------- Horario de atención ----------------------------
 * Cuándo se atiende. Es la base del SLA: fuera de horario el reloj se PARA, así
 * que un ticket que entra un viernes por la noche no consume plazo hasta el lunes.
 * No confundir con el cuadrante de turnos (quién trabaja cada semana).
 * ------------------------------------------------------------------------- */
function BusinessHours() {
  const toast = useToast()
  const confirm = useConfirm()
  const [d, setD] = useState(null)
  const [h, setH] = useState(null)          // { 1: [{opens,closes}], … }
  const [saving, setSaving] = useState(false)
  const [nuevo, setNuevo] = useState({ date: '', name: '' })

  const load = useCallback(() => {
    api.getBusinessHours().then((r) => { setD(r); setH(r.hours) })
  }, [])
  useEffect(() => { load() }, [load])

  const setTramo = (dia, i, k, v) => setH((s) => {
    const c = { ...s, [dia]: [...s[dia]] }; c[dia][i] = { ...c[dia][i], [k]: v }; return c
  })
  const addTramo = (dia) => setH((s) => ({ ...s, [dia]: [...s[dia], { opens: '09:00', closes: '18:00' }] }))
  const delTramo = (dia, i) => setH((s) => ({ ...s, [dia]: s[dia].filter((_, j) => j !== i) }))
  // Copiar el lunes al resto de días laborables: lo normal es que sean iguales.
  const copiarLunes = () => setH((s) => ({ ...s, 2: clonar(s[1]), 3: clonar(s[1]), 4: clonar(s[1]), 5: clonar(s[1]) }))
  const clonar = (arr) => (arr || []).map((t) => ({ ...t }))

  const save = async () => {
    setSaving(true)
    const r = await api.saveBusinessHours(h)
    setSaving(false)
    if (r.ok) { toast('Horario guardado'); load() } else toast(r.error || 'Error', 'err')
  }

  const addFestivo = async () => {
    if (!nuevo.date) { toast('Elige una fecha', 'err'); return }
    const r = await api.addHoliday(nuevo.date, nuevo.name)
    if (r.ok) { toast('Festivo añadido'); setNuevo({ date: '', name: '' }); load() }
    else toast(r.error || 'Error', 'err')
  }
  const delFestivo = async (f) => {
    if (!(await confirm({ title: 'Quitar festivo', message: `¿Quitar el ${fmtDia(f.date)}?`, danger: true, confirmText: 'Quitar' }))) return
    const r = await api.delHoliday(f.id)
    if (r.ok) { toast('Festivo quitado'); load() } else toast(r.error || 'Error', 'err')
  }

  if (!d || !h) return <div className="center-load"><div className="spinner" /></div>

  return (
    <>
      <div className="cfg-head">
        <h2>Horario de atención</h2>
        <span className={`chip ${d.open_now ? 'abierto' : 'cerrado'} sm`}>{d.open_now ? 'Abierto ahora' : 'Cerrado ahora'}</span>
      </div>
      <p className="cfg-hint" style={{ margin: '0 0 14px', fontSize: 13 }}>
        Sobre esto corre el reloj del <b>SLA</b>: fuera de horario se para, así que un ticket que entra un viernes
        por la noche no consume plazo hasta el lunes. Puedes poner <b>varios tramos por día</b> (jornada partida);
        si se solapan, se juntan solos. <b>{d.week_hours} h</b> de atención a la semana.
      </p>

      {/* Interruptor general: apagarlo NO borra las horas de cada categoría. */}
      <div className={`card sla-sw ${d.sla_active ? 'on' : ''}`}>
        <label className="fb-req-row">
          <span className="fb-switch">
            <input type="checkbox" checked={!!d.sla_active} onChange={async (e) => {
              const r = await api.toggleSla(e.target.checked)
              if (r.ok) { toast(r.active ? 'SLA activado' : 'SLA desactivado'); load() }
              else toast(r.error || 'Error', 'err')
            }} />
            <span className={`fb-toggle ${d.sla_active ? 'on' : ''}`} />
          </span>
          <span className="fb-req-label">
            <b>{d.sla_active ? 'SLA activado' : 'SLA desactivado'}</b>
            <small>
              {d.sla_active
                ? <>Se calculan los plazos y se avisa <b>en pantalla</b> de los vencidos. No se manda ningún correo por esto.</>
                : <>No se calcula ningún plazo. Las horas de cada categoría se conservan tal cual.</>}
            </small>
          </span>
        </label>

        <div className="sla-sw-cats">
          {d.sla_cats?.length
            ? <>Con plazo puesto: {d.sla_cats.map((c) => <span key={c} className="chip sm">{c}</span>)}</>
            : <i>Ninguna categoría tiene plazo configurado todavía: el SLA no hará nada hasta que pongas horas en alguna.</i>}
        </div>
      </div>

      {/* El reloj se para cuando la pelota no está en nuestro tejado. */}
      <p className="cfg-hint" style={{ margin: '10px 0 14px', fontSize: 12.5 }}>
        El reloj también se <b>pausa</b> mientras el ticket está en <b>Esperando respuesta</b>, <b>Resuelto</b> o
        <b> Cerrado</b>: si el cliente tarda tres días en contestar, ese tiempo no cuenta como retraso vuestro.
      </p>

      <div className="card em-card">
        <div className="bh-head">
          <h2>Semana</h2>
          <button className="btn ghost sm" onClick={copiarLunes} title="Copia los tramos del lunes al resto de días laborables">
            <Icon.copy /> Aplicar el lunes a L-V
          </button>
        </div>

        <div className="bh-days">
          {Object.entries(d.days).map(([dia, nombre]) => (
            <div key={dia} className={`bh-day ${(h[dia] || []).length === 0 ? 'off' : ''}`}>
              <div className="bh-day-n">{nombre}</div>
              <div className="bh-tramos">
                {(h[dia] || []).length === 0
                  ? <span className="bh-cerrado">Cerrado</span>
                  : h[dia].map((t, i) => (
                    <div key={i} className="bh-tramo">
                      <input type="time" value={t.opens} onChange={(e) => setTramo(dia, i, 'opens', e.target.value)} />
                      <span>a</span>
                      <input type="time" value={t.closes} onChange={(e) => setTramo(dia, i, 'closes', e.target.value)} />
                      <button className="icon-btn" title="Quitar tramo" style={{ color: 'var(--danger)' }}
                        onClick={() => delTramo(dia, i)}><Icon.trash /></button>
                    </div>
                  ))}
              </div>
              <button className="btn ghost sm" onClick={() => addTramo(dia)}><Icon.plus /> Tramo</button>
            </div>
          ))}
        </div>

        <div style={{ marginTop: 14 }}>
          <button className="btn" disabled={saving} onClick={save}><Icon.save /> {saving ? 'Guardando…' : 'Guardar horario'}</button>
        </div>
      </div>

      <div className="card em-card" style={{ marginTop: 16 }}>
        <h2>Festivos</h2>
        <p className="em-desc">Días sueltos en los que no se atiende. El reloj del SLA se los salta.</p>

        <div className="bh-fest-new">
          <input type="date" value={nuevo.date} onChange={(e) => setNuevo((s) => ({ ...s, date: e.target.value }))} />
          <input value={nuevo.name} onChange={(e) => setNuevo((s) => ({ ...s, name: e.target.value }))} placeholder="Motivo (opcional): Navidad, local…" />
          <button className="btn ghost sm" onClick={addFestivo}><Icon.plus /> Añadir</button>
        </div>

        {d.holidays.length === 0
          ? <p className="ct-hint" style={{ marginTop: 12 }}>No hay festivos configurados.</p>
          : (
            <div className="bh-fests">
              {d.holidays.map((f) => (
                <span key={f.id} className="bh-fest">
                  <b>{fmtDia(f.date)}</b>{f.name && <i>· {f.name}</i>}
                  <button className="icon-btn" title="Quitar" onClick={() => delFestivo(f)}>✕</button>
                </span>
              ))}
            </div>
          )}
      </div>
    </>
  )
}

const fmtDia = (s) => s ? new Date(String(s).slice(0, 10) + 'T00:00:00').toLocaleDateString('es-ES', { weekday: 'short', day: '2-digit', month: 'short', year: 'numeric' }) : '—'

/* ----------------------------- Seguridad del acceso -----------------------------
 * Es del SISTEMA, no del soporte: por eso vive en su propia sección.
 * ------------------------------------------------------------------------- */
function SecuritySettings() {
  const { d, f, set, save, saving } = useAjustes([
    'login_max_user', 'login_max_ip', 'login_lock_minutes', 'login_lock_message',
  ])
  if (!d || !f) return <div className="center-load"><div className="spinner" /></div>

  return (
    <>
      <div className="cfg-head"><h2>Seguridad del acceso</h2></div>

      <div className="card em-card">
        <h2>Intentos de inicio de sesión</h2>
        <p className="em-desc">
          Protección contra intentos de adivinar contraseñas. Solo cuentan los intentos <b>fallidos</b>;
          al entrar bien, el contador se limpia. Hay dos contadores: uno por cuenta (probar contraseñas
          de una persona) y otro por IP (probar muchas cuentas desde el mismo sitio).
        </p>
        <div className="rl-grid3">
          <label className="field"><span className="lbl">Intentos por cuenta</span>
            <input type="number" min="1" max="50" value={f.login_max_user} onChange={set('login_max_user')} /></label>
          <label className="field"><span className="lbl">Intentos por IP</span>
            <input type="number" min="1" max="200" value={f.login_max_ip} onChange={set('login_max_ip')} /></label>
          <label className="field"><span className="lbl">Minutos de bloqueo</span>
            <input type="number" min="1" max="120" value={f.login_lock_minutes} onChange={set('login_lock_minutes')} /></label>
        </div>
        <label className="field" style={{ marginTop: 12 }}><span className="lbl">Mensaje al bloquear</span>
          <input value={f.login_lock_message} onChange={set('login_lock_message')}
            placeholder="Demasiados intentos. Inténtalo de nuevo en :segundos s." /></label>
        <p className="ct-hint">Usa <code>:segundos</code> donde quieras que salga el tiempo que queda. En blanco se usa el mensaje por defecto.</p>
      </div>

      <GuardarAjustes save={save} saving={saving} />
    </>
  )
}

/* ------------------------------ Estado del cron ------------------------------
 * Informativo: el comando que hay que dejar en el servidor y si de verdad corre.
 * Es el fallo más silencioso del despliegue: no salta ningún error, simplemente
 * deja de entrar el correo.
 * ------------------------------------------------------------------------- */
function CronStatus() {
  const toast = useToast()
  const [c, setC] = useState(null)
  useEffect(() => { api.getCronStatus().then(setC) }, [])

  if (!c) return <div className="center-load"><div className="spinner" /></div>
  const copiar = () => {
    navigator.clipboard?.writeText(c.cron_line).then(() => toast('Comando copiado'), () => toast('No se pudo copiar', 'err'))
  }
  const hace = (s) => s == null ? 'nunca' : s < 90 ? 'hace ' + s + ' s' : 'hace ' + Math.round(s / 60) + ' min'

  return (
    <>
    <div className="cfg-head"><h2>Tareas programadas</h2></div>
    <div className="card em-card">
      <h2>Estado del planificador</h2>
      <p className="em-desc">
        El correo entrante, las automatizaciones y el cierre automático dependen de que el servidor
        ejecute esta línea <b>cada minuto</b>.
      </p>

      <div className={`cron-state ${c.alive ? 'ok' : 'bad'}`}>
        <Icon.clock />
        <span>{c.alive
          ? <>Funcionando · última ejecución {hace(c.seconds_ago)}</>
          : <><b>No se está ejecutando.</b> {c.last_run ? <>Última señal {hace(c.seconds_ago)}.</> : 'Nunca ha corrido.'} Añade la línea de abajo al cron del servidor.</>}</span>
      </div>

      <label className="field" style={{ marginTop: 12 }}><span className="lbl">Línea para el cron</span>
        <input value={c.cron_line} readOnly onFocus={(e) => e.target.select()} /></label>
      <button className="btn ghost sm" onClick={copiar}><Icon.copy /> Copiar</button>

      <div className="card" style={{ padding: 0, overflow: 'hidden', marginTop: 14 }}>
        <table className="tk-table">
          <thead><tr><th>Tarea</th><th>Cada</th><th>Última vez</th></tr></thead>
          <tbody>
            {c.tasks.map((t, i) => (
              <tr key={i}>
                <td>{t.name} {t.off && <span className="chip cerrado sm">desactivada</span>}</td>
                <td>{t.schedule}</td>
                <td className="muted">{t.last ? fmtFecha(t.last) : '—'}</td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </div>
    </>
  )
}

const fmtFecha = (s) => s ? new Date(s.replace(' ', 'T')).toLocaleString('es-ES', { dateStyle: 'short', timeStyle: 'short' }) : '—'

/* ------------------------------- Prioridades -------------------------------
 * Antes eran una lista fija en el código; ahora se configuran. La CLAVE de cada
 * una es lo que se guarda en el ticket, así que se genera del nombre al crearla
 * y ya no se toca (cambiarla dejaría huérfanos los tickets que la usan).
 * ------------------------------------------------------------------------- */
function Priorities() {
  const toast = useToast()
  const confirm = useConfirm()
  const [rows, setRows] = useState(null)
  const [form, setForm] = useState(null)

  const load = useCallback(() => { api.listPriorities().then((d) => setRows(d.priorities || [])) }, [])
  useEffect(() => { load() }, [load])

  const blank = { id: 0, name: '', color: '#64748b', position: (rows?.length || 0) + 1, active: true, is_default: false }

  const save = async () => {
    if (!form.name.trim()) { toast('El nombre es obligatorio', 'err'); return }
    const r = await api.savePriority(form)
    if (r.ok) { toast(form.id ? 'Prioridad actualizada' : 'Prioridad creada'); setForm(null); load() }
    else toast(r.error || 'Error', 'err')
  }
  const del = async (p) => {
    if (!(await confirm({ title: 'Eliminar prioridad', message: `¿Eliminar «${p.name}»?`, danger: true, confirmText: 'Eliminar' }))) return
    const r = await api.deletePriority(p.id)
    if (r.ok) { toast('Prioridad eliminada'); load() } else toast(r.error || 'Error', 'err')
  }

  return (
    <>
      <div className="cfg-head">
        <h2>Prioridades del ticket</h2>
        <button className="btn" onClick={() => setForm({ ...blank })}><Icon.plus /> Nueva prioridad</button>
      </div>
      <p className="cfg-hint" style={{ margin: '0 0 14px', color: 'var(--ink-2)', fontSize: 13 }}>
        Cada prioridad tiene su color y orden. La marcada <b>por defecto</b> es la que se pone a los tickets nuevos.
        Una prioridad en uso no se puede borrar: desactívala y dejará de ofrecerse, sin tocar los tickets que ya la tienen.
      </p>

      {rows === null ? <div className="center-load"><div className="spinner" /></div> : (
        <div className="cfg-grid">
          {rows.map((p) => (
            <div key={p.id} className="card cfg-card">
              <div className="cfg-card-h">
                <span className="chip" style={{ background: p.color + '22', color: p.color }}>{p.name}</span>
                {Number(p.is_default) === 1 && <span className="pill sm">Por defecto</span>}
                <span className={`chip ${Number(p.active) ? 'abierto' : 'cerrado'} sm`}>{Number(p.active) ? 'Activa' : 'Inactiva'}</span>
              </div>
              <p className="cfg-desc">
                {p.tickets} ticket{p.tickets === 1 ? '' : 's'} la usan
                <span className="muted" style={{ marginLeft: 8, fontFamily: 'var(--mono, monospace)', fontSize: 11.5 }}>{p.key}</span>
              </p>
              <div className="cfg-actions">
                <span className="muted" style={{ fontSize: 12, marginRight: 'auto' }}>#{p.position}</span>
                <button className="icon-btn" title="Editar" onClick={() => setForm({
                  id: p.id, name: p.name, color: p.color, position: p.position,
                  active: !!Number(p.active), is_default: !!Number(p.is_default),
                })}><Icon.pencil /></button>
                <button className="icon-btn" title={p.tickets ? 'En uso: desactívala en su lugar' : 'Eliminar'}
                  style={{ color: 'var(--danger)', opacity: p.tickets ? 0.4 : 1 }} onClick={() => del(p)}><Icon.trash /></button>
              </div>
            </div>
          ))}
        </div>
      )}

      {form && (
        <Modal title={form.id ? 'Editar prioridad' : 'Nueva prioridad'} onClose={() => setForm(null)} onSave={save} saveLabel={form.id ? 'Actualizar' : 'Crear'}>
          <div className="grid2">
            <label className="field"><span className="lbl">Nombre <em>*</em></span>
              <input value={form.name} onChange={(e) => setForm((f) => ({ ...f, name: e.target.value }))} placeholder="p. ej. Crítica" autoFocus /></label>
            <label className="field" style={{ maxWidth: 120 }}><span className="lbl">Orden</span>
              <input type="number" min="0" value={form.position} onChange={(e) => setForm((f) => ({ ...f, position: e.target.value }))} /></label>
          </div>
          <div className="field"><span className="lbl">Color</span>
            <Select block value={form.color} onChange={(color) => setForm((f) => ({ ...f, color }))}
              options={COLORS.map((c) => ({ ...c, color: c.value }))} /></div>
          <label className="fb-req-row">
            <span className="fb-switch"><input type="checkbox" checked={form.active} onChange={(e) => setForm((f) => ({ ...f, active: e.target.checked }))} /><span className={`fb-toggle ${form.active ? 'on' : ''}`} /></span>
            <span className="fb-req-label">Activa <span className="hint">· si no, deja de ofrecerse</span></span>
          </label>
          <label className="fb-req-row">
            <span className="fb-switch"><input type="checkbox" checked={form.is_default} onChange={(e) => setForm((f) => ({ ...f, is_default: e.target.checked }))} /><span className={`fb-toggle ${form.is_default ? 'on' : ''}`} /></span>
            <span className="fb-req-label">Por defecto <span className="hint">· la de los tickets nuevos</span></span>
          </label>
        </Modal>
      )}
    </>
  )
}

/* -------------------------- Reglas automáticas ---------------------------
 * «Si el asunto o el cuerpo contiene X → asigna a Fulano, categoría Y, prioridad Z».
 * Se evalúan al CREARSE el ticket, por orden. Equivale al flujo de trabajo de osTicket.
 * ------------------------------------------------------------------------- */
function TicketRules() {
  const toast = useToast()
  const confirm = useConfirm()
  const [d, setD] = useState(null)      // { rules, fields, ops, channels, priorities, categories, agents }
  const [form, setForm] = useState(null)

  const load = useCallback(() => { api.listTicketRules().then(setD) }, [])
  useEffect(() => { load() }, [load])

  const blank = {
    id: 0, name: '', active: true, position: (d?.rules?.length || 0) + 1, channel: 'any', match: 'any',
    conditions: [{ field: 'subject', op: 'contains', value: '' }],
    actions: { assign_to: '', category_id: '', priority: '' }, stop: false,
  }

  const save = async () => {
    const r = await api.saveTicketRule(form)
    if (r.ok) { toast(form.id ? 'Regla actualizada' : 'Regla creada'); setForm(null); load() }
    else toast(r.error || 'Error', 'err')
  }
  const del = async (x) => {
    if (!(await confirm({ title: 'Eliminar regla', message: `¿Eliminar «${x.name}»?`, danger: true, confirmText: 'Eliminar' }))) return
    const r = await api.deleteTicketRule(x.id)
    if (r.ok) { toast('Regla eliminada'); load() } else toast(r.error || 'Error', 'err')
  }
  const toggle = async (x) => {
    const r = await api.saveTicketRule({ ...x, active: !Number(x.active) })
    if (r.ok) load(); else toast(r.error || 'Error', 'err')
  }

  // --- helpers del formulario ---
  const setCond = (i, k, v) => setForm((f) => {
    const c = [...f.conditions]; c[i] = { ...c[i], [k]: v }; return { ...f, conditions: c }
  })
  const addCond = () => setForm((f) => ({ ...f, conditions: [...f.conditions, { field: 'subject', op: 'contains', value: '' }] }))
  const delCond = (i) => setForm((f) => ({ ...f, conditions: f.conditions.filter((_, j) => j !== i) }))
  const setAct = (k, v) => setForm((f) => ({ ...f, actions: { ...f.actions, [k]: v } }))

  // Resumen legible de lo que hace una regla, para la tarjeta.
  const resumen = (x) => {
    const a = x.actions || {}
    const out = []
    if (a.assign_to) out.push('asigna a ' + (d.agents.find((g) => g.id === Number(a.assign_to))?.name || '—'))
    if (a.category_id) out.push('categoría ' + (d.categories.find((c) => c.id === Number(a.category_id))?.name || '—'))
    if (a.priority) out.push('prioridad ' + (d.priorities[a.priority] || a.priority))
    return out.join(' · ') || 'sin acciones'
  }

  if (!d) return <div className="center-load"><div className="spinner" /></div>

  return (
    <>
      <div className="cfg-head">
        <h2>Reglas automáticas</h2>
        <button className="btn" onClick={() => setForm({ ...blank })}><Icon.plus /> Nueva regla</button>
      </div>
      <p className="cfg-hint" style={{ margin: '0 0 14px', color: 'var(--ink-2)', fontSize: 13 }}>
        Se aplican <b>al crearse</b> el ticket, en orden. Sirven para repartir el trabajo solo:
        «si el asunto contiene <i>factura</i> → asígnaselo a quien lleva facturación».
      </p>

      {d.rules.length === 0 ? <div className="tk-empty"><p>No hay reglas todavía.</p></div> : (
        <div className="cfg-grid">
          {d.rules.map((x) => (
            <div key={x.id} className="card cfg-card">
              <div className="cfg-card-h">
                <b>{x.name}</b>
                <span className={`chip ${Number(x.active) ? 'abierto' : 'cerrado'} sm`}>{Number(x.active) ? 'Activa' : 'Inactiva'}</span>
              </div>
              <p className="cfg-desc">
                {(x.conditions || []).length} condicion{(x.conditions || []).length === 1 ? '' : 'es'}
                {' · '}{x.match === 'all' ? 'todas' : 'cualquiera'}
                {' · '}canal {d.channels[x.channel] || x.channel}
              </p>
              <p className="cfg-desc" style={{ opacity: 0.8 }}><b>Hace:</b> {resumen(x)}</p>
              <div className="cfg-actions">
                <span className="muted" style={{ fontSize: 12, marginRight: 'auto' }}>#{x.position}</span>
                <button className="btn ghost sm" onClick={() => toggle(x)}>{Number(x.active) ? 'Desactivar' : 'Activar'}</button>
                <button className="icon-btn" title="Editar" onClick={() => setForm({
                  ...x, active: !!Number(x.active), stop: !!Number(x.stop),
                  conditions: x.conditions?.length ? x.conditions : [{ field: 'subject', op: 'contains', value: '' }],
                  actions: { assign_to: x.actions?.assign_to || '', category_id: x.actions?.category_id || '', priority: x.actions?.priority || '' },
                })}><Icon.pencil /></button>
                <button className="icon-btn" title="Eliminar" style={{ color: 'var(--danger)' }} onClick={() => del(x)}><Icon.trash /></button>
              </div>
            </div>
          ))}
        </div>
      )}

      {form && (
        <Modal width={760} title={form.id ? 'Editar regla' : 'Nueva regla'} onClose={() => setForm(null)} onSave={save} saveLabel={form.id ? 'Actualizar' : 'Crear'}>
          <label className="field"><span className="lbl">Nombre <em>*</em></span>
            <input value={form.name} onChange={(e) => setForm((f) => ({ ...f, name: e.target.value }))} placeholder="p. ej. Asignación automática de facturación" autoFocus /></label>

          {/* --- 1) Cuándo se aplica --- */}
          <section className="rl-sec">
            <h4>Cuándo se aplica</h4>
            <div className="rl-grid3">
              <div className="field"><span className="lbl">Canal</span>
                <Select block value={form.channel} onChange={(v) => setForm((f) => ({ ...f, channel: v }))}
                  options={Object.entries(d.channels).map(([value, label]) => ({ value, label }))} /></div>
              <div className="field"><span className="lbl">Se cumple si…</span>
                <Select block value={form.match} onChange={(v) => setForm((f) => ({ ...f, match: v }))}
                  options={[{ value: 'any', label: 'Cualquiera de las condiciones' }, { value: 'all', label: 'Todas las condiciones' }]} /></div>
              <label className="field"><span className="lbl">Orden</span>
                <input type="number" min="0" value={form.position} onChange={(e) => setForm((f) => ({ ...f, position: e.target.value }))} /></label>
            </div>

            <div className="rl-conds">
              <div className="rl-cond rl-cond-head"><span>Campo</span><span>Condición</span><span>Valor</span><span /></div>
              {form.conditions.map((c, i) => (
                <div key={i} className="rl-cond">
                  <Select block value={c.field} onChange={(v) => setCond(i, 'field', v)}
                    options={Object.entries(d.fields).map(([value, label]) => ({ value, label }))} />
                  <Select block value={c.op} onChange={(v) => setCond(i, 'op', v)}
                    options={Object.entries(d.ops).map(([value, label]) => ({ value, label }))} />
                  <input value={c.value} onChange={(e) => setCond(i, 'value', e.target.value)} placeholder="factura" />
                  <button className="icon-btn" title="Quitar condición" style={{ color: 'var(--danger)' }}
                    onClick={() => delCond(i)} disabled={form.conditions.length === 1}><Icon.trash /></button>
                </div>
              ))}
            </div>
            <button className="btn ghost sm rl-add" onClick={addCond}><Icon.plus /> Añadir condición</button>
          </section>

          {/* --- 2) Qué hace --- */}
          <section className="rl-sec">
            <h4>Qué hace <span className="hint">· al menos una</span></h4>
            <div className="rl-grid3">
              <div className="field"><span className="lbl">Asignar a</span>
                <Select block value={form.actions.assign_to} onChange={(v) => setAct('assign_to', v)}
                  options={[{ value: '', label: 'No cambiar' }, ...d.agents.map((a) => ({ value: a.id, label: a.name || a.email }))]} /></div>
              <div className="field"><span className="lbl">Categoría</span>
                <Select block value={form.actions.category_id} onChange={(v) => setAct('category_id', v)}
                  options={[{ value: '', label: 'No cambiar' }, ...d.categories.map((c) => ({ value: c.id, label: c.name }))]} /></div>
              <div className="field"><span className="lbl">Prioridad</span>
                <Select block value={form.actions.priority} onChange={(v) => setAct('priority', v)}
                  options={[{ value: '', label: 'No cambiar' }, ...Object.entries(d.priorities).map(([value, label]) => ({ value, label }))]} /></div>
            </div>
          </section>

          {/* --- 3) Opciones --- */}
          <section className="rl-sec rl-opts">
            <label className="fb-req-row">
              <span className="fb-switch"><input type="checkbox" checked={form.active} onChange={(e) => setForm((f) => ({ ...f, active: e.target.checked }))} /><span className={`fb-toggle ${form.active ? 'on' : ''}`} /></span>
              <span className="fb-req-label">Activa <span className="hint">· si no, la regla no se evalúa</span></span>
            </label>
            <label className="fb-req-row">
              <span className="fb-switch"><input type="checkbox" checked={form.stop} onChange={(e) => setForm((f) => ({ ...f, stop: e.target.checked }))} /><span className={`fb-toggle ${form.stop ? 'on' : ''}`} /></span>
              <span className="fb-req-label">Si casa, no evaluar más reglas</span>
            </label>
          </section>
        </Modal>
      )}
    </>
  )
}

/* -------------------------------- Modal común -------------------------------- */

function Modal({ title, children, onClose, onSave, saveLabel, width = 560 }) {
  useEffect(() => {
    const h = (e) => e.key === 'Escape' && onClose()
    document.addEventListener('keydown', h)
    return () => document.removeEventListener('keydown', h)
  }, [onClose])
  return (
    <div className="modal-bg" onClick={(e) => e.target.classList.contains('modal-bg') && onClose()}>
      <div className="modal" style={{ maxWidth: width }}>
        <div className="modal-h"><h3>{title}</h3><button className="icon-btn" onClick={onClose}>✕</button></div>
        <div className="modal-body">{children}</div>
        <div className="modal-foot">
          <button className="btn ghost" onClick={onClose}>Cancelar</button>
          <button className="btn" onClick={onSave}>{saveLabel}</button>
        </div>
      </div>
    </div>
  )
}

/* ------------------------------ Canal correo ------------------------------ */
function EmailChannel() {
  const toast = useToast()
  const [f, setF] = useState(null)
  const [saving, setSaving] = useState(false)
  const [testing, setTesting] = useState(false)
  const [result, setResult] = useState(null) // { imap, smtp }

  useEffect(() => {
    api.getEmailAccount().then((d) => {
      const a = d.account || {}
      setF({
        email: a.email || '', from_name: a.from_name || '', active: a.active !== false,
        imap_host: a.imap_host || '', imap_port: a.imap_port || 993, imap_encryption: a.imap_encryption || 'ssl', imap_user: a.imap_user || '', imap_password: '',
        smtp_host: a.smtp_host || '', smtp_port: a.smtp_port || 465, smtp_encryption: a.smtp_encryption || 'ssl', smtp_user: a.smtp_user || '', smtp_password: '',
        has_imap_password: !!a.has_imap_password, has_smtp_password: !!a.has_smtp_password,
        footer_active: !!d.footer?.active, footer_html: d.footer?.html || '',
      })
    })
  }, [])

  if (!f) return <div className="center-load"><div className="spinner" /></div>
  const set = (k) => (e) => setF((s) => ({ ...s, [k]: e.target.value }))
  const encOpts = [{ value: 'ssl', label: 'SSL/TLS' }, { value: 'tls', label: 'STARTTLS' }, { value: 'none', label: 'Ninguno' }]

  const save = async () => {
    setSaving(true)
    const r = await api.saveEmailAccount(f)
    setSaving(false)
    if (r.ok) { toast('Configuración de correo guardada'); setF((s) => ({ ...s, has_imap_password: s.imap_password ? true : s.has_imap_password, has_smtp_password: s.smtp_password ? true : s.has_smtp_password, imap_password: '', smtp_password: '' })) }
    else toast(r.error || 'Error al guardar', 'err')
  }
  const test = async () => {
    setTesting(true); setResult(null)
    const r = await api.testEmailAccount(f)
    setTesting(false)
    if (r.ok) setResult({ imap: r.imap, smtp: r.smtp }); else toast(r.error || 'Error', 'err')
  }
  const badge = (res) => !res ? null
    : res.ok ? <span className="pill ok sm"><span className="dot" />Conecta</span>
    : <span className="pill err sm" title={res.error}><span className="dot" />{(res.error || 'Error').slice(0, 44)}</span>

  const server = (p, label, desc) => (
    <div className="card">
      <h2>{label}</h2>
      <p className="desc">{desc}</p>
      <div className="grid2">
        <label className="field"><span className="lbl">Host</span><input className="mono" value={f[`${p}_host`]} onChange={set(`${p}_host`)} placeholder={`${p}.tudominio.com`} /></label>
        <div className="grid2">
          <label className="field"><span className="lbl">Puerto</span><input className="mono" value={f[`${p}_port`]} onChange={set(`${p}_port`)} /></label>
          <div className="field"><span className="lbl">Cifrado</span><Select block value={f[`${p}_encryption`]} onChange={(v) => setF((s) => ({ ...s, [`${p}_encryption`]: v }))} options={encOpts} /></div>
        </div>
      </div>
      <div className="grid2">
        <label className="field"><span className="lbl">Usuario</span><input className="mono" value={f[`${p}_user`]} onChange={set(`${p}_user`)} placeholder="soporte@tudominio.com" /></label>
        <label className="field"><span className="lbl">Contraseña {f[`has_${p}_password`] && <span className="hint">(guardada · vacío = no cambiar)</span>}</span><input type="password" value={f[`${p}_password`]} onChange={set(`${p}_password`)} placeholder={f[`has_${p}_password`] ? '••••••••' : ''} /></label>
      </div>
    </div>
  )

  return (
    <>
      <div className="card">
        <h2>Buzón de soporte</h2>
        <p className="desc">Los correos que lleguen a esta dirección se convierten en <b>tickets</b>, y las respuestas salen desde ella. La contraseña se guarda <b>cifrada</b>.</p>
        <div className="grid2">
          <label className="field"><span className="lbl">Dirección de correo</span><input type="email" value={f.email} onChange={set('email')} placeholder="soporte@tudominio.com" /></label>
          <label className="field"><span className="lbl">Nombre visible <span className="hint">(el «De:»)</span></span><input value={f.from_name} onChange={set('from_name')} placeholder="Soporte" /></label>
        </div>
        <label className="fb-req-row" style={{ marginTop: 4 }}>
          <span className="fb-switch"><input type="checkbox" checked={f.active} onChange={(e) => setF((s) => ({ ...s, active: e.target.checked }))} /><span className={`fb-toggle ${f.active ? 'on' : ''}`} /></span>
          <span className="fb-req-label">Canal de correo activo</span>
        </label>
      </div>

      {server('imap', 'Entrante (IMAP)', 'Desde aquí se leen los correos que entran para convertirlos en tickets.')}
      {server('smtp', 'Saliente (SMTP)', 'Por aquí salen las respuestas de los tickets.')}

      <div style={{ display: 'flex', gap: 11, alignItems: 'center', flexWrap: 'wrap' }}>
        <button className="btn" disabled={saving} onClick={save}><Icon.save /> {saving ? 'Guardando…' : 'Guardar cambios'}</button>
        <button className="btn ghost" disabled={testing} onClick={test}>{testing ? 'Probando…' : 'Probar conexión'}</button>
        {result && <span className="em-test">IMAP: {badge(result.imap)} &nbsp; SMTP: {badge(result.smtp)}</span>}
      </div>

      {/* Pie que se añade a TODOS los correos que salen (respuestas y avisos). */}
      <div className="card em-card" style={{ marginTop: 18 }}>
        <h2>Pie de los correos</h2>
        <p className="em-desc">
          Se añade al final de cada correo que sale (respuestas de tickets y avisos automáticos),
          separado por una línea. <b>No se guarda en el hilo del ticket</b>: la conversación interna
          queda limpia, sin la firma repetida en cada mensaje.
        </p>
        <label className="field"><span className="lbl">Contenido</span>
          <textarea rows={5} value={f.footer_html} onChange={set('footer_html')}
            placeholder="Aeme Group S.L · 96 000 00 00 · soporte@etiquetaselectronicas.com" /></label>
        <p className="ct-hint">Admite formato básico (negrita, enlaces, listas). Ejemplo: <code>&lt;b&gt;Aeme Group&lt;/b&gt; · &lt;a href="https://aemegroup.com"&gt;aemegroup.com&lt;/a&gt;</code></p>
        <label className="fb-req-row" style={{ marginTop: 6 }}>
          <span className="fb-switch"><input type="checkbox" checked={!!f.footer_active}
            onChange={(e) => setF((s) => ({ ...s, footer_active: e.target.checked }))} /><span className={`fb-toggle ${f.footer_active ? 'on' : ''}`} /></span>
          <span className="fb-req-label">{f.footer_active ? 'Activo · se añade a los correos' : 'Inactivo · no se añade nada'}</span>
        </label>
        <p className="ct-hint">Se guarda con el botón «Guardar cambios» de arriba.</p>
      </div>

      <MailDiagnostic from={f.email} />
    </>
  )
}

/* ---------------------------- Diagnóstico de correo ----------------------------
 * «Probar conexión» solo dice si el SMTP acepta la contraseña. Esto manda un correo
 * DE VERDAD para confirmar que sale y llega (equivale al diagnóstico de osTicket).
 * ------------------------------------------------------------------------- */
function MailDiagnostic({ from }) {
  const toast = useToast()
  const [f, setF] = useState({ to: '', subject: 'Correo de prueba del helpdesk', body: '' })
  const [sending, setSending] = useState(false)
  const [res, setRes] = useState(null)
  const set = (k) => (e) => setF((s) => ({ ...s, [k]: e.target.value }))

  const send = async () => {
    if (!f.to.trim()) { toast('Indica a quién enviarlo', 'err'); return }
    setSending(true); setRes(null)
    const r = await api.sendTestEmail(f)
    setSending(false); setRes(r)
    if (r.ok) toast(`Correo enviado a ${r.to}`)
    else toast(r.error || 'No se pudo enviar', 'err')
  }

  return (
    <div className="card em-card" style={{ marginTop: 18 }}>
      <h2>Diagnóstico</h2>
      <p className="em-desc">Envía un correo real para comprobar que la salida funciona de punta a punta.</p>

      <div className="grid2">
        <label className="field"><span className="lbl">De</span>
          <input value={from || '(sin buzón configurado)'} disabled /></label>
        <label className="field"><span className="lbl">Para <em>*</em></span>
          <input type="email" value={f.to} onChange={set('to')} placeholder="tu-correo@dominio.com" /></label>
      </div>
      <label className="field"><span className="lbl">Asunto</span>
        <input value={f.subject} onChange={set('subject')} /></label>
      <label className="field"><span className="lbl">Mensaje</span>
        <textarea rows={4} value={f.body} onChange={set('body')} placeholder="Si lo dejas vacío se envía un texto de prueba." /></label>

      <div style={{ display: 'flex', gap: 11, alignItems: 'center', flexWrap: 'wrap' }}>
        <button className="btn ghost" disabled={sending} onClick={send}>
          <Icon.send /> {sending ? 'Enviando…' : 'Enviar correo de prueba'}
        </button>
        {res && <span className="em-test">{res.ok
          ? <span className="pill ok">Enviado a {res.to}</span>
          : <span className="pill err">{res.error}</span>}</span>}
      </div>
    </div>
  )
}
