// Capítulo activo en la barra de /ia/flujo al desplazarse (archivo aparte: la política de seguridad no admite scripts en la página).
(function () {
  var enlaces = [].slice.call(document.querySelectorAll('#caps a[href^="#"]'));
  var anclas = enlaces.map(function (a) { return document.querySelector(a.getAttribute('href')); });
  function marca() {
    var activo = -1;
    anclas.forEach(function (n, i) { if (n && n.getBoundingClientRect().top <= 140) activo = i; });
    enlaces.forEach(function (a, i) { a.classList.toggle('on', i === activo); });
  }
  addEventListener('scroll', marca, { passive: true });
  marca();
})();
