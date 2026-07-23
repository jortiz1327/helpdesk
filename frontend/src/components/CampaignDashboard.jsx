import { useState, useEffect, useCallback } from 'react'
import { api } from '../api.js'
import { Icon } from '../icons.jsx'
import { useToast, useConfirm } from '../App.jsx'
import LockTip from './LockTip.jsx'

const STATUS = {
  draft:     { t: 'Borrador', c: 'gray' },
  scheduled: { t: 'Programada', c: 'warn' },
  sending:   { t: 'Enviando', c: 'warn' },
  sent:      { t: 'Enviada', c: 'ok' },
  failed:    { t: 'Con errores', c: 'err' },
  canceled:  { t: 'Cancelada', c: 'gray' },
}
// Estado por destinatario (lo actualiza el webhook de Meta)
const RSTATUS = {
  pending:   { t: 'En cola', c: 'gray' },
  sent:      { t: 'Enviado', c: 'gray' },
  delivered: { t: 'Entregado', c: 'info' },
  read:      { t: 'Leído', c: 'ok' },
  failed:    { t: 'Fallido', c: 'err' },
}
const fmt = (s) => (s ? new Date(s.replace(' ', 'T')).toLocaleString('es-ES', { day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit' }) : '—')

// ---- Detalle (destinatarios) ----
function Detail({ id, onBack, onChange }) {
  const toast = useToast()
  const [c, setC] = useState(null)
  const [busy, setBusy] = useState(false)
  const load = useCallback(() => { api.getCampaign(id).then((d) => d.ok && setC(d.campaign)) }, [id])
  useEffect(() => { load() }, [load])

  const run = async () => {
    setBusy(true)
    const r = await api.runCampaign(id)
    setBusy(false)
    if (r.ok) { toast(`Procesados: ${r.sent} enviados, ${r.failed} fallidos, ${r.pending} en cola`); load(); onChange?.() }
    else toast(r.error || 'Error', 'err')
  }

  if (!c) return <div className="center-load"><div className="spinner" /></div>
  const st = STATUS[c.status] || STATUS.draft
  const cnt = (...s) => c.recipients.filter((r) => s.includes(r.status)).length
  const pending = cnt('pending')
  const delivered = cnt('delivered', 'read')
  const read = cnt('read')

  return (
    <>
      <header className="page-head">
        <button className="btn ghost sm" onClick={onBack}><Icon.send style={{ transform: 'rotate(180deg)' }} /> Volver</button>
        <div style={{ marginLeft: 8 }}><h1>{c.title}</h1></div>
        <span className={`pill ${st.c}`} style={{ marginLeft: 4 }}><span className="dot" />{st.t}</span>
        <div className="spacer" />
        {pending > 0 && <button className="btn" disabled={busy} onClick={run}><Icon.play /> Procesar pendientes</button>}
      </header>
      <div className="page-scroll">
        <div className="page" style={{ maxWidth: 980 }}>
          <div className="stat-grid" style={{ marginBottom: 16 }}>
            <div className="stat-card"><div className="stat-num" style={{ color: '#8696a0', marginTop: 0 }}>{c.total}</div><div className="stat-sub">Destinatarios</div></div>
            <div className="stat-card"><div className="stat-num" style={{ color: '#25d366', marginTop: 0 }}>{c.sent}</div><div className="stat-sub">Enviados</div></div>
            <div className="stat-card"><div className="stat-num" style={{ color: '#4a9bff', marginTop: 0 }}>{delivered}</div><div className="stat-sub">Entregados</div></div>
            <div className="stat-card"><div className="stat-num" style={{ color: '#00a884', marginTop: 0 }}>{read}</div><div className="stat-sub">Leídos</div></div>
            <div className="stat-card"><div className="stat-num" style={{ color: 'var(--danger)', marginTop: 0 }}>{c.failed}</div><div className="stat-sub">Fallidos</div></div>
            {pending > 0 && <div className="stat-card"><div className="stat-num" style={{ color: '#f4b740', marginTop: 0 }}>{pending}</div><div className="stat-sub">En cola</div></div>}
          </div>
          <div className="muted" style={{ fontSize: 12.5, marginBottom: 12 }}>Plantilla <b>{c.template_name}</b> · Destino <b>{c.source_name || '—'}</b> · {fmt(c.scheduled_at)}</div>
          <div className="card" style={{ padding: 0 }}>
            {c.recipients.map((r, i) => {
              const rs = RSTATUS[r.status] || RSTATUS.pending
              return (
                <div key={i} className="pb-row">
                  <span className="pb-avatar">{(r.name || r.wa_id).slice(0, 1).toUpperCase()}</span>
                  <div className="pb-meta"><b>{r.name || '—'}</b><span className="muted">+{r.wa_id}</span></div>
                  <span className={`pill sm ${rs.c}`} style={{ marginLeft: 'auto' }} title={r.error || ''}>{rs.t}</span>
                </div>
              )
            })}
          </div>
          {c.recipients.some((r) => r.status === 'failed') && (
            <p className="muted" style={{ fontSize: 12, marginTop: 10 }}>Pasa el ratón sobre «Fallido» para ver el motivo del error de Meta.</p>
          )}
        </div>
      </div>
    </>
  )
}

// ---- Seguridad de envíos: interruptor de pánico + tope diario (solo superadmin) ----
function SendSafety() {
  const toast = useToast()
  const [s, setS] = useState(null)
  const [cap, setCap] = useState('')

  useEffect(() => { api.getSettings().then((d) => { setS(d); setCap(String(d.daily_send_cap ?? 0)) }) }, [])
  if (!s) return null

  const togglePause = async () => {
    const next = !s.outbound_paused
    const r = await api.saveSettings({ outbound_paused: next })
    if (r.ok) { setS((v) => ({ ...v, outbound_paused: next })); toast(next ? '⏸ Envíos PAUSADOS' : '▶ Envíos reactivados') }
    else toast(r.error || 'Error', 'err')
  }
  const saveCap = async () => {
    const n = Math.max(0, parseInt(cap, 10) || 0)
    const r = await api.saveSettings({ daily_send_cap: n })
    if (r.ok) { setS((v) => ({ ...v, daily_send_cap: n })); toast('Tope diario guardado') }
    else toast(r.error || 'Error', 'err')
  }

  return (
    <div className={`card safety-card ${s.outbound_paused ? 'paused' : ''}`}>
      <div className="safety-h"><Icon.lock /> Seguridad de envíos</div>
      <div className="safety-row">
        <div className="safety-meta">
          <b>Pausar todos los envíos</b>
          <span className="muted">Interruptor de pánico: detiene campañas y difusiones al instante.</span>
        </div>
        <label className="fb-switch"><input type="checkbox" checked={s.outbound_paused} onChange={togglePause} /><span className={`fb-toggle ${s.outbound_paused ? 'on' : ''}`} /></label>
      </div>
      {s.outbound_paused && <div className="safety-warn"><Icon.warn /> Los envíos están <b>PAUSADOS</b>. No saldrá ninguna campaña hasta que lo reactives.</div>}
      <div className="safety-sep" />
      <div className="safety-row">
        <div className="safety-meta">
          <b>Tope de mensajes de pago por día</b>
          <span className="muted">Máximo de plantillas al día. Al alcanzarlo se detiene y continúa mañana. 0 = sin tope.</span>
        </div>
        <div style={{ display: 'flex', gap: 8, alignItems: 'center' }}>
          <input className="safety-cap" type="number" min="0" value={cap} onChange={(e) => setCap(e.target.value)} />
          <button className="btn sm" onClick={saveCap}><Icon.save /> Guardar</button>
        </div>
      </div>
      <div className="muted" style={{ fontSize: 12.5, marginTop: 6 }}>
        Hoy llevas <b style={{ color: 'var(--ink)' }}>{s.sent_today}</b> mensaje(s) de pago{s.daily_send_cap > 0 ? ` de ${s.daily_send_cap}` : ' · sin tope'}.
      </div>
    </div>
  )
}

// ---- Lista de campañas ----
export default function CampaignDashboard({ onNew, user }) {
  const toast = useToast()
  const confirm = useConfirm()
  const [list, setList] = useState(null)
  const [openId, setOpenId] = useState(null)
  const canManage = (user?.permissions || []).includes('settings.manage')

  const [gate, setGate] = useState(null)
  const load = useCallback(() => { api.listCampaigns().then((d) => setList(d.campaigns || [])) }, [])
  useEffect(() => { load() }, [load])
  useEffect(() => { api.gating().then((d) => setGate(d?.ok ? d : null)) }, [])
  const waLocked = gate?.features?.wa_campaign   // WhatsApp sin configurar

  const del = async (id) => {
    if (!(await confirm({ title: 'Eliminar campaña', message: '¿Eliminar esta campaña y su historial de envíos?', danger: true, confirmText: 'Eliminar' }))) return
    const r = await api.deleteCampaign(id); if (r.ok) { toast('Campaña eliminada'); load() }
  }
  const cancel = async (id) => {
    if (!(await confirm({ title: 'Cancelar campaña', message: '¿Detener los envíos pendientes de esta campaña?', danger: true, confirmText: 'Cancelar envío' }))) return
    const r = await api.cancelCampaign(id); if (r.ok) { toast('Campaña cancelada'); load() }
  }
  const run = async (id) => { const r = await api.runCampaign(id); if (r.ok) { toast(`Procesados: ${r.sent} enviados, ${r.failed} fallidos`); load() } }

  if (openId) return <Detail id={openId} onBack={() => { setOpenId(null); load() }} onChange={load} />

  const totals = (list || []).reduce((a, c) => ({ camp: a.camp + 1, sent: a.sent + (c.sent || 0), pend: a.pend + Math.max(0, (c.total || 0) - (c.sent || 0) - (c.failed || 0)) }), { camp: 0, sent: 0, pend: 0 })

  return (
    <>
      <header className="page-head">
        <span className="ic" style={{ width: 30, height: 30, borderRadius: 9, background: 'var(--primary-soft)', display: 'grid', placeItems: 'center' }}><Icon.dashboard style={{ width: 17, height: 17, fill: 'var(--primary)' }} /></span>
        <div><h1>Panel de campañas</h1></div>
        <span className="sub">· Historial y estado de tus difusiones</span>
        <div className="spacer" />
        {waLocked ? (
          <span className="gated-wrap">
            <button className="btn gated" disabled><Icon.lock /> Nueva campaña</button>
            <LockTip info={waLocked} />
          </span>
        ) : (
          <button className="btn" onClick={onNew}><Icon.plus /> Nueva campaña</button>
        )}
      </header>
      <div className="page-scroll">
        <div className="page" style={{ maxWidth: 1120 }}>
          <div className="stat-grid">
            <div className="stat-card"><div className="stat-num" style={{ color: '#00a884', marginTop: 0 }}>{totals.camp}</div><div className="stat-sub">Campañas</div></div>
            <div className="stat-card"><div className="stat-num" style={{ color: '#25d366', marginTop: 0 }}>{totals.sent}</div><div className="stat-sub">Mensajes enviados</div></div>
            <div className="stat-card"><div className="stat-num" style={{ color: '#f4b740', marginTop: 0 }}>{totals.pend}</div><div className="stat-sub">En cola</div></div>
          </div>

          {canManage && <SendSafety />}

          {list === null ? <div className="center-load"><div className="spinner" /></div> :
            list.length === 0 ? (
              <div className="empty"><div className="ico"><Icon.send /></div><p><b>Aún no hay campañas</b><br />Crea tu primera difusión para enviar plantillas a una agenda.</p>{waLocked ? <button className="btn gated" disabled><Icon.lock /> Nueva campaña</button> : <button className="btn" onClick={onNew}><Icon.plus /> Nueva campaña</button>}</div>
            ) : (
              <div className="card" style={{ padding: 0, marginTop: 16 }}>
                {list.map((c) => {
                  const st = STATUS[c.status] || STATUS.draft
                  const pending = Math.max(0, (c.total || 0) - (c.sent || 0) - (c.failed || 0))
                  const pct = c.total ? Math.round(((c.sent + c.failed) / c.total) * 100) : 0
                  return (
                    <div key={c.id} className="camp-row" onClick={() => setOpenId(c.id)}>
                      <div className="camp-main">
                        <div className="camp-title"><b>{c.title}</b><span className={`pill sm ${st.c}`}><span className="dot" />{st.t}</span></div>
                        <span className="muted" style={{ fontSize: 12.5 }}>{c.template_name} · {c.source_name || '—'} · {fmt(c.scheduled_at || c.created_at)}</span>
                        <div className="camp-bar"><span style={{ width: pct + '%' }} /></div>
                      </div>
                      <div className="camp-nums">
                        <span title="Enviados"><b style={{ color: '#25d366' }}>{c.sent}</b></span>
                        <span title="Entregados"><b style={{ color: '#4a9bff' }}>{c.delivered ?? 0}</b></span>
                        <span title="Leídos"><b style={{ color: '#00c39a' }}>{c.read_count ?? 0}</b></span>
                        <span title="Fallidos"><b style={{ color: 'var(--danger)' }}>{c.failed}</b></span>
                        <span title="Total"><b className="muted">/ {c.total}</b></span>
                      </div>
                      <div className="camp-acts" onClick={(e) => e.stopPropagation()}>
                        {pending > 0 && (c.status === 'sending' || c.status === 'scheduled') && <button className="icon-btn" title="Procesar pendientes" onClick={() => run(c.id)}><Icon.play /></button>}
                        {(c.status === 'sending' || c.status === 'scheduled') && <button className="icon-btn" title="Cancelar" onClick={() => cancel(c.id)} style={{ color: '#f4b740' }}><Icon.warn /></button>}
                        <button className="icon-btn" title="Eliminar" style={{ color: 'var(--danger)' }} onClick={() => del(c.id)}><Icon.trash /></button>
                      </div>
                    </div>
                  )
                })}
              </div>
            )}
        </div>
      </div>
    </>
  )
}
