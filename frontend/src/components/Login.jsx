import { useState, useEffect, useRef } from 'react'
import { api } from '../api.js'
import { Icon } from '../icons.jsx'
import logo from '../assets/logo.png'

/* ---------------------------------------------------------------------------
 * ACCESO — «sala de control».
 *
 * Esto NO es un escaparate: es una herramienta interna que usan cuatro personas
 * varias veces al día. La versión anterior era la del gestor de campañas tal cual
 * —pantalla partida con un carrusel vendiendo funcionalidades—, y además contaba
 * lo que hace la aplicación en la única pantalla que ve alguien que aún no ha
 * entrado. Aquí no se explica nada: marca, hora y dos campos.
 *
 * Lo que da carácter es el AMBIENTE, y no es decoración: el color de fondo sigue
 * la hora real (amanecer, día, tarde, noche), que es exactamente cómo se organiza
 * el trabajo aquí —turnos de mañana 07-15 y de tarde 13-21—. Quien entra a las
 * siete y quien entra a las nueve de la noche no ven la misma pantalla.
 * ------------------------------------------------------------------------- */

/* Franjas del día. Los cortes siguen los turnos reales, no una división genérica. */
const FRANJAS = [
  { h: 5,  k: 'amanecer', saludo: 'Buenos días' },
  { h: 8,  k: 'dia',      saludo: 'Buenos días' },
  /* «Buenas tardes» a las 14, no a las 15: en España se dice después de comer,
     y el turno de tarde ya ha entrado (13-21). */
  { h: 14, k: 'tarde',    saludo: 'Buenas tardes' },
  { h: 21, k: 'noche',    saludo: 'Buenas noches' },
]
/* Antes de las 5 de la mañana sigue siendo «noche»: la lista se recorre al revés
   y, si no hay ninguna franja por debajo, se cae en la última (la de las 21 h). */
const franjaDe = (d) => [...FRANJAS].reverse().find((f) => d.getHours() >= f.h) || FRANJAS[FRANJAS.length - 1]

const DOS = (n) => String(n).padStart(2, '0')

export default function Login({ onLogin }) {
  const [email, setEmail] = useState('')
  const [password, setPassword] = useState('')
  const [verClave, setVerClave] = useState(false)
  const [mayus, setMayus] = useState(false)
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState('')
  const [entrando, setEntrando] = useState(false)   // barrido de luz al acertar
  const [ahora, setAhora] = useState(() => new Date())
  const claveRef = useRef(null)

  /* Reloj vivo. Se reengancha al segundo REAL en cada vuelta (no un intervalo de
     1000 ms a ojo): así el minuto cambia cuando cambia de verdad, y no medio
     segundo tarde y desfasándose más con las horas. */
  useEffect(() => {
    let t
    const tic = () => {
      const d = new Date()
      setAhora(d)
      t = setTimeout(tic, 1000 - d.getMilliseconds())
    }
    tic()
    return () => clearTimeout(t)
  }, [])

  const franja = franjaDe(ahora)
  const fecha = ahora.toLocaleDateString('es-ES', { weekday: 'long', day: 'numeric', month: 'long' })

  /* Aviso de bloqueo de mayúsculas: la causa número uno de «no me deja entrar»
     teniendo la contraseña bien. El navegador solo lo sabe al pulsar una tecla. */
  const mirarMayus = (e) => {
    try { setMayus(e.getModifierState('CapsLock')) } catch { /* navegador antiguo */ }
  }

  const submit = async (e) => {
    e.preventDefault()
    if (!email.trim() || !password) { setError('Escribe tu correo y tu contraseña'); return }
    setBusy(true); setError('')

    const res = await api.login(email.trim(), password)
    if (!res.ok) {
      setBusy(false)
      setError(res.error || 'No se pudo entrar')
      claveRef.current?.focus()
      claveRef.current?.select()
      return
    }

    /*
     * Al acertar, la luz barre la pantalla ANTES de entrar. Son 420 ms: lo justo
     * para que el salto no sea un corte seco. Si el sistema pide menos animación,
     * se salta el adorno y entra directo — nadie debería esperar por un efecto.
     */
    const quieto = window.matchMedia?.('(prefers-reduced-motion: reduce)')?.matches
    if (quieto) { onLogin(res.user); return }
    setEntrando(true)
    setTimeout(() => onLogin(res.user), 420)
  }

  return (
    <div className={`acc ${franja.k} ${entrando ? 'entrando' : ''}`}>
      {/* Ambiente: dos focos que se desplazan muy lento y un velo de grano, que
          es lo que evita que un degradado tan grande salga a bandas. */}
      <div className="acc-luz a" aria-hidden="true" />
      <div className="acc-luz b" aria-hidden="true" />
      <div className="acc-grano" aria-hidden="true" />
      <div className="acc-barrido" aria-hidden="true" />

      <main className="acc-centro">
        <header className="acc-marca">
          {/* El logo se muestra a MENOS de su tamaño real (185 px de ancho): así
              queda nítido también en pantallas de densidad doble, donde una
              imagen a tamaño 1:1 se ve borrosa. */}
          <img className="acc-logo" src={logo} alt="AEME Group" width={148} height={36} />
          <span className="acc-regla" />
          <div className="acc-hora">
            <b>{DOS(ahora.getHours())}<i>:</i>{DOS(ahora.getMinutes())}</b>
            <span>{fecha}</span>
          </div>
        </header>

        <form className="acc-form" onSubmit={submit} noValidate>
          <p className="acc-saludo">{franja.saludo}</p>

          <label className="acc-campo">
            <span>Correo</span>
            <input autoFocus type="email" value={email} autoComplete="username"
              onChange={(e) => setEmail(e.target.value)} placeholder="nombre@aemegroup.com" />
          </label>

          <label className="acc-campo">
            <span>Contraseña</span>
            <div className="acc-clave">
              <input ref={claveRef} type={verClave ? 'text' : 'password'} value={password}
                autoComplete="current-password" onChange={(e) => setPassword(e.target.value)}
                onKeyUp={mirarMayus} onKeyDown={mirarMayus} placeholder="••••••••" />
              <button type="button" tabIndex={-1} onClick={() => setVerClave((v) => !v)}
                title={verClave ? 'Ocultar' : 'Mostrar'}
                aria-label={verClave ? 'Ocultar contraseña' : 'Mostrar contraseña'}>
                {verClave ? <Icon.eyeOff /> : <Icon.eye />}
              </button>
            </div>
            {mayus && <em className="acc-mayus">Bloq Mayús está activado</em>}
          </label>

          {/* El error va donde se mira (bajo los campos, encima del botón) y se
              anuncia: un mensaje que solo cambia de color no existe para quien
              no lo ve. */}
          {error && <p className="acc-error" role="alert"><Icon.warn /> {error}</p>}

          <button className="acc-btn" type="submit" disabled={busy || entrando}>
            <span>{busy ? 'Comprobando…' : 'Entrar'}</span>
            {busy ? <i className="acc-spin" /> : <Icon.arrowRight />}
          </button>
        </form>

        <footer className="acc-pie">
          <span className="acc-punto" /> Acceso restringido
        </footer>
      </main>
    </div>
  )
}
