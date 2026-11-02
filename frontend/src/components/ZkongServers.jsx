import { useState, useEffect, useCallback } from 'react'
import { api } from '../api.js'
import { Icon } from '../icons.jsx'
import { useToast, useConfirm } from '../App.jsx'
import InfoTip from './InfoTip.jsx'

/* -------------------------------------------------------------------------
 * SERVIDORES ZKONG (Gestión de garantías → Ajustes). Solo superadmin.
 * Cada servidor es una cuenta de ADMINISTRADOR de la nube de etiquetas: con ella
 * el Centro de dispositivos lista y busca etiquetas y antenas. La contraseña se
 * guarda cifrada y no vuelve nunca al navegador; «Probar» dice si entra y cuánto tarda.
 * ---------------------------------------------------------------------- */

const blank = { id: 0, name: '', base_url: 'http://', username: '', password: '', active: true }
const num = (n) => Number(n || 0).toLocaleString('es-ES')
const aFecha = (v) => (v ? new Date(String(v).replace(' ', 'T')) : null)
const hace = (v) => {
  const d = aFecha(v)
  if (!d || isNaN(d)) return null
  const m = Math.max(0, Math.round((Date.now() - d) / 60000))
  if (m < 1) return 'hace un momento'
  if (m < 60) return `hace ${m} min`
  const h = Math.round(m / 60)
  if (h < 24) return `hace ${h} ${h === 1 ? 'hora' : 'horas'}`
  const dd = Math.round(h / 24)
  return `hace ${dd} ${dd === 1 ? 'día' : 'días'}`
}
const fechaHora = (v) => { const d = aFecha(v); return d && !isNaN(d) ? d.toLocaleString('es-ES', { day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit' }) : '' }

function Totales({ s, t }) {
  if (!s.active) return <><td className="r"><span className="dev-mute">—</span></td><td className="r"><span className="dev-mute">—</span></td></>
  if (!t || t.loading) return <><td className="r"><span className="zs-load" /></td><td className="r"><span className="zs-load" /></td></>
  if (t.error) return <td className="r" colSpan={2}><span className="dev-mute" title={t.error}>No se pudo contar</span></td>
  const e = t.esl
  return (
    <>
      <td className="r">
        <b className="wt-num">{num(e.total)}</b>
        <small className="zk-sub">{e.online != null ? `${num(e.online)} en línea · ${num(e.offline)} sin conexión` : ''}</small>
      </td>
      <td className="r">
        {t.ap ? <><b className="wt-num">{num(t.ap.total)}</b><small className="zk-sub">{t.ap.online != null ? `${num(t.ap.online)} en línea` : ''}</small></> : <span className="dev-mute">—</span>}
      </td>
    </>
  )
}

export default function ZkongServers() {
  const toast = useToast()
  const confirm = useConfirm()
  const [rows, setRows] = useState(null)
  const [form, setForm] = useState(null)
  const [saving, setSaving] = useState(false)
  const [testing, setTesting] = useState([])       // ids probándose ahora
  const [pruebas, setPruebas] = useState({})       // id → { ok, ms, error } de esta sesión

  const [totales, setTotales] = useState({})       // id → { esl, ap } leídos del propio servidor, o { error }

  const load = useCallback(() => { api.zkongServers().then((d) => setRows(d.servers || [])) }, [])
  useEffect(() => { load() }, [load])

  // Cuántas etiquetas y antenas tiene cada servidor activo, preguntándoselo a él (caché de 2 min).
  const contar = useCallback((lista) => {
    lista.filter((s) => s.active).forEach((s) => {
      setTotales((t) => ({ ...t, [s.id]: { loading: true } }))
      Promise.all([api.deviceStats(s.id, 'esl'), api.deviceStats(s.id, 'ap')])
        .then(([e, a]) => setTotales((t) => ({ ...t, [s.id]: e.ok ? { esl: e.stats, ap: a.ok ? a.stats : null } : { error: e.error || 'No responde' } })))
        .catch(() => setTotales((t) => ({ ...t, [s.id]: { error: 'No responde' } })))
    })
  }, [])
  const ids = (rows || []).filter((s) => s.active).map((s) => s.id).join(',')
  useEffect(() => { if (rows) contar(rows) }, [ids])   // eslint-disable-line react-hooks/exhaustive-deps

  const abrir = (s) => setForm(s ? { ...blank, ...s, password: '' } : { ...blank })

  const save = async () => {
    if (!form.name.trim() || !form.username.trim()) { toast('Pon un nombre y el usuario', 'err'); return }
    if (!form.id && !form.password) { toast('Falta la contraseña', 'err'); return }
    setSaving(true)
    const payload = { ...form }
    if (form.id && !form.password) delete payload.password
    const r = await api.saveZkongServer(payload)
    setSaving(false)
    if (r.ok) {
      toast(form.id ? 'Servidor actualizado' : 'Servidor añadido')
      setPruebas((p) => { const x = { ...p }; delete x[form.id]; return x })
      setForm(null); load()
    } else toast(r.error || 'Error', 'err')
  }

  const del = async (s) => {
    if (!(await confirm({ title: 'Quitar servidor', message: `¿Quitar «${s.name}»? El Centro de dispositivos dejará de consultarlo.`, danger: true, confirmText: 'Quitar' }))) return
    const r = await api.deleteZkongServer(s.id)
    if (r.ok) { toast('Servidor quitado'); load() } else toast(r.error || 'Error', 'err')
  }

  const probarUno = async (s) => {
    setTesting((t) => [...t, s.id])
    const r = await api.testZkongServer(s.id).catch(() => ({ ok: false, error: 'No se pudo conectar' }))
    setTesting((t) => t.filter((x) => x !== s.id))
    setPruebas((p) => ({ ...p, [s.id]: { ok: !!r.ok, ms: r.ms ?? null, error: r.error || null } }))
    return r.ok
  }
  const probar = async (s) => {
    const ok = await probarUno(s)
    toast(ok ? `${s.name}: conecta` : `${s.name}: no conecta`, ok ? undefined : 'err')
    load()
  }
  const probarTodos = async () => {
    const activos = (rows || []).filter((s) => s.active)
    const res = await Promise.all(activos.map(probarUno))
    const mal = res.filter((x) => !x).length
    toast(mal ? `${mal} de ${activos.length} no conectan` : `Los ${activos.length} servidores conectan`, mal ? 'err' : undefined)
    load()
  }

  const lista = rows || []
  const activos = lista.filter((s) => s.active)
  const conectan = activos.filter((s) => (pruebas[s.id] ? pruebas[s.id].ok : !!s.token_updated_at))
  const fallan = activos.filter((s) => pruebas[s.id] && !pruebas[s.id].ok)
  const leidos = activos.map((s) => totales[s.id]).filter((t) => t && t.esl)
  const cargandoTot = activos.some((s) => !totales[s.id] || totales[s.id].loading)
  const suma = (k, campo) => leidos.reduce((n, t) => n + ((t[k] && t[k][campo]) || 0), 0)
  const etiquetas = suma('esl', 'total')
  const enLinea = suma('esl', 'online')
  const antenas = suma('ap', 'total')
  const ultima = lista.map((s) => s.token_updated_at).filter(Boolean).sort().pop()
  const tiempos = Object.values(pruebas).filter((p) => p.ok && p.ms != null).map((p) => p.ms)

  const estado = (s) => {
    const p = pruebas[s.id]
    if (testing.includes(s.id)) return <span className="zs-st wait"><i />Probando…</span>
    if (!s.active) return <span className="zs-st off"><i />Inactivo<small>El Centro de dispositivos no lo consulta</small></span>
    if (p && !p.ok) return <span className="zs-st bad" title={p.error}><i />No conecta<small>{p.error}</small></span>
    if (p) return <span className="zs-st ok"><i />Conecta<small>Ahora · respondió en {num(p.ms)} ms</small></span>
    if (s.token_updated_at) return <span className="zs-st ok" title={`Última entrada correcta: ${fechaHora(s.token_updated_at)}`}><i />Conectado<small>Última entrada {hace(s.token_updated_at)}</small></span>
    return <span className="zs-st off"><i />Sin probar<small>Pulsa «Probar» para comprobar el acceso</small></span>
  }

  return (
    <>
      <header className="page-head">
        <span className="sc-ic"><Icon.server style={{ width: 18, height: 18, fill: 'var(--primary)' }} /></span>
        <div><h1>Servidores ZKONG</h1></div>
        <span className="sub">· Cuentas que usa el Centro de dispositivos para ver etiquetas y antenas</span>
      </header>

      <div className="page-scroll"><div className="page dev-page wr-page">
        {rows === null ? <div className="center-load"><div className="spinner" /></div> : (
          <>
            <div className="wi-kpis">
              <div className="wi-kpi">
                <div className="v">{num(activos.length)}<span className="wi-de">{activos.length === 1 ? 'activo' : 'activos'}</span></div>
                <div className="k">Servidores</div>
                <div className="h">{lista.length - activos.length ? `${lista.length - activos.length} desactivado${lista.length - activos.length === 1 ? '' : 's'}` : 'Todos se consultan'}</div>
              </div>
              <div className="wi-kpi">
                <div className="v">{num(conectan.length)}<span className="wi-de">de {num(activos.length)}</span></div>
                <div className="k">Con acceso</div>
                <div className="h">{fallan.length ? <span className="zk-warn-t">{fallan.length} no {fallan.length === 1 ? 'conecta' : 'conectan'} ahora mismo</span> : ultima ? `Última entrada correcta ${hace(ultima)}` : 'Ninguno probado todavía'}</div>
              </div>
              <div className="wi-kpi">
                <div className="v">{tiempos.length ? num(Math.round(tiempos.reduce((a, b) => a + b, 0) / tiempos.length)) : '—'}{tiempos.length ? <span className="wi-de">ms</span> : null}</div>
                <div className="k">Tiempo de respuesta</div>
                <div className="h">{tiempos.length ? 'Media de las pruebas de ahora (entrar en la cuenta)' : 'Pulsa «Probar todos» para medirlo'}</div>
              </div>
              <div className="wi-kpi">
                <div className="v">{leidos.length ? num(etiquetas) : '—'}{leidos.length ? <span className="wi-de">etiquetas</span> : null}</div>
                <div className="k">En los servidores</div>
                <div className="h">{cargandoTot ? 'Preguntando a los servidores…' : leidos.length
                  ? <>{num(enLinea)} en línea ({etiquetas ? Math.round((enLinea / etiquetas) * 100) : 0} %) · {num(antenas)} antenas{leidos.length < activos.length ? <span className="zk-warn-t"> · falta {activos.length - leidos.length} servidor</span> : null}</>
                  : 'Ningún servidor ha respondido'}</div>
              </div>
            </div>

            <div className="dev-card zk-bandeja">
              <div className="zk-bar">
                <span className="zs-bar-t">{lista.length === 1 ? '1 servidor' : `${lista.length} servidores`}</span>
                <span className="spacer" />
                <button className="dev-btn" disabled={!activos.length || testing.length > 0} onClick={probarTodos} title="Entra en cada servidor activo con su cuenta"><Icon.refresh /> {testing.length ? 'Probando…' : 'Probar todos'}</button>
                <button className="btn" onClick={() => abrir(null)}><Icon.plus /> Añadir servidor</button>
              </div>
              <div className="dev-table-wrap keep">
                <table className="dev-table zs-t">
                  <thead><tr>
                    <th>Servidor</th>
                    <th>Dirección</th>
                    <th>Usuario</th>
                    <th>Estado de la conexión</th>
                    <th className="r" title="Etiquetas registradas en el servidor (en línea / sin conexión)">Etiquetas</th>
                    <th className="r" title="Antenas (puntos de acceso) registradas en el servidor">Antenas</th>
                    <th />
                  </tr></thead>
                  <tbody>
                    {lista.map((s) => (
                      <tr key={s.id} className={s.active ? '' : 'zs-off'}>
                        <td><span className="zs-name"><span className="zs-ic"><Icon.server /></span><span><b>{s.name}</b><small className="zk-sub">{s.updated_at ? `Editado el ${fechaHora(s.updated_at)}` : ''}</small></span></span></td>
                        <td><code className="zs-url">{s.base_url}</code></td>
                        <td>{s.username}<small className="zk-sub zs-pass" title="Se guarda cifrada y no vuelve nunca al navegador"><Icon.lock /> Contraseña guardada cifrada</small></td>
                        <td>{estado(s)}</td>
                        <Totales s={s} t={totales[s.id]} />
                        <td className="zs-acts">
                          <button className="ws-txt" disabled={testing.includes(s.id) || !s.active} onClick={() => probar(s)} title={s.active ? 'Entrar con la cuenta para comprobar que funciona' : 'Actívalo para probarlo'}>Probar</button>
                          <button className="ws-txt" onClick={() => abrir(s)}>Editar</button>
                          <button className="ws-txt danger" onClick={() => del(s)}>Quitar</button>
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
                {!lista.length && (
                  <div className="act-empty"><Icon.server style={{ width: 30, height: 30, fill: 'var(--ink-3)' }} />
                    <p>Aún no hay servidores. Añade los de etiquetas (por ejemplo <b>APP01</b> y <b>APP02</b>) para ver sus dispositivos.</p></div>
                )}
              </div>
              <div className="zk-pie">
                <span className="dev-mute"><Icon.lock style={{ width: 13, height: 13, fill: 'currentColor', verticalAlign: '-2px' }} /> Una cuenta de administrador por servidor. Cambiar la dirección, el usuario o la contraseña obliga a volver a entrar.</span>
              </div>
            </div>
          </>
        )}
      </div></div>

      {form && (
        <div className="modal-bg" onMouseDown={(e) => e.target.classList.contains('modal-bg') && setForm(null)}>
          <div className="modal" style={{ maxWidth: 540 }}>
            <div className="modal-head">
              <h3>{form.id ? 'Editar servidor' : 'Añadir servidor ZKONG'}</h3>
              <button className="x" onClick={() => setForm(null)}>×</button>
            </div>
            <div className="modal-body">
              <div className="grid2">
                <label className="field"><span className="lbl">Nombre <em>*</em> <InfoTip text="Cómo se verá en el Centro de dispositivos, p. ej. «APP02»." /></span>
                  <input value={form.name} onChange={(e) => setForm((f) => ({ ...f, name: e.target.value }))} placeholder="APP02" autoFocus /></label>
                <label className="field"><span className="lbl">Usuario <em>*</em></span>
                  <input value={form.username} onChange={(e) => setForm((f) => ({ ...f, username: e.target.value }))} autoComplete="off" /></label>
              </div>
              <label className="field"><span className="lbl">Dirección del servidor <em>*</em> <InfoTip text="La dirección base de la API, con su puerto. La misma que tiene la cuenta en la app de garantías." wide /></span>
                <input className="mono" value={form.base_url} onChange={(e) => setForm((f) => ({ ...f, base_url: e.target.value }))} placeholder="http://app02.etiquetaselectronicas.com:9999" /></label>
              <label className="field"><span className="lbl">Contraseña {form.id ? <span className="hint">(vacío = no cambiar)</span> : <em>*</em>}</span>
                <input type="password" value={form.password} onChange={(e) => setForm((f) => ({ ...f, password: e.target.value }))} placeholder={form.id ? '••••••••' : ''} autoComplete="new-password" /></label>
              <label className="fb-req-row" style={{ marginTop: 2 }}>
                <span className="fb-switch"><input type="checkbox" checked={form.active} onChange={(e) => setForm((f) => ({ ...f, active: e.target.checked }))} /><span className={`fb-toggle ${form.active ? 'on' : ''}`} /></span>
                <span className="fb-req-label">Activo <span className="hint">· si no, el Centro de dispositivos no lo consulta</span></span>
              </label>
            </div>
            <div className="modal-foot">
              <button className="btn ghost" onClick={() => setForm(null)}>Cancelar</button>
              <button className="btn" disabled={saving} onClick={save}>{saving ? 'Guardando…' : (form.id ? 'Guardar' : 'Añadir')}</button>
            </div>
          </div>
        </div>
      )}
    </>
  )
}
