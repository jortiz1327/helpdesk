import { useState, useEffect, useRef } from 'react'
import { api } from '../api.js'
import { Icon } from '../icons.jsx'
import Select from './Select.jsx'

/*
 * Filtros del Chat en vivo de Campañas: por Interés, Tipo y otras etiquetas (dentro de un
 * grupo vale cualquiera; entre grupos, todas), provincia, ventana de 24 h abierta, sin
 * clasificar y sin bajas. Botón con contador + panel desplegable. Se recuerda en este
 * navegador (comodidad), nunca en el servidor.
 */
export const FILTROS_VACIOS = { interes: [], tipo: [], labels: [], provincia: '', ventana: false, sin_clasificar: false, sin_bajas: false }
const KEY = 'chat_filtros'
const toggle = (arr, v) => (arr.includes(v) ? arr.filter((x) => x !== v) : [...arr, v])

export function filtrosGuardados() {
  try { return { ...FILTROS_VACIOS, ...(JSON.parse(localStorage.getItem(KEY) || 'null') || {}) } } catch { return FILTROS_VACIOS }
}
export function cuantosFiltros(f) {
  return f.interes.length + f.tipo.length + f.labels.length + (f.provincia ? 1 : 0) + (f.ventana ? 1 : 0) + (f.sin_clasificar ? 1 : 0) + (f.sin_bajas ? 1 : 0)
}
/** Lo que se manda al servidor (null si no hay ningún filtro). */
export function filtrosParaApi(f) {
  return cuantosFiltros(f) ? f : null
}

export default function ChatFilters({ value, onChange }) {
  const [open, setOpen] = useState(false)
  const [meta, setMeta] = useState(null)
  const ref = useRef(null)
  const n = cuantosFiltros(value)

  useEffect(() => { if (open && !meta) api.audienceMeta().then((d) => setMeta(d?.ok ? d : { labels: [], provincias: [] })) }, [open, meta])
  useEffect(() => { try { localStorage.setItem(KEY, JSON.stringify(value)) } catch { /* sin almacenamiento: da igual */ } }, [value])
  useEffect(() => {
    if (!open) return
    const fuera = (e) => { if (ref.current && !ref.current.contains(e.target)) setOpen(false) }
    const esc = (e) => { if (e.key === 'Escape') setOpen(false) }
    document.addEventListener('mousedown', fuera); document.addEventListener('keydown', esc)
    return () => { document.removeEventListener('mousedown', fuera); document.removeEventListener('keydown', esc) }
  }, [open])

  const set = (patch) => onChange({ ...value, ...patch })
  const grupo = (g) => (meta?.labels || []).filter((l) => (g ? l.grupo === g : !l.grupo))
  const fila = (titulo, campo, lista) => lista.length > 0 && (
    <div className="cf-row">
      <span className="cf-lbl">{titulo}</span>
      <div className="aud-chips">
        {lista.map((l) => (
          <button key={l.id} type="button" className={`aud-chip ${value[campo].includes(l.id) ? 'on' : ''}`} style={{ '--c': l.color }}
            onClick={() => set({ [campo]: toggle(value[campo], l.id) })}>
            <span className="aud-dot" />{l.name}
          </button>
        ))}
      </div>
    </div>
  )
  const check = (campo, texto, ayuda) => (
    <label className="cf-check">
      <span className="fb-switch"><input type="checkbox" checked={!!value[campo]} onChange={(e) => set({ [campo]: e.target.checked })} /><span className={`fb-toggle ${value[campo] ? 'on' : ''}`} /></span>
      <span><b>{texto}</b>{ayuda && <small>{ayuda}</small>}</span>
    </label>
  )

  return (
    <div className="cf" ref={ref}>
      <button type="button" className={`btn ghost sm cf-btn ${n ? 'on' : ''}`} onClick={() => setOpen((o) => !o)} aria-expanded={open}>
        <Icon.list /> Filtros{n > 0 && <span className="cf-n">{n}</span>}
      </button>
      {open && (
        <div className="cf-pop" role="dialog" aria-label="Filtros del chat">
          {!meta ? <div className="center-load" style={{ minHeight: 80 }}><div className="spinner" /></div> : (
            <>
              {fila('Interés', 'interes', grupo('interes'))}
              {fila('Tipo', 'tipo', grupo('tipo'))}
              {fila('Otras etiquetas', 'labels', grupo(null))}
              {meta.provincias.length > 0 && (
                <div className="cf-row">
                  <span className="cf-lbl">Provincia</span>
                  <Select sm block value={value.provincia} onChange={(v) => set({ provincia: v })}
                    options={[{ value: '', label: 'Todas' }, ...meta.provincias.map((p) => ({ value: p.name, label: p.name, sub: `${p.contacts}` }))]} />
                </div>
              )}
              <div className="cf-checks">
                {check('ventana', 'Ventana de 24 h abierta', 'Escribieron en las últimas 24 h: se les puede responder sin plantilla')}
                {check('sin_clasificar', 'Sin clasificar', 'Aún sin etiqueta de interés')}
                {check('sin_bajas', 'Ocultar dados de baja')}
              </div>
              <div className="cf-foot">
                <span className="hint">Dentro de un grupo vale cualquiera; entre grupos, todos.</span>
                <button type="button" className="btn ghost sm" disabled={!n} onClick={() => onChange(FILTROS_VACIOS)}>Limpiar</button>
              </div>
            </>
          )}
        </div>
      )}
    </div>
  )
}
