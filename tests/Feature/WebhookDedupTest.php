<?php

namespace Tests\Feature;

use App\Http\Controllers\WebhookController;
use App\Services\ChatService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Meta entrega los webhooks «al menos una vez» (reintenta si tardamos o devolvemos
 * 5xx). Un mismo mensaje (mismo wamid) NO debe procesarse dos veces: si no, se
 * duplica el mensaje, se infla `unread`, se reenvía el formulario y avanza el bot dos veces.
 */
class WebhookDedupTest extends TestCase
{
    use RefreshDatabase;

    public function test_un_mensaje_duplicado_de_meta_no_se_procesa_dos_veces(): void
    {
        $wa  = '34600111222';
        $cid = ChatService::upsertContact($wa, 'Cliente');
        // Primera entrega: ya guardado con su wamid.
        ChatService::storeMessage($cid, $wa, 'in', 'text', 'hola', ['wamid' => 'wamid_dup', 'channel' => 'whatsapp']);

        // Reentrega de Meta (mismo wamid) → debe descartarse en el webhook.
        $ctrl = app(WebhookController::class);
        $m = new \ReflectionMethod($ctrl, 'handleIncoming');
        $m->setAccessible(true);
        $m->invoke($ctrl, ['from' => $wa, 'id' => 'wamid_dup', 'type' => 'text', 'text' => ['body' => 'hola']], [], 'campanas');

        $this->assertSame(1, DB::table('messages')->where('wamid', 'wamid_dup')->count());
    }
}
