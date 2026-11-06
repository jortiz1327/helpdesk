<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Services\SoporteWhatsApp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Un WhatsApp escrito sin conexión llega minutos después con la hora a la que se escribió
 * (TK-2611-0045: escrito a las 17:01, recibido a las 17:16). Se guardan las dos: created_at
 * la del móvil (para ordenar como en el móvil) y received_at cuándo nos llegó.
 */
class WhatsAppLateArrivalTest extends TestCase
{
    use RefreshDatabase;

    private function evento(string $id, Carbon $escrito, string $texto): array
    {
        return [
            'contacts' => [['wa_id' => '34600111333', 'profile' => ['name' => 'Cliente']]],
            'messages' => [['from' => '34600111333', 'id' => $id, 'timestamp' => (string) $escrito->timestamp, 'type' => 'text', 'text' => ['body' => $texto]]],
        ];
    }

    public function test_guarda_cuando_se_escribio_y_cuando_llego(): void
    {
        Setting::put('wa_soporte_tickets', '1');
        Carbon::setTestNow(Carbon::parse('2026-11-05 17:16:56'));
        $svc = app(SoporteWhatsApp::class);

        $svc->procesar('messages', $this->evento('wamid_ok', Carbon::parse('2026-11-05 17:05:02'), 'Ok'));
        $svc->procesar('messages', $this->evento('wamid_tarde', Carbon::parse('2026-11-05 17:01:13'), 'En ese caso…'));

        $m = DB::table('messages')->where('wamid', 'wamid_tarde')->first();
        $this->assertSame('2026-11-05 17:01:13', Carbon::parse($m->created_at)->format('Y-m-d H:i:s'));
        $this->assertSame('2026-11-05 17:16:56', Carbon::parse($m->received_at)->format('Y-m-d H:i:s'));

        // En el ticket, la conversación sale por la hora a la que se escribió.
        $orden = DB::table('messages')->where('ticket_id', $m->ticket_id)->orderBy('created_at')->orderBy('id')->pluck('body')->all();
        $this->assertSame(['En ese caso…', 'Ok'], $orden);
        Carbon::setTestNow();
    }

    public function test_rellena_la_llegada_de_los_ya_guardados_desde_los_eventos(): void
    {
        $cid = \App\Services\ChatService::upsertContact('34600111333', 'Cliente');
        foreach (['wamid_a' => '2026-11-05 17:01:13', 'wamid_sin' => '2026-11-05 17:02:00'] as $w => $c) {
            \App\Services\ChatService::storeMessage($cid, '34600111333', 'in', 'text', 'x', ['wamid' => $w, 'created_at' => $c]);
        }
        DB::table('wa_raw_events')->insert([
            ['field' => 'messages', 'payload' => json_encode(['messages' => [['id' => 'wamid_a']]]), 'created_at' => '2026-11-05 17:16:56'],
            ['field' => 'messages', 'payload' => json_encode(['messages' => [['id' => 'wamid_a']]]), 'created_at' => '2026-11-05 17:20:00'],   // reintento de Meta
        ]);

        (require database_path('migrations/2026_11_06_090100_backfill_received_at_from_raw_events.php'))->up();

        $this->assertSame('2026-11-05 17:16:56', Carbon::parse(DB::table('messages')->where('wamid', 'wamid_a')->value('received_at'))->format('Y-m-d H:i:s'));
        $this->assertNull(DB::table('messages')->where('wamid', 'wamid_sin')->value('received_at'));
    }

    public function test_al_reprocesar_vale_la_hora_de_llegada_del_evento(): void
    {
        Setting::put('wa_soporte_tickets', '1');
        Carbon::setTestNow(Carbon::parse('2026-11-05 18:00:00'));   // se reprocesa una hora después
        app(SoporteWhatsApp::class)->procesar('messages', $this->evento('wamid_re', Carbon::parse('2026-11-05 17:01:13'), 'Hola'), '2026-11-05 17:16:56');

        $m = DB::table('messages')->where('wamid', 'wamid_re')->first();
        $this->assertSame('2026-11-05 17:16:56', Carbon::parse($m->received_at)->format('Y-m-d H:i:s'));
        Carbon::setTestNow();
    }
}
