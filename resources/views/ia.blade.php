<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="csrf-token" content="{{ csrf_token() }}">
<title>Chats de prueba de la IA</title>
<style>
  :root {
    --bg: #f4f5f7; --panel: #ffffff; --ink: #1c2230; --muted: #667085; --line: #e3e6ec;
    --accent: #2f5bea; --accent-ink: #ffffff; --user: #2f5bea; --user-ink: #ffffff;
    --bot: #ffffff; --warn: #fff4e0; --warn-ink: #7a4b00; --ok: #e8f6ee; --ok-ink: #14653a; --bad: #fdecec; --bad-ink: #8f1d1d;
  }
  @media (prefers-color-scheme: dark) {
    :root:not([data-theme="light"]) {
      --bg: #12151c; --panel: #1b2029; --ink: #e8ebf2; --muted: #9aa3b5; --line: #2a303c;
      --accent: #6f93ff; --accent-ink: #0d1220; --user: #3a63e0; --user-ink: #ffffff;
      --bot: #232a36; --warn: #3a2d12; --warn-ink: #f3cf8a; --ok: #15301f; --ok-ink: #8fdcae; --bad: #3a1b1b; --bad-ink: #f3a6a6;
    }
  }
  * { box-sizing: border-box; }
  html, body { height: 100%; margin: 0; }
  body { background: var(--bg); color: var(--ink); font: 15px/1.5 system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; }
  .app { display: grid; grid-template-columns: 290px 1fr; height: 100dvh; }
  aside { background: var(--panel); border-right: 1px solid var(--line); display: flex; flex-direction: column; min-width: 0; }
  aside header { padding: 16px; border-bottom: 1px solid var(--line); }
  aside h1 { font-size: 15px; margin: 0 0 2px; }
  aside p { margin: 0; color: var(--muted); font-size: 12.5px; }
  .list { overflow: auto; flex: 1; padding: 8px; }
  .item { display: block; width: 100%; text-align: left; border: 0; background: none; color: inherit; font: inherit; padding: 9px 10px; border-radius: 8px; cursor: pointer; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
  .item:hover { background: var(--bg); }
  .item[aria-current="true"] { background: var(--bg); font-weight: 600; box-shadow: inset 3px 0 0 var(--accent); }
  button { font: inherit; }
  .btn { border: 1px solid var(--line); background: var(--panel); color: var(--ink); padding: 7px 13px; border-radius: 8px; cursor: pointer; transition: transform .12s ease-out, background .12s; }
  .btn:hover { background: var(--bg); }
  .btn:active { transform: scale(.97); }
  .btn:disabled { opacity: .5; cursor: default; }
  .btn.primary { background: var(--accent); color: var(--accent-ink); border-color: var(--accent); font-weight: 600; }
  .btn.primary:hover { filter: brightness(1.06); }
  .btn.sm { padding: 4px 10px; font-size: 13px; }
  .btn.ghost { border-color: transparent; background: none; color: var(--muted); }
  .newbtn { width: 100%; margin-top: 12px; }
  .casos-link { display: block; width: 100%; margin-top: 8px; text-align: center; text-decoration: none; }
  main { display: flex; flex-direction: column; min-width: 0; min-height: 0; }
  .top { padding: 12px 20px; border-bottom: 1px solid var(--line); background: var(--panel); display: flex; align-items: center; gap: 12px; }
  .top h2 { margin: 0; font-size: 15px; flex: 1; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
  .top small { color: var(--muted); }
  .scroll { flex: 1; overflow: auto; padding: 22px 20px 8px; }
  .col { max-width: 760px; margin: 0 auto; display: flex; flex-direction: column; gap: 14px; }
  .row { display: flex; flex-direction: column; gap: 6px; }
  .row.user { align-items: flex-end; }
  .who { font-size: 11.5px; letter-spacing: .04em; text-transform: uppercase; color: var(--muted); }
  .bubble { padding: 11px 15px; border-radius: 16px; max-width: 88%; white-space: pre-wrap; overflow-wrap: anywhere; }
  .user .bubble { background: var(--user); color: var(--user-ink); border-bottom-right-radius: 5px; }
  .bot .bubble { background: var(--bot); border: 1px solid var(--line); border-bottom-left-radius: 5px; }
  .bot.old .bubble { opacity: .45; text-decoration: line-through; text-decoration-thickness: 1px; }
  .bubble.warn { background: var(--warn); color: var(--warn-ink); border-color: transparent; white-space: normal; }
  .bubble.bad { background: var(--bad); color: var(--bad-ink); border-color: transparent; white-space: normal; }
  .acts { display: flex; gap: 6px; flex-wrap: wrap; align-items: center; }
  .verdict { font-size: 13px; padding: 7px 11px; border-radius: 10px; max-width: 88%; }
  .verdict.up { background: var(--ok); color: var(--ok-ink); }
  .verdict.down { background: var(--bad); color: var(--bad-ink); }
  .fix { max-width: 88%; display: flex; flex-direction: column; gap: 8px; }
  .fix label { font-size: 13px; color: var(--muted); }
  textarea, input { font: inherit; color: var(--ink); background: var(--panel); border: 1px solid var(--line); border-radius: 10px; padding: 9px 12px; width: 100%; }
  textarea:focus, input:focus { outline: 2px solid color-mix(in srgb, var(--accent) 45%, transparent); outline-offset: 1px; }
  .composer { border-top: 1px solid var(--line); background: var(--panel); padding: 14px 20px 18px; }
  .composer .col { flex-direction: row; align-items: flex-end; gap: 10px; }
  .composer textarea { resize: none; max-height: 180px; }
  .typing { display: inline-flex; gap: 4px; padding: 6px 2px; }
  .typing i { width: 7px; height: 7px; border-radius: 50%; background: var(--muted); animation: b 1s infinite ease-in-out; }
  .typing i:nth-child(2) { animation-delay: .15s; } .typing i:nth-child(3) { animation-delay: .3s; }
  @keyframes b { 0%, 60%, 100% { opacity: .25; transform: translateY(0); } 30% { opacity: 1; transform: translateY(-3px); } }
  @media (prefers-reduced-motion: reduce) { .typing i { animation: none; opacity: .6; } .btn { transition: none; } }
  .empty { margin: auto; text-align: center; color: var(--muted); max-width: 420px; padding: 40px 20px; }
  .empty h3 { color: var(--ink); margin: 0 0 6px; }
  .newform { display: none; flex-direction: column; gap: 8px; margin-top: 12px; }
  .newform.open { display: flex; }
  .notice { background: var(--warn); color: var(--warn-ink); padding: 8px 20px; font-size: 13px; }
  @media (max-width: 760px) { .app { grid-template-columns: 1fr; grid-template-rows: auto 1fr; } aside { border-right: 0; border-bottom: 1px solid var(--line); max-height: 36dvh; } }
</style>
</head>
<body>
<div class="app">
  <aside>
    <header>
      <h1>Chats de prueba de la IA</h1>
      <p>Solo local · nada se envía a nadie</p>
      <button class="btn primary newbtn" id="nuevo">+ Nueva conversación</button>
      <a class="btn casos-link" href="/ia/casos">Revisar casos →</a>
      <form class="newform" id="form">
        <input id="n-name" placeholder="Nombre del cliente (opcional)" maxlength="120" autocomplete="off">
        <input id="n-email" type="email" placeholder="Correo del cliente (opcional, prueba la verificación del hotel)" maxlength="190" autocomplete="off">
        <button class="btn primary" type="submit">Empezar</button>
      </form>
    </header>
    <div class="list" id="lista" role="list"></div>
  </aside>

  <main>
    @unless ($configurada)
      <div class="notice">Falta la clave del proveedor de IA en el .env: el agente no podrá contestar.</div>
    @endunless
    <div class="top"><h2 id="titulo">Elige o empieza una conversación</h2><small id="meta"></small><button class="btn sm ghost" id="borrar" hidden>Borrar</button></div>
    <div class="scroll" id="scroll"><div class="col" id="msgs"><div class="empty"><h3>Escribe como si fueras el cliente</h3>El agente contesta con el mismo motor que en los tickets (prompt, guías, correcciones…). Valora cada respuesta: lo que corrijas queda en su memoria.</div></div></div>
    <div class="composer" id="composer" hidden>
      <div class="col">
        <textarea id="texto" rows="1" placeholder="Escribe el mensaje del cliente…  (Enter envía · Shift+Enter nueva línea)" aria-label="Mensaje del cliente"></textarea>
        <button class="btn primary" id="enviar">Enviar</button>
      </div>
    </div>
  </main>
</div>

<script src="/ia/app.js"></script>
</body>
</html>
