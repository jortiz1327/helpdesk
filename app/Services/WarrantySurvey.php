<?php

namespace App\Services;

use App\Models\Warranty;
use App\Models\WarrantyDocument;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;

/**
 * Encuesta al cerrar: una pregunta de 1 a 5 al pie del correo de resolución.
 * Cada número es un enlace firmado a la página de la encuesta; el GET solo marca la nota
 * elegida y la guarda el clic en la página (POST). Así los antivirus del correo, que abren
 * todos los enlaces, no votan por el cliente. Mismo criterio que la valoración de tickets.
 */
class WarrantySurvey
{
    public const DIAS = 60;   // lo que vale el enlace
    public const DEFAULTS = [
        'active'   => true,
        'question' => '¿Qué tal ha ido tu garantía con nosotros?',
        'intro'    => 'Nos encantaría saber tu opinión. Es solo un clic y nos ayuda muchísimo a mejorar nuestros productos y nuestro servicio.',
        'thanks'   => '¡Muchas gracias! Leemos cada respuesta y la tenemos muy en cuenta para seguir mejorando.',
    ];
    public const ESCALA = [1 => 'Nada contento', 2 => 'Poco contento', 3 => 'Normal', 4 => 'Contento', 5 => 'Encantado'];
    private const COLOR = [1 => '#dc2626', 2 => '#ea580c', 3 => '#ca8a04', 4 => '#65a30d', 5 => '#16a34a'];

    public static function settings(): array
    {
        return array_replace(self::DEFAULTS, array_intersect_key(WarrantyDocument::setting('survey'), self::DEFAULTS));
    }

    /** Lo de Configuración, validado. */
    public static function clean(array $in): array
    {
        $t = fn ($k, $max) => mb_substr(trim((string) ($in[$k] ?? '')), 0, $max) ?: self::DEFAULTS[$k];
        return ['active' => (bool) ($in['active'] ?? true), 'question' => $t('question', 160), 'intro' => $t('intro', 400), 'thanks' => $t('thanks', 300)];
    }

    /** Enlace firmado a la encuesta (con la nota marcada si se da). */
    public static function url(int $warrantyId, ?int $score = null): string
    {
        $p = ['warranty' => $warrantyId] + ($score ? ['score' => $score] : []);
        return rtrim((string) config('app.url'), '/') . URL::signedRoute('warranty.rate', $p, now()->addDays(self::DIAS), false);
    }

    /** El bloque de la encuesta para el pie del correo (HTML apto para correo, con tablas). */
    public static function emailBlock(Warranty $w): string
    {
        $s = self::settings();
        $celdas = '';
        foreach (self::ESCALA as $n => $l) {
            $celdas .= '<td style="padding:0 4px;"><a href="' . e(self::url($w->id, $n)) . '" title="' . e($l) . '" '
                . 'style="display:inline-block;width:40px;height:40px;line-height:40px;border-radius:50%;text-align:center;text-decoration:none;'
                . 'font:700 17px Arial,sans-serif;color:#fff;background:' . self::COLOR[$n] . ';">' . $n . '</a></td>';
        }
        return '<table role="presentation" cellpadding="0" cellspacing="0" style="margin:22px 0 4px;border-collapse:separate;width:100%;max-width:480px;'
            . 'background:#f5f8fc;border:1px solid #e3e8f0;border-radius:14px;"><tr><td style="padding:18px 20px;font-family:Arial,sans-serif;">'
            . '<div style="font-size:16px;font-weight:700;color:#101a2c;margin:0 0 4px;">' . e($s['question']) . '</div>'
            . '<div style="font-size:13px;line-height:1.5;color:#4a5a72;margin:0 0 14px;">' . e($s['intro']) . '</div>'
            . '<table role="presentation" cellpadding="0" cellspacing="0"><tr>' . $celdas . '</tr></table>'
            . '<div style="font-size:12px;color:#8494a8;margin-top:8px;">1 = nada contento · 5 = encantado</div>'
            . '</td></tr></table>';
    }

    /** Guarda la nota y/o el comentario. [ok, error]. */
    public static function save(int $warrantyId, ?int $score, ?string $comment): array
    {
        $w = Warranty::find($warrantyId);
        if (! $w) return [false, 'Garantía no encontrada'];
        $antes = DB::table('warranty_ratings')->where('warranty_id', $warrantyId)->first();
        if ($score !== null && ($score < 1 || $score > 5)) return [false, 'Elige un número del 1 al 5'];
        if (! $antes && $score === null) return [false, 'Primero elige un número del 1 al 5'];

        $fila = [];
        if ($score !== null && (int) ($antes->score ?? 0) !== $score) $fila['score'] = $score;
        if ($comment !== null) {
            $comment = mb_substr(trim($comment), 0, 2000);
            if ($comment !== (string) ($antes->comment ?? '')) $fila['comment'] = $comment !== '' ? $comment : null;
        }
        if (! $fila) return [true, null];

        if ($antes) DB::table('warranty_ratings')->where('warranty_id', $warrantyId)->update($fila + ['updated_at' => now()]);
        else DB::table('warranty_ratings')->insert($fila + ['warranty_id' => $warrantyId, 'comment' => null, 'created_at' => now(), 'updated_at' => now()]);

        if (isset($fila['score'])) WarrantyDocuments::evento($w->id, null, "El cliente valoró la garantía en la encuesta: {$score} de 5 (" . self::ESCALA[$score] . ')');
        if (! empty($fila['comment'])) WarrantyDocuments::evento($w->id, null, 'Comentario del cliente en la encuesta: «' . mb_substr($fila['comment'], 0, 400) . '»');
        return [true, null];
    }

    /** Nota de una garantía para la ficha, o null. */
    public static function forWarranty(int $warrantyId): ?array
    {
        $r = DB::table('warranty_ratings')->where('warranty_id', $warrantyId)->first(['score', 'comment', 'updated_at']);
        return $r ? ['score' => (int) $r->score, 'label' => self::ESCALA[(int) $r->score] ?? '', 'comment' => $r->comment, 'at' => (string) $r->updated_at] : null;
    }

    /** Resumen para Estadísticas: media, reparto, por mes y los comentarios. */
    public static function report(?string $from = null, ?string $to = null): array
    {
        $q = DB::table('warranty_ratings as r')->join('warranties as w', 'w.id', '=', 'r.warranty_id');
        if ($from) $q->where('r.created_at', '>=', $from);
        if ($to) $q->where('r.created_at', '<=', $to . ' 23:59:59');
        $filas = $q->orderByDesc('r.updated_at')->get(['r.score', 'r.comment', 'r.created_at', 'r.updated_at', 'w.id', 'w.code', 'w.client_name']);
        $enviadas = DB::table('warranty_events')->where('field', 'documents')->where('to_value', 'like', 'Correo de resolución enviado%la encuesta)')
            ->when($from, fn ($e) => $e->where('created_at', '>=', $from))->when($to, fn ($e) => $e->where('created_at', '<=', $to . ' 23:59:59'))
            ->distinct()->count('warranty_id');
        $n = $filas->count();
        return [
            'kpis' => ['answers' => $n, 'avg' => $n ? round($filas->avg('score'), 2) : null, 'sent' => $enviadas,
                'rate' => $enviadas ? round(min(1, $n / $enviadas), 4) : null,
                'happy' => $n ? round($filas->where('score', '>=', 4)->count() / $n, 4) : null,
                'unhappy' => $filas->where('score', '<=', 2)->count()],
            'scores' => array_map(fn ($k) => ['score' => $k, 'label' => self::ESCALA[$k], 'color' => self::COLOR[$k], 'n' => $filas->where('score', $k)->count()], array_keys(self::ESCALA)),
            'months' => $filas->groupBy(fn ($r) => substr((string) $r->created_at, 0, 7))->sortKeys()
                ->map(fn ($g, $m) => ['month' => $m, 'n' => $g->count(), 'avg' => round($g->avg('score'), 2),
                    'by' => collect(self::ESCALA)->keys()->mapWithKeys(fn ($k) => ["s{$k}" => $g->where('score', $k)->count()])->all()])
                ->values()->slice(-18)->values()->all(),
            'comments' => $filas->filter(fn ($r) => trim((string) $r->comment) !== '')->take(30)
                ->map(fn ($r) => ['id' => $r->id, 'code' => $r->code, 'client' => $r->client_name, 'score' => (int) $r->score, 'comment' => $r->comment,
                    'at' => substr((string) $r->updated_at, 0, 10)])->values()->all(),
        ];
    }

    /** Página pública de la encuesta (autocontenida). */
    public static function page(Warranty $w, int $nota, int $pintar, string $comment, bool $comentado, ?string $aviso): string
    {
        $s = self::settings();
        $accion = e(URL::signedRoute('warranty.rate', ['warranty' => $w->id], now()->addDays(self::DIAS), false));
        $botones = '';
        foreach (self::ESCALA as $n => $l) {
            $on = $n === $pintar;
            $botones .= '<form method="post" action="' . $accion . '" style="margin:0;"><input type="hidden" name="score" value="' . $n . '">'
                . '<button type="submit" title="' . e($l) . '" style="width:54px;height:54px;border-radius:50%;border:3px solid ' . ($on ? '#101a2c' : 'transparent') . ';'
                . 'background:' . self::COLOR[$n] . ';color:#fff;font:700 20px system-ui,sans-serif;cursor:pointer;opacity:' . ($pintar && ! $on ? '.45' : '1') . ';">' . $n . '</button>'
                . '<div style="font-size:11px;color:#6b7a90;margin-top:5px;">' . e($l) . '</div></form>';
        }
        $c = e($w->code);
        $titulo = $nota ? e($s['thanks']) : e($s['question']);
        $sub = $nota ? 'Tu nota: <b>' . $nota . ' de 5</b>. Puedes cambiarla cuando quieras.' : e($s['intro']);
        $cta = $nota ? '' : ($pintar ? '<p style="font-size:13px;color:#2563eb;margin:12px 0 0;font-weight:600;">Pulsa otra vez el ' . $pintar . ' para confirmar tu nota.</p>'
            : '<p style="font-size:13px;color:#4a5a72;margin:12px 0 0;">Pulsa un número para enviar tu nota.</p>');
        $avisoH = $aviso ? '<p style="color:#c0392b;font-size:13px;margin:10px 0 0;">' . e($aviso) . '</p>' : '';
        $ok = $comentado && ! $aviso ? '<p style="color:#0f9d6b;font-size:13px;margin:8px 0 0;">¡Comentario recibido, gracias!</p>' : '';
        $com = e($comment);
        $coment = $nota ? <<<HTML
  <form method="post" action="$accion" style="margin-top:20px;text-align:left;">
    <label style="display:block;font-size:13px;color:#4a5a72;margin-bottom:6px;">¿Hay algo que podamos hacer mejor? (opcional)</label>
    <textarea name="comment" rows="3" maxlength="2000" style="width:100%;box-sizing:border-box;padding:10px;border:1px solid #e3e8f0;border-radius:10px;font:inherit;font-size:14px;">$com</textarea>
    <button type="submit" style="margin-top:10px;width:100%;padding:11px;border:0;border-radius:10px;background:#2563eb;color:#fff;font-size:14px;font-weight:600;cursor:pointer;">Enviar comentario</button>
  </form>
HTML : '';
        return <<<HTML
<!doctype html><html lang="es"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex"><title>Tu opinión · $c</title></head>
<body style="margin:0;background:#eef2f7;font-family:system-ui,-apple-system,'Segoe UI',Roboto,sans-serif;color:#101a2c;">
<div style="max-width:480px;margin:8vh auto;background:#fff;border-radius:18px;box-shadow:0 8px 30px rgba(16,26,44,.1);padding:30px 24px;text-align:center;">
  <div style="font-size:13px;color:#8494a8;">Garantía $c</div>
  <h2 style="margin:6px 0 8px;font-size:21px;line-height:1.3;">$titulo</h2>
  <p style="margin:0 0 18px;font-size:14px;line-height:1.5;color:#4a5a72;">$sub</p>
  <div style="display:flex;justify-content:center;gap:8px;flex-wrap:wrap;">$botones</div>
  $cta
  $avisoH
  $ok
  $coment
  <div style="margin-top:20px;font-size:12px;color:#8494a8;">Servicio técnico · AEME Group</div>
</div></body></html>
HTML;
    }
}
