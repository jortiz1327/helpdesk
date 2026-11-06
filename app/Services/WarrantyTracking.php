<?php

namespace App\Services;

use App\Models\Warranty;
use App\Models\WarrantyDocument;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Seguimiento para el cliente: un enlace (/seguimiento/<clave>) que enseña en qué fase está
 * su garantía, sin entrar en ningún sitio. Ahorra los «¿cómo va lo mío?».
 *
 *  · La clave es aleatoria y va guardada en la garantía: «Nuevo enlace» la cambia y el
 *    enlace anterior deja de funcionar. Vale mientras la garantía está abierta y unos días
 *    después de cerrarse (Configuración).
 *  · El cliente no ve los estados internos: cada estado se traduce a una fase pensada para
 *    él (Configuración → Seguimiento del cliente), con su texto.
 *  · Solo cifras de los dispositivos (cuántos, revisados, en el fabricante); nunca notas,
 *    responsable, alertas, quién paga, importes ni datos del Cloud.
 *  · No manda nada: el enlace lo copia el agente y lo pega donde quiera.
 */
class WarrantyTracking
{
    // Fases que ve el cliente, en orden. [nombre, texto de serie]
    public const STAGES = [
        'recibida'     => ['Recibida', 'Hemos registrado tu garantía. En breve empezamos a revisar tus dispositivos.'],
        'revision'     => ['En revisión', 'Nuestro equipo técnico está revisando tus dispositivos.'],
        'tu_respuesta' => ['Necesitamos tu respuesta', 'Para seguir necesitamos algo de ti. Responde, por favor, al último mensaje que te hemos enviado.'],
        'fabricante'   => ['En el fabricante', 'Algunos dispositivos están en el fabricante para su diagnóstico o reparación. Puede tardar unas semanas.'],
        'envio'        => ['Preparando el envío', 'Estamos preparando el envío de tus dispositivos.'],
        'enviada'      => ['Enviada', 'Te hemos enviado tus dispositivos. Si en unos días no te ha llegado, avísanos.'],
        'resuelta'     => ['Resuelta', 'Tu garantía está resuelta. ¡Gracias por tu paciencia!'],
        'cerrada'      => ['Cerrada', 'Este expediente está cerrado. Si tienes cualquier duda, escríbenos.'],
    ];
    // Estado de la garantía → fase del cliente (de serie; los estados nuevos van a «En revisión»).
    public const MAP = [
        'no_iniciado' => 'recibida', 'en_curso' => 'revision', 'falta_stock' => 'revision',
        'pte_presupuesto' => 'tu_respuesta', 'pte_cliente' => 'tu_respuesta', 'rechazado_cliente' => 'revision',
        'pte_zkong' => 'fabricante', 'pte_envio' => 'envio', 'enviada' => 'enviada',
        'resuelta' => 'resuelta', 'cerrada' => 'resuelta', 'descartada' => 'cerrada',
    ];
    public const DIAS_TRAS_CIERRE = 30;
    // Fases que no son un paso de la barra: se dicen arriba y la barra sigue en «En revisión».
    private const AVISO = ['tu_respuesta' => 'revision'];
    // Fases que solo salen en la barra si la garantía ha pasado por ellas.
    private const SI_PASA = ['fabricante', 'envio', 'enviada'];

    /* ------------------------------------------------------------ Configuración */

    /** {active, days, stages: {key: {label, text}}, map: {estado: fase}} */
    public static function settings(): array
    {
        $v = WarrantyDocument::setting('tracking');
        $stages = [];
        foreach (self::STAGES as $k => [$l, $t]) {
            $c = (array) ($v['stages'][$k] ?? []);
            $stages[$k] = ['label' => trim((string) ($c['label'] ?? '')) ?: $l, 'text' => trim((string) ($c['text'] ?? '')) ?: $t];
        }
        $map = [];
        foreach (array_keys(Warranty::statusRows()) as $st) {
            $f = (string) ($v['map'][$st] ?? self::MAP[$st] ?? 'revision');
            $map[$st] = isset(self::STAGES[$f]) ? $f : 'revision';
        }
        return ['active' => (bool) ($v['active'] ?? true), 'days' => (int) ($v['days'] ?? self::DIAS_TRAS_CIERRE), 'stages' => $stages, 'map' => $map];
    }

    /** Lo de Configuración, validado: solo lo que difiere de serie. [valor, error]. */
    public static function clean(array $in): array
    {
        $dias = (int) ($in['days'] ?? self::DIAS_TRAS_CIERRE);
        if ($dias < 0 || $dias > 365) return [null, 'Los días tras el cierre van de 0 a 365'];
        $out = ['active' => (bool) ($in['active'] ?? true), 'days' => $dias, 'stages' => [], 'map' => []];
        foreach (self::STAGES as $k => [$l, $t]) {
            $c = (array) ($in['stages'][$k] ?? []);
            $label = mb_substr(trim((string) ($c['label'] ?? '')), 0, 60);
            $text = mb_substr(trim((string) ($c['text'] ?? '')), 0, 400);
            if (array_key_exists('label', $c) && $label === '') return [null, 'Cada fase necesita un nombre'];
            if (array_key_exists('text', $c) && $text === '') return [null, "«{$label}» necesita un texto"];
            if ($label !== '' && $label !== $l) $out['stages'][$k]['label'] = $label;
            if ($text !== '' && $text !== $t) $out['stages'][$k]['text'] = $text;
        }
        foreach (array_keys(Warranty::statusRows()) as $st) {
            $f = (string) ($in['map'][$st] ?? '');
            if ($f === '') continue;
            if (! isset(self::STAGES[$f])) return [null, 'Fase desconocida: ' . $f];
            if ($f !== (self::MAP[$st] ?? 'revision')) $out['map'][$st] = $f;
        }
        return [$out, null];
    }

    /* ------------------------------------------------------------ El enlace */

    public static function url(string $token): string
    {
        return rtrim((string) config('app.url'), '/') . '/seguimiento/' . $token;
    }

    /** La clave de la garantía; la crea si no tiene, o una nueva si se pide (anula la anterior). */
    public static function token(Warranty $w, bool $nueva, ?int $userId): string
    {
        if ($w->track_token && ! $nueva) return $w->track_token;
        $antes = (bool) $w->track_token;
        do { $t = Str::random(32); } while (Warranty::where('track_token', $t)->exists());
        $w->forceFill(['track_token' => $t, 'track_views' => 0, 'track_last_view_at' => null])->saveQuietly();
        WarrantyDocuments::evento($w->id, $userId, $antes
            ? 'Nuevo enlace de seguimiento para el cliente (el anterior ya no funciona)'
            : 'Enlace de seguimiento para el cliente creado');
        return $t;
    }

    /** Hasta cuándo vale el enlace (null: mientras siga abierta). */
    public static function expiresAt(Warranty $w): ?Carbon
    {
        if ($w->isOpen()) return null;
        $cierre = $w->closed_at ? Carbon::parse($w->closed_at)->endOfDay() : ($w->status_changed_at ?? $w->updated_at ?? now());
        return Carbon::parse($cierre)->addDays(self::settings()['days']);
    }

    /** Para la ficha: el enlace (si hay), visitas y hasta cuándo vale. */
    public static function forAgent(Warranty $w): array
    {
        $exp = self::expiresAt($w);
        return [
            'active'    => self::settings()['active'],
            'url'       => $w->track_token ? self::url($w->track_token) : null,
            'views'     => (int) $w->track_views,
            'last_view' => $w->track_last_view_at ? (string) $w->track_last_view_at : null,
            'expires'   => $exp?->toDateString(),
            'expired'   => $exp !== null && $exp->isPast(),
        ];
    }

    /** Una visita del cliente (no cuentan los robots ni los antivirus del correo). */
    public static function visto(Warranty $w, ?string $agente): void
    {
        if (preg_match('/bot|crawl|spider|preview|scan|safelinks|proofpoint|mimecast|barracuda|headless|curl|wget|python/i', (string) $agente)) return;
        Warranty::whereKey($w->id)->update(['track_views' => DB::raw('track_views + 1'), 'track_last_view_at' => now()]);
    }

    /* ------------------------------------------------------------ Lo que ve el cliente */

    /**
     * {code, stage: {key, label, text}, steps: [{key, label, state, at}], counts, since}
     * steps: la barra (los pasos por los que no ha pasado y quizá no pase no salen).
     */
    public static function view(Warranty $w): array
    {
        $cfg = self::settings();
        $fase = fn (?string $st) => $st === null ? null : ($cfg['map'][$st] ?? 'revision');
        $actual = $fase((string) $w->status);

        // Cuándo entró en cada fase (la primera vez), por los cambios de estado.
        $entro = ['recibida' => (string) $w->created_at];   // se recibe al crearla, empiece en la fase que empiece
        foreach (self::recorrido($w) as [$st, $cuando]) {
            $f = $fase($st);
            $f = self::AVISO[$f] ?? $f;
            if ($f && ! isset($entro[$f])) $entro[$f] = (string) $cuando;
        }
        $barraActual = self::AVISO[$actual] ?? $actual;
        if (! isset($entro[$barraActual])) $entro[$barraActual] = (string) ($w->status_changed_at ?? $w->created_at);

        $final = $actual === 'cerrada' ? 'cerrada' : 'resuelta';
        $orden = ['recibida', 'revision', 'fabricante', 'envio', 'enviada', $final];
        $pos = array_search($barraActual, $orden, true);
        $steps = [];
        foreach ($orden as $i => $k) {
            if (in_array($k, self::SI_PASA, true) && ! isset($entro[$k]) && $k !== $barraActual) continue;
            $state = $k === $barraActual ? 'current' : ($i < $pos ? 'done' : 'todo');
            if ($k === $final && $state === 'current') $state = 'done';   // resuelta o cerrada: el último, hecho
            $steps[] = ['key' => $k, 'label' => $cfg['stages'][$k]['label'], 'state' => $state,
                'at' => $state !== 'todo' ? substr((string) ($entro[$k] ?? ''), 0, 10) ?: null : null];
        }

        return [
            'code'   => $w->code,
            'stage'  => ['key' => $actual] + $cfg['stages'][$actual],
            'steps'  => $steps,
            'counts' => self::cifras($w->id),
            'since'  => substr((string) ($w->status_changed_at ?? $w->created_at), 0, 10),
            'closed' => ! $w->isOpen(),
        ];
    }

    /** [[estado, desde]] en orden, a partir de los cambios de estado. */
    private static function recorrido(Warranty $w): array
    {
        $estados = Warranty::statusRows();
        $porNombre = [];
        foreach ($estados as $k => $s) $porNombre[mb_strtolower($s['label'])] = $k;
        $clave = fn ($v) => $v === null ? null : ($porNombre[mb_strtolower(trim((string) $v))] ?? (isset($estados[$v]) ? $v : null));

        $ev = DB::table('warranty_events')->where('warranty_id', $w->id)->where('field', 'status')
            ->orderBy('created_at')->orderBy('id')->get(['from_value', 'to_value', 'created_at']);
        if ($ev->isEmpty()) return [[(string) $w->status, $w->created_at]];
        $out = [[$clave($ev[0]->from_value), $w->created_at]];
        foreach ($ev as $e) $out[] = [$clave($e->to_value), $e->created_at];
        return $out;
    }

    /** Solo cifras: cuántos dispositivos, cuántos revisados y cuántos están en el fabricante. */
    public static function cifras(int $warrantyId): array
    {
        $r = DB::table('warranty_items as i')->leftJoin('zkong_batches as b', 'b.id', '=', 'i.zkong_batch_id')
            ->where('i.warranty_id', $warrantyId)
            ->selectRaw("count(*) as total, sum(i.resolution <> 'pendiente') as revisados, sum(b.status in ('enviada','recibida')) as fabricante")
            ->first();
        $total = (int) ($r->total ?? 0);
        $rev = (int) ($r->revisados ?? 0);
        return ['total' => $total, 'reviewed' => $rev, 'pending' => max(0, $total - $rev), 'at_factory' => (int) ($r->fabricante ?? 0)];
    }
}
