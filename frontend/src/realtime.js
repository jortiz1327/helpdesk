import Echo from 'laravel-echo'
import Pusher from 'pusher-js'
import { getToken } from './api.js'

/* ---------------------------------------------------------------------------
 * TIEMPO REAL
 *
 * Websocket (Laravel Reverb) sobre un canal PRIVADO. Por el socket solo viaja
 * la SEÑAL de que algo cambió (qué acción, en qué ticket): los datos se vuelven
 * a pedir por la API, que ya comprueba permisos.
 *
 * RED DE SEGURIDAD: si el socket no conecta (servidor Reverb caído, un proxy que
 * corta los websockets, una red rara del cliente), se cae automáticamente a
 * POLLING cada 10 s. La app nunca se queda «muerta» esperando un socket.
 * ------------------------------------------------------------------------- */

window.Pusher = Pusher

const POLL_MS = 10000

let echo = null
let pollTimer = null
let connected = false
let gaveUp = false
const listeners = new Set()

/**
 * Cae a polling Y, si el websocket ya se dio por vencido, CORTA el socket para que
 * el navegador deje de reintentar (si no, llena la consola de errores «WebSocket
 * connection failed» en bucle, típico en local sin el servidor Reverb levantado).
 * En producción con Reverb, 'connected' llega antes y nunca se llega aquí.
 */
function fallback(reason, teardown = false) {
  startPolling(reason)
  if (teardown && !gaveUp && echo) {
    gaveUp = true
    try { echo.connector?.pusher?.disconnect() } catch { /* nada que hacer */ }
  }
}

/** Avisa a toda la app de que algo se movió. */
function emit(payload) {
  listeners.forEach((fn) => { try { fn(payload) } catch { /* un listener roto no tumba al resto */ } })
}

function startPolling(reason) {
  if (pollTimer) return
  console.info(`[tiempo real] usando polling cada ${POLL_MS / 1000}s (${reason})`)
  pollTimer = setInterval(() => emit({ action: 'poll' }), POLL_MS)
}

function stopPolling() {
  if (!pollTimer) return
  clearInterval(pollTimer)
  pollTimer = null
}

export function connectRealtime() {
  if (echo) return

  const key = import.meta.env.VITE_REVERB_APP_KEY
  if (!key) { startPolling('sin configuración de websocket'); return }

  try {
    echo = new Echo({
      broadcaster: 'reverb',
      key,
      wsHost: import.meta.env.VITE_REVERB_HOST,
      wsPort: Number(import.meta.env.VITE_REVERB_PORT || 8080),
      wssPort: Number(import.meta.env.VITE_REVERB_PORT || 443),
      forceTLS: (import.meta.env.VITE_REVERB_SCHEME || 'http') === 'https',
      enabledTransports: ['ws', 'wss'],

      // El canal es privado: hay que autorizarse. Nuestra auth es por TOKEN,
      // no por cookie de sesión, así que se envía en la cabecera.
      authEndpoint: 'api/broadcasting/auth',
      auth: { headers: { 'X-App-Token': getToken() } },
    })

    const socket = echo.connector.pusher.connection

    socket.bind('connected', () => {
      connected = true
      stopPolling()          // ya no hace falta preguntar: nos avisan
      console.info('[tiempo real] websocket conectado')
    })

    socket.bind('unavailable', () => fallback('websocket no disponible'))
    socket.bind('failed',      () => fallback('websocket falló', true))            // no se puede: cortar
    socket.bind('disconnected', () => { connected = false; fallback('websocket desconectado') })

    echo.private('tickets').listen('.ticket.activity', (e) => emit(e))

    // Si en 5 s no ha conectado, se pasa a polling Y se corta el socket (deja de reintentar).
    setTimeout(() => { if (!connected) fallback('el websocket tardó demasiado', true) }, 5000)
  } catch (err) {
    startPolling('no se pudo iniciar el websocket')
  }
}

export function disconnectRealtime() {
  echo?.disconnect()
  echo = null
  connected = false
  stopPolling()
  listeners.clear()
}

/** Se suscribe a los cambios. Devuelve la función para darse de baja. */
export function onTicketActivity(fn) {
  listeners.add(fn)
  return () => listeners.delete(fn)
}

export const isLive = () => connected
