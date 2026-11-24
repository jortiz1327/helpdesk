<?php

namespace App\Services\Ai;

use Illuminate\Support\Facades\Http;
use Throwable;

/** Proveedor Anthropic (API Messages). */
class AnthropicProvider implements AiProviderConHerramientas
{
    use ConfiguraProveedor;

    public function name(): string
    {
        return 'anthropic';
    }

    public function complete(string $system, array $messages): array
    {
        $this->exigirClave();

        $cuerpo = [
            'model'      => $this->model(),
            'max_tokens' => $this->maxTokens(),
            'system'     => $system,
            'messages'   => $messages,
        ];
        if (config('ai.anthropic_thinking') === 'disabled') {
            $cuerpo['thinking'] = ['type' => 'disabled'];
        }

        $r = $this->enviar($cuerpo, 'mensaje');

        $text = '';
        foreach ((array) $r->json('content', []) as $bloque) {
            if (($bloque['type'] ?? '') === 'text') {
                $text .= (string) ($bloque['text'] ?? '');
            }
        }

        return [
            'text'          => trim($text),
            'model'         => (string) ($r->json('model') ?: $this->model()),
            'input_tokens'  => $r->json('usage.input_tokens'),
            'output_tokens' => $r->json('usage.output_tokens'),
            'truncated'     => $r->json('stop_reason') === 'max_tokens',
        ];
    }

    /**
     * Igual que complete(), pero la IA puede pedir una herramienta (hasta $maxRondas veces); en la última ronda ya no se le
     * ofrece, para que termine con texto. Se suman los tokens de todas las rondas (se pagan todas).
     */
    public function completeConHerramientas(string $system, array $messages, array $herramientas, callable $ejecutar, int $maxRondas = 3): array
    {
        $this->exigirClave();
        $entrada = 0;
        $salida = 0;
        $usos = 0;
        for ($ronda = 0; ; $ronda++) {
            $cuerpo = ['model' => $this->model(), 'max_tokens' => $this->maxTokens(), 'system' => $system, 'messages' => $messages];
            if (config('ai.anthropic_thinking') === 'disabled') {
                $cuerpo['thinking'] = ['type' => 'disabled'];
            }
            if ($ronda < $maxRondas) {
                $cuerpo['tools'] = $herramientas;
            }
            $r = $this->enviar($cuerpo, isset($cuerpo['tools']) ? 'mensaje (con herramienta)' : 'mensaje');
            $entrada += (int) $r->json('usage.input_tokens');
            $salida += (int) $r->json('usage.output_tokens');
            $bloques = (array) $r->json('content', []);
            $pedidas = array_values(array_filter($bloques, fn ($b) => ($b['type'] ?? '') === 'tool_use'));

            if ($r->json('stop_reason') === 'tool_use' && $pedidas && $ronda < $maxRondas) {
                $resultados = [];
                foreach ($pedidas as $p) {
                    $usos++;
                    try {
                        $txt = (string) $ejecutar((string) ($p['name'] ?? ''), (array) ($p['input'] ?? []));
                    } catch (Throwable) {
                        $txt = 'La búsqueda no está disponible ahora.';
                    }
                    $resultados[] = ['type' => 'tool_result', 'tool_use_id' => $p['id'] ?? '', 'content' => $txt];
                }
                $messages[] = ['role' => 'assistant', 'content' => $bloques];
                $messages[] = ['role' => 'user', 'content' => $resultados];
                continue;
            }

            $text = '';
            foreach ($bloques as $b) {
                if (($b['type'] ?? '') === 'text') {
                    $text .= (string) ($b['text'] ?? '');
                }
            }
            return [
                'text'          => trim($text),
                'model'         => (string) ($r->json('model') ?: $this->model()),
                'input_tokens'  => $entrada,
                'output_tokens' => $salida,
                'truncated'     => $r->json('stop_reason') === 'max_tokens',
                'usos'          => $usos,
            ];
        }
    }

    /** Una llamada a la API, medida (duración y fallos van a Ajustes › Asistente IA). */
    private function enviar(array $cuerpo, string $operacion)
    {
        $t0 = microtime(true);
        try {
            $r = Http::timeout($this->timeout())
                ->withHeaders(['x-api-key' => $this->key(), 'anthropic-version' => '2023-06-01'])
                ->acceptJson()
                ->post($this->url('/v1/messages'), $cuerpo);
        } catch (Throwable) {
            ApiMonitor::registrar('proveedor', $operacion, microtime(true) - $t0, false, 'sin respuesta');
            throw new AiException('No se pudo contactar con la IA');
        }
        if (! $r->successful()) {
            ApiMonitor::registrar('proveedor', $operacion, microtime(true) - $t0, false, 'HTTP ' . $r->status());
            // El mensaje de error del proveedor habla del formato de la petición, no lleva datos del cliente.
            \Illuminate\Support\Facades\Log::warning('IA proveedor: HTTP ' . $r->status() . ' · ' . mb_substr((string) ($r->json('error.message') ?? ''), 0, 300));
        } else {
            ApiMonitor::registrar('proveedor', $operacion, microtime(true) - $t0, true);
        }
        $this->exigirOk($r);
        return $r;
    }
}
