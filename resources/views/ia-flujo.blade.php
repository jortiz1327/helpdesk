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
  .wrap { max-width: 1600px; margin: 0 auto; padding: 22px 18px 60px; }
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
  .lienzo svg { width: 100%; min-width: 1100px; height: auto; display: block; }
  .n rect { fill: var(--bg); stroke: var(--k); stroke-width: 2; rx: 12; }
  .n circle { fill: var(--k); }
  .n .b { fill: #fff; font: 700 12px system-ui, sans-serif; text-anchor: middle; }
  .n .t { fill: var(--ink); font: 700 14px system-ui, sans-serif; text-anchor: middle; }
  .n .s { fill: var(--muted); font: 12px system-ui, sans-serif; text-anchor: middle; }
  .n polygon { fill: var(--bg); stroke: var(--k); stroke-width: 2; stroke-linejoin: round; }
  .n .s.q { font-size: 12px; }
  .sec { fill: var(--ink); font: 700 14px system-ui, sans-serif; letter-spacing: .02em; }
  .secl { stroke: var(--line); stroke-width: 1; fill: none; }
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

    <div class="lienzo"><svg viewBox="0 0 1520 4553" role="img" aria-label="Flujo completo de la IA de soporte, de que el cliente escribe a que el responsable valida la corrección">
<defs><marker id="p" viewBox="0 0 10 10" refX="9" refY="5" markerWidth="7" markerHeight="7" orient="auto-start-reverse"><path d="M0,0 L10,5 L0,10 z" class="punta"/></marker></defs>
<g class="fl" marker-end="url(#p)"><path d="M640,132 L640,174"/><path d="M640,254 L640,296"/><path d="M820,342 L930,342"/><path d="M640,388 L640,430"/><path d="M820,476 L930,476"/><path d="M640,522 L640,564"/><path d="M820,610 L930,610"/><path d="M640,656 L640,698"/><path d="M820,744 L930,744"/><path d="M640,790 L640,872"/><path d="M200,1130 L200,1166"/><path d="M640,1108 L640,1144"/><path d="M1170,1062 L1225,1065.5"/><path d="M1000,1108 L1000,1144"/><path d="M1000,1224 L1000,1260"/><path d="M1170,1306 L1225,1306.5"/><path d="M1000,1352 L1000,1388"/><path d="M1170,1434 L1225,1434"/><path d="M1000,1480 L1000,1516"/><path marker-end="none" d="M640,952 L640,986"/><path marker-end="none" d="M200,986 L1000,986"/><path d="M200,986 L200,1016"/><path d="M640,986 L640,1016"/><path d="M1000,986 L1000,1016"/><path marker-end="none" d="M480,1062 L425,1062 L425,1244 L640,1244"/><path marker-end="none" d="M200,1280 L200,1636"/><path marker-end="none" d="M640,1224 L640,1636"/><path marker-end="none" d="M1000,1596 L1000,1636"/><path marker-end="none" d="M200,1636 L1000,1636"/><path d="M640,1636 L640,1690"/><path d="M460,1736 L350,1726"/><path d="M820,1736 L870,1726"/><path marker-end="none" d="M200,1766 L200,1810 L1040,1810"/><path marker-end="none" d="M1040,1766 L1040,1810"/><path d="M640,1782 L640,1846"/><path d="M640,1943 L640,1989"/><path d="M640,2086 L640,2150"/><path d="M850,2204.5 L920,2204.5"/><path d="M640,2259 L640,2295"/><path d="M850,2349.5 L920,2349.5"/><path d="M640,2404 L640,2468"/><path d="M850,2522.5 L920,2522.5"/><path d="M640,2577 L640,2613"/><path d="M850,2667.5 L920,2667.5"/><path d="M640,2722 L640,2758"/><path d="M850,2804 L920,2804"/><path d="M640,2850 L640,2886"/><path d="M850,2932 L920,2932"/><path d="M640,2978 L640,3014"/><path d="M850,3060 L920,3060"/><path d="M640,3106 L640,3142"/><path marker-end="none" d="M1340,2204.5 L1485,2204.5"/><path marker-end="none" d="M1340,2349.5 L1485,2349.5"/><path marker-end="none" d="M1340,2522.5 L1485,2522.5"/><path marker-end="none" d="M1340,2667.5 L1485,2667.5"/><path marker-end="none" d="M1340,2804 L1485,2804"/><path marker-end="none" d="M1340,2932 L1485,2932"/><path marker-end="none" d="M1340,3060 L1485,3060"/><path marker-end="none" d="M1485,2204.5 L1485,3260"/><path marker-end="none" d="M640,3222 L640,3260"/><path marker-end="none" d="M1485,3260 L640,3260"/><path d="M640,3296 L640,3316"/><path d="M830,3362 L920,3358"/><path d="M640,3408 L640,3446"/><path d="M830,3492 L920,3488"/><path d="M640,3538 L640,3576"/><path d="M830,3622 L920,3621"/><path d="M640,3668 L640,3706"/><path d="M640,3786 L640,3830"/><path marker-end="none" d="M1320,3358 L1485,3358"/><path marker-end="none" d="M1320,3488 L1485,3488"/><path marker-end="none" d="M1320,3621 L1485,3621"/><path marker-end="none" d="M1485,3358 L1485,3808"/><path marker-end="none" d="M1485,3808 L640,3808"/><path d="M640,3927 L640,4011"/><path marker-end="none" d="M640,4091 L640,4123"/><path marker-end="none" d="M190,4123 L1240,4123"/><path d="M190,4123 L190,4153"/><path d="M540,4123 L540,4153"/><path d="M890,4123 L890,4153"/><path d="M1240,4123 L1240,4153"/><path d="M1240,4233 L1240,4273"/><path d="M1075,4319 L1040,4318.5"/><path d="M1240,4365 L1240,4403"/><path class="vuelta" style="stroke:var(--k-cli)" d="M540,4233 L540,4523 L18,4523 L18,92 L450,92"/><path class="vuelta" style="stroke:var(--k-mem)" d="M1090,4443 L46,4443 L46,1073 L50,1073"/></g>
<text x="760" y="24" class="sec" text-anchor="middle">A · Desde que el cliente escribe hasta que se decide llamar a la IA</text><path class="secl" d="M30,34 L1490,34"/><text x="828" y="334" class="et" text-anchor="start">Sí</text><text x="650" y="405" class="et" text-anchor="start">No</text><text x="828" y="468" class="et" text-anchor="start">Sí</text><text x="650" y="539" class="et" text-anchor="start">No</text><text x="828" y="602" class="et" text-anchor="start">Sí</text><text x="650" y="673" class="et" text-anchor="start">No</text><text x="828" y="736" class="et" text-anchor="start">Sí</text><text x="650" y="807" class="et" text-anchor="start">No</text><text x="760" y="838" class="sec" text-anchor="middle">B · La conversación y todo lo que se consulta antes de llamar a la IA</text><path class="secl" d="M30,848 L1490,848"/><text x="650" y="1132" class="et" text-anchor="start">Sí</text><text x="1178" y="1054" class="et" text-anchor="start">No</text><text x="1010" y="1132" class="et" text-anchor="start">Sí</text><text x="1178" y="1298" class="et" text-anchor="start">No</text><text x="1010" y="1376" class="et" text-anchor="start">Sí: rol, usuario activo, hoteles</text><text x="1178" y="1426" class="et" text-anchor="start">No</text><text x="1010" y="1504" class="et" text-anchor="start">Sí</text><text x="458" y="1054" class="et" text-anchor="end">No</text><text x="760" y="1670" class="sec" text-anchor="middle">C · Lo que decide el código antes de preguntar a la IA, y la instrucción que se le envía</text><path class="secl" d="M30,1680 L1490,1680"/><text x="452" y="1728" class="et" text-anchor="end">Sí</text><text x="828" y="1728" class="et" text-anchor="start">Sí</text><text x="650" y="1797" class="et" text-anchor="start">No: decide el modelo</text><text x="650" y="1971" class="et" text-anchor="start">una llamada: se pagan los tokens</text><text x="760" y="2126" class="sec" text-anchor="middle">D · Cómo decide el modelo: el primer punto que encaja manda</text><path class="secl" d="M30,2136 L1490,2136"/><text x="858" y="2196.5" class="et" text-anchor="start">Sí</text><text x="650" y="2289" class="et" text-anchor="start">No</text><text x="858" y="2341.5" class="et" text-anchor="start">Sí</text><text x="650" y="2462" class="et" text-anchor="start">No</text><text x="858" y="2514.5" class="et" text-anchor="start">Sí</text><text x="650" y="2607" class="et" text-anchor="start">No</text><text x="858" y="2659.5" class="et" text-anchor="start">Sí</text><text x="650" y="2752" class="et" text-anchor="start">No</text><text x="858" y="2796" class="et" text-anchor="start">Sí</text><text x="650" y="2880" class="et" text-anchor="start">No</text><text x="858" y="2924" class="et" text-anchor="start">Sí</text><text x="650" y="3008" class="et" text-anchor="start">No</text><text x="858" y="3052" class="et" text-anchor="start">Sí</text><text x="650" y="3123" class="et" text-anchor="start">No</text><text x="760" y="3284" class="sec" text-anchor="middle">E · Qué se hace con lo que contesta la IA</text><path class="secl" d="M30,3294 L1490,3294"/><text x="838" y="3354" class="et" text-anchor="start">No</text><text x="650" y="3433" class="et" text-anchor="start">Sí</text><text x="838" y="3484" class="et" text-anchor="start">No</text><text x="650" y="3563" class="et" text-anchor="start">Sí</text><text x="838" y="3614" class="et" text-anchor="start">Sí</text><text x="650" y="3693" class="et" text-anchor="start">No</text><text x="760" y="3981" class="sec" text-anchor="middle">F · El agente decide, valora, y lo validado vuelve a la memoria</text><path class="secl" d="M30,3991 L1490,3991"/><text x="1067" y="4311" class="et" text-anchor="end">No</text><text x="1250" y="4390" class="et" text-anchor="start">Sí</text><text x="30" y="4515" class="et" text-anchor="start">el cliente contesta → vuelve al paso 1</text><text x="640" y="4435" class="et vu" text-anchor="start">bucle: lo validado vuelve a la memoria</text>
<g style="--k:var(--k-cli)" class="n"><rect x="450" y="52" width="380" height="80"/><text x="640" y="77" class="t">Cliente escribe</text><text x="640" y="97" class="s">correo · WhatsApp · formulario · portal</text><text x="640" y="114" class="s">se crea o se actualiza el ticket</text><circle cx="450" cy="52" r="13"/><text x="450" y="56.5" class="b">1</text></g>
<g style="--k:var(--k-ag)" class="n"><rect x="450" y="174" width="380" height="80"/><text x="640" y="199" class="t">El agente pide la sugerencia</text><text x="640" y="219" class="s">abre el ticket y pulsa «Sugerir»</text><text x="640" y="236" class="s">la IA no actúa sola y no envía nada</text><circle cx="450" cy="174" r="13"/><text x="450" y="178.5" class="b">2</text></g>
<g style="--k:var(--k-sys)" class="n q"><polygon points="640,296 820,342 640,388 460,342"/><text x="640" y="337.5" class="s q">¿Asistente encendido y con</text><text x="640" y="354.5" class="s q">la clave del proveedor puesta?</text></g>
<g style="--k:var(--k-no)" class="n"><rect x="930" y="310.5" width="380" height="63"/><text x="1120" y="335.5" class="t">Sin botón de sugerir</text><text x="1120" y="355.5" class="s">no sale ningún dato hacia fuera</text></g>
<g style="--k:var(--k-sys)" class="n q"><polygon points="640,430 820,476 640,522 460,476"/><text x="640" y="471.5" class="s q">¿Ya hay una sugerencia para</text><text x="640" y="488.5" class="s q">el último mensaje del cliente?</text></g>
<g style="--k:var(--k-mem)" class="n"><rect x="930" y="427.5" width="380" height="97"/><text x="1120" y="452.5" class="t">Se enseña la que ya estaba</text><text x="1120" y="472.5" class="s">no se llama a la IA y no gasta</text><text x="1120" y="489.5" class="s">«Regenerar» salta esta puerta</text><text x="1120" y="506.5" class="s">→ sigue en el paso 9</text></g>
<g style="--k:var(--k-sys)" class="n q"><polygon points="640,564 820,610 640,656 460,610"/><text x="640" y="605.5" class="s q">¿Se ha llegado al tope</text><text x="640" y="622.5" class="s q">mensual de gasto? (50 USD)</text></g>
<g style="--k:var(--k-no)" class="n"><rect x="930" y="561.5" width="380" height="97"/><text x="1120" y="586.5" class="t">Falla con aviso al agente</text><text x="1120" y="606.5" class="s">«Contesta tú; vuelve el día 1»</text><text x="1120" y="623.5" class="s">no se llama al proveedor</text><text x="1120" y="640.5" class="s">→ lo contesta el agente sin IA</text></g>
<g style="--k:var(--k-sys)" class="n q"><polygon points="640,698 820,744 640,790 460,744"/><text x="640" y="739.5" class="s q">¿El cliente solo ha enviado</text><text x="640" y="756.5" class="s q">audios de WhatsApp?</text></g>
<g style="--k:var(--k-sys)" class="n"><rect x="930" y="695.5" width="380" height="97"/><text x="1120" y="720.5" class="t">Texto fijo, sin IA</text><text x="1120" y="740.5" class="s">«No podemos escuchar audios…»</text><text x="1120" y="757.5" class="s">no gasta nada</text><text x="1120" y="774.5" class="s">→ sigue en el paso 9</text></g>
<g style="--k:var(--k-sys)" class="n"><rect x="430" y="872" width="420" height="80"/><text x="640" y="897" class="t">Prepara la conversación</text><text x="640" y="917" class="s">lee los mensajes del cliente y las respuestas del equipo</text><text x="640" y="934" class="s">(sin notas internas) y localiza la última pregunta</text><circle cx="430" cy="872" r="13"/><text x="430" y="876.5" class="b">3</text></g>
<g style="--k:var(--k-mem)" class="n"><rect x="50" y="1016" width="300" height="114"/><text x="200" y="1041" class="t">Memoria y guía</text><text x="200" y="1061" class="s">preguntas frecuentes</text><text x="200" y="1078" class="s">respuestas de ejemplo del equipo</text><text x="200" y="1095" class="s">tickets parecidos y manuales</text><text x="200" y="1112" class="s">correcciones validadas por un responsable</text><circle cx="50" cy="1016" r="13"/><text x="50" y="1020.5" class="b">4</text></g>
<g style="--k:var(--k-mem)" class="n"><rect x="50" y="1166" width="300" height="114"/><text x="200" y="1191" class="t">Cómo se limpia lo que se guarda</text><text x="200" y="1211" class="s">sin firmas, correos ni teléfonos</text><text x="200" y="1228" class="s">contraseñas tapadas</text><text x="200" y="1245" class="s">lo antiguo se marca ANTIGUO</text><text x="200" y="1262" class="s">(sirve para entender, no para copiar)</text></g>
<g style="--k:var(--k-sys)" class="n q"><polygon points="640,1016 800,1062 640,1108 480,1062"/><text x="640" y="1057.5" class="s q">¿El cliente ha enviado</text><text x="640" y="1074.5" class="s q">fotos?</text><circle cx="502" cy="1040" r="13"/><text x="502" y="1044.5" class="b">4</text></g>
<g style="--k:var(--k-sys)" class="n"><rect x="480" y="1144" width="320" height="80"/><text x="640" y="1169" class="t">Lee el código de barras</text><text x="640" y="1189" class="s">en local (la foto no sale)</text><text x="640" y="1206" class="s">saca los códigos de etiqueta</text></g>
<g style="--k:var(--k-api)" class="n q"><polygon points="1000,1016 1170,1062 1000,1108 830,1062"/><text x="1000" y="1057.5" class="s q">¿AdminHoteles está</text><text x="1000" y="1074.5" class="s q">configurado? (solo lectura)</text><circle cx="852" cy="1040" r="13"/><text x="852" y="1044.5" class="b">4</text></g>
<g style="--k:var(--k-no)" class="n"><rect x="1225" y="1034" width="230" height="63"/><text x="1340" y="1059" class="t">Sin datos del hotel</text><text x="1340" y="1079" class="s">la IA pregunta por él</text></g>
<g style="--k:var(--k-api)" class="n"><rect x="830" y="1144" width="340" height="80"/><text x="1000" y="1169" class="t">Busca el hotel</text><text x="1000" y="1189" class="s">por el correo del ticket, el nombre en</text><text x="1000" y="1206" class="s">el mensaje o la firma, o los códigos</text></g>
<g style="--k:var(--k-api)" class="n q"><polygon points="1000,1260 1170,1306 1000,1352 830,1306"/><text x="1000" y="1301.5" class="s q">¿El correo está dado de alta</text><text x="1000" y="1318.5" class="s q">en la aplicación?</text></g>
<g style="--k:var(--k-no)" class="n"><rect x="1225" y="1258" width="230" height="97"/><text x="1340" y="1283" class="t">Correo sin verificar</text><text x="1340" y="1303" class="s">no se dan datos de hoteles</text><text x="1340" y="1320" class="s">si el hotel viene nombrado:</text><text x="1340" y="1337" class="s">solo se cuentan las antenas</text></g>
<g style="--k:var(--k-api)" class="n q"><polygon points="1000,1388 1170,1434 1000,1480 830,1434"/><text x="1000" y="1429.5" class="s q">¿El hotel que dice el cliente</text><text x="1000" y="1446.5" class="s q">coincide con el del correo?</text></g>
<g style="--k:var(--k-no)" class="n"><rect x="1225" y="1394" width="230" height="80"/><text x="1340" y="1419" class="t">No coincide</text><text x="1340" y="1439" class="s">se explica con tacto</text><text x="1340" y="1456" class="s">y se deriva a una persona</text></g>
<g style="--k:var(--k-api)" class="n"><rect x="830" y="1516" width="340" height="80"/><text x="1000" y="1541" class="t">Datos del hotel para la IA</text><text x="1000" y="1561" class="s">antenas conectadas y desconectadas</text><text x="1000" y="1578" class="s">estado de la etiqueta</text></g>
<g style="--k:var(--k-sys)" class="n q"><polygon points="640,1690 820,1736 640,1782 460,1736"/><text x="640" y="1731.5" class="s q">¿Es un tema de los que</text><text x="640" y="1748.5" class="s q">fija el código?</text><circle cx="482" cy="1714" r="13"/><text x="482" y="1718.5" class="b">5</text></g>
<g style="--k:var(--k-api)" class="n"><rect x="50" y="1686" width="300" height="80"/><text x="200" y="1711" class="t">Derivar, sin preguntar nada</text><text x="200" y="1731" class="s">alérgenos · tótem</text><text x="200" y="1748" class="s">garantía (si no es de farmacia)</text></g>
<g style="--k:var(--k-cli)" class="n"><rect x="870" y="1686" width="340" height="80"/><text x="1040" y="1711" class="t">Pedir hotel y correo</text><text x="1040" y="1731" class="s">problema de uso, primer mensaje,</text><text x="1040" y="1748" class="s">sin hotel ni correo verificado</text></g>
<g style="--k:var(--k-sys)" class="n"><rect x="405" y="1846" width="470" height="97"/><text x="640" y="1871" class="t">Compone la instrucción para la IA</text><text x="640" y="1891" class="s">reglas del equipo + orden de decisión</text><text x="640" y="1908" class="s">+ memoria + datos del hotel + códigos de las fotos</text><text x="640" y="1925" class="s">+ «DECISIÓN YA TOMADA» si el código decidió</text><circle cx="405" cy="1846" r="13"/><text x="405" y="1850.5" class="b">5</text></g>
<g style="--k:var(--k-ai)" class="n ia"><rect x="420" y="1989" width="440" height="97"/><text x="640" y="2014" class="t">IA · Claude Haiku 5.5</text><text x="640" y="2034" class="s">recibe la conversación y la instrucción</text><text x="640" y="2051" class="s">aplica el orden de decisión (7 puntos)</text><text x="640" y="2068" class="s">y redacta el borrador</text><circle cx="420" cy="1989" r="13"/><text x="420" y="1993.5" class="b">6</text></g>
<g style="--k:var(--k-sys)" class="n q"><polygon points="640,2150 850,2204.5 640,2259 430,2204.5"/><text x="640" y="2191.5" class="s q">¿Alérgenos, contenido erróneo en</text><text x="640" y="2208.5" class="s q">las etiquetas, tótem, formación o</text><text x="640" y="2225.5" class="s q">cliente que no es hotel ni tienda?</text><circle cx="452" cy="2182.5" r="13"/><text x="452" y="2187" class="b">1</text></g>
<g style="--k:var(--k-api)" class="n"><rect x="920" y="2164.5" width="420" height="80"/><text x="1130" y="2189.5" class="t">DERIVAR</text><text x="1130" y="2209.5" class="s">sin preguntar nada</text><text x="1130" y="2226.5" class="s">nota «Para el equipo» → Soporte</text></g>
<g style="--k:var(--k-sys)" class="n q"><polygon points="640,2295 850,2349.5 640,2404 430,2349.5"/><text x="640" y="2336.5" class="s q">¿Es material o dinero?</text><text x="640" y="2353.5" class="s q">(pedido, presupuesto,</text><text x="640" y="2370.5" class="s q">garantía o cambio de precio)</text><circle cx="452" cy="2327.5" r="13"/><text x="452" y="2332" class="b">2</text></g>
<g style="--k:var(--k-api)" class="n"><rect x="920" y="2267" width="420" height="165"/><text x="1130" y="2292" class="t">Según qué sea</text><text x="1130" y="2312" class="s">pedido de material → escribir a postventa</text><text x="1130" y="2329" class="s">   (material, cantidad y foto)</text><text x="1130" y="2346" class="s">presupuesto → «Asignar al departamento de</text><text x="1130" y="2363" class="s">   Pedidos y facturas.»</text><text x="1130" y="2380" class="s">garantía → DERIVAR a Garantías (nunca correo)</text><text x="1130" y="2397" class="s">garantía de farmacia → «lo gestiona Expofarm»</text><text x="1130" y="2414" class="s">cambio de precio en tienda → DERIVAR</text></g>
<g style="--k:var(--k-sys)" class="n q"><polygon points="640,2468 850,2522.5 640,2577 430,2522.5"/><text x="640" y="2509.5" class="s q">¿Lo ejecuta el equipo?</text><text x="640" y="2526.5" class="s q">(rueda de menú, logos, plantillas,</text><text x="640" y="2543.5" class="s q">alta de etiquetas en la plataforma)</text><circle cx="452" cy="2500.5" r="13"/><text x="452" y="2505" class="b">3</text></g>
<g style="--k:var(--k-api)" class="n"><rect x="920" y="2482.5" width="420" height="80"/><text x="1130" y="2507.5" class="t">DERIVAR</text><text x="1130" y="2527.5" class="s">si el hotel ya consta no se pide</text><text x="1130" y="2544.5" class="s">hotel ni correo para derivarlo</text></g>
<g style="--k:var(--k-sys)" class="n q"><polygon points="640,2613 850,2667.5 640,2722 430,2667.5"/><text x="640" y="2654.5" class="s q">¿Lo hace el propio hotel?</text><text x="640" y="2671.5" class="s q">(usuarios, roles y permisos, posiciones,</text><text x="640" y="2688.5" class="s q">«Sin plato», forzar menú, horarios)</text><circle cx="452" cy="2645.5" r="13"/><text x="452" y="2650" class="b">4</text></g>
<g style="--k:var(--k-ag)" class="n"><rect x="920" y="2619" width="420" height="97"/><text x="1130" y="2644" class="t">EXPLICAR los pasos</text><text x="1130" y="2664" class="s">Ajustes › Roles, Usuarios, Posiciones…</text><text x="1130" y="2681" class="s">solo con el correo verificado</text><text x="1130" y="2698" class="s">si nadie del hotel puede → DERIVAR</text></g>
<g style="--k:var(--k-sys)" class="n q"><polygon points="640,2758 850,2804 640,2850 430,2804"/><text x="640" y="2799.5" class="s q">¿Es un problema de uso y no se</text><text x="640" y="2816.5" class="s q">sabe quién escribe?</text><circle cx="452" cy="2782" r="13"/><text x="452" y="2786.5" class="b">5</text></g>
<g style="--k:var(--k-cli)" class="n"><rect x="920" y="2764" width="420" height="80"/><text x="1130" y="2789" class="t">PEDIR hotel y correo</text><text x="1130" y="2809" class="s">como máximo esas dos preguntas</text><text x="1130" y="2826" class="s">los demás datos se piden después</text></g>
<g style="--k:var(--k-sys)" class="n q"><polygon points="640,2886 850,2932 640,2978 430,2932"/><text x="640" y="2927.5" class="s q">¿Hay antenas desconectadas?</text><text x="640" y="2944.5" class="s q">(dato de AdminHoteles)</text><circle cx="452" cy="2910" r="13"/><text x="452" y="2914.5" class="b">6</text></g>
<g style="--k:var(--k-ag)" class="n"><rect x="920" y="2892" width="420" height="80"/><text x="1130" y="2917" class="t">Avisar a su personal técnico</text><text x="1130" y="2937" class="s">que lo revise su mantenimiento</text><text x="1130" y="2954" class="s">si ya se le dijo y sigue igual → DERIVAR</text></g>
<g style="--k:var(--k-sys)" class="n q"><polygon points="640,3014 850,3060 640,3106 430,3060"/><text x="640" y="3055.5" class="s q">¿El contexto trae los pasos</text><text x="640" y="3072.5" class="s q">o la respuesta?</text><circle cx="452" cy="3038" r="13"/><text x="452" y="3042.5" class="b">7</text></g>
<g style="--k:var(--k-ag)" class="n"><rect x="920" y="3020" width="420" height="80"/><text x="1130" y="3045" class="t">RESPONDER</text><text x="1130" y="3065" class="s">solo con lo que dice el contexto</text><text x="1130" y="3082" class="s">nunca inventa pasos, datos ni plazos</text></g>
<g style="--k:var(--k-no)" class="n"><rect x="450" y="3142" width="380" height="80"/><text x="640" y="3167" class="t">Si no encaja o hay duda</text><text x="640" y="3187" class="s">DERIVAR (o «no sé»: sin borrador)</text><text x="640" y="3204" class="s">es preferible derivar a dar una respuesta dudosa</text><circle cx="450" cy="3142" r="13"/><text x="450" y="3146.5" class="b">8</text></g>
<g style="--k:var(--k-ai)" class="n q"><polygon points="640,3316 830,3362 640,3408 450,3362"/><text x="640" y="3357.5" class="s q">¿La respuesta llegó completa</text><text x="640" y="3374.5" class="s q">y el proveedor contestó?</text><circle cx="472" cy="3340" r="13"/><text x="472" y="3344.5" class="b">7</text></g>
<g style="--k:var(--k-no)" class="n"><rect x="920" y="3318" width="400" height="80"/><text x="1120" y="3343" class="t">No pudo preparar la respuesta</text><text x="1120" y="3363" class="s">botón «Reintentar»</text><text x="1120" y="3380" class="s">si ya se pagó, los tokens se cuentan</text></g>
<g style="--k:var(--k-ai)" class="n q"><polygon points="640,3446 830,3492 640,3538 450,3492"/><text x="640" y="3487.5" class="s q">¿La IA se atreve a contestar?</text><text x="640" y="3504.5" class="s q">(si no, responde «NO SÉ»)</text></g>
<g style="--k:var(--k-no)" class="n"><rect x="920" y="3448" width="400" height="80"/><text x="1120" y="3473" class="t">No sabe: no hay borrador</text><text x="1120" y="3493" class="s">lo debe llevar una persona</text><text x="1120" y="3510" class="s">queda como hueco en Ajustes › Asistente IA</text></g>
<g style="--k:var(--k-ai)" class="n q"><polygon points="640,3576 830,3622 640,3668 450,3622"/><text x="640" y="3617.5" class="s q">¿Lleva el marcador interno</text><text x="640" y="3634.5" class="s q">[[DERIVAR: categoría | motivo]]?</text></g>
<g style="--k:var(--k-api)" class="n"><rect x="920" y="3564" width="400" height="114"/><text x="1120" y="3589" class="t">Derivar (sugerido)</text><text x="1120" y="3609" class="s">nota «Para el equipo»:</text><text x="1120" y="3626" class="s">«Llévalo a Soporte · Garantías · Pedidos» + motivo</text><text x="1120" y="3643" class="s">más un mensaje para el cliente (opcional)</text><text x="1120" y="3660" class="s">el marcador no se enseña al agente</text></g>
<g style="--k:var(--k-ag)" class="n"><rect x="440" y="3706" width="400" height="80"/><text x="640" y="3731" class="t">Borrador sugerido</text><text x="640" y="3751" class="s">responder · pedir datos · indicar el canal</text><text x="640" y="3768" class="s">se enseña en el panel: no se envía solo</text></g>
<g style="--k:var(--k-sys)" class="n"><rect x="405" y="3830" width="470" height="97"/><text x="640" y="3855" class="t">Se guarda la sugerencia</text><text x="640" y="3875" class="s">con los tokens gastados → coste estimado del mes</text><text x="640" y="3892" class="s">aviso al 80 % y al 100 % del tope (50 USD, mes natural)</text><text x="640" y="3909" class="s">el panel de Ajustes muestra el gasto por día</text><circle cx="405" cy="3830" r="13"/><text x="405" y="3834.5" class="b">8</text></g>
<g style="--k:var(--k-ag)" class="n"><rect x="420" y="4011" width="440" height="80"/><text x="640" y="4036" class="t">El agente ve la sugerencia</text><text x="640" y="4056" class="s">lo decide todo él: la IA no envía nada</text><text x="640" y="4073" class="s">lo que llegó de las puertas de A también se ve aquí</text><circle cx="420" cy="4011" r="13"/><text x="420" y="4015.5" class="b">9</text></g>
<g style="--k:var(--k-ag)" class="n"><rect x="40" y="4153" width="300" height="80"/><text x="190" y="4178" class="t">👍 Útil</text><text x="190" y="4198" class="s">cuenta para el acierto</text><text x="190" y="4215" class="s">de la IA en el panel</text></g>
<g style="--k:var(--k-ag)" class="n"><rect x="390" y="4153" width="300" height="80"/><text x="540" y="4178" class="t">✉️ Copia y envía él</text><text x="540" y="4198" class="s">lo edita si quiere</text><text x="540" y="4215" class="s">se marca como usada</text></g>
<g style="--k:var(--k-sys)" class="n"><rect x="740" y="4153" width="300" height="80"/><text x="890" y="4178" class="t">Lo contesta sin la IA</text><text x="890" y="4198" class="s">si no hay borrador o no sirve</text><text x="890" y="4215" class="s">el cliente recibe su respuesta</text></g>
<g style="--k:var(--k-no)" class="n"><rect x="1090" y="4153" width="300" height="80"/><text x="1240" y="4178" class="t">👎 No sirve</text><text x="1240" y="4198" class="s">escribe qué estaba mal y</text><text x="1240" y="4215" class="s">cómo había que responder</text></g>
<g style="--k:var(--k-mem)" class="n q"><polygon points="1240,4273 1405,4319 1240,4365 1075,4319"/><text x="1240" y="4314.5" class="s q">¿Un responsable valida</text><text x="1240" y="4331.5" class="s q">la corrección?</text><circle cx="1097" cy="4297" r="13"/><text x="1097" y="4301.5" class="b">10</text></g>
<g style="--k:var(--k-no)" class="n"><rect x="740" y="4287" width="300" height="63"/><text x="890" y="4312" class="t">Se descarta</text><text x="890" y="4332" class="s">no llega nunca a la IA</text></g>
<g style="--k:var(--k-mem)" class="n"><rect x="1090" y="4403" width="300" height="80"/><text x="1240" y="4428" class="t">Entra en la memoria</text><text x="1240" y="4448" class="s">como corrección validada</text><text x="1240" y="4465" class="s">la IA la lee en la próxima consulta</text></g>
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
        <li><div><b>Tope mensual de gasto</b> <span>→ 50 USD al mes (mes natural, se cambia en Ajustes › Asistente IA). Al 80 % y al 100 % el panel avisa; al llegar al tope el botón dice «Contesta tú» y no se llama al proveedor hasta el día 1. Solo cuentan las sugerencias de los agentes: los chats de prueba y las evaluaciones no cortan a nadie.</span></div></li>
        <li><div><b>Sugerencia ya guardada</b> <span>→ si ya hay una para el último mensaje del cliente se enseña sin gastar; «Regenerar» fuerza otra nueva.</span></div></li>
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
        <li><div><b>Correcciones validadas</b> <span>lo que los agentes han enseñado con 👎; solo entran las que un responsable valida, más unas pocas «base» guardadas en el repositorio.</span></div></li>
      </ul>
    </div>
  </div>

  <div class="paso" style="--k: var(--k-ai)">
    <div class="carril"><div class="num">6</div></div>
    <div class="card">
      <span class="quien">IA · Claude Haiku 5.5</span>
      <h2>Redacta con las reglas puestas</h2>
      <p>Recibe la conversación, lo que dijo AdminHoteles y lo que encontró en la memoria, más las reglas del equipo. Antes de redactar, el sistema <b>fija en código</b> los casos claros (alérgenos, tótems y garantías → derivar; problema de uso sin hotel ni correo en el primer mensaje → pedir solo hotel y correo); el resto lo decide el modelo siguiendo un <b>orden de 7 puntos</b>: derivar sin preguntar · material y dinero · lo que ejecuta el equipo · lo que hace el hotel (explicar) · problema de uso sin hotel · antenas · duda = derivar.</p>
      <ul>
        <li><div><b>Siempre se deriva:</b> <span>alérgenos, garantías (sin dar correo: las lleva el responsable en la aplicación), estado de envíos, tótems, dominios, y lo que ejecuta el equipo (rueda de menú de un buffet, logos, plantillas, dar de alta etiquetas nuevas en la plataforma).</span></div></li>
        <li><div><b>Lo gestiona el hotel, la IA explica cómo:</b> <span>altas de usuarios, permisos y roles (Ajustes &gt; Roles, Usuarios), posiciones, nombres de etiquetas y forzar el menú. Solo se deriva si nadie en el hotel puede.</span></div></li>
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
