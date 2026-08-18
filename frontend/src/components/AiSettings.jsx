import { useState, useEffect, useCallback } from 'react'
import { api } from '../api.js'
import { Icon } from '../icons.jsx'
import { useToast, useConfirm } from '../App.jsx'
import InfoTip from './InfoTip.jsx'

/* -------------------------------------------------------------------------
 * AGENTE DE IA del WhatsApp de soporte. DOS piezas INDEPENDIENTES, cada una
 * con su propio guardar y su propio estado:
 *   1) Agente de TEXTO (Claude) — necesita clave de Anthropic.
 *   2) Lector de FOTOS (soporteQA) — endpoint externo, con su propia clave.
 * Una puede estar activa sin la otra.
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
  const [keyInput, setKeyInput] = useState('')     // clave de Claude a escribir (vacío = no cambiar)
  const [sqKeyInput, setSqKeyInput] = useState('')  // clave de soporteQA a escribir
  const [savingA, setSavingA] = useState(false)    // guardando agente de texto
  const [savingB, setSavingB] = useState(false)    // guardando lector de fotos

  const load = useCallback(() => {
    api.aiSettings().then((d) => {
      setS(d.settings)
      setDef(d.personalidad_def || '')
      setNivelWa(d.nivel_wa || 'ninguno')
      setKeyInput('')
      setSqKeyInput('')
    })
  }, [])
  useEffect(() => { load() }, [load])

  const set = (k, v) => setS((o) => ({ ...o, [k]: v }))

  // Estado de cada pieza.
  const keySet = !!s?.api_key_set
  const locked = !keySet && !keyInput.trim()                          // agente de texto sin clave
  const sqKeySet = !!s?.soporteqa_key_set || !!sqKeyInput.trim()
  const sqOn = !!s?.soporteqa_activo && sqKeySet                       // lector de fotos activo

  // --- Guardar SOLO el agente de texto (Claude) ---
  const guardarTexto = async () => {
    setSavingA(true)
    const payload = {
      ia_activa: !!s.ia_activa && !locked,
      ia_modelo: s.ia_modelo,
      ia_modo: s.ia_modo,
      ia_personalidad: s.ia_personalidad,
      ia_solo_en_turno: !!s.ia_solo_en_turno,
      ia_tope_dia: s.ia_tope_dia,
    }
    if (keyInput.trim()) payload.ia_api_key = keyInput.trim()
    const r = await api.saveAiSettings(payload)
    setSavingA(false)
    if (r.ok) { toast('Agente de texto guardado'); load() } else toast(r.error || 'Error al guardar', 'err')
  }

  // --- Guardar SOLO el lector de fotos (soporteQA) ---
  const guardarSq = async () => {
    setSavingB(true)
    const payload = { soporteqa_activo: !!s.soporteqa_activo, soporteqa_url: s.soporteqa_url || '' }
    if (sqKeyInput.trim()) payload.soporteqa_key = sqKeyInput.trim()
    const r = await api.saveAiSettings(payload)
    setSavingB(false)
    if (r.ok) { toast('Lector de fotos guardado'); load() } else toast(r.error || 'Error al guardar', 'err')
  }

  const quitarClave = async () => {
    if (!(await confirm({ title: 'Quitar la clave', message: 'El agente de texto quedará INACTIVO hasta que vuelvas a poner una clave. ¿Continuar?', danger: true, confirmText: 'Quitar clave' }))) return
    const r = await api.saveAiSettings({ ia_api_key: '__CLEAR__', ia_activa: false })
    if (r.ok) { toast('Clave eliminada — agente de texto inactivo'); load() } else toast(r.error || 'Error', 'err')
  }

  const quitarClaveSq = async () => {
    if (!(await confirm({ title: 'Quitar la clave de soporteQA', message: 'El lector de fotos quedará inactivo. ¿Continuar?', danger: true, confirmText: 'Quitar clave' }))) return
    const r = await api.saveAiSettings({ soporteqa_key: '__CLEAR__', soporteqa_activo: false })
    if (r.ok) { toast('Clave de soporteQA eliminada'); load() } else toast(r.error || 'Error', 'err')
  }

  const restaurarPersonalidad = async () => {
    if (!(await confirm({ title: 'Restaurar personalidad', message: 'Se descartará tu texto y volverá el de fábrica. ¿Seguro?', confirmText: 'Restaurar' }))) return
    set('ia_personalidad', def)
  }

  if (!s) return <div className="card"><div className="center-load"><div className="spinner" /></div></div>

  return (
    <>
      {/* ============ 1) LECTOR DE FOTOS · soporteQA (lo primero: es lo que se usa) ============ */}
      <div className="card">
        <div className="wa-num-head">
          <div>
            <h2>Lector de fotos <span className="hint" style={{ fontWeight: 400 }}>· soporteQA (etiquetas)</span></h2>
            <p className="desc" style={{ margin: '2px 0 0' }}>
              Cuando un cliente manda una <b>foto</b> de una etiqueta, o hace una pregunta, el borrador lo genera <b>soporteQA</b> (lee el código de barras y responde con las FAQ de AEME). Funciona <b>por su cuenta</b>, no necesita el agente de texto de abajo.
            </p>
          </div>
          <span className={`pill ${sqOn ? 'ok' : 'gray'}`}><span className="dot" />{sqOn ? 'Activo' : 'Inactivo'}</span>
        </div>

        <label className="fb-req-row" style={{ marginBottom: 14 }}>
          <span className="fb-switch"><input type="checkbox" checked={!!s.soporteqa_activo} onChange={(e) => set('soporteqa_activo', e.target.checked)} /><span className={`fb-toggle ${s.soporteqa_activo ? 'on' : ''}`} /></span>
          <span className="fb-req-label">Usar soporteQA <span className="hint">· necesita la clave de abajo</span></span>
        </label>

        <label className="field">
          <span className="lbl">URL del endpoint <InfoTip text="La función de Base44. Por defecto la de AEME; solo cámbiala si te dan otra." /></span>
          <input className="mono" value={s.soporteqa_url || ''} onChange={(e) => set('soporteqa_url', e.target.value)} placeholder="https://…/functions/soporteQA" />
        </label>

        <label className="field" style={{ marginBottom: 0 }}>
          <span className="lbl">Clave (x-api-key) {s.soporteqa_key_set && <span className="hint">(guardada {s.soporteqa_key_hint} · vacío = no cambiar)</span>} <InfoTip text="La clave del endpoint soporteQA. Se guarda en tu servidor y nunca se muestra entera." /></span>
          <input className="mono" type="password" autoComplete="off" value={sqKeyInput} onChange={(e) => setSqKeyInput(e.target.value)} placeholder={s.soporteqa_key_set ? '••••••••  (vacío = conservar)' : 'clave del endpoint'} />
          {s.soporteqa_key_set && <button type="button" className="btn ghost sm" style={{ marginTop: 8, alignSelf: 'flex-start', color: 'var(--danger)' }} onClick={quitarClaveSq}>Quitar clave</button>}
        </label>

        <div className="ai-foot">
          {s.soporteqa_activo && !sqKeySet && <span className="hint" style={{ color: 'var(--warn)' }}>Pon una clave para que quede activo.</span>}
          <div className="spacer" />
          <button className="btn" disabled={savingB} onClick={guardarSq}><Icon.save /> {savingB ? 'Guardando…' : 'Guardar lector de fotos'}</button>
        </div>
      </div>

      {/* ============ 2) AGENTE DE TEXTO · Claude ============ */}
      <div className="card" style={{ marginTop: 14 }}>
        <div className="wa-num-head">
          <div>
            <h2>Agente de texto <span className="hint" style={{ fontWeight: 400 }}>· Claude (opcional)</span></h2>
            <p className="desc" style={{ margin: '2px 0 0' }}>
              Redacta respuestas de texto con tus FAQs, el historial del cliente y tus plantillas. Necesita una clave de <b>Anthropic</b> (distinta de la de soporteQA). En <b>modo borrador</b>: propone, el agente revisa y envía.
            </p>
          </div>
          <span className={`pill ${locked ? 'gray' : (s.ia_activa ? 'ok' : 'warn')}`}>
            <span className="dot" />{locked ? 'Sin clave' : (s.ia_activa ? 'Activo' : 'En pausa')}
          </span>
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

        <label className="field">
          <span className="lbl">Clave de API de Anthropic {keySet ? <span className="hint">(guardada {s.api_key_hint} · vacío = no cambiar)</span> : <span className="hint">· opcional</span>} <InfoTip text="La consigues en platform.anthropic.com → API Keys. Se guarda en tu servidor y nunca se vuelve a mostrar entera. El coste se factura en esa cuenta. Sin ella, el agente de texto no funciona (pero el lector de fotos sí)." wide /></span>
          <input className="mono" type="password" autoComplete="off" value={keyInput}
            onChange={(e) => setKeyInput(e.target.value)}
            placeholder={keySet ? '••••••••  (déjalo vacío para conservar la actual)' : 'sk-ant-…'} />
          {keySet && <button type="button" className="btn ghost sm" style={{ marginTop: 8, alignSelf: 'flex-start', color: 'var(--danger)' }} onClick={quitarClave}>Quitar clave</button>}
        </label>

        <div className="grid2">
          <label className="field"><span className="lbl">Modelo <InfoTip text="Qué versión de Claude usa. «Equilibrado» va bien para soporte; «Potente» razona mejor casos raros pero cuesta más." /></span>
            <select value={s.ia_modelo} onChange={(e) => set('ia_modelo', e.target.value)}>
              {MODELOS.map((m) => <option key={m.v} value={m.v}>{m.t} — {m.d}</option>)}
            </select></label>

          <label className="field"><span className="lbl">Tope de respuestas al día <InfoTip text="Freno de coste: el agente no generará más de estas respuestas por día. 0 = sin límite." /></span>
            <input type="number" min={0} value={s.ia_tope_dia} onChange={(e) => set('ia_tope_dia', e.target.value)} /></label>
        </div>

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

        <label className="fb-req-row" style={{ marginTop: 4, marginBottom: 12 }}>
          <span className="fb-switch"><input type="checkbox" checked={s.ia_solo_en_turno} onChange={(e) => set('ia_solo_en_turno', e.target.checked)} /><span className={`fb-toggle ${s.ia_solo_en_turno ? 'on' : ''}`} /></span>
          <span className="fb-req-label">Trabajar solo dentro del horario del agente de guardia <span className="hint">· fuera de horario no propone</span></span>
        </label>

        <label className="field">
          <span className="lbl">Personalidad e instrucciones <InfoTip text="El «cómo debe comportarse» del agente de texto: tono, qué puede prometer, líneas rojas. Sale de la entrevista de AEME; edítalo a tu gusto." wide /></span>
          <textarea rows={12} value={s.ia_personalidad} onChange={(e) => set('ia_personalidad', e.target.value)} style={{ lineHeight: 1.5 }} />
          <button type="button" className="btn ghost sm" style={{ marginTop: 8, alignSelf: 'flex-start' }} onClick={restaurarPersonalidad}>Restaurar el de fábrica</button>
        </label>

        <div className="ai-foot">
          <label className="fb-req-row" style={{ margin: 0 }}>
            <span className="fb-switch"><input type="checkbox" disabled={locked} checked={!!s.ia_activa && !locked} onChange={(e) => set('ia_activa', e.target.checked)} /><span className={`fb-toggle ${s.ia_activa && !locked ? 'on' : ''}`} /></span>
            <span className="fb-req-label">Agente de texto activado {locked && <span className="hint">· pon la clave de Anthropic (el lector de fotos no la necesita)</span>}</span>
          </label>
          <div className="spacer" />
          <button className="btn" disabled={savingA} onClick={guardarTexto}><Icon.save /> {savingA ? 'Guardando…' : 'Guardar agente de texto'}</button>
        </div>
      </div>
    </>
  )
}
