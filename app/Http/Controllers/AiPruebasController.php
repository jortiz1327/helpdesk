<?php

namespace App\Http\Controllers;

use App\Services\Ai\AiKnowledge;
use App\Services\Ai\AiProvider;
use App\Services\Ai\AiSuggestionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Chat de pruebas de la IA (permiso ai.pruebas: agentes de soporte y encargados; también en Ajustes). Se escribe como si fuera el cliente y el agente
 * contesta con el mismo motor que en los tickets, pero NADA se envía a ningún cliente. Cada conversación, mensaje y
 * corrección queda con el nombre de quien la hizo; una corrección guardada se puede retirar de la memoria y restaurar.
 * Tiene tope de gasto propio (euros al mes, aparte del de los agentes).
 */
class AiPruebasController extends Controller
{
    /** Máximo de respuestas de la IA que pide una persona por minuto. */
    public const MAX_POR_MINUTO = 12;

    public function __construct(private AiSuggestionService $ai, private AiKnowledge $knowledge, private AiProvider $provider)
    {
    }

    public function handle(Request $r)
    {
        if (! $r->user()?->can('ai.pruebas')) {
            return response()->json(['ok' => false, 'error' => 'No tienes permiso'], 403);
        }
        $post = $r->isMethod('post');
        $accion = (string) $r->query('action', 'status');
        $lectura = ['status', 'chat', 'corrections'];
        if (in_array($accion, $lectura, true) === $post) {
            return response()->json(['ok' => false, 'error' => 'Método no permitido'], 405);
        }
        return match ($accion) {
            'status' => $this->status(),
            'chat' => $this->chat((int) $r->query('id')),
            'corrections' => $this->corrections($r),
            'create' => $this->create($r),
            'send' => $this->send($r),
            'reply' => $this->reply($r),
            'regenerate' => $this->regenerate($r),
            'rate' => $this->rate($r),
            'retire' => $this->retire($r),
            'delete' => $this->delete($r),
            default => response()->json(['ok' => false, 'error' => 'Acción desconocida'], 404),
        };
    }

    // ---------------------------------------------------------------- lectura

    private function status()
    {
        $chats = DB::table('ai_lab_chats as c')->orderByDesc('c.updated_at')->limit(100)->get(['c.id', 'c.title', 'c.author_name', 'c.updated_at']);
        $pend = DB::table('ai_lab_messages')->whereIn('chat_id', $chats->pluck('id'))->where('role', 'assistant')->where('superseded', 0)
            ->whereIn('status', ['ready', 'abstained'])->whereNull('rating')->selectRaw('chat_id, COUNT(*) n')->groupBy('chat_id')->pluck('n', 'chat_id');
        return response()->json([
            'ok' => true,
            'configurada' => $this->provider->configured(),
            'presupuesto' => $this->presupuesto(),
            'chats' => $chats->map(fn ($c) => ['id' => $c->id, 'title' => $c->title, 'author' => $c->author_name, 'updated_at' => $c->updated_at, 'pendientes' => (int) ($pend[$c->id] ?? 0)])->all(),
        ]);
    }

    private function presupuesto(): array
    {
        $tope = $this->ai->topeLabEur();
        $gasto = $this->ai->gastoLabEur();
        return [
            'limit' => $tope, 'spent' => round($gasto, 2), 'pct' => $tope > 0 ? round($gasto / $tope * 100, 1) : null,
            'level' => $tope <= 0 ? 'none' : ($gasto >= $tope ? 'over' : ($gasto >= $tope * 0.8 ? 'warn' : 'ok')),
            'resets' => now()->startOfMonth()->addMonth()->toDateString(),
        ];
    }

    private function chat(int $id)
    {
        $c = DB::table('ai_lab_chats')->where('id', $id)->first();
        abort_unless($c, 404);
        return response()->json(['ok' => true, 'chat' => $c, 'messages' => $this->mensajes($id), 'presupuesto' => $this->presupuesto()]);
    }

    /** Todas las correcciones escritas en el chat de pruebas, con quién y cuándo, y si están retiradas. */
    private function corrections(Request $r)
    {
        $filas = DB::table('ai_lab_messages as m')->join('ai_lab_chats as c', 'c.id', '=', 'm.chat_id')
            ->where('m.role', 'assistant')->where('m.rating', 'down')->whereNotNull('m.correction')->orderByDesc('m.rated_at')->orderByDesc('m.id')->limit(300)
            ->get(['m.id', 'm.chat_id', 'm.body', 'm.correction', 'm.rated_by_name', 'm.rated_by_id', 'm.rated_at', 'm.retired_at', 'm.retired_by_name', 'c.title']);
        $yo = (int) $r->user()?->id;
        $todas = (bool) $r->user()?->can('support.config');
        $items = $filas->map(function ($m) use ($yo, $todas) {
            $q = DB::table('ai_lab_messages')->where('chat_id', $m->chat_id)->where('role', 'user')->where('id', '<', $m->id)->orderByDesc('id')->value('body');
            return [
                'id' => $m->id, 'chat_id' => $m->chat_id, 'title' => $m->title, 'question' => (string) $q, 'ai' => (string) $m->body,
                'correction' => $m->correction, 'by' => $m->rated_by_name, 'at' => $m->rated_at,
                'mine' => (int) $m->rated_by_id === $yo, 'can_retire' => $todas || ((int) $m->rated_by_id === $yo && $yo > 0),
                'retired' => $m->retired_at !== null, 'retired_by' => $m->retired_by_name, 'retired_at' => $m->retired_at,
            ];
        })->all();
        return response()->json(['ok' => true, 'items' => $items]);
    }

    // ---------------------------------------------------------------- escritura

    private function quien(Request $r): string
    {
        return mb_substr((string) ($r->user()?->name ?: $r->user()?->email ?: 'Alguien'), 0, 120);
    }

    private function create(Request $r)
    {
        $d = $r->validate(['name' => 'nullable|string|max:120', 'email' => 'nullable|email|max:190', 'title' => 'nullable|string|max:190']);
        $id = DB::table('ai_lab_chats')->insertGetId([
            'title' => $d['title'] ?? 'Conversación nueva', 'contact_name' => $d['name'] ?? null, 'contact_email' => $d['email'] ?? null,
            'author_id' => $r->user()?->id, 'author_name' => $this->quien($r), 'created_at' => now(), 'updated_at' => now(),
        ]);
        return response()->json(['ok' => true, 'id' => $id]);
    }

    /** El «cliente» escribe: solo se guarda. El agente contesta cuando se pide «reply». */
    private function send(Request $r)
    {
        $id = (int) $r->input('id');
        $texto = trim((string) $r->input('text'));
        if ($texto === '' || ! DB::table('ai_lab_chats')->where('id', $id)->exists()) {
            return response()->json(['ok' => false, 'error' => 'Escribe el mensaje del cliente'], 422);
        }
        if ($sin = $this->sinValidar($id)) {
            return response()->json(['ok' => false, 'error' => 'Antes de seguir, valida la última respuesta de la IA (¿está bien o hay que corregirla?)'], 409);
        }
        DB::table('ai_lab_messages')->insert(['chat_id' => $id, 'role' => 'user', 'body' => mb_substr($texto, 0, 6000), 'author_name' => $this->quien($r), 'created_at' => now()]);
        if (DB::table('ai_lab_chats')->where('id', $id)->value('title') === 'Conversación nueva') {
            DB::table('ai_lab_chats')->where('id', $id)->update(['title' => mb_substr($texto, 0, 60)]);
        }
        return $this->enRespuesta($id);
    }

    private function reply(Request $r)
    {
        $id = (int) $r->input('id');
        $ult = DB::table('ai_lab_messages')->where('chat_id', $id)->orderByDesc('id')->first();
        if (! $ult || $ult->role !== 'user') {
            return response()->json(['ok' => false, 'error' => 'No hay nada que contestar'], 422);
        }
        return $this->contestar($r, $id);
    }

    /** «Otra»: la última respuesta queda atenuada y se genera una nueva (con las correcciones ya guardadas). */
    private function regenerate(Request $r)
    {
        $id = (int) $r->input('id');
        $ult = DB::table('ai_lab_messages')->where('chat_id', $id)->where('role', 'assistant')->where('superseded', 0)->orderByDesc('id')->first();
        if (! $ult) {
            return response()->json(['ok' => false, 'error' => 'No hay respuesta que sustituir'], 422);
        }
        if ($bloq = $this->bloqueo($r)) {
            return $bloq;
        }
        DB::table('ai_lab_messages')->where('id', $ult->id)->update(['superseded' => 1]);
        // Si la que se sustituye tenía una corrección guardada en la memoria, sigue: «Otra con esta indicación» la aplica.
        return $this->contestar($r, $id, true, false);
    }

    private function rate(Request $r)
    {
        $m = DB::table('ai_lab_messages')->where('id', (int) $r->input('id'))->where('role', 'assistant')->first();
        if (! $m) {
            return response()->json(['ok' => false, 'error' => 'Respuesta no encontrada'], 404);
        }
        $rating = (string) $r->input('rating');
        $corr = trim((string) $r->input('correction'));
        if (! in_array($rating, ['up', 'down'], true)) {
            return response()->json(['ok' => false, 'error' => 'Valoración no válida'], 422);
        }
        if ($rating === 'down' && mb_strlen($corr) < 5) {
            return response()->json(['ok' => false, 'error' => 'Escribe qué debía responder (mínimo 5 letras)'], 422);
        }
        DB::table('ai_lab_messages')->where('id', $m->id)->update([
            'rating' => $rating, 'correction' => $rating === 'down' ? mb_substr($corr, 0, 4000) : null,
            'rated_by_name' => $this->quien($r), 'rated_by_id' => $r->user()?->id, 'rated_at' => now(), 'retired_at' => null, 'retired_by_name' => null,
        ]);
        $this->knowledge->indexarCorreccionChat((int) $m->id);
        return response()->json(['ok' => true, 'messages' => $this->mensajes((int) $m->chat_id)]);
    }

    /** Retira una corrección de la memoria de la IA (retired=1) o la devuelve (retired=0). La conversación se conserva. */
    private function retire(Request $r)
    {
        $m = DB::table('ai_lab_messages')->where('id', (int) $r->input('id'))->where('role', 'assistant')->where('rating', 'down')->first();
        if (! $m) {
            return response()->json(['ok' => false, 'error' => 'Corrección no encontrada'], 404);
        }
        // Cada persona retira sus propias correcciones; retirar las de otros solo lo hace quien configura el soporte (support.config).
        if (! $r->user()?->can('support.config') && (int) $m->rated_by_id !== (int) $r->user()?->id) {
            return response()->json(['ok' => false, 'error' => 'Solo quien la escribió o un encargado puede retirar esta corrección'], 403);
        }
        $retirar = filter_var($r->input('retired'), FILTER_VALIDATE_BOOLEAN);
        DB::table('ai_lab_messages')->where('id', $m->id)->update($retirar
            ? ['retired_at' => now(), 'retired_by_name' => $this->quien($r)]
            : ['retired_at' => null, 'retired_by_name' => null]);
        $this->knowledge->indexarCorreccionChat((int) $m->id);   // la quita o la vuelve a poner en la memoria
        return $this->corrections($r);
    }

    private function delete(Request $r)
    {
        $id = (int) $r->input('id');
        $c = DB::table('ai_lab_chats')->where('id', $id)->first();
        if (! $c) {
            return response()->json(['ok' => false, 'error' => 'Conversación no encontrada'], 404);
        }
        // Borrarla borra también sus correcciones de la memoria: solo quien la creó (o un superadmin).
        if ($c->author_id && (int) $c->author_id !== (int) $r->user()?->id && ! $r->user()?->isSuperAdmin()) {
            return response()->json(['ok' => false, 'error' => 'Solo quien la creó puede borrarla (o un superadmin)'], 403);
        }
        foreach (DB::table('ai_lab_messages')->where('chat_id', $id)->pluck('id') as $m) {
            $this->knowledge->quitarCorreccionChat((int) $m);
        }
        DB::table('ai_lab_chats')->where('id', $id)->delete();
        return response()->json(['ok' => true]);
    }

    // ---------------------------------------------------------------- apoyo

    /** ¿Hay una respuesta de la IA sin validar? (la última visible, que ya salió bien o derivó). */
    private function sinValidar(int $chatId): bool
    {
        $ult = DB::table('ai_lab_messages')->where('chat_id', $chatId)->where('role', 'assistant')->where('superseded', 0)->orderByDesc('id')->first();
        return $ult && in_array($ult->status, ['ready', 'abstained'], true) && $ult->rating === null;
    }

    private function enRespuesta(int $id)
    {
        return response()->json(['ok' => true, 'messages' => $this->mensajes($id)]);
    }

    /** Tope de gasto y límite por minuto: si no se puede llamar al proveedor, devuelve la respuesta de error. */
    private function bloqueo(Request $r)
    {
        $tope = $this->ai->topeLabEur();
        if ($tope > 0 && $this->ai->gastoLabEur() >= $tope) {
            return response()->json(['ok' => false, 'error' => 'Se ha llegado al tope del chat de pruebas este mes (' . number_format($tope, 0, ',', '.') . ' €). Vuelve a abrirse el día 1.'], 429);
        }
        $clave = 'ai-pruebas:' . (int) $r->user()?->id;
        if (RateLimiter::tooManyAttempts($clave, self::MAX_POR_MINUTO)) {
            return response()->json(['ok' => false, 'error' => 'Demasiadas respuestas seguidas. Espera un minuto.'], 429);
        }
        RateLimiter::hit($clave, 60);
        return null;
    }

    private function contestar(Request $r, int $id, bool $forzar = false, bool $comprobar = true)
    {
        if ($comprobar && ($bloq = $this->bloqueo($r))) {
            return $bloq;
        }
        $chat = DB::table('ai_lab_chats')->where('id', $id)->first();
        $conv = DB::table('ai_lab_messages')->where('chat_id', $id)->where('superseded', 0)
            ->where(fn ($q) => $q->where('role', 'user')->orWhere('status', 'ready'))
            ->orderBy('id')->get(['role', 'body'])->map(fn ($m) => ['role' => $m->role, 'text' => (string) $m->body])->all();
        $res = $this->ai->responderChat($chat, $conv, $this->provider, $forzar);
        if ($res['status'] === 'silent') {   // solo acusó recibo: no se contesta ni se guarda nada
            return $this->enRespuesta($id);
        }
        DB::table('ai_lab_messages')->insert([
            'chat_id' => $id, 'role' => 'assistant', 'body' => $res['body'] ?? null, 'status' => $res['status'], 'error' => $res['error'] ?? null,
            'model' => $res['model'] ?? null, 'input_tokens' => $res['input_tokens'] ?? null, 'output_tokens' => $res['output_tokens'] ?? null, 'created_at' => now(),
        ]);
        DB::table('ai_lab_chats')->where('id', $id)->update(['updated_at' => now()]);
        return response()->json(['ok' => true, 'messages' => $this->mensajes($id), 'presupuesto' => $this->presupuesto()]);
    }

    private function mensajes(int $chatId): array
    {
        return DB::table('ai_lab_messages')->where('chat_id', $chatId)->orderBy('id')
            ->get(['id', 'role', 'body', 'author_name', 'status', 'error', 'superseded', 'rating', 'correction', 'rated_by_name', 'rated_at', 'retired_at', 'retired_by_name', 'created_at'])->all();
    }
}
