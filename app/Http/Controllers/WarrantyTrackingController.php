<?php

namespace App\Http\Controllers;

use App\Models\Warranty;
use App\Services\WarrantyTracking;
use Illuminate\Http\Request;

/**
 * Página pública del seguimiento (/seguimiento/<clave>): sin login, solo lectura. Si la
 * clave no existe, se renovó o ya caducó, se dice que el enlace no está disponible (sin
 * distinguir el motivo: no da pistas de qué claves existen).
 */
class WarrantyTrackingController extends Controller
{
    public function page(Request $request, string $token)
    {
        $w = Warranty::where('track_token', $token)->first();
        $exp = $w ? WarrantyTracking::expiresAt($w) : null;
        if (! $w || ! WarrantyTracking::settings()['active'] || ($exp && $exp->isPast())) {
            return $this->html(self::noDisponible(), 404);
        }
        // ?previa=1: el agente mirando lo que ve el cliente; no cuenta como visita.
        if (! $request->boolean('previa')) WarrantyTracking::visto($w, $request->userAgent());
        return $this->html(self::pagina(WarrantyTracking::view($w)));
    }

    private function html(string $html, int $status = 200)
    {
        return response($html, $status)->header('Content-Type', 'text/html; charset=utf-8')
            ->header('X-Robots-Tag', 'noindex, nofollow')->header('Cache-Control', 'no-store')
            ->header('Referrer-Policy', 'no-referrer');
    }

    private static function fecha(?string $d): string
    {
        return $d ? implode('/', array_reverse(explode('-', substr($d, 0, 10)))) : '';
    }

    private static function pagina(array $v): string
    {
        $c = e($v['code']);
        $st = $v['stage'];
        $atencion = $st['key'] === 'tu_respuesta';

        $pasos = '';
        $n = count($v['steps']);
        foreach ($v['steps'] as $i => $p) {
            $ult = $i === $n - 1;
            $punto = match ($p['state']) {
                'done' => '<span class="dot done"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M9 16.2 4.8 12l-1.4 1.4L9 19 21 7l-1.4-1.4z"/></svg></span>',
                'current' => '<span class="dot cur' . ($atencion ? ' warn' : '') . '"><i></i></span>',
                default => '<span class="dot"></span>',
            };
            $cuando = $p['at'] ? '<small>' . ($p['state'] === 'current' ? 'Desde el ' : '') . self::fecha($p['at']) . '</small>' : '';
            $pasos .= '<li class="' . $p['state'] . ($ult ? ' last' : '') . '">' . $punto . '<div><b>' . e($p['label']) . '</b>'
                . ($p['state'] === 'current' ? '<em>' . ($atencion ? 'Esperando tu respuesta' : 'Ahora') . '</em>' : '') . $cuando . '</div></li>';
        }

        $k = $v['counts'];
        $cifra = fn ($num, $txt, $cls = '') => '<div class="num ' . $cls . '"><b>' . $num . '</b><span>' . $txt . '</span></div>';
        $cifras = $k['total']
            ? $cifra($k['total'], $k['total'] === 1 ? 'dispositivo' : 'dispositivos')
              . $cifra($k['reviewed'], $k['reviewed'] === 1 ? 'revisado' : 'revisados', 'ok')
              . ($k['pending'] && ! $v['closed'] ? $cifra($k['pending'], 'por revisar') : '')
              . ($k['at_factory'] ? $cifra($k['at_factory'], 'en el fabricante', 'fab') : '')
            : '';
        $bloqueCifras = $cifras ? '<section class="card"><h2>Tus dispositivos</h2><div class="nums">' . $cifras . '</div></section>'
            : '<section class="card"><h2>Tus dispositivos</h2><p class="mute">Todavía no los hemos registrado. Aparecerán aquí en cuanto empecemos a revisarlos.</p></section>';

        $aviso = $atencion ? '<div class="alert"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M1 21h22L12 2 1 21zm12-3h-2v-2h2v2zm0-4h-2v-4h2v4z"/></svg><span>' . e($st['text']) . '</span></div>' : '';
        $texto = $atencion ? '' : '<p class="lead">' . e($st['text']) . '</p>';
        $desde = $v['closed'] ? '' : '<p class="mute small">En esta fase desde el ' . self::fecha($v['since']) . '.</p>';
        $titulo = e($st['label']);

        return <<<HTML
<!doctype html><html lang="es"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow">
<meta name="referrer" content="no-referrer"><meta name="color-scheme" content="light">
<title>Garantía $c · $titulo</title>
<style>
:root { --ink:#101a2c; --ink2:#4a5a72; --ink3:#6b7a90; --line:#e3e8f0; --bg:#eef2f7; --pri:#2563eb; --ok:#0f9d6b; --warn:#b45309; --warnbg:#fff7e6; }
* { box-sizing:border-box; }
body { margin:0; background:var(--bg); color:var(--ink); font:15px/1.5 system-ui,-apple-system,'Segoe UI',Roboto,sans-serif; -webkit-text-size-adjust:100%; }
main { max-width:520px; margin:0 auto; padding:24px 16px 40px; }
.top { display:flex; align-items:center; justify-content:space-between; gap:12px; color:var(--ink3); font-size:13px; margin-bottom:14px; }
.top b { font:600 13px ui-monospace,'Cascadia Code',Menlo,Consolas,monospace; color:var(--ink2); background:#fff; border:1px solid var(--line); border-radius:8px; padding:3px 8px; }
.card { background:#fff; border-radius:16px; box-shadow:0 1px 2px rgba(16,26,44,.05),0 8px 24px rgba(16,26,44,.06); padding:22px 20px; margin-bottom:14px; }
.hero small { display:block; font-size:12px; font-weight:600; letter-spacing:.06em; text-transform:uppercase; color:var(--ink3); }
h1 { margin:4px 0 6px; font-size:26px; line-height:1.2; letter-spacing:-.01em; text-wrap:balance; }
h2 { margin:0 0 12px; font-size:13px; font-weight:600; letter-spacing:.04em; text-transform:uppercase; color:var(--ink3); }
.lead { margin:0; color:var(--ink2); }
.mute { color:var(--ink3); margin:0; } .small { font-size:13px; margin-top:12px; }
.alert { display:flex; gap:10px; align-items:flex-start; margin-top:12px; padding:12px 14px; border-radius:12px; background:var(--warnbg); color:var(--warn); font-weight:600; }
.alert svg { flex:none; width:20px; height:20px; fill:currentColor; margin-top:1px; }
ol { list-style:none; margin:0; padding:0; }
li { position:relative; display:flex; gap:14px; padding-bottom:18px; }
li.last { padding-bottom:0; }
li:not(.last)::before { content:''; position:absolute; left:11px; top:26px; bottom:2px; width:2px; background:var(--line); }
li.done:not(.last)::before { background:var(--ok); }
li div { display:flex; flex-direction:column; min-width:0; padding-top:1px; }
li b { font-weight:600; } li.todo b { color:var(--ink3); font-weight:500; }
li em { font-style:normal; font-size:12px; font-weight:700; color:var(--pri); }
li small { font-size:13px; color:var(--ink3); font-variant-numeric:tabular-nums; }
.dot { flex:none; position:relative; z-index:1; width:24px; height:24px; border-radius:50%; background:#fff; border:2px solid var(--line); display:grid; place-items:center; }
.dot.done { background:var(--ok); border-color:var(--ok); } .dot.done svg { width:15px; height:15px; fill:#fff; }
.dot.cur { border-color:var(--pri); box-shadow:0 0 0 4px rgba(37,99,235,.14); } .dot.cur i { width:10px; height:10px; border-radius:50%; background:var(--pri); }
.dot.cur.warn { border-color:var(--warn); box-shadow:0 0 0 4px rgba(180,83,9,.14); } .dot.cur.warn i { background:var(--warn); }
.nums { display:grid; grid-template-columns:repeat(auto-fit,minmax(104px,1fr)); gap:10px; }
.num { border:1px solid var(--line); border-radius:12px; padding:12px; }
.num b { display:block; font-size:26px; line-height:1.1; font-variant-numeric:tabular-nums; }
.num span { font-size:13px; color:var(--ink2); }
.num.ok b { color:var(--ok); } .num.fab b { color:var(--pri); }
footer { text-align:center; color:var(--ink3); font-size:13px; padding:6px 8px; }
footer p { margin:0 0 6px; }
@media (prefers-reduced-motion:no-preference) { .card { animation:in .35s cubic-bezier(.16,1,.3,1); } @keyframes in { from { opacity:0; transform:translateY(6px); } } }
</style></head>
<body><main>
  <div class="top"><span>Seguimiento de tu garantía</span><b>$c</b></div>
  <section class="card hero"><small>Estado actual</small><h1>$titulo</h1>$texto$aviso$desde</section>
  <section class="card"><h2>Recorrido</h2><ol>$pasos</ol></section>
  $bloqueCifras
  <footer><p>¿Dudas? Responde al correo o al WhatsApp de tu incidencia indicando el número <b>$c</b>.</p><p>Servicio técnico · AEME Group</p></footer>
</main></body></html>
HTML;
    }

    private static function noDisponible(): string
    {
        return <<<HTML
<!doctype html><html lang="es"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><title>Enlace no disponible</title></head>
<body style="margin:0;background:#eef2f7;font:15px/1.5 system-ui,-apple-system,'Segoe UI',Roboto,sans-serif;color:#101a2c;">
<main style="max-width:480px;margin:10vh auto;padding:0 16px;"><div style="background:#fff;border-radius:16px;box-shadow:0 8px 24px rgba(16,26,44,.08);padding:28px 22px;text-align:center;">
  <h1 style="margin:0 0 8px;font-size:21px;">Este enlace ya no está disponible</h1>
  <p style="margin:0;color:#4a5a72;">Puede que haya caducado o que te hayamos mandado uno nuevo. Si quieres saber cómo va tu garantía, responde al correo o al WhatsApp de tu incidencia y te lo contamos.</p>
  <p style="margin:18px 0 0;font-size:13px;color:#6b7a90;">Servicio técnico · AEME Group</p>
</div></main></body></html>
HTML;
    }
}
