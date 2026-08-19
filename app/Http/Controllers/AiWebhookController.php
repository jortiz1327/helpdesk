<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Webhook RECEPTOR de resultados de un agente externo (Workspace Agent de ChatGPT u
 * otro). El trigger del agente es asíncrono: arranca la ejecución y, al terminar, el
 * agente manda su resultado AQUÍ. Guardamos el resultado asociado a un `ref` (que el
 * helpdesk envía al disparar, p. ej. "ticket:123") para luego pintarlo en su ticket.
 *
 * PÚBLICO (lo llama un sistema externo, sin token de agente). La autenticación es un
 * SECRETO compartido: va en la URL (?key=…) o en la cabecera X-Webhook-Secret. Se
 * genera solo la primera vez.
 *
 * Es una PRUEBA: no sabemos aún el formato exacto que enviará el agente, así que se
 * guarda el `raw` completo y se intenta extraer el texto y el ref con nombres de campo
 * habituales. Cuando veamos un payload real, se afina la extracción.
 */
class AiWebhookController extends Controller
{
    /** Nombres de campo habituales donde puede venir el TEXTO de la respuesta. */
    private const CLAVES_TEXTO = ['answer', 'output_text', 'output', 'response', 'result', 'text', 'content', 'message', 'draft', 'reply'];
    /** Nombres de campo habituales donde puede venir la CORRELACIÓN con el ticket. */
    private const CLAVES_REF = ['ref', 'reference', 'correlation_id', 'correlationId', 'ticket_id', 'ticketId', 'ticket'];

    /** El secreto compartido del webhook (se genera y guarda la primera vez). */
    public static function secret(): string
    {
        $s = (string) Setting::get('ai_webhook_secret', '');
        if ($s === '') {
            $s = Str::random(40);
            Setting::put('ai_webhook_secret', $s);
        }
        return $s;
    }

    /** RECIBE el resultado del agente. Siempre responde 200 para no provocar reintentos. */
    public function receive(Request $request)
    {
        // Autenticación por secreto compartido (URL ?key= o cabecera X-Webhook-Secret).
        $dado = (string) ($request->query('key', $request->header('X-Webhook-Secret', '')));
        if (!hash_equals(self::secret(), $dado)) {
            return response()->json(['ok' => false, 'error' => 'Secreto no válido'], 403);
        }

        $raw     = $request->getContent();
        $payload = json_decode($raw, true);

        $ref    = $request->query('ref')
            ?: (is_array($payload) ? $this->buscar($payload, self::CLAVES_REF) : null);
        $answer = is_array($payload) ? $this->buscar($payload, self::CLAVES_TEXTO) : null;

        try {
            $id = DB::table('ai_agent_results')->insertGetId([
                'ref'        => $ref ? mb_substr((string) $ref, 0, 120) : null,
                'source'     => mb_substr((string) $request->query('source', 'workspace_agent'), 0, 40),
                'answer'     => $answer,
                'raw'        => mb_substr((string) $raw, 0, 200000),
                'created_at' => now(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('AiWebhook: no se pudo guardar el resultado', ['error' => $e->getMessage()]);
            return response()->json(['ok' => true]);   // aun así, 200 (no queremos reintentos)
        }

        Log::info('AiWebhook: resultado recibido', ['id' => $id, 'ref' => $ref, 'tiene_texto' => $answer !== null]);
        return response()->json(['ok' => true, 'id' => $id, 'ref' => $ref]);
    }

    /**
     * Panel de inspección (PROTEGIDO): la URL del webhook, el secreto y los últimos
     * resultados recibidos. Para probar y para configurar la salida en el agente.
     */
    public function panel(Request $request)
    {
        $base = rtrim((string) config('app.url'), '/');
        $url  = $base . '/api/ai_webhook.php?key=' . self::secret();

        $recientes = DB::table('ai_agent_results')->orderByDesc('id')->limit(20)
            ->get(['id', 'ref', 'source', 'answer', 'created_at'])
            ->map(fn ($r) => [
                'id'      => (int) $r->id,
                'ref'     => $r->ref,
                'source'  => $r->source,
                'answer'  => $r->answer ? mb_substr($r->answer, 0, 400) : null,
                'created' => $r->created_at,
            ]);

        return response()->json([
            'ok'          => true,
            'webhook_url' => $url,          // esto es lo que se pone como salida del agente
            'secret'      => self::secret(),
            'recent'      => $recientes,
        ]);
    }

    /** Busca recursivamente el primer valor string bajo alguna de las claves dadas. */
    private function buscar(array $data, array $claves): ?string
    {
        foreach ($claves as $k) {
            if (isset($data[$k]) && is_string($data[$k]) && trim($data[$k]) !== '') {
                return $data[$k];
            }
        }
        foreach ($data as $v) {
            if (is_array($v)) {
                $r = $this->buscar($v, $claves);
                if ($r !== null) return $r;
            }
        }
        return null;
    }
}
