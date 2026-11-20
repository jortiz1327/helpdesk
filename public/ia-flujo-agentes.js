// «Volver a la app»: si esta pestaña la abrió la app, se cierra y el agente vuelve a donde estaba; si no, va a la app.
(function () {
  var b = document.getElementById('volver');
  if (!b) return;
  b.addEventListener('click', function (e) {
    if (window.opener && !window.opener.closed) { e.preventDefault(); window.close(); setTimeout(function () { location.href = b.getAttribute('href'); }, 200); }
  });
})();

// Burbujas con el detalle de cada nodo: ratón, teclado (Tab) o toque.
(function () {
  var datos = {};
  try { datos = JSON.parse(document.getElementById('flujo-info').textContent) || {}; } catch (e) { return; }
  var tip = document.createElement('div');
  tip.className = 'tip'; tip.setAttribute('role', 'tooltip'); tip.id = 'tip'; tip.hidden = true;
  document.body.appendChild(tip);
  var actual = null, fijo = false;

  function esc(s) { return String(s).replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }); }
  function mostrar(g) {
    var d = datos[g.getAttribute('data-n')];
    if (!d) return;
    actual = g;
    tip.style.setProperty('--k', getComputedStyle(g).getPropertyValue('--k') || 'var(--accent)');
    tip.innerHTML = '<b>' + esc(d[0]) + '</b><p>' + esc(d[1]) + '</p>' + (d[2] && d[2].length ? '<ul>' + d[2].map(function (x) { return '<li>' + esc(x) + '</li>'; }).join('') + '</ul>' : '');
    tip.hidden = false;
    g.setAttribute('aria-describedby', 'tip');
    var r = g.getBoundingClientRect(), w = tip.offsetWidth, h = tip.offsetHeight, vw = innerWidth, vh = innerHeight, m = 14;
    var x = r.right + m;
    if (x + w > vw - 12) x = r.left - w - m;
    if (x < 12) { x = Math.max(12, Math.min(vw - w - 12, r.left + r.width / 2 - w / 2)); }
    var y = r.top + r.height / 2 - h / 2;
    if (x > r.right || x + w < r.left) { /* a un lado */ } else { y = r.bottom + m; if (y + h > vh - 12) y = r.top - h - m; }
    y = Math.max(72, Math.min(vh - h - 12, y));
    tip.style.left = Math.round(x) + 'px'; tip.style.top = Math.round(y) + 'px';
    tip.classList.add('on');
  }
  function ocultar() {
    if (actual) actual.removeAttribute('aria-describedby');
    actual = null; fijo = false; tip.classList.remove('on'); tip.hidden = true;
  }
  function nodo(e) { return e.target.closest ? e.target.closest('g[data-n]') : null; }
  var svg = document.querySelector('.lienzo svg');
  svg.addEventListener('mouseover', function (e) { if (fijo) return; var g = nodo(e); if (g && g !== actual) mostrar(g); });
  svg.addEventListener('mouseout', function (e) { if (fijo) return; var g = nodo(e); if (g && !(e.relatedTarget && g.contains(e.relatedTarget))) ocultar(); });
  svg.addEventListener('focusin', function (e) { var g = nodo(e); if (g) mostrar(g); });
  svg.addEventListener('focusout', function () { if (!fijo) ocultar(); });
  svg.addEventListener('click', function (e) { var g = nodo(e); if (!g) { ocultar(); return; } if (fijo && g === actual) { ocultar(); return; } mostrar(g); fijo = true; });
  addEventListener('scroll', function () { if (!fijo) ocultar(); }, { passive: true });
  addEventListener('keydown', function (e) { if (e.key === 'Escape') ocultar(); });
  document.addEventListener('click', function (e) { if (fijo && !nodo(e) && !tip.contains(e.target)) ocultar(); });
})();

// Pantalla completa (API del navegador: hace falta un gesto del usuario, por eso el aviso con botón). Esc la cierra.
(function () {
  var el = document.documentElement;
  var pedir = el.requestFullscreen || el.webkitRequestFullscreen;
  var salir = document.exitFullscreen || document.webkitExitFullscreen;
  var btn = document.getElementById('pant'), modal = document.getElementById('modal-pant');
  if (!pedir || !btn) return;   // sin soporte (p. ej. iPhone): se queda la página normal
  function activa() { return !!(document.fullscreenElement || document.webkitFullscreenElement); }
  function entrar() { try { var p = pedir.call(el); if (p && p.catch) p.catch(function () {}); } catch (e) {} }
  function sale() { try { var p = salir.call(document); if (p && p.catch) p.catch(function () {}); } catch (e) {} }
  function pinta() {
    var on = activa();
    btn.setAttribute('aria-pressed', on ? 'true' : 'false');
    btn.querySelector('span').textContent = on ? 'Salir de pantalla completa' : 'Pantalla completa';
  }
  btn.hidden = false;
  btn.addEventListener('click', function () { activa() ? sale() : entrar(); });
  document.addEventListener('fullscreenchange', pinta); document.addEventListener('webkitfullscreenchange', pinta);
  function cierra() { modal.hidden = true; try { sessionStorage.setItem('flujo-pant', '1'); } catch (e) {} btn.focus(); }
  var visto = false; try { visto = sessionStorage.getItem('flujo-pant') === '1'; } catch (e) {}
  if (!activa() && !visto) {
    modal.hidden = false;
    var si = document.getElementById('modal-si'); si.focus();
    si.addEventListener('click', function () { entrar(); cierra(); });
    document.getElementById('modal-no').addEventListener('click', cierra);
    addEventListener('keydown', function (e) { if (e.key === 'Escape' && !modal.hidden) cierra(); });
  }
  // «Volver a la app» desde pantalla completa: primero se sale de ella.
  var v = document.getElementById('volver');
  if (v) v.addEventListener('click', function () { if (activa()) sale(); }, true);
})();
