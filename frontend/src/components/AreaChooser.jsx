import { Icon } from '../icons.jsx'

/*
 * Pantalla de bienvenida del superadmin: elige entre Helpdesk y Campañas, tipo
 * «píldoras de Matrix» a pantalla completa (dos mitades 50/50). Solo se muestra a
 * quien tiene acceso a más de un área; los demás entran directos a la suya.
 * Después de elegir se puede seguir cambiando con el selector del sidebar.
 */
const META = {
  helpdesk: {
    tagline: 'Tickets, soporte y atención al cliente',
    c1: '#2563eb', c2: '#1d4ed8', glow: 'rgba(37,99,235,0.42)',
    points: ['Gestión de tickets', 'Web, correo y WhatsApp en una bandeja', 'SLA, categorías y agentes'],
    // mini-gráfico decorativo: barras
    art: 'bars',
  },
  campaigns: {
    tagline: 'Difusiones, plantillas y chat en vivo',
    c1: '#0ea5a4', c2: '#16a34a', glow: 'rgba(15,164,146,0.42)',
    points: ['Envío de campañas y plantillas', 'Chat en vivo y automatizaciones', 'Agenda de contactos y formularios'],
    art: 'send',
  },
}

function Art({ kind }) {
  if (kind === 'bars') {
    const bars = [42, 66, 30, 78, 54, 88]
    return (
      <svg className="chooser-art" viewBox="0 0 200 100" preserveAspectRatio="none" aria-hidden="true">
        {bars.map((h, i) => (
          <rect key={i} x={10 + i * 32} y={100 - h} width="20" height={h} rx="4" opacity={0.25 + i * 0.11} />
        ))}
      </svg>
    )
  }
  // 'send' — trazos de difusión saliendo de un punto
  return (
    <svg className="chooser-art" viewBox="0 0 200 100" preserveAspectRatio="none" aria-hidden="true">
      {[20, 45, 70].map((y, i) => (
        <line key={i} x1="18" y1="50" x2="190" y2={y} strokeWidth="6" strokeLinecap="round" opacity={0.5 - i * 0.12} />
      ))}
      {[80, 55, 30].map((y, i) => (
        <line key={'b' + i} x1="18" y1="50" x2="190" y2={y + 20} strokeWidth="6" strokeLinecap="round" opacity={0.28 - i * 0.07} />
      ))}
      <circle cx="18" cy="50" r="10" />
    </svg>
  )
}

export default function AreaChooser({ areas, user, onPick }) {
  const first = (user?.name || 'Administrador').trim().split(/\s+/)[0]
  return (
    <div className="chooser">
      <div className="chooser-head">
        <span className="chooser-hi">Hola, {first}</span>
        <h1>¿Dónde quieres entrar?</h1>
        <p>Elige un área para empezar. Podrás cambiar cuando quieras desde el menú.</p>
      </div>

      <div className="chooser-grid">
        {areas.map((a) => {
          const m = META[a.key] || { c1: '#2563eb', c2: '#1d4ed8', glow: 'rgba(37,99,235,0.42)', points: [] }
          return (
            <button key={a.key} className="chooser-panel" style={{ '--c1': m.c1, '--c2': m.c2, '--cg': m.glow }} onClick={() => onPick(a.key)}>
              <Art kind={m.art} />
              <span className="chooser-ico"><a.icon /></span>
              <h2>{a.label}</h2>
              <p className="chooser-tag">{m.tagline}</p>
              <ul className="chooser-points">
                {(m.points || []).map((p, i) => <li key={i}><Icon.check /> {p}</li>)}
              </ul>
              <span className="chooser-enter">Entrar <Icon.chevron /></span>
            </button>
          )
        })}
      </div>
    </div>
  )
}
