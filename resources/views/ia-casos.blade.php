<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="csrf-token" content="{{ csrf_token() }}">
<title>Revisión de casos de la IA</title>
<style>
  :root {
    --bg: #f4f5f7; --panel: #ffffff; --ink: #1c2230; --muted: #667085; --line: #e3e6ec; --accent: #2f5bea; --accent-ink: #ffffff;
    --c-responder: #1f8a4c; --c-pedir_info: #b7791f; --c-derivar: #c0392b; --c-descartar: #7b8190;
    --client: #eef3ff; --human: #f1f8f3; --ia: #fbf6ec;
  }
  @media (prefers-color-scheme: dark) {
    :root:not([data-theme="light"]) {
      --bg: #12151c; --panel: #1b2029; --ink: #e8ebf2; --muted: #9aa3b5; --line: #2a303c; --accent: #6f93ff; --accent-ink: #0d1220;
      --c-responder: #4fc883; --c-pedir_info: #e6b04c; --c-derivar: #ee7d70; --c-descartar: #9aa3b5;
      --client: #1c2742; --human: #17291f; --ia: #2b2415;
    }
  }
  * { box-sizing: border-box; }
  html, body { height: 100%; margin: 0; }
  body { background: var(--bg); color: var(--ink); font: 16px/1.55 system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; }
  button { font: inherit; color: inherit; }
  .app { display: grid; grid-template-columns: 300px minmax(0, 1fr); grid-template-rows: minmax(0, 1fr); height: 100dvh; overflow: hidden; }
  aside { background: var(--panel); border-right: 1px solid var(--line); display: flex; flex-direction: column; min-width: 0; }
  aside header { padding: 16px; border-bottom: 1px solid var(--line); }
  aside h1 { font-size: 15px; margin: 0 0 2px; }
  aside p { margin: 0 0 10px; color: var(--muted); font-size: 12.5px; }
  .bar { height: 6px; background: var(--line); border-radius: 99px; overflow: hidden; margin: 8px 0 4px; }
  .bar i { display: block; height: 100%; background: var(--accent); width: 0; transition: width .25s ease-out; }
  select { font: inherit; width: 100%; padding: 7px 10px; border: 1px solid var(--line); border-radius: 8px; background: var(--panel); color: var(--ink); }
  .list { overflow: auto; flex: 1; padding: 8px; }
  .item { display: flex; align-items: center; gap: 9px; width: 100%; text-align: left; border: 0; background: none; padding: 8px 10px; border-radius: 8px; cursor: pointer; }
  .item:hover { background: var(--bg); }
  .item[aria-current="true"] { background: var(--bg); box-shadow: inset 3px 0 0 var(--accent); font-weight: 600; }
  .item .dot { width: 10px; height: 10px; border-radius: 50%; flex: none; }
  .item .t { flex: 1; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; font-size: 14px; }
  .item .ck { color: var(--c-responder); font-size: 13px; width: 14px; flex: none; }
  .item .df { font-size: 11px; color: var(--c-pedir_info); flex: none; }
  main { display: flex; flex-direction: column; min-width: 0; min-height: 0; }
  .top { padding: 12px 24px; border-bottom: 1px solid var(--line); background: var(--panel); display: flex; align-items: center; gap: 12px; }
  .top h2 { margin: 0; font-size: 15px; flex: 1; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
  .top small { color: var(--muted); }
  .scroll { flex: 1; overflow: auto; padding: 22px 24px; }
  .col { max-width: 820px; margin: 0 auto; display: flex; flex-direction: column; gap: 16px; }
  .blk { border-radius: 12px; padding: 14px 18px; border-left: 5px solid var(--line); }
  .blk h3 { margin: 0 0 6px; font-size: 12px; letter-spacing: .05em; text-transform: uppercase; color: var(--muted); display: flex; gap: 10px; align-items: center; }
  .blk .txt { white-space: pre-wrap; overflow-wrap: anywhere; font-size: 16px; }
  .client { background: var(--client); border-color: var(--accent); }
  .human { background: var(--human); border-color: var(--c-responder); }
  .ia { background: var(--ia); border-color: var(--c-pedir_info); }
  .badge { font-size: 11px; padding: 2px 8px; border-radius: 99px; color: #fff; text-transform: none; letter-spacing: 0; }
  .decide { display: grid; grid-template-columns: repeat(4, 1fr); gap: 10px; }
  .dbtn { border: 2px solid var(--line); background: var(--panel); border-radius: 12px; padding: 10px 12px; cursor: pointer; text-align: left; transition: transform .12s ease-out, border-color .12s; }
  .dbtn:hover { border-color: var(--muted); }
  .dbtn:active { transform: scale(.97); }
  .dbtn b { display: block; font-size: 15px; }
  .dbtn small { display: block; color: var(--muted); font-size: 12px; line-height: 1.3; margin-top: 2px; }
  .dbtn kbd { float: right; font: 12px ui-monospace, monospace; border: 1px solid var(--line); border-radius: 5px; padding: 0 6px; color: var(--muted); }
  .dbtn[aria-pressed="true"] { border-color: var(--k); background: color-mix(in srgb, var(--k) 12%, var(--panel)); }
  .dbtn[aria-pressed="true"] b { color: var(--k); }
  textarea { font: inherit; color: var(--ink); background: var(--panel); border: 1px solid var(--line); border-radius: 10px; padding: 9px 12px; width: 100%; resize: vertical; }
  textarea:focus { outline: 2px solid color-mix(in srgb, var(--accent) 45%, transparent); outline-offset: 1px; }
  .foot { border-top: 1px solid var(--line); background: var(--panel); padding: 12px 24px; }
  .foot .col { flex-direction: row; align-items: center; gap: 10px; }
  .btn { border: 1px solid var(--line); background: var(--panel); padding: 8px 14px; border-radius: 9px; cursor: pointer; transition: transform .12s ease-out; }
  .btn:hover { background: var(--bg); } .btn:active { transform: scale(.97); } .btn:disabled { opacity: .45; cursor: default; }
  .btn.primary { background: var(--accent); color: var(--accent-ink); border-color: var(--accent); font-weight: 600; }
  .grow { flex: 1; color: var(--muted); font-size: 13px; }
  .hint { color: var(--muted); font-size: 12.5px; }
  .empty { margin: auto; text-align: center; color: var(--muted); max-width: 420px; padding: 40px 20px; }
  @media (prefers-reduced-motion: reduce) { .btn, .dbtn, .bar i { transition: none; } }
  @media (max-width: 860px) { .app { grid-template-columns: minmax(0, 1fr); grid-template-rows: auto minmax(0, 1fr); } aside { max-height: 34dvh; border-right: 0; border-bottom: 1px solid var(--line); } .decide { grid-template-columns: 1fr 1fr; } }
</style>
</head>
<body>
<div class="app">
  <aside>
    <header>
      <h1>Casos de prueba de la IA</h1>
      <p>Solo local · se guarda al instante · nada se envía</p>
      <div class="bar"><i id="barra"></i></div>
      <p id="prog" style="margin-bottom:10px"></p>
      <select id="filtro" aria-label="Filtrar casos">
        <option value="todos">Todos los casos</option>
        <option value="pend">Sin revisar</option>
        <option value="dif">Donde la IA no coincide</option>
        <option value="desc">Descartados</option>
      </select>
    </header>
    <div class="list" id="lista" role="list"></div>
  </aside>
  <main>
    <div class="top"><h2 id="titulo">Cargando…</h2><small id="meta"></small></div>
    <div class="scroll" id="scroll"><div class="col" id="caso"></div></div>
    <div class="foot"><div class="col">
      <button class="btn" id="ant">← Anterior</button>
      <span class="grow" id="estado">Teclas: 1 responder · 2 pedir dato · 3 derivar · 4 descartar · Enter confirma y sigue · ← → mover</span>
      <button class="btn primary" id="sig">Confirmar y siguiente ↵</button>
    </div></div>
  </main>
</div>
<script src="/ia/casos/app.js"></script>
</body>
</html>
