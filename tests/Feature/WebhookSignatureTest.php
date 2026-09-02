<?php

namespace Tests\Feature;

use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * INVARIANTE DE SEGURIDAD: el webhook de WhatsApp se firma con el App Secret (HMAC-SHA256
 * en X-Hub-Signature-256). Sin verificarla, cualquiera que conozca la URL podría inyectar
 * mensajes falsos y disparar el bot de Campañas (envíos de pago). Con App Secret puesto,
 * la firma es OBLIGATORIA.
 */
class WebhookSignatureTest extends TestCase
{
    use RefreshDatabase;

    private function enviar(string $body, ?string $sig)
    {
        $server = ['CONTENT_TYPE' => 'application/json'];
        if ($sig !== null) $server['HTTP_X_HUB_SIGNATURE_256'] = $sig;
        return $this->call('POST', '/api/webhook.php', [], [], [], $server, $body);
    }

    public function test_con_app_secret_exige_firma_valida(): void
    {
        Setting::put('wa_app_secret', 'sekret');
        $body = '{"object":"whatsapp_business_account"}';

        $this->enviar($body, null)->assertStatus(403);              // sin firma
        $this->enviar($body, 'sha256=deadbeef')->assertStatus(403); // firma que no casa

        $ok = 'sha256=' . hash_hmac('sha256', $body, 'sekret');
        $this->enviar($body, $ok)->assertStatus(200);               // firma correcta → Meta de verdad
    }

    public function test_firma_de_otro_secreto_no_cuela(): void
    {
        Setting::put('wa_app_secret', 'sekret');
        $body = '{"object":"x"}';
        // Firmado con OTRO secreto: es válido por forma pero no por contenido → 403.
        $this->enviar($body, 'sha256=' . hash_hmac('sha256', $body, 'otro'))->assertStatus(403);
    }
}
