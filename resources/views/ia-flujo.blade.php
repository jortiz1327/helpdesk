<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Cómo funciona la IA de soporte</title>
<style>
  @font-face { font-family: 'Lato'; font-style: normal; font-weight: 400; font-display: swap; src: url('/fonts/lato-400.woff2') format('woff2'); }
  @font-face { font-family: 'Lato'; font-style: normal; font-weight: 700; font-display: swap; src: url('/fonts/lato-700.woff2') format('woff2'); }
  @font-face { font-family: 'Lato'; font-style: normal; font-weight: 900; font-display: swap; src: url('/fonts/lato-900.woff2') format('woff2'); }

  :root {
    color-scheme: light;
    --bg: #f2f4f8; --surface: #ffffff; --band: #e7ebf3; --line: #d5dae5; --edge: #6c7790;
    --ink: #151b2b; --ink2: #424c61; --muted: #5d6881; --accent: #3047d4; --ok: #1d7a43;
    --k-cli: #4f63e8; --k-sys: #6b7385; --k-api: #0c8f84; --k-mem: #2a7fc1; --k-ai: #7a4fe0; --k-ag: #25914f; --k-der: #c27a0e; --k-no: #c4473a;
    --sombra: 0 1px 2px rgb(20 30 60 / .10), 0 6px 18px -8px rgb(20 30 60 / .18);
  }
  @media (prefers-color-scheme: dark) {
    :root:not([data-theme="light"]) {
      color-scheme: dark;
      --bg: #0e1218; --surface: #1a212b; --band: #141a22; --line: #2a3340; --edge: #77839a;
      --ink: #eaeef6; --ink2: #bac3d3; --muted: #9aa5ba; --accent: #8ea0ff; --ok: #5ccf8b;
      --k-cli: #8a9bff; --k-sys: #98a2b6; --k-api: #3fc7b8; --k-mem: #5ab0ee; --k-ai: #ab8cff; --k-ag: #53cf86; --k-der: #efb04a; --k-no: #f0847a;
      --sombra: 0 1px 2px rgb(0 0 0 / .35), 0 8px 22px -10px rgb(0 0 0 / .6);
    }
  }
  :root[data-theme="dark"] {
    color-scheme: dark;
    --bg: #0e1218; --surface: #1a212b; --band: #141a22; --line: #2a3340; --edge: #77839a;
    --ink: #eaeef6; --ink2: #bac3d3; --muted: #9aa5ba; --accent: #8ea0ff; --ok: #5ccf8b;
    --k-cli: #8a9bff; --k-sys: #98a2b6; --k-api: #3fc7b8; --k-mem: #5ab0ee; --k-ai: #ab8cff; --k-ag: #53cf86; --k-der: #efb04a; --k-no: #f0847a;
    --sombra: 0 1px 2px rgb(0 0 0 / .35), 0 8px 22px -10px rgb(0 0 0 / .6);
  }

  * { box-sizing: border-box; }
  html { scroll-behavior: smooth; scrollbar-color: var(--line) transparent; }
  body { margin: 0; background: var(--bg); color: var(--ink); font: 400 16px/1.55 'Lato', system-ui, 'Segoe UI', sans-serif; -webkit-font-smoothing: antialiased; }
  ::selection { background: color-mix(in srgb, var(--accent) 28%, transparent); }
  a { color: var(--accent); text-underline-offset: 3px; }
  :focus-visible { outline: 2px solid var(--accent); outline-offset: 3px; border-radius: 4px; }
  .wrap { width: min(1760px, 100% - 48px); margin: 0 auto; padding: 0 0 72px; }

  /* Cabecera */
  .top { display: flex; gap: 22px; padding: 18px 0 0; font-size: 14px; font-weight: 700; }
  .top a { text-decoration: none; }
  .top a:hover { text-decoration: underline; }
  .intro { padding: 44px 0 28px; max-width: 78ch; }
  h1 { font-size: clamp(32px, 4.2vw, 52px); line-height: 1.05; letter-spacing: -0.025em; font-weight: 900; margin: 0 0 16px; text-wrap: balance; }
  .lead { font-size: clamp(17px, 1.5vw, 20px); line-height: 1.55; color: var(--ink2); margin: 0; text-wrap: pretty; }

  .garantias { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); border-block: 1px solid var(--line); margin: 8px 0 34px; }
  .garantias > div { padding: 20px 24px 20px 0; display: grid; grid-template-columns: 28px minmax(0, 1fr); gap: 0 14px; align-content: start; }
  .garantias > div + div { padding-left: 24px; border-left: 1px solid var(--line); }
  .garantias svg { width: 26px; height: 26px; stroke: var(--accent); fill: none; stroke-width: 1.8; stroke-linecap: round; stroke-linejoin: round; margin-top: 2px; }
  .garantias b { display: block; font-size: 16px; font-weight: 900; line-height: 1.3; }
  .garantias span { display: block; font-size: 14px; color: var(--muted); margin-top: 3px; line-height: 1.45; }

  /* Navegación por capítulos */
  .caps { position: sticky; top: 0; z-index: 5; background: var(--bg); border-bottom: 1px solid var(--line); padding: 10px 0; display: flex; flex-wrap: wrap; gap: 6px 8px; align-items: center; }
  .caps a { display: inline-flex; align-items: center; gap: 8px; padding: 6px 14px 6px 6px; border-radius: 99px; text-decoration: none; color: var(--ink2); font-size: 14px; font-weight: 700; transition: background .15s, color .15s; }
  .caps a i { width: 24px; height: 24px; border-radius: 50%; background: var(--band); color: var(--ink); font: 900 12.5px/24px 'Lato', sans-serif; text-align: center; font-style: normal; transition: background .15s, color .15s; }
  .caps a:hover { background: var(--band); color: var(--ink); }
  .caps a.on { background: var(--ink); color: var(--surface); }
  .caps a.on i { background: var(--surface); color: var(--ink); }
  .caps .sep { flex: 1; }
  .leyenda { display: flex; flex-wrap: wrap; gap: 8px 22px; padding: 16px 0 14px; font-size: 13.5px; color: var(--ink2); }
  .leyenda > div { display: flex; flex-wrap: wrap; align-items: center; gap: 6px 14px; }
  .leyenda strong { font-weight: 900; color: var(--ink); margin-right: 2px; }
  .leyenda span { display: inline-flex; align-items: center; gap: 7px; }
  .leyenda i { width: 12px; height: 12px; border-radius: 4px; background: color-mix(in srgb, var(--c) 22%, var(--surface)); border: 2px solid var(--c); }
  .leyenda .nodo i { border-radius: 3px; }
  .leyenda .sal i { border-radius: 99px; }

  /* Diagrama */
  .lienzo { position: relative; margin: 0; overflow-x: auto; }
  .lienzo svg { width: 100%; min-width: 1180px; height: auto; display: block; }
  .ancla { position: absolute; left: 0; width: 1px; height: 1px; scroll-margin-top: 70px; }
  .banda path { fill: var(--band); }
  .banda .sep { stroke: var(--line, rgba(127,127,127,.35)); stroke-width: 1; }
  .banda circle { fill: var(--ink); }
  .banda .bl { fill: var(--surface); font: 900 15px 'Lato', sans-serif; text-anchor: middle; }
  .banda .bt { fill: var(--ink); font: 900 19px 'Lato', sans-serif; letter-spacing: -0.01em; }
  .banda .bs { fill: var(--muted); font: 400 14.5px 'Lato', sans-serif; }
  .fl path { fill: none; stroke: var(--edge); stroke-width: 1.7; stroke-linejoin: round; stroke-linecap: round; }
  .fl path.vuelta { stroke: var(--k); stroke-width: 2.2; stroke-dasharray: 7 6; }
  .punta { fill: var(--edge); }
  .n rect, .n polygon { fill: color-mix(in srgb, var(--k) 9%, var(--surface)); stroke: color-mix(in srgb, var(--k) 62%, var(--surface)); stroke-width: 1.6; stroke-linejoin: round; filter: drop-shadow(0 1px 1.5px rgb(20 30 60 / .13)); }
  .n.q rect { fill: var(--surface); stroke: color-mix(in srgb, var(--k) 70%, var(--surface)); stroke-width: 1.8; }
  .n.q .qm { fill: var(--k); stroke: none; filter: none; }
  .n.q .qt { fill: var(--surface); font: 900 14px 'Lato', sans-serif; }
  .chip rect { fill: color-mix(in srgb, var(--k) 12%, var(--surface)); stroke: var(--k); stroke-width: 1.4; stroke-dasharray: 5 4; }
  .chip text { fill: var(--ink); font: 700 13px 'Lato', sans-serif; text-anchor: middle; }
  .chip .ic { fill: none; stroke: var(--k); stroke-width: 2.2; stroke-linecap: round; stroke-linejoin: round; }
  .n.ia rect { fill: color-mix(in srgb, var(--k) 15%, var(--surface)); stroke: var(--k); stroke-width: 2.6; filter: drop-shadow(0 4px 10px color-mix(in srgb, var(--k) 30%, transparent)); }
  .n text { text-anchor: middle; }
  .n .t { fill: var(--ink); font: 900 14.5px 'Lato', sans-serif; }
  .n .s { fill: var(--ink2); font: 400 12.5px 'Lato', sans-serif; }
  .n .s.q { fill: var(--ink); font-weight: 700; font-size: 13px; }
  .n .bd { fill: var(--ink); stroke: var(--surface); stroke-width: 2.5; filter: none; }
  .n .b { fill: var(--surface); font: 900 12.5px 'Lato', sans-serif; }
  .n .ic { fill: none; stroke: var(--k); stroke-width: 2; stroke-linecap: round; stroke-linejoin: round; }
  .pill rect { fill: var(--surface); stroke: var(--line); stroke-width: 1.2; }
  .pill text { font: 900 12px 'Lato', sans-serif; text-anchor: middle; }
  .pill.si text { fill: var(--ok); }
  .pill.no text { fill: var(--muted); }
  .et { fill: var(--ink2); font: 700 13px 'Lato', sans-serif; paint-order: stroke; stroke: var(--band); stroke-width: 6px; stroke-linejoin: round; }
  .et.vu { fill: var(--k-mem); }

  /* Detalle por capítulo */
  .detalle { margin-top: 56px; }
  .detalle > h2 { font-size: clamp(26px, 2.6vw, 34px); letter-spacing: -0.02em; line-height: 1.15; font-weight: 900; margin: 0 0 6px; text-wrap: balance; }
  .detalle > p { color: var(--muted); margin: 0 0 24px; max-width: 70ch; }
  .capitulo { display: grid; grid-template-columns: minmax(200px, 300px) minmax(0, 1fr); gap: 8px 48px; padding: 30px 0; border-top: 1px solid var(--line); scroll-margin-top: 70px; }
  .capitulo h3 { margin: 0; font-size: 20px; line-height: 1.2; font-weight: 900; letter-spacing: -0.01em; display: flex; gap: 12px; align-items: flex-start; }
  .capitulo h3 i { flex: none; width: 30px; height: 30px; border-radius: 50%; background: var(--ink); color: var(--surface); font: 900 14px/30px 'Lato', sans-serif; text-align: center; font-style: normal; }
  .capitulo .res { color: var(--muted); font-size: 14.5px; margin: 8px 0 0 42px; max-width: 28ch; }
  dl { margin: 0; max-width: 96ch; }
  dl > div { display: grid; grid-template-columns: minmax(150px, 220px) minmax(0, 1fr); gap: 4px 24px; padding: 11px 0; border-top: 1px solid color-mix(in srgb, var(--line) 60%, transparent); }
  dl > div:first-child { border-top: 0; padding-top: 0; }
  dt { font-weight: 900; font-size: 15px; line-height: 1.4; }
  dd { margin: 0; color: var(--ink2); font-size: 15px; line-height: 1.55; text-wrap: pretty; }
  dd b { color: var(--ink); font-weight: 700; }
  .tag { display: inline-block; font-size: 11.5px; font-weight: 900; padding: 1px 8px; border-radius: 99px; background: color-mix(in srgb, var(--accent) 14%, transparent); color: var(--accent); margin-left: 6px; vertical-align: 1px; }
  .nota { margin: 14px 0 0; padding: 12px 16px; border-radius: 12px; background: var(--band); font-size: 14.5px; color: var(--ink2); max-width: 78ch; }
  footer { margin-top: 40px; padding-top: 22px; border-top: 1px solid var(--line); color: var(--muted); font-size: 14px; max-width: 80ch; }

  /* Un solo gesto de entrada, sin tocar el contenido */
  @media (prefers-reduced-motion: no-preference) {
    .intro > *, .garantias > div { animation: entra .6s cubic-bezier(.16, 1, .3, 1) both; }
    .intro h1 { animation-delay: .04s; } .intro .lead { animation-delay: .12s; }
    .garantias > div:nth-child(1) { animation-delay: .2s; } .garantias > div:nth-child(2) { animation-delay: .26s; }
    .garantias > div:nth-child(3) { animation-delay: .32s; } .garantias > div:nth-child(4) { animation-delay: .38s; }
    @keyframes entra { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: none; } }
  }
  @media (max-width: 980px) {
    .garantias { grid-template-columns: repeat(2, minmax(0, 1fr)); }
    .garantias > div:nth-child(3) { padding-left: 0; border-left: 0; }
    .garantias > div:nth-child(n+3) { border-top: 1px solid var(--line); }
    .capitulo { grid-template-columns: minmax(0, 1fr); }
    dl > div { grid-template-columns: minmax(0, 1fr); }
  }
  @media (max-width: 760px) { .caps { flex-wrap: nowrap; overflow-x: auto; scrollbar-width: none; } .caps a { flex: none; white-space: nowrap; } .caps .sep { display: none; } }
  @media (max-width: 560px) { .garantias { grid-template-columns: minmax(0, 1fr); } .garantias > div + div { padding-left: 0; border-left: 0; border-top: 1px solid var(--line); } }
  @media print { .caps, .top { display: none; } .lienzo { overflow: visible; } .lienzo svg { min-width: 0; } .capitulo { break-inside: avoid; } }
</style>
</head>
<body>
<div class="wrap">
  <nav class="top" aria-label="Secciones de la IA">
    <a href="/ia">← Chats de prueba</a>
    <a href="/ia/casos">Revisar casos →</a>
  </nav>

  <section class="intro">
    <h1>Cómo funciona la IA de soporte</h1>
    <p class="lead">Este es el recorrido completo, desde que el cliente escribe hasta que el agente decide. En esta fase la IA solo sugiere cuando un agente pulsa el botón: no responde sola ni envía nada.</p>
  </section>

  <section class="garantias" aria-label="Garantías de la fase 1">
    <div>
      <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/><path d="M8 9h8M8 13h5"/></svg>
      <p style="margin:0"><b>La IA solo sugiere</b><span>Nada llega al cliente sin que un agente lo decida y lo envíe él.</span></p>
    </div>
    <div>
      <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M20 13c0 5-3.5 7.5-7.66 8.95a1 1 0 0 1-.67-.01C7.5 20.5 4 18 4 13V6a1 1 0 0 1 1-1c2 0 4.5-1.2 6.24-2.72a1.17 1.17 0 0 1 1.52 0C14.51 3.81 17 5 19 5a1 1 0 0 1 1 1z"/><path d="m9 12 2 2 4-4"/></svg>
      <p style="margin:0"><b>Memoria sin datos personales</b><span>Lo que aprende no guarda correos, teléfonos, firmas ni contraseñas.</span></p>
    </div>
    <div>
      <svg viewBox="0 0 24 24" aria-hidden="true"><path d="m12 14 4-4"/><path d="M3.34 19a10 10 0 1 1 17.32 0"/></svg>
      <p style="margin:0"><b>Gasto acotado</b><span>Tope de 50 USD al mes. Al llegar, la IA se pausa hasta el día 1.</span></p>
    </div>
    <div>
      <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="9" cy="7" r="4"/><path d="M3 21v-2a4 4 0 0 1 4-4h4a4 4 0 0 1 4 4v2"/><path d="m16 11 2 2 4-4"/></svg>
      <p style="margin:0"><b>Aprende de las personas</b><span>Mejora con lo que corrigen los agentes, al momento.</span></p>
    </div>
  </section>

  <nav class="caps" id="caps" aria-label="Capítulos del flujo">
    <a href="#cap-a"><i>A</i>Puertas</a>
    <a href="#cap-b"><i>B</i>Contexto</a>
    <a href="#cap-c"><i>C</i>Decisión en código</a>
    <a href="#cap-d"><i>D</i>Orden de decisión</a>
    <a href="#cap-e"><i>E</i>Salida</a>
    <a href="#cap-f"><i>F</i>Aprendizaje</a>
    <span class="sep"></span>
    <a href="#detalle">Detalle por capítulo</a>
  </nav>

  <div class="leyenda" aria-label="Leyenda">
    <div class="nodo"><strong>Quién actúa</strong>
      <span style="--c:var(--k-cli)"><i></i>Cliente</span>
      <span style="--c:var(--k-sys)"><i></i>Sistema (sin IA)</span>
      <span style="--c:var(--k-api)"><i></i>AdminHoteles (solo lectura)</span>
      <span style="--c:var(--k-mem)"><i></i>Memoria</span>
      <span style="--c:var(--k-ai)"><i></i>IA (Claude Haiku 5.5)</span>
      <span style="--c:var(--k-ag)"><i></i>Agente</span>
    </div>
    <div class="sal"><strong>Resultado</strong>
      <span style="--c:var(--k-der)"><i></i>Derivar a una persona</span>
      <span style="--c:var(--k-no)"><i></i>Sin respuesta o fallo</span>
    </div>
  </div>

  <div class="lienzo"><!--LIENZO--><svg viewBox="0 0 1400 4592.5" role="img" aria-label="Flujo completo de la IA de soporte, de que el cliente escribe a que la corrección del agente pasa a la memoria">
<defs><marker id="p" viewBox="0 0 10 10" refX="8.5" refY="5" markerWidth="6" markerHeight="6" orient="auto-start-reverse"><path d="M0,0.5 L10,5 L0,9.5 z" class="punta"/></marker></defs>
<g class="banda"><path d="M58 16H1352Q1370 16 1370 34V757.5H40V34Q40 16 58 16Z"/><circle cx="72" cy="48" r="15"/><text x="72" y="53.5" class="bl">A</text><text x="98" y="47" class="bt">Puertas de entrada</text><text x="98" y="68" class="bs">Antes de gastar nada, se comprueba si toca consultar a la IA</text></g>
<g class="banda"><path d="M40 757.5H1370V1701.5H40V757.5Z"/><line class="sep" x1="64" x2="1346" y1="757.5" y2="757.5"/><circle cx="72" cy="789.5" r="15"/><text x="72" y="795" class="bl">B</text><text x="98" y="788.5" class="bt">Contexto</text><text x="98" y="809.5" class="bs">Lo que la IA necesita saber: memoria, fotos y datos del hotel</text></g>
<g class="banda"><path d="M40 1701.5H1370V2229.5H40V1701.5Z"/><line class="sep" x1="64" x2="1346" y1="1701.5" y2="1701.5"/><circle cx="72" cy="1733.5" r="15"/><text x="72" y="1739" class="bl">C</text><text x="98" y="1732.5" class="bt">Decisión en código</text><text x="98" y="1753.5" class="bs">Lo evidente se decide sin la IA; luego se le dan instrucciones</text></g>
<g class="banda"><path d="M40 2229.5H1370V3367.5H40V2229.5Z"/><line class="sep" x1="64" x2="1346" y1="2229.5" y2="2229.5"/><circle cx="72" cy="2261.5" r="15"/><text x="72" y="2267" class="bl">D</text><text x="98" y="2260.5" class="bt">Orden de decisión</text><text x="98" y="2281.5" class="bs">La IA repasa estos puntos en orden: el primero que encaja manda</text></g>
<g class="banda"><path d="M40 3367.5H1370V4074.5H40V3367.5Z"/><line class="sep" x1="64" x2="1346" y1="3367.5" y2="3367.5"/><circle cx="72" cy="3399.5" r="15"/><text x="72" y="3405" class="bl">E</text><text x="98" y="3398.5" class="bt">Salida</text><text x="98" y="3419.5" class="bs">Qué pasa con la respuesta de la IA, y cuánto cuesta</text></g>
<g class="banda"><path d="M40 4074.5H1370V4558.5Q1370 4576.5 1352 4576.5H58Q40 4576.5 40 4558.5V4074.5Z"/><line class="sep" x1="64" x2="1346" y1="4074.5" y2="4074.5"/><circle cx="72" cy="4106.5" r="15"/><text x="72" y="4112" class="bl">F</text><text x="98" y="4105.5" class="bt">Agente y aprendizaje</text><text x="98" y="4126.5" class="bs">La persona decide; lo que corrige mejora al momento a la IA</text></g>
<g class="fl" marker-end="url(#p)"><path d="M360,135 L420,135"/><path d="M590,176 L590,210"/><path d="M590,292 L590,326"/><path d="M745,356 L835,356"/><path d="M590,386 L590,422.5"/><path d="M745,452.5 L835,452.5"/><path d="M590,482.5 L590,536"/><path d="M745,566 L835,566"/><path d="M590,596 L590,649.5"/><path d="M745,679.5 L835,679.5"/><path d="M590,709.5 L590,835.5"/><path d="M185,1108.5 L185,1140.5"/><path d="M590,1035.5 L590,1067.5"/><path d="M1040,1005.5 L1115,1018.5"/><path d="M900,1035.5 L900,1067.5"/><path d="M900,1200.5 L900,1232.5"/><path d="M1040,1262.5 L1115,1266"/><path d="M900,1292.5 L900,1324.5"/><path d="M1040,1354.5 L1115,1359.5"/><path d="M900,1384.5 L900,1416.5"/><path d="M900,1515.5 L900,1547.5"/><path marker-end="none" d="M590,917.5 L590,947.5"/><path marker-end="none" d="M185,947.5 L900,947.5"/><path d="M185,947.5 L185,975.5"/><path d="M590,947.5 L590,975.5"/><path d="M900,947.5 L900,975.5"/><path d="M460,1005.5 L435,1005.5"/><path marker-end="none" d="M185,1256.5 L185,1663.5"/><path marker-end="none" d="M590,1149.5 L590,1663.5"/><path marker-end="none" d="M900,1629.5 L900,1663.5"/><path marker-end="none" d="M185,1663.5 L900,1663.5"/><path d="M590,1663.5 L590,1779.5"/><path d="M445,1809.5 L310,1810.5"/><path d="M735,1809.5 L815,1827.5"/><path marker-end="none" d="M185,1851.5 L185,1911.5 L960,1911.5"/><path marker-end="none" d="M960,1885.5 L960,1911.5"/><path d="M590,1839.5 L590,1945.5"/><path d="M590,2044.5 L590,2088.5"/><path d="M590,2187.5 L590,2307.5"/><path d="M755,2348.5 L875,2348.5"/><path d="M590,2389.5 L590,2423.5"/><path d="M755,2507 L875,2507"/><path d="M590,2590.5 L590,2624.5"/><path d="M755,2665.5 L875,2665.5"/><path d="M590,2706.5 L590,2740.5"/><path d="M755,2790 L875,2790"/><path d="M590,2839.5 L590,2873.5"/><path d="M755,2914.5 L875,2914.5"/><path d="M590,2955.5 L590,2989.5"/><path d="M755,3030.5 L875,3030.5"/><path d="M590,3071.5 L590,3105.5"/><path d="M755,3146.5 L875,3146.5"/><path d="M590,3187.5 L590,3221.5"/><path marker-end="none" d="M1245,2348.5 L1338,2348.5"/><path marker-end="none" d="M1245,2507 L1338,2507"/><path marker-end="none" d="M1245,2665.5 L1338,2665.5"/><path marker-end="none" d="M1245,2790 L1338,2790"/><path marker-end="none" d="M1245,2914.5 L1338,2914.5"/><path marker-end="none" d="M1245,3030.5 L1338,3030.5"/><path marker-end="none" d="M1245,3146.5 L1338,3146.5"/><path marker-end="none" d="M1338,2348.5 L1338,3333.5"/><path marker-end="none" d="M590,3303.5 L590,3333.5"/><path marker-end="none" d="M1338,3333.5 L590,3333.5"/><path d="M590,3333.5 L590,3445.5"/><path d="M735,3486.5 L830,3487.5"/><path d="M590,3527.5 L590,3559.5"/><path d="M735,3600.5 L830,3601.5"/><path d="M590,3641.5 L590,3673.5"/><path d="M735,3729.5 L830,3731.5"/><path d="M590,3785.5 L590,3817.5"/><path d="M590,3899.5 L590,3939.5"/><path marker-end="none" d="M1170,3487.5 L1338,3487.5"/><path marker-end="none" d="M1170,3601.5 L1338,3601.5"/><path marker-end="none" d="M1170,3731.5 L1338,3731.5"/><path marker-end="none" d="M1338,3487.5 L1338,3919.5"/><path marker-end="none" d="M1338,3919.5 L590,3919.5"/><path d="M590,4038.5 L590,4152.5"/><path marker-end="none" d="M590,4234.5 L590,4262.5"/><path marker-end="none" d="M185,4262.5 L1085,4262.5"/><path d="M185,4262.5 L185,4288.5"/><path d="M485,4262.5 L485,4288.5"/><path d="M785,4262.5 L785,4288.5"/><path d="M1085,4262.5 L1085,4288.5"/><path d="M185,4370.5 L185,4400.5"/><path d="M485,4370.5 L485,4400.5"/><path d="M1085,4370.5 L1085,4410.5"/><path d="M1085,4492.5 L1085,4520.5"/></g>
<g data-n="n1" tabindex="0" style="--k:var(--k-cli)" class="n"><rect x="420" y="94" width="340" height="82" rx="11"/><text x="590" y="120" class="t">Cliente escribe</text><text x="590" y="141" class="s">por correo, WhatsApp, formulario o portal</text><text x="590" y="158" class="s">el mensaje llega al ticket</text><circle class="bd" cx="420" cy="94" r="12"/><text x="420" y="98.2" class="b">1</text></g>
<g data-n="c9" tabindex="0" style="--k:var(--k-cli)" class="chip"><rect x="150" y="119" width="210" height="32" rx="16"/><g class="ic" transform="translate(162,127) scale(0.66)"><path d="M17 2l4 4-4 4"/><path d="M3 11V9a3 3 0 0 1 3-3h15"/><path d="M7 22l-4-4 4-4"/><path d="M21 13v2a3 3 0 0 1-3 3H3"/></g><text x="265" y="139" >Vuelve aquí desde el paso 9</text></g>
<g data-n="n2" tabindex="0" style="--k:var(--k-ag)" class="n"><rect x="420" y="210" width="340" height="82" rx="11"/><text x="590" y="236" class="t">El agente pide la sugerencia</text><text x="590" y="257" class="s">abre el ticket y pulsa «Sugerir»</text><text x="590" y="274" class="s">la IA no actúa por su cuenta</text><circle class="bd" cx="420" cy="210" r="12"/><text x="420" y="214.2" class="b">2</text></g>
<g data-n="d1" tabindex="0" style="--k:var(--k-sys)" class="n q"><rect x="435" y="326" width="310" height="60" rx="30"/><circle class="qm" cx="459" cy="356" r="11"/><text x="459" y="360.6" class="qt">?</text><text x="604" y="351.7" class="s q">¿La IA está activada</text><text x="604" y="368.7" class="s q">en Ajustes?</text></g>
<g data-n="d1x" tabindex="0" style="--k:var(--k-no)" class="n"><rect x="835" y="323.5" width="330" height="65" rx="11"/><text x="1000" y="349.5" class="t">No aparece el botón</text><text x="1000" y="370.5" class="s">no sale ningún dato hacia fuera</text></g>
<g data-n="d2" tabindex="0" style="--k:var(--k-sys)" class="n q"><rect x="435" y="422.5" width="310" height="60" rx="30"/><circle class="qm" cx="459" cy="452.5" r="11"/><text x="459" y="457.1" class="qt">?</text><text x="604" y="448.2" class="s q">¿Ya se sugirió algo para</text><text x="604" y="465.2" class="s q">este mensaje del cliente?</text></g>
<g data-n="d2x" tabindex="0" style="--k:var(--k-mem)" class="n"><rect x="835" y="403" width="330" height="99" rx="11"/><text x="1000" y="429" class="t">Se enseña la que ya estaba</text><text x="1000" y="450" class="s">no se vuelve a consultar a la IA</text><text x="1000" y="467" class="s">«Regenerar» pide una nueva</text><text x="1000" y="484" class="s">→ sigue en el paso 9</text></g>
<g data-n="d3" tabindex="0" style="--k:var(--k-sys)" class="n q"><rect x="435" y="536" width="310" height="60" rx="30"/><circle class="qm" cx="459" cy="566" r="11"/><text x="459" y="570.6" class="qt">?</text><text x="604" y="561.7" class="s q">¿Se ha gastado ya lo previsto</text><text x="604" y="578.7" class="s q">para este mes? (50 USD)</text></g>
<g data-n="d3x" tabindex="0" style="--k:var(--k-no)" class="n"><rect x="835" y="516.5" width="330" height="99" rx="11"/><text x="1000" y="542.5" class="t">Se avisa al agente</text><text x="1000" y="563.5" class="s">«Contesta tú; la IA vuelve el día 1»</text><text x="1000" y="580.5" class="s">no se consulta a la IA</text><text x="1000" y="597.5" class="s">→ lo contesta el agente</text></g>
<g data-n="d4" tabindex="0" style="--k:var(--k-sys)" class="n q"><rect x="435" y="649.5" width="310" height="60" rx="30"/><circle class="qm" cx="459" cy="679.5" r="11"/><text x="459" y="684.1" class="qt">?</text><text x="604" y="675.2" class="s q">¿El cliente solo ha mandado</text><text x="604" y="692.2" class="s q">audios de WhatsApp?</text></g>
<g data-n="d4x" tabindex="0" style="--k:var(--k-sys)" class="n"><rect x="835" y="630" width="330" height="99" rx="11"/><text x="1000" y="656" class="t">Respuesta fija, sin IA</text><text x="1000" y="677" class="s">«Ahora mismo no podemos escuchar</text><text x="1000" y="694" class="s">audios…» · no cuesta nada</text><text x="1000" y="711" class="s">→ sigue en el paso 9</text></g>
<g data-n="b1" tabindex="0" style="--k:var(--k-sys)" class="n"><rect x="390" y="835.5" width="400" height="82" rx="11"/><text x="590" y="861.5" class="t">Prepara la conversación</text><text x="590" y="882.5" class="s">lee lo que ha escrito el cliente y lo que se le contestó</text><text x="590" y="899.5" class="s">(sin notas internas) y busca su última pregunta</text><circle class="bd" cx="390" cy="835.5" r="12"/><text x="390" y="839.7" class="b">3</text></g>
<g data-n="m1" tabindex="0" style="--k:var(--k-mem)" class="n"><rect x="60" y="975.5" width="250" height="133" rx="11"/><text x="185" y="1001.5" class="t">Memoria y guía</text><text x="185" y="1022.5" class="s">preguntas frecuentes</text><text x="185" y="1039.5" class="s">respuestas de ejemplo del equipo</text><text x="185" y="1056.5" class="s">plantillas de respuesta del equipo</text><text x="185" y="1073.5" class="s">casos parecidos y manuales</text><text x="185" y="1090.5" class="s">correcciones de los agentes (paso 10)</text><circle class="bd" cx="60" cy="975.5" r="12"/><text x="60" y="979.7" class="b">4</text></g>
<g data-n="m2" tabindex="0" style="--k:var(--k-mem)" class="n"><rect x="60" y="1140.5" width="250" height="116" rx="11"/><text x="185" y="1166.5" class="t">Memoria limpia</text><text x="185" y="1187.5" class="s">sin firmas, correos ni teléfonos</text><text x="185" y="1204.5" class="s">contraseñas tapadas</text><text x="185" y="1221.5" class="s">lo antiguo se marca como ANTIGUO</text><text x="185" y="1238.5" class="s">(sirve para entender, no para copiar)</text></g>
<g data-n="p1" tabindex="0" style="--k:var(--k-sys)" class="n q"><rect x="460" y="975.5" width="260" height="60" rx="30"/><circle class="qm" cx="484" cy="1005.5" r="11"/><text x="484" y="1010.1" class="qt">?</text><text x="604" y="1001.2" class="s q">¿El cliente ha enviado</text><text x="604" y="1018.2" class="s q">fotos?</text><circle class="bd" cx="466" cy="979.5" r="12"/><text x="466" y="983.7" class="b">4</text></g>
<g data-n="p2" tabindex="0" style="--k:var(--k-sys)" class="n"><rect x="455" y="1067.5" width="270" height="82" rx="11"/><text x="590" y="1093.5" class="t">Lee el código de barras</text><text x="590" y="1114.5" class="s">en el propio servidor (la foto no sale)</text><text x="590" y="1131.5" class="s">para saber de qué etiqueta se trata</text></g>
<g data-n="h1" tabindex="0" style="--k:var(--k-api)" class="n q"><rect x="760" y="975.5" width="280" height="60" rx="30"/><circle class="qm" cx="784" cy="1005.5" r="11"/><text x="784" y="1010.1" class="qt">?</text><text x="914" y="1001.2" class="s q">¿Podemos consultar el panel</text><text x="914" y="1018.2" class="s q">de hoteles? (solo lectura)</text><circle class="bd" cx="766" cy="979.5" r="12"/><text x="766" y="983.7" class="b">4</text></g>
<g data-n="h1x" tabindex="0" style="--k:var(--k-no)" class="n"><rect x="1115" y="977.5" width="200" height="82" rx="11"/><text x="1215" y="1003.5" class="t">Sin datos del hotel</text><text x="1215" y="1024.5" class="s">la IA se los pregunta</text><text x="1215" y="1041.5" class="s">al cliente</text></g>
<g data-n="h2" tabindex="0" style="--k:var(--k-api)" class="n"><rect x="760" y="1067.5" width="280" height="133" rx="11"/><text x="900" y="1093.5" class="t">Busca el hotel</text><text x="900" y="1114.5" class="s">por el correo del ticket, el nombre</text><text x="900" y="1131.5" class="s">(aunque traiga palabras de más), la</text><text x="900" y="1148.5" class="s">firma o la etiqueta; se identifica por</text><text x="900" y="1165.5" class="s">su id. Si no consta, quizá no sea hotel;</text><text x="900" y="1182.5" class="s">apps aún sin API: lo lleva una persona</text></g>
<g data-n="h3" tabindex="0" style="--k:var(--k-api)" class="n q"><rect x="760" y="1232.5" width="280" height="60" rx="30"/><circle class="qm" cx="784" cy="1262.5" r="11"/><text x="784" y="1267.1" class="qt">?</text><text x="914" y="1258.2" class="s q">¿Reconocemos el correo como</text><text x="914" y="1275.2" class="s q">usuario de la aplicación?</text></g>
<g data-n="h3x" tabindex="0" style="--k:var(--k-no)" class="n"><rect x="1115" y="1216.5" width="200" height="99" rx="11"/><text x="1215" y="1242.5" class="t">Correo no reconocido</text><text x="1215" y="1263.5" class="s">no se da información de</text><text x="1215" y="1280.5" class="s">hoteles; si el cliente nombra</text><text x="1215" y="1297.5" class="s">el hotel: solo sus antenas</text></g>
<g data-n="h4" tabindex="0" style="--k:var(--k-api)" class="n q"><rect x="760" y="1324.5" width="280" height="60" rx="30"/><circle class="qm" cx="784" cy="1354.5" r="11"/><text x="784" y="1359.1" class="qt">?</text><text x="914" y="1350.2" class="s q">¿El hotel que dice el cliente</text><text x="914" y="1367.2" class="s q">es el de ese correo?</text></g>
<g data-n="h4x" tabindex="0" style="--k:var(--k-no)" class="n"><rect x="1115" y="1318.5" width="200" height="82" rx="11"/><text x="1215" y="1344.5" class="t">No es el mismo</text><text x="1215" y="1365.5" class="s">se le explica con tacto</text><text x="1215" y="1382.5" class="s">y lo atiende una persona</text></g>
<g data-n="h5" tabindex="0" style="--k:var(--k-api)" class="n"><rect x="760" y="1416.5" width="280" height="99" rx="11"/><text x="900" y="1442.5" class="t">Datos del hotel para la IA</text><text x="900" y="1463.5" class="s">su rol y si el usuario está activo</text><text x="900" y="1480.5" class="s">antenas conectadas y desconectadas</text><text x="900" y="1497.5" class="s">estado de la etiqueta y de la posición</text></g>
<g data-n="h6" tabindex="0" style="--k:var(--k-api)" class="n"><rect x="760" y="1547.5" width="280" height="82" rx="11"/><text x="900" y="1573.5" class="t">Historial del cliente</text><text x="900" y="1594.5" class="s">sus últimos tickets y los de su hotel,</text><text x="900" y="1611.5" class="s">resumidos (solo con el correo verificado)</text></g>
<g data-n="sf" tabindex="0" style="--k:var(--k-sys)" class="chip"><rect x="315" y="989.5" width="120" height="32" rx="16"/><text x="375" y="1009.5" >Sin fotos: sigue</text></g>
<g data-n="d5" tabindex="0" style="--k:var(--k-sys)" class="n q"><rect x="445" y="1779.5" width="290" height="60" rx="30"/><circle class="qm" cx="469" cy="1809.5" r="11"/><text x="469" y="1814.1" class="qt">?</text><text x="604" y="1805.2" class="s q">¿Es un caso tan claro que</text><text x="604" y="1822.2" class="s q">no hace falta pensarlo?</text></g>
<g data-n="c1" tabindex="0" style="--k:var(--k-der)" class="n"><rect x="60" y="1769.5" width="250" height="82" rx="11"/><text x="185" y="1795.5" class="t">Pasa a una persona</text><text x="185" y="1816.5" class="s">con el cliente verificado: alérgenos,</text><text x="185" y="1833.5" class="s">tótems y garantías (salvo farmacias)</text></g>
<g data-n="c2" tabindex="0" style="--k:var(--k-cli)" class="n"><rect x="815" y="1769.5" width="290" height="116" rx="11"/><text x="960" y="1795.5" class="t">Pedir hotel y correo</text><text x="960" y="1816.5" class="s">norma de la dirección: en un hotel sin correo</text><text x="960" y="1833.5" class="s">verificado, se verifica ANTES de todo</text><text x="960" y="1850.5" class="s">(salvo material, presupuestos, farmacias,</text><text x="960" y="1867.5" class="s">tiendas y sitios que no son hoteles)</text></g>
<g data-n="pr" tabindex="0" style="--k:var(--k-sys)" class="n"><rect x="370" y="1945.5" width="440" height="99" rx="11"/><text x="590" y="1971.5" class="t">Prepara las instrucciones para la IA</text><text x="590" y="1992.5" class="s">las reglas del equipo + el orden de decisión</text><text x="590" y="2009.5" class="s">+ lo que sabemos del cliente y del hotel</text><text x="590" y="2026.5" class="s">+ «decisión ya tomada» si el caso era claro</text><circle class="bd" cx="370" cy="1945.5" r="12"/><text x="370" y="1949.7" class="b">5</text></g>
<g data-n="ia" tabindex="0" style="--k:var(--k-ai)" class="n ia"><rect x="390" y="2088.5" width="400" height="99" rx="11"/><text x="590" y="2114.5" class="t">IA · Claude Haiku 5.5</text><text x="590" y="2135.5" class="s">lee la conversación y las instrucciones</text><text x="590" y="2152.5" class="s">sigue el orden de decisión (7 puntos)</text><text x="590" y="2169.5" class="s">y escribe una propuesta de respuesta</text><circle class="bd" cx="390" cy="2088.5" r="12"/><text x="390" y="2092.7" class="b">6</text></g>
<g data-n="o1" tabindex="0" style="--k:var(--k-sys)" class="n q"><rect x="425" y="2307.5" width="330" height="82" rx="41"/><circle class="qm" cx="449" cy="2348.5" r="11"/><text x="449" y="2353.1" class="qt">?</text><text x="604" y="2335.7" class="s q">¿Es algo que siempre atiende</text><text x="604" y="2352.7" class="s q">una persona? (alérgenos, tótems,</text><text x="604" y="2369.7" class="s q">formaciones, fallos del contenido)</text><circle class="bd" cx="431" cy="2311.5" r="12"/><text x="431" y="2315.7" class="b">1</text></g>
<g data-n="o1x" tabindex="0" style="--k:var(--k-der)" class="n"><rect x="875" y="2307.5" width="370" height="82" rx="11"/><text x="1060" y="2333.5" class="t">Pasa a una persona</text><text x="1060" y="2354.5" class="s">sin hacer preguntas al cliente</text><text x="1060" y="2371.5" class="s">nota «Para el equipo» → Soporte</text></g>
<g data-n="o2" tabindex="0" style="--k:var(--k-sys)" class="n q"><rect x="425" y="2423.5" width="330" height="167" rx="83.5"/><circle class="qm" cx="449" cy="2507" r="11"/><text x="449" y="2511.6" class="qt">?</text><text x="604" y="2494.2" class="s q">¿Es de material o de dinero?</text><text x="604" y="2511.2" class="s q">(pedido, presupuesto,</text><text x="604" y="2528.2" class="s q">garantía o cambio de precio)</text><circle class="bd" cx="431" cy="2427.5" r="12"/><text x="431" y="2431.7" class="b">2</text></g>
<g data-n="o2x" tabindex="0" style="--k:var(--k-der)" class="n"><rect x="875" y="2423.5" width="370" height="167" rx="11"/><text x="1060" y="2449.5" class="t">Según el caso</text><text x="1060" y="2470.5" class="s">material (pilas, soportes…) → escribir a postventa</text><text x="1060" y="2487.5" class="s">   con material, cantidad y foto</text><text x="1060" y="2504.5" class="s">presupuesto que preparamos → «Asignar al depto.</text><text x="1060" y="2521.5" class="s">   de Pedidos y facturas.»; si pide el correo → postventa</text><text x="1060" y="2538.5" class="s">garantía → la lleva el equipo de Garantías</text><text x="1060" y="2555.5" class="s">garantía de farmacia → «la gestiona Expofarm»</text><text x="1060" y="2572.5" class="s">cambio de precio en tienda → lo hace soporte</text></g>
<g data-n="o3" tabindex="0" style="--k:var(--k-sys)" class="n q"><rect x="425" y="2624.5" width="330" height="82" rx="41"/><circle class="qm" cx="449" cy="2665.5" r="11"/><text x="449" y="2670.1" class="qt">?</text><text x="604" y="2652.7" class="s q">¿Lo tiene que hacer el equipo?</text><text x="604" y="2669.7" class="s q">(logo, tipos de letra,</text><text x="604" y="2686.7" class="s q">importar platos de Excel, altas)</text><circle class="bd" cx="431" cy="2628.5" r="12"/><text x="431" y="2632.7" class="b">3</text></g>
<g data-n="o3x" tabindex="0" style="--k:var(--k-der)" class="n"><rect x="875" y="2624.5" width="370" height="82" rx="11"/><text x="1060" y="2650.5" class="t">Pasa a una persona</text><text x="1060" y="2671.5" class="s">si ya sabemos el hotel, no se piden</text><text x="1060" y="2688.5" class="s">más datos para pasarlo</text></g>
<g data-n="o4" tabindex="0" style="--k:var(--k-sys)" class="n q"><rect x="425" y="2740.5" width="330" height="99" rx="49.5"/><circle class="qm" cx="449" cy="2790" r="11"/><text x="449" y="2794.6" class="qt">?</text><text x="604" y="2777.2" class="s q">¿Lo puede hacer el propio hotel?</text><text x="604" y="2794.2" class="s q">(usuarios, permisos, posiciones,</text><text x="604" y="2811.2" class="s q">cambiar un plato, forzar el menú)</text><circle class="bd" cx="431" cy="2744.5" r="12"/><text x="431" y="2748.7" class="b">4</text></g>
<g data-n="o4x" tabindex="0" style="--k:var(--k-ag)" class="n"><rect x="875" y="2740.5" width="370" height="99" rx="11"/><text x="1060" y="2766.5" class="t">Se le explica cómo</text><text x="1060" y="2787.5" class="s">con los pasos de la aplicación</text><text x="1060" y="2804.5" class="s">(Ajustes › Roles, Usuarios, Posiciones…)</text><text x="1060" y="2821.5" class="s">si nadie del hotel puede → una persona</text></g>
<g data-n="o5" tabindex="0" style="--k:var(--k-sys)" class="n q"><rect x="425" y="2873.5" width="330" height="82" rx="41"/><circle class="qm" cx="449" cy="2914.5" r="11"/><text x="449" y="2919.1" class="qt">?</text><text x="604" y="2910.2" class="s q">¿Es un problema de uso y aún</text><text x="604" y="2927.2" class="s q">no sabemos quién escribe?</text><circle class="bd" cx="431" cy="2877.5" r="12"/><text x="431" y="2881.7" class="b">5</text></g>
<g data-n="o5x" tabindex="0" style="--k:var(--k-cli)" class="n"><rect x="875" y="2873.5" width="370" height="82" rx="11"/><text x="1060" y="2899.5" class="t">Se pide hotel y correo</text><text x="1060" y="2920.5" class="s">como mucho esas dos preguntas</text><text x="1060" y="2937.5" class="s">lo demás se pregunta después</text></g>
<g data-n="o6" tabindex="0" style="--k:var(--k-sys)" class="n q"><rect x="425" y="2989.5" width="330" height="82" rx="41"/><circle class="qm" cx="449" cy="3030.5" r="11"/><text x="449" y="3035.1" class="qt">?</text><text x="604" y="3026.2" class="s q">¿Hay antenas sin conexión?</text><text x="604" y="3043.2" class="s q">(dato del panel de hoteles)</text><circle class="bd" cx="431" cy="2993.5" r="12"/><text x="431" y="2997.7" class="b">6</text></g>
<g data-n="o6x" tabindex="0" style="--k:var(--k-ag)" class="n"><rect x="875" y="2989.5" width="370" height="82" rx="11"/><text x="1060" y="3015.5" class="t">Se avisa a su personal técnico</text><text x="1060" y="3036.5" class="s">que revise sus antenas</text><text x="1060" y="3053.5" class="s">si ya se le dijo y sigue igual → una persona</text></g>
<g data-n="o7" tabindex="0" style="--k:var(--k-sys)" class="n q"><rect x="425" y="3105.5" width="330" height="82" rx="41"/><circle class="qm" cx="449" cy="3146.5" r="11"/><text x="449" y="3151.1" class="qt">?</text><text x="604" y="3142.2" class="s q">¿Lo que sabemos trae la</text><text x="604" y="3159.2" class="s q">respuesta o los pasos?</text><circle class="bd" cx="431" cy="3109.5" r="12"/><text x="431" y="3113.7" class="b">7</text></g>
<g data-n="o7x" tabindex="0" style="--k:var(--k-ag)" class="n"><rect x="875" y="3105.5" width="370" height="82" rx="11"/><text x="1060" y="3131.5" class="t">La IA responde</text><text x="1060" y="3152.5" class="s">solo con lo que consta en la memoria</text><text x="1060" y="3169.5" class="s">nunca inventa pasos, datos ni plazos</text></g>
<g data-n="o8" tabindex="0" style="--k:var(--k-no)" class="n"><rect x="420" y="3221.5" width="340" height="82" rx="11"/><text x="590" y="3247.5" class="t">Si no encaja o hay duda</text><text x="590" y="3268.5" class="s">pasa a una persona (o «no sé»)</text><text x="590" y="3285.5" class="s">mejor derivar que responder con dudas</text></g>
<g data-n="e1" tabindex="0" style="--k:var(--k-ai)" class="n q"><rect x="445" y="3445.5" width="290" height="82" rx="41"/><circle class="qm" cx="469" cy="3486.5" r="11"/><text x="469" y="3491.1" class="qt">?</text><text x="604" y="3482.2" class="s q">¿La IA ha podido contestar</text><text x="604" y="3499.2" class="s q">sin fallos?</text><circle class="bd" cx="451" cy="3449.5" r="12"/><text x="451" y="3453.7" class="b">7</text></g>
<g data-n="e1x" tabindex="0" style="--k:var(--k-no)" class="n"><rect x="830" y="3446.5" width="340" height="82" rx="11"/><text x="1000" y="3472.5" class="t">No ha podido responder</text><text x="1000" y="3493.5" class="s">aparece el botón «Reintentar»</text><text x="1000" y="3510.5" class="s">si ya costó algo, se cuenta</text></g>
<g data-n="e2" tabindex="0" style="--k:var(--k-ai)" class="n q"><rect x="445" y="3559.5" width="290" height="82" rx="41"/><circle class="qm" cx="469" cy="3600.5" r="11"/><text x="469" y="3605.1" class="qt">?</text><text x="604" y="3596.2" class="s q">¿La IA se atreve a contestar?</text><text x="604" y="3613.2" class="s q">(si no, dice «no sé»)</text></g>
<g data-n="e2x" tabindex="0" style="--k:var(--k-no)" class="n"><rect x="830" y="3560.5" width="340" height="82" rx="11"/><text x="1000" y="3586.5" class="t">La IA no sabe</text><text x="1000" y="3607.5" class="s">no hay propuesta: lo lleva una persona</text><text x="1000" y="3624.5" class="s">queda apuntado en Ajustes para documentarlo</text></g>
<g data-n="e3" tabindex="0" style="--k:var(--k-ai)" class="n q"><rect x="445" y="3673.5" width="290" height="112" rx="56"/><circle class="qm" cx="469" cy="3729.5" r="11"/><text x="469" y="3734.1" class="qt">?</text><text x="604" y="3725.2" class="s q">¿La IA propone pasarlo</text><text x="604" y="3742.2" class="s q">a una persona?</text></g>
<g data-n="e3x" tabindex="0" style="--k:var(--k-der)" class="n"><rect x="830" y="3673.5" width="340" height="116" rx="11"/><text x="1000" y="3699.5" class="t">Pasa a una persona</text><text x="1000" y="3720.5" class="s">aviso «Para el equipo»:</text><text x="1000" y="3737.5" class="s">«Llévalo a Soporte, Garantías o Pedidos»</text><text x="1000" y="3754.5" class="s">y un mensaje corto para el cliente</text><text x="1000" y="3771.5" class="s">lo ve el agente; el cliente no</text></g>
<g data-n="e4" tabindex="0" style="--k:var(--k-ag)" class="n"><rect x="410" y="3817.5" width="360" height="82" rx="11"/><text x="590" y="3843.5" class="t">Propuesta de respuesta</text><text x="590" y="3864.5" class="s">contesta, pide datos o indica dónde escribir</text><text x="590" y="3881.5" class="s">se muestra en el panel: no se envía sola</text></g>
<g data-n="s1" tabindex="0" style="--k:var(--k-sys)" class="n"><rect x="370" y="3939.5" width="440" height="99" rx="11"/><text x="590" y="3965.5" class="t">Se guarda la sugerencia</text><text x="590" y="3986.5" class="s">y lo que ha costado: se suma al gasto del mes</text><text x="590" y="4003.5" class="s">aviso al llegar al 80 % y al 100 % de 50 USD</text><text x="590" y="4020.5" class="s">el panel de Ajustes muestra el gasto por día</text><circle class="bd" cx="370" cy="3939.5" r="12"/><text x="370" y="3943.7" class="b">8</text></g>
<g data-n="g1" tabindex="0" style="--k:var(--k-ag)" class="n"><rect x="390" y="4152.5" width="400" height="82" rx="11"/><text x="590" y="4178.5" class="t">El agente ve la sugerencia</text><text x="590" y="4199.5" class="s">decide él: la IA nunca envía nada</text><text x="590" y="4216.5" class="s">aquí también llegan los casos de la sección A</text><circle class="bd" cx="390" cy="4152.5" r="12"/><text x="390" y="4156.7" class="b">9</text></g>
<g data-n="f3" tabindex="0" style="--k:var(--k-sys)" class="n"><rect x="55" y="4288.5" width="260" height="82" rx="11"/><text x="185" y="4314.5" class="t">Contesta sin la IA</text><text x="185" y="4335.5" class="s">si no hay propuesta o no le sirve</text><text x="185" y="4352.5" class="s">el cliente recibe su respuesta</text></g>
<g data-n="f2" tabindex="0" style="--k:var(--k-ag)" class="n"><rect x="355" y="4288.5" width="260" height="82" rx="11"/><g class="ic" transform="translate(412.2,4298.5) scale(0.72)"><rect x="2" y="4" width="20" height="16" rx="2"/><path d="m22 7-8.97 5.7a1.94 1.94 0 0 1-2.06 0L2 7"/></g><text x="497" y="4314.5" class="t">Copia y envía él</text><text x="485" y="4335.5" class="s">puede retocarla antes</text><text x="485" y="4352.5" class="s">se apunta como usada</text></g>
<g data-n="f1" tabindex="0" style="--k:var(--k-ag)" class="n"><rect x="655" y="4288.5" width="260" height="82" rx="11"/><g class="ic" transform="translate(757.8,4298.5) scale(0.72)"><path d="M7 10v12"/><path d="M15 5.88 14 10h5.83a2 2 0 0 1 1.92 2.56l-2.33 8A2 2 0 0 1 17.5 22H4a2 2 0 0 1-2-2v-8a2 2 0 0 1 2-2h2.76a2 2 0 0 0 1.79-1.11L12 2a3.13 3.13 0 0 1 3 3.88Z"/></g><text x="797" y="4314.5" class="t">Útil</text><text x="785" y="4335.5" class="s">queda en el historial</text><text x="785" y="4352.5" class="s">para medir cuánto acierta la IA</text></g>
<g data-n="f4" tabindex="0" style="--k:var(--k-no)" class="n"><rect x="955" y="4288.5" width="260" height="82" rx="11"/><g class="ic" transform="translate(1042.6,4298.5) scale(0.72)"><g transform="translate(0,24) scale(1,-1)"><path d="M7 10v12"/><path d="M15 5.88 14 10h5.83a2 2 0 0 1 1.92 2.56l-2.33 8A2 2 0 0 1 17.5 22H4a2 2 0 0 1-2-2v-8a2 2 0 0 1 2-2h2.76a2 2 0 0 0 1.79-1.11L12 2a3.13 3.13 0 0 1 3 3.88Z"/></g></g><text x="1097" y="4314.5" class="t">No sirve</text><text x="1085" y="4335.5" class="s">escribe qué falló y cómo había</text><text x="1085" y="4352.5" class="s">que responder · queda en el historial</text></g>
<g data-n="r1" tabindex="0" style="--k:var(--k-cli)" class="chip"><rect x="160" y="4400.5" width="350" height="32" rx="16"/><g class="ic" transform="translate(172,4408.5) scale(0.66)"><path d="M17 2l4 4-4 4"/><path d="M3 11V9a3 3 0 0 1 3-3h15"/><path d="M7 22l-4-4 4-4"/><path d="M21 13v2a3 3 0 0 1-3 3H3"/></g><text x="345" y="4420.5" >El cliente contesta: se vuelve al paso 1</text></g>
<g data-n="v2" tabindex="0" style="--k:var(--k-mem)" class="n"><rect x="955" y="4410.5" width="260" height="82" rx="11"/><text x="1085" y="4436.5" class="t">Pasa directo a la memoria</text><text x="1085" y="4457.5" class="s">sin revisión previa; queda apuntado</text><text x="1085" y="4474.5" class="s">quién, cuándo y qué corrigió</text><circle class="bd" cx="955" cy="4410.5" r="13"/><text x="955" y="4414.7" class="b">10</text></g>
<g data-n="r2" tabindex="0" style="--k:var(--k-mem)" class="chip"><rect x="955" y="4520.5" width="260" height="32" rx="16"/><g class="ic" transform="translate(967,4528.5) scale(0.66)"><path d="M17 2l4 4-4 4"/><path d="M3 11V9a3 3 0 0 1 3-3h15"/><path d="M7 22l-4-4 4-4"/><path d="M21 13v2a3 3 0 0 1-3 3H3"/></g><text x="1095" y="4540.5" >Vuelve a la memoria (paso 4)</text></g>
<g class="pill si"><rect x="751" y="337" width="28" height="18" rx="9"/><text x="765" y="349.5">Sí</text></g>
<g class="pill no"><rect x="598" y="394" width="28" height="18" rx="9"/><text x="612" y="406.5">No</text></g>
<g class="pill si"><rect x="751" y="433.5" width="28" height="18" rx="9"/><text x="765" y="446">Sí</text></g>
<g class="pill no"><rect x="598" y="490.5" width="28" height="18" rx="9"/><text x="612" y="503">No</text></g>
<g class="pill si"><rect x="751" y="547" width="28" height="18" rx="9"/><text x="765" y="559.5">Sí</text></g>
<g class="pill no"><rect x="598" y="604" width="28" height="18" rx="9"/><text x="612" y="616.5">No</text></g>
<g class="pill si"><rect x="751" y="660.5" width="28" height="18" rx="9"/><text x="765" y="673">Sí</text></g>
<g class="pill no"><rect x="598" y="717.5" width="28" height="18" rx="9"/><text x="612" y="730">No</text></g>
<g class="pill si"><rect x="598" y="1045.5" width="28" height="18" rx="9"/><text x="612" y="1058">Sí</text></g>
<g class="pill no"><rect x="1046" y="986.5" width="28" height="18" rx="9"/><text x="1060" y="999">No</text></g>
<g class="pill si"><rect x="908" y="1045.5" width="28" height="18" rx="9"/><text x="922" y="1058">Sí</text></g>
<g class="pill no"><rect x="1046" y="1243.5" width="28" height="18" rx="9"/><text x="1060" y="1256">No</text></g>
<g class="pill si"><rect x="908" y="1302.5" width="28" height="18" rx="9"/><text x="922" y="1315">Sí</text></g>
<g class="pill no"><rect x="1046" y="1335.5" width="28" height="18" rx="9"/><text x="1060" y="1348">No</text></g>
<g class="pill si"><rect x="908" y="1394.5" width="28" height="18" rx="9"/><text x="922" y="1407">Sí</text></g>
<g class="pill no"><rect x="431" y="971.5" width="28" height="18" rx="9"/><text x="445" y="984">No</text></g>
<g class="pill si"><rect x="411" y="1790.5" width="28" height="18" rx="9"/><text x="425" y="1803">Sí</text></g>
<g class="pill si"><rect x="741" y="1790.5" width="28" height="18" rx="9"/><text x="755" y="1803">Sí</text></g>
<text x="602" y="1855.5" class="et" text-anchor="start">No: decide la IA</text>
<text x="602" y="2070.5" class="et" text-anchor="start">una sola consulta (tiene un pequeño coste)</text>
<g class="pill si"><rect x="761" y="2329.5" width="28" height="18" rx="9"/><text x="775" y="2342">Sí</text></g>
<g class="pill no"><rect x="598" y="2404.5" width="28" height="18" rx="9"/><text x="612" y="2417">No</text></g>
<g class="pill si"><rect x="761" y="2488" width="28" height="18" rx="9"/><text x="775" y="2500.5">Sí</text></g>
<g class="pill no"><rect x="598" y="2605.5" width="28" height="18" rx="9"/><text x="612" y="2618">No</text></g>
<g class="pill si"><rect x="761" y="2646.5" width="28" height="18" rx="9"/><text x="775" y="2659">Sí</text></g>
<g class="pill no"><rect x="598" y="2721.5" width="28" height="18" rx="9"/><text x="612" y="2734">No</text></g>
<g class="pill si"><rect x="761" y="2771" width="28" height="18" rx="9"/><text x="775" y="2783.5">Sí</text></g>
<g class="pill no"><rect x="598" y="2854.5" width="28" height="18" rx="9"/><text x="612" y="2867">No</text></g>
<g class="pill si"><rect x="761" y="2895.5" width="28" height="18" rx="9"/><text x="775" y="2908">Sí</text></g>
<g class="pill no"><rect x="598" y="2970.5" width="28" height="18" rx="9"/><text x="612" y="2983">No</text></g>
<g class="pill si"><rect x="761" y="3011.5" width="28" height="18" rx="9"/><text x="775" y="3024">Sí</text></g>
<g class="pill no"><rect x="598" y="3086.5" width="28" height="18" rx="9"/><text x="612" y="3099">No</text></g>
<g class="pill si"><rect x="761" y="3127.5" width="28" height="18" rx="9"/><text x="775" y="3140">Sí</text></g>
<g class="pill no"><rect x="598" y="3195.5" width="28" height="18" rx="9"/><text x="612" y="3208">No</text></g>
<g class="pill no"><rect x="741" y="3467.5" width="28" height="18" rx="9"/><text x="755" y="3480">No</text></g>
<g class="pill si"><rect x="598" y="3537.5" width="28" height="18" rx="9"/><text x="612" y="3550">Sí</text></g>
<g class="pill no"><rect x="741" y="3581.5" width="28" height="18" rx="9"/><text x="755" y="3594">No</text></g>
<g class="pill si"><rect x="598" y="3651.5" width="28" height="18" rx="9"/><text x="612" y="3664">Sí</text></g>
<g class="pill si"><rect x="741" y="3710.5" width="28" height="18" rx="9"/><text x="755" y="3723">Sí</text></g>
<g class="pill no"><rect x="598" y="3795.5" width="28" height="18" rx="9"/><text x="612" y="3808">No</text></g>
</svg><span class="ancla" id="cap-a" style="top:0.348%"></span><span class="ancla" id="cap-b" style="top:16.494%"></span><span class="ancla" id="cap-c" style="top:37.050%"></span><span class="ancla" id="cap-d" style="top:48.547%"></span><span class="ancla" id="cap-e" style="top:73.326%"></span><span class="ancla" id="cap-f" style="top:88.721%"></span><!--/LIENZO--></div>

  <section class="detalle" id="detalle">
    <h2>Detalle de cada capítulo</h2>
    <p>Lo mismo que muestra el diagrama, con las reglas y los matices de cada paso.</p>

    <article class="capitulo" id="det-a">
      <div><h3><i>A</i>Puertas de entrada</h3><p class="res">Qué se comprueba antes de gastar nada.</p></div>
      <dl>
        <div><dt>Si la IA está desactivada</dt><dd>No aparece el botón «Sugerir» y no sale ningún dato hacia fuera. Un responsable la activa o la apaga en Ajustes › Asistente IA. Para poder activarla, el servidor tiene que estar conectado al servicio de IA (se configura aparte, nunca en pantalla).</dd></div>
        <div><dt>Sugerencia ya guardada</dt><dd>Si ya hay una para el último mensaje del cliente, se enseña sin gastar. «Regenerar» fuerza una nueva.</dd></div>
        <div><dt>Tope mensual de gasto</dt><dd>50 USD al mes (mes natural; se cambia en Ajustes › Asistente IA). Al 80 % y al 100 % el panel avisa. Al llegar al tope el botón dice «Contesta tú» y no se consulta a la IA hasta el día 1. Solo cuentan las sugerencias de los agentes: las pruebas internas no cortan a nadie.</dd></div>
        <div><dt>Solo audios de WhatsApp</dt><dd>Texto fijo: «Lo sentimos, ahora mismo no podemos escuchar este audio…». No se consulta a la IA y no cuesta nada.</dd></div>
      </dl>
    </article>

    <article class="capitulo" id="det-b">
      <div><h3><i>B</i>Contexto</h3><p class="res">Lo que la IA lee antes de redactar: memoria, fotos y datos del hotel.</p></div>
      <div>
        <dl>
          <div><dt>¿De qué hotel habla? <span class="tag">Panel de hoteles</span></dt><dd>Busca los nombres que aparecen en el mensaje o la firma y los localiza en el panel. Solo se mira, nunca se cambia nada.</dd></div>
          <div><dt>¿El correo figura?</dt><dd>Lo comprueba entre los usuarios: hotel, buffet, <b>rol</b> y si el usuario está activo. Si no cuadra con el hotel que dice, no revela nada.</dd></div>
          <div><dt>Antenas y etiqueta</dt><dd>Cuántas antenas hay y cuáles están sin conexión (el estado de ahora, no el del día del ticket). Si hay un código de etiqueta: batería, señal, última actualización y la antena que la sirve.</dd></div>
          <div><dt>Si el panel no contesta</dt><dd>La IA lo sabe y no afirma nada que no haya podido comprobar.</dd></div>
          <div><dt>Preguntas frecuentes</dt><dd>Las publicadas en el portal.</dd></div>
          <div><dt>Tickets resueltos parecidos <span class="tag">Memoria</span></dt><dd>Sirven de ejemplo, nunca para citar datos de otro cliente. Los antiguos, donde el equipo hacía por el cliente lo que hoy hace el hotel, salen con el aviso «ANTIGUO». Los que entregaban contraseñas no se usan.</dd></div>
          <div><dt>Guía y reglas de soporte</dt><dd>Manuales de la aplicación de hoteles y documentación del equipo.</dd></div>
          <div><dt>Correcciones validadas</dt><dd>Lo que los agentes han enseñado con «No sirve». Entran directamente, sin que nadie las valide antes (un responsable puede retirar una mala), más unas pocas de partida que el equipo dejó preparadas.</dd></div>
          <div><dt>Lectura de fotos</dt><dd>Si hay fotos, el código de barras se lee en el servidor: la foto no sale.</dd></div>
        </dl>
        <p class="nota"><b>Datos personales.</b> En la memoria se quitan correos, teléfonos, nombres, firmas y avisos legales. La conversación del ticket que se está contestando se envía tal cual al servicio de IA, porque necesita leerla. Las notas internas nunca entran.</p>
      </div>
    </article>

    <article class="capitulo" id="det-c">
      <div><h3><i>C</i>Decisión en código</h3><p class="res">Lo evidente no se deja al azar: se decide sin la IA.</p></div>
      <dl>
        <div><dt>Siempre derivan</dt><dd>Alérgenos, tótems y garantías (salvo las de farmacia): respuesta corta, sin preguntar nada, y nota «Para el equipo».</dd></div>
        <div><dt>Piden hotel y correo</dt><dd>Un problema de uso en el primer mensaje, sin hotel ni correo verificado: solo se pregunta eso.</dd></div>
        <div><dt>El resto</dt><dd>Lo decide la IA. Se le dan las reglas del equipo, el orden de decisión, la memoria, los datos del hotel y, si el caso ya estaba claro, una nota de «decisión ya tomada».</dd></div>
      </dl>
    </article>

    <article class="capitulo" id="det-d">
      <div><h3><i>D</i>Orden de decisión</h3><p class="res">Siete puntos, en este orden. Si dos reglas chocan, gana el orden.</p></div>
      <dl>
        <div><dt>Siempre se deriva</dt><dd>Alérgenos, garantías (sin dar correo: las lleva el responsable en la aplicación), estado de envíos, tótems, dominios, y lo que ejecuta el equipo (el logo, tipos de letra, importar platos desde Excel, dar de alta etiquetas en Conectividad).</dd></div>
        <div><dt>Lo gestiona el hotel</dt><dd>La IA explica cómo: altas de usuarios, permisos y roles (Ajustes › Roles, Usuarios), posiciones, nombres de etiquetas y forzar el menú. Solo se deriva si nadie en el hotel puede.</dd></div>
        <div><dt>Farmacias y precios</dt><dd>Garantía de una farmacia: «lo gestiona Expofarm», sin correo. Cambio de precio en tienda: lo hace una persona de soporte.</dd></div>
        <div><dt>Presupuestos</dt><dd>Si el cliente pide que le preparemos o enviemos el presupuesto, el borrador es «Asignar al departamento de Pedidos y facturas». Si solo pide el correo o dónde pedirlo (también por una rotura), se le da el de postventa con material, cantidad y foto.</dd></div>
        <div><dt>Se contesta con el correo</dt><dd>Pedidos de material (pilas, soportes, recambios, etiquetas): «escriba a postventa@aemegroup.com con material, cantidad y foto de referencia».</dd></div>
        <div><dt>Verificar primero</dt><dd>Configurar algo del buffet pide el correo antes de dar los pasos.</dd></div>
        <div><dt>Antenas</dt><dd>Todas en línea: pregunta y guía a forzar el menú. Alguna caída: que lo revise su personal técnico; si ya se le dijo, se deriva.</dd></div>
        <div><dt>Máximo dos preguntas</dt><dd>Sin preguntar lo obvio ni inventar datos.</dd></div>
        <div><dt>Nunca inventa</dt><dd>Si no está seguro, no contesta («no sé») y una persona lo lleva. Es preferible derivar a dar una respuesta dudosa.</dd></div>
      </dl>
    </article>

    <article class="capitulo" id="det-e">
      <div><h3><i>E</i>Salida</h3><p class="res">Solo sugerencias: nada se envía al cliente.</p></div>
      <dl>
        <div><dt>Borrador de respuesta</dt><dd>Una respuesta lista para revisar: explica, pide datos o indica el canal.</dd></div>
        <div><dt>Derivar</dt><dd>Un mensaje corto para el cliente («hemos registrado la incidencia y el equipo la revisará») y, aparte, un bloque <b>«Para el equipo»</b> en el panel: la categoría a la que llevarlo (Soporte, Garantías o Pedidos y facturas) y el motivo. Ese bloque lo ve el agente, no el cliente.</dd></div>
        <div><dt>No sabe o falla</dt><dd>No hay borrador: el agente ve «La IA no se atreve» o un aviso con «Reintentar». Lo que la IA no supo queda anotado en Ajustes para saber qué documentar.</dd></div>
        <div><dt>Coste</dt><dd>Cada consulta guarda lo que ha costado. El panel suma el gasto estimado del mes, avisa al 80 % y al 100 % del tope y muestra el gasto por día.</dd></div>
      </dl>
    </article>

    <article class="capitulo" id="det-f">
      <div><h3><i>F</i>Agente y aprendizaje</h3><p class="res">La persona decide; lo que corrige mejora las siguientes sugerencias.</p></div>
      <dl>
        <div><dt>Panel lateral (solo para mirar)</dt><dd>Enseña cada paso al momento (hotel, correo, rol, antenas, memoria) y deja el borrador. Solo se puede <b>copiar</b>: no se escribe en el editor y la IA nunca envía.</dd></div>
        <div><dt>Útil</dt><dd>Queda anotado para medir el acierto.</dd></div>
        <div><dt>Historial de valoraciones</dt><dd>Cada valoración queda apuntada para siempre en Ajustes › Asistente IA: quién, cuándo, en qué ticket, qué sugirió la IA, qué corrigió la persona y qué se envió finalmente al cliente. Si alguien cambia de opinión, la primera valoración no se borra. Si un responsable retira una corrección, también queda.</dd></div>
        <div><dt>No sirve</dt><dd>El agente escribe qué estaba mal y cómo había que responder. Se guarda y pasa directa a la memoria: nadie tiene que validarla antes.</dd></div>
        <div><dt>Otra sugerencia</dt><dd>Vuelve a pedir a la IA, con la indicación del agente.</dd></div>
        <div><dt>Retirar una corrección mala (opcional)</dt><dd>En Ajustes › Asistente IA, un responsable puede retirar una corrección concreta y la IA deja de usarla. No es un paso obligatorio ni hay nadie encargado de revisarlas una a una.</dd></div>
      </dl>
    </article>
  </section>

  <footer>Fase 2 (respuesta automática fuera de horario) todavía no está hecha: usaría este mismo recorrido, con un aviso fijo de que habla una IA y salida a una persona.</footer>
</div>
<script src="/ia-flujo.js" defer></script>
</body>
</html>
