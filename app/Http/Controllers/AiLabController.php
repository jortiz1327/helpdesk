<?php

namespace App\Http\Controllers;

use App\Services\Ai\AiKnowledge;
use App\Services\Ai\AiProvider;
use App\Services\Ai\AiSuggestionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Chats de prueba de la IA (páginas /ia y /ia/{id}). SOLO en local (config ai.lab = APP_ENV local): en
 * cualquier otro entorno todo devuelve 404. No hay tickets ni clientes reales ni se envía nada a nadie:
 * se escribe como si fuera el cliente y el agente contesta con el mismo motor que en los tickets.
 */
class AiLabController extends Controller
{
    public function __construct(private AiSuggestionService $ai, private AiKnowledge $knowledge, private AiProvider $provider)
    {
        abort_unless(config('ai.lab'), 404);
    }

    public function page()
    {
        return response()->view('ia', ['configurada' => $this->provider->configured()]);
    }

    /** El JavaScript de la página: va aparte porque la CSP de la app no permite scripts dentro del HTML. */
    public function script()
    {
        return response(file_get_contents(resource_path('ia/chat.js')), 200, ['Content-Type' => 'application/javascript; charset=utf-8', 'Cache-Control' => 'no-store']);
    }

    public function chats()
    {
        return response()->json(DB::table('ai_lab_chats')->orderByDesc('updated_at')->limit(100)->get(['id', 'title', 'updated_at'])->all());
    }

    public function create(Request $r)
    {
        $d = $r->validate(['name' => 'nullable|string|max:120', 'email' => 'nullable|email|max:190', 'title' => 'nullable|string|max:190']);
        $id = DB::table('ai_lab_chats')->insertGetId([
            'title' => $d['title'] ?? 'Conversación nueva', 'contact_name' => $d['name'] ?? null, 'contact_email' => $d['email'] ?? null,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        return response()->json(['id' => $id]);
    }

    public function show(int $id)
    {
        $c = DB::table('ai_lab_chats')->where('id', $id)->first();
        abort_unless($c, 404);
        return response()->json(['chat' => $c, 'messages' => $this->mensajes($id)]);
    }

    public function destroy(int $id)
    {
        foreach (DB::table('ai_lab_messages')->where('chat_id', $id)->pluck('id') as $m) {
            $this->knowledge->quitarCorreccionChat((int) $m);
        }
        DB::table('ai_lab_chats')->where('id', $id)->delete();
        return response()->json(['ok' => true]);
    }

    /** El «cliente» escribe: se guarda y el agente contesta. */
    public function send(Request $r, int $id)
    {
        $texto = trim((string) $r->input('text'));
        abort_unless($texto !== '' && DB::table('ai_lab_chats')->where('id', $id)->exists(), 422);
        DB::table('ai_lab_messages')->insert(['chat_id' => $id, 'role' => 'user', 'body' => mb_substr($texto, 0, 6000), 'created_at' => now()]);
        if (DB::table('ai_lab_chats')->where('id', $id)->value('title') === 'Conversación nueva') {
            DB::table('ai_lab_chats')->where('id', $id)->update(['title' => mb_substr($texto, 0, 60)]);
        }
        return $this->contestar($id);
    }

    /** «Otra»: la última respuesta queda atenuada y se genera una nueva (con las correcciones ya guardadas). */
    public function regenerate(int $id)
    {
        $ult = DB::table('ai_lab_messages')->where('chat_id', $id)->where('role', 'assistant')->where('superseded', 0)->orderByDesc('id')->first();
        abort_unless($ult, 422);
        DB::table('ai_lab_messages')->where('id', $ult->id)->update(['superseded' => 1]);
        return $this->contestar($id);
    }

    public function rate(Request $r, int $msg)
    {
        $m = DB::table('ai_lab_messages')->where('id', $msg)->where('role', 'assistant')->first();
        abort_unless($m, 404);
        $rating = (string) $r->input('rating');
        $corr = trim((string) $r->input('correction'));
        if (! in_array($rating, ['up', 'down'], true)) {
            return response()->json(['ok' => false, 'error' => 'Valoración no válida'], 422);
        }
        if ($rating === 'down' && mb_strlen($corr) < 5) {
            return response()->json(['ok' => false, 'error' => 'Escribe qué debía responder (mínimo 5 letras)'], 422);
        }
        DB::table('ai_lab_messages')->where('id', $msg)->update(['rating' => $rating, 'correction' => $rating === 'down' ? mb_substr($corr, 0, 4000) : null]);
        $this->knowledge->indexarCorreccionChat($msg);
        return response()->json(['ok' => true, 'messages' => $this->mensajes((int) $m->chat_id)]);
    }

    private function contestar(int $id)
    {
        $chat = DB::table('ai_lab_chats')->where('id', $id)->first();
        $conv = DB::table('ai_lab_messages')->where('chat_id', $id)->where('superseded', 0)
            ->where(fn ($q) => $q->where('role', 'user')->orWhere('status', 'ready'))
            ->orderBy('id')->get(['role', 'body'])->map(fn ($m) => ['role' => $m->role, 'text' => (string) $m->body])->all();
        $res = $this->ai->responderChat($chat, $conv, $this->provider);
        DB::table('ai_lab_messages')->insert([
            'chat_id' => $id, 'role' => 'assistant', 'body' => $res['body'] ?? null, 'status' => $res['status'], 'error' => $res['error'] ?? null,
            'model' => $res['model'] ?? null, 'input_tokens' => $res['input_tokens'] ?? null, 'output_tokens' => $res['output_tokens'] ?? null, 'created_at' => now(),
        ]);
        DB::table('ai_lab_chats')->where('id', $id)->update(['updated_at' => now()]);
        return response()->json(['messages' => $this->mensajes($id)]);
    }

    private function mensajes(int $chatId): array
    {
        return DB::table('ai_lab_messages')->where('chat_id', $chatId)->orderBy('id')
            ->get(['id', 'role', 'body', 'status', 'error', 'superseded', 'rating', 'correction', 'created_at'])->all();
    }
}
