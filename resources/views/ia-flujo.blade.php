<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Cómo funciona la IA de soporte</title>
<style>
  :root {
    --bg: #f4f5f7; --panel: #ffffff; --ink: #1c2230; --muted: #667085; --line: #e3e6ec; --accent: #2f5bea;
    --k-cli: #2f5bea; --k-sys: #667085; --k-api: #b7791f; --k-ai: #7c4dd8; --k-ag: #1f8a4c; --k-mem: #0e8a9c; --k-no: #c0392b;
  }
  @media (prefers-color-scheme: dark) {
    :root:not([data-theme="light"]) {
      --bg: #12151c; --panel: #1b2029; --ink: #e8ebf2; --muted: #9aa3b5; --line: #2a303c; --accent: #6f93ff;
      --k-cli: #6f93ff; --k-sys: #9aa3b5; --k-api: #e6b04c; --k-ai: #a98bff; --k-ag: #4fc883; --k-mem: #4cc3d4; --k-no: #ee7d70;
    }
  }
  * { box-sizing: border-box; }
  body { margin: 0; background: var(--bg); color: var(--ink); font: 15px/1.5 system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; }
  .wrap { max-width: 1200px; margin: 0 auto; padding: 22px 18px 60px; }
  header { display: flex; align-items: baseline; gap: 14px; flex-wrap: wrap; margin-bottom: 4px; }
  h1 { font-size: 20px; margin: 0; }
  header a { color: var(--accent); text-decoration: none; font-size: 14px; }
  header a:hover { text-decoration: underline; }
  .sub { color: var(--muted); margin: 2px 0 18px; font-size: 14px; }
  .leyenda { display: flex; flex-wrap: wrap; gap: 6px 14px; font-size: 12.5px; color: var(--muted); margin-bottom: 20px; }
  .leyenda i { display: inline-block; width: 10px; height: 10px; border-radius: 3px; margin-right: 6px; vertical-align: -1px; }

  .paso { display: grid; grid-template-columns: 34px minmax(0, 1fr); gap: 0 14px; }
  .num { width: 30px; height: 30px; border-radius: 50%; background: var(--k, var(--k-sys)); color: #fff; font-weight: 700; font-size: 14px; display: grid; place-items: center; margin-top: 12px; flex: none; }
  .carril { display: flex; flex-direction: column; align-items: center; }
  .carril::after { content: ""; flex: 1; width: 2px; background: var(--line); margin-top: 6px; }
  .paso:last-of-type .carril::after { display: none; }
  .card { background: var(--panel); border: 1px solid var(--line); border-left: 5px solid var(--k, var(--k-sys)); border-radius: 12px; padding: 12px 16px; margin: 6px 0 14px; }
  .card h2 { margin: 0 0 2px; font-size: 15.5px; }
  .card .quien { font-size: 12px; text-transform: uppercase; letter-spacing: .05em; color: var(--k, var(--k-sys)); font-weight: 700; }
  .card p { margin: 4px 0; color: var(--muted); font-size: 14px; }
  .card ul { margin: 6px 0 2px; padding: 0; list-style: none; display: grid; gap: 6px; }
  .card li { display: grid; grid-template-columns: 10px minmax(0, 1fr); gap: 0 10px; font-size: 14px; }
  .card li::before { content: ""; width: 8px; height: 8px; border-radius: 2px; background: var(--c, var(--k, var(--k-sys))); margin-top: 7px; }
  .card li b { font-weight: 600; }
  .card li span { color: var(--muted); }
  .etq { display: inline-block; font-size: 11px; padding: 1px 7px; border-radius: 99px; background: color-mix(in srgb, var(--c, var(--k-sys)) 16%, transparent); color: var(--c, var(--k-sys)); margin-left: 6px; font-weight: 600; }

  .salidas { display: grid; grid-template-columns: repeat(auto-fit, minmax(210px, 1fr)); gap: 10px; margin-top: 8px; }
  .salida { border: 1px solid var(--line); border-top: 4px solid var(--c); border-radius: 10px; padding: 10px 12px; background: var(--bg); font-size: 13.5px; }
  .salida b { display: block; margin-bottom: 2px; }
  .salida span { color: var(--muted); }
  .aviso { background: color-mix(in srgb, var(--k-api) 12%, var(--panel)); border: 1px solid color-mix(in srgb, var(--k-api) 40%, var(--line)); border-radius: 10px; padding: 10px 14px; font-size: 14px; margin-top: 6px; }
  .lienzo { background: var(--panel); border: 1px solid var(--line); border-radius: 14px; padding: 10px; overflow-x: auto; margin-bottom: 18px; }
  .lienzo svg { width: 100%; min-width: 760px; height: auto; display: block; }
  .n rect { fill: var(--bg); stroke: var(--k); stroke-width: 2; rx: 12; }
  .n circle { fill: var(--k); }
  .n .b { fill: #fff; font: 700 12px system-ui, sans-serif; text-anchor: middle; }
  .n .t { fill: var(--ink); font: 700 14px system-ui, sans-serif; text-anchor: middle; }
  .n .s { fill: var(--muted); font: 12px system-ui, sans-serif; text-anchor: middle; }
  .n.ia rect { fill: color-mix(in srgb, var(--k-ai) 14%, var(--bg)); stroke-width: 3; }
  .fl path { fill: none; stroke: var(--muted); stroke-width: 2; }
  .fl path.vuelta { stroke: var(--k-mem); stroke-dasharray: 5 4; }
  .punta { fill: var(--muted); }
  .et { fill: var(--muted); font: italic 12px system-ui, sans-serif; }
  .et.vu { fill: var(--k-mem); }
  .det { margin-top: 6px; }
  .det summary { cursor: pointer; color: var(--accent); font-size: 14px; margin-bottom: 12px; }
  footer { margin-top: 22px; color: var(--muted); font-size: 13px; }
</style>
</head>
<body>
<div class="wrap">
  <header>
    <h1>Cómo funciona la IA de soporte (fase 1)</h1>
    <a href="/ia">← Chats de prueba</a>
    <a href="/ia/casos">Revisar casos →</a>
  </header>
  <p class="sub">Esto es lo que ocurre desde que el cliente escribe hasta que el agente decide. La IA solo sugiere cuando un agente pulsa el botón: no responde sola ni envía nada.</p>
  <div class="leyenda">
    <span><i style="background:var(--k-cli)"></i>Cliente</span>
    <span><i style="background:var(--k-sys)"></i>Sistema (sin IA)</span>
    <span><i style="background:var(--k-api)"></i>AdminHoteles (solo lectura)</span>
    <span><i style="background:var(--k-mem)"></i>Memoria y documentación</span>
    <span><i style="background:var(--k-ai)"></i>IA (Claude Haiku 5.5)</span>
    <span><i style="background:var(--k-ag)"></i>Agente</span>
  </div>

    <div class="lienzo"><svg viewBox="0 0 1290 730" role="img" aria-label="Esquema del flujo de la IA de soporte">
    <defs><marker id="p" viewBox="0 0 10 10" refX="9" refY="5" markerWidth="7" markerHeight="7" orient="auto-start-reverse"><path d="M0,0 L10,5 L0,10 z" class="punta"/></marker></defs>
    <g class="fl" marker-end="url(#p)">
      <path d="M170,285 L246,285"/><path d="M400,285 L476,285"/><path d="M630,285 L736,285"/>
      <path d="M825,120 L825,231"/><path d="M825,450 L825,339"/>
      <path d="M912,262 C980,262 980,145 1046,145"/><path d="M912,285 L1046,285"/><path d="M912,308 C980,308 980,425 1046,425"/>
      <path d="M1155,460 L1155,586"/><path class="vuelta" style="stroke:var(--k-cli)" d="M1155,690 L1155,716 L95,716 L95,326"/><path d="M1046,640 L954,640"/><path d="M1260,145 L1278,145 L1278,640 L1262,640"/><path d="M1260,285 L1278,285" marker-end="none"/><path d="M825,586 L825,544" class="vuelta"/>
    </g>
    <text x="842" y="182" class="et">consulta (solo lectura)</text>
    <text x="842" y="402" class="et">busca lo que ya sabemos</text>
    <text x="1000" y="662" class="et" text-anchor="end">👎 corrige</text>
    <text x="842" y="570" class="et vu">bucle: lo validado vuelve a la memoria</text><text x="640" y="708" class="et" text-anchor="middle">el agente envía lo que decide · el cliente contesta → vuelve al 1</text>
    <g style="--k:var(--k-cli)" class="n"><rect x="20" y="245" width="150" height="80"/><text x="95" y="279" class="t">💬 Cliente escribe</text><text x="95" y="302" class="s">correo · WhatsApp</text><text x="95" y="319" class="s">· formulario</text><circle cx="20" cy="245" r="14"/><text x="20" y="250" class="b">1</text></g>
    <g style="--k:var(--k-ag)" class="n"><rect x="250" y="245" width="150" height="80"/><text x="325" y="279" class="t">🧑‍💼 Agente pide</text><text x="325" y="302" class="s">sugerencia con el botón</text><text x="325" y="319" class="s">(la IA no actúa sola)</text><circle cx="250" cy="245" r="14"/><text x="250" y="250" class="b">2</text></g>
    <g style="--k:var(--k-sys)" class="n"><rect x="480" y="245" width="150" height="80"/><text x="555" y="279" class="t">⚙️ Filtros previos</text><text x="555" y="302" class="s">solo audio → texto fijo</text><text x="555" y="319" class="s">código de barras local</text><circle cx="480" cy="245" r="14"/><text x="480" y="250" class="b">3</text></g>
    <g style="--k:var(--k-api)" class="n"><rect x="700" y="30" width="250" height="90"/><text x="825" y="64" class="t">🏨 AdminHoteles</text><text x="825" y="87" class="s">hotel · correo y rol · usuario activo</text><text x="825" y="104" class="s">antenas · estado de la etiqueta</text><circle cx="700" cy="30" r="14"/><text x="700" y="35" class="b">4</text></g>
    <g style="--k:var(--k-mem)" class="n"><rect x="700" y="450" width="250" height="90"/><text x="825" y="484" class="t">📚 Memoria y guía</text><text x="825" y="507" class="s">preguntas frecuentes · tickets parecidos</text><text x="825" y="524" class="s">manuales · correcciones validadas</text><circle cx="700" cy="450" r="14"/><text x="700" y="455" class="b">5</text></g>
    <g style="--k:var(--k-ai)" class="n ia"><rect x="740" y="235" width="170" height="100"/><text x="825" y="269" class="t">🤖 IA</text><text x="825" y="292" class="s">Claude Haiku 5.5</text><text x="825" y="309" class="s">con las reglas del equipo</text><circle cx="740" cy="235" r="14"/><text x="740" y="240" class="b">6</text></g>
    <g style="--k:var(--k-ag)" class="n"><rect x="1050" y="110" width="210" height="70"/><text x="1155" y="144" class="t">✅ Borrador sugerido</text><text x="1155" y="167" class="s">responder o pedir datos (no se envía)</text></g>
    <g style="--k:var(--k-api)" class="n"><rect x="1050" y="240" width="210" height="90"/><text x="1155" y="274" class="t">↪️ Derivar (sugerido)</text><text x="1155" y="297" class="s">nota «Para el equipo»:</text><text x="1155" y="314" class="s">Soporte · Garantías · Pedidos</text></g>
    <g style="--k:var(--k-no)" class="n"><rect x="1050" y="390" width="210" height="70"/><text x="1155" y="424" class="t">🙋 No sabe / falla</text><text x="1155" y="447" class="s">sin borrador · Reintentar</text></g>
    <g style="--k:var(--k-ag)" class="n"><rect x="1050" y="590" width="210" height="100"/><text x="1155" y="624" class="t">🧑‍💼 Agente decide</text><text x="1155" y="647" class="s">copia y envía él (panel)</text><text x="1155" y="664" class="s">👍 útil · 👎 corrige</text><circle cx="1050" cy="590" r="14"/><text x="1050" y="595" class="b">7</text></g>
    <g style="--k:var(--k-mem)" class="n"><rect x="700" y="590" width="250" height="100"/><text x="825" y="624" class="t">🛡️ Responsable valida</text><text x="825" y="647" class="s">Ajustes › Asistente IA</text><text x="825" y="664" class="s">sin validar, no llega a la IA</text><circle cx="700" cy="590" r="14"/><text x="700" y="595" class="b">8</text></g>
    </svg></div>
  <details class="det"><summary>Ver el detalle paso a paso</summary>
  <div class="paso" style="--k: var(--k-cli)">
    <div class="carril"><div class="num">1</div></div>
    <div class="card">
      <span class="quien">Cliente</span>
      <h2>Escribe un mensaje</h2>
      <p>Por correo, WhatsApp o el formulario del portal. Se crea o se actualiza el ticket.</p>
    </div>
  </div>

  <div class="paso" style="--k: var(--k-ag)">
    <div class="carril"><div class="num">2</div></div>
    <div class="card">
      <span class="quien">Agente</span>
      <h2>Abre el ticket y pide la sugerencia</h2>
      <p>Pestaña «Sugerencia de IA». Hasta que el agente la pide, la IA no hace nada. Fuera de horario tampoco responde (eso es la fase 2).</p>
    </div>
  </div>

  <div class="paso" style="--k: var(--k-sys)">
    <div class="carril"><div class="num">3</div></div>
    <div class="card">
      <span class="quien">Sistema · sin IA, sin coste</span>
      <h2>Filtros previos</h2>
      <ul>
        <li><div><b>Solo audios de WhatsApp</b> <span>→ texto fijo: «Lo sentimos, ahora mismo no podemos escuchar este audio…». No se llama a la IA.</span></div></li>
        <li><div><b>Datos personales</b> <span>→ en la memoria (tickets parecidos y correcciones) se quitan correos, teléfonos y nombres. La conversación del ticket que se está contestando viaja tal cual al proveedor de IA, porque necesita leerla. Las notas internas nunca entran.</span></div></li>
        <li><div><b>Lectura local del código de barras</b> <span>→ si hay fotos, se leen en el servidor; la foto no sale.</span></div></li>
      </ul>
    </div>
  </div>

  <div class="paso" style="--k: var(--k-api)">
    <div class="carril"><div class="num">4</div></div>
    <div class="card">
      <span class="quien">AdminHoteles · solo lectura</span>
      <h2>Consulta quién escribe y cómo está su hotel <span class="etq">API</span></h2>
      <ul>
        <li><div><b>¿De qué hotel habla?</b> <span>→ busca los nombres que aparecen en el mensaje o la firma y los localiza en el panel.</span></div></li>
        <li><div><b>¿El correo figura?</b> <span>→ comprueba el correo contra los usuarios: hotel, buffet, <b>rol</b> y si el usuario está activo. Si no cuadra con el hotel que dice, no revela nada.</span></div></li>
        <li><div><b>Estado de las antenas</b> <span>→ cuántas hay y cuáles están sin conexión (el estado es el de ahora, no el del día del ticket).</span></div></li>
        <li><div><b>Etiqueta concreta</b> <span>→ si hay un código, su batería, señal, última actualización y la antena que la sirve.</span></div></li>
      </ul>
      <div class="aviso">Si el panel tarda o no contesta, la IA lo sabe y no afirma nada que no haya podido comprobar.</div>
    </div>
  </div>

  <div class="paso" style="--k: var(--k-mem)">
    <div class="carril"><div class="num">5</div></div>
    <div class="card">
      <span class="quien">Memoria y documentación</span>
      <h2>Busca en lo que ya sabemos <span class="etq">RAG</span></h2>
      <ul>
        <li><div><b>Preguntas frecuentes</b> <span>publicadas en el portal.</span></div></li>
        <li><div><b>Tickets resueltos parecidos</b> <span>como patrón, nunca para citar datos de otro cliente. Los antiguos donde el equipo hacía por el cliente lo que hoy hace el hotel salen con aviso «ANTIGUO», y los que entregaban contraseñas no se usan.</span></div></li>
        <li><div><b>Guía de la aplicación de hoteles y reglas de soporte</b> <span>manuales y documentación (≈ 300 trozos).</span></div></li>
        <li><div><b>Correcciones validadas</b> <span>lo que los agentes han enseñado con 👎; solo entran las que un responsable valida.</span></div></li>
      </ul>
    </div>
  </div>

  <div class="paso" style="--k: var(--k-ai)">
    <div class="carril"><div class="num">6</div></div>
    <div class="card">
      <span class="quien">IA · Claude Haiku 5.5</span>
      <h2>Redacta con las reglas puestas</h2>
      <p>Recibe la conversación, lo que dijo AdminHoteles y lo que encontró en la memoria, más las reglas del equipo:</p>
      <ul>
        <li><div><b>Siempre se deriva:</b> <span>alérgenos, garantías (sin dar correo: las lleva el responsable en la aplicación), estado de envíos, altas de usuarios, tótems, dominios…</span></div></li>
        <li><div><b>Farmacias y precios:</b> <span>garantía de una farmacia → «lo gestiona Expofarm» (sin correo); cambio de precio en tienda → lo hace una persona de soporte.</span></div></li>
        <li><div><b>Presupuestos:</b> <span>el borrador es «Asignar al departamento de Pedidos y facturas».</span></div></li>
        <li><div><b>No se deriva, se contesta con el correo:</b> <span>pedidos de material (pilas, soportes, recambios, etiquetas): «escriba a postventa@aemegroup.com con material, cantidad y foto de referencia».</span></div></li>
        <li><div><b>Verificar primero, explicar después:</b> <span>configurar algo del buffet pide el correo antes de dar los pasos.</span></div></li>
        <li><div><b>Máximo dos preguntas,</b> <span>sin preguntar lo obvio ni inventar datos.</span></div></li>
        <li><div><b>Antenas:</b> <span>todas en línea → pregunta y guía a forzar el menú; alguna caída → su personal técnico.</span></div></li>
        <li><div><b>Nunca inventa:</b> <span>si no está seguro, no contesta («no sé»).</span></div></li>
      </ul>
    </div>
  </div>

  <div class="paso" style="--k: var(--k-sys)">
    <div class="carril"><div class="num">7</div></div>
    <div class="card">
      <span class="quien">Resultado</span>
      <h2>Una de estas salidas (solo sugerencias: nada se envía al cliente)</h2>
      <div class="salidas">
        <div class="salida" style="--c: var(--k-ag)"><b>Borrador de respuesta</b><span>Una respuesta lista para revisar: explica, o pide datos al cliente.</span></div>
        <div class="salida" style="--c: var(--k-api)"><b>Derivar</b><span>Un mensaje corto para el cliente («hemos registrado la incidencia y el equipo la revisará») y, aparte, un bloque <b>«Para el equipo»</b> en el panel: la categoría de la app a la que llevarlo (Soporte, Garantías o Pedidos y facturas) y el motivo en pocas palabras. Ese bloque lo ve el agente, no el cliente.</span></div>
        <div class="salida" style="--c: var(--k-no)"><b>No sabe / falla</b><span>No hay borrador: el agente ve «La IA no se atreve» o un aviso con Reintentar.</span></div>
      </div>
    </div>
  </div>

  <div class="paso" style="--k: var(--k-ag)">
    <div class="carril"><div class="num">8</div></div>
    <div class="card">
      <span class="quien">Agente</span>
      <h2>Decide y valora</h2>
      <ul>
        <li><div><b>Panel lateral de solo lectura</b> <span>→ va enseñando cada paso al momento (hotel, correo, rol, antenas con su estado, memoria) y deja el borrador. Solo se puede <b>copiar</b>: no se escribe en el editor y la IA nunca envía.</span></div></li>
        <li><div><b>Útil</b> <span>→ queda anotado para medir el acierto.</span></div></li>
        <li><div><b>Corregir</b> <span>→ escribe qué debía decir. Se guarda como pendiente (o validada si la hace un responsable).</span></div></li>
        <li><div><b>Otra sugerencia</b> <span>→ vuelve al paso 6 con la indicación.</span></div></li>
      </ul>
    </div>
  </div>

  <div class="paso" style="--k: var(--k-mem)">
    <div class="carril"><div class="num">9</div></div>
    <div class="card">
      <span class="quien">Responsable de soporte</span>
      <h2>Valida las correcciones</h2>
      <p>Ajustes › Asistente IA. Una corrección no llega a la IA hasta que se valida; las rechazadas tampoco. Las validadas vuelven al paso 5 y mejoran las siguientes sugerencias.</p>
    </div>
  </div>

  </details>
  <footer>Fase 2 (respuesta automática fuera de horario) todavía no está hecha: usaría este mismo recorrido, con un aviso fijo de que habla una IA y salida a una persona.</footer>
</div>
</body>
</html>
