import { useState, useEffect } from 'react'
import { api } from '../api.js'
import { Icon } from '../icons.jsx'
import { useToast } from '../App.jsx'
import LoadError from './LoadError.jsx'

/* ---------------------------------------------------------------------------
 * Ayuda → Manuales. Lista los PDF que ESTE usuario puede ver (la API los filtra
 * por rol) y permite descargarlos. La descarga es binaria y autenticada: el PDF
 * no está en una URL pública.
 * ------------------------------------------------------------------------- */
export default function Manuals() {
  const toast = useToast()
  const [list, setList] = useState(null)
  const [err, setErr] = useState(false)
  const [busy, setBusy] = useState('')   // key en descarga

  const load = () => { setErr(false); api.listManuals().then((d) => d.ok ? setList(d.manuals || []) : setErr(true)).catch(() => setErr(true)) }
  useEffect(() => { load() }, [])

  const descargar = async (m) => {
    setBusy(m.key)
    const r = await api.downloadManual(m.key)
    setBusy('')
    if (!r.ok) { toast('No se pudo descargar el manual', 'err'); return }
    const url = URL.createObjectURL(r.blob)
    const a = document.createElement('a')
    a.href = url; a.download = r.filename
    document.body.appendChild(a); a.click()
    document.body.removeChild(a); URL.revokeObjectURL(url)
  }

  return (
    <>
      <header className="page-head">
        <span className="ic" style={{ width: 30, height: 30, borderRadius: 9, background: 'var(--primary-soft)', display: 'grid', placeItems: 'center' }}>
          <Icon.doc style={{ width: 17, height: 17, fill: 'var(--primary)' }} />
        </span>
        <div><h1>Manuales</h1></div>
        <span className="sub">· Guías de uso del Helpdesk en PDF</span>
        <div className="spacer" />
      </header>

      <div className="page-scroll">
        <div className="page" style={{ maxWidth: 900 }}>
          <p className="lead" style={{ color: 'var(--ink-3, #6b7280)', marginBottom: 16 }}>
            Aquí tienes los manuales que corresponden a tu puesto. Descárgalos en PDF para consultarlos
            cuando quieras.
          </p>

          {list === null ? <div className="center-load"><div className="spinner" /></div> :
            err ? <LoadError onRetry={load} msg="No se pudieron cargar los manuales" /> :
            list.length === 0 ? (
              <div className="empty"><div className="ico"><Icon.doc /></div><p>Aún no hay manuales disponibles para tu puesto.</p></div>
            ) : (
              <div className="man-grid">
                {list.map((m) => (
                  <div key={m.key} className="man-card">
                    <span className="man-ic"><Icon.doc /></span>
                    <div className="man-meta">
                      <b>{m.title}</b>
                      {m.desc && <span className="man-desc">{m.desc}</span>}
                      <span className="man-sub">PDF · {m.kb} KB</span>
                    </div>
                    <button className="btn" disabled={busy === m.key} onClick={() => descargar(m)}>
                      <Icon.download /> {busy === m.key ? 'Descargando…' : 'Descargar'}
                    </button>
                  </div>
                ))}
              </div>
            )}
        </div>
      </div>
    </>
  )
}
