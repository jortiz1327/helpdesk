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

  /* Tres zonas: lista | lectura | decisión. Cada una con su propio scroll; la decisión siempre a la vista. */
  .app { display: grid; grid-template-columns: 270px minmax(0, 1fr); grid-template-rows: minmax(0, 1fr); height: 100dvh; overflow: hidden; }
  aside.lista { background: var(--panel); border-right: 1px solid var(--line); display: flex; flex-direction: column; min-width: 0; min-height: 0; }
  aside.lista header { padding: 16px; border-bottom: 1px solid var(--line); }
  aside.lista h1 { font-size: 15px; margin: 0 0 2px; }
  aside.lista p { margin: 0 0 10px; color: var(--muted); font-size: 12.5px; }
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
  .top { padding: 12px 24px; border-bottom: 1px solid var(--line); background: var(--panel); display: flex; align-items: baseline; gap: 12px; }
  .top h2 { margin: 0; font-size: 16px; flex: 1; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
  .top small { color: var(--muted); }
  .work { flex: 1; min-height: 0; display: grid; grid-template-columns: minmax(0, 1fr) 380px; }
  .read { overflow: auto; padding: 22px 24px 40px; min-height: 0; }
  .col { max-width: 760px; margin: 0 auto; display: flex; flex-direction: column; gap: 14px; }

  .blk { border-radius: 14px; padding: 14px 18px; border-left: 5px solid var(--line); }
  .blk .bh { all: unset; box-sizing: border-box; display: flex; width: 100%; gap: 10px; align-items: center; cursor: pointer; font-size: 12px; letter-spacing: .05em; text-transform: uppercase; color: var(--muted); margin-bottom: 6px; }
  .blk .bh:hover { color: var(--ink); }
  .blk .bh:focus-visible { outline: 2px solid var(--accent); outline-offset: 3px; border-radius: 4px; }
  .blk .bh .chev { width: 12px; flex: none; }
  .blk .bh kbd { margin-left: auto; font: 11px ui-monospace, monospace; border: 1px solid var(--line); border-radius: 5px; padding: 0 6px; text-transform: none; }
  .blk .txt { white-space: pre-wrap; overflow-wrap: anywhere; font-size: 16px; }
  .blk .prev { display: none; color: var(--muted); font-size: 14px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
  .blk.min .txt { display: none; }
  .blk.min .prev { display: block; }
  .blk.min .bh { margin-bottom: 2px; }
  .client { background: var(--client); border-color: var(--accent); }
  .human { background: var(--human); border-color: var(--c-responder); }
  .ia { background: var(--ia); border-color: var(--c-pedir_info); }
  .badge { font-size: 11px; padding: 2px 8px; border-radius: 99px; color: #fff; text-transform: none; letter-spacing: 0; }
  .empty { margin: 40px auto; text-align: center; color: var(--muted); max-width: 420px; }

  /* Panel de decisión: siempre visible, sin scroll para decidir ni para escribir. */
  .panel { border-left: 1px solid var(--line); background: var(--panel); display: flex; flex-direction: column; gap: 14px; padding: 18px; min-height: 0; overflow: auto; }
  .panel h3 { margin: 0; font-size: 12px; letter-spacing: .05em; text-transform: uppercase; color: var(--muted); }
  .decide { display: flex; flex-direction: column; gap: 8px; }
  .dbtn { display: grid; grid-template-columns: 22px 1fr auto; align-items: center; gap: 2px 10px; text-align: left; border: 2px solid var(--line); background: var(--bg); border-radius: 12px; padding: 10px 12px; cursor: pointer; transition: transform .12s ease-out, border-color .12s, background .12s; }
  .dbtn:hover { border-color: var(--muted); }
  .dbtn:active { transform: scale(.98); }
  .dbtn i { grid-row: 1 / 3; width: 14px; height: 14px; border-radius: 50%; background: var(--k); opacity: .55; justify-self: center; }
  .dbtn b { font-size: 15px; }
  .dbtn small { grid-column: 2; color: var(--muted); font-size: 12.5px; line-height: 1.3; }
  .dbtn kbd { grid-row: 1 / 3; grid-column: 3; font: 12px ui-monospace, monospace; border: 1px solid var(--line); border-radius: 5px; padding: 0 7px; color: var(--muted); background: var(--panel); }
  .dbtn[aria-pressed="true"] { border-color: var(--k); background: color-mix(in srgb, var(--k) 14%, var(--panel)); }
  .dbtn[aria-pressed="true"] i { opacity: 1; box-shadow: 0 0 0 4px color-mix(in srgb, var(--k) 25%, transparent); }
  .dbtn[aria-pressed="true"] b { color: var(--k); }
  .note { display: flex; flex-direction: column; gap: 6px; flex: 1; min-height: 180px; }
  .note label { font-size: 12px; letter-spacing: .05em; text-transform: uppercase; color: var(--muted); display: flex; justify-content: space-between; }
  .note label kbd { font: 11px ui-monospace, monospace; border: 1px solid var(--line); border-radius: 5px; padding: 0 6px; text-transform: none; letter-spacing: 0; }
  textarea { font: inherit; color: var(--ink); background: var(--bg); border: 1px solid var(--line); border-radius: 12px; padding: 12px 14px; width: 100%; flex: 1; min-height: 150px; resize: none; line-height: 1.5; }
  textarea:focus { outline: 2px solid color-mix(in srgb, var(--accent) 50%, transparent); outline-offset: 1px; background: var(--panel); }
  .nav { display: flex; gap: 8px; align-items: center; }
  .estado { color: var(--muted); font-size: 12.5px; min-height: 18px; }
  .btn { border: 1px solid var(--line); background: var(--panel); padding: 9px 14px; border-radius: 10px; cursor: pointer; transition: transform .12s ease-out; }
  .btn:hover { background: var(--bg); } .btn:active { transform: scale(.97); } .btn:disabled { opacity: .45; cursor: default; }
  .btn.primary { background: var(--accent); color: var(--accent-ink); border-color: var(--accent); font-weight: 600; flex: 1; }
  .btn.primary:hover { filter: brightness(1.07); }
  .keys { color: var(--muted); font-size: 12px; line-height: 1.6; }
  .keys kbd { font: 11px ui-monospace, monospace; border: 1px solid var(--line); border-radius: 4px; padding: 0 5px; }

  @media (prefers-reduced-motion: reduce) { .btn, .dbtn, .bar i { transition: none; } }
  @media (max-width: 1100px) { .work { grid-template-columns: minmax(0, 1fr) 330px; } }
  @media (max-width: 860px) {
    .app { grid-template-columns: minmax(0, 1fr); grid-template-rows: auto minmax(0, 1fr); }
    aside.lista { max-height: 28dvh; border-right: 0; border-bottom: 1px solid var(--line); }
    .work { grid-template-columns: minmax(0, 1fr); grid-template-rows: minmax(0, 1fr) auto; }
    .panel { border-left: 0; border-top: 1px solid var(--line); max-height: 52dvh; }
    .note { min-height: 110px; }
  }
</style>
</head>
<body>
<div class="app">
  <aside class="lista">
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
    <div class="work">
      <div class="read" id="read"><div class="col" id="caso"></div></div>
      <aside class="panel" aria-label="Decisión">
        <h3>¿Qué debería hacer la IA aquí?</h3>
        <div class="decide" id="botones"></div>
        <div class="note">
          <label for="com">Tu comentario <kbd>N</kbd></label>
          <textarea id="com" placeholder="Opcional: por qué, o qué cambiarías (Ctrl+Enter guarda y pasa al siguiente)"></textarea>
        </div>
        <div class="estado" id="estado" role="status"></div>
        <div class="nav">
          <button class="btn" id="ant" title="Caso anterior (←)">←</button>
          <button class="btn primary" id="sig">Confirmar y siguiente ↵</button>
        </div>
        <div class="keys"><kbd>1</kbd>–<kbd>4</kbd> decidir · <kbd>Enter</kbd> confirmar · <kbd>←</kbd> <kbd>→</kbd> mover · <kbd>C</kbd> <kbd>H</kbd> <kbd>I</kbd> minimizar · <kbd>N</kbd> comentar · <kbd>Esc</kbd> salir del comentario</div>
      </aside>
    </div>
  </main>
</div>
<script src="/ia/casos/app.js"></script>
</body>
</html>
