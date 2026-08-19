<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Memoria de RESPUESTAS EFECTIVAS. El agente marca con ⭐ una respuesta enviada que
 * funcionó; se guarda con la categoría y las palabras clave del caso. Luego, en tickets
 * parecidos, se SUGIEREN (aquí y también a la IA vía [[ClaudeBrain]]).
 *
 * Guardar/sugerir/usar → cualquiera que pueda responder (tickets.reply). Gestionar el
 * catálogo (listar/borrar) → support.config, comprobado dentro.
 */
class EffectiveResponsesController extends Controller
{
    public function handle(Request $request)
    {
        return match ($request->query('action', 'suggest')) {
            'save'   => $this->save($request),
            'suggest' => $this->suggest($request),
            'used'   => $this->used($request),
            'list'   => $this->list($request),
            'delete' => $this->delete($request),
            default  => response()->json(['ok' => false, 'error' => 'Acción no válida'], 400),
        };
    }

    /** Guarda una respuesta como efectiva (desde un mensaje enviado o un cuerpo suelto). */
    protected function save(Request $request)
    {
        $ticketId = (int) $request->input('ticket_id');
        $t = DB::table('tickets')->where('id', $ticketId)->first(['category_id', 'subject']);
        if (!$t) return response()->json(['ok' => false, 'error' => 'Ticket no encontrado'], 404);

        // El cuerpo: de un mensaje concreto (por id) o del payload.
        $body = (string) $request->input('body', '');
        if ($msgId = (int) $request->input('message_id', 0)) {
            $m = DB::table('messages')->where('id', $msgId)->where('ticket_id', $ticketId)->first(['body']);
            if ($m) $body = (string) $m->body;
        }
        $body = trim($body);
        if ($body === '' || $this->plain($body) === '') {
            return response()->json(['ok' => false, 'error' => 'La respuesta está vacía'], 400);
        }

        // Palabras clave para buscar por parecido: asunto + última consulta del cliente + la respuesta.
        $lastIn = DB::table('messages')->where('ticket_id', $ticketId)->where('direction', 'in')
            ->where('is_internal_note', 0)->orderByDesc('id')->value('body');
        $keywords = trim($this->plain((string) $t->subject) . ' ' . $this->plain((string) $lastIn) . ' ' . $this->plain($body));
        $title    = mb_substr($this->plain((string) $t->subject) ?: $this->plain($body), 0, 180);

        // Evita duplicar exactamente la misma respuesta del mismo ticket.
        $dup = DB::table('effective_responses')->where('ticket_id', $ticketId)
            ->whereRaw('LEFT(body, 500) = ?', [mb_substr($body, 0, 500)])->exists();
        if ($dup) return response()->json(['ok' => true, 'dup' => true]);

        $id = DB::table('effective_responses')->insertGetId([
            'ticket_id'   => $ticketId,
            'category_id' => $t->category_id,
            'title'       => $title,
            'body'        => $body,
            'keywords'    => mb_substr($keywords, 0, 8000),
            'created_by'  => (int) $request->user()->id,
            'uses'        => 0,
            'created_at'  => now(),
        ]);

        return response()->json(['ok' => true, 'id' => $id]);
    }

    /** Respuestas efectivas parecidas a un ticket (misma categoría + coincidencia de texto). */
    protected function suggest(Request $request)
    {
        $ticketId = (int) $request->query('ticket_id');
        $t = DB::table('tickets')->where('id', $ticketId)->first(['category_id', 'subject']);
        if (!$t) return response()->json(['ok' => true, 'items' => []]);

        $lastIn = DB::table('messages')->where('ticket_id', $ticketId)->where('direction', 'in')
            ->where('is_internal_note', 0)->orderByDesc('id')->value('body');
        $q   = trim($this->plain((string) $t->subject) . ' ' . $this->plain((string) $lastIn));
        $cat = $t->category_id;

        $items = collect();
        if (mb_strlen($q) >= 3) {
            $items = DB::table('effective_responses')
                ->selectRaw('id, title, body, category_id, uses, MATCH(keywords) AGAINST(? IN NATURAL LANGUAGE MODE) AS score', [$q])
                ->whereRaw('MATCH(keywords) AGAINST(? IN NATURAL LANGUAGE MODE)', [$q])
                ->when($cat, fn ($qq) => $qq->orderByRaw('(category_id <=> ?) DESC', [$cat]))
                ->orderByDesc('score')->orderByDesc('uses')
                ->limit(6)->get();
        }
        // Completar con populares de la misma categoría si hay pocas.
        if ($items->count() < 4 && $cat) {
            $ya = $items->pluck('id')->all();
            $extra = DB::table('effective_responses')->where('category_id', $cat)
                ->when($ya, fn ($qq) => $qq->whereNotIn('id', $ya))
                ->orderByDesc('uses')->limit(6 - $items->count())
                ->get(['id', 'title', 'body', 'category_id', 'uses']);
            $items = $items->concat($extra);
        }

        return response()->json(['ok' => true, 'items' => $items->map(fn ($x) => [
            'id'    => (int) $x->id,
            'title' => $x->title,
            'body'  => $x->body,
            'uses'  => (int) $x->uses,
        ])->values()]);
    }

    /** Suma un uso cuando el agente inserta una respuesta efectiva. */
    protected function used(Request $request)
    {
        DB::table('effective_responses')->where('id', (int) $request->input('id'))->increment('uses');
        return response()->json(['ok' => true]);
    }

    /** Listado para gestión (superadmin / encargado). */
    protected function list(Request $request)
    {
        if (!$request->user()->can('support.config')) {
            return response()->json(['ok' => false, 'error' => 'Sin permiso'], 403);
        }
        $rows = DB::table('effective_responses as e')
            ->leftJoin('ticket_categories as c', 'c.id', '=', 'e.category_id')
            ->leftJoin('users as u', 'u.id', '=', 'e.created_by')
            ->orderByDesc('e.uses')->orderByDesc('e.id')->limit(200)
            ->get(['e.id', 'e.title', 'e.body', 'e.uses', 'e.created_at', 'c.name as category', 'u.name as author']);
        return response()->json(['ok' => true, 'items' => $rows]);
    }

    /** Borra una respuesta efectiva (superadmin / encargado). */
    protected function delete(Request $request)
    {
        if (!$request->user()->can('support.config')) {
            return response()->json(['ok' => false, 'error' => 'Sin permiso'], 403);
        }
        DB::table('effective_responses')->where('id', (int) $request->input('id'))->delete();
        return response()->json(['ok' => true]);
    }

    /** HTML → texto plano legible (para claves de búsqueda y títulos). */
    private function plain(string $s): string
    {
        $s = preg_replace('/<(br|\/p|\/div)\s*\/?>/i', ' ', $s);
        $s = html_entity_decode(strip_tags((string) $s), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return trim(preg_replace('/\s+/', ' ', (string) $s));
    }
}
