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
  .banda rect { fill: var(--band); }
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
      <p style="margin:0"><b>Aprende de las personas</b><span>Solo mejora con lo que un responsable valida.</span></p>
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

  <div class="lienzo"><!--LIENZO--><svg viewBox="0 0 1400 4481.5" role="img" aria-label="Flujo completo de la IA de soporte, de que el cliente escribe a que el responsable valida la corrección">
<defs><marker id="p" viewBox="0 0 10 10" refX="8.5" refY="5" markerWidth="6" markerHeight="6" orient="auto-start-reverse"><path d="M0,0.5 L10,5 L0,9.5 z" class="punta"/></marker></defs>
<g class="banda"><rect x="40" y="16" width="1330" height="723.5" rx="18"/><circle cx="72" cy="48" r="15"/><text x="72" y="53.5" class="bl">A</text><text x="98" y="47" class="bt">Puertas de entrada</text><text x="98" y="68" class="bs">Antes de gastar nada, se comprueba si toca consultar a la IA</text></g>
<g class="banda"><rect x="40" y="757.5" width="1330" height="761" rx="18"/><circle cx="72" cy="789.5" r="15"/><text x="72" y="795" class="bl">B</text><text x="98" y="788.5" class="bt">Contexto</text><text x="98" y="809.5" class="bs">Lo que la IA necesita saber: memoria, fotos y datos del hotel</text></g>
<g class="banda"><rect x="40" y="1536.5" width="1330" height="476" rx="18"/><circle cx="72" cy="1568.5" r="15"/><text x="72" y="1574" class="bl">C</text><text x="98" y="1567.5" class="bt">Decisión en código</text><text x="98" y="1588.5" class="bs">Lo evidente se decide sin la IA; luego se le dan instrucciones</text></g>
<g class="banda"><rect x="40" y="2030.5" width="1330" height="1120" rx="18"/><circle cx="72" cy="2062.5" r="15"/><text x="72" y="2068" class="bl">D</text><text x="98" y="2061.5" class="bt">Orden de decisión</text><text x="98" y="2082.5" class="bs">La IA repasa estos puntos en orden: el primero que encaja manda</text></g>
<g class="banda"><rect x="40" y="3168.5" width="1330" height="689" rx="18"/><circle cx="72" cy="3200.5" r="15"/><text x="72" y="3206" class="bl">E</text><text x="98" y="3199.5" class="bt">Salida</text><text x="98" y="3220.5" class="bs">Qué pasa con la respuesta de la IA, y cuánto cuesta</text></g>
<g class="banda"><rect x="40" y="3875.5" width="1330" height="590" rx="18"/><circle cx="72" cy="3907.5" r="15"/><text x="72" y="3913" class="bl">F</text><text x="98" y="3906.5" class="bt">Agente y aprendizaje</text><text x="98" y="3927.5" class="bs">La persona decide; lo que se valida hace que la IA acierte más</text></g>
<g class="fl" marker-end="url(#p)"><path d="M360,135 L420,135"/><path d="M590,176 L590,210"/><path d="M590,292 L590,326"/><path d="M745,356 L835,356"/><path d="M590,386 L590,422.5"/><path d="M745,452.5 L835,452.5"/><path d="M590,482.5 L590,536"/><path d="M745,566 L835,566"/><path d="M590,596 L590,649.5"/><path d="M745,679.5 L835,679.5"/><path d="M590,709.5 L590,835.5"/><path d="M185,1091.5 L185,1123.5"/><path d="M590,1035.5 L590,1067.5"/><path d="M1040,1005.5 L1115,1018.5"/><path d="M900,1035.5 L900,1067.5"/><path d="M900,1149.5 L900,1181.5"/><path d="M1040,1211.5 L1115,1215"/><path d="M900,1241.5 L900,1273.5"/><path d="M1040,1303.5 L1115,1308.5"/><path d="M900,1333.5 L900,1365.5"/><path marker-end="none" d="M590,917.5 L590,947.5"/><path marker-end="none" d="M185,947.5 L900,947.5"/><path d="M185,947.5 L185,975.5"/><path d="M590,947.5 L590,975.5"/><path d="M900,947.5 L900,975.5"/><path d="M460,1005.5 L435,1005.5"/><path marker-end="none" d="M185,1239.5 L185,1498.5"/><path marker-end="none" d="M590,1149.5 L590,1498.5"/><path marker-end="none" d="M900,1464.5 L900,1498.5"/><path marker-end="none" d="M185,1498.5 L900,1498.5"/><path d="M590,1498.5 L590,1614.5"/><path d="M445,1644.5 L310,1645.5"/><path d="M735,1644.5 L815,1645.5"/><path marker-end="none" d="M185,1686.5 L185,1712.5 L960,1712.5"/><path marker-end="none" d="M960,1686.5 L960,1712.5"/><path d="M590,1674.5 L590,1746.5"/><path d="M590,1845.5 L590,1889.5"/><path d="M590,1988.5 L590,2108.5"/><path d="M755,2149.5 L875,2149.5"/><path d="M590,2190.5 L590,2224.5"/><path d="M755,2308 L875,2308"/><path d="M590,2391.5 L590,2425.5"/><path d="M755,2466.5 L875,2466.5"/><path d="M590,2507.5 L590,2541.5"/><path d="M755,2591 L875,2591"/><path d="M590,2640.5 L590,2674.5"/><path d="M755,2715.5 L875,2715.5"/><path d="M590,2756.5 L590,2790.5"/><path d="M755,2831.5 L875,2831.5"/><path d="M590,2872.5 L590,2906.5"/><path d="M755,2947.5 L875,2947.5"/><path d="M590,2988.5 L590,3022.5"/><path marker-end="none" d="M1245,2149.5 L1338,2149.5"/><path marker-end="none" d="M1245,2308 L1338,2308"/><path marker-end="none" d="M1245,2466.5 L1338,2466.5"/><path marker-end="none" d="M1245,2591 L1338,2591"/><path marker-end="none" d="M1245,2715.5 L1338,2715.5"/><path marker-end="none" d="M1245,2831.5 L1338,2831.5"/><path marker-end="none" d="M1245,2947.5 L1338,2947.5"/><path marker-end="none" d="M1338,2149.5 L1338,3134.5"/><path marker-end="none" d="M590,3104.5 L590,3134.5"/><path marker-end="none" d="M1338,3134.5 L590,3134.5"/><path d="M590,3134.5 L590,3246.5"/><path d="M735,3287.5 L830,3288.5"/><path d="M590,3328.5 L590,3360.5"/><path d="M735,3401.5 L830,3402.5"/><path d="M590,3442.5 L590,3474.5"/><path d="M735,3530.5 L830,3532.5"/><path d="M590,3586.5 L590,3618.5"/><path d="M590,3700.5 L590,3740.5"/><path marker-end="none" d="M1170,3288.5 L1338,3288.5"/><path marker-end="none" d="M1170,3402.5 L1338,3402.5"/><path marker-end="none" d="M1170,3532.5 L1338,3532.5"/><path marker-end="none" d="M1338,3288.5 L1338,3720.5"/><path marker-end="none" d="M1338,3720.5 L590,3720.5"/><path d="M590,3839.5 L590,3953.5"/><path marker-end="none" d="M590,4035.5 L590,4063.5"/><path marker-end="none" d="M185,4063.5 L1085,4063.5"/><path d="M185,4063.5 L185,4089.5"/><path d="M485,4063.5 L485,4089.5"/><path d="M785,4063.5 L785,4089.5"/><path d="M1085,4063.5 L1085,4089.5"/><path d="M185,4171.5 L185,4201.5"/><path d="M485,4171.5 L485,4201.5"/><path d="M1085,4171.5 L1085,4205.5"/><path d="M950,4235.5 L910,4238"/><path d="M1085,4265.5 L1085,4299.5"/><path d="M1085,4381.5 L1085,4409.5"/></g>
<g style="--k:var(--k-cli)" class="n"><rect x="420" y="94" width="340" height="82" rx="11"/><text x="590" y="120" class="t">Cliente escribe</text><text x="590" y="141" class="s">por correo, WhatsApp, formulario o portal</text><text x="590" y="158" class="s">el mensaje llega al ticket</text><circle class="bd" cx="420" cy="94" r="12"/><text x="420" y="98.2" class="b">1</text></g>
<g style="--k:var(--k-cli)" class="chip"><rect x="150" y="119" width="210" height="32" rx="16"/><g class="ic" transform="translate(162,127) scale(0.66)"><path d="M17 2l4 4-4 4"/><path d="M3 11V9a3 3 0 0 1 3-3h15"/><path d="M7 22l-4-4 4-4"/><path d="M21 13v2a3 3 0 0 1-3 3H3"/></g><text x="265" y="139" >Vuelve aquí desde el paso 9</text></g>
<g style="--k:var(--k-ag)" class="n"><rect x="420" y="210" width="340" height="82" rx="11"/><text x="590" y="236" class="t">El agente pide la sugerencia</text><text x="590" y="257" class="s">abre el ticket y pulsa «Sugerir»</text><text x="590" y="274" class="s">la IA no actúa por su cuenta</text><circle class="bd" cx="420" cy="210" r="12"/><text x="420" y="214.2" class="b">2</text></g>
<g style="--k:var(--k-sys)" class="n q"><rect x="435" y="326" width="310" height="60" rx="30"/><circle class="qm" cx="459" cy="356" r="11"/><text x="459" y="360.6" class="qt">?</text><text x="604" y="351.7" class="s q">¿La IA está activada</text><text x="604" y="368.7" class="s q">en Ajustes?</text></g>
<g style="--k:var(--k-no)" class="n"><rect x="835" y="323.5" width="330" height="65" rx="11"/><text x="1000" y="349.5" class="t">No aparece el botón</text><text x="1000" y="370.5" class="s">no sale ningún dato hacia fuera</text></g>
<g style="--k:var(--k-sys)" class="n q"><rect x="435" y="422.5" width="310" height="60" rx="30"/><circle class="qm" cx="459" cy="452.5" r="11"/><text x="459" y="457.1" class="qt">?</text><text x="604" y="448.2" class="s q">¿Ya se sugirió algo para</text><text x="604" y="465.2" class="s q">este mensaje del cliente?</text></g>
<g style="--k:var(--k-mem)" class="n"><rect x="835" y="403" width="330" height="99" rx="11"/><text x="1000" y="429" class="t">Se enseña la que ya estaba</text><text x="1000" y="450" class="s">no se vuelve a consultar a la IA</text><text x="1000" y="467" class="s">«Regenerar» pide una nueva</text><text x="1000" y="484" class="s">→ sigue en el paso 9</text></g>
<g style="--k:var(--k-sys)" class="n q"><rect x="435" y="536" width="310" height="60" rx="30"/><circle class="qm" cx="459" cy="566" r="11"/><text x="459" y="570.6" class="qt">?</text><text x="604" y="561.7" class="s q">¿Se ha gastado ya lo previsto</text><text x="604" y="578.7" class="s q">para este mes? (50 USD)</text></g>
<g style="--k:var(--k-no)" class="n"><rect x="835" y="516.5" width="330" height="99" rx="11"/><text x="1000" y="542.5" class="t">Se avisa al agente</text><text x="1000" y="563.5" class="s">«Contesta tú; la IA vuelve el día 1»</text><text x="1000" y="580.5" class="s">no se consulta a la IA</text><text x="1000" y="597.5" class="s">→ lo contesta el agente</text></g>
<g style="--k:var(--k-sys)" class="n q"><rect x="435" y="649.5" width="310" height="60" rx="30"/><circle class="qm" cx="459" cy="679.5" r="11"/><text x="459" y="684.1" class="qt">?</text><text x="604" y="675.2" class="s q">¿El cliente solo ha mandado</text><text x="604" y="692.2" class="s q">audios de WhatsApp?</text></g>
<g style="--k:var(--k-sys)" class="n"><rect x="835" y="630" width="330" height="99" rx="11"/><text x="1000" y="656" class="t">Respuesta fija, sin IA</text><text x="1000" y="677" class="s">«Ahora mismo no podemos escuchar</text><text x="1000" y="694" class="s">audios…» · no cuesta nada</text><text x="1000" y="711" class="s">→ sigue en el paso 9</text></g>
<g style="--k:var(--k-sys)" class="n"><rect x="390" y="835.5" width="400" height="82" rx="11"/><text x="590" y="861.5" class="t">Prepara la conversación</text><text x="590" y="882.5" class="s">lee lo que ha escrito el cliente y lo que se le contestó</text><text x="590" y="899.5" class="s">(sin notas internas) y busca su última pregunta</text><circle class="bd" cx="390" cy="835.5" r="12"/><text x="390" y="839.7" class="b">3</text></g>
<g style="--k:var(--k-mem)" class="n"><rect x="60" y="975.5" width="250" height="116" rx="11"/><text x="185" y="1001.5" class="t">Memoria y guía</text><text x="185" y="1022.5" class="s">preguntas frecuentes</text><text x="185" y="1039.5" class="s">respuestas de ejemplo del equipo</text><text x="185" y="1056.5" class="s">casos parecidos y manuales</text><text x="185" y="1073.5" class="s">correcciones validadas (paso 10)</text><circle class="bd" cx="60" cy="975.5" r="12"/><text x="60" y="979.7" class="b">4</text></g>
<g style="--k:var(--k-mem)" class="n"><rect x="60" y="1123.5" width="250" height="116" rx="11"/><text x="185" y="1149.5" class="t">Memoria limpia</text><text x="185" y="1170.5" class="s">sin firmas, correos ni teléfonos</text><text x="185" y="1187.5" class="s">contraseñas tapadas</text><text x="185" y="1204.5" class="s">lo antiguo se marca como ANTIGUO</text><text x="185" y="1221.5" class="s">(sirve para entender, no para copiar)</text></g>
<g style="--k:var(--k-sys)" class="n q"><rect x="460" y="975.5" width="260" height="60" rx="30"/><circle class="qm" cx="484" cy="1005.5" r="11"/><text x="484" y="1010.1" class="qt">?</text><text x="604" y="1001.2" class="s q">¿El cliente ha enviado</text><text x="604" y="1018.2" class="s q">fotos?</text><circle class="bd" cx="466" cy="979.5" r="12"/><text x="466" y="983.7" class="b">4</text></g>
<g style="--k:var(--k-sys)" class="n"><rect x="455" y="1067.5" width="270" height="82" rx="11"/><text x="590" y="1093.5" class="t">Lee el código de barras</text><text x="590" y="1114.5" class="s">en el propio servidor (la foto no sale)</text><text x="590" y="1131.5" class="s">para saber de qué etiqueta se trata</text></g>
<g style="--k:var(--k-api)" class="n q"><rect x="760" y="975.5" width="280" height="60" rx="30"/><circle class="qm" cx="784" cy="1005.5" r="11"/><text x="784" y="1010.1" class="qt">?</text><text x="914" y="1001.2" class="s q">¿Podemos consultar el panel</text><text x="914" y="1018.2" class="s q">de hoteles? (solo lectura)</text><circle class="bd" cx="766" cy="979.5" r="12"/><text x="766" y="983.7" class="b">4</text></g>
<g style="--k:var(--k-no)" class="n"><rect x="1115" y="977.5" width="200" height="82" rx="11"/><text x="1215" y="1003.5" class="t">Sin datos del hotel</text><text x="1215" y="1024.5" class="s">la IA se los pregunta</text><text x="1215" y="1041.5" class="s">al cliente</text></g>
<g style="--k:var(--k-api)" class="n"><rect x="760" y="1067.5" width="280" height="82" rx="11"/><text x="900" y="1093.5" class="t">Busca el hotel</text><text x="900" y="1114.5" class="s">por el correo del ticket, el nombre</text><text x="900" y="1131.5" class="s">del mensaje o la firma, o la etiqueta</text></g>
<g style="--k:var(--k-api)" class="n q"><rect x="760" y="1181.5" width="280" height="60" rx="30"/><circle class="qm" cx="784" cy="1211.5" r="11"/><text x="784" y="1216.1" class="qt">?</text><text x="914" y="1207.2" class="s q">¿Reconocemos el correo como</text><text x="914" y="1224.2" class="s q">usuario de la aplicación?</text></g>
<g style="--k:var(--k-no)" class="n"><rect x="1115" y="1165.5" width="200" height="99" rx="11"/><text x="1215" y="1191.5" class="t">Correo no reconocido</text><text x="1215" y="1212.5" class="s">no se da información de</text><text x="1215" y="1229.5" class="s">hoteles; si el cliente nombra</text><text x="1215" y="1246.5" class="s">el hotel: solo sus antenas</text></g>
<g style="--k:var(--k-api)" class="n q"><rect x="760" y="1273.5" width="280" height="60" rx="30"/><circle class="qm" cx="784" cy="1303.5" r="11"/><text x="784" y="1308.1" class="qt">?</text><text x="914" y="1299.2" class="s q">¿El hotel que dice el cliente</text><text x="914" y="1316.2" class="s q">es el de ese correo?</text></g>
<g style="--k:var(--k-no)" class="n"><rect x="1115" y="1267.5" width="200" height="82" rx="11"/><text x="1215" y="1293.5" class="t">No es el mismo</text><text x="1215" y="1314.5" class="s">se le explica con tacto</text><text x="1215" y="1331.5" class="s">y lo atiende una persona</text></g>
<g style="--k:var(--k-api)" class="n"><rect x="760" y="1365.5" width="280" height="99" rx="11"/><text x="900" y="1391.5" class="t">Datos del hotel para la IA</text><text x="900" y="1412.5" class="s">su rol y si el usuario está activo</text><text x="900" y="1429.5" class="s">antenas conectadas y desconectadas</text><text x="900" y="1446.5" class="s">estado de la etiqueta</text></g>
<g style="--k:var(--k-sys)" class="chip"><rect x="315" y="989.5" width="120" height="32" rx="16"/><text x="375" y="1009.5" >Sin fotos: sigue</text></g>
<g style="--k:var(--k-sys)" class="n q"><rect x="445" y="1614.5" width="290" height="60" rx="30"/><circle class="qm" cx="469" cy="1644.5" r="11"/><text x="469" y="1649.1" class="qt">?</text><text x="604" y="1640.2" class="s q">¿Es un caso tan claro que</text><text x="604" y="1657.2" class="s q">no hace falta pensarlo?</text></g>
<g style="--k:var(--k-der)" class="n"><rect x="60" y="1604.5" width="250" height="82" rx="11"/><text x="185" y="1630.5" class="t">Pasa a una persona</text><text x="185" y="1651.5" class="s">alérgenos, tótems y</text><text x="185" y="1668.5" class="s">garantías (salvo farmacias)</text></g>
<g style="--k:var(--k-cli)" class="n"><rect x="815" y="1604.5" width="290" height="82" rx="11"/><text x="960" y="1630.5" class="t">Pedir hotel y correo</text><text x="960" y="1651.5" class="s">primer mensaje con un problema de uso</text><text x="960" y="1668.5" class="s">y aún no sabemos quién escribe</text></g>
<g style="--k:var(--k-sys)" class="n"><rect x="370" y="1746.5" width="440" height="99" rx="11"/><text x="590" y="1772.5" class="t">Prepara las instrucciones para la IA</text><text x="590" y="1793.5" class="s">las reglas del equipo + el orden de decisión</text><text x="590" y="1810.5" class="s">+ lo que sabemos del cliente y del hotel</text><text x="590" y="1827.5" class="s">+ «decisión ya tomada» si el caso era claro</text><circle class="bd" cx="370" cy="1746.5" r="12"/><text x="370" y="1750.7" class="b">5</text></g>
<g style="--k:var(--k-ai)" class="n ia"><rect x="390" y="1889.5" width="400" height="99" rx="11"/><text x="590" y="1915.5" class="t">IA · Claude Haiku 5.5</text><text x="590" y="1936.5" class="s">lee la conversación y las instrucciones</text><text x="590" y="1953.5" class="s">sigue el orden de decisión (7 puntos)</text><text x="590" y="1970.5" class="s">y escribe una propuesta de respuesta</text><circle class="bd" cx="390" cy="1889.5" r="12"/><text x="390" y="1893.7" class="b">6</text></g>
<g style="--k:var(--k-sys)" class="n q"><rect x="425" y="2108.5" width="330" height="82" rx="41"/><circle class="qm" cx="449" cy="2149.5" r="11"/><text x="449" y="2154.1" class="qt">?</text><text x="604" y="2136.7" class="s q">¿Es algo que siempre atiende</text><text x="604" y="2153.7" class="s q">una persona? (alérgenos, tótems,</text><text x="604" y="2170.7" class="s q">formaciones, fallos del contenido)</text><circle class="bd" cx="431" cy="2112.5" r="12"/><text x="431" y="2116.7" class="b">1</text></g>
<g style="--k:var(--k-der)" class="n"><rect x="875" y="2108.5" width="370" height="82" rx="11"/><text x="1060" y="2134.5" class="t">Pasa a una persona</text><text x="1060" y="2155.5" class="s">sin hacer preguntas al cliente</text><text x="1060" y="2172.5" class="s">nota «Para el equipo» → Soporte</text></g>
<g style="--k:var(--k-sys)" class="n q"><rect x="425" y="2224.5" width="330" height="167" rx="83.5"/><circle class="qm" cx="449" cy="2308" r="11"/><text x="449" y="2312.6" class="qt">?</text><text x="604" y="2295.2" class="s q">¿Es de material o de dinero?</text><text x="604" y="2312.2" class="s q">(pedido, presupuesto,</text><text x="604" y="2329.2" class="s q">garantía o cambio de precio)</text><circle class="bd" cx="431" cy="2228.5" r="12"/><text x="431" y="2232.7" class="b">2</text></g>
<g style="--k:var(--k-der)" class="n"><rect x="875" y="2224.5" width="370" height="167" rx="11"/><text x="1060" y="2250.5" class="t">Según el caso</text><text x="1060" y="2271.5" class="s">material (pilas, soportes…) → escribir a postventa</text><text x="1060" y="2288.5" class="s">   con material, cantidad y foto</text><text x="1060" y="2305.5" class="s">presupuesto → «Asignar al departamento de</text><text x="1060" y="2322.5" class="s">   Pedidos y facturas.»</text><text x="1060" y="2339.5" class="s">garantía → la lleva el equipo de Garantías</text><text x="1060" y="2356.5" class="s">garantía de farmacia → «la gestiona Expofarm»</text><text x="1060" y="2373.5" class="s">cambio de precio en tienda → lo hace soporte</text></g>
<g style="--k:var(--k-sys)" class="n q"><rect x="425" y="2425.5" width="330" height="82" rx="41"/><circle class="qm" cx="449" cy="2466.5" r="11"/><text x="449" y="2471.1" class="qt">?</text><text x="604" y="2453.7" class="s q">¿Lo tiene que hacer el equipo?</text><text x="604" y="2470.7" class="s q">(menú de un buffet, logos,</text><text x="604" y="2487.7" class="s q">plantillas, altas de etiquetas)</text><circle class="bd" cx="431" cy="2429.5" r="12"/><text x="431" y="2433.7" class="b">3</text></g>
<g style="--k:var(--k-der)" class="n"><rect x="875" y="2425.5" width="370" height="82" rx="11"/><text x="1060" y="2451.5" class="t">Pasa a una persona</text><text x="1060" y="2472.5" class="s">si ya sabemos el hotel, no se piden</text><text x="1060" y="2489.5" class="s">más datos para pasarlo</text></g>
<g style="--k:var(--k-sys)" class="n q"><rect x="425" y="2541.5" width="330" height="99" rx="49.5"/><circle class="qm" cx="449" cy="2591" r="11"/><text x="449" y="2595.6" class="qt">?</text><text x="604" y="2578.2" class="s q">¿Lo puede hacer el propio hotel?</text><text x="604" y="2595.2" class="s q">(usuarios, permisos, posiciones,</text><text x="604" y="2612.2" class="s q">cambiar un plato, forzar el menú)</text><circle class="bd" cx="431" cy="2545.5" r="12"/><text x="431" y="2549.7" class="b">4</text></g>
<g style="--k:var(--k-ag)" class="n"><rect x="875" y="2541.5" width="370" height="99" rx="11"/><text x="1060" y="2567.5" class="t">Se le explica cómo</text><text x="1060" y="2588.5" class="s">con los pasos de la aplicación</text><text x="1060" y="2605.5" class="s">(Ajustes › Roles, Usuarios, Posiciones…)</text><text x="1060" y="2622.5" class="s">si nadie del hotel puede → una persona</text></g>
<g style="--k:var(--k-sys)" class="n q"><rect x="425" y="2674.5" width="330" height="82" rx="41"/><circle class="qm" cx="449" cy="2715.5" r="11"/><text x="449" y="2720.1" class="qt">?</text><text x="604" y="2711.2" class="s q">¿Es un problema de uso y aún</text><text x="604" y="2728.2" class="s q">no sabemos quién escribe?</text><circle class="bd" cx="431" cy="2678.5" r="12"/><text x="431" y="2682.7" class="b">5</text></g>
<g style="--k:var(--k-cli)" class="n"><rect x="875" y="2674.5" width="370" height="82" rx="11"/><text x="1060" y="2700.5" class="t">Se pide hotel y correo</text><text x="1060" y="2721.5" class="s">como mucho esas dos preguntas</text><text x="1060" y="2738.5" class="s">lo demás se pregunta después</text></g>
<g style="--k:var(--k-sys)" class="n q"><rect x="425" y="2790.5" width="330" height="82" rx="41"/><circle class="qm" cx="449" cy="2831.5" r="11"/><text x="449" y="2836.1" class="qt">?</text><text x="604" y="2827.2" class="s q">¿Hay antenas sin conexión?</text><text x="604" y="2844.2" class="s q">(dato del panel de hoteles)</text><circle class="bd" cx="431" cy="2794.5" r="12"/><text x="431" y="2798.7" class="b">6</text></g>
<g style="--k:var(--k-ag)" class="n"><rect x="875" y="2790.5" width="370" height="82" rx="11"/><text x="1060" y="2816.5" class="t">Se avisa a su personal técnico</text><text x="1060" y="2837.5" class="s">que revise sus antenas</text><text x="1060" y="2854.5" class="s">si ya se le dijo y sigue igual → una persona</text></g>
<g style="--k:var(--k-sys)" class="n q"><rect x="425" y="2906.5" width="330" height="82" rx="41"/><circle class="qm" cx="449" cy="2947.5" r="11"/><text x="449" y="2952.1" class="qt">?</text><text x="604" y="2943.2" class="s q">¿Lo que sabemos trae la</text><text x="604" y="2960.2" class="s q">respuesta o los pasos?</text><circle class="bd" cx="431" cy="2910.5" r="12"/><text x="431" y="2914.7" class="b">7</text></g>
<g style="--k:var(--k-ag)" class="n"><rect x="875" y="2906.5" width="370" height="82" rx="11"/><text x="1060" y="2932.5" class="t">La IA responde</text><text x="1060" y="2953.5" class="s">solo con lo que consta en la memoria</text><text x="1060" y="2970.5" class="s">nunca inventa pasos, datos ni plazos</text></g>
<g style="--k:var(--k-no)" class="n"><rect x="420" y="3022.5" width="340" height="82" rx="11"/><text x="590" y="3048.5" class="t">Si no encaja o hay duda</text><text x="590" y="3069.5" class="s">pasa a una persona (o «no sé»)</text><text x="590" y="3086.5" class="s">mejor derivar que responder con dudas</text></g>
<g style="--k:var(--k-ai)" class="n q"><rect x="445" y="3246.5" width="290" height="82" rx="41"/><circle class="qm" cx="469" cy="3287.5" r="11"/><text x="469" y="3292.1" class="qt">?</text><text x="604" y="3283.2" class="s q">¿La IA ha podido contestar</text><text x="604" y="3300.2" class="s q">sin fallos?</text><circle class="bd" cx="451" cy="3250.5" r="12"/><text x="451" y="3254.7" class="b">7</text></g>
<g style="--k:var(--k-no)" class="n"><rect x="830" y="3247.5" width="340" height="82" rx="11"/><text x="1000" y="3273.5" class="t">No ha podido responder</text><text x="1000" y="3294.5" class="s">aparece el botón «Reintentar»</text><text x="1000" y="3311.5" class="s">si ya costó algo, se cuenta</text></g>
<g style="--k:var(--k-ai)" class="n q"><rect x="445" y="3360.5" width="290" height="82" rx="41"/><circle class="qm" cx="469" cy="3401.5" r="11"/><text x="469" y="3406.1" class="qt">?</text><text x="604" y="3397.2" class="s q">¿La IA se atreve a contestar?</text><text x="604" y="3414.2" class="s q">(si no, dice «no sé»)</text></g>
<g style="--k:var(--k-no)" class="n"><rect x="830" y="3361.5" width="340" height="82" rx="11"/><text x="1000" y="3387.5" class="t">La IA no sabe</text><text x="1000" y="3408.5" class="s">no hay propuesta: lo lleva una persona</text><text x="1000" y="3425.5" class="s">queda apuntado en Ajustes para documentarlo</text></g>
<g style="--k:var(--k-ai)" class="n q"><rect x="445" y="3474.5" width="290" height="112" rx="56"/><circle class="qm" cx="469" cy="3530.5" r="11"/><text x="469" y="3535.1" class="qt">?</text><text x="604" y="3526.2" class="s q">¿La IA propone pasarlo</text><text x="604" y="3543.2" class="s q">a una persona?</text></g>
<g style="--k:var(--k-der)" class="n"><rect x="830" y="3474.5" width="340" height="116" rx="11"/><text x="1000" y="3500.5" class="t">Pasa a una persona</text><text x="1000" y="3521.5" class="s">aviso «Para el equipo»:</text><text x="1000" y="3538.5" class="s">«Llévalo a Soporte, Garantías o Pedidos»</text><text x="1000" y="3555.5" class="s">y un mensaje corto para el cliente</text><text x="1000" y="3572.5" class="s">lo ve el agente; el cliente no</text></g>
<g style="--k:var(--k-ag)" class="n"><rect x="410" y="3618.5" width="360" height="82" rx="11"/><text x="590" y="3644.5" class="t">Propuesta de respuesta</text><text x="590" y="3665.5" class="s">contesta, pide datos o indica dónde escribir</text><text x="590" y="3682.5" class="s">se muestra en el panel: no se envía sola</text></g>
<g style="--k:var(--k-sys)" class="n"><rect x="370" y="3740.5" width="440" height="99" rx="11"/><text x="590" y="3766.5" class="t">Se guarda la sugerencia</text><text x="590" y="3787.5" class="s">y lo que ha costado: se suma al gasto del mes</text><text x="590" y="3804.5" class="s">aviso al llegar al 80 % y al 100 % de 50 USD</text><text x="590" y="3821.5" class="s">el panel de Ajustes muestra el gasto por día</text><circle class="bd" cx="370" cy="3740.5" r="12"/><text x="370" y="3744.7" class="b">8</text></g>
<g style="--k:var(--k-ag)" class="n"><rect x="390" y="3953.5" width="400" height="82" rx="11"/><text x="590" y="3979.5" class="t">El agente ve la sugerencia</text><text x="590" y="4000.5" class="s">decide él: la IA nunca envía nada</text><text x="590" y="4017.5" class="s">aquí también llegan los casos de la sección A</text><circle class="bd" cx="390" cy="3953.5" r="12"/><text x="390" y="3957.7" class="b">9</text></g>
<g style="--k:var(--k-sys)" class="n"><rect x="55" y="4089.5" width="260" height="82" rx="11"/><text x="185" y="4115.5" class="t">Contesta sin la IA</text><text x="185" y="4136.5" class="s">si no hay propuesta o no le sirve</text><text x="185" y="4153.5" class="s">el cliente recibe su respuesta</text></g>
<g style="--k:var(--k-ag)" class="n"><rect x="355" y="4089.5" width="260" height="82" rx="11"/><g class="ic" transform="translate(412.2,4099.5) scale(0.72)"><rect x="2" y="4" width="20" height="16" rx="2"/><path d="m22 7-8.97 5.7a1.94 1.94 0 0 1-2.06 0L2 7"/></g><text x="497" y="4115.5" class="t">Copia y envía él</text><text x="485" y="4136.5" class="s">puede retocarla antes</text><text x="485" y="4153.5" class="s">se apunta como usada</text></g>
<g style="--k:var(--k-ag)" class="n"><rect x="655" y="4089.5" width="260" height="82" rx="11"/><g class="ic" transform="translate(757.8,4099.5) scale(0.72)"><path d="M7 10v12"/><path d="M15 5.88 14 10h5.83a2 2 0 0 1 1.92 2.56l-2.33 8A2 2 0 0 1 17.5 22H4a2 2 0 0 1-2-2v-8a2 2 0 0 1 2-2h2.76a2 2 0 0 0 1.79-1.11L12 2a3.13 3.13 0 0 1 3 3.88Z"/></g><text x="797" y="4115.5" class="t">Útil</text><text x="785" y="4136.5" class="s">se apunta para medir cuánto</text><text x="785" y="4153.5" class="s">acierta la IA</text></g>
<g style="--k:var(--k-no)" class="n"><rect x="955" y="4089.5" width="260" height="82" rx="11"/><g class="ic" transform="translate(1042.6,4099.5) scale(0.72)"><g transform="translate(0,24) scale(1,-1)"><path d="M7 10v12"/><path d="M15 5.88 14 10h5.83a2 2 0 0 1 1.92 2.56l-2.33 8A2 2 0 0 1 17.5 22H4a2 2 0 0 1-2-2v-8a2 2 0 0 1 2-2h2.76a2 2 0 0 0 1.79-1.11L12 2a3.13 3.13 0 0 1 3 3.88Z"/></g></g><text x="1097" y="4115.5" class="t">No sirve</text><text x="1085" y="4136.5" class="s">escribe qué falló y cómo</text><text x="1085" y="4153.5" class="s">había que responder</text></g>
<g style="--k:var(--k-cli)" class="chip"><rect x="160" y="4201.5" width="350" height="32" rx="16"/><g class="ic" transform="translate(172,4209.5) scale(0.66)"><path d="M17 2l4 4-4 4"/><path d="M3 11V9a3 3 0 0 1 3-3h15"/><path d="M7 22l-4-4 4-4"/><path d="M21 13v2a3 3 0 0 1-3 3H3"/></g><text x="345" y="4221.5" >El cliente contesta: se vuelve al paso 1</text></g>
<g style="--k:var(--k-mem)" class="n q"><rect x="950" y="4205.5" width="270" height="60" rx="30"/><circle class="qm" cx="974" cy="4235.5" r="11"/><text x="974" y="4240.1" class="qt">?</text><text x="1099" y="4231.2" class="s q">¿Un responsable da por</text><text x="1099" y="4248.2" class="s q">buena la corrección?</text><circle class="bd" cx="956" cy="4209.5" r="13"/><text x="956" y="4213.7" class="b">10</text></g>
<g style="--k:var(--k-no)" class="n"><rect x="660" y="4205.5" width="250" height="65" rx="11"/><text x="785" y="4231.5" class="t">Se descarta</text><text x="785" y="4252.5" class="s">la IA no la verá nunca</text></g>
<g style="--k:var(--k-mem)" class="n"><rect x="955" y="4299.5" width="260" height="82" rx="11"/><text x="1085" y="4325.5" class="t">Pasa a la memoria</text><text x="1085" y="4346.5" class="s">como corrección validada</text><text x="1085" y="4363.5" class="s">la IA la usará en las próximas consultas</text></g>
<g style="--k:var(--k-mem)" class="chip"><rect x="955" y="4409.5" width="260" height="32" rx="16"/><g class="ic" transform="translate(967,4417.5) scale(0.66)"><path d="M17 2l4 4-4 4"/><path d="M3 11V9a3 3 0 0 1 3-3h15"/><path d="M7 22l-4-4 4-4"/><path d="M21 13v2a3 3 0 0 1-3 3H3"/></g><text x="1095" y="4429.5" >Vuelve a la memoria (paso 4)</text></g>
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
<g class="pill no"><rect x="1046" y="1192.5" width="28" height="18" rx="9"/><text x="1060" y="1205">No</text></g>
<g class="pill si"><rect x="908" y="1251.5" width="28" height="18" rx="9"/><text x="922" y="1264">Sí</text></g>
<g class="pill no"><rect x="1046" y="1284.5" width="28" height="18" rx="9"/><text x="1060" y="1297">No</text></g>
<g class="pill si"><rect x="908" y="1343.5" width="28" height="18" rx="9"/><text x="922" y="1356">Sí</text></g>
<g class="pill no"><rect x="431" y="971.5" width="28" height="18" rx="9"/><text x="445" y="984">No</text></g>
<g class="pill si"><rect x="411" y="1625.5" width="28" height="18" rx="9"/><text x="425" y="1638">Sí</text></g>
<g class="pill si"><rect x="741" y="1625.5" width="28" height="18" rx="9"/><text x="755" y="1638">Sí</text></g>
<text x="602" y="1690.5" class="et" text-anchor="start">No: decide la IA</text>
<text x="602" y="1871.5" class="et" text-anchor="start">una sola consulta (tiene un pequeño coste)</text>
<g class="pill si"><rect x="761" y="2130.5" width="28" height="18" rx="9"/><text x="775" y="2143">Sí</text></g>
<g class="pill no"><rect x="598" y="2205.5" width="28" height="18" rx="9"/><text x="612" y="2218">No</text></g>
<g class="pill si"><rect x="761" y="2289" width="28" height="18" rx="9"/><text x="775" y="2301.5">Sí</text></g>
<g class="pill no"><rect x="598" y="2406.5" width="28" height="18" rx="9"/><text x="612" y="2419">No</text></g>
<g class="pill si"><rect x="761" y="2447.5" width="28" height="18" rx="9"/><text x="775" y="2460">Sí</text></g>
<g class="pill no"><rect x="598" y="2522.5" width="28" height="18" rx="9"/><text x="612" y="2535">No</text></g>
<g class="pill si"><rect x="761" y="2572" width="28" height="18" rx="9"/><text x="775" y="2584.5">Sí</text></g>
<g class="pill no"><rect x="598" y="2655.5" width="28" height="18" rx="9"/><text x="612" y="2668">No</text></g>
<g class="pill si"><rect x="761" y="2696.5" width="28" height="18" rx="9"/><text x="775" y="2709">Sí</text></g>
<g class="pill no"><rect x="598" y="2771.5" width="28" height="18" rx="9"/><text x="612" y="2784">No</text></g>
<g class="pill si"><rect x="761" y="2812.5" width="28" height="18" rx="9"/><text x="775" y="2825">Sí</text></g>
<g class="pill no"><rect x="598" y="2887.5" width="28" height="18" rx="9"/><text x="612" y="2900">No</text></g>
<g class="pill si"><rect x="761" y="2928.5" width="28" height="18" rx="9"/><text x="775" y="2941">Sí</text></g>
<g class="pill no"><rect x="598" y="2996.5" width="28" height="18" rx="9"/><text x="612" y="3009">No</text></g>
<g class="pill no"><rect x="741" y="3268.5" width="28" height="18" rx="9"/><text x="755" y="3281">No</text></g>
<g class="pill si"><rect x="598" y="3338.5" width="28" height="18" rx="9"/><text x="612" y="3351">Sí</text></g>
<g class="pill no"><rect x="741" y="3382.5" width="28" height="18" rx="9"/><text x="755" y="3395">No</text></g>
<g class="pill si"><rect x="598" y="3452.5" width="28" height="18" rx="9"/><text x="612" y="3465">Sí</text></g>
<g class="pill si"><rect x="741" y="3511.5" width="28" height="18" rx="9"/><text x="755" y="3524">Sí</text></g>
<g class="pill no"><rect x="598" y="3596.5" width="28" height="18" rx="9"/><text x="612" y="3609">No</text></g>
<g class="pill no"><rect x="916" y="4245.5" width="28" height="18" rx="9"/><text x="930" y="4258">No</text></g>
<g class="pill si"><rect x="1093" y="4275.5" width="28" height="18" rx="9"/><text x="1107" y="4288">Sí</text></g>
</svg><span class="ancla" id="cap-a" style="top:0.357%"></span><span class="ancla" id="cap-b" style="top:16.903%"></span><span class="ancla" id="cap-c" style="top:34.285%"></span><span class="ancla" id="cap-d" style="top:45.308%"></span><span class="ancla" id="cap-e" style="top:70.702%"></span><span class="ancla" id="cap-f" style="top:86.478%"></span><!--/LIENZO--></div>

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
          <div><dt>Correcciones validadas</dt><dd>Lo que los agentes han enseñado con «No sirve». Solo entran las que un responsable valida, más unas pocas de partida que el equipo dejó preparadas.</dd></div>
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
        <div><dt>Siempre se deriva</dt><dd>Alérgenos, garantías (sin dar correo: las lleva el responsable en la aplicación), estado de envíos, tótems, dominios, y lo que ejecuta el equipo (rueda de menú de un buffet, logos, plantillas, dar de alta etiquetas nuevas en la plataforma).</dd></div>
        <div><dt>Lo gestiona el hotel</dt><dd>La IA explica cómo: altas de usuarios, permisos y roles (Ajustes › Roles, Usuarios), posiciones, nombres de etiquetas y forzar el menú. Solo se deriva si nadie en el hotel puede.</dd></div>
        <div><dt>Farmacias y precios</dt><dd>Garantía de una farmacia: «lo gestiona Expofarm», sin correo. Cambio de precio en tienda: lo hace una persona de soporte.</dd></div>
        <div><dt>Presupuestos</dt><dd>El borrador es «Asignar al departamento de Pedidos y facturas».</dd></div>
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
      <div><h3><i>F</i>Agente y aprendizaje</h3><p class="res">La persona decide; lo validado mejora las siguientes sugerencias.</p></div>
      <dl>
        <div><dt>Panel lateral (solo para mirar)</dt><dd>Enseña cada paso al momento (hotel, correo, rol, antenas, memoria) y deja el borrador. Solo se puede <b>copiar</b>: no se escribe en el editor y la IA nunca envía.</dd></div>
        <div><dt>Útil</dt><dd>Queda anotado para medir el acierto.</dd></div>
        <div><dt>No sirve</dt><dd>El agente escribe qué estaba mal y cómo había que responder. Se guarda como pendiente (o validada, si la hace un responsable).</dd></div>
        <div><dt>Otra sugerencia</dt><dd>Vuelve a pedir a la IA, con la indicación del agente.</dd></div>
        <div><dt>El responsable valida</dt><dd>En Ajustes › Asistente IA. Una corrección no llega a la IA hasta que se valida; las rechazadas tampoco. Las validadas entran en la memoria.</dd></div>
      </dl>
    </article>
  </section>

  <footer>Fase 2 (respuesta automática fuera de horario) todavía no está hecha: usaría este mismo recorrido, con un aviso fijo de que habla una IA y salida a una persona.</footer>
</div>
<script src="/ia-flujo.js" defer></script>
</body>
</html>
