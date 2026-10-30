import { useState, useEffect, useCallback, useRef } from 'react'
import { api } from '../api.js'
import { Icon } from '../icons.jsx'
import Select from './Select.jsx'

/* -------------------------------------------------------------------------
 * CENTRO DE DISPOSITIVOS (Gestión de garantías). Solo consulta.
 *
 * Arriba: el servidor que se consulta y los tres modos (Etiquetas, Antenas, Buscar).
 * Etiquetas y Antenas: cifras del servidor (también filtran al pincharlas) y una
 * tabla paginada con sus filtros. Buscar: varios códigos a la vez en TODOS los
 * servidores. Pinchar un código abre su ficha en un panel lateral: dónde está ahora
 * (si sale en varios servidores, cuál es el vivo), en qué otros aparece y el
 * historial que guardamos aquí.
 *
 * El artículo de cada etiqueta NO viene con la lista: el servidor tarda ~0,3 s por
 * etiqueta, así que la tabla se pinta ya y los artículos se rellenan por tandas.
 * ---------------------------------------------------------------------- */

const MODOS = [
  { key: 'esl', label: 'Etiquetas', icon: Icon.tag },
  { key: 'ap', label: 'Antenas', icon: Icon.antenna },
  { key: 'search', label: 'Buscar', icon: Icon.search, badge: 'Multiservidor' },
]
const ESTADO_ESL = { 0: ['Sin conexión', 'off'], 1: ['En línea', 'on'], 2: ['Actualizando', 'upd'], 3: ['Fallo al actualizar', 'fail'] }
const BATERIA = [['', 'Batería: cualquiera'], ['5', 'Llena'], ['4', 'Alta'], ['3', 'Media'], ['2', 'Baja'], ['1', 'Crítica']]
const TAMS = [25, 50, 100]
const VACIO = { code: '', stores: [], models: [], versions: [], state: '', online: '', battery: '' }
const TANDA = 25          // etiquetas por petición de artículos
const SRV_KEY = 'devices_server'

// Los servidores dan la fecha como texto o como milisegundos.
function aFecha(v) {
  if (v === null || v === undefined || v === '') return null
  const d = typeof v === 'number' || /^\d{10,}$/.test(String(v)) ? new Date(Number(v)) : new Date(String(v).replace(' ', 'T'))
  return isNaN(d) ? null : d
}
export function fmtFecha(v) {
  const d = aFecha(v)
  if (!d) return v ? String(v) : '—'
  return d.toLocaleString('es-ES', { day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit' })
}
// «hace 5 min», «hace 3 d»: lo que importa en una lista es cuánto hace, no la hora exacta.
function hace(v) {
  const d = aFecha(v)
  if (!d) return '—'
  const s = Math.max(0, (Date.now() - d.getTime()) / 1000)
  if (s < 60) return 'ahora'
  if (s < 3600) return `hace ${Math.floor(s / 60)} min`
  if (s < 86400) return `hace ${Math.floor(s / 3600)} h`
  if (s < 86400 * 60) return `hace ${Math.floor(s / 86400)} d`
  return d.toLocaleDateString('es-ES', { day: '2-digit', month: '2-digit', year: '2-digit' })
}
const num = (n) => (n == null ? '—' : Number(n).toLocaleString('es-ES'))
const pct = (v, t) => (t ? (v / t) * 100 : 0)
const fmtPct = (p) => p.toLocaleString('es-ES', { maximumFractionDigits: 1 }) + '%'

const estadoDe = (kind, row) => (kind === 'ap'
  ? (Number(row.online) === 1 ? ['En línea', 'on'] : ['Sin conexión', 'off'])
  : (ESTADO_ESL[Number(row.state)] || ['—', 'off']))

function Estado({ kind, row }) {
  const [txt, cls] = estadoDe(kind, row)
  return <span className={`dev-pill ${cls}`}><i />{txt}</span>
}

function Bateria({ row }) {
  const p = row.battery
  if (p === null || p === undefined || p === '') return <span className="dev-mute">—</span>
  const n = Math.max(0, Math.min(100, Number(p)))
  const cls = n < 20 ? 'low' : n < 50 ? 'mid' : ''
  return <span className={`dev-bat ${cls}`}><span className="dev-bat-bar"><i style={{ width: `${n}%` }} /></span>{n}%</span>
}

const COLS = {
  esl: [
    ['barCode', 'Código'], ['state', 'Estado'], ['storeName', 'Tienda'], ['itemTitle', 'Artículo'],
    ['model', 'Modelo'], ['battery', 'Batería'], ['softVersion', 'Firmware'], ['apSignal', 'Señal'], ['lastCommuTime', 'Última com.'],
  ],
  ap: [
    ['mac', 'MAC'], ['online', 'Estado'], ['storeName', 'Tienda'], ['apName', 'Nombre'],
    ['model', 'Modelo'], ['eslCount', 'Etiquetas'], ['ip', 'IP'], ['softVersion', 'Firmware'], ['offLineTime', 'Última caída'],
  ],
}

function descargar(nombre, lineas) {
  const blob = new Blob(['﻿' + lineas.join('\r\n')], { type: 'text/csv;charset=utf-8' })
  const a = document.createElement('a')
  a.href = URL.createObjectURL(blob)
  a.download = `${nombre}-${new Date().toISOString().slice(0, 10)}.csv`
  a.click()
  setTimeout(() => URL.revokeObjectURL(a.href), 1000)
}
const esc = (v) => `"${String(v ?? '').replace(/"/g, '""')}"`

// Exporta lo que se ve a CSV (Excel lo abre con el BOM y el punto y coma).
function exportar(kind, rows) {
  const cols = COLS[kind]
  const txt = (r, k) => (k === 'state' || k === 'online' ? estadoDe(kind, r)[0]
    : k === 'lastCommuTime' || k === 'offLineTime' ? fmtFecha(r[k])
    : k === 'storeName' ? [r.storeName, r.merchantName].filter(Boolean).join(' · ')
    : k === 'itemTitle' ? [r.itemTitle, r.itemBarCode].filter(Boolean).join(' · ')
    : r[k])
  descargar(kind === 'ap' ? 'antenas' : 'etiquetas', [cols.map(([, l]) => esc(l)).join(';'), ...rows.map((r) => cols.map(([k]) => esc(txt(r, k))).join(';'))])
}

function Tienda({ r }) {
  return r.storeName
    ? <div className="dev-two" title={[r.storeName, r.merchantName].filter(Boolean).join(' · ')}><b>{r.storeName}</b>{r.merchantName ? <small>{r.merchantName}</small> : null}</div>
    : <span className="dev-mute">Sin vincular</span>
}

function Articulo({ r, items }) {
  if (Number(r.bindState) !== 1) return <span className="dev-mute">Sin artículo</span>
  const it = items?.[r.barCode]
  if (it === undefined) return <span className="dev-skel" />
  return it?.itemTitle
    ? <div className="dev-two" title={[it.itemTitle, it.itemBarCode].filter(Boolean).join(' · ')}><span>{it.itemTitle}</span>{it.itemBarCode ? <small>{it.itemBarCode}</small> : null}</div>
    : <span className="dev-mute">—</span>
}

function Celda({ kind, k, r, onOpen, items }) {
  switch (k) {
    case 'barCode':
    case 'mac':
      return <button className="dev-code" onClick={() => onOpen(r[k])}>{r[k]}</button>
    case 'state':
    case 'online':
      return <Estado kind={kind} row={r} />
    case 'battery':
      return <Bateria row={r} />
    case 'storeName':
      return <Tienda r={r} />
    case 'itemTitle':
      return <Articulo r={r} items={items} />
    case 'model':
      return r[k] ? <span className="dev-tag">{r[k]}</span> : <span className="dev-mute">—</span>
    case 'eslCount':
      return r[k] != null ? <span className="dev-count">{num(r[k])}</span> : <span className="dev-mute">—</span>
    case 'softVersion':
    case 'ip':
      return r[k] ? <span className="dev-mono">{r[k]}</span> : <span className="dev-mute">—</span>
    case 'lastCommuTime':
    case 'offLineTime':
      return <span className="dev-mute" title={fmtFecha(r[k])}>{hace(r[k])}</span>
    default:
      return r[k] ?? <span className="dev-mute">—</span>
  }
}

/* Cifras del servidor. Pinchar una filtra la lista por ella (y otra vez la quita). */
function Cifras({ kind, stats, f, onPick }) {
  const tiles = kind === 'ap'
    ? [['total', 'Total antenas', null, 'estaciones'], ['online', 'En línea', ['online', '1'], 'Pulsar para filtrar'], ['offline', 'Sin conexión', ['online', '0'], 'Requiere revisión']]
    : [['total', 'Total etiquetas', null, 'en el servidor'], ['online', 'En línea', ['state', '1'], 'Pulsar para filtrar'], ['offline', 'Sin conexión', ['state', '0'], 'Requiere revisión'], ['low_battery', 'Batería crítica', ['battery', '1'], 'Sustitución recomendada']]
  return (
    <div className={`dev-kpis n${tiles.length}`}>
      {tiles.map(([k, label, filtro, hint]) => {
        const v = stats?.[k]
        const on = filtro ? f[filtro[0]] === filtro[1] : false
        const p = k === 'total' ? 100 : pct(v, stats?.total)
        return (
          <button key={k} className={`dev-kpi k-${k} ${on ? 'on' : ''}`} disabled={!filtro} onClick={() => filtro && onPick(filtro[0], on ? '' : filtro[1])}>
            <span className="k">{label}<i /></span>
            <span className="v">
              {stats ? num(v) : <span className="dev-skel w60" />}
              {stats && k !== 'total' ? <small>{fmtPct(p)}</small> : null}
            </span>
            <span className="h">{on ? <><Icon.check /> Filtrando · pulsar para quitar</> : hint}</span>
            <span className="dev-kpi-bar"><i style={{ width: stats ? `${Math.max(p, k === 'total' ? 0 : 1)}%` : 0 }} /></span>
          </button>
        )
      })}
    </div>
  )
}

// Páginas a mostrar: 1 … 4 5 [6] 7 8 … 120
function paginasVisibles(actual, total) {
  if (total <= 7) return Array.from({ length: total }, (_, i) => i)
  const set = new Set([0, total - 1, actual - 1, actual, actual + 1].filter((n) => n >= 0 && n < total))
  if (actual < 3) [1, 2, 3].forEach((n) => set.add(n))
  if (actual > total - 4) [total - 2, total - 3, total - 4].forEach((n) => set.add(n))
  const orden = [...set].sort((a, b) => a - b)
  const out = []
  orden.forEach((n, i) => { if (i && n - orden[i - 1] > 1) out.push(null); out.push(n) })
  return out
}

function Paginas({ page, paginas, onGo, disabled }) {
  if (paginas <= 1) return null
  return (
    <div className="dev-pages">
      <button disabled={page === 0 || disabled} onClick={() => onGo(page - 1)}><Icon.chevron style={{ transform: 'rotate(90deg)' }} /> <span>Anterior</span></button>
      {paginasVisibles(page, paginas).map((n, i) => (n === null
        ? <span key={'g' + i} className="gap">…</span>
        : <button key={n} className={n === page ? 'on' : ''} disabled={disabled} onClick={() => onGo(n)}>{num(n + 1)}</button>))}
      <button disabled={page + 1 >= paginas || disabled} onClick={() => onGo(page + 1)}><span>Siguiente</span> <Icon.chevron style={{ transform: 'rotate(-90deg)' }} /></button>
    </div>
  )
}

/* Tarjeta de un dispositivo para el móvil (la tabla no cabe). */
function Tarjeta({ kind, r, items, onOpen }) {
  const code = kind === 'ap' ? r.mac : r.barCode
  const [, cls] = estadoDe(kind, r)
  return (
    <div className={`dev-mcard ${cls}`}>
      <div className="dev-mcard-h">
        <i className={`dot ${cls}`} />
        <button className="dev-code" onClick={() => onOpen(code)}>{code}</button>
        {r.model ? <span className="dev-tag">{r.model}</span> : null}
        <span className="spacer" />
        {kind === 'esl' ? <Bateria row={r} /> : <Estado kind={kind} row={r} />}
      </div>
      {kind === 'esl' ? <div className="dev-mcard-t"><Articulo r={r} items={items} /></div> : <div className="dev-mcard-t"><b>{r.apName || '—'}</b></div>}
      <div className="dev-mcard-s"><Icon.building /> {r.storeName || 'Sin vincular'}{r.merchantName ? ` · ${r.merchantName}` : ''}</div>
      <div className="dev-mcard-f">
        <span>{kind === 'ap' ? `${num(r.eslCount)} etiquetas` : r.softVersion || '—'}</span>
        <span className={cls === 'off' ? 'bad' : ''}><Icon.clock /> {hace(kind === 'ap' ? r.offLineTime : r.lastCommuTime)}</span>
      </div>
    </div>
  )
}

/* --- Lista de un servidor (etiquetas o antenas) --- */
function Lista({ kind, server, stats, onOpen }) {
  const [opts, setOpts] = useState(null)        // { stores, models, versions }
  const [f, setF] = useState(VACIO)
  const [codeLive, setCodeLive] = useState('')
  const [page, setPage] = useState(0)
  const [size, setSize] = useState(25)
  const [data, setData] = useState(null)        // { rows, total } | { error }
  const [loading, setLoading] = useState(true)
  const [items, setItems] = useState({})        // barCode → {itemTitle, itemBarCode} | null
  const pedida = useRef(0)                       // descarta respuestas de peticiones viejas

  useEffect(() => { setF(VACIO); setCodeLive(''); setPage(0); setData(null) }, [server, kind])

  useEffect(() => {
    setOpts(null)
    api.deviceFilters(server, kind).then((r) => setOpts(r.ok ? r : { stores: [], models: [], versions: [] }))
  }, [server, kind])

  useEffect(() => {
    // Solo si cambió: si no, al montar se pediría la misma página dos veces.
    const t = setTimeout(() => { setF((s) => (s.code === codeLive.trim() ? s : { ...s, code: codeLive.trim() })); setPage(0) }, 400)
    return () => clearTimeout(t)
  }, [codeLive])

  const cargar = useCallback(() => {
    const n = ++pedida.current
    setLoading(true)
    const filtros = kind === 'ap'
      ? { code: f.code, stores: f.stores, models: f.models, versions: f.versions, online: f.online }
      : { code: f.code, stores: f.stores, models: f.models, versions: f.versions, state: f.state, battery: f.battery }
    api.deviceList(server, kind, filtros, page, size).then(async (r) => {
      if (n !== pedida.current) return
      setLoading(false)
      setData(r.ok ? r : { error: r.error || 'No se pudo consultar el servidor' })
      if (!r.ok || kind !== 'esl') return
      // Artículos por tandas, sin bloquear la tabla.
      setItems({})
      const pend = r.rows.filter((x) => Number(x.bindState) === 1 && x.storeId).map((x) => ({ storeId: x.storeId, barCode: x.barCode }))
      for (let i = 0; i < pend.length; i += TANDA) {
        const lote = pend.slice(i, i + TANDA)
        const res = await api.deviceItems(server, lote)
        if (n !== pedida.current) return
        const got = res.ok ? res.items : {}
        setItems((prev) => ({ ...prev, ...Object.fromEntries(lote.map((x) => [x.barCode, got[x.barCode] || null])) }))
      }
    })
  }, [server, kind, f, page, size])
  useEffect(() => { cargar() }, [cargar])

  const set = (k) => (v) => { setF((s) => ({ ...s, [k]: v })); setPage(0) }
  const filtrando = f.code || f.stores.length || f.models.length || f.versions.length || f.state !== '' || f.online !== '' || f.battery !== ''
  const total = data?.total || 0
  const paginas = Math.ceil(total / size)
  const desde = total ? page * size + 1 : 0
  const hasta = Math.min(total, (page + 1) * size)
  const filas = data?.rows?.map((r) => (kind === 'esl' && items[r.barCode] ? { ...r, ...items[r.barCode] } : r)) || []
  const nombre = kind === 'ap' ? 'antenas' : 'etiquetas'
  const caido = (r) => estadoDe(kind, r)[1] === 'off'

  return (
    <>
      <Cifras kind={kind} stats={stats} f={f} onPick={(k, v) => set(k)(v)} />

      <div className={`dev-card ${loading ? 'busy' : ''}`}>
        {loading && <div className="dev-progress" />}
        <div className="dev-tools">
          <div className="dev-search">
            <Icon.search />
            <input value={codeLive} onChange={(e) => setCodeLive(e.target.value)} placeholder={kind === 'ap' ? 'Buscar por MAC…' : 'Buscar por código de etiqueta…'} />
            {codeLive ? <button className="dev-x" onClick={() => setCodeLive('')} title="Borrar">×</button> : null}
          </div>
          <Select sm multiple searchable value={f.stores} onChange={set('stores')} options={opts?.stores || []} placeholder={opts ? `Todas las tiendas${opts.stores?.length ? ` (${num(opts.stores.length)})` : ''}` : 'Cargando tiendas…'} />
          <Select sm multiple value={f.models} onChange={set('models')} options={(opts?.models || []).map((m) => ({ value: m, label: m }))} placeholder="Modelos: todos" />
          <Select sm multiple value={f.versions} onChange={set('versions')} options={(opts?.versions || []).map((v) => ({ value: v, label: v }))} placeholder="Firmware: todos" />
          {kind === 'esl' && <Select sm value={f.battery} onChange={set('battery')} options={BATERIA.map(([value, label]) => ({ value, label }))} />}
          {filtrando ? <button className="dev-clear" onClick={() => { setF(VACIO); setCodeLive(''); setPage(0) }}><Icon.x /> Limpiar</button> : null}
          <span className="spacer" />
          <button className="dev-btn" disabled={!filas.length} onClick={() => exportar(kind, filas)}><Icon.download /> Exportar CSV</button>
        </div>

        {data?.error ? <div className="dev-error in"><Icon.warn /> {data.error}</div> : (
          <>
            <div className="dev-table-wrap">
              <table className="dev-table">
                <thead><tr>{COLS[kind].map(([k, l]) => <th key={k} className={`c-${k}`}>{l}</th>)}</tr></thead>
                <tbody>
                  {data === null
                    ? Array.from({ length: 8 }, (_, i) => <tr key={i}>{COLS[kind].map(([k]) => <td key={k}><span className="dev-skel" /></td>)}</tr>)
                    : filas.map((r, i) => (
                      <tr key={(r.id ?? '') + '-' + i} className={caido(r) ? 'is-off' : ''}>
                        {COLS[kind].map(([k]) => <td key={k} className={`c-${k}`}><Celda kind={kind} k={k} r={r} onOpen={onOpen} items={kind === 'esl' ? items : null} /></td>)}
                      </tr>
                    ))}
                </tbody>
              </table>
            </div>
            <div className="dev-mlist">
              {data === null ? <div className="center-load"><div className="spinner" /></div>
                : filas.map((r, i) => <Tarjeta key={(r.id ?? '') + '-' + i} kind={kind} r={r} items={items} onOpen={onOpen} />)}
            </div>
            {data && !filas.length && (
              <div className="act-empty"><Icon.search style={{ width: 30, height: 30, fill: 'var(--ink-3)' }} /><p>{filtrando ? 'Ningún dispositivo coincide con el filtro.' : 'Este servidor no tiene dispositivos.'}</p></div>
            )}
            <div className="dev-foot">
              <span className="dev-mute">{total ? <>Mostrando <b>{num(desde)}–{num(hasta)}</b> de <b>{num(total)}</b> {nombre}</> : '—'}</span>
              <span className="dev-foot-size"><span className="dev-mute">Por página</span>
                <Select sm value={size} onChange={(v) => { setSize(Number(v)); setPage(0) }} options={TAMS.map((n) => ({ value: n, label: String(n) }))} />
              </span>
              <span className="spacer" />
              <Paginas page={page} paginas={paginas} disabled={loading} onGo={setPage} />
            </div>
          </>
        )}
      </div>
    </>
  )
}

/* --- Búsqueda de varios códigos en todos los servidores --- */
function Buscar({ servers, onOpen }) {
  const [txt, setTxt] = useState('')
  const [res, setRes] = useState(null)
  const [busy, setBusy] = useState(false)
  const [ver, setVer] = useState('all')        // all | found | missing

  const codigos = txt.split(/[\s,;]+/).filter(Boolean)
  const buscar = async () => {
    if (!codigos.length || busy) return
    setBusy(true)
    const r = await api.deviceSearch(txt)
    setBusy(false)
    setVer('all')
    setRes(r.ok ? r : { error: r.error || 'No se pudo buscar' })
  }

  const results = res?.results || []
  const encontrados = results.filter((x) => x.rows.length)
  const noEstan = results.filter((x) => !x.rows.length)
  const porServidor = (name) => results.reduce((n, x) => n + x.rows.filter((r) => r.server === name).length, 0)
  const maxSrv = Math.max(1, ...servers.map((s) => porServidor(s.name)))
  const visibles = ver === 'found' ? encontrados : ver === 'missing' ? noEstan : results

  const exportarBusqueda = () => {
    const cab = ['Código', 'Tipo', 'Servidor', 'Estado', 'Tienda', 'Empresa', 'Modelo', 'Firmware']
    const lineas = results.flatMap((x) => (x.rows.length
      ? x.rows.map((r) => [x.code, r.kind === 'ap' ? 'Antena' : 'Etiqueta', r.server, estadoDe(r.kind, r)[0], r.storeName, r.merchantName, r.model, r.softVersion])
      : [[x.code, '', '', 'No encontrado']]))
    descargar('busqueda', [cab.map(esc).join(';'), ...lineas.map((l) => l.map(esc).join(';'))])
  }

  return (
    <>
      <div className="dev-search-grid">
        <div className="dev-panel">
          <div className="dev-panel-h">
            <span><Icon.list /> Códigos a buscar <small>(uno por línea)</small></span>
            {txt ? <button className="dev-link" onClick={() => { setTxt(''); setRes(null) }}><Icon.trash /> Borrar</button> : null}
          </div>
          <textarea className="dev-codes" rows={9} value={txt} onChange={(e) => setTxt(e.target.value)}
            onKeyDown={(e) => { if (e.key === 'Enter' && (e.ctrlKey || e.metaKey)) buscar() }}
            placeholder={'805804112\nA0:A3:B8:27:3D:71\n…'} />
          <div className="dev-panel-f"><Icon.info /> Códigos de etiqueta o MAC de antena (con o sin dos puntos), separados por líneas o comas. Hasta 50.</div>
        </div>

        <div className="dev-panel dev-detect">
          <div className="dev-panel-h"><span>Resultado</span>{res && !res.error ? <span className="dev-chip ok">Búsqueda completada</span> : null}</div>
          <div className="dev-detect-sum">
            <div><b>{res && !res.error ? results.length : codigos.length}</b> <span>código{(res ? results.length : codigos.length) === 1 ? '' : 's'}</span></div>
            {res && !res.error ? (
              <div className="dev-detect-sub"><span className="ok"><i /> {encontrados.length} encontrados</span><span className="bad"><i /> {noEstan.length} no localizados</span></div>
            ) : <div className="dev-detect-sub dev-mute">Se busca en los {servers.length} servidores a la vez.</div>}
          </div>
          <ul className="dev-detect-srv">
            {servers.map((s) => {
              const n = porServidor(s.name)
              const fallo = res?.failed?.includes(s.name)
              return (
                <li key={s.id}>
                  <div><span>{s.name}</span><b className={fallo ? 'bad' : ''}>{fallo ? 'Sin respuesta' : res ? `${n} registro${n === 1 ? '' : 's'}` : '—'}</b></div>
                  <span className="dev-kpi-bar"><i style={{ width: res && !fallo ? `${(n / maxSrv) * 100}%` : 0 }} /></span>
                </li>
              )
            })}
          </ul>
          <button className="dev-go" disabled={busy || !codigos.length} onClick={buscar}>
            <Icon.search /> {busy ? 'Buscando…' : 'Buscar en todos los servidores'} <kbd>Ctrl+↵</kbd>
          </button>
        </div>
      </div>

      {res?.error && <div className="dev-error"><Icon.warn /> {res.error}</div>}

      {res && !res.error && (
        <div className="dev-card">
          <div className="dev-tools">
            <b className="dev-tools-t">Dispositivos localizados <span className="dev-count">{encontrados.length} / {results.length}</span></b>
            <div className="dev-seg">
              <button className={ver === 'all' ? 'on' : ''} onClick={() => setVer('all')}>Todos</button>
              <button className={ver === 'found' ? 'on' : ''} onClick={() => setVer('found')}>Encontrados</button>
              <button className={`${ver === 'missing' ? 'on' : ''} bad`} onClick={() => setVer('missing')}>No localizados ({noEstan.length})</button>
            </div>
            <span className="spacer" />
            <button className="dev-btn" onClick={exportarBusqueda}><Icon.download /> Exportar CSV</button>
            <button className="dev-btn" disabled={busy} onClick={buscar}><Icon.refresh /> Volver a buscar</button>
          </div>
          <div className="dev-table-wrap keep">
            <table className="dev-table">
              <thead><tr><th>#</th><th>Código buscado</th><th>Tipo</th><th>Servidor</th><th>Tienda</th><th>Estado</th><th>Modelo · firmware</th><th /></tr></thead>
              <tbody>
                {visibles.flatMap((x) => {
                  const idx = results.indexOf(x) + 1
                  if (!x.rows.length) {
                    return [(
                      <tr key={x.code} className="is-off">
                        <td className="dev-mute">{String(idx).padStart(2, '0')}</td>
                        <td><span className="dev-code bad">{x.code}</span></td>
                        <td className="dev-mute">Desconocido</td>
                        <td colSpan={4}><span className="dev-pill off"><i />No encontrado en ningún servidor</span></td>
                        <td />
                      </tr>
                    )]
                  }
                  return x.rows.map((r, i) => (
                    <tr key={x.code + i} className={i ? 'dev-sub' : ''}>
                      <td className="dev-mute">{i === 0 ? String(idx).padStart(2, '0') : ''}</td>
                      <td>{i === 0 ? <button className="dev-code" onClick={() => onOpen(x.code)}>{x.code}</button> : <span className="dev-mute">↳ también en</span>}</td>
                      <td><span className="dev-type">{r.kind === 'ap' ? <Icon.antenna /> : <Icon.tag />}{r.kind === 'ap' ? 'Antena' : 'Etiqueta'}</span></td>
                      <td><span className="dev-srvtag">{r.server}</span></td>
                      <td className="c-storeName"><Tienda r={r} /></td>
                      <td><Estado kind={r.kind} row={r} /></td>
                      <td><div className="dev-two">{r.model ? <span className="dev-mono">{r.model}</span> : <span className="dev-mute">—</span>}{r.softVersion ? <small>{r.softVersion}</small> : null}</div></td>
                      <td>{i === 0 ? <button className="dev-open" onClick={() => onOpen(x.code)}>Abrir ficha <Icon.arrowRight /></button> : null}</td>
                    </tr>
                  ))
                })}
              </tbody>
            </table>
          </div>
          <div className="dev-foot">
            <span className="dev-mute">Mostrando <b>{visibles.length}</b> de <b>{results.length}</b> códigos consultados</span>
            <span className="spacer" />
            <span className="dev-mute">Servidores consultados: {servers.map((s) => s.name).join(', ')}</span>
          </div>
        </div>
      )}
    </>
  )
}

const CAMPO = { server: 'Servidor', store: 'Tienda', model: 'Modelo', firmware: 'Firmware', ap_mac: 'Antena' }

/* --- Ficha de un dispositivo: panel lateral --- */
function Ficha({ code, servers, onClose, onOpen }) {
  const [d, setD] = useState(null)
  const [copiado, setCopiado] = useState(false)
  useEffect(() => {
    setD(null)
    api.deviceSheet(code).then((r) => setD(r.ok ? r : { error: r.error || 'No se pudo consultar' }))
  }, [code])
  useEffect(() => {
    const k = (e) => e.key === 'Escape' && onClose()
    window.addEventListener('keydown', k)
    return () => window.removeEventListener('keydown', k)
  }, [onClose])

  const v = d?.device
  const esAp = d?.kind === 'ap'
  const filas = v ? (esAp
    ? [['Nombre', v.apName], ['Modelo', v.model], ['Firmware', v.softVersion], ['IP', v.ip], ['Nº de serie', v.sn], ['Etiquetas conectadas', v.eslCount], ['Última caída', fmtFecha(v.offLineTime)], ['Alta', fmtFecha(v.addTime)]]
    : [['Artículo', [v.itemTitle, v.itemBarCode].filter(Boolean).join(' · ') || '—'], ['Modelo', v.model], ['Firmware', v.softVersion], ['Antena', v.apMac], ['Última comunicación', fmtFecha(v.lastCommuTime)], ['Alta', fmtFecha(v.addTime)]])
    : []
  const enOtros = new Set((d?.others || []).map((o) => o.server))
  const ausentes = servers.filter((s) => s.name !== v?.server && !enOtros.has(s.name))

  const copiar = () => {
    navigator.clipboard?.writeText(code).then(() => { setCopiado(true); setTimeout(() => setCopiado(false), 1200) })
  }

  return (
    <div className="dev-drawer-bg" onMouseDown={(e) => e.target === e.currentTarget && onClose()}>
      <aside className="dev-drawer" role="dialog" aria-label={`Ficha de ${code}`}>
        <div className="dev-drawer-h">
          <div className="dev-drawer-t">
            <span className="dev-type">{d ? (esAp ? <><Icon.antenna />Antena</> : <><Icon.tag />Etiqueta</>) : 'Ficha'}</span>
            <h3>{code}<button className="dev-copy" onClick={copiar} title="Copiar código">{copiado ? <Icon.check /> : <Icon.copy />}</button></h3>
          </div>
          <button className="dev-close" onClick={onClose} title="Cerrar (Esc)"><Icon.x /></button>
        </div>
        {v && d.found ? (
          <div className="dev-chips">
            <Estado kind={d.kind} row={v} />
            {!esAp && v.battery != null && v.battery !== '' ? <span className="dev-chip"><Bateria row={v} /></span> : null}
            {!esAp && v.apSignal != null ? <span className="dev-chip">Señal <b>{v.apSignal}</b></span> : null}
            {esAp && v.eslCount != null ? <span className="dev-chip"><b>{num(v.eslCount)}</b> etiquetas</span> : null}
          </div>
        ) : null}

        <div className="dev-drawer-body">
          {d === null ? <div className="center-load"><div className="spinner" /></div>
            : d.error ? <div className="dev-error"><Icon.warn /> {d.error}</div>
            : (
              <>
                {d.failed?.length ? <div className="dev-error"><Icon.warn /> No respondió: {d.failed.join(', ')}.</div> : null}
                {!d.found ? <div className="dev-box"><p className="dev-mute" style={{ margin: 0 }}>No aparece en ningún servidor ahora mismo.</p></div> : (
                  <>
                    <section className="dev-box">
                      <h4><Icon.building /> Tienda</h4>
                      <div className="dev-store"><b>{v.storeName || 'Sin vincular'}</b>{v.merchantName ? <span>{v.merchantName}</span> : null}</div>
                    </section>

                    <section className="dev-box">
                      <h4><Icon.server /> Servidores</h4>
                      <ul className="dev-srvlist">
                        <li className="cur"><i /> <span>Servidor actual: <b>{v.server}</b></span> <span className="dev-chip ok">Vivo</span></li>
                        {(d.others || []).map((o, i) => (
                          <li key={i}><i /> <span>También en <b>{o.server}</b>{o.storeName ? ` · ${o.storeName}` : ''}</span> <span className="dev-mute">{estadoDe(d.kind, o)[0]}</span></li>
                        ))}
                        {ausentes.map((s) => <li key={s.id} className="no"><i /> <span>No está en <b>{s.name}</b></span></li>)}
                      </ul>
                    </section>

                    <section className="dev-box">
                      <h4><Icon.info /> Datos</h4>
                      <dl className="dev-dl">
                        {filas.map(([k, val]) => <div key={k}><dt>{k}</dt><dd>{k === 'Antena' && val ? <button className="dev-code" onClick={() => onOpen(val)}>{val}</button> : (val ?? '—')}</dd></div>)}
                      </dl>
                    </section>
                  </>
                )}

                <section className="dev-box">
                  <h4><Icon.clock /> Historial</h4>
                  {!d.history?.length ? <p className="dev-mute" style={{ margin: 0 }}>Sin historial todavía: se empieza a guardar desde la primera vez que se consulta aquí.</p> : (
                    <ul className="dev-hist">
                      {d.history.map((h, i) => (
                        <li key={i}>
                          <div className="dev-hist-h"><b>{h.server || '—'}{h.store ? ` · ${h.store}` : ''}</b><span className="dev-mute">{fmtFecha(h.first_seen_at)} → {fmtFecha(h.seen_at)}</span></div>
                          {h.changes?.length ? (
                            <div className="dev-hist-c">{h.changes.map((c, j) => <span key={j}>{CAMPO[c.field] || c.field}: <s>{c.before || '—'}</s> → <b>{c.after || '—'}</b></span>)}</div>
                          ) : <div className="dev-hist-c dev-mute">{i === d.history.length - 1 ? 'Primera vez visto' : ''}{h.model ? ` · ${h.model}` : ''}{h.firmware ? ` · ${h.firmware}` : ''}</div>}
                        </li>
                      ))}
                    </ul>
                  )}
                </section>
              </>
            )}
        </div>
        <div className="dev-drawer-f">
          <span className="dev-mute">{v ? `Consultado ahora en ${v.server}` : ''}</span>
          <button className="dev-btn" onClick={onClose}>Cerrar ficha</button>
        </div>
      </aside>
    </div>
  )
}

/* Arriba: el servidor que se consulta y los tres modos. */
function Cabecera({ tab, setTab, servers, server, elegir, stats }) {
  const st = stats.esl
  const conectado = st && st.total != null
  const caido = st && st.total == null
  return (
    <div className="dev-top">
      <div className={`dev-srv ${tab === 'search' ? 'dim' : ''}`}>
        <span className="dev-srv-ic"><Icon.server /></span>
        <div className="dev-srv-tx">
          <span className="dev-srv-lbl">Servidor</span>
          {servers.length > 1
            ? <Select sm value={server} onChange={(v) => elegir(Number(v))} options={servers.map((s) => ({ value: s.id, label: s.name }))} />
            : <b>{servers[0]?.name}</b>}
          <span className={`dev-srv-st ${conectado ? 'on' : caido ? 'off' : ''}`}><i />{tab === 'search' ? 'Buscar usa todos los servidores' : conectado ? 'Conectado' : caido ? 'Sin respuesta' : 'Conectando…'}</span>
        </div>
      </div>

      <div className="dev-tabs" role="tablist">
        {MODOS.map((m) => {
          const Ic = m.icon
          const total = stats[m.key]?.total
          return (
            <button key={m.key} role="tab" aria-selected={tab === m.key} className={tab === m.key ? 'on' : ''} onClick={() => setTab(m.key)}>
              <Ic />
              <span>{m.label}</span>
              {m.badge ? <em className="b">{m.badge}</em>
                : <em>{total == null ? <span className="dev-skel" style={{ width: 40, height: 8 }} /> : num(total)}</em>}
            </button>
          )
        })}
      </div>
    </div>
  )
}

export default function Devices({ canServers = false, onGoServers }) {
  const [tab, setTab] = useState('esl')
  const [servers, setServers] = useState(null)
  const [server, setServer] = useState(null)
  const [stats, setStats] = useState({})        // { esl: {...}, ap: {...} } del servidor elegido
  const [ficha, setFicha] = useState(null)

  // Cifras de los dos tipos a la vez: dan la cuenta de cada pestaña y las tarjetas de la lista.
  useEffect(() => {
    if (!server) return
    setStats({})
    for (const k of ['esl', 'ap']) {
      api.deviceStats(server, k).then((r) => setStats((s) => ({ ...s, [k]: r.ok ? r.stats : {} })))
    }
  }, [server])

  useEffect(() => {
    api.deviceServers().then((r) => {
      const list = r.ok ? r.servers : []
      setServers(list)
      // Se recuerda el último servidor elegido (por navegador).
      let guardado = null
      try { guardado = Number(localStorage.getItem(SRV_KEY)) } catch { /* sin almacenamiento */ }
      setServer((list.find((s) => s.id === guardado) || list[0])?.id ?? null)
    })
  }, [])
  const elegir = (id) => { setServer(id); try { localStorage.setItem(SRV_KEY, String(id)) } catch { /* sin almacenamiento */ } }
  const cerrarFicha = useCallback(() => setFicha(null), [])

  return (
    <>
      <header className="page-head">
        <span className="sc-ic"><Icon.shield style={{ width: 18, height: 18, fill: 'var(--primary)' }} /></span>
        <div><h1>Centro de dispositivos</h1></div>
        <span className="sub">· Etiquetas y antenas de los servidores ZKONG</span>
      </header>

      <div className="page-scroll"><div className="page dev-page">
        {servers === null ? <div className="center-load"><div className="spinner" /></div>
          : servers.length === 0 ? (
            <div className="empty">
              <div className="ico"><Icon.shield /></div>
              <p>Aún no hay servidores ZKONG configurados.</p>
              {canServers && <button className="btn" onClick={onGoServers}><Icon.plus /> Añadir servidor</button>}
            </div>
          ) : (
            <>
              <Cabecera tab={tab} setTab={setTab} servers={servers} server={server} elegir={elegir} stats={stats} />
              {tab === 'search' ? <Buscar servers={servers} onOpen={setFicha} />
                : <Lista key={tab} kind={tab} server={server} stats={stats[tab] || null} onOpen={setFicha} />}
            </>
          )}
      </div></div>

      {ficha && <Ficha code={ficha} servers={servers || []} onClose={cerrarFicha} onOpen={setFicha} />}
    </>
  )
}
