// Modo CLARO por defecto en TODAS las páginas, fijado ANTES de pintar (sin parpadeo).
// El portal público es SIEMPRE claro; la app de agentes respeta la elección guardada
// (por defecto, claro). Si algo falla, cae a claro.
//
// Va en un fichero EXTERNO (no inline) a propósito: la CSP de producción es
// `script-src 'self'`, que bloquea los <script> inline. Inline daba el error de
// consola «Executing inline script violates ... 'script-src self'» y no aplicaba el
// tema hasta que React montaba (parpadeo). Como fichero propio, 'self' lo permite.
try {
  var esAgentes = location.pathname.replace(/\/+$/, '').indexOf('/agentes') === 0;
  document.documentElement.setAttribute('data-theme', esAgentes ? (localStorage.getItem('theme') || 'light') : 'light');
} catch (e) {
  document.documentElement.setAttribute('data-theme', 'light');
}
