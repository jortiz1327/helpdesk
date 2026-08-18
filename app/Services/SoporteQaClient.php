<?php

namespace App\Services;

use App\Models\Setting;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Cliente del endpoint externo «soporteQA» (Base44): lee el código de barras de una
 * foto de etiqueta AEME y responde preguntas frecuentes. Es el "cerebro para fotos"
 * del agente: cuando un cliente manda una imagen, [[ClaudeBrain]] delega aquí.
 *
 * La URL y la clave (`x-api-key`) viven en Ajustes (Configuración → Agente de IA),
 * NUNCA en el código. Sin clave, el lector está apagado.
 */
class SoporteQaClient
{
    /** Endpoint por defecto (se puede cambiar en Ajustes). */
    public const URL_DEF = 'https://elara-989d9578.base44.app/functions/soporteQA';

    /** ¿Está encendido Y configurado? */
    public static function activo(): bool
    {
        return (string) Setting::get('soporteqa_activo', '0') === '1' && self::configurado();
    }

    public static function configurado(): bool
    {
        return trim((string) Setting::get('soporteqa_key', '')) !== '';
    }

    /**
     * Pregunta a soporteQA. Al menos `question` o `imageBase64`. Devuelve el JSON del
     * endpoint bajo ['ok'=>true, ...] (answer, barcode, barcode_format, category…), o
     * ['ok'=>false, 'error'=>...] si algo falla (nunca lanza: es un aviso, no un corte).
     */
    public function preguntar(?string $question, ?string $imageBase64, ?string $hotel = null, ?string $context = null): array
    {
        $key = trim((string) Setting::get('soporteqa_key', ''));
        if ($key === '') return ['ok' => false, 'error' => 'soporteQA no está configurado (falta la clave).'];

        $url = trim((string) Setting::get('soporteqa_url', '')) ?: self::URL_DEF;

        $payload = array_filter([
            'question'     => $question,
            'image_base64' => $imageBase64,
            'hotel'        => $hotel,
            'context'      => $context,
        ], fn ($v) => $v !== null && $v !== '');

        if (empty($payload['question']) && empty($payload['image_base64'])) {
            return ['ok' => false, 'error' => 'No hay ni pregunta ni imagen que enviar a soporteQA.'];
        }

        try {
            $resp = Http::withHeaders([
                'x-api-key'    => $key,
                'Content-Type' => 'application/json',
            ])->timeout(60)->post($url, $payload);

            if (!$resp->successful()) {
                Log::warning('soporteQA error', ['status' => $resp->status(), 'body' => mb_substr($resp->body(), 0, 500)]);
                return ['ok' => false, 'error' => 'soporteQA no respondió (' . $resp->status() . ').'];
            }

            return ['ok' => true] + (array) ($resp->json() ?? []);
        } catch (\Throwable $e) {
            Log::warning('soporteQA excepción', ['msg' => $e->getMessage()]);
            return ['ok' => false, 'error' => 'No se pudo contactar con soporteQA.'];
        }
    }
}
