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
  .n.q polygon { fill: var(--surface); stroke: color-mix(in srgb, var(--k) 70%, var(--surface)); stroke-width: 1.8; }
  .n.ia rect { fill: color-mix(in srgb, var(--k) 15%, var(--surface)); stroke: var(--k); stroke-width: 2.6; filter: drop-shadow(0 4px 10px color-mix(in srgb, var(--k) 30%, transparent)); }
  .n text { text-anchor: middle; }
  .n .t { fill: var(--ink); font: 900 15.5px 'Lato', sans-serif; }
  .n .s { fill: var(--ink2); font: 400 13.5px 'Lato', sans-serif; }
  .n .s.q { fill: var(--ink); font-weight: 700; font-size: 14px; }
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

  <div class="lienzo"><!--LIENZO--><svg viewBox="0 0 1520 5358" role="img" aria-label="Flujo completo de la IA de soporte, de que el cliente escribe a que el responsable valida la corrección">
<defs><marker id="p" viewBox="0 0 10 10" refX="8.5" refY="5" markerWidth="6.5" markerHeight="6.5" orient="auto-start-reverse"><path d="M0,0.5 L10,5 L0,9.5 z" class="punta"/></marker></defs>
<g class="banda"><rect x="44" y="20" width="1446" height="920" rx="20"/><circle cx="82" cy="58" r="16"/><text x="82" y="64" class="bl">A</text><text x="110" y="64" class="bt">Puertas de entrada</text><text x="316.79999999999995" y="64" class="bs">Antes de gastar nada, se comprueba si toca consultar a la IA</text></g>
<g class="banda"><rect x="44" y="962" width="1446" height="968" rx="20"/><circle cx="82" cy="1000" r="16"/><text x="82" y="1006" class="bl">B</text><text x="110" y="1006" class="bt">Contexto</text><text x="210.8" y="1006" class="bs">Se reúne lo que la IA necesita saber: lo que ya sabemos, las fotos y los datos del hotel</text></g>
<g class="banda"><rect x="44" y="1952" width="1446" height="558" rx="20"/><circle cx="82" cy="1990" r="16"/><text x="82" y="1996" class="bl">C</text><text x="110" y="1996" class="bt">Decisión en código</text><text x="316.79999999999995" y="1996" class="bs">Lo evidente se decide sin la IA; después se le preparan las instrucciones</text></g>
<g class="banda"><rect x="44" y="2532" width="1446" height="1304" rx="20"/><circle cx="82" cy="2570" r="16"/><text x="82" y="2576" class="bl">D</text><text x="110" y="2576" class="bt">Orden de decisión</text><text x="306.2" y="2576" class="bs">La IA repasa estos puntos en orden: el primero que encaja manda</text></g>
<g class="banda"><rect x="44" y="3858" width="1446" height="770" rx="20"/><circle cx="82" cy="3896" r="16"/><text x="82" y="3902" class="bl">E</text><text x="110" y="3902" class="bt">Salida</text><text x="189.6" y="3902" class="bs">Qué pasa con la respuesta de la IA, y cuánto cuesta</text></g>
<g class="banda"><rect x="44" y="4650" width="1446" height="688" rx="20"/><circle cx="82" cy="4688" r="16"/><text x="82" y="4694" class="bl">F</text><text x="110" y="4694" class="bt">Agente y aprendizaje</text><text x="338" y="4694" class="bs">La persona decide; lo que se valida hace que la IA acierte más</text></g>
<g class="fl" marker-end="url(#p)"><path d="M640,208 L640,252"/><path d="M640,340 L640,384"/><path d="M830,432 L925,432"/><path d="M640,480 L640,524"/><path d="M830,572 L925,572"/><path d="M640,620 L640,664"/><path d="M830,712 L925,712"/><path d="M640,760 L640,804"/><path d="M830,852 L925,852"/><path d="M640,900 L640,1062"/><path d="M215,1342 L215,1382"/><path d="M640,1314 L640,1354"/><path d="M1175,1266 L1225,1271"/><path d="M1000,1314 L1000,1354"/><path d="M1000,1442 L1000,1482"/><path d="M1175,1530 L1225,1531"/><path d="M1000,1578 L1000,1618"/><path d="M1175,1666 L1225,1668"/><path d="M1000,1714 L1000,1754"/><path marker-end="none" d="M640,1150 L640,1186"/><path marker-end="none" d="M215,1186 L1000,1186"/><path d="M215,1186 L215,1218"/><path d="M640,1186 L640,1218"/><path d="M1000,1186 L1000,1218"/><path marker-end="none" d="M470,1266 L410,1266 L410,1464 L640,1464"/><path marker-end="none" d="M215,1506 L215,1904"/><path marker-end="none" d="M640,1442 L640,1904"/><path marker-end="none" d="M1000,1860 L1000,1904"/><path marker-end="none" d="M215,1904 L1000,1904"/><path d="M640,1904 L640,2052"/><path d="M450,2100 L360,2092"/><path d="M830,2100 L890,2092"/><path marker-end="none" d="M215,2136 L215,2178 L1060,2178"/><path marker-end="none" d="M1060,2136 L1060,2178"/><path d="M640,2148 L640,2218"/><path d="M640,2324 L640,2374"/><path d="M640,2480 L640,2632"/><path d="M860,2689 L920,2689"/><path d="M640,2746 L640,2786"/><path d="M860,2843 L920,2843"/><path d="M640,2900 L640,2972"/><path d="M860,3029 L920,3029"/><path d="M640,3086 L640,3126"/><path d="M860,3183 L920,3183"/><path d="M640,3240 L640,3280"/><path d="M860,3328 L920,3328"/><path d="M640,3376 L640,3416"/><path d="M860,3464 L920,3464"/><path d="M640,3512 L640,3552"/><path d="M860,3600 L920,3600"/><path d="M640,3648 L640,3688"/><path marker-end="none" d="M1350,2689 L1485,2689"/><path marker-end="none" d="M1350,2843 L1485,2843"/><path marker-end="none" d="M1350,3029 L1485,3029"/><path marker-end="none" d="M1350,3183 L1485,3183"/><path marker-end="none" d="M1350,3328 L1485,3328"/><path marker-end="none" d="M1350,3464 L1485,3464"/><path marker-end="none" d="M1350,3600 L1485,3600"/><path marker-end="none" d="M1485,2689 L1485,3816"/><path marker-end="none" d="M640,3776 L640,3816"/><path marker-end="none" d="M1485,3816 L640,3816"/><path d="M640,3816 L640,3958"/><path d="M840,4006 L915,4002"/><path d="M640,4054 L640,4094"/><path d="M840,4142 L915,4138"/><path d="M640,4190 L640,4230"/><path d="M840,4278 L915,4276"/><path d="M640,4326 L640,4366"/><path d="M640,4454 L640,4500"/><path marker-end="none" d="M1325,4002 L1485,4002"/><path marker-end="none" d="M1325,4138 L1485,4138"/><path marker-end="none" d="M1325,4276 L1485,4276"/><path marker-end="none" d="M1485,4002 L1485,4476"/><path marker-end="none" d="M1485,4476 L640,4476"/><path d="M640,4606 L640,4750"/><path marker-end="none" d="M640,4838 L640,4872"/><path marker-end="none" d="M215,4872 L1235,4872"/><path d="M215,4872 L215,4904"/><path d="M555,4872 L555,4904"/><path d="M895,4872 L895,4904"/><path d="M1235,4872 L1235,4904"/><path d="M1235,4992 L1235,5036"/><path d="M1065,5084 L1045,5085"/><path d="M1235,5132 L1235,5174"/><path class="vuelta" style="--k:var(--k-cli)" d="M555,4992 L555,5308 L14,5308 L14,164 L440,164"/><path class="vuelta" style="--k:var(--k-mem)" d="M1085,5218 L38,5218 L38,1280 L70,1280"/></g>
<g style="--k:var(--k-cli)" class="n"><rect x="440" y="120" width="400" height="88" rx="12"/><text x="640" y="148" class="t">Cliente escribe</text><text x="640" y="170" class="s">por correo, WhatsApp, formulario o portal</text><text x="640" y="188" class="s">el mensaje llega al ticket</text><circle class="bd" cx="440" cy="120" r="13"/><text x="440" y="124.5" class="b">1</text></g>
<g style="--k:var(--k-ag)" class="n"><rect x="440" y="252" width="400" height="88" rx="12"/><text x="640" y="280" class="t">El agente pide la sugerencia</text><text x="640" y="302" class="s">abre el ticket y pulsa «Sugerir»</text><text x="640" y="320" class="s">la IA no actúa por su cuenta</text><circle class="bd" cx="440" cy="252" r="13"/><text x="440" y="256.5" class="b">2</text></g>
<g style="--k:var(--k-sys)" class="n q"><polygon points="640,384 830,432 640,480 450,432"/><text x="640" y="427.5" class="s q">¿La IA está activada</text><text x="640" y="445.5" class="s q">en Ajustes?</text></g>
<g style="--k:var(--k-no)" class="n"><rect x="925" y="397" width="390" height="70" rx="12"/><text x="1120" y="425" class="t">No aparece el botón</text><text x="1120" y="447" class="s">no sale ningún dato hacia fuera</text></g>
<g style="--k:var(--k-sys)" class="n q"><polygon points="640,524 830,572 640,620 450,572"/><text x="640" y="567.5" class="s q">¿Ya se sugirió algo para</text><text x="640" y="585.5" class="s q">este mensaje del cliente?</text></g>
<g style="--k:var(--k-mem)" class="n"><rect x="925" y="519" width="390" height="106" rx="12"/><text x="1120" y="547" class="t">Se enseña la que ya estaba</text><text x="1120" y="569" class="s">no se vuelve a consultar a la IA</text><text x="1120" y="587" class="s">«Regenerar» pide una nueva</text><text x="1120" y="605" class="s">→ sigue en el paso 9</text></g>
<g style="--k:var(--k-sys)" class="n q"><polygon points="640,664 830,712 640,760 450,712"/><text x="640" y="707.5" class="s q">¿Se ha gastado ya lo previsto</text><text x="640" y="725.5" class="s q">para este mes? (50 USD)</text></g>
<g style="--k:var(--k-no)" class="n"><rect x="925" y="659" width="390" height="106" rx="12"/><text x="1120" y="687" class="t">Se avisa al agente</text><text x="1120" y="709" class="s">«Contesta tú; la IA vuelve el día 1»</text><text x="1120" y="727" class="s">no se consulta a la IA</text><text x="1120" y="745" class="s">→ lo contesta el agente</text></g>
<g style="--k:var(--k-sys)" class="n q"><polygon points="640,804 830,852 640,900 450,852"/><text x="640" y="847.5" class="s q">¿El cliente solo ha mandado</text><text x="640" y="865.5" class="s q">audios de WhatsApp?</text></g>
<g style="--k:var(--k-sys)" class="n"><rect x="925" y="799" width="390" height="106" rx="12"/><text x="1120" y="827" class="t">Respuesta fija, sin IA</text><text x="1120" y="849" class="s">«Ahora mismo no podemos escuchar audios…»</text><text x="1120" y="867" class="s">no cuesta nada</text><text x="1120" y="885" class="s">→ sigue en el paso 9</text></g>
<g style="--k:var(--k-sys)" class="n"><rect x="420" y="1062" width="440" height="88" rx="12"/><text x="640" y="1090" class="t">Prepara la conversación</text><text x="640" y="1112" class="s">lee lo que ha escrito el cliente y lo que se le contestó</text><text x="640" y="1130" class="s">(sin notas internas) y busca su última pregunta</text><circle class="bd" cx="420" cy="1062" r="13"/><text x="420" y="1066.5" class="b">3</text></g>
<g style="--k:var(--k-mem)" class="n"><rect x="70" y="1218" width="290" height="124" rx="12"/><text x="215" y="1246" class="t">Memoria y guía</text><text x="215" y="1268" class="s">preguntas frecuentes</text><text x="215" y="1286" class="s">respuestas de ejemplo del equipo</text><text x="215" y="1304" class="s">casos parecidos y manuales</text><text x="215" y="1322" class="s">correcciones ya validadas</text><circle class="bd" cx="70" cy="1218" r="13"/><text x="70" y="1222.5" class="b">4</text></g>
<g style="--k:var(--k-mem)" class="n"><rect x="70" y="1382" width="290" height="124" rx="12"/><text x="215" y="1410" class="t">Memoria limpia</text><text x="215" y="1432" class="s">sin firmas, correos ni teléfonos</text><text x="215" y="1450" class="s">contraseñas tapadas</text><text x="215" y="1468" class="s">lo antiguo se marca como ANTIGUO</text><text x="215" y="1486" class="s">(sirve para entender, no para copiar)</text></g>
<g style="--k:var(--k-sys)" class="n q"><polygon points="640,1218 810,1266 640,1314 470,1266"/><text x="640" y="1261.5" class="s q">¿El cliente ha enviado</text><text x="640" y="1279.5" class="s q">fotos?</text><circle class="bd" cx="496" cy="1242" r="13"/><text x="496" y="1246.5" class="b">4</text></g>
<g style="--k:var(--k-sys)" class="n"><rect x="480" y="1354" width="320" height="88" rx="12"/><text x="640" y="1382" class="t">Lee el código de barras</text><text x="640" y="1404" class="s">en el propio servidor (la foto no sale)</text><text x="640" y="1422" class="s">para saber de qué etiqueta se trata</text></g>
<g style="--k:var(--k-api)" class="n q"><polygon points="1000,1218 1175,1266 1000,1314 825,1266"/><text x="1000" y="1261.5" class="s q">¿Podemos consultar el panel</text><text x="1000" y="1279.5" class="s q">de hoteles? (solo lectura)</text><circle class="bd" cx="851" cy="1242" r="13"/><text x="851" y="1246.5" class="b">4</text></g>
<g style="--k:var(--k-no)" class="n"><rect x="1225" y="1236" width="230" height="70" rx="12"/><text x="1340" y="1264" class="t">Sin datos del hotel</text><text x="1340" y="1286" class="s">la IA se los pregunta al cliente</text></g>
<g style="--k:var(--k-api)" class="n"><rect x="825" y="1354" width="350" height="88" rx="12"/><text x="1000" y="1382" class="t">Busca el hotel</text><text x="1000" y="1404" class="s">por el correo del ticket, el nombre que</text><text x="1000" y="1422" class="s">sale en el mensaje o la firma, o la etiqueta</text></g>
<g style="--k:var(--k-api)" class="n q"><polygon points="1000,1482 1175,1530 1000,1578 825,1530"/><text x="1000" y="1525.5" class="s q">¿Reconocemos el correo como</text><text x="1000" y="1543.5" class="s q">usuario de la aplicación?</text></g>
<g style="--k:var(--k-no)" class="n"><rect x="1225" y="1478" width="230" height="106" rx="12"/><text x="1340" y="1506" class="t">Correo no reconocido</text><text x="1340" y="1528" class="s">no se da información de hoteles</text><text x="1340" y="1546" class="s">si el cliente nombra el hotel:</text><text x="1340" y="1564" class="s">solo cuántas antenas tiene</text></g>
<g style="--k:var(--k-api)" class="n q"><polygon points="1000,1618 1175,1666 1000,1714 825,1666"/><text x="1000" y="1661.5" class="s q">¿El hotel que dice el cliente</text><text x="1000" y="1679.5" class="s q">es el de ese correo?</text></g>
<g style="--k:var(--k-no)" class="n"><rect x="1225" y="1624" width="230" height="88" rx="12"/><text x="1340" y="1652" class="t">No es el mismo</text><text x="1340" y="1674" class="s">se le explica con tacto</text><text x="1340" y="1692" class="s">y lo atiende una persona</text></g>
<g style="--k:var(--k-api)" class="n"><rect x="825" y="1754" width="350" height="106" rx="12"/><text x="1000" y="1782" class="t">Datos del hotel para la IA</text><text x="1000" y="1804" class="s">su rol y si el usuario está activo</text><text x="1000" y="1822" class="s">antenas conectadas y desconectadas</text><text x="1000" y="1840" class="s">estado de la etiqueta</text></g>
<g style="--k:var(--k-sys)" class="n q"><polygon points="640,2052 830,2100 640,2148 450,2100"/><text x="640" y="2095.5" class="s q">¿Es un caso tan claro que</text><text x="640" y="2113.5" class="s q">no hace falta pensarlo?</text></g>
<g style="--k:var(--k-der)" class="n"><rect x="70" y="2048" width="290" height="88" rx="12"/><text x="215" y="2076" class="t">Pasa a una persona</text><text x="215" y="2098" class="s">alérgenos, tótems y</text><text x="215" y="2116" class="s">garantías (salvo farmacias)</text></g>
<g style="--k:var(--k-cli)" class="n"><rect x="890" y="2048" width="340" height="88" rx="12"/><text x="1060" y="2076" class="t">Pedir hotel y correo</text><text x="1060" y="2098" class="s">primer mensaje con un problema de uso</text><text x="1060" y="2116" class="s">y aún no sabemos quién escribe</text></g>
<g style="--k:var(--k-sys)" class="n"><rect x="390" y="2218" width="500" height="106" rx="12"/><text x="640" y="2246" class="t">Prepara las instrucciones para la IA</text><text x="640" y="2268" class="s">las reglas del equipo + el orden de decisión</text><text x="640" y="2286" class="s">+ lo que sabemos del cliente y del hotel</text><text x="640" y="2304" class="s">+ «decisión ya tomada» si el caso era claro</text><circle class="bd" cx="390" cy="2218" r="13"/><text x="390" y="2222.5" class="b">5</text></g>
<g style="--k:var(--k-ai)" class="n ia"><rect x="410" y="2374" width="460" height="106" rx="12"/><text x="640" y="2402" class="t">IA · Claude Haiku 5.5</text><text x="640" y="2424" class="s">lee la conversación y las instrucciones</text><text x="640" y="2442" class="s">sigue el orden de decisión (7 puntos)</text><text x="640" y="2460" class="s">y escribe una propuesta de respuesta</text><circle class="bd" cx="410" cy="2374" r="13"/><text x="410" y="2378.5" class="b">6</text></g>
<g style="--k:var(--k-sys)" class="n q"><polygon points="640,2632 860,2689 640,2746 420,2689"/><text x="640" y="2675.5" class="s q">¿Es algo que siempre atiende</text><text x="640" y="2693.5" class="s q">una persona? (alérgenos, tótems,</text><text x="640" y="2711.5" class="s q">formaciones, fallos del contenido)</text><circle class="bd" cx="446" cy="2665" r="13"/><text x="446" y="2669.5" class="b">1</text></g>
<g style="--k:var(--k-der)" class="n"><rect x="920" y="2645" width="430" height="88" rx="12"/><text x="1135" y="2673" class="t">Pasa a una persona</text><text x="1135" y="2695" class="s">sin hacer preguntas al cliente</text><text x="1135" y="2713" class="s">nota «Para el equipo» → Soporte</text></g>
<g style="--k:var(--k-sys)" class="n q"><polygon points="640,2786 860,2843 640,2900 420,2843"/><text x="640" y="2829.5" class="s q">¿Es de material o de dinero?</text><text x="640" y="2847.5" class="s q">(pedido, presupuesto,</text><text x="640" y="2865.5" class="s q">garantía o cambio de precio)</text><circle class="bd" cx="446" cy="2819" r="13"/><text x="446" y="2823.5" class="b">2</text></g>
<g style="--k:var(--k-der)" class="n"><rect x="920" y="2754" width="430" height="178" rx="12"/><text x="1135" y="2782" class="t">Según el caso</text><text x="1135" y="2804" class="s">material (pilas, soportes…) → escribir a postventa</text><text x="1135" y="2822" class="s">   con material, cantidad y foto</text><text x="1135" y="2840" class="s">presupuesto → «Asignar al departamento de</text><text x="1135" y="2858" class="s">   Pedidos y facturas.»</text><text x="1135" y="2876" class="s">garantía → la lleva el equipo de Garantías</text><text x="1135" y="2894" class="s">garantía de farmacia → «la gestiona Expofarm»</text><text x="1135" y="2912" class="s">cambio de precio en tienda → lo hace soporte</text></g>
<g style="--k:var(--k-sys)" class="n q"><polygon points="640,2972 860,3029 640,3086 420,3029"/><text x="640" y="3015.5" class="s q">¿Lo tiene que hacer el equipo?</text><text x="640" y="3033.5" class="s q">(menú de un buffet, logos,</text><text x="640" y="3051.5" class="s q">plantillas, altas de etiquetas)</text><circle class="bd" cx="446" cy="3005" r="13"/><text x="446" y="3009.5" class="b">3</text></g>
<g style="--k:var(--k-der)" class="n"><rect x="920" y="2985" width="430" height="88" rx="12"/><text x="1135" y="3013" class="t">Pasa a una persona</text><text x="1135" y="3035" class="s">si ya sabemos el hotel, no se piden</text><text x="1135" y="3053" class="s">más datos para pasarlo</text></g>
<g style="--k:var(--k-sys)" class="n q"><polygon points="640,3126 860,3183 640,3240 420,3183"/><text x="640" y="3169.5" class="s q">¿Lo puede hacer el propio hotel?</text><text x="640" y="3187.5" class="s q">(usuarios, permisos, posiciones,</text><text x="640" y="3205.5" class="s q">cambiar un plato, forzar el menú)</text><circle class="bd" cx="446" cy="3159" r="13"/><text x="446" y="3163.5" class="b">4</text></g>
<g style="--k:var(--k-ag)" class="n"><rect x="920" y="3130" width="430" height="106" rx="12"/><text x="1135" y="3158" class="t">Se le explica cómo</text><text x="1135" y="3180" class="s">con los pasos de la aplicación</text><text x="1135" y="3198" class="s">(Ajustes › Roles, Usuarios, Posiciones…)</text><text x="1135" y="3216" class="s">si nadie del hotel puede → una persona</text></g>
<g style="--k:var(--k-sys)" class="n q"><polygon points="640,3280 860,3328 640,3376 420,3328"/><text x="640" y="3323.5" class="s q">¿Es un problema de uso y aún</text><text x="640" y="3341.5" class="s q">no sabemos quién escribe?</text><circle class="bd" cx="446" cy="3304" r="13"/><text x="446" y="3308.5" class="b">5</text></g>
<g style="--k:var(--k-cli)" class="n"><rect x="920" y="3284" width="430" height="88" rx="12"/><text x="1135" y="3312" class="t">Se pide hotel y correo</text><text x="1135" y="3334" class="s">como mucho esas dos preguntas</text><text x="1135" y="3352" class="s">lo demás se pregunta después</text></g>
<g style="--k:var(--k-sys)" class="n q"><polygon points="640,3416 860,3464 640,3512 420,3464"/><text x="640" y="3459.5" class="s q">¿Hay antenas sin conexión?</text><text x="640" y="3477.5" class="s q">(dato del panel de hoteles)</text><circle class="bd" cx="446" cy="3440" r="13"/><text x="446" y="3444.5" class="b">6</text></g>
<g style="--k:var(--k-ag)" class="n"><rect x="920" y="3420" width="430" height="88" rx="12"/><text x="1135" y="3448" class="t">Se avisa a su personal técnico</text><text x="1135" y="3470" class="s">que revise sus antenas</text><text x="1135" y="3488" class="s">si ya se le dijo y sigue igual → una persona</text></g>
<g style="--k:var(--k-sys)" class="n q"><polygon points="640,3552 860,3600 640,3648 420,3600"/><text x="640" y="3595.5" class="s q">¿Lo que sabemos trae la</text><text x="640" y="3613.5" class="s q">respuesta o los pasos?</text><circle class="bd" cx="446" cy="3576" r="13"/><text x="446" y="3580.5" class="b">7</text></g>
<g style="--k:var(--k-ag)" class="n"><rect x="920" y="3556" width="430" height="88" rx="12"/><text x="1135" y="3584" class="t">La IA responde</text><text x="1135" y="3606" class="s">solo con lo que consta en la memoria</text><text x="1135" y="3624" class="s">nunca inventa pasos, datos ni plazos</text></g>
<g style="--k:var(--k-no)" class="n"><rect x="440" y="3688" width="400" height="88" rx="12"/><text x="640" y="3716" class="t">Si no encaja o hay duda</text><text x="640" y="3738" class="s">pasa a una persona (o «no sé»)</text><text x="640" y="3756" class="s">mejor derivar que responder con dudas</text></g>
<g style="--k:var(--k-ai)" class="n q"><polygon points="640,3958 840,4006 640,4054 440,4006"/><text x="640" y="4001.5" class="s q">¿La IA ha podido contestar</text><text x="640" y="4019.5" class="s q">sin fallos?</text><circle class="bd" cx="466" cy="3982" r="13"/><text x="466" y="3986.5" class="b">7</text></g>
<g style="--k:var(--k-no)" class="n"><rect x="915" y="3958" width="410" height="88" rx="12"/><text x="1120" y="3986" class="t">No ha podido responder</text><text x="1120" y="4008" class="s">aparece el botón «Reintentar»</text><text x="1120" y="4026" class="s">si ya costó algo, se cuenta</text></g>
<g style="--k:var(--k-ai)" class="n q"><polygon points="640,4094 840,4142 640,4190 440,4142"/><text x="640" y="4137.5" class="s q">¿La IA se atreve a contestar?</text><text x="640" y="4155.5" class="s q">(si no, dice «no sé»)</text></g>
<g style="--k:var(--k-no)" class="n"><rect x="915" y="4094" width="410" height="88" rx="12"/><text x="1120" y="4122" class="t">La IA no sabe</text><text x="1120" y="4144" class="s">no hay propuesta: lo lleva una persona</text><text x="1120" y="4162" class="s">queda apuntado en Ajustes para documentarlo</text></g>
<g style="--k:var(--k-ai)" class="n q"><polygon points="640,4230 840,4278 640,4326 440,4278"/><text x="640" y="4273.5" class="s q">¿La IA propone pasarlo</text><text x="640" y="4291.5" class="s q">a una persona?</text></g>
<g style="--k:var(--k-der)" class="n"><rect x="915" y="4214" width="410" height="124" rx="12"/><text x="1120" y="4242" class="t">Pasa a una persona</text><text x="1120" y="4264" class="s">aviso «Para el equipo»:</text><text x="1120" y="4282" class="s">«Llévalo a Soporte, Garantías o Pedidos» + motivo</text><text x="1120" y="4300" class="s">y un mensaje corto para el cliente</text><text x="1120" y="4318" class="s">lo ve el agente; el cliente no</text></g>
<g style="--k:var(--k-ag)" class="n"><rect x="430" y="4366" width="420" height="88" rx="12"/><text x="640" y="4394" class="t">Propuesta de respuesta</text><text x="640" y="4416" class="s">contesta, pide datos o indica dónde escribir</text><text x="640" y="4434" class="s">se muestra en el panel: no se envía sola</text></g>
<g style="--k:var(--k-sys)" class="n"><rect x="390" y="4500" width="500" height="106" rx="12"/><text x="640" y="4528" class="t">Se guarda la sugerencia</text><text x="640" y="4550" class="s">y lo que ha costado: se suma al gasto del mes</text><text x="640" y="4568" class="s">aviso al llegar al 80 % y al 100 % de 50 USD</text><text x="640" y="4586" class="s">el panel de Ajustes muestra el gasto por día</text><circle class="bd" cx="390" cy="4500" r="13"/><text x="390" y="4504.5" class="b">8</text></g>
<g style="--k:var(--k-ag)" class="n"><rect x="410" y="4750" width="460" height="88" rx="12"/><text x="640" y="4778" class="t">El agente ve la sugerencia</text><text x="640" y="4800" class="s">decide él: la IA nunca envía nada</text><text x="640" y="4818" class="s">aquí también llegan los casos de la sección A</text><circle class="bd" cx="410" cy="4750" r="13"/><text x="410" y="4754.5" class="b">9</text></g>
<g style="--k:var(--k-ag)" class="n"><rect x="65" y="4904" width="300" height="88" rx="12"/><g class="ic" transform="translate(186.2,4913) scale(0.8)"><path d="M7 10v12"/><path d="M15 5.88 14 10h5.83a2 2 0 0 1 1.92 2.56l-2.33 8A2 2 0 0 1 17.5 22H4a2 2 0 0 1-2-2v-8a2 2 0 0 1 2-2h2.76a2 2 0 0 0 1.79-1.11L12 2a3.13 3.13 0 0 1 3 3.88Z"/></g><text x="228" y="4932" class="t">Útil</text><text x="215" y="4954" class="s">se apunta para medir cuánto</text><text x="215" y="4972" class="s">acierta la IA</text></g>
<g style="--k:var(--k-ag)" class="n"><rect x="405" y="4904" width="300" height="88" rx="12"/><g class="ic" transform="translate(475.8,4913) scale(0.8)"><rect x="2" y="4" width="20" height="16" rx="2"/><path d="m22 7-8.97 5.7a1.94 1.94 0 0 1-2.06 0L2 7"/></g><text x="568" y="4932" class="t">Copia y envía él</text><text x="555" y="4954" class="s">puede retocarla antes</text><text x="555" y="4972" class="s">se apunta como usada</text></g>
<g style="--k:var(--k-sys)" class="n"><rect x="745" y="4904" width="300" height="88" rx="12"/><text x="895" y="4932" class="t">Contesta sin la IA</text><text x="895" y="4954" class="s">si no hay propuesta o no le sirve</text><text x="895" y="4972" class="s">el cliente recibe su respuesta</text></g>
<g style="--k:var(--k-no)" class="n"><rect x="1085" y="4904" width="300" height="88" rx="12"/><g class="ic" transform="translate(1189.4,4913) scale(0.8)"><g transform="translate(0,24) scale(1,-1)"><path d="M7 10v12"/><path d="M15 5.88 14 10h5.83a2 2 0 0 1 1.92 2.56l-2.33 8A2 2 0 0 1 17.5 22H4a2 2 0 0 1-2-2v-8a2 2 0 0 1 2-2h2.76a2 2 0 0 0 1.79-1.11L12 2a3.13 3.13 0 0 1 3 3.88Z"/></g></g><text x="1248" y="4932" class="t">No sirve</text><text x="1235" y="4954" class="s">escribe qué falló y cómo</text><text x="1235" y="4972" class="s">había que responder</text></g>
<g style="--k:var(--k-mem)" class="n q"><polygon points="1235,5036 1405,5084 1235,5132 1065,5084"/><text x="1235" y="5079.5" class="s q">¿Un responsable da por</text><text x="1235" y="5097.5" class="s q">buena la corrección?</text><circle class="bd" cx="1091" cy="5060" r="14"/><text x="1091" y="5064.5" class="b">10</text></g>
<g style="--k:var(--k-no)" class="n"><rect x="745" y="5050" width="300" height="70" rx="12"/><text x="895" y="5078" class="t">Se descarta</text><text x="895" y="5100" class="s">la IA no la verá nunca</text></g>
<g style="--k:var(--k-mem)" class="n"><rect x="1085" y="5174" width="300" height="88" rx="12"/><text x="1235" y="5202" class="t">Pasa a la memoria</text><text x="1235" y="5224" class="s">como corrección validada</text><text x="1235" y="5242" class="s">la IA la usará en las próximas consultas</text></g>
<g class="pill si"><rect x="838" y="411" width="30" height="19" rx="9.5"/><text x="853" y="424.5">Sí</text></g>
<g class="pill no"><rect x="650" y="489" width="30" height="19" rx="9.5"/><text x="665" y="502.5">No</text></g>
<g class="pill si"><rect x="838" y="551" width="30" height="19" rx="9.5"/><text x="853" y="564.5">Sí</text></g>
<g class="pill no"><rect x="650" y="629" width="30" height="19" rx="9.5"/><text x="665" y="642.5">No</text></g>
<g class="pill si"><rect x="838" y="691" width="30" height="19" rx="9.5"/><text x="853" y="704.5">Sí</text></g>
<g class="pill no"><rect x="650" y="769" width="30" height="19" rx="9.5"/><text x="665" y="782.5">No</text></g>
<g class="pill si"><rect x="838" y="831" width="30" height="19" rx="9.5"/><text x="853" y="844.5">Sí</text></g>
<g class="pill no"><rect x="650" y="909" width="30" height="19" rx="9.5"/><text x="665" y="922.5">No</text></g>
<g class="pill si"><rect x="650" y="1327" width="30" height="19" rx="9.5"/><text x="665" y="1340.5">Sí</text></g>
<g class="pill no"><rect x="1183" y="1245" width="30" height="19" rx="9.5"/><text x="1198" y="1258.5">No</text></g>
<g class="pill si"><rect x="1010" y="1327" width="30" height="19" rx="9.5"/><text x="1025" y="1340.5">Sí</text></g>
<g class="pill no"><rect x="1183" y="1509" width="30" height="19" rx="9.5"/><text x="1198" y="1522.5">No</text></g>
<g class="pill si"><rect x="1010" y="1591" width="30" height="19" rx="9.5"/><text x="1025" y="1604.5">Sí</text></g>
<g class="pill no"><rect x="1183" y="1645" width="30" height="19" rx="9.5"/><text x="1198" y="1658.5">No</text></g>
<g class="pill si"><rect x="1010" y="1727" width="30" height="19" rx="9.5"/><text x="1025" y="1740.5">Sí</text></g>
<g class="pill no"><rect x="430" y="1245" width="30" height="19" rx="9.5"/><text x="445" y="1258.5">No</text></g>
<g class="pill si"><rect x="412" y="2079" width="30" height="19" rx="9.5"/><text x="427" y="2092.5">Sí</text></g>
<g class="pill si"><rect x="838" y="2079" width="30" height="19" rx="9.5"/><text x="853" y="2092.5">Sí</text></g>
<text x="654" y="2166" class="et" text-anchor="start">No: decide la IA</text>
<text x="654" y="2354" class="et" text-anchor="start">una sola consulta (tiene un pequeño coste)</text>
<g class="pill si"><rect x="868" y="2668" width="30" height="19" rx="9.5"/><text x="883" y="2681.5">Sí</text></g>
<g class="pill no"><rect x="650" y="2765" width="30" height="19" rx="9.5"/><text x="665" y="2778.5">No</text></g>
<g class="pill si"><rect x="868" y="2822" width="30" height="19" rx="9.5"/><text x="883" y="2835.5">Sí</text></g>
<g class="pill no"><rect x="650" y="2951" width="30" height="19" rx="9.5"/><text x="665" y="2964.5">No</text></g>
<g class="pill si"><rect x="868" y="3008" width="30" height="19" rx="9.5"/><text x="883" y="3021.5">Sí</text></g>
<g class="pill no"><rect x="650" y="3105" width="30" height="19" rx="9.5"/><text x="665" y="3118.5">No</text></g>
<g class="pill si"><rect x="868" y="3162" width="30" height="19" rx="9.5"/><text x="883" y="3175.5">Sí</text></g>
<g class="pill no"><rect x="650" y="3259" width="30" height="19" rx="9.5"/><text x="665" y="3272.5">No</text></g>
<g class="pill si"><rect x="868" y="3307" width="30" height="19" rx="9.5"/><text x="883" y="3320.5">Sí</text></g>
<g class="pill no"><rect x="650" y="3395" width="30" height="19" rx="9.5"/><text x="665" y="3408.5">No</text></g>
<g class="pill si"><rect x="868" y="3443" width="30" height="19" rx="9.5"/><text x="883" y="3456.5">Sí</text></g>
<g class="pill no"><rect x="650" y="3531" width="30" height="19" rx="9.5"/><text x="665" y="3544.5">No</text></g>
<g class="pill si"><rect x="868" y="3579" width="30" height="19" rx="9.5"/><text x="883" y="3592.5">Sí</text></g>
<g class="pill no"><rect x="650" y="3657" width="30" height="19" rx="9.5"/><text x="665" y="3670.5">No</text></g>
<g class="pill no"><rect x="848" y="3985" width="30" height="19" rx="9.5"/><text x="863" y="3998.5">No</text></g>
<g class="pill si"><rect x="650" y="4067" width="30" height="19" rx="9.5"/><text x="665" y="4080.5">Sí</text></g>
<g class="pill no"><rect x="848" y="4121" width="30" height="19" rx="9.5"/><text x="863" y="4134.5">No</text></g>
<g class="pill si"><rect x="650" y="4203" width="30" height="19" rx="9.5"/><text x="665" y="4216.5">Sí</text></g>
<g class="pill si"><rect x="848" y="4257" width="30" height="19" rx="9.5"/><text x="863" y="4270.5">Sí</text></g>
<g class="pill no"><rect x="650" y="4339" width="30" height="19" rx="9.5"/><text x="665" y="4352.5">No</text></g>
<g class="pill no"><rect x="1027" y="5095" width="30" height="19" rx="9.5"/><text x="1042" y="5108.5">No</text></g>
<g class="pill si"><rect x="1245" y="5145" width="30" height="19" rx="9.5"/><text x="1260" y="5158.5">Sí</text></g>
<text x="32" y="5296" class="et" text-anchor="start">el cliente contesta → vuelve al paso 1</text>
<text x="660" y="5206" class="et vu" text-anchor="start">lo validado vuelve a la memoria de la IA</text>
</svg><span class="ancla" id="cap-a" style="top:0.373%"></span><span class="ancla" id="cap-b" style="top:17.954%"></span><span class="ancla" id="cap-c" style="top:36.432%"></span><span class="ancla" id="cap-d" style="top:47.256%"></span><span class="ancla" id="cap-e" style="top:72.004%"></span><span class="ancla" id="cap-f" style="top:86.786%"></span><!--/LIENZO--></div>

  <section class="detalle" id="detalle">
    <h2>Detalle de cada capítulo</h2>
    <p>Lo mismo que muestra el diagrama, con las reglas y los matices de cada paso.</p>

    <article class="capitulo" id="det-a">
      <div><h3><i>A</i>Puertas de entrada</h3><p class="res">Qué se comprueba antes de gastar nada.</p></div>
      <dl>
        <div><dt>Si la IA está desactivada</dt><dd>No aparece el botón «Sugerir» y no sale ningún dato hacia fuera. Un responsable la activa o la apaga en Ajustes › Asistente IA. Para poder activarla, el servidor necesita tener puesta la clave de acceso al proveedor de IA (se configura aparte, nunca en pantalla).</dd></div>
        <div><dt>Sugerencia ya guardada</dt><dd>Si ya hay una para el último mensaje del cliente, se enseña sin gastar. «Regenerar» fuerza una nueva.</dd></div>
        <div><dt>Tope mensual de gasto</dt><dd>50 USD al mes (mes natural; se cambia en Ajustes › Asistente IA). Al 80 % y al 100 % el panel avisa. Al llegar al tope el botón dice «Contesta tú» y no se llama al proveedor hasta el día 1. Solo cuentan las sugerencias de los agentes: los chats de prueba y las evaluaciones no cortan a nadie.</dd></div>
        <div><dt>Solo audios de WhatsApp</dt><dd>Texto fijo: «Lo sentimos, ahora mismo no podemos escuchar este audio…». No se llama a la IA y no cuesta nada.</dd></div>
      </dl>
    </article>

    <article class="capitulo" id="det-b">
      <div><h3><i>B</i>Contexto</h3><p class="res">Lo que la IA lee antes de redactar: memoria, fotos y datos del hotel.</p></div>
      <div>
        <dl>
          <div><dt>¿De qué hotel habla? <span class="tag">AdminHoteles</span></dt><dd>Busca los nombres que aparecen en el mensaje o la firma y los localiza en el panel. Todo es de solo lectura.</dd></div>
          <div><dt>¿El correo figura?</dt><dd>Lo comprueba contra los usuarios: hotel, buffet, <b>rol</b> y si el usuario está activo. Si no cuadra con el hotel que dice, no revela nada.</dd></div>
          <div><dt>Antenas y etiqueta</dt><dd>Cuántas antenas hay y cuáles están sin conexión (el estado de ahora, no el del día del ticket). Si hay un código de etiqueta: batería, señal, última actualización y la antena que la sirve.</dd></div>
          <div><dt>Si el panel no contesta</dt><dd>La IA lo sabe y no afirma nada que no haya podido comprobar.</dd></div>
          <div><dt>Preguntas frecuentes</dt><dd>Las publicadas en el portal.</dd></div>
          <div><dt>Tickets resueltos parecidos <span class="tag">RAG</span></dt><dd>Como patrón, nunca para citar datos de otro cliente. Los antiguos, donde el equipo hacía por el cliente lo que hoy hace el hotel, salen con el aviso «ANTIGUO». Los que entregaban contraseñas no se usan.</dd></div>
          <div><dt>Guía y reglas de soporte</dt><dd>Manuales de la aplicación de hoteles y documentación del equipo (unos 300 trozos).</dd></div>
          <div><dt>Correcciones validadas</dt><dd>Lo que los agentes han enseñado con «No sirve». Solo entran las que un responsable valida, más unas pocas «base» guardadas en el repositorio.</dd></div>
          <div><dt>Lectura de fotos</dt><dd>Si hay fotos, el código de barras se lee en el servidor: la foto no sale.</dd></div>
        </dl>
        <p class="nota"><b>Datos personales.</b> En la memoria se quitan correos, teléfonos, nombres, firmas y avisos legales. La conversación del ticket que se está contestando viaja tal cual al proveedor de IA, porque necesita leerla. Las notas internas nunca entran.</p>
      </div>
    </article>

    <article class="capitulo" id="det-c">
      <div><h3><i>C</i>Decisión en código</h3><p class="res">Lo claro no se deja a la suerte del modelo.</p></div>
      <dl>
        <div><dt>Siempre derivan</dt><dd>Alérgenos, tótems y garantías (salvo las de farmacia): respuesta corta, sin preguntar nada, y nota «Para el equipo».</dd></div>
        <div><dt>Piden hotel y correo</dt><dd>Un problema de uso en el primer mensaje, sin hotel ni correo verificado: solo se pregunta eso.</dd></div>
        <div><dt>El resto</dt><dd>Lo decide el modelo. La instrucción reúne las reglas del equipo, el orden de decisión, la memoria, los datos del hotel y, si el código decidió, «DECISIÓN YA TOMADA».</dd></div>
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
        <div><dt>No sabe o falla</dt><dd>No hay borrador: el agente ve «La IA no se atreve» o un aviso con «Reintentar». Los huecos quedan anotados en Ajustes para saber qué documentar.</dd></div>
        <div><dt>Coste</dt><dd>Cada llamada guarda sus tokens. El panel suma el gasto estimado del mes, avisa al 80 % y al 100 % del tope y muestra el gasto por día.</dd></div>
      </dl>
    </article>

    <article class="capitulo" id="det-f">
      <div><h3><i>F</i>Agente y aprendizaje</h3><p class="res">La persona decide; lo validado mejora las siguientes sugerencias.</p></div>
      <dl>
        <div><dt>Panel lateral de solo lectura</dt><dd>Enseña cada paso al momento (hotel, correo, rol, antenas, memoria) y deja el borrador. Solo se puede <b>copiar</b>: no se escribe en el editor y la IA nunca envía.</dd></div>
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
