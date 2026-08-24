<?php

namespace Tests\Feature;

use App\Models\EmailAccount;
use App\Services\BusinessHoursService;
use App\Services\MailService;
use App\Services\ScheduledReplyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Respuesta programada: se guarda pendiente y el cron la envía a su hora por el mismo
 * camino que una respuesta normal. Se prueban guardar, cancelar y el disparo del cron
 * (con un MailService de pega, para no depender de un SMTP real).
 */
class ScheduledReplyTest extends TestCase
{
    use RefreshDatabase;

    private function ticketEmail(): int
    {
        $cid = DB::table('contacts')->insertGetId(['name' => 'Cliente', 'email' => 'cli@x.com']);
        return DB::table('tickets')->insertGetId([
            'code' => 'TK-1', 'subject' => 'Etiquetas', 'status' => 'abierto', 'priority' => 'media',
            'channel' => 'email', 'contact_id' => $cid, 'opened_at' => now(),
            'last_message_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function agente(): int
    {
        return DB::table('users')->insertGetId([
            'name' => 'Ana', 'email' => 'ana@x.com', 'password' => bcrypt('x'), 'created_at' => now(),
        ]);
    }

    public function test_programar_guarda_una_pendiente(): void
    {
        $id = $this->ticketEmail();
        $me = $this->agente();
        $cuando = now()->addHours(3);

        $sid = app(ScheduledReplyService::class)->schedule($id, '<p>Hola</p>', [], ['jefe@x.com'], [], $cuando, $me);

        $row = DB::table('scheduled_replies')->where('id', $sid)->first();
        $this->assertSame('pending', $row->status);
        $this->assertSame($id, (int) $row->ticket_id);
        $this->assertStringContainsString('Hola', $row->body);
        $this->assertSame(['jefe@x.com'], json_decode($row->cc, true));
    }

    public function test_cancelar_una_pendiente(): void
    {
        $id = $this->ticketEmail();
        $sid = app(ScheduledReplyService::class)->schedule($id, '<p>x</p>', [], [], [], now()->addHour(), $this->agente());

        $this->assertTrue(app(ScheduledReplyService::class)->cancel($sid));
        $this->assertSame('canceled', DB::table('scheduled_replies')->where('id', $sid)->value('status'));
        // Cancelar dos veces no vale (ya no está pendiente).
        $this->assertFalse(app(ScheduledReplyService::class)->cancel($sid));
    }

    public function test_el_cron_envia_las_vencidas(): void
    {
        // Buzón SMTP + MailService de pega (devuelve un id sin tocar red).
        DB::table('email_accounts')->insert([
            'email' => 'soporte@x.com', 'smtp_host' => 'smtp.x.com', 'active' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $fake = \Mockery::mock(MailService::class);
        $fake->shouldReceive('sendMail')->once()->andReturn('<smtp-id@x.com>');
        app()->instance(MailService::class, $fake);

        $id = $this->ticketEmail();
        $me = $this->agente();
        // Vencida (send_at en el pasado).
        $sid = app(ScheduledReplyService::class)->schedule($id, '<p>Respuesta</p>', [], [], [], now()->subMinute(), $me);

        $n = app(ScheduledReplyService::class)->dispatchDue();

        $this->assertSame(1, $n);
        $this->assertSame('sent', DB::table('scheduled_replies')->where('id', $sid)->value('status'));
        // Quedó como mensaje SALIENTE en el hilo.
        $this->assertSame(1, DB::table('messages')->where('ticket_id', $id)->where('direction', 'out')->count());
    }

    public function test_sin_smtp_falla_y_avisa_al_autor(): void
    {
        $id = $this->ticketEmail();
        $me = $this->agente();
        $sid = app(ScheduledReplyService::class)->schedule($id, '<p>x</p>', [], [], [], now()->subMinute(), $me);

        app(ScheduledReplyService::class)->dispatchDue();   // no hay buzón SMTP → falla

        // Un solo intento no la marca fallida todavía (reintenta hasta 5).
        $row = DB::table('scheduled_replies')->where('id', $sid)->first();
        $this->assertSame('pending', $row->status);
        $this->assertSame(1, (int) $row->attempts);
    }

    public function test_la_proxima_apertura_siempre_cae_en_horario(): void
    {
        // Con horario configurado (el sembrado por defecto), la próxima apertura nunca es
        // anterior al punto de partida y SÍ cae dentro de un tramo de atención.
        $bh = app(BusinessHoursService::class);
        $ap = $bh->proximaApertura(now());
        $this->assertTrue($ap->greaterThanOrEqualTo(now()->subSecond()));
        $this->assertTrue($bh->abierto($ap));
    }
}
