import { useState, useEffect, useCallback } from 'react'
import { api } from '../api.js'
import { Icon } from '../icons.jsx'
import { useToast, useConfirm } from '../App.jsx'
import InfoTip from './InfoTip.jsx'

/* -------------------------------------------------------------------------
 * AGENTE DE IA (Claude) para el WhatsApp de soporte — BLOQUE 1.
 * Solo ajustes + candado: sin clave de API el agente está INACTIVO (solo lectura).
 * El cerebro y el enganche en el webhook llegan en el Bloque 2.
 * ---------------------------------------------------------------------- */

const MODELOS = [
  { v: 'rapido',      t: 'Rápido',      d: 'el más ágil y barato' },
  { v: 'equilibrado', t: 'Equilibrado', d: 'recomendado' },
  { v: 'potente',     t: 'Potente',     d: 'el más capaz, algo más caro' },
]

export default function AiSettings() {
  const toast = useToast()
  const confirm = useConfirm()
  const [s, setS] = useState(null)          // ajustes cargados
  const [def, setDef] = useState('')        // personalidad por defecto
  const [nivelWa, setNivelWa] = useState('ninguno')
  const [keySet, setKeySet] = useState(false)
  const [keyHint, setKeyHint] = useState('')
  const [keyInput, setKeyInput] = useState('')   // clave nueva a escribir (vacío = no cambiar)
  const [saving, setSaving] = useState(false)

  const load = useCallback(() => {
    api.aiSettings().then((d) => {
      setS(d.settings)
      setDef(d.personalidad_def || '')
      setNivelWa(d.nivel_wa || 'ninguno')
      setKeySet(!!d.settings.api_key_set)
      setKeyHint(d.settings.api_key_hint || '')
      setKeyInput('')
    })
  }, [])
  useEffect(() => { load() }, [load])

  const set = (k, v) => setS((o) => ({ ...o, [k]: v }))

  const locked = !keySet && !keyInput.trim()   // sin clave (ni recién escrita) → inactivo

  const save = async () => {
    setSaving(true)
    const payload = { ...s }
    // La clave solo viaja si el usuario escribió una nueva (vacío = no tocar).
    if (keyInput.trim()) payload.ia_api_key = keyInput.trim()
    else delete payload.ia_api_key
    const r = await api.saveAiSettings(payload)
    setSaving(false)
    if (r.ok) { toast('Ajustes del agente guardados'); load() }
    else toast(r.error || 'Error al guardar', 'err')
  }

  const quitarClave = async () => {
    if (!(await confirm({ title: 'Quitar la clave', message: 'El agente de IA quedará INACTIVO hasta que vuelvas a poner una clave. ¿Continuar?', danger: true, confirmText: 'Quitar clave' }))) return
    const r = await api.saveAiSettings({ ia_api_key: '__CLEAR__', ia_activa: false })
    if (r.ok) { toast('Clave eliminada — agente inactivo'); load() } else toast(r.error || 'Error', 'err')
  }

  const restaurarPersonalidad = async () => {
    if (!(await confirm({ title: 'Restaurar personalidad', message: 'Se descartará tu texto y volverá el de fábrica. ¿Seguro?', confirmText: 'Restaurar' }))) return
    set('ia_personalidad', def)
  }

  if (!s) return <div className="card"><div className="center-load"><div className="spinner" /></div></div>

  return (
    <div className="card">
      <div className="wa-num-head">
        <div>
          <h2>Agente de IA <span className="hint" style={{ fontWeight: 400 }}>· Claude en el WhatsApp de soporte</span></h2>
          <p className="desc" style={{ margin: '2px 0 0' }}>Redacta respuestas a los clientes usando tus FAQs, el historial del cliente y tus plantillas. De momento en <b>modo borrador</b>: propone, el agente revisa y envía.</p>
        </div>
        <span className={`pill ${locked ? 'gray' : (s.ia_activa ? 'ok' : 'warn')}`}>
          <span className="dot" />{locked ? 'Sin clave' : (s.ia_activa ? 'Activo' : 'En pausa')}
        </span>
      </div>

      {/* Banner de estado del candado */}
      <div className={`wn-banner ${locked ? 'warn' : 'ok'}`} style={{ marginBottom: 14 }}>
        <Icon.lock />
        <div>
          <b>{locked ? 'Agente INACTIVO — falta la clave de API' : 'Clave de API configurada'}</b>
          <span>{locked
            ? 'Pega tu clave de Anthropic (platform.anthropic.com) más abajo para activarlo. Sin clave, el agente no responde.'
            : 'El agente ya puede pensar. Recuerda que hasta el Bloque 2 solo se está guardando la configuración.'}</span>
        </div>
      </div>

      {nivelWa === 'ninguno' && (
        <div className="wn-banner warn" style={{ marginBottom: 14 }}>
          <Icon.message />
          <div>
            <b>No hay número de WhatsApp de Soporte</b>
            <span>El agente necesita un número de Soporte para escuchar. Da de alta uno en «Números de WhatsApp» (arriba).</span>
          </div>
        </div>
      )}

      {/* Clave de API */}
      <label className="field">
        <span className="lbl">Clave de API de Anthropic {keySet ? <span className="hint">(guardada {keyHint} · vacío = no cambiar)</span> : <em>*</em>} <InfoTip text="La consigues en platform.anthropic.com → API Keys. Se guarda en tu servidor y nunca se vuelve a mostrar entera. El coste de la IA se factura en esa cuenta." wide /></span>
        <input className="mono" type="password" autoComplete="off" value={keyInput}
          onChange={(e) => setKeyInput(e.target.value)}
          placeholder={keySet ? '••••••••  (déjalo vacío para conservar la actual)' : 'sk-ant-…'} />
        {keySet && <button type="button" className="btn ghost sm" style={{ marginTop: 8, alignSelf: 'flex-start', color: 'var(--danger)' }} onClick={quitarClave}>Quitar clave</button>}
      </label>

      <div className="grid2">
        {/* Modelo */}
        <label className="field"><span className="lbl">Modelo <InfoTip text="Qué versión de Claude usa. «Equilibrado» va bien para soporte; «Potente» razona mejor casos raros pero cuesta más." /></span>
          <select value={s.ia_modelo} onChange={(e) => set('ia_modelo', e.target.value)}>
            {MODELOS.map((m) => <option key={m.v} value={m.v}>{m.t} — {m.d}</option>)}
          </select></label>

        {/* Tope diario */}
        <label className="field"><span className="lbl">Tope de respuestas al día <InfoTip text="Freno de coste: el agente no generará más de estas respuestas por día. 0 = sin límite." /></span>
          <input type="number" min={0} value={s.ia_tope_dia} onChange={(e) => set('ia_tope_dia', e.target.value)} /></label>
      </div>

      {/* Modo */}
      <div className="field">
        <span className="lbl">Modo de trabajo <InfoTip text="«Borrador» = la IA propone y un agente revisa y envía (seguro). «Auto» = la IA responde sola al cliente. El envío automático se activará en un bloque posterior." wide /></span>
        <div className="ai-modo">
          <label className={`ai-radio ${s.ia_modo === 'borrador' ? 'on' : ''}`}>
            <input type="radio" name="ia_modo" checked={s.ia_modo === 'borrador'} onChange={() => set('ia_modo', 'borrador')} />
            <b>Borrador</b><span>propone, el agente envía</span>
          </label>
          <label className={`ai-radio ${s.ia_modo === 'auto' ? 'on' : ''}`}>
            <input type="radio" name="ia_modo" checked={s.ia_modo === 'auto'} onChange={() => set('ia_modo', 'auto')} />
            <b>Auto <span className="hint">(próximamente)</span></b><span>responde solo</span>
          </label>
        </div>
      </div>

      {/* Solo en turno */}
      <label className="fb-req-row" style={{ marginTop: 4, marginBottom: 12 }}>
        <span className="fb-switch"><input type="checkbox" checked={s.ia_solo_en_turno} onChange={(e) => set('ia_solo_en_turno', e.target.checked)} /><span className={`fb-toggle ${s.ia_solo_en_turno ? 'on' : ''}`} /></span>
        <span className="fb-req-label">Trabajar solo dentro del horario del agente de guardia <span className="hint">· fuera de horario no propone (a futuro se podrá invertir)</span></span>
      </label>

      {/* Personalidad */}
      <label className="field">
        <span className="lbl">Personalidad e instrucciones <InfoTip text="Es el «cómo debe comportarse» del agente: tono, qué puede prometer, líneas rojas. Sale de la entrevista de AEME; edítalo a tu gusto." wide /></span>
        <textarea rows={14} value={s.ia_personalidad} onChange={(e) => set('ia_personalidad', e.target.value)} style={{ lineHeight: 1.5 }} />
        <button type="button" className="btn ghost sm" style={{ marginTop: 8, alignSelf: 'flex-start' }} onClick={restaurarPersonalidad}>Restaurar el de fábrica</button>
      </label>

      {/* Interruptor maestro + guardar */}
      <div className="ai-foot">
        <label className="fb-req-row" style={{ margin: 0 }}>
          <span className="fb-switch"><input type="checkbox" disabled={locked} checked={!!s.ia_activa && !locked} onChange={(e) => set('ia_activa', e.target.checked)} /><span className={`fb-toggle ${s.ia_activa && !locked ? 'on' : ''}`} /></span>
          <span className="fb-req-label">Agente activado {locked && <span className="hint">· pon una clave para poder activarlo</span>}</span>
        </label>
        <div className="spacer" />
        <button className="btn" disabled={saving} onClick={save}><Icon.save /> {saving ? 'Guardando…' : 'Guardar cambios'}</button>
      </div>
    </div>
  )
}
