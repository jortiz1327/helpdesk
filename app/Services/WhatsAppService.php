<?php

namespace App\Services;

use App\Models\Setting;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * Cliente de la WhatsApp Cloud API (Graph API de Meta).
 * Portado de includes/functions.php de app-whatsapp.
 * Las credenciales (token, phone_number_id) se leen de la tabla `settings`.
 */
class WhatsAppService
{
    protected function token(): string
    {
        return (string) Setting::get('wa_token', '');
    }

    protected function phoneId(): string
    {
        return (string) Setting::get('wa_phone_number_id', '');
    }

    protected function appId(): string
    {
        return (string) Setting::get('wa_app_id', '');
    }

    protected function base(): string
    {
        return 'https://graph.facebook.com/' . config('whatsapp.graph_version');
    }

    protected function client(): PendingRequest
    {
        return Http::withToken($this->token())->timeout(30);
    }

    /**
     * Llama a la Graph API.
     * @return array{0:int,1:array} [httpCode, json]
     */
    public function graph(string $method, string $path, ?array $payload = null, array $query = []): array
    {
        $url = $this->base() . '/' . ltrim($path, '/');
        if ($query) {
            $url .= '?' . http_build_query($query);
        }
        try {
            $method = strtoupper($method);
            $resp = match ($method) {
                'POST'   => $this->client()->post($url, $payload ?? []),
                'PUT'    => $this->client()->put($url, $payload ?? []),
                'DELETE' => $this->client()->delete($url, $payload ?? []),
                default  => $this->client()->get($url),
            };
            return [$resp->status(), (array) ($resp->json() ?? [])];
        } catch (\Throwable $e) {
            return [0, ['error' => ['message' => 'cURL: ' . $e->getMessage()]]];
        }
    }

    /** Envía un mensaje de texto libre. */
    public function sendText(string $to, string $body): array
    {
        return $this->graph('POST', $this->phoneId() . '/messages', [
            'messaging_product' => 'whatsapp',
            'recipient_type'    => 'individual',
            'to'                => $to,
            'type'              => 'text',
            'text'              => ['preview_url' => true, 'body' => $body],
        ]);
    }

    /** Envía una plantilla aprobada. */
    public function sendTemplate(string $to, string $name, string $lang = 'es', array $components = []): array
    {
        $template = ['name' => $name, 'language' => ['code' => $lang]];
        if ($components) {
            $template['components'] = $components;
        }
        return $this->graph('POST', $this->phoneId() . '/messages', [
            'messaging_product' => 'whatsapp',
            'to'                => $to,
            'type'              => 'template',
            'template'          => $template,
        ]);
    }

    /** Envía un medio ya subido (por media_id). */
    public function sendMedia(string $to, string $type, string $mediaId, string $caption = '', ?string $filename = null): array
    {
        $media = ['id' => $mediaId];
        if ($caption !== '' && in_array($type, ['image', 'video', 'document'], true)) {
            $media['caption'] = $caption;
        }
        if ($type === 'document' && $filename) {
            $media['filename'] = $filename;
        }
        return $this->graph('POST', $this->phoneId() . '/messages', [
            'messaging_product' => 'whatsapp',
            'recipient_type'    => 'individual',
            'to'                => $to,
            'type'              => $type,
            $type               => $media,
        ]);
    }

    /** Envía un mensaje interactivo (botones o lista) ya formado. */
    public function sendInteractive(string $to, array $interactive): array
    {
        return $this->graph('POST', $this->phoneId() . '/messages', [
            'messaging_product' => 'whatsapp',
            'recipient_type'    => 'individual',
            'to'                => $to,
            'type'              => 'interactive',
            'interactive'       => $interactive,
        ]);
    }

    /**
     * Resumable Upload API: sube un archivo de muestra y devuelve el "handle"
     * necesario para crear una plantilla con cabecera de medios.
     * @return array{0:bool,1:string,2:array} [ok, handleOrError, detalle]
     */
    public function uploadResumable(string $tmpPath, string $fname, string $type): array
    {
        $appId = $this->appId();
        if (!$appId) return [false, 'Falta el App ID en Configuración', []];
        $len = filesize($tmpPath);

        // 1) Crear sesión de subida
        $start = Http::timeout(30)->post($this->base() . '/' . $appId . '/uploads?' . http_build_query([
            'file_name'    => $fname,
            'file_length'  => $len,
            'file_type'    => $type,
            'access_token' => $this->token(),
        ]));
        $r1 = (array) ($start->json() ?? []);
        $sessionId = $r1['id'] ?? null;
        if (!$sessionId) return [false, $r1['error']['message'] ?? 'No se pudo iniciar la subida', $r1];

        // 2) Subir los bytes
        $up = Http::withHeaders([
            'Authorization' => 'OAuth ' . $this->token(),
            'file_offset'   => '0',
        ])->timeout(120)->withBody(file_get_contents($tmpPath), $type)->post($this->base() . '/' . $sessionId);
        $r2 = (array) ($up->json() ?? []);
        $handle = $r2['h'] ?? null;
        if ($handle) return [true, $handle, []];
        return [false, $r2['error']['message'] ?? 'No se pudo subir el archivo', $r2];
    }

    /**
     * Proxy de medios: descarga el binario de un medio de Meta por su media_id.
     * @return ?array{binary:string,type:string}
     */
    public function mediaBinary(string $mediaId): ?array
    {
        [$code, $info] = $this->graph('GET', $mediaId);
        $url = $info['url'] ?? null;
        if (!$url) return null;
        $resp = Http::withToken($this->token())->timeout(60)->get($url);
        if (!$resp->successful()) return null;
        return [
            'binary' => $resp->body(),
            'type'   => $resp->header('Content-Type') ?: ($info['mime_type'] ?? 'application/octet-stream'),
        ];
    }

    /** Sube un archivo a la cuenta de WhatsApp y devuelve [code, json] ($json['id'] = media_id). */
    public function uploadMedia(string $tmpPath, string $mime, string $filename): array
    {
        $url = $this->base() . '/' . $this->phoneId() . '/media';
        try {
            $resp = Http::withToken($this->token())
                ->timeout(120)
                ->attach('file', file_get_contents($tmpPath), $filename, ['Content-Type' => $mime])
                ->post($url, [
                    'messaging_product' => 'whatsapp',
                    'type'              => $mime,
                ]);
            return [$resp->status(), (array) ($resp->json() ?? [])];
        } catch (\Throwable $e) {
            return [0, ['error' => ['message' => $e->getMessage()]]];
        }
    }
}
