import { useState, useEffect, useCallback } from 'react'
import { api } from '../api.js'
import { Icon } from '../icons.jsx'
import { fmtDateShort } from '../util.js'
import LoadError from './LoadError.jsx'
import { nf, DsKpi, DsHead, DsTabs } from './ds.jsx'

/* ---------------------------------------------------------------------------
 * ANALÍTICAS del área de Campañas. Mismo lenguaje que el Centro de Soporte y las
 * Estadísticas de Garantías: cabecera con pestañas a la derecha, una cifra que
 * manda (la tasa de entrega), KPIs del mes frente al anterior con su tendencia,
 * WhatsApp frente a correo y tablas limpias.
 * ------------------------------------------------------------------------- */

const eur = (n) => '€' + Number(n || 0).toFixed(2).replace('.', ',')
const pct = (a, b) => (b > 0 ? Math.round((a / b) * 100) : 0)
const ESTADO = { scheduled: 'Programada', sending: 'Enviando', sent: 'Enviada', done: 'Enviada', completed: 'Enviada', canceled: 'Cancelada', failed: 'Con errores', draft: 'Borrador' }
const MESES = ['ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sep', 'oct', 'nov', 'dic']

const Canal = ({ ch }) => (
  <span className={`an2-ch ${ch === 'email' ? 'mail' : 'wa'}`}>
    {ch === 'email' ? <><Icon.mail /> Correo</> : <><Icon.whatsapp /> WhatsApp</>}
  </span>
)

export default function Analytics() {
  const [d, setD] = useState(null)
  const [err, setErr] = useState(false)
  const [tab, setTab] = useState('resumen')          // 'resumen' | 'historial'
  const load = useCallback(() => { setErr(false); api.analytics().then((r) => r.ok ? setD(r) : setErr(true)).catch(() => setErr(true)) }, [])
  useEffect(() => { load() }, [load])

  return (
    <>
      <DsHead icon={Icon.chart} title="Analíticas" sub="Cómo rinden tus campañas">
        <DsTabs value={tab} onChange={setTab} items={[['resumen', 'Resumen'], ['historial', 'Historial', Icon.send]]} />
        <button className="icon-btn" title="Actualizar" onClick={load}><Icon.refresh /></button>
      </DsHead>

      <div className="page-scroll">
        <div className="page" style={{ maxWidth: 1180 }}>
          {tab === 'historial' ? <CampaignHistory /> : (
            err && !d ? <LoadError onRetry={load} msg="No se pudieron cargar las analíticas" />
              : !d ? <div className="center-load"><div className="spinner" /></div>
                : <Resumen d={d} onHistorial={() => setTab('historial')} />
          )}
        </div>
      </div>
    </>
  )
}

/* --------------------------- Resumen (campañas) --------------------------- */
function Resumen({ d, onHistorial }) {
  const k = d.kpi
  const f = d.forms
  const c = d.contacts
  const meses = d.months || []
  const este = meses[meses.length - 1]
  const antes = meses[meses.length - 2]
  const labelsTop = [...(d.by_label || [])].sort((a, b) => Number(b.n) - Number(a.n)).slice(0, 8)
  const wa = d.channels.whatsapp, em = d.channels.email

  return (
    <>
      {/* --- La cifra que manda y las que la acompañan --- */}
      <div className="ds-hero">
        <button className="ds-hero-main" onClick={onHistorial}>
          <span className="k">Tasa de entrega</span>
          <b className="v">{k.delivery_rate}%</b>
          <span className="h">
            <b>{nf(k.msgs_delivered)}</b> de {nf(k.msgs_sent)} mensajes entregados · <b>{k.read_rate}%</b> leídos en WhatsApp
          </span>
          <span className="ds-hero-go">Ver historial <Icon.chevron /></span>
        </button>
        <div className="ds-kpis">
          <DsKpi k="Mensajes este mes" v={este?.sent ?? 0} prev={antes?.sent} good vs="el mes anterior"
            h={este ? `${nf(este.delivered)} entregados · últimos 12 meses` : ' '} spark={meses.map((m) => m.sent)} />
          <DsKpi k="Campañas este mes" v={k.campaigns_month} prev={k.campaigns_prev} good vs="el mes anterior"
            h={`${nf(k.campaigns_total)} lanzadas en total`} />
          <DsKpi k="Bajas este mes" v={k.optout_month} tone={k.optout_month > 0 ? 'bad' : null}
            h={`${nf(k.optout_total)} dados de baja en total`} />
          <DsKpi k="Contactos nuevos este mes" v={c.new_month} h={`${nf(c.total)} contactos de Campañas`} />
        </div>
      </div>

      <div className="an-grid">
        {/* WhatsApp frente a correo: mismas filas, para comparar de un vistazo */}
        <div className="card an-card">
          <div className="an-h"><Icon.send /> WhatsApp frente a correo</div>
          <div className="an2-vs">
            <span />
            <span className="an2-vs-h"><Icon.whatsapp /> WhatsApp</span>
            <span className="an2-vs-h"><Icon.mail /> Correo</span>

            <span className="an2-vs-k">Enviados</span>
            <b>{nf(wa.sent)}</b><b>{nf(em.sent)}</b>

            <span className="an2-vs-k">Entregados</span>
            <Tasa v={pct(wa.delivered, wa.sent)} /><Tasa v={pct(em.delivered, em.sent)} />

            <span className="an2-vs-k">Leídos</span>
            <Tasa v={pct(wa.read, wa.sent)} /><span className="an2-na" title="El correo no cuenta aperturas">—</span>

            <span className="an2-vs-k">Fallidos</span>
            <b className={wa.failed ? 'an2-bad' : ''}>{nf(wa.failed)}</b><b className={em.failed ? 'an2-bad' : ''}>{nf(em.failed)}</b>
          </div>
        </div>

        {/* Formularios */}
        <div className="card an-card">
          <div className="an-h"><Icon.forms /> Formularios</div>
          <div className="an2-big"><b>{f.response_rate}%</b><span>de los formularios enviados se responden</span></div>
          <dl className="an2-dl">
            <div><dt>Publicados</dt><dd>{nf(f.published)}</dd></div>
            <div><dt>Veces enviado</dt><dd>{nf(f.sends)}</dd></div>
            <div><dt>Respuestas recibidas</dt><dd>{nf(f.submissions)}</dd></div>
          </dl>
        </div>

        {/* Base de contactos */}
        <div className="card an-card">
          <div className="an-h"><Icon.user /> Base de contactos</div>
          <dl className="an2-dl">
            <div><dt>Alcanzables por WhatsApp</dt><dd>{nf(c.whatsapp)}</dd></div>
            <div><dt>Alcanzables por correo</dt><dd>{nf(c.email)}</dd></div>
            <div><dt>Dados de baja</dt><dd className={c.optout ? 'an2-bad' : ''}>{nf(c.optout)}</dd></div>
          </dl>
        </div>

        {/* Contactos por etiqueta */}
        <div className="card an-card">
          <div className="an-h"><Icon.tag /> Contactos por etiqueta</div>
          {!labelsTop.length ? <p className="muted" style={{ fontSize: 12.5 }}>Sin datos todavía.</p> : (
            <div className="sc-bars ds-bars">
              {(() => {
                const max = Math.max(1, ...labelsTop.map((l) => Number(l.n)))
                return labelsTop.map((l) => (
                  <div key={l.name} className="sc-bar-row">
                    <span className="an2-lbl" title={l.name}><i style={{ background: l.color || 'var(--primary)' }} />{l.name}</span>
                    <span className="sc-bar"><i style={{ width: `${(Number(l.n) / max) * 100}%` }} /></span>
                    <span className="sc-bar-n">{nf(l.n)}</span>
                  </div>
                ))
              })()}
            </div>
          )}
        </div>
      </div>

      {/* Mensajes por mes: barras simples, una por mes, con la cifra al pasar el ratón */}
      {meses.some((m) => m.sent) ? (
        <div className="card an-card an2-mes">
          <div className="an-h"><Icon.chart /> Mensajes por mes</div>
          <div className="an2-cols">
            {(() => {
              const top = Math.max(1, ...meses.map((m) => m.sent))
              return meses.map((m) => (
                <div key={m.m} className="an2-col" title={`${MESES[Number(m.m.slice(5)) - 1]} ${m.m.slice(0, 4)}: ${nf(m.sent)} enviados, ${nf(m.delivered)} entregados`}>
                  <span className="an2-col-bar"><i style={{ height: `${(m.sent / top) * 100}%` }}><em style={{ height: `${m.sent ? (m.delivered / m.sent) * 100 : 0}%` }} /></i></span>
                  <small>{MESES[Number(m.m.slice(5)) - 1]}</small>
                </div>
              ))
            })()}
          </div>
          <p className="an2-leg"><i className="s" /> Enviados <i className="d" /> Entregados</p>
        </div>
      ) : null}

      {/* Últimas campañas */}
      <div className="card an2-tcard">
        <div className="an-h"><Icon.send /> Últimas campañas <span className="spacer" /><button className="lnk" onClick={onHistorial}>Ver todas</button></div>
        {(!d.recent || d.recent.length === 0) ? (
          <p className="muted" style={{ padding: '0 0 6px', fontSize: 12.5 }}>Aún no has lanzado campañas.</p>
        ) : (
          <div className="an2-scroll">
            <table className="an2-table">
              <thead><tr><th>Campaña</th><th>Canal</th><th className="r">Enviados</th><th className="r">Entrega</th><th className="r">Lectura</th></tr></thead>
              <tbody>
                {d.recent.map((r) => (
                  <tr key={r.id}>
                    <td className="t">{r.title}</td>
                    <td><Canal ch={r.channel} /></td>
                    <td className="r">{nf(r.sent)}</td>
                    <td className="r">{pct(r.delivered, r.sent)}%</td>
                    <td className="r">{r.channel === 'email' ? '—' : pct(r.read, r.sent) + '%'}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </div>
    </>
  )
}

// Una tasa con su barrita: la cifra manda, la barra solo da la proporción.
const Tasa = ({ v }) => <span className="an2-tasa"><b>{v}%</b><i><em style={{ width: `${v}%` }} /></i></span>

/* -------------------- Historial / trazabilidad de campañas --------------------
 * Cada campaña: quién la lanzó, cuándo, destino, plantilla, categoría, resultados
 * (enviados/entregados/leídos/fallidos) y coste real (entregados × tarifa).
 * Coste solo si el backend lo autoriza (show_cost = superadmin).
 * ---------------------------------------------------------------------------- */
function CampaignHistory() {
  const [d, setD] = useState(null)
  const [err, setErr] = useState(false)
  const load = useCallback(() => { setErr(false); api.campaignHistory().then((r) => r.ok ? setD(r) : setErr(true)).catch(() => setErr(true)) }, [])
  useEffect(() => { load() }, [load])

  if (err && !d) return <LoadError onRetry={load} msg="No se pudo cargar el historial" />
  if (!d) return <div className="center-load"><div className="spinner" /></div>

  const t = d.totals

  return (
    <>
      <div className="ds-kpis an2-kpis">
        {d.show_cost ? (
          <>
            <DsKpi k="Gasto este mes" v={t.month_cost} fmt={eur} h={`${t.month_count} campaña${t.month_count === 1 ? '' : 's'} este mes`} />
            <DsKpi k="Gasto histórico" v={t.all_cost} fmt={eur} h={`${nf(t.all_count)} campañas en total`} />
          </>
        ) : (
          <>
            <DsKpi k="Campañas este mes" v={t.month_count} h="Lanzadas este mes" />
            <DsKpi k="Campañas en total" v={t.all_count} h="Desde el principio" />
          </>
        )}
      </div>

      {d.campaigns.length === 0 ? (
        <div className="empty"><div className="ico"><Icon.send /></div><p>Aún no hay campañas.</p></div>
      ) : (
        <div className="card an2-tcard">
          <div className="an2-scroll">
            <table className="an2-table wide">
              <thead>
                <tr>
                  <th>Fecha</th><th>Campaña</th><th>Canal</th><th>Destino</th><th>Plantilla</th><th>Categoría</th><th>Lanzó</th>
                  <th className="r">Dest.</th><th className="r">Entreg.</th><th className="r">Leídos</th><th className="r">Fallidos</th><th>Estado</th>
                  {d.show_cost && <th className="r">Coste</th>}
                </tr>
              </thead>
              <tbody>
                {d.campaigns.map((c) => (
                  <tr key={c.id}>
                    <td>{fmtDateShort(c.created_at)}</td>
                    <td className="t">{c.title}</td>
                    <td><Canal ch={c.channel} /></td>
                    <td className="w">{c.source_name || '—'}</td>
                    <td>{c.template_name || '—'}</td>
                    <td>{c.category ? <span className="an2-cat">{c.category.toLowerCase()}</span> : '—'}</td>
                    <td>{c.by_name || <span className="muted">—</span>}</td>
                    <td className="r">{nf(c.total)}</td>
                    <td className="r">{nf(c.delivered)}</td>
                    <td className="r">{nf(c.read_count)}</td>
                    <td className={`r${c.failed ? ' an2-bad' : ''}`}>{nf(c.failed)}</td>
                    <td><span className="an2-st">{ESTADO[c.status] || c.status}</span></td>
                    {d.show_cost && <td className="r b">{c.channel === 'email' ? '—' : (c.cost > 0 ? eur(c.cost) : 'Gratis')}</td>}
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </div>
      )}
      {d.show_cost && <p className="muted" style={{ fontSize: 12, marginTop: 10 }}>Coste real = entregados × tarifa aplicada al lanzar. Las campañas anteriores a esta función no tienen tarifa guardada (coste €0,00).</p>}
    </>
  )
}
