import { useState, useEffect, useCallback, useMemo } from 'react'
import { api } from '../api.js'
import { Icon } from '../icons.jsx'
import { useToast, useConfirm } from '../App.jsx'
import Select from './Select.jsx'

/* ---------------------------------------------------------------------------
 * CUADRANTE DE TURNOS — sustituye al Excel de soporte.
 *
 * Semanas en filas, Mañana y Tarde en columnas. Arriba, lo único que de verdad
 * se consulta a diario: QUIÉN ESTÁ DE GUARDIA AHORA. Las sustituciones («Jue–Vie:
 * Juan») se ven dentro de su celda, no en una columna de texto libre como en el
 * Excel, y pueden durar de un día a la semana entera.
 *
 * Esto es SOLO del helpdesk: en campañas no pinta nada. Por eso la pantalla vive
 * en el área Helpdesk y solo se listan agentes con permiso `helpdesk.access`.
 * ------------------------------------------------------------------------- */

const VENTANA = 8            // semanas visibles
const DIAS = ['Lun', 'Mar', 'Mié', 'Jue', 'Vie']
/* Inicial de cada día. No sale de DIAS: lunes y martes empiezan igual, y el
   miércoles se abrevia con X justo para eso. */
const INIC = ['L', 'M', 'X', 'J', 'V']
const DIAS_LARGO = ['lunes', 'martes', 'miércoles', 'jueves', 'viernes']

/**
 * Color estable de una persona: la misma siempre del mismo color.
 *
 * Sale del id si lo hay y, si no, del NOMBRE. Sin ese respaldo, `Number(undefined)`
 * daba `hsl(NaN …)`, el navegador descartaba la regla entera y quedaba un círculo
 * transparente con la letra blanca encima: invisible. Es lo que pasó en el listado.
 */
const colorDe = (id, name = '') => {
  const n = Number(id)
  const semilla = Number.isFinite(n) && n > 0
    ? n
    : [...String(name)].reduce((a, c) => a + c.charCodeAt(0), 0)
  return `hsl(${(semilla * 137) % 360} 52% 45%)`
}
const iniciales = (n) => (n || '?').trim().split(/\s+/).slice(0, 2).map((p) => p[0]).join('').toUpperCase()

function Avatar({ id, name, size = 30 }) {
  return (
    <span className="tn-av" title={name}
      style={{ width: size, height: size, background: colorDe(id, name), fontSize: Math.max(9, size * 0.38) }}>
      {iniciales(name)}
    </span>
  )
}

/** Suma días a un YYYY-MM-DD sin líos de zona horaria. */
const masDias = (iso, n) => {
  const [a, m, d] = iso.split('-').map(Number)
  const f = new Date(a, m - 1, d + n)
  return `${f.getFullYear()}-${String(f.getMonth() + 1).padStart(2, '0')}-${String(f.getDate()).padStart(2, '0')}`
}
/** «20 jul» a partir de un YYYY-MM-DD, para la vista previa de la rotación. */
const fmtDia = (iso) => {
  const [a, m, d] = iso.split('-').map(Number)
  return `${d} ${MESES[m - 1]}`
}
const MESES = ['ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sep', 'oct', 'nov', 'dic']

/** Semanas que caben entre dos lunes (ambos incluidos); 0 si el orden está mal. */
const semanasEntre = (ini, fin) => {
  if (!ini || !fin) return 0
  const dias = Math.round((Date.parse(fin) - Date.parse(ini)) / 86400000)
  return dias < 0 ? 0 : Math.floor(dias / 7) + 1
}

const lunesDeHoy = () => {
  const h = new Date()
  return masDias(`${h.getFullYear()}-${String(h.getMonth() + 1).padStart(2, '0')}-${String(h.getDate()).padStart(2, '0')}`,
    -((h.getDay() + 6) % 7))
}

export default function Shifts() {
  const toast = useToast()
  const confirm = useConfirm()
  const [mes, setMes] = useState('')          // '' = mes actual
  const [d, setD] = useState(null)
  const [dia, setDia] = useState(null)        // día abierto en el panel
  const [sub, setSub] = useState(null)        // { week_start, shift } → sustitución de varios días
  const [rotar, setRotar] = useState(false)
  /* Calendario o listado. Se recuerda: quien prefiere la tabla la prefiere siempre,
     y volver a cambiarla en cada visita es un peaje tonto. */
  const [vista, setVista] = useState(() => localStorage.getItem('turnos_vista') || 'cal')
  const cambiarVista = (v) => { setVista(v); localStorage.setItem('turnos_vista', v) }

  const load = useCallback(() => { api.getShiftMonth(mes).then(setD) }, [mes])
  useEffect(() => { load() }, [load])

  // El día abierto se relee del mes recargado: si no, el panel se queda con datos viejos.
  const diaVivo = dia && d ? d.days.find((x) => x.date === dia.date) || dia : dia

  if (!d) return <div className="center-load"><div className="spinner" /></div>

  const puedeEditar = !!d.can_edit
  /* Hueco inicial para que el día 1 caiga en su columna: el calendario solo pinta
     laborables, así que la posición sale del día de la semana (1 = lunes). */
  const hueco = d.days.length ? d.days[0].dow - 1 : 0

  return (
    <>
      <header className="page-head">
        <span className="sc-ic"><Icon.calendar style={{ width: 18, height: 18, fill: 'var(--primary)' }} /></span>
        <div><h1>Turnos de soporte</h1></div>
        <span className="sub">· Quién cubre cada día. Los tickets nuevos se reparten solos al de guardia.</span>
        <div className="spacer" />
      </header>

      <Ahora on={d.on_duty} hours={d.hours} shifts={d.shifts} cats={d.shift_categories} />

      {/* Semanas próximas sin cubrir. Va SIEMPRE desde hoy, mires el mes que mires:
          olvidarse de rellenar el cuadrante no se nota hasta que un ticket se queda
          huérfano, y para entonces ya es tarde. */}
      {d.gaps?.length > 0 && (
        <div className={`hueco-aviso ${d.gaps[0].esta ? 'urge' : ''}`}>
          <span className="hueco-ic"><Icon.warn /></span>
          <div className="hueco-tx">
            <b>
              {d.gaps.length === 1
                ? 'Hay una semana sin cubrir'
                : `Hay ${d.gaps.length} semanas sin cubrir`}
              {d.gaps[0].esta && ' — una es esta'}
            </b>
            <small>Los tickets de esos días entrarán sin asignar y nadie recibirá aviso.</small>
          </div>
          <div className="hueco-chips">
            {d.gaps.slice(0, 4).map((g) => (
              <button key={g.week_start} className={`hueco-chip ${g.esta ? 'urge' : ''}`}
                onClick={() => setMes(g.month)}
                title={`Falta ${g.turnos.join(' y ')} · ir a ese mes`}>
                {g.label}
                <em>{g.turnos.length === 2 ? 'sin nadie' : g.turnos[0].toLowerCase()}</em>
              </button>
            ))}
            {d.gaps.length > 4 && <span className="hueco-mas">+{d.gaps.length - 4}</span>}
          </div>
        </div>
      )}

      <div className="tn-bar">
        <div className="tn-nav">
          <button className="icon-btn" onClick={() => setMes(d.prev)} title="Mes anterior">‹</button>
          <b>{d.label}</b>
          <button className="icon-btn" onClick={() => setMes(d.next)} title="Mes siguiente">›</button>
          {mes && <button className="btn ghost sm" onClick={() => setMes('')}>Ir a hoy</button>}
        </div>
        <div className="spacer" />
        <div className="tn-vista" role="group" aria-label="Vista">
          <button className={vista === 'cal' ? 'on' : ''} onClick={() => cambiarVista('cal')}
            title="Ver el mes día a día"><Icon.calendar /> Calendario</button>
          <button className={vista === 'lista' ? 'on' : ''} onClick={() => cambiarVista('lista')}
            title="Ver una fila por semana"><Icon.list /> Listado</button>
        </div>
        {puedeEditar && (
          <button className="btn sm" onClick={() => setRotar(true)} title="Rellena varias semanas de golpe">
            <Icon.refresh /> Generar rotación
          </button>
        )}
      </div>

      {vista === 'lista' && <Listado d={d} onDia={setDia} />}

      <div className="card cal-card" style={vista === 'cal' ? undefined : { display: 'none' }}>
        <div className="cal-dows">
          {DIAS_LARGO.map((n) => <div key={n}>{n}</div>)}
        </div>

        <div className="cal-grid">
          {Array.from({ length: hueco }, (_, i) => <div key={'h' + i} className="cal-hueco" />)}

          {d.days.map((x) => (
            <button key={x.date} className={`cal-dia ${x.today ? 'hoy' : ''} ${x.past ? 'pasado' : ''} ${x.holiday ? 'festivo' : ''} ${x.note ? 'con-nota' : ''}`}
              onClick={() => setDia(x)} title={x.note || undefined}>
              <div className="cal-dia-h">
                <b>{x.day}</b>
                {x.today && <span className="cal-hoy">hoy</span>}
                <span className="spacer" />
                {x.note && <Icon.note className="cal-ic-nota" />}
              </div>

              {x.holiday
                ? <div className="cal-festivo">{x.holiday}</div>
                : Object.keys(d.shifts).map((k) => {
                    const t = x.shifts[k]
                    /* Un turno lo pueden cubrir varios. El icono va PEGADO al que
                       sustituye, no al turno entero: con dos personas, teñir la fila
                       de ámbar decía que los dos eran cambios y solo lo era uno. */
                    return (
                      <div key={k} className={`cal-turno ${t.substitute ? 'sust' : ''} ${t.names ? '' : 'vacio'} ${t.people?.length > 1 ? 'dos' : ''}`}>
                        <span className="cal-t">{k === 'morning' ? 'M' : 'T'}</span>
                        <span className="cal-n">
                          {t.people?.length
                            ? t.people.map((p, i) => (
                                <span key={p.user_id} className={p.substitute ? 'cal-p sust' : 'cal-p'}>
                                  {i > 0 && <i className="cal-sep">/</i>}
                                  {p.name}{p.substitute && <Icon.refresh />}
                                </span>
                              ))
                            : 'sin cubrir'}
                        </span>
                      </div>
                    )
                  })}

              {/* Lo que la versión anterior escondía: a quién se está sustituyendo. */}
              {!x.holiday && Object.keys(d.shifts).some((k) => x.shifts[k].replaces) && (
                <div className="cal-replaces">
                  {Object.keys(d.shifts).filter((k) => x.shifts[k].replaces)
                    .map((k) => 'sustituye a ' + x.shifts[k].replaces).join(' · ')}
                </div>
              )}

              {/* La nota, con su texto: un icono suelto no dice qué pasa ese día. */}
              {x.note && <div className="cal-nota"><Icon.note />{x.note}</div>}
            </button>
          ))}
        </div>

        <div className="cal-leyenda">
          <span><Icon.refresh /> sustitución</span>
          <span><Icon.note /> tiene nota</span>
          <span className="cal-l-vacio">sin cubrir</span>
          <span className="cal-l-fest">festivo</span>
        </div>
      </div>

      {diaVivo && (
        <PanelDia dia={diaVivo} d={d} puedeEditar={puedeEditar} onClose={() => setDia(null)}
          onRecargar={load} onVariosDias={(shift, holders) => { setDia(null); setSub({ week_start: diaVivo.week, shift, holders }) }}
          toast={toast} confirm={confirm} />
      )}

      {sub && <ModalSub base={sub} agents={d.agents} shifts={d.shifts} onClose={() => setSub(null)}
        onDone={(recortadas) => {
          setSub(null); load()
          toast(recortadas ? 'Sustitución guardada (se ajustó otra que se solapaba)' : 'Sustitución guardada')
        }} />}
      {rotar && <ModalRotar desde={d.days[0]?.week || ''} agents={d.agents} onClose={() => setRotar(false)}
        onIr={(m) => { setRotar(false); setMes(m) }}
        onDone={(n) => { setRotar(false); load(); toast(n + ' turnos rellenados') }} />}
    </>
  )
}

/* ---------------------------- Vista de listado -----------------------------
 * El calendario se lee día a día; esta se lee de un vistazo. Una fila por semana
 * con el titular de cada turno, y debajo SOLO lo que se sale de lo normal: las
 * sustituciones (agrupadas por tramo, no un día suelto por fila) y las notas.
 * -------------------------------------------------------------------------- */
function Listado({ d, onDia }) {
  const turnos = Object.keys(d.shifts)

  /* Los días vienen sueltos del servidor; aquí se agrupan por semana y se juntan
     los días seguidos de la misma sustitución en un solo tramo. */
  const semanas = []
  for (const x of d.days) {
    let s = semanas.find((w) => w.week === x.week)
    if (!s) { s = { week: x.week, dias: [], titulares: {}, tramos: [], notas: [] }; semanas.push(s) }
    s.dias.push(x)
    if (x.note) s.notas.push({ date: x.date, note: x.note })
    if (x.holiday) continue

    for (const k of turnos) {
      const t = x.shifts[k]
      if (t.holders?.length && !s.titulares[k]) s.titulares[k] = t.holders
      /* Un tramo por SUSTITUTO, no por turno: el mismo día pueden cubrir dos. */
      for (const p of (t.people || []).filter((q) => q.substitute)) {
        const ult = [...s.tramos].reverse().find((z) => z.turno === k && z.quien === p.name)
        if (ult && ult.hasta === masDias(x.date, -1)) ult.hasta = x.date
        else s.tramos.push({ turno: k, quien: p.name, replaces: p.replaces, desde: x.date, hasta: x.date })
      }
    }
  }

  const nDia = (iso) => new Date(iso + 'T00:00:00').getDate()

  return (
    <div className="card tn-lista">
      <div className="tn-l-cab">
        <span>Semana</span>
        {turnos.map((k) => <span key={k}>{d.shifts[k].label || (k === 'morning' ? 'Mañana' : 'Tarde')}</span>)}
        <span>Cambios y notas</span>
      </div>

      {semanas.map((s) => {
        const hoy = s.dias.some((x) => x.today)
        return (
          <div key={s.week} className={`tn-l-fila ${hoy ? 'esta' : ''}`}>
            <div className="tn-l-sem">
              <b>{nDia(s.dias[0].date)} – {fmtDia(s.dias[s.dias.length - 1].date)}</b>
              {hoy && <em>esta semana</em>}

              {/* LOS CINCO DÍAS, pinchables. Sin esto el listado solo dejaba tocar
                  lo que YA tenía un cambio: para poner uno nuevo había que irse al
                  calendario. Las dos vistas editan lo mismo. */}
              <div className="tn-l-dias" title="Pincha un día para verlo o cambiarlo">
                {s.dias.map((x) => (
                  <button key={x.date} onClick={() => onDia(x)}
                    className={`tn-l-d ${x.today ? 'hoy' : ''} ${x.past ? 'pasado' : ''} ${x.holiday ? 'fest' : ''}`}
                    title={[x.holiday && `Festivo: ${x.holiday}`, x.note].filter(Boolean).join(' · ') || undefined}>
                    <i>{INIC[x.dow - 1]}</i><b>{x.day}</b>
                    <span className="tn-l-marcas">
                      {turnos.some((k) => x.shifts[k].substitute) && <em className="sust" />}
                      {x.note && <em className="nota" />}
                    </span>
                  </button>
                ))}
              </div>
            </div>

            {turnos.map((k) => (
              <div key={k} className={`tn-l-t ${s.titulares[k] ? '' : 'vacio'}`}>
                {s.titulares[k]
                  ? s.titulares[k].map((h) => (
                      <span key={h.user_id} className="tn-l-p">
                        <Avatar id={h.user_id} name={h.name} size={22} /> {h.name}
                      </span>
                    ))
                  : 'sin cubrir'}
              </div>
            ))}

            <div className="tn-l-cambios">
              {s.tramos.map((t, i) => (
                <button key={'t' + i} className="tn-l-sust"
                  onClick={() => onDia(s.dias.find((x) => x.date === t.desde))}>
                  <Icon.refresh />
                  <span><b>{t.quien}</b> cubre {d.shifts[t.turno].label?.toLowerCase() || (t.turno === 'morning' ? 'mañana' : 'tarde')}
                    {t.replaces ? ` a ${t.replaces}` : ''} · {t.desde === t.hasta ? fmtDia(t.desde) : `${nDia(t.desde)} – ${fmtDia(t.hasta)}`}</span>
                </button>
              ))}
              {s.notas.map((n) => (
                <button key={n.date} className="tn-l-nota" onClick={() => onDia(s.dias.find((x) => x.date === n.date))}>
                  <Icon.note /><span><b>{fmtDia(n.date)}</b> {n.note}</span>
                </button>
              ))}
              {!s.tramos.length && !s.notas.length && <span className="tn-l-nada">—</span>}
            </div>
          </div>
        )
      })}

      {!semanas.length && <div className="tn-l-vacia">No hay días laborables en este mes.</div>}
    </div>
  )
}

/* ------------------- El día: quién está, sustituto y nota ------------------ */
function PanelDia({ dia, d, puedeEditar, onClose, onRecargar, onVariosDias, toast }) {
  const [nota, setNota] = useState(dia.note || '')
  const [guardando, setGuardando] = useState(false)
  /* Qué fila está pidiendo sustituto ahora mismo, «turno:persona». El selector NO
     está siempre puesto: en un día normal no hay ningún cambio que hacer, y tener
     un desplegable por titular diciendo «viene Juan» donde ya pone Juan no informa
     de nada. Se abre al pulsar, y hasta entonces la ficha solo se lee. */
  const [cambiando, setCambiando] = useState(null)

  useEffect(() => { setNota(dia.note || '') }, [dia.date, dia.note])

  const fecha = new Date(dia.date + 'T00:00:00')
    .toLocaleDateString('es-ES', { weekday: 'long', day: 'numeric', month: 'long' })

  /* Cambiar quién está ESE día es una sustitución; cambiar los titulares afecta a
     toda la semana. Son dos cosas distintas y por eso van en dos controles.
     `releva` dice a QUIÉN cubre: solo hace falta cuando el turno lo llevan varios,
     porque entonces «viene Robert» no dice si releva a Juan o a Ian. */
  const ponerSustituto = async (shift, uid, releva) => {
    setGuardando(true)
    setCambiando(null)
    const r = await api.saveShiftOverride({
      days: [dia.date], shift, user_id: Number(uid), replaces_user_id: releva || 0,
    })
    setGuardando(false)
    if (r.ok) { toast('Cambio guardado para hoy'); onRecargar() } else toast(r.error || 'Error', 'err')
  }

  /* Deshacer un cambio se hace por el ID de esa sustitución, no volviendo a poner
     al titular: el mismo turno puede tener dos cambios y hay que quitar el suyo. */
  const quitarCambio = async (oid) => {
    setGuardando(true)
    const r = await api.saveShiftOverride({ delete_id: oid })
    setGuardando(false)
    if (r.ok) { toast('Cambio deshecho'); onRecargar() } else toast(r.error || 'Error', 'err')
  }

  /* Los titulares se mandan SIEMPRE como lista completa: quitar a alguien es
     enviarla sin él. Así no hay un «borrar» aparte que se pueda quedar a medias. */
  const guardarTitulares = async (shift, ids) => {
    setGuardando(true)
    const r = await api.assignShift({ week_start: dia.week, shift, user_ids: ids })
    setGuardando(false)
    if (r.ok) { toast('Titulares de la semana actualizados'); onRecargar() } else toast(r.error || 'Error', 'err')
  }

  const sinCambios = nota === (dia.note || '')

  /* Guardar la nota CIERRA el panel: es una acción que termina, y dejarlo abierto
     con un botón ya inerte parecía que no había pasado nada. La confirmación real
     es ver la nota aparecer en su día del calendario. */
  const guardarNota = async () => {
    setGuardando(true)
    const r = await api.saveShiftNote(dia.date, nota)
    setGuardando(false)

    if (!r.ok) { toast(r.error || 'Error', 'err'); return }
    toast(nota.trim() ? 'Nota guardada' : 'Nota borrada')
    onRecargar()
    onClose()
  }

  return (
    <div className="modal-bg" onMouseDown={(e) => e.target.classList.contains('modal-bg') && onClose()}>
      <div className="modal cal-panel">
        <div className="modal-h">
          <h3>{fecha[0].toUpperCase() + fecha.slice(1)}</h3>
          <button className="icon-btn" onClick={onClose}>✕</button>
        </div>

        <div className="modal-body">
          {dia.holiday && <p className="cal-panel-fest">Festivo: {dia.holiday}</p>}

          {Object.entries(d.shifts).map(([k, etiqueta]) => {
            const t = dia.shifts[k]
            const tit = t.holders || []
            const ids = tit.map((h) => h.user_id)
            /* Quien puede cubrir: cualquiera que no esté ya en el turno. */
            const libres = (d.agents || []).filter((a) => !ids.includes(a.id))
              .map((a) => ({ value: String(a.id), label: a.name }))

            /* El selector, cuando toca. `releva` es a quién cubre; va vacío solo si
               el turno no tiene titular (entonces la sustitución cubre el turno). */
            const selector = (releva, quien) => (
              <div className="cal-cambio">
                <span>{quien
                  ? <>Hoy hace el soporte en lugar de <b>{quien}</b>:</>
                  : <>Hoy hace el soporte de este turno:</>}</span>
                <Select sm value="" options={libres} placeholder="Elige un agente…"
                  onChange={(v) => v && ponerSustituto(k, v, releva)} />
                <button className="btn ghost sm" onClick={() => setCambiando(null)}>Cancelar</button>
              </div>
            )

            return (
              <div key={k} className={`cal-turno-ed ${t.substitute ? 'sust' : ''}`}>
                <div className="cal-turno-ed-h">
                  <b>{etiqueta}</b>
                  <span className="cal-horas">{d.hours[k][0]}–{d.hours[k][1]}</span>
                </div>

                {/* QUIÉN TRABAJA HOY. Cada persona lleva su etiqueta: «titular» o «en
                    lugar de X». Antes se ponían los nombres seguidos y con dos había
                    que adivinar quién era quién y a quién estaba cubriendo. */}
                {/* QUIÉN TRABAJA HOY, y la acción de cada uno al lado: al titular se
                    le puede poner quien le cubra, y un cambio se deshace. Nada de
                    controles permanentes que repiten lo que ya pone en la ficha. */}
                <div className="cal-trab-l">Trabajan hoy</div>
                {t.people?.length ? (
                  <ul className="cal-trab">
                    {t.people.map((p) => (
                      <li key={p.user_id}>
                        <div className="cal-trab-q">
                          <Avatar id={p.user_id} name={p.name} size={28} />
                          <b>{p.name}</b>
                          {p.substitute
                            ? <span className="cal-tag sust">en lugar de {p.replaces || 'el titular'}</span>
                            : <span className="cal-tag">titular</span>}
                          {p.reason && <span className="cal-motivo-in">{p.reason}</span>}
                          <span className="spacer" />
                          {puedeEditar && (p.substitute
                            ? <button className="btn ghost sm" disabled={guardando}
                                onClick={() => quitarCambio(p.override_id)}>Deshacer</button>
                            : <button className="btn ghost sm" disabled={guardando}
                                onClick={() => setCambiando(`${k}:${p.user_id}`)}>Hoy lo hace otro…</button>)}
                        </div>
                        {cambiando === `${k}:${p.user_id}` && selector(tit.length > 1 ? p.user_id : null, p.name)}
                      </li>
                    ))}
                  </ul>
                ) : (
                  <div className="cal-trab-nadie">
                    <p className="cal-vacio-tx">Nadie cubre este turno</p>
                    {puedeEditar && cambiando !== `${k}:libre` && (
                      <button className="btn ghost sm" onClick={() => setCambiando(`${k}:libre`)}>Que lo haga alguien hoy…</button>
                    )}
                    {cambiando === `${k}:libre` && selector(null, null)}
                  </div>
                )}

                {puedeEditar && (
                  <div className="cal-acciones">
                    {/* TITULARES de la semana. Pueden ser varios: se añaden y se
                        quitan de uno en uno, que es como se piensa («metemos a Ian»). */}
                    <div className="cal-acc ancho">
                      <span>Los de esta semana</span>
                      <div className="cal-tit">
                        {tit.map((h) => (
                          <span key={h.user_id} className="cal-chip">
                            <Avatar id={h.user_id} name={h.name} size={18} /> {h.name}
                            <button title={`Quitar a ${h.name} de toda la semana`}
                              onClick={() => guardarTitulares(k, ids.filter((x) => x !== h.user_id))}>✕</button>
                          </span>
                        ))}
                        <Select sm value="" placeholder={tit.length ? '+ Añadir' : 'Elegir…'}
                          options={libres} onChange={(v) => v && guardarTitulares(k, [...ids, Number(v)])} />
                      </div>
                      <i className="cal-pista">Cambiarlo afecta a los cinco días</i>
                    </div>

                    <button className="btn ghost sm cal-varios" onClick={() => onVariosDias(k, tit)}>
                      Cambio de varios días…
                    </button>
                  </div>
                )}
              </div>
            )
          })}

          {/* La nota es del DÍA: «hoy falta alguien y cubrimos entre todos». */}
          <div className="field" style={{ marginBottom: 0 }}>
            <span className="lbl">Nota del día</span>
            <textarea rows={2} value={nota} disabled={!puedeEditar}
              onChange={(e) => setNota(e.target.value)}
              placeholder="Ej.: Juan de baja, hoy cubrimos entre todos" />
          </div>
        </div>

        <div className="modal-foot">
          <button className="btn ghost" onClick={onClose}>Cerrar</button>
          {puedeEditar && (
            /* Un botón deshabilitado y sin más señal parece que no ha hecho nada.
               Cuando no queda nada por guardar, lo dice. */
            sinCambios
              ? <span className="cal-guardada">{nota.trim() ? <><Icon.check /> Nota guardada</> : 'Sin nota'}</span>
              : <button className="btn" disabled={guardando} onClick={guardarNota}>
                  {guardando ? 'Guardando…' : (nota.trim() ? 'Guardar nota' : 'Borrar nota')}
                </button>
          )}
        </div>
      </div>
    </div>
  )
}
/* ------------------------- Quién está de guardia ------------------------- */
function Ahora({ on, hours, shifts, cats }) {
  return (
    <div className={`tn-now ${on ? '' : 'sin'}`}>
      <div className="tn-now-l">Ahora mismo</div>
      {on ? (
        <>
          <Avatar id={on.user_id} name={on.name} size={44} />
          <div className="tn-now-w">
            <b>{(on.equipo || []).map((p) => p.name).join(' / ') || on.name}</b>
            <span>
              Turno de {shifts[on.shift]?.toLowerCase()} · {hours[on.shift][0]}–{hours[on.shift][1]}
              {on.substitute && <em> · cubriendo hoy</em>}
            </span>
            {/* Con dos personas hace falta decir a quién le cae el próximo ticket:
                se reparten alternando, y si no se ve, parece que va al azar. */}
            {on.equipo?.length > 1 && (
              <span className="tn-now-rr"><Icon.refresh /> Se reparten alternando · el siguiente ticket es para <b>{on.name}</b></span>
            )}
          </div>
        </>
      ) : (
        <div className="tn-now-w">
          <b>Nadie de guardia</b>
          <span>Fuera de horario o semana sin cubrir. Los tickets entran igual, pero quedan sin asignar.</span>
        </div>
      )}
      <div className="spacer" />
      {!!cats?.length && (
        <div className="tn-cats" title="Solo estas categorías se reparten por turno">
          Se reparten por turno: {cats.map((c) => <span key={c} className="chip sm">{c}</span>)}
        </div>
      )}
    </div>
  )
}

/* -------------------------- Sustituir un solo día ------------------------- */
function ModalSub({ base, agents, shifts, onClose, onDone }) {
  const toast = useToast()
  /* Días marcados, sueltos. No tienen por qué ir seguidos: «el lunes y el viernes»
     es tan normal como la semana entera, así que cada día se pulsa por su cuenta. */
  const [dias, setDias] = useState([])
  const [turno, setTurno] = useState(base.shift)
  const [uid, setUid] = useState('')
  const [nota, setNota] = useState('')
  /* A quién releva. Con un solo titular NO se pregunta y se guarda vacío: vacío
     significa «cubre el turno», que es lo que pasa cuando solo hay uno. Debe ir
     igual que en el panel del día; si una pantalla lo apuntara y la otra no, dos
     sustituciones del mismo turno no se pisarían y saldrían las dos a la vez. */
  const titulares = base.holders || []
  const [releva, setReleva] = useState('')

  const semana = DIAS.map((_, i) => masDias(base.week_start, i))
  const marcar = (f) => setDias((s) => (s.includes(f) ? s.filter((x) => x !== f) : [...s, f].sort()))
  const todaLaSemana = () => setDias((s) => (s.length === semana.length ? [] : [...semana]))

  const guardar = async () => {
    if (!dias.length) { toast('Marca al menos un día', 'err'); return }
    if (!uid) { toast('Elige quién lo cubre', 'err'); return }
    if (titulares.length > 1 && !releva) { toast('Di a quién sustituye: ese turno lo llevan varios', 'err'); return }
    const r = await api.saveShiftOverride({
      days: dias, shift: turno, user_id: Number(uid), replaces_user_id: Number(releva) || 0, notes: nota,
    })
    if (r.ok) onDone(r.trimmed); else toast(r.error || 'Error', 'err')
  }

  // «Lunes y viernes», «de lunes a jueves»… en cristiano, no una lista de fechas.
  const resumen = () => {
    if (!dias.length) return 'Pincha los días que cubre esta persona.'
    if (dias.length === semana.length) return 'Cubre la semana entera'
    const nombres = dias.map((f) => DIAS_LARGO[semana.indexOf(f)])
    const lista = nombres.length === 1 ? nombres[0]
      : `${nombres.slice(0, -1).join(', ')} y ${nombres[nombres.length - 1]}`
    return `Cubre el ${lista}`
  }

  return (
    <Modal title="Sustitución" onClose={onClose} onSave={guardar} saveLabel="Guardar sustitución">
      <p className="cfg-hint">
        Cuando otra persona cubre el turno algunos días: <i>«el lunes y el viernes los lleva Juan»</i>. Marca los que
        haga falta, no tienen por qué ir seguidos; el resto de días sigue el titular.
      </p>

      <div className="field">
        <div className="tn-dias-h">
          <span className="lbl">Días que cubre</span>
          <button type="button" className="btn ghost sm" onClick={todaLaSemana}>
            {dias.length === semana.length ? 'Ninguno' : 'Toda la semana'}
          </button>
        </div>
        <div className="tn-dias">
          {DIAS.map((n, i) => {
            const f = semana[i]
            return (
              <button key={f} type="button" className={`tn-dia ${dias.includes(f) ? 'on' : ''}`} onClick={() => marcar(f)}>
                <b>{n}</b><span>{f.slice(8)}</span>
              </button>
            )
          })}
        </div>
        <span className="hint">{resumen()}</span>
      </div>

      <div className="grid2">
        <div className="field"><span className="lbl">Turno</span>
          <Select block value={turno} onChange={setTurno}
            options={Object.entries(shifts).map(([v, l]) => ({ value: v, label: l }))} /></div>
        <div className="field"><span className="lbl">Hace el soporte <em>*</em></span>
          <Select block value={uid} onChange={setUid} placeholder="Elige un agente…"
            options={agents.filter((a) => !titulares.some((h) => h.user_id === a.id))
              .map((a) => ({ value: String(a.id), label: a.name }))} /></div>
      </div>

      {/* Solo cuando el turno lo llevan varios: si no, «lo cubre Robert» ya lo dice
          todo. Con dos titulares hay que decir a cuál releva, porque el otro sigue. */}
      {titulares.length > 1 && (
        <div className="field"><span className="lbl">En lugar de <em>*</em></span>
          <Select block value={releva} onChange={setReleva} placeholder="¿A quién sustituye?"
            options={titulares.map((h) => ({ value: String(h.user_id), label: h.name }))} />
          <span className="hint">Ese turno lo llevan {titulares.map((h) => h.name).join(' y ')}: el que no elijas sigue cubriendo.</span>
        </div>
      )}

      <label className="field"><span className="lbl">Motivo <span className="hint">(opcional)</span></span>
        <input value={nota} onChange={(e) => setNota(e.target.value)} placeholder="Vacaciones, médico, cambio acordado…" /></label>
    </Modal>
  )
}

/* --------------------------- Generar la rotación --------------------------
 * El patrón lo define el USUARIO: una lista de semanas con sus dos turnos
 * independientes, del largo que quiera, que se repite hasta la fecha de fin.
 * La versión anterior traía el ciclo de AEME cocido en el código y no se podía
 * cambiar; si mañana rotan de otra forma, aquí no hay nada que tocar.
 * ------------------------------------------------------------------------- */
function ModalRotar({ desde, agents, onClose, onDone, onIr }) {
  const toast = useToast()
  const lunes = (iso) => (iso ? masDias(iso, -((new Date(iso + 'T00:00:00').getDay() + 6) % 7)) : '')

  const [ini, setIni] = useState(() => lunes(desde) || lunesDeHoy())
  const [fin, setFin] = useState(() => masDias(lunes(desde) || lunesDeHoy(), 7 * 7))
  const [patron, setPatron] = useState([{ morning: '', afternoon: '' }])
  const [pisar, setPisar] = useState(false)
  const [previa, setPrevia] = useState(null)
  const [enviando, setEnviando] = useState(false)

  const opciones = [{ value: '', label: '— nadie —' }, ...agents.map((a) => ({ value: String(a.id), label: a.name }))]
  const nombre = (id) => agents.find((a) => String(a.id) === String(id))?.name || '— nadie —'

  const payload = () => ({
    from: ini,
    to: fin,
    pattern: patron.map((p) => ({ morning: Number(p.morning) || 0, afternoon: Number(p.afternoon) || 0 })),
  })

  /* La previa se pide al servidor: es el MISMO cálculo que luego escribe, así que
     lo que se enseña no puede diferir de lo que acaba pasando. */
  useEffect(() => {
    const t = setTimeout(() => {
      api.previewRotation({ ...payload(), overwrite: pisar })
        .then((r) => setPrevia(r.ok ? r : { error: r.error }))
    }, 250)
    return () => clearTimeout(t)
  }, [ini, fin, pisar, JSON.stringify(patron)])   // eslint-disable-line react-hooks/exhaustive-deps

  const cambiar = (i, turno, v) => setPatron((s) => s.map((p, j) => (j === i ? { ...p, [turno]: v } : p)))
  const quitar = (i) => setPatron((s) => (s.length > 1 ? s.filter((_, j) => j !== i) : s))
  /* El tope se comprueba DENTRO de la actualización, no solo con `disabled`: diez
     clics en el mismo instante se aplican todos antes de que el botón se repinte. */
  const anadir = () => setPatron((s) => {
    const tope = semanasEntre(ini, fin)
    return tope > 0 && s.length >= tope ? s : [...s, { morning: '', afternoon: '' }]
  })

  /* Propone un ciclo en el que todos pasan por los dos turnos. Es una SUGERENCIA
     para no picarlo a mano: se puede cambiar entera. */
  const autocompletar = () => {
    if (agents.length < 2) { toast('Hacen falta al menos dos agentes', 'err'); return }
    setPatron(agents.map((_, i) => ({
      morning: String(agents[i % agents.length].id),
      afternoon: String(agents[(i + 1) % agents.length].id),
    })))
  }

  const generar = async () => {
    setEnviando(true)
    const r = await api.rotateShifts({ ...payload(), overwrite: pisar })
    setEnviando(false)
    if (r.ok) { onDone(r.filled); return }
    /* El servidor vuelve a comprobarlo al escribir: la previa puede haberse
       quedado vieja si otro ha anotado algo mientras el modal estaba abierto. */
    if (r.revisar) setPrevia((p) => ({ ...(p || {}), revisar: r.revisar }))
    toast(r.error || 'Error', 'err')
  }

  const conflictos = previa?.conflicts || []
  const semanas = previa?.weeks || []
  /* Semanas que cambian de titular y tienen notas o sustituciones: lo que se
     escribió a mano dejaría de cuadrar, así que hay que repasarlo antes. */
  const revisar = previa?.revisar || []

  /* Se calcula AQUÍ, no con la vista previa del servidor: mientras el patrón está
     vacío esa no llega, y sin este número el tope no se aplicaba. */
  const semanasPeriodo = semanasEntre(ini, fin)
  const lleno = semanasPeriodo > 0 && patron.length >= semanasPeriodo

  return (
    <Modal title="Generar rotación" onClose={onClose} onSave={generar} saveDisabled={enviando || revisar.length > 0}
      saveLabel={enviando ? 'Generando…' : `Generar ${semanas.length || ''} semanas`.trim()} width={640}>

      <div className="grid2">
        <label className="field"><span className="lbl">Empezar el <em>*</em></span>
          <input type="date" value={ini} onChange={(e) => setIni(lunes(e.target.value))} />
          <span className="hint">{ini ? `lunes ${fmtDia(ini)}` : 'elige una fecha'}</span></label>
        <label className="field"><span className="lbl">Repetir hasta <em>*</em></span>
          <input type="date" value={fin} onChange={(e) => setFin(lunes(e.target.value))} />
          <span className="hint">{semanas.length ? `${semanas.length} semanas` : '—'}</span></label>
      </div>

      <div className="field">
        <div className="tn-dias-h">
          <span className="lbl">Patrón que se repite</span>
          <button type="button" className="btn ghost sm" onClick={autocompletar}>Autocompletar patrón</button>
        </div>

        <div className="rot-tabla">
          <div className="rot-cab">
            <span></span><span>Mañana</span><span>Tarde</span><span></span>
          </div>
          {patron.map((p, i) => (
            <div key={i} className="rot-fila">
              <span className="rot-n">Semana {i + 1}</span>
              <Select block sm value={p.morning} options={opciones} onChange={(v) => cambiar(i, 'morning', v)} />
              <Select block sm value={p.afternoon} options={opciones} onChange={(v) => cambiar(i, 'afternoon', v)} />
              <button type="button" className="icon-btn sm" title="Quitar semana"
                disabled={patron.length < 2} onClick={() => quitar(i)}>✕</button>
            </div>
          ))}
        </div>

        {/* Un patrón más largo que el periodo tiene semanas que no se ejecutan nunca:
            no se dejan añadir, y si el periodo se acorta después, se avisa. */}
        <button type="button" className="btn ghost sm" style={{ marginTop: 8 }} onClick={anadir}
          disabled={lleno}
          title={lleno
            ? `El periodo son ${semanasPeriodo} semanas: un patrón más largo no llegaría a repetirse`
            : 'Añade otra semana al ciclo'}>
          <Icon.plus /> Añadir semana al ciclo
        </button>
        <span className="hint" style={{ display: 'block', marginTop: 6 }}>
          {lleno
            ? <>El patrón ya cubre las <b>{semanasPeriodo} semanas</b> del periodo. Para alargarlo, mueve la fecha de fin.</>
            : <>Al llegar al final del patrón vuelve a empezar. Puedes dejar un turno sin nadie.</>}
        </span>

        {/* Puede pasar al acortar las fechas DESPUÉS de montar el patrón. */}
        {semanasPeriodo > 0 && patron.length > semanasPeriodo && (
          <p className="rot-sobra">
            El patrón tiene {patron.length} semanas y el periodo solo {semanasPeriodo}:
            las {patron.length - semanasPeriodo} últimas no se usarán.
          </p>
        )}
      </div>

      {previa?.error && <p className="lnk-err">{previa.error}</p>}

      {semanas.length > 0 && (
        <div className="tn-prev">
          <div className="tn-prev-h">Quedaría así</div>
          {semanas.slice(0, 5).map((s) => (
            <div key={s.week_start} className="tn-prev-r">
              <span className="tn-prev-w">{s.label}</span>
              <span><i>Mañana</i> {nombre(s.plan.morning)}</span>
              <span><i>Tarde</i> {nombre(s.plan.afternoon)}</span>
            </div>
          ))}
          {semanas.length > 5 && <div className="tn-prev-mas">…y así hasta el {fmtDia(semanas[semanas.length - 1].week_start)}</div>}
        </div>
      )}

      {/* El aviso solo existe si de verdad va a pisar algo, y dice QUÉ. */}
      {conflictos.length > 0 && (
        <div className="rot-aviso">
          <div className="rot-aviso-h">
            <Icon.warn />
            <b>{conflictos.length === 1 ? '1 semana ya tiene turno asignado' : `${conflictos.length} semanas ya tienen turno asignado`}</b>
          </div>
          <p>{conflictos.slice(0, 4).map((c) => c.label).join(' · ')}
            {conflictos.length > 4 && ` y ${conflictos.length - 4} más`}</p>

          <label className="rot-op">
            <input type="radio" checked={!pisar} onChange={() => setPisar(false)} />
            <span><b>Dejarlas como están</b><small>Solo se rellenan las semanas vacías</small></span>
          </label>
          <label className="rot-op">
            <input type="radio" checked={pisar} onChange={() => setPisar(true)} />
            <span><b>Reemplazarlas por el patrón</b><small>Se pierde lo que hubiera puesto a mano</small></span>
          </label>
        </div>
      )}

      {/* Bloqueo. Una nota o una sustitución la escribió alguien a propósito: si
          cambia el titular de esa semana dejan de tener sentido —la sustitución
          puede acabar apuntando a la propia persona—, así que no se pisa a ciegas. */}
      {revisar.length > 0 && (
        <div className="rot-stop">
          <div className="rot-stop-h">
            <Icon.warn />
            <div>
              <b>Hay cosas anotadas que se descuadrarían</b>
              <small>{revisar.length === 1 ? 'Una semana cambia de titular' : `${revisar.length} semanas cambian de titular`} y
                tienen notas o sustituciones. Repásalas y vuelve.</small>
            </div>
          </div>
          {revisar.map((s) => (
            <div key={s.week_start} className="rot-stop-s">
              <div className="rot-stop-w">
                <b>{s.label}</b>
                <button type="button" className="btn ghost sm" onClick={() => onIr?.(s.month)}>Ver en el calendario</button>
              </div>
              <ul>
                {s.subs.map((x, i) => (
                  <li key={'s' + i}><i>Sustitución</i> {x.quien} · {x.turno} · {fmtDia(x.desde)}
                    {x.hasta !== x.desde && ` – ${fmtDia(x.hasta)}`}</li>
                ))}
                {s.notas.map((n, i) => (
                  <li key={'n' + i}><i>Nota</i> {fmtDia(n.date)} · {n.note}</li>
                ))}
              </ul>
            </div>
          ))}
        </div>
      )}
    </Modal>
  )
}
function Modal({ title, children, onClose, onSave, saveLabel, saveDisabled = false, width = 560 }) {
  useEffect(() => {
    const h = (e) => e.key === 'Escape' && onClose()
    document.addEventListener('keydown', h)
    return () => document.removeEventListener('keydown', h)
  }, [onClose])
  return (
    <div className="modal-bg" onClick={(e) => e.target.classList.contains('modal-bg') && onClose()}>
      <div className="modal" style={{ maxWidth: width }}>
        <div className="modal-h"><h3>{title}</h3><button className="icon-btn" onClick={onClose}>✕</button></div>
        <div className="modal-body">{children}</div>
        <div className="modal-foot">
          <button className="btn ghost" onClick={onClose}>Cancelar</button>
          <button className="btn" onClick={onSave} disabled={saveDisabled}>{saveLabel}</button>
        </div>
      </div>
    </div>
  )
}
