import { useState, useEffect, useCallback, useRef } from 'react'
import { api } from '../api.js'
import { fmtDate } from '../util.js'
import { Icon } from '../icons.jsx'
import { useToast } from '../App.jsx'

/* -------------------------------------------------------------------------
 * AJUSTES › CHAT DE PRUEBAS DE LA IA. El agente escribe como si fuera el
 * cliente y la IA contesta con el mismo motor que en los tickets, pero NADA
 * se envía a ningún cliente. Cada respuesta se valida («está bien» o «hay que
 * corregirla»): lo corregido pasa a la memoria de la IA de producción, con el
 * nombre de quien lo escribió, y se puede retirar desde «Correcciones».
 * ---------------------------------------------------------------------- */
const ESPERA = 20 // segundos sin que el «cliente» escriba antes de que la IA conteste (como en WhatsApp)
const eur = (n) => (Number(n) || 0).toFixed(2).replace('.', ',') + ' €'

export default function AiPruebas() {
  const toast = useToast()
  const [vista, setVista] = useState('chats')
  const [st, setSt] = useState(null)
  const [id, setId] = useState(null)
  const [chat, setChat] = useState(null)
  const [msgs, setMsgs] = useState([])
  const [texto, setTexto] = useState('')
  const [ocupado, setOcupado] = useState(false)
  const [resta, setResta] = useState(0)
  const [corrigiendo, setCorrigiendo] = useState(null)
  const [nota, setNota] = useState('')
  const [nuevo, setNuevo] = useState(false)
  const [form, setForm] = useState({ name: '', email: '' })
  const [corr, setCorr] = useState(null)
  const cajaRef = useRef(null)

  const cargar = useCallback(async () => {
    const r = await api.pruebasStatus()
    setSt(r?.ok ? r : { error: r?.error || 'No se pudo cargar' })
  }, [])
  useEffect(() => { cargar() }, [cargar])

  const cargarCorr = useCallback(async () => {
    const r = await api.pruebasCorrections()
    setCorr(r?.ok ? r.items : [])
  }, [])
  useEffect(() => { if (vista === 'corr') cargarCorr() }, [vista, cargarCorr])

  // Cuenta atrás hasta que la IA contesta; si el «cliente» sigue escribiendo, se reinicia.
  useEffect(() => {
    if (resta <= 0) return
    const t = setTimeout(() => { if (resta === 1) { setResta(0); responder() } else setResta(resta - 1) }, 1000)
    return () => clearTimeout(t)
  }, [resta]) // eslint-disable-line react-hooks/exhaustive-deps

  // Siempre se ve lo último: la respuesta nueva y su tarjeta de validación.
  useEffect(() => { const c = cajaRef.current; if (c) c.scrollTop = c.scrollHeight }, [msgs, ocupado, resta > 0, corrigiendo, id])

  const abrir = async (cid) => {
    setResta(0); setCorrigiendo(null); setNota(''); setNuevo(false)
    const r = await api.pruebasChat(cid)
    if (!r?.ok) { toast(r?.error || 'No se pudo abrir', 'err'); return }
    setId(cid); setChat(r.chat); setMsgs(r.messages)
    if (r.messages.length && r.messages[r.messages.length - 1].role === 'user') setResta(ESPERA)
  }

  const pedir = async (accion, body, ok) => {
    setOcupado(true)
    const r = await api.pruebasPost(accion, body)
    setOcupado(false)
    if (!r?.ok) { toast(r?.error || 'No se pudo completar', 'err'); return null }
    if (r.messages) setMsgs(r.messages)
    if (r.presupuesto) setSt((s) => ({ ...s, presupuesto: r.presupuesto }))
    if (ok) toast(ok)
    cargar()
    return r
  }

  const responder = () => { setResta(0); if (id && msgs.length && msgs[msgs.length - 1].role === 'user') pedir('reply', { id }) }
  const otra = () => pedir('regenerate', { id })
  const enviar = async () => {
    const v = texto.trim()
    if (!v || !id || ocupado) return
    setTexto(''); setResta(0)
    const r = await pedir('send', { id, text: v })
    if (r) setResta(ESPERA); else setTexto(v)
  }
  const valorar = async (m, rating, correction) => {
    const r = await pedir('rate', { id: m.id, rating, correction }, rating === 'up' ? 'Respuesta validada' : 'Corrección guardada en la memoria de la IA')
    if (r) { setCorrigiendo(null); setNota('') }
  }
  const crear = async (e) => {
    e.preventDefault()
    const r = await pedir('create', { name: form.name.trim() || null, email: form.email.trim() || null })
    if (r) { setForm({ name: '', email: '' }); setNuevo(false); await cargar(); abrir(r.id) }
  }
  const borrar = async () => {
    if (!id || !window.confirm('¿Borrar esta conversación? También se borran de la memoria las correcciones que salieron de ella.')) return
    const r = await pedir('delete', { id }, 'Conversación borrada')
    if (r) { setId(null); setChat(null); setMsgs([]) }
  }
  const retirar = async (m, retired) => {
    setOcupado(true)
    const r = await api.pruebasPost('retire', { id: m.id, retired })
    setOcupado(false)
    if (r?.ok) { setCorr(r.items); toast(retired ? 'Corrección retirada: la IA ya no la usa' : 'Corrección restaurada: la IA vuelve a usarla'); cargar() }
    else toast(r?.error || 'No se pudo guardar', 'err')
  }

  if (!st) return <div className="center-load"><div className="spinner" /></div>
  if (st.error) return <div className="card em-card"><p className="em-desc">{st.error}</p></div>

  const pres = st.presupuesto
  const ultima = [...msgs].reverse().find((m) => m.role === 'assistant' && !m.superseded)
  const pend = ultima && ['ready', 'abstained'].includes(ultima.status) && !ultima.rating ? ultima : null
  const sinValidar = (st.chats || []).reduce((n, c) => n + c.pendientes, 0)
  const tope = pres.level === 'over'

  return (
    <div className="pr">
      <div className="pr-aviso" role="note">
        <span className="pr-aviso-ic"><Icon.warn /></span>
        <div>
          <b>Esto es una prueba. Nada se envía a ningún cliente ni toca ningún ticket.</b>
          <span>Escribes como si fueras el cliente y la IA contesta como lo haría en un ticket. Las correcciones que guardes <u>sí</u> enseñan a la IA de producción: valida con cuidado.</span>
        </div>
      </div>

      <div className="pr-barra">
        <div className="pr-tabs" role="tablist">
          <button role="tab" aria-selected={vista === 'chats'} className={vista === 'chats' ? 'on' : ''} onClick={() => setVista('chats')}>
            Conversaciones{sinValidar > 0 ? <i className="pr-n" title="Respuestas sin validar">{sinValidar}</i> : null}
          </button>
          <button role="tab" aria-selected={vista === 'corr'} className={vista === 'corr' ? 'on' : ''} onClick={() => setVista('corr')}>Correcciones guardadas</button>
        </div>
        <div className={'pr-gasto ' + pres.level} title={`El gasto se reinicia el ${fmtDate(pres.resets)}`}>
          <span>Gasto de pruebas este mes <b>{eur(pres.spent)}</b>{pres.limit > 0 ? <> de {eur(pres.limit).replace(',00', '')}</> : null}</span>
          {pres.limit > 0 ? <i style={{ '--w': Math.min(100, pres.pct || 0) + '%' }} /> : null}
        </div>
      </div>

      {vista === 'corr' ? (
        <Correcciones items={corr} busy={ocupado} onRetirar={retirar} onAbrir={(cid) => { setVista('chats'); abrir(cid) }} />
      ) : (
        <div className="pr-grid">
          <aside className="pr-lista">
            <button className="btn" onClick={() => setNuevo((v) => !v)}><Icon.plus /> Nueva conversación</button>
            {nuevo && (
              <form className="pr-nuevo" onSubmit={crear}>
                <label className="field"><span className="lbl">Nombre del «cliente» <span className="hint">(opcional)</span></span>
                  <input value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} placeholder="Ej.: Javier" />
                </label>
                <label className="field"><span className="lbl">Correo del «cliente» <span className="hint">(opcional)</span></span>
                  <input type="email" value={form.email} onChange={(e) => setForm({ ...form, email: e.target.value })} placeholder="nombre@hotel.com" />
                </label>
                <p className="muted">Si pones un correo, la IA lo comprueba de verdad en los hoteles (solo lectura), igual que en un ticket.</p>
                <button className="btn primary" type="submit" disabled={ocupado}>Empezar</button>
              </form>
            )}
            <ul>
              {(st.chats || []).length === 0 && <li className="pr-vacio-l">Aún no hay conversaciones. Empieza una.</li>}
              {(st.chats || []).map((c) => (
                <li key={c.id}>
                  <button className={id === c.id ? 'on' : ''} onClick={() => abrir(c.id)}>
                    <span className="pr-l-t">{c.title}</span>
                    <span className="pr-l-m">{c.author || 'Sin autor'} · {fmtDate(c.updated_at)}{c.pendientes > 0 ? <em>{c.pendientes} sin validar</em> : null}</span>
                  </button>
                </li>
              ))}
            </ul>
          </aside>

          <section className="pr-chat" aria-label="Conversación de prueba">
            {!chat ? (
              <div className="pr-vacio">
                <Icon.chat />
                <h3>Elige una conversación o empieza una nueva</h3>
                <p>Escribe un mensaje como lo haría un cliente y comprueba cómo respondería la IA. Después valida si la respuesta está bien o corrígela.</p>
              </div>
            ) : (
              <>
                <header className="pr-chat-h">
                  <div>
                    <h3>{chat.title}</h3>
                    <span className="muted">Creada por {chat.author_name || '—'}{chat.contact_name || chat.contact_email ? ` · «cliente»: ${[chat.contact_name, chat.contact_email].filter(Boolean).join(' · ')}` : ''}</span>
                  </div>
                  <button className="btn ghost sm" onClick={borrar} disabled={ocupado}><Icon.trash /> Borrar</button>
                </header>

                <div className="pr-msgs" ref={cajaRef}>
                  {msgs.length === 0 && <p className="pr-hint">Escribe abajo el primer mensaje del cliente.</p>}
                  {msgs.map((m) => m.role === 'user' ? (
                    <div className="pr-fila cli" key={m.id}>
                      <span className="pr-who">Cliente de prueba · escrito por {m.author_name || 'alguien'}</span>
                      <div className="pr-b">{m.body}</div>
                    </div>
                  ) : (
                    <div className={'pr-fila ia' + (m.superseded ? ' old' : '')} key={m.id}>
                      <span className="pr-who">Respuesta de la IA · <b>no enviada a nadie</b>{m.superseded ? ' · sustituida' : ''}</span>
                      {m.status === 'ready' && <div className="pr-b">{m.body}</div>}
                      {m.status === 'abstained' && <div className="pr-b warn">La IA ha decidido pasar esto a una persona: no contestaría al cliente.</div>}
                      {m.status === 'failed' && <div className="pr-b bad">La IA no pudo contestar{m.error ? `: ${m.error}` : '.'} <button className="btn ghost sm" onClick={otra} disabled={ocupado}>Reintentar</button></div>}
                      {m.rating && <Veredicto m={m} />}
                      {pend && pend.id === m.id && (
                        <div className="pr-validar" role="group" aria-label="Validar la respuesta">
                          {corrigiendo === m.id ? (
                            <div className="pr-fix">
                              <label htmlFor="pr-nota">{m.status === 'abstained' ? '¿Sabía contestar? Explica cómo debía responder.' : '¿Qué falló y cómo debía responder? Basta el criterio.'}</label>
                              <textarea id="pr-nota" rows={4} value={nota} onChange={(e) => setNota(e.target.value)} autoFocus placeholder="Ej.: ese correo ya es usuario; no hay que explicar cómo crearlo, preguntar a qué hotel necesita acceso." />
                              <p className="muted">Se guarda en la memoria de la IA de producción con tu nombre. Podrás retirarla desde «Correcciones guardadas».</p>
                              <div className="pr-acts">
                                <button className="btn danger" onClick={() => valorar(m, 'down', nota)} disabled={ocupado || nota.trim().length < 5}>Guardar corrección</button>
                                <button className="btn ghost" onClick={() => { setCorrigiendo(null); setNota('') }}>Cancelar</button>
                              </div>
                            </div>
                          ) : (
                            <>
                              <p className="pr-validar-t"><b>Falta tu validación.</b> {m.status === 'abstained' ? '¿Hizo bien en pasarlo a una persona?' : '¿Esta respuesta está bien?'} No puedes seguir hasta decidirlo.</p>
                              <div className="pr-acts">
                                <button className="btn pr-si" onClick={() => valorar(m, 'up')} disabled={ocupado}><Icon.check /> {m.status === 'abstained' ? 'Sí, bien derivado' : 'Sí, está bien'}</button>
                                <button className="btn pr-no" onClick={() => setCorrigiendo(m.id)} disabled={ocupado}><Icon.x /> {m.status === 'abstained' ? 'No, sí sabía' : 'No, hay que corregirla'}</button>
                                <button className="btn ghost" onClick={otra} disabled={ocupado}><Icon.refresh /> Probar otra respuesta</button>
                              </div>
                            </>
                          )}
                        </div>
                      )}
                      {ultima && ultima.id === m.id && m.rating === 'down' && !m.retired_at && (
                        <div className="pr-acts"><button className="btn ghost sm" onClick={otra} disabled={ocupado}><Icon.refresh /> Generar de nuevo con esta indicación</button></div>
                      )}
                    </div>
                  ))}
                  {ocupado && <div className="pr-fila ia"><span className="pr-who">La IA está escribiendo…</span><div className="pr-b"><span className="pr-typing"><i /><i /><i /></span></div></div>}
                  {resta > 0 && !ocupado && (
                    <div className="pr-espera">La IA contestará en {resta} s por si el cliente sigue escribiendo… <button className="btn ghost sm" onClick={responder}>Responder ya</button></div>
                  )}
                </div>

                <footer className="pr-comp">
                  {tope ? <p className="pr-bloq bad">Se ha llegado al tope de gasto de pruebas de este mes ({eur(pres.limit).replace(',00', '')}). Se reabre el {fmtDate(pres.resets)}.</p>
                    : pend ? <p className="pr-bloq">Valida la respuesta de arriba para poder escribir otro mensaje del cliente.</p> : null}
                  <div className="pr-comp-f">
                    <textarea rows={2} value={texto} disabled={!!pend || ocupado || tope} aria-label="Mensaje del cliente de prueba"
                      onChange={(e) => setTexto(e.target.value)}
                      onKeyDown={(e) => { if (e.key === 'Enter' && !e.shiftKey && !e.nativeEvent.isComposing) { e.preventDefault(); enviar() } }}
                      placeholder={pend ? 'Primero valida la respuesta de la IA' : 'Escribe lo que diría el cliente… (Intro para enviar, Mays+Intro para otra línea)'} />
                    <button className="btn primary" onClick={enviar} disabled={!texto.trim() || !!pend || ocupado || tope}><Icon.send /> Enviar</button>
                  </div>
                  <small className="muted">Mensaje de prueba: no sale de aquí.</small>
                </footer>
              </>
            )}
          </section>
        </div>
      )}
    </div>
  )
}

function Veredicto({ m }) {
  if (m.rating === 'up') return <div className="pr-ver up"><Icon.check /> <span>Validada por <b>{m.rated_by_name || 'alguien'}</b></span></div>
  return (
    <div className={'pr-ver down' + (m.retired_at ? ' off' : '')}>
      <Icon.x />
      <span>
        Corregida por <b>{m.rated_by_name || 'alguien'}</b>: {m.correction}
        {m.retired_at ? <em> · Retirada de la memoria por {m.retired_by_name || 'alguien'}</em> : null}
      </span>
    </div>
  )
}

/* Correcciones escritas en este chat, con quién y cuándo, para retirar las que no deberían estar en la memoria. */
function Correcciones({ items, busy, onRetirar, onAbrir }) {
  if (!items) return <div className="center-load"><div className="spinner" /></div>
  const activas = items.filter((x) => !x.retired).length
  return (
    <div className="pr-corr">
      <p className="pr-corr-d">Cada corrección que se guarda en una prueba pasa a la memoria de la IA y la usa en los tickets reales. Aquí ves quién la escribió y puedes <b>retirar</b> la que no deba estar: la IA deja de usarla al instante, pero queda guardada y se puede restaurar.
        {' '}<b>{activas}</b> en uso · <b>{items.length - activas}</b> retirada{items.length - activas === 1 ? '' : 's'}.</p>
      {items.length === 0 ? <div className="pr-vacio"><Icon.note /><h3>Aún no hay correcciones</h3><p>Aparecerán aquí cuando alguien diga «No, hay que corregirla» en una conversación de prueba.</p></div> : (
        <ul>
          {items.map((x) => (
            <li key={x.id} className={x.retired ? 'off' : ''}>
              <div className="pr-c-h">
                <span className={'pill ' + (x.retired ? 'gray' : 'ok')}><span className="dot" />{x.retired ? 'Retirada' : 'En la memoria de la IA'}</span>
                <span className="muted"><b>{x.by || 'Autor sin registrar'}</b>{x.at ? ' · ' + fmtDate(x.at) : ''} · <button className="pr-link" onClick={() => onAbrir(x.chat_id)}>{x.title}</button></span>
              </div>
              <dl>
                <div><dt>El «cliente» escribió</dt><dd>{x.question || '—'}</dd></div>
                <div><dt>La IA respondió (mal)</dt><dd>{x.ai || '—'}</dd></div>
                <div className="fix"><dt>Corrección</dt><dd>{x.correction}</dd></div>
              </dl>
              <div className="pr-acts">
                {x.retired
                  ? <><button className="btn ghost sm" onClick={() => onRetirar(x, false)} disabled={busy}>Restaurar en la memoria</button><span className="muted">Retirada por {x.retired_by || 'alguien'} · {fmtDate(x.retired_at)}</span></>
                  : <button className="btn danger sm" onClick={() => onRetirar(x, true)} disabled={busy}>Retirar de la memoria</button>}
              </div>
            </li>
          ))}
        </ul>
      )}
    </div>
  )
}
