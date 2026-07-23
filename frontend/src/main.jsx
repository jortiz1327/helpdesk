import React from 'react'
import ReactDOM from 'react-dom/client'

/*
 * Dos aplicaciones en una:
 *  · `/agentes…`  → la app interna (login «sala de control» + gestión). Arranca
 *                   TODA su maquinaria: auth, sondeos, websocket… nada de eso debe
 *                   correr para un cliente.
 *  · el resto     → el PORTAL público del cliente.
 *
 * La bifurcación va aquí, lo primero, para que el cliente ni siquiera monte la app
 * de agentes (ni la descargue: cada una es su propio trozo). El servidor devuelve
 * el mismo index.html en todas las rutas (Route::fallback), así que quien decide
 * es esto.
 */
const esAgentes = window.location.pathname.replace(/\/+$/, '').startsWith('/agentes')
const root = ReactDOM.createRoot(document.getElementById('root'))

if (esAgentes) {
  import('./styles.css')
  import('./App.jsx').then(({ default: App }) => {
    root.render(<React.StrictMode><App /></React.StrictMode>)
  })
} else {
  import('./portal/portal.css')
  import('./portal/Portal.jsx').then(({ default: Portal }) => {
    root.render(<React.StrictMode><Portal /></React.StrictMode>)
  })
}
