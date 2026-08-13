import { useRef, useState } from 'react'
import { Icon } from '../icons.jsx'

/* -------------------------------------------------------------------------
 * Reproductor de audio de marca para las notas de voz de WhatsApp. Sustituye
 * al <audio controls> nativo (feo y distinto en cada navegador) por un botón
 * play/pause + una «onda» clicable para buscar + el tiempo. Tema claro/oscuro.
 * ---------------------------------------------------------------------- */

// Alturas de las barras de la onda: patrón FIJO (no aleatorio por render) y agradable.
const BARS = Array.from({ length: 40 }, (_, i) =>
  Math.round((0.32 + 0.6 * Math.abs(Math.sin(i * 1.7) * Math.cos(i * 0.55))) * 100) / 100)

export default function WaAudio({ src }) {
  const ref = useRef(null)
  const [playing, setPlaying] = useState(false)
  const [cur, setCur] = useState(0)
  const [dur, setDur] = useState(0)

  const finite = Number.isFinite(dur) && dur > 0
  const pct = finite ? cur / dur : 0

  const toggle = () => {
    const a = ref.current
    if (!a) return
    if (a.paused) a.play(); else a.pause()
  }
  const seek = (e) => {
    const a = ref.current
    if (!a || !finite) return
    const r = e.currentTarget.getBoundingClientRect()
    a.currentTime = Math.min(1, Math.max(0, (e.clientX - r.left) / r.width)) * dur
  }
  const fmt = (s) => {
    if (!Number.isFinite(s)) s = 0
    return `${Math.floor(s / 60)}:${String(Math.floor(s % 60)).padStart(2, '0')}`
  }

  return (
    <div className="wa-au">
      <audio ref={ref} src={src} preload="metadata"
        onPlay={() => setPlaying(true)} onPause={() => setPlaying(false)}
        onTimeUpdate={() => setCur(ref.current?.currentTime || 0)}
        onLoadedMetadata={() => setDur(ref.current?.duration || 0)}
        onEnded={() => { setPlaying(false); setCur(0) }} />
      <button type="button" className="wa-au-btn" onClick={toggle} aria-label={playing ? 'Pausar' : 'Reproducir'}>
        {playing ? <Icon.pause /> : <Icon.play />}
      </button>
      <div className="wa-au-wave" onClick={seek} role="slider" aria-label="Progreso del audio">
        {BARS.map((h, i) => (
          <span key={i} className={`wa-au-bar ${(i + 0.5) / BARS.length <= pct ? 'on' : ''}`}
            style={{ height: `${Math.max(14, h * 100)}%` }} />
        ))}
      </div>
      <span className="wa-au-t">{fmt(playing || cur ? cur : (finite ? dur : 0))}</span>
    </div>
  )
}
