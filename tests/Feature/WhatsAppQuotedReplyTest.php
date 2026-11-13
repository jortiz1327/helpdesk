<?php

namespace Tests\Feature;

use App\Http\Controllers\TicketsController;
use App\Models\Setting;
use App\Services\SoporteWhatsApp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Un WhatsApp que CITA a otro trae payload.context.id (el wamid del citado): el hilo del ticket
 * debe enseñar la cita (antes se veían dos textos sueltos).
 */
class WhatsAppQuotedReplyTest extends TestCase
{
    use RefreshDatabase;

    private function msg(string $id, string $texto, ?string $cita = null): array
    {
        $m = ['from' => '34600111444', 'id' => $id, 'timestamp' => (string) now()->timestamp, 'type' => 'text', 'text' => ['body' => $texto]];
        if ($cita) $m['context'] = ['from' => '34600999999', 'id' => $cita];
        return ['contacts' => [['wa_id' => '34600111444', 'profile' => ['name' => 'Cliente']]], 'messages' => [$m]];
    }

    private function hilo(int $ticketId)
    {
        $c = app(TicketsController::class);
        $f = new \ReflectionMethod($c, 'cargarMensajes');
        $f->setAccessible(true);
        return collect($f->invoke($c, $ticketId, null, 50))->keyBy('body');
    }

    public function test_el_hilo_enseña_a_que_mensaje_responde_cada_cita(): void
    {
        Setting::put('wa_soporte_tickets', '1');
        $svc = app(SoporteWhatsApp::class);
        $svc->procesar('messages', $this->msg('wamid_a', 'Primero, mira el cartel'));
        $svc->procesar('messages', $this->msg('wamid_b', 'Esto ya está', 'wamid_a'));
        $svc->procesar('messages', $this->msg('wamid_c', 'Esto me refiero', 'wamid_zzz'));   // citado que no tenemos
        $svc->procesar('messages', $this->msg('wamid_d', 'Sin cita'));

        $tid = (int) DB::table('messages')->where('wamid', 'wamid_a')->value('ticket_id');
        $h = $this->hilo($tid);

        $this->assertSame(DB::table('messages')->where('wamid', 'wamid_a')->value('id'), $h['Esto ya está']->reply_to['id']);
        $this->assertSame('Primero, mira el cartel', $h['Esto ya está']->reply_to['preview']);
        $this->assertSame('in', $h['Esto ya está']->reply_to['direction']);
        $this->assertNull($h['Esto me refiero']->reply_to['id']);   // se avisa, no se inventa
        $this->assertFalse(isset($h['Sin cita']->reply_to));
        $this->assertFalse(isset($h['Esto ya está']->payload));      // el payload no sale al navegador
    }
}
