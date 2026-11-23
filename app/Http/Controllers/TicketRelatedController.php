<?php

namespace App\Http\Controllers;

use App\Support\TicketVisibility;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Tickets duplicados o relacionados con el que tienes abierto (mismo cliente, o mismo hotel/empresa con un problema parecido).
 * Sin IA y sin gasto: solo compara datos del propio helpdesk. Respeta lo que cada agente puede ver. Solo lee.
 *
 *   GET ?ticket=ID  → { ok, related: [{ id, code, subject, status, relation: cliente|empresa, similar, days }] }
 */
class TicketRelatedController extends Controller
{
    public const DIAS = 14;          // ventana de búsqueda
    public const MAX = 6;            // como mucho esta cantidad de avisos
    public const SIMILITUD = 0.3;    // parecido mínimo (0–1) entre dos tickets para decir «parece el mismo problema»

    private const VACIAS = ['para', 'como', 'esta', 'este', 'esto', 'estan', 'tengo', 'tiene', 'tienen', 'desde', 'hola', 'buenas', 'buenos', 'dias', 'tardes', 'gracias', 'saludos', 'cliente',
        'favor', 'podrian', 'podria', 'puedo', 'pueden', 'sobre', 'cuando', 'donde', 'pero', 'porque', 'tambien', 'ayuda', 'aeme', 'group', 'etiqueta', 'etiquetas', 'ticket', 'correo', 'mensaje'];

    public function __invoke(Request $request)
    {
        $id = (int) $request->query('ticket');
        $me = $request->user();
        if (! TicketVisibility::puedeVer($me, $id)) {
            return response()->json(['ok' => false, 'error' => 'Ticket no encontrado'], 404);
        }
        return response()->json(['ok' => true, 'related' => $this->buscar($id, $me), 'urgency' => $this->urgencia($id)]);
    }

    /** Aviso de urgencia según lo último que escribió el cliente (sin IA). No cambia nada del ticket. */
    public function urgencia(int $id): ?array
    {
        $t = DB::table('tickets')->where('id', $id)->first(['status', 'channel', 'merged_into_id']);
        if (! $t || $t->merged_into_id || $t->channel === 'cron' || in_array($t->status, ['resuelto', 'cerrado'], true)) {
            return null;
        }
        $ult = DB::table('messages')->where('ticket_id', $id)->where('direction', 'in')->where('is_internal_note', 0)->whereNotNull('body')
            ->orderByDesc('id')->limit(2)->pluck('body')->all();
        return $ult ? \App\Support\Urgency::detectar(implode("\n", array_map(fn ($b) => mb_substr(trim(strip_tags((string) $b)), 0, 1500), $ult))) : null;
    }

    public function buscar(int $id, $me): array
    {
        $t = DB::table('tickets as t')->leftJoin('contacts as c', 'c.id', '=', 't.contact_id')->where('t.id', $id)
            ->first(['t.id', 't.subject', 't.contact_id', 't.channel', 't.merged_into_id', 'c.empresa']);
        if (! $t || $t->merged_into_id || $t->channel === 'cron') {
            return [];
        }
        $empresa = self::norm((string) ($t->empresa ?? ''));
        $empresa = mb_strlen($empresa) >= 3 ? $empresa : '';

        $q = DB::table('tickets as t')->leftJoin('contacts as c', 'c.id', '=', 't.contact_id')
            ->where('t.id', '!=', $id)->whereNull('t.merged_into_id')->where('t.channel', '!=', 'cron')
            ->where('t.created_at', '>=', now()->subDays(self::DIAS))
            ->where(function ($w) use ($t, $empresa) {
                if ($t->contact_id) $w->orWhere('t.contact_id', $t->contact_id);
                if ($empresa !== '') $w->orWhereRaw('LOWER(TRIM(c.empresa)) = ?', [$empresa]);
            });
        $q = TicketVisibility::scope($q, $me);
        $filas = $q->orderByDesc('t.id')->limit(30)->get(['t.id', 't.code', 't.subject', 't.status', 't.contact_id', 't.created_at', 'c.empresa']);
        if ($filas->isEmpty()) {
            return [];
        }

        // Primer mensaje del cliente de todos los candidatos y del propio ticket, en dos consultas (no una por ticket).
        $primeros = $this->primerosMensajes(array_merge([$id], $filas->pluck('id')->map(fn ($v) => (int) $v)->all()));
        $mio = self::palabras($t->subject . ' ' . ($primeros[$id] ?? ''));
        $salida = [];
        foreach ($filas as $f) {
            $mismo = $t->contact_id && (int) $f->contact_id === (int) $t->contact_id;
            $parecido = $this->parecido($mio, self::palabras($f->subject . ' ' . ($primeros[(int) $f->id] ?? '')));
            $similar = $parecido >= self::SIMILITUD;
            // Mismo cliente: siempre cuenta. Otra persona del mismo hotel: solo si el problema se parece (si no, es ruido).
            if (! $mismo && ! $similar) {
                continue;
            }
            $salida[] = [
                'id' => (int) $f->id, 'code' => $f->code, 'subject' => (string) $f->subject, 'status' => $f->status,
                'relation' => $mismo ? 'cliente' : 'empresa', 'similar' => $similar,
                'days' => (int) floor(abs(now()->diffInDays($f->created_at))), '_p' => $parecido,
            ];
        }
        usort($salida, fn ($a, $b) => [$b['similar'], $b['_p'], $b['id']] <=> [$a['similar'], $a['_p'], $a['id']]);
        return array_map(function ($r) { unset($r['_p']); return $r; }, array_slice($salida, 0, self::MAX));
    }

    /** @param int[] $ids  @return array<int,string> ticket_id => primer mensaje del cliente (recortado) */
    private function primerosMensajes(array $ids): array
    {
        $base = fn () => DB::table('messages')->whereIn('ticket_id', $ids)->where('direction', 'in')->where('is_internal_note', 0)->whereNotNull('body');
        $primeros = $base()->groupBy('ticket_id')->selectRaw('MIN(id) as id')->pluck('id');
        if ($primeros->isEmpty()) {
            return [];
        }
        $salida = [];
        foreach (DB::table('messages')->whereIn('id', $primeros)->get(['ticket_id', 'body']) as $m) {
            $salida[(int) $m->ticket_id] = mb_substr(trim(strip_tags((string) $m->body)), 0, 600);
        }
        return $salida;
    }

    /** Palabras con contenido (sin tildes, de 4 letras o más, sin las vacías de siempre). */
    public static function palabras(string $texto): array
    {
        $texto = self::norm($texto);
        $p = preg_split('/[^a-z0-9]+/', $texto, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        return array_values(array_unique(array_filter($p, fn ($w) => strlen($w) >= 4 && ! in_array($w, self::VACIAS, true))));
    }

    /** Parecido 0–1 entre dos conjuntos de palabras (Jaccard). */
    public function parecido(array $a, array $b): float
    {
        if (! $a || ! $b) {
            return 0.0;
        }
        $union = count(array_unique(array_merge($a, $b)));
        return $union ? count(array_intersect($a, $b)) / $union : 0.0;
    }

    private static function norm(string $s): string
    {
        return trim(Str::lower(Str::ascii($s)));
    }
}
