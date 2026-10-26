import { useState, useEffect } from 'react'
import { api } from '../api.js'
import { Icon } from '../icons.jsx'
import { useToast, useConfirm } from '../App.jsx'
import Select from './Select.jsx'

/*
 * «¿A quién se lo envías?» — elegir la AUDIENCIA de una campaña con tarjetas. La audiencia
 * es una regla que se recalcula al enviar (segmento, etiquetas, provincia, todos…), salvo
 * la agenda, que es una lista fija de teléfonos. Avisa con el número de contactos al
 * momento y deja guardar la regla con nombre para reutilizarla.
 *
 * value: audiencia ({ type, … }) o null · onChange(aud) · onCount({ recipients, excluded })
 */
const n = (x) => Number(x || 0).toLocaleString('es-ES')
const toggle = (arr, v) => (arr.includes(v) ? arr.filter((x) => x !== v) : [...arr, v])

export default function AudiencePicker({ channel, value, onChange, onCount, phonebooks = [] }) {
  const toast = useToast()
  const confirm = useConfirm()
  const [meta, setMeta] = useState(null)
  const [count, setCount] = useState(null)        // { recipients, excluded } | { loading }
  const [saving, setSaving] = useState(null)      // nombre al guardar como audiencia
  const email = channel === 'email'
  const tipo = value?.type || ''

  const cargar = () => api.audienceMeta().then((d) => setMeta(d?.ok ? d : { labels: [], provincias: [], audiences: [], grupos: {} }))
  useEffect(() => { cargar() }, [])

  // Las agendas son teléfonos: en correo no valen.
  useEffect(() => { if (email && tipo === 'phonebook') onChange(null) }, [email])  // eslint-disable-line react-hooks/exhaustive-deps

  // Recuento en vivo (mismo cálculo que el envío real).
  const completa = valida(value)
  useEffect(() => {
    if (!completa) { setCount(null); onCount?.(null); return }
    let alive = true
    setCount({ loading: true })
    const t = setTimeout(() => {
      api.estimateCampaign({ channel: email ? 'email' : '', audience: value }).then((d) => {
        if (!alive) return
        const c = d?.ok ? { recipients: d.recipients, excluded: d.excluded } : null
        setCount(c); onCount?.(c)
      })
    }, 250)
    return () => { alive = false; clearTimeout(t) }
  }, [JSON.stringify(value), email])  // eslint-disable-line react-hooks/exhaustive-deps

  if (!meta) return <div className="center-load" style={{ minHeight: 120 }}><div className="spinner" /></div>

  const grupos = meta.grupos || {}
  const deGrupo = (g) => meta.labels.filter((l) => l.grupo === g)
  const libres = meta.labels.filter((l) => !l.grupo)
  const haySegmento = Object.keys(grupos).some((g) => deGrupo(g).length)

  const CARDS = [
    { type: 'segment', icon: <Icon.star />, t: 'Por segmento', d: 'Interés y tipo de contacto, p. ej. «Interés alto + No cliente»', hide: !haySegmento },
    { type: 'labels', icon: <Icon.tag />, t: 'Por etiquetas', d: 'Una o varias etiquetas' },
    { type: 'phonebook', icon: <Icon.list />, t: 'Por agenda', d: email ? 'Solo WhatsApp: son listas de teléfonos' : 'Una lista fija de teléfonos', off: email },
    { type: 'provincia', icon: <Icon.globe />, t: 'Por provincia', d: 'Contactos de una o varias provincias', hide: !meta.provincias.length },
    { type: 'audience', icon: <Icon.user />, t: 'Audiencia guardada', d: `${meta.audiences.length} guardada${meta.audiences.length === 1 ? '' : 's'}`, hide: !meta.audiences.length },
    { type: 'all', icon: <Icon.chat />, t: 'Todos', d: `Todos los contactos de Campañas (${n(meta.all_contacts)})` },
  ].filter((c) => !c.hide)

  const elegir = (t) => {
    if (t === tipo) return
    if (t === 'segment') onChange({ type: 'segment' })
    else if (t === 'labels') onChange({ type: 'labels', ids: [], match: 'any' })
    else if (t === 'phonebook') onChange({ type: 'phonebook', id: 0 })
    else if (t === 'provincia') onChange({ type: 'provincia', values: [] })
    else if (t === 'audience') onChange({ type: 'audience', id: 0 })
    else onChange({ type: 'all' })
  }

  const chip = (l, on, onClick) => (
    <button key={l.id ?? l.name} type="button" className={`aud-chip ${on ? 'on' : ''}`} onClick={onClick}
      style={l.color ? { '--c': l.color } : undefined}>
      {l.color && <span className="aud-dot" />}{l.name}<small>{n(l.contacts)}</small>
    </button>
  )

  const guardar = async () => {
    const name = (saving || '').trim()
    if (!name) { toast('Ponle un nombre', 'err'); return }
    const r = await api.saveAudience(name, value)
    if (!r.ok) { toast(r.error || 'No se pudo guardar', 'err'); return }
    toast(`Audiencia «${name}» guardada`)
    setSaving(null)
    await cargar()
    onChange({ type: 'audience', id: r.id })
  }
  const borrar = async (a) => {
    if (!(await confirm({ title: 'Borrar audiencia', message: `¿Borrar «${a.name}»? Las campañas ya enviadas no cambian.`, confirmText: 'Borrar', danger: true }))) return
    const r = await api.deleteAudience(a.id)
    if (!r.ok) { toast(r.error || 'No se pudo borrar', 'err'); return }
    if (value?.type === 'audience' && value.id === a.id) onChange(null)
    cargar()
  }

  return (
    <div className="aud">
      <div className="aud-cards">
        {CARDS.map((c) => (
          <button key={c.type} type="button" disabled={c.off}
            className={`aud-card ${tipo === c.type ? 'on' : ''}`} onClick={() => elegir(c.type)}>
            <span className="aud-ic">{c.icon}</span>
            <b>{c.t}</b>
            <span className="aud-d">{c.d}</span>
            {tipo === c.type && <span className="aud-ok"><Icon.check /></span>}
          </button>
        ))}
      </div>

      {tipo && (
        <div className="aud-panel">
          {tipo === 'segment' && (
            <>
              {Object.entries(grupos).map(([g, nombre]) => deGrupo(g).length > 0 && (
                <div className="aud-row" key={g}>
                  <span className="aud-lbl">{nombre}</span>
                  <div className="aud-chips">
                    {deGrupo(g).map((l) => chip(l, (value[g] || []).includes(l.id), () => {
                      const v = toggle(value[g] || [], l.id)
                      const next = { ...value, [g]: v }
                      if (!v.length) delete next[g]
                      onChange(next)
                    }))}
                  </div>
                </div>
              ))}
              <span className="hint">Dentro de una fila vale <b>cualquiera</b>; entre filas, <b>todas</b>. Deja una fila vacía para no filtrar por ella.</span>
            </>
          )}

          {tipo === 'labels' && (
            <>
              <div className="aud-chips">
                {(libres.length ? libres : meta.labels).map((l) => chip(l, value.ids.includes(l.id), () => onChange({ ...value, ids: toggle(value.ids, l.id) })))}
                {!meta.labels.length && <span className="hint">No hay etiquetas: créalas en Contactos.</span>}
              </div>
              {value.ids.length > 1 && (
                <div className="seg" style={{ maxWidth: 420, marginTop: 10 }}>
                  <button type="button" className={value.match === 'any' ? 'on' : ''} onClick={() => onChange({ ...value, match: 'any' })}>Con cualquiera de ellas</button>
                  <button type="button" className={value.match === 'all' ? 'on' : ''} onClick={() => onChange({ ...value, match: 'all' })}>Con todas a la vez</button>
                </div>
              )}
            </>
          )}

          {tipo === 'phonebook' && (
            <>
              <Select block value={value.id || ''} onChange={(v) => onChange({ type: 'phonebook', id: Number(v) })} placeholder="Selecciona una agenda…"
                options={phonebooks.map((p) => ({ value: p.id, label: p.name, sub: `${p.contacts} contactos` }))} />
              {!phonebooks.length && <span className="hint" style={{ color: 'var(--warn)' }}>No tienes agendas. Crea una en «Agenda de contactos».</span>}
            </>
          )}

          {tipo === 'provincia' && (
            <div className="aud-chips">
              {meta.provincias.map((p) => chip(p, value.values.includes(p.name), () => onChange({ ...value, values: toggle(value.values, p.name) })))}
            </div>
          )}

          {tipo === 'audience' && (
            <div className="aud-saved">
              {meta.audiences.map((a) => (
                <div key={a.id} className={`aud-sv ${value.id === a.id ? 'on' : ''}`} onClick={() => onChange({ type: 'audience', id: a.id })}>
                  <span className="aud-radio" />
                  <div><b>{a.name}</b><small>{a.desc}</small></div>
                  <button type="button" className="icon-btn" title="Borrar audiencia" onClick={(e) => { e.stopPropagation(); borrar(a) }}><Icon.trash /></button>
                </div>
              ))}
            </div>
          )}

          {tipo === 'all' && <span className="hint">Todos los contactos de Campañas{email ? ' que tengan correo' : ' que tengan teléfono'}, menos los dados de baja. Nunca incluye a los clientes que solo son del Helpdesk.</span>}

          <div className="aud-foot">
            <span className="aud-count">
              {!completa ? <span className="muted">Elige {tipo === 'phonebook' ? 'una agenda' : tipo === 'audience' ? 'una audiencia' : 'al menos una opción'}</span>
                : count?.loading ? <span className="muted">Contando…</span>
                : count ? <><b>{n(count.recipients)}</b> contacto{count.recipients === 1 ? '' : 's'}{email ? ' con correo' : ' con teléfono'}{count.excluded > 0 && <span className="muted"> · {n(count.excluded)} excluido{count.excluded === 1 ? '' : 's'} por baja</span>}</>
                : null}
            </span>
            {completa && ['segment', 'labels', 'provincia', 'all'].includes(tipo) && (
              saving === null
                ? <button type="button" className="btn ghost sm" onClick={() => setSaving('')}><Icon.save /> Guardar como audiencia</button>
                : <span className="aud-savebox">
                    <input autoFocus value={saving} onChange={(e) => setSaving(e.target.value)} placeholder="Nombre, p. ej. Calientes sin comprar"
                      onKeyDown={(e) => { if (e.key === 'Enter') guardar(); if (e.key === 'Escape') setSaving(null) }} />
                    <button type="button" className="btn sm" onClick={guardar}>Guardar</button>
                    <button type="button" className="btn ghost sm" onClick={() => setSaving(null)}>Cancelar</button>
                  </span>
            )}
          </div>
        </div>
      )}
    </div>
  )
}

/** ¿La audiencia está lista para contar/enviar? */
export function valida(a) {
  if (!a) return false
  switch (a.type) {
    case 'segment': return Object.keys(a).some((k) => k !== 'type' && (a[k] || []).length)
    case 'labels': return a.ids.length > 0
    case 'phonebook': return a.id > 0
    case 'provincia': return a.values.length > 0
    case 'audience': return a.id > 0
    case 'all': return true
    default: return false
  }
}
