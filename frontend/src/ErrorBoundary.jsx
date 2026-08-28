import { Component } from 'react'

/*
 * Red de seguridad de la interfaz. Si CUALQUIER componente lanza un error al
 * renderizar, React desmonta todo el árbol y el usuario ve una pantalla en blanco
 * (nos pasó con un icono inexistente en el menú del chat). Este límite lo captura
 * y muestra un aviso con «Recargar» en vez de dejar la app muerta.
 *
 * Tiene que ser un componente de CLASE: los hooks no capturan errores de render.
 */
export default class ErrorBoundary extends Component {
  constructor(props) {
    super(props)
    this.state = { error: null }
  }

  static getDerivedStateFromError(error) {
    return { error }
  }

  componentDidCatch(error, info) {
    // Queda en la consola para poder diagnosticarlo (con el stack del componente).
    console.error('La interfaz ha fallado al renderizar:', error, info?.componentStack)
  }

  render() {
    if (!this.state.error) return this.props.children

    const S = {
      wrap: { minHeight: '100vh', display: 'grid', placeItems: 'center', padding: '24px', boxSizing: 'border-box',
        background: '#0e1522', color: '#e8ecf3', fontFamily: 'system-ui,-apple-system,"Segoe UI",Roboto,sans-serif' },
      card: { maxWidth: '440px', width: '100%', background: '#151d2c', border: '1px solid #26304a', borderRadius: '16px',
        padding: '30px 28px', boxShadow: '0 18px 50px rgba(0,0,0,.45)', textAlign: 'center' },
      ic: { width: '52px', height: '52px', margin: '0 auto 16px', borderRadius: '14px', display: 'grid', placeItems: 'center',
        background: 'rgba(224,166,58,.14)' },
      h: { margin: '0 0 8px', fontSize: '19px', fontWeight: 800, letterSpacing: '-.01em' },
      p: { margin: '0 0 20px', fontSize: '14px', lineHeight: 1.5, color: '#a9b4c9' },
      btn: { appearance: 'none', border: 0, cursor: 'pointer', background: '#2563eb', color: '#fff', fontWeight: 700,
        fontSize: '14px', padding: '11px 22px', borderRadius: '11px' },
      det: { marginTop: '18px', textAlign: 'left' },
      sum: { cursor: 'pointer', fontSize: '12px', color: '#7d8aa6', userSelect: 'none' },
      pre: { marginTop: '8px', maxHeight: '160px', overflow: 'auto', background: '#0e1420', border: '1px solid #26304a',
        borderRadius: '9px', padding: '10px 12px', fontSize: '11.5px', color: '#c0392b', whiteSpace: 'pre-wrap', wordBreak: 'break-word' },
    }

    return (
      <div style={S.wrap}>
        <div style={S.card}>
          <div style={S.ic}>
            <svg viewBox="0 0 24 24" width="26" height="26" fill="#e0a63a"><path d="M1 21h22L12 2 1 21zm12-3h-2v-2h2v2zm0-4h-2v-4h2v4z" /></svg>
          </div>
          <h1 style={S.h}>Algo ha fallado</h1>
          <p style={S.p}>Ha ocurrido un error inesperado en la pantalla. Tus datos están a salvo; normalmente basta con recargar para seguir.</p>
          <button style={S.btn} onClick={() => window.location.reload()}>Recargar la página</button>
          <details style={S.det}>
            <summary style={S.sum}>Ver detalle técnico</summary>
            <pre style={S.pre}>{String(this.state.error?.stack || this.state.error?.message || this.state.error)}</pre>
          </details>
        </div>
      </div>
    )
  }
}
