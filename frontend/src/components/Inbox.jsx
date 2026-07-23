import { useState, useEffect, useRef, useCallback } from 'react'
import { api, mediaUrl } from '../api.js'
import { Icon } from '../icons.jsx'
import { initials, avatarBg, relTime, clockTime, dayLabel, parseDate } from '../util.js'
import { useToast, useConfirm } from '../App.jsx'
import TemplatePicker from './TemplatePicker.jsx'
import InteractiveBuilder from './InteractiveBuilder.jsx'
import ChatInfo from './ChatInfo.jsx'
import Select from './Select.jsx'

// Tipos de adjunto del menú 📎 (clip) del composer
const ATTACH = [
  { type: 'image', label: 'Imagen', icon: 'image', accept: 'image/*' },
  { type: 'video', label: 'Vídeo', icon: 'video', accept: 'video/*' },
  { type: 'audio', label: 'Audio', icon: 'play', accept: 'audio/*' },
  { type: 'document', label: 'Documento', icon: 'file', accept: '.pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.txt,.csv,.zip' },
]

function Avatar({ c, size = 'md' }) {
  return (
    <div className={`avatar ${size}`} style={{ background: avatarBg(c?.wa_id || c?.name || '') }}>
      {initials(c)}
    </div>
  )
}

function Ticks({ status }) {
  if (status === 'read') return <span className="tick read">✓✓</span>
  if (status === 'delivered') return <span className="tick">✓✓</span>
  if (status === 'failed') return <span className="tick failed">✕</span>
  return <span className="tick">✓</span>
}

// Vista previa de un mensaje interactivo saliente (botones o lista)
function InteractivePreview({ payload }) {
  let i
  try { i = JSON.parse(payload) } catch { return null }
  if (!i || typeof i !== 'object') return null
  return (
    <div className="ix">
      {i.header?.text && <div className="ix-header">{i.header.text}</div>}
      {i.body?.text && <div className="ix-body">{i.body.text}</div>}
      {i.footer?.text && <div className="ix-footer">{i.footer.text}</div>}
      {i.type === 'button' && (
        <div className="ix-buttons">
          {(i.action?.buttons || []).map((b, k) => <span className="ix-btn" key={k}>{b.reply?.title}</span>)}
        </div>
      )}
      {i.type === 'list' && (
        <div className="ix-list">
          <div className="ix-listbtn"><Icon.list style={{ width: 14, height: 14, fill: 'currentColor' }} /> {i.action?.button || 'Ver opciones'}</div>
          {(i.action?.sections || []).map((s, si) => (
            <div className="ix-sec" key={si}>
              {s.title && <div className="ix-sec-t">{s.title}</div>}
              {(s.rows || []).map((r, ri) => (
                <div className="ix-opt" key={ri}><b>{r.title}</b>{r.description && <span>{r.description}</span>}</div>
              ))}
            </div>
          ))}
        </div>
      )}
    </div>
  )
}

function Bubble({ m }) {
  const isImg = ['image', 'sticker'].includes(m.type) && m.media_url
  const isVideo = m.type === 'video' && m.media_url
  const isAudio = m.type === 'audio' && m.media_url
  const isDoc = m.type === 'document' && m.media_url
  const ix = m.type === 'interactive' && m.payload
  return (
    <div className={`bubble ${m.direction === 'in' ? 'in' : 'out'}`}>
      {m.direction === 'out' && m.sent_by_name && <span className="bubble-by">{m.sent_by_name}</span>}
      {isImg && <img className="media" src={mediaUrl(m.media_url)} loading="lazy" alt="" />}
      {isVideo && <video className="media" controls src={mediaUrl(m.media_url)} />}
      {isAudio && <audio className="media-audio" controls src={mediaUrl(m.media_url)} />}
      {isDoc && (
        <a className="doc" href={mediaUrl(m.media_url)} target="_blank" rel="noreferrer">
          <Icon.file style={{ width: 16, height: 16, fill: 'currentColor' }} /> Abrir documento
        </a>
      )}
      {ix ? <InteractivePreview payload={m.payload} /> : m.body}
      <span className="stamp">{clockTime(m.created_at)}{m.direction === 'out' && <Ticks status={m.status} />}</span>
    </div>
  )
}

const TABS = [{ k: 'all', t: 'Todos' }, { k: 'unread', t: 'No leídos' }, { k: 'read', t: 'Leídos' }]

export default function Inbox({ onUnread, initialContactId, onOpened }) {
  const toast = useToast()
  const confirm = useConfirm()
  const [convs, setConvs] = useState(null)
  const [query, setQuery] = useState('')
  const [tab, setTab] = useState('all')
  const [active, setActive] = useState(null)
  const [detail, setDetail] = useState(null)       // contacto con note + labels
  const [messages, setMessages] = useState([])
  const [loadingMsgs, setLoadingMsgs] = useState(false)
  const [draft, setDraft] = useState('')
  const [sending, setSending] = useState(false)
  const [pickerOpen, setPickerOpen] = useState(false)
  const [showInfo, setShowInfo] = useState(true)
  const [ctx, setCtx] = useState(null) // { x, y, conv }
  const [assignFilter, setAssignFilter] = useState('all') // all | me | none
  const [agents, setAgents] = useState([])
  const [attachMenu, setAttachMenu] = useState(false)
  const [botMenu, setBotMenu] = useState(false)
  const [ibMode, setIbMode] = useState(null)          // 'button' | 'list'
  const [mediaPreview, setMediaPreview] = useState(null) // { file, type, url, caption }

  const fileRef = useRef(null)
  const pendingTypeRef = useRef('document')
  const bodyRef = useRef(null)
  const lastIdRef = useRef(0)
  const activeRef = useRef(null)
  activeRef.current = active
  const assignRef = useRef('all')
  assignRef.current = assignFilter

  useEffect(() => { api.listAgents().then((d) => setAgents(d.agents || [])) }, [])

  const loadConvs = useCallback((q) => {
    api.listConversations(q ?? '', assignRef.current).then((d) => {
      const list = d.conversations || []
      setConvs(list)
      onUnread(list.reduce((s, c) => s + (parseInt(c.unread) || 0), 0))
    })
  }, [onUnread])

  useEffect(() => { loadConvs('') }, [loadConvs])
  useEffect(() => { const t = setInterval(() => loadConvs(query), 8000); return () => clearInterval(t) }, [loadConvs, query])

  // abrir conversación solicitada desde el Dashboard
  useEffect(() => {
    if (!initialContactId || !convs) return
    const c = convs.find((x) => x.id === initialContactId)
    if (c) openChat(c)
    onOpened?.()
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [initialContactId, convs])
  useEffect(() => { const t = setTimeout(() => loadConvs(query), 280); return () => clearTimeout(t) }, [query, loadConvs])
  useEffect(() => { loadConvs(query) }, [assignFilter]) // eslint-disable-line react-hooks/exhaustive-deps

  const assign = async (userId) => {
    if (!active) return
    await api.assignConversation(active.id, userId || 0)
    const a = agents.find((x) => x.id === Number(userId))
    setDetail((d) => ({ ...d, assigned_to: userId || null, assignee_name: a ? (a.name || a.email) : null }))
    setActive((x) => ({ ...x, assigned_to: userId || null, assignee_name: a ? (a.name || a.email) : null }))
    loadConvs(query)
  }

  const scrollDown = () => requestAnimationFrame(() => { if (bodyRef.current) bodyRef.current.scrollTop = bodyRef.current.scrollHeight })

  const openChat = (c) => {
    setActive(c)
    setDetail(null)
    setLoadingMsgs(true)
    setMessages([])
    api.getMessages(c.id).then((d) => {
      const msgs = d.messages || []
      setMessages(msgs)
      setDetail(d.contact || null)
      lastIdRef.current = msgs.length ? Math.max(...msgs.map((m) => +m.id)) : 0
      setLoadingMsgs(false)
      scrollDown()
      setConvs((cs) => cs?.map((x) => (x.id === c.id ? { ...x, unread: 0 } : x)))
      loadConvs(query)
    })
  }

  useEffect(() => {
    if (!active) return
    const t = setInterval(() => {
      const a = activeRef.current
      if (!a) return
      api.pollMessages(a.id, lastIdRef.current).then((d) => {
        const fresh = d.messages || []
        if (!fresh.length) return
        setMessages((prev) => {
          const ids = new Set(prev.map((m) => m.id))
          const add = fresh.filter((m) => !ids.has(m.id))
          const updated = prev.map((m) => { const u = fresh.find((f) => f.id === m.id); return u ? { ...m, status: u.status } : m })
          if (add.length) scrollDown()
          return [...updated, ...add]
        })
        lastIdRef.current = Math.max(lastIdRef.current, ...fresh.map((m) => +m.id))
      })
    }, 4000)
    return () => clearInterval(t)
  }, [active])

  const nowStamp = () => new Date().toISOString().slice(0, 19).replace('T', ' ')

  // Añade un mensaje saliente al hilo usando su id real, y adelanta lastIdRef
  // para que el polling no lo vuelva a insertar (evita duplicados).
  const pushOut = (msg) => {
    setMessages((m) => [...m, msg])
    if (typeof msg.id === 'number') lastIdRef.current = Math.max(lastIdRef.current, msg.id)
    scrollDown()
  }

  const send = async () => {
    const body = draft.trim()
    if (!body || !active || sending) return
    setSending(true)
    const res = await api.send({ contact_id: active.id, to: active.wa_id, type: 'text', body })
    setSending(false)
    if (res.ok) {
      setDraft('')
      pushOut({ id: res.message_id, direction: 'out', type: 'text', body, status: 'sent', created_at: nowStamp() })
      loadConvs(query)
    } else toast(res.error || 'No se pudo enviar', 'err')
  }

  const sendTemplate = async (tpl, components = []) => {
    setPickerOpen(false)
    const res = await api.send({ contact_id: active.id, to: active.wa_id, type: 'template', template_name: tpl.name, language: tpl.language, components })
    if (res.ok) { toast('Plantilla enviada'); loadConvs(query) } else toast(res.error || 'No se pudo enviar', 'err')
  }

  // ---- Adjuntar medios (📎) ----
  const pickAttach = (a) => {
    setAttachMenu(false)
    pendingTypeRef.current = a.type
    if (fileRef.current) { fileRef.current.accept = a.accept; fileRef.current.value = ''; fileRef.current.click() }
  }

  const onFileChosen = (e) => {
    const f = e.target.files?.[0]
    if (!f) return
    const type = pendingTypeRef.current
    const url = (type === 'image' || type === 'video') ? URL.createObjectURL(f) : null
    setMediaPreview({ file: f, type, url, caption: '' })
  }

  const cancelMedia = () => {
    if (mediaPreview?.url) URL.revokeObjectURL(mediaPreview.url)
    setMediaPreview(null)
  }

  const sendMediaNow = async () => {
    if (!mediaPreview || !active || sending) return
    const { file, type, caption } = mediaPreview
    setSending(true)
    const res = await api.sendMedia({ file, to: active.wa_id, contact_id: active.id, type, caption: caption.trim() })
    setSending(false)
    if (res.ok) {
      const body = caption.trim() || (type === 'document' ? file.name : '')
      pushOut({ id: res.message_id, direction: 'out', type, body, media_url: res.media_id, media_mime: file.type, status: 'sent', created_at: nowStamp() })
      cancelMedia(); loadConvs(query)
    } else toast(res.error || 'No se pudo enviar', 'err')
  }

  // ---- Mensaje interactivo (botones / lista) ----
  const sendInteractive = async (interactive) => {
    if (!active) return
    const res = await api.send({ contact_id: active.id, to: active.wa_id, type: 'interactive', interactive })
    if (res.ok) {
      pushOut({ id: res.message_id, direction: 'out', type: 'interactive', body: interactive.body?.text || '', payload: JSON.stringify(interactive), status: 'sent', created_at: nowStamp() })
      setIbMode(null); loadConvs(query)
    } else toast(res.error || 'No se pudo enviar', 'err')
    return res
  }

  // Cerrar menús del composer al hacer clic fuera
  useEffect(() => {
    if (!attachMenu && !botMenu) return
    const close = () => { setAttachMenu(false); setBotMenu(false) }
    document.addEventListener('click', close)
    return () => document.removeEventListener('click', close)
  }, [attachMenu, botMenu])

  // ---- Menú contextual (clic derecho en una conversación) ----
  const openCtx = (e, c) => {
    e.preventDefault()
    const menuW = 210, menuH = 110
    const x = Math.min(e.clientX, window.innerWidth - menuW)
    const y = Math.min(e.clientY, window.innerHeight - menuH)
    setCtx({ x, y, conv: c })
  }
  useEffect(() => {
    if (!ctx) return
    const close = () => setCtx(null)
    document.addEventListener('click', close)
    document.addEventListener('scroll', close, true)
    window.addEventListener('resize', close)
    const esc = (e) => e.key === 'Escape' && close()
    document.addEventListener('keydown', esc)
    return () => {
      document.removeEventListener('click', close)
      document.removeEventListener('scroll', close, true)
      window.removeEventListener('resize', close)
      document.removeEventListener('keydown', esc)
    }
  }, [ctx])

  const markConv = async (c, read) => {
    setCtx(null)
    setConvs((cs) => cs.map((x) => (x.id === c.id ? { ...x, unread: read ? 0 : 1 } : x)))
    await api.markConversation(c.id, read)
    loadConvs(query)
  }

  const deleteConv = async (c) => {
    setCtx(null)
    if (!(await confirm({ title: 'Eliminar conversación', message: `Se borrarán todos los mensajes con ${c.name || '+' + c.wa_id}.`, danger: true, confirmText: 'Eliminar' }))) return
    const res = await api.deleteConversation(c.id)
    if (res.ok) {
      setConvs((cs) => cs.filter((x) => x.id !== c.id))
      if (active?.id === c.id) { setActive(null); setDetail(null); setMessages([]) }
      toast('Conversación eliminada')
    } else toast(res.error || 'No se pudo eliminar', 'err')
  }

  // refrescar detalle del contacto (nombre / notas / etiquetas) tras editar en el panel
  const onContactUpdated = (patch) => {
    setDetail((d) => ({ ...d, ...patch }))
    if (patch.name !== undefined) setActive((a) => ({ ...a, name: patch.name }))
    loadConvs(query)
  }

  const lastIn = [...messages].reverse().find((m) => m.direction === 'in')
  const windowClosed = active && (!lastIn || (Date.now() - parseDate(lastIn.created_at)?.getTime()) > 24 * 3600 * 1000)

  const rows = []
  let lastDay = null
  messages.forEach((m) => {
    const d = parseDate(m.created_at)?.toDateString()
    if (d && d !== lastDay) { rows.push({ sep: dayLabel(m.created_at), id: 'sep' + d }); lastDay = d }
    rows.push({ m })
  })

  const filtered = (convs || []).filter((c) => {
    if (tab === 'unread') return parseInt(c.unread) > 0
    if (tab === 'read') return !parseInt(c.unread)
    return true
  })

  return (
    <div className="inbox">
      {/* Lista */}
      <aside className="conv-col">
        <div className="conv-header">
          <div className="title-row">
            <span className="ic"><Icon.message /></span>
            <h1>Chat en vivo</h1>
            <span className="count">{convs ? `${convs.length}` : ''}</span>
          </div>
          <div className="search">
            <Icon.search />
            <input placeholder="Buscar por nombre o número" value={query} onChange={(e) => setQuery(e.target.value)} />
          </div>
          <div className="assign-filter">
            <Select sm block value={assignFilter} onChange={setAssignFilter} options={[
              { value: 'all', label: 'Todas las conversaciones' },
              { value: 'me', label: 'Asignadas a mí' },
              { value: 'none', label: 'Sin asignar' },
            ]} />
          </div>
        </div>
        <div className="tabs">
          {TABS.map((t) => (
            <button key={t.k} className={`tab ${tab === t.k ? 'active' : ''}`} onClick={() => setTab(t.k)}>{t.t}</button>
          ))}
        </div>
        <div className="conv-scroll">
          {convs === null && (
            <div className="skeleton">
              {Array.from({ length: 6 }).map((_, i) => (
                <div className="sk-row" key={i}><div className="sk-c av" /><div style={{ flex: 1 }}><div className="sk-c l1" /><div className="sk-c l2" /></div></div>
              ))}
            </div>
          )}
          {convs && filtered.length === 0 && (
            <div className="empty"><div className="ico"><Icon.chat /></div><p>{tab === 'all' ? 'Aún no hay conversaciones. Aparecerán aquí cuando alguien escriba a tu WhatsApp.' : 'No hay conversaciones en este filtro.'}</p></div>
          )}
          {filtered.map((c) => (
            <div key={c.id} className={`conv ${active?.id === c.id ? 'active' : ''}`} onClick={() => openChat(c)} onContextMenu={(e) => openCtx(e, c)}>
              <Avatar c={c} size="lg" />
              <div className="info">
                <div className="line1">
                  <span className="name">{c.name || '+' + c.wa_id}</span>
                  <span className="time">{relTime(c.last_time)}</span>
                </div>
                <div className="line2">
                  <span className="preview">{c.last_message || ''}</span>
                  {parseInt(c.unread) > 0 && <span className="badge">{c.unread}</span>}
                </div>
                <div className="line3">
                  <span className="chan"><Icon.logo style={{ width: 11, height: 11, fill: 'currentColor' }} /> Meta</span>
                  {c.assignee_name && <span className="assignee-chip"><Icon.user style={{ width: 10, height: 10, fill: 'currentColor' }} />{c.assignee_name}</span>}
                  {(c.labels || []).map((l) => (
                    <span key={l.id} className="lbl-chip" style={{ background: l.color + '22', color: l.color }}><span className="lbl-dot" style={{ background: l.color }} />{l.name}</span>
                  ))}
                </div>
              </div>
            </div>
          ))}
        </div>
      </aside>

      {/* Chat */}
      <section className="chat">
        {!active ? (
          <div className="no-chat">
            <div className="ico"><Icon.logo /></div>
            <h2>Tu bandeja de WhatsApp</h2>
            <p>Selecciona una conversación de la izquierda para leer y responder los mensajes.</p>
          </div>
        ) : (
          <>
            <header className="chat-head">
              <Avatar c={active} size="md" />
              <div>
                <div className="name">{active.name || '+' + active.wa_id}</div>
                <div className="sub">+{active.wa_id}</div>
              </div>
              <div className="actions">
                <div className="assign-pick" title="Asignar conversación">
                  <Icon.user style={{ width: 15, height: 15, fill: 'var(--ink-3)' }} />
                  <Select sm value={(detail || active)?.assigned_to || 0} onChange={assign} placeholder="Sin asignar"
                    options={[{ value: 0, label: 'Sin asignar' }, ...agents.map((a) => ({ value: a.id, label: a.name || a.email }))]} />
                </div>
                <button className={`icon-btn ${showInfo ? 'on' : ''}`} title="Información del chat" onClick={() => setShowInfo((s) => !s)}><Icon.info /></button>
              </div>
            </header>

            <div className="chat-body" ref={bodyRef}>
              {loadingMsgs && <div className="center-load"><div className="spinner" /></div>}
              {!loadingMsgs && messages.length === 0 && <div className="empty" style={{ margin: 'auto' }}><p>No hay mensajes todavía en esta conversación.</p></div>}
              {rows.map((r) => r.sep ? <div className="day-sep" key={r.id}><span>{r.sep}</span></div> : <Bubble key={r.m.id} m={r.m} />)}
            </div>

            {windowClosed && <div className="window-banner">⚠️ Ventana de 24 h cerrada. Solo puedes enviar plantillas aprobadas.</div>}

            <div className="composer">
              <div className="cmp-tools">
                <div className="cmp-menu-wrap">
                  <button className={`round tool ${attachMenu ? 'on' : ''}`} title="Adjuntar archivo" disabled={windowClosed}
                    onClick={(e) => { e.stopPropagation(); setBotMenu(false); setAttachMenu((v) => !v) }}><Icon.link /></button>
                  {attachMenu && (
                    <div className="cmp-menu" onClick={(e) => e.stopPropagation()}>
                      {ATTACH.map((a) => {
                        const I = Icon[a.icon]
                        return <button key={a.type} onClick={() => pickAttach(a)}><I style={{ width: 17, height: 17, fill: 'currentColor' }} /> {a.label}</button>
                      })}
                    </div>
                  )}
                </div>
                <div className="cmp-menu-wrap">
                  <button className={`round tool ${botMenu ? 'on' : ''}`} title="Mensaje interactivo" disabled={windowClosed}
                    onClick={(e) => { e.stopPropagation(); setAttachMenu(false); setBotMenu((v) => !v) }}><Icon.bolt /></button>
                  {botMenu && (
                    <div className="cmp-menu" onClick={(e) => e.stopPropagation()}>
                      <button onClick={() => { setBotMenu(false); setIbMode('button') }}><Icon.checkSquare style={{ width: 17, height: 17, fill: 'currentColor' }} /> Botones</button>
                      <button onClick={() => { setBotMenu(false); setIbMode('list') }}><Icon.list style={{ width: 17, height: 17, fill: 'currentColor' }} /> Lista</button>
                    </div>
                  )}
                </div>
                <button className="round tpl" title="Enviar plantilla" onClick={() => setPickerOpen(true)}><Icon.templates /></button>
              </div>
              <textarea placeholder="Escribe un mensaje" value={draft} rows={1}
                onChange={(e) => setDraft(e.target.value)}
                onKeyDown={(e) => { if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); send() } }} />
              <button className="round send" title="Enviar" disabled={!draft.trim() || sending} onClick={send}><Icon.send /></button>
              <input ref={fileRef} type="file" hidden onChange={onFileChosen} />
            </div>
          </>
        )}
      </section>

      {/* Panel Chat Info */}
      {active && showInfo && (
        <ChatInfo
          key={`${active.id}-${detail ? 'd' : 'a'}`}
          contact={detail || active}
          messages={messages}
          onClose={() => setShowInfo(false)}
          onUpdated={onContactUpdated}
        />
      )}

      {pickerOpen && <TemplatePicker onClose={() => setPickerOpen(false)} onPick={sendTemplate} />}

      {ibMode && <InteractiveBuilder mode={ibMode} onClose={() => setIbMode(null)} onSend={sendInteractive} />}

      {mediaPreview && (
        <div className="modal-bg" onClick={(e) => e.target.classList.contains('modal-bg') && cancelMedia()}>
          <div className="modal mpv">
            <div className="modal-head">
              <h3>Enviar {ATTACH.find((a) => a.type === mediaPreview.type)?.label.toLowerCase() || 'archivo'}</h3>
              <button className="x" onClick={cancelMedia}>×</button>
            </div>
            <div className="modal-body">
              <div className="mpv-preview">
                {mediaPreview.type === 'image' && <img src={mediaPreview.url} alt="" />}
                {mediaPreview.type === 'video' && <video src={mediaPreview.url} controls />}
                {(mediaPreview.type === 'audio' || mediaPreview.type === 'document') && (
                  <div className="mpv-file"><Icon.file style={{ width: 30, height: 30, fill: 'var(--primary)' }} /><span>{mediaPreview.file.name}</span></div>
                )}
              </div>
              {mediaPreview.type !== 'audio' && (
                <input className="mpv-caption" placeholder="Añade un pie de foto (opcional)…" value={mediaPreview.caption}
                  onChange={(e) => setMediaPreview((p) => ({ ...p, caption: e.target.value }))}
                  onKeyDown={(e) => { if (e.key === 'Enter') sendMediaNow() }} autoFocus />
              )}
              <div className="add-row" style={{ marginTop: 14, justifyContent: 'flex-end' }}>
                <button className="btn ghost" onClick={cancelMedia}>Cancelar</button>
                <button className="btn" disabled={sending} onClick={sendMediaNow}>{sending ? 'Enviando…' : 'Enviar'}</button>
              </div>
            </div>
          </div>
        </div>
      )}

      {ctx && (
        <div className="ctx-menu" style={{ left: ctx.x, top: ctx.y }} onClick={(e) => e.stopPropagation()}>
          {parseInt(ctx.conv.unread) > 0 ? (
            <button onClick={() => markConv(ctx.conv, true)}><Icon.check /> Marcar como leído</button>
          ) : (
            <button onClick={() => markConv(ctx.conv, false)}><Icon.dot /> Marcar como no leído</button>
          )}
          <button className="danger" onClick={() => deleteConv(ctx.conv)}><Icon.trash /> Eliminar conversación</button>
        </div>
      )}
    </div>
  )
}
