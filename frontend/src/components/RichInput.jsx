import { useRef, useState, useImperativeHandle, forwardRef, useEffect } from 'react'
import { Icon } from '../icons.jsx'
import { api } from '../api.js'

/* ---------------------------------------------------------------------------
 * RichInput — editor con formato + adjuntos. Se usa en dos sitios:
 *   · el composer de respuestas del ticket
 *   · la descripción del formulario de «Nuevo ticket»
 *
 * El HTML que produce se SANEA EN EL SERVIDOR (App\Services\HtmlSanitizer) antes
 * de guardarse. Nunca se confía en el HTML que llega del navegador.
 * ------------------------------------------------------------------------- */

const escaparHtml = (s) => String(s).replace(/[&<>"']/g, (c) =>
  ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c])

/* Sustituye {{cliente}}, {{codigo}}, {{agente}}, {{sede}} por los datos del ticket
   al insertar una respuesta predefinida. Una variable sin dato se queda vacía. */
const sustituirVars = (texto, vars) => {
  if (!vars) return texto
  return String(texto || '').replace(/\{\{\s*(cliente|codigo|agente|sede)\s*\}\}/gi,
    (_, k) => vars[k.toLowerCase()] ?? '')
}

/**
 * Deja la dirección lista para usarse, o null si no vale.
 *
 * Se aceptan http, https y mailto. Cualquier otro esquema se RECHAZA: un enlace
 * `javascript:` en un correo que luego abre un agente es un agujero de seguridad,
 * y no vale confiar en que ya lo limpie el servidor.
 * Lo que no trae esquema («aemegroup.com») se asume https, que es lo que la gente
 * espera al pegar una dirección.
 */
function normalizarUrl(v) {
  const s = String(v || '').trim()
  if (!s) return null

  if (/^(https?:\/\/|mailto:)/i.test(s)) {
    return /\s/.test(s) ? null : s
  }
  // Con esquema pero no permitido (javascript:, data:, file:…)
  if (/^[a-z][a-z0-9+.-]*:/i.test(s)) return null

  // Un correo suelto es un enlace de correo, no una página web.
  if (/^[^\s@]+@[^\s@/]+\.[^\s@/]{2,}$/.test(s)) return 'mailto:' + s

  if (!/^[^\s/]+\.[^\s/]{2,}/.test(s)) return null   // ni siquiera parece un dominio
  return 'https://' + s
}

const TOOLS = [
  { cmd: 'bold', icon: 'B', title: 'Negrita (Ctrl+B)', style: { fontWeight: 800 } },
  { cmd: 'italic', icon: 'I', title: 'Cursiva (Ctrl+I)', style: { fontStyle: 'italic' } },
  { cmd: 'underline', icon: 'U', title: 'Subrayado (Ctrl+U)', style: { textDecoration: 'underline' } },
  { cmd: 'strikeThrough', icon: 'S', title: 'Tachado', style: { textDecoration: 'line-through' } },
  { sep: true },
  { cmd: 'insertUnorderedList', icon: '•', title: 'Lista' },
  { cmd: 'insertOrderedList', icon: '1.', title: 'Lista numerada' },
  { cmd: 'formatBlock', arg: 'blockquote', ico: 'quote', title: 'Cita' },
  { sep: true },
  { cmd: 'justifyLeft', ico: 'alignLeft', title: 'Alinear a la izquierda' },
  { cmd: 'justifyCenter', ico: 'alignCenter', title: 'Centrar' },
  { cmd: 'justifyRight', ico: 'alignRight', title: 'Alinear a la derecha' },
  { sep: true },
  { cmd: 'removeFormat', ico: 'eraser', title: 'Quitar formato' },
]

const RichInput = forwardRef(function RichInput({
  placeholder = 'Escribe aquí…',
  minHeight = 110,
  disabled = false,
  canned = false,          // habilita el menú «/» de respuestas predefinidas
  cannedVars = null,       // { cliente, codigo, agente, sede } para sustituir {{...}} al insertar
  mentionUsers = null,     // [{id,name}] → habilita el menú «@» de menciones (notas internas)
  onChange,
}, ref) {
  const area = useRef(null)
  const fileRef = useRef(null)
  const imgRef = useRef(null)
  const [files, setFiles] = useState([])
  const [empty, setEmpty] = useState(true)
  const [imgTool, setImgTool] = useState(null) // { el, top, left } al clicar una imagen

  // Menú «/» de respuestas predefinidas
  const [cannedList, setCannedList] = useState([])
  const [slash, setSlash] = useState(null)   // { query, index } o null
  const [at, setAt] = useState(null)         // menú de menciones «@»: { query, index } o null
  const [enlace, setEnlace] = useState(null) // diálogo de enlace (ver abrirEnlace)

  useEffect(() => { if (canned) api.cannedForComposer().then((d) => setCannedList(d.canned || [])) }, [canned])

  useImperativeHandle(ref, () => ({
    getHtml: () => area.current?.innerHTML || '',
    getFiles: () => files.map((f) => f.file),
    // Ids mencionados: se leen del DOM (los chips @Nombre con data-uid), así que si el
    // agente borra una mención, deja de contar. Frescos y exactos al enviar.
    getMentions: () => [...(area.current?.querySelectorAll('.mention[data-uid]') || [])]
      .map((el) => Number(el.dataset.uid)).filter(Boolean),
    // Se lee del DOM (fresco): cuenta el texto Y las imágenes en línea, sin depender del estado.
    isEmpty: () => !area.current?.textContent.trim() && !area.current?.querySelector('img') && files.length === 0,
    reset: () => { if (area.current) area.current.innerHTML = ''; setFiles([]); setEmpty(true) },
    // Vuelca texto plano en el editor (p. ej. el borrador de la IA). Respeta saltos
    // de línea y párrafos, y deja el cursor listo para que el agente lo revise.
    setText: (txt) => {
      if (!area.current) return
      const html = String(txt || '').split(/\n{2,}/)
        .map((p) => `<p>${escaparHtml(p).replace(/\n/g, '<br>')}</p>`).join('')
      area.current.innerHTML = html
      setEmpty(!String(txt || '').trim())
      onChange?.()
      area.current.focus()
    },
    // Vuelca HTML tal cual (p. ej. una respuesta efectiva guardada, con su formato).
    setHtml: (html) => {
      if (!area.current) return
      area.current.innerHTML = String(html || '')
      setEmpty(!area.current.textContent.trim() && !area.current.querySelector('img'))
      onChange?.()
      area.current.focus()
    },
  }))

  const exec = (cmd, arg) => { document.execCommand(cmd, false, arg ?? null); area.current?.focus(); setEmpty(!area.current?.textContent.trim()); onChange?.() }

  /*
   * ENLACES. Antes se pedía la dirección con el `prompt` del navegador: feo, fuera
   * del estilo de la aplicación, sin validar nada y sin poder poner el texto del
   * enlace. Ahora abre un diálogo propio.
   *
   * Detalle importante: al escribir en el diálogo, el editor PIERDE la selección,
   * así que hay que guardar el rango al abrirlo y devolverlo antes de insertar.
   */
  const abrirEnlace = () => {
    const sel = window.getSelection()
    const rango = sel && sel.rangeCount && area.current?.contains(sel.anchorNode)
      ? sel.getRangeAt(0).cloneRange()
      : null
    const elegido = (sel?.toString() || '').trim()

    setEnlace({ url: '', texto: elegido, rango, habiaSeleccion: !!elegido, error: '' })
  }

  const ponerEnlace = () => {
    const { url, texto, rango, habiaSeleccion } = enlace
    const limpia = normalizarUrl(url)
    if (!limpia) { setEnlace((s) => ({ ...s, error: 'Escribe una dirección válida' })); return }

    area.current?.focus()
    if (rango) {
      const s = window.getSelection()
      s.removeAllRanges()
      s.addRange(rango)
    }

    if (habiaSeleccion) {
      document.execCommand('createLink', false, limpia)
    } else {
      const visible = (texto || '').trim() || limpia
      document.execCommand('insertHTML', false,
        `<a href="${escaparHtml(limpia)}" target="_blank" rel="noopener noreferrer">${escaparHtml(visible)}</a>`)
    }

    setEnlace(null)
    setEmpty(!area.current?.textContent.trim())
    onChange?.()
  }

  const addFiles = (list) => {
    const arr = [...list].map((f) => ({
      file: f, name: f.name, size: f.size,
      preview: f.type.startsWith('image/') ? URL.createObjectURL(f) : null,
    }))
    setFiles((s) => [...s, ...arr])
  }

  /** Sube una imagen y la inserta EN LÍNEA (en el cursor) con su URL firmada. */
  const insertImage = async (file) => {
    if (!file?.type?.startsWith('image/')) return
    const r = await api.uploadInlineImage(file)
    if (!r?.ok) { window.alert(r?.error || 'No se pudo subir la imagen'); return }
    area.current?.focus()
    document.execCommand('insertHTML', false, `<img src="${r.url}" alt="">`)
    setEmpty(false); onChange?.()
  }

  // ¿el editor está vacío? Cuenta también las imágenes, no solo el texto.
  const isBlank = () => !area.current?.textContent.trim() && !area.current?.querySelector('img')

  /** Al clicar una imagen del editor, muestra el popover de tamaño/borrar junto a ella. */
  const onAreaClick = (e) => {
    if (e.target?.tagName === 'IMG') {
      const r = e.target.getBoundingClientRect()
      setImgTool({ el: e.target, top: r.bottom + 6, left: r.left })
    } else setImgTool(null)
  }
  const setImgSize = (cls) => { if (imgTool?.el) { imgTool.el.className = cls; onChange?.() } }
  const delImg = () => { if (imgTool?.el) { imgTool.el.remove(); setImgTool(null); setEmpty(isBlank()); onChange?.() } }

  /** Ctrl+V: las imágenes se insertan EN LÍNEA; el texto se pega en plano. */
  const onPaste = (e) => {
    const imgs = [...(e.clipboardData?.files || [])].filter((f) => f.type.startsWith('image/'))
    if (imgs.length) { e.preventDefault(); imgs.forEach(insertImage); return }
    e.preventDefault()
    document.execCommand('insertText', false, e.clipboardData.getData('text/plain'))
  }

  const touch = (e) => {
    setEmpty(!e.currentTarget.textContent.trim() && !e.currentTarget.querySelector('img'))
    setImgTool(null) // al escribir se cierra el popover de imagen
    onChange?.()
    if (canned) detectSlash()
    if (mentionUsers?.length) detectAt()
  }

  /* Detecta un «@nombre» a medio escribir para abrir el selector de agentes. */
  const detectAt = () => {
    const sel = window.getSelection()
    if (!sel?.rangeCount) return setAt(null)
    const node = sel.anchorNode
    const text = (node?.textContent || '').slice(0, sel.anchorOffset)
    const m = text.match(/(?:^|\s)@([\p{L}0-9_]*)$/u)
    setAt(m ? { query: m[1].toLowerCase(), index: 0 } : null)
  }

  /*
   * Detecta un «/atajo» al principio de línea (o del texto) para abrir el menú.
   * Solo dispara si el «/» arranca palabra, para no molestar en mitad de una URL.
   */
  const detectSlash = () => {
    const sel = window.getSelection()
    if (!sel?.rangeCount) return setSlash(null)
    const node = sel.anchorNode
    const text = (node?.textContent || '').slice(0, sel.anchorOffset)
    const m = text.match(/(?:^|\s)\/([a-z0-9_]*)$/i)
    setSlash(m ? { query: m[1].toLowerCase(), index: 0 } : null)
  }

  const matches = slash
    ? cannedList.filter((c) => c.shortcut.includes(slash.query) || c.title.toLowerCase().includes(slash.query))
    : []

  const mentionMatches = at
    ? (mentionUsers || []).filter((u) => (u.name || '').toLowerCase().includes(at.query)).slice(0, 8)
    : []

  /** Inserta un chip de mención (@Nombre) no editable, con el id del agente. */
  const pickMention = (u) => {
    const sel = window.getSelection()
    if (sel?.rangeCount) {
      const range = sel.getRangeAt(0)
      const node = range.startContainer
      const before = (node.textContent || '').slice(0, range.startOffset)
      const cut = before.match(/@[\p{L}0-9_]*$/u)   // el «@parcial» tecleado
      if (cut && node.nodeType === Node.TEXT_NODE) {
        range.setStart(node, range.startOffset - cut[0].length)
        range.deleteContents()
      }
      const chip = document.createElement('span')
      chip.className = 'mention'
      chip.dataset.uid = String(u.id)
      chip.contentEditable = 'false'
      chip.textContent = '@' + u.name
      range.insertNode(chip)
      const space = document.createTextNode(' ')   // espacio tras el chip
      chip.after(space)
      range.setStartAfter(space); range.collapse(true)
      sel.removeAllRanges(); sel.addRange(range)
    }
    setAt(null); setEmpty(false); onChange?.(); area.current?.focus()
  }

  /** Sustituye el «/atajo» a medio escribir por el texto de la respuesta. */
  const pickCanned = (c) => {
    const sel = window.getSelection()
    if (sel?.rangeCount) {
      const range = sel.getRangeAt(0)
      const node = range.startContainer
      const before = (node.textContent || '').slice(0, range.startOffset)
      const cut = before.match(/\/[a-z0-9_]*$/i)
      if (cut && node.nodeType === Node.TEXT_NODE) {
        range.setStart(node, range.startOffset - cut[0].length)
        range.deleteContents()
      }
      document.execCommand('insertText', false, sustituirVars(c.body, cannedVars))
    }
    setSlash(null)
    setEmpty(false)
    onChange?.()
    area.current?.focus()
  }

  const kb = (e) => {
    // Menú de menciones «@» (tiene prioridad si está abierto).
    if (at && mentionMatches.length) {
      if (e.key === 'ArrowDown') { e.preventDefault(); setAt((s) => ({ ...s, index: (s.index + 1) % mentionMatches.length })); return }
      if (e.key === 'ArrowUp') { e.preventDefault(); setAt((s) => ({ ...s, index: (s.index - 1 + mentionMatches.length) % mentionMatches.length })); return }
      if (e.key === 'Enter' || e.key === 'Tab') { e.preventDefault(); pickMention(mentionMatches[at.index]); return }
      if (e.key === 'Escape') { e.preventDefault(); setAt(null); return }
    }
    if (!slash || !matches.length) return
    if (e.key === 'ArrowDown') { e.preventDefault(); setSlash((s) => ({ ...s, index: (s.index + 1) % matches.length })) }
    else if (e.key === 'ArrowUp') { e.preventDefault(); setSlash((s) => ({ ...s, index: (s.index - 1 + matches.length) % matches.length })) }
    else if (e.key === 'Enter' || e.key === 'Tab') { e.preventDefault(); pickCanned(matches[slash.index]) }
    else if (e.key === 'Escape') { e.preventDefault(); setSlash(null) }
  }

  return (
    <div className={`cmp ${disabled ? 'off' : ''}`}
      onDrop={(e) => {
        e.preventDefault(); if (disabled) return
        const fs = [...(e.dataTransfer?.files || [])]
        fs.filter((f) => f.type.startsWith('image/')).forEach(insertImage)   // imágenes en línea
        const docs = fs.filter((f) => !f.type.startsWith('image/'))
        if (docs.length) addFiles(docs)                                       // el resto, adjuntos
      }}
      onDragOver={(e) => e.preventDefault()}>

      <div className="cmp-tools">
        {TOOLS.map((t, i) => {
          if (t.sep) return <span key={i} className="cmp-sep" />
          const Ico = t.ico ? Icon[t.ico] : null
          return (
            <button key={i} type="button" className="cmp-t" title={t.title} disabled={disabled} style={t.style}
              onMouseDown={(e) => { e.preventDefault(); exec(t.cmd, t.arg) }}>
              {Ico ? <Ico /> : t.icon}
            </button>
          )
        })}
        <button type="button" className="cmp-t" title="Insertar enlace" disabled={disabled}
          onMouseDown={(e) => { e.preventDefault(); abrirEnlace() }}><Icon.link /></button>
        <span className="cmp-sep" />
        <button type="button" className="cmp-t" title="Insertar imagen (en el texto)" disabled={disabled}
          onClick={() => imgRef.current?.click()}><Icon.image /></button>
        <input ref={imgRef} type="file" accept="image/*" multiple hidden
          onChange={(e) => { [...e.target.files].forEach(insertImage); e.target.value = '' }} />
        <button type="button" className="cmp-t" title="Adjuntar archivo" disabled={disabled}
          onClick={() => fileRef.current?.click()}><Icon.file /></button>
        <input ref={fileRef} type="file" multiple hidden
          onChange={(e) => { addFiles(e.target.files); e.target.value = '' }} />
        <span className="spacer" />
        <span className="cmp-hint">{canned ? 'Escribe / para respuestas rápidas · pega con Ctrl+V' : 'Pega capturas con Ctrl+V o arrastra archivos'}</span>
      </div>

      <div style={{ position: 'relative' }}>
        <div ref={area} className="cmp-area" style={{ minHeight }}
          contentEditable={!disabled} suppressContentEditableWarning
          data-ph={placeholder} onInput={touch} onPaste={onPaste} onClick={onAreaClick}
          onKeyDown={kb} onBlur={() => setTimeout(() => { setSlash(null); setAt(null); setImgTool(null) }, 150)} />

        {/* Menú de respuestas predefinidas al escribir «/» */}
        {slash && canned && (
          <div className="cmp-slash" style={{ bottom: '100%', left: 12, marginBottom: 6 }}>
            {matches.length === 0
              ? <div className="cmp-slash-empty">Sin respuestas para «/{slash.query}»</div>
              : matches.map((c, i) => (
                <button key={c.id} type="button" className={`cmp-slash-i ${i === slash.index ? 'on' : ''}`}
                  onMouseDown={(e) => { e.preventDefault(); pickCanned(c) }}>
                  <span className="s">/{c.shortcut}</span>
                  <span className="t"><b>{c.title}</b><small>{c.body}</small></span>
                </button>
              ))}
          </div>
        )}

        {/* Menú de menciones al escribir «@» (solo en notas internas) */}
        {at && mentionUsers?.length > 0 && (
          <div className="cmp-slash" style={{ bottom: '100%', left: 12, marginBottom: 6 }}>
            {mentionMatches.length === 0
              ? <div className="cmp-slash-empty">Sin agentes para «@{at.query}»</div>
              : mentionMatches.map((u, i) => (
                <button key={u.id} type="button" className={`cmp-slash-i ${i === at.index ? 'on' : ''}`}
                  onMouseDown={(e) => { e.preventDefault(); pickMention(u) }}>
                  <span className="s">@</span>
                  <span className="t"><b>{u.name}</b></span>
                </button>
              ))}
          </div>
        )}
      </div>

      {/* Popover al clicar una imagen: tamaño (100/50/25%) + borrar */}
      {imgTool && (
        <div className="img-tool" style={{ top: imgTool.top, left: imgTool.left }}
          onMouseDown={(e) => e.preventDefault()}>
          {[['sz-100', '100%'], ['sz-50', '50%'], ['sz-25', '25%']].map(([cls, label]) => (
            <button key={cls} type="button" className={`img-tool-b ${imgTool.el?.className === cls ? 'on' : ''}`}
              onClick={() => setImgSize(cls)}>{label}</button>
          ))}
          <span className="img-tool-sep" />
          <button type="button" className="img-tool-b del" title="Quitar imagen" onClick={delImg}><Icon.trash /></button>
        </div>
      )}

      {enlace && (
        <div className="modal-bg" onMouseDown={(e) => e.target.classList.contains('modal-bg') && setEnlace(null)}>
          <div className="modal lnk-dlg" onKeyDown={(e) => {
            if (e.key === 'Escape') setEnlace(null)
            if (e.key === 'Enter') { e.preventDefault(); ponerEnlace() }
          }}>
            <div className="modal-h"><h3><Icon.link /> Insertar enlace</h3>
              <button type="button" className="icon-btn" onClick={() => setEnlace(null)}>✕</button></div>

            <div className="modal-body">
              <label className="field"><span className="lbl">Dirección <em>*</em></span>
                <input autoFocus value={enlace.url} placeholder="aemegroup.com  ·  https://…  ·  correo@dominio.com"
                  onChange={(e) => setEnlace((s) => ({ ...s, url: e.target.value, error: '' }))} /></label>

              {/* Si había texto seleccionado, ese será el enlace: no se pregunta. */}
              {enlace.habiaSeleccion ? (
                <p className="lnk-sel">Se enlazará el texto seleccionado: <b>«{enlace.texto}»</b></p>
              ) : (
                <label className="field"><span className="lbl">Texto que se verá <span className="hint">(opcional)</span></span>
                  <input value={enlace.texto} placeholder="Si lo dejas vacío se verá la dirección"
                    onChange={(e) => setEnlace((s) => ({ ...s, texto: e.target.value }))} /></label>
              )}

              {enlace.error && <p className="lnk-err">{enlace.error}</p>}
            </div>

            <div className="modal-foot">
              <button type="button" className="btn ghost" onClick={() => setEnlace(null)}>Cancelar</button>
              <button type="button" className="btn" onClick={ponerEnlace}>Insertar</button>
            </div>
          </div>
        </div>
      )}

      {files.length > 0 && (
        <div className="cmp-files">
          {files.map((f, i) => (
            <div key={i} className="cmp-file">
              {f.preview ? <img src={f.preview} alt="" /> : <span className="cf-ic"><Icon.file /></span>}
              <span className="cf-tx"><b>{f.name}</b><small>{(f.size / 1024).toFixed(0)} KB</small></span>
              <button type="button" className="cf-x" title="Quitar"
                onClick={() => setFiles((s) => s.filter((_, j) => j !== i))}>✕</button>
            </div>
          ))}
        </div>
      )}
    </div>
  )
})

export default RichInput
