<?php

namespace Tests\Feature;

use App\Models\EmailAccount;
use App\Services\MailService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Cabecera IMAP falsa. */
class FakeHeader
{
    public function __construct(private array $h = []) {}
    public function get($name) { return $this->h[strtolower($name)] ?? ''; }
}

/** Mensaje IMAP falso con lo mínimo que toca handleMessage. */
class FakeMailMsg
{
    public function __construct(private string $from, private string $subject = 'Consulta', private array $headers = []) {}
    public function getFrom(): array { return [(object) ['mail' => $this->from, 'personal' => 'Cliente']]; }
    public function getMessageId(): string { return '<' . uniqid() . '@x.com>'; }
    public function getSubject(): string { return $this->subject; }
    public function getDate() { return null; }
    public function getHTMLBody(): string { return '<p>Hola, necesito ayuda.</p>'; }
    public function getTextBody(): string { return 'Hola, necesito ayuda.'; }
    public function getAttachments(): array { return []; }
    public function getCc(): array { return []; }
    public function getHeader(): FakeHeader { return new FakeHeader($this->headers); }
}

/**
 * Cortacircuitos anti-BUCLE del correo entrante (el incidente de los 220 tickets vacíos):
 * (1) los correos automáticos no crean ticket, (2) un tope por remitente corta la fuga, y
 * (3) un correo normal SÍ crea ticket CON mensaje (no huérfano).
 */
class MailLoopGuardTest extends TestCase
{
    use RefreshDatabase;

    private function handle($msg)
    {
        $acc = EmailAccount::firstOrCreate(['email' => 'sop@x.com']);
        $svc = app(MailService::class);
        $m = new \ReflectionMethod($svc, 'handleMessage');
        $m->setAccessible(true);
        return $m->invoke($svc, $acc, $msg);
    }

    public function test_un_correo_automatico_no_crea_ticket(): void
    {
        $antes = DB::table('tickets')->count();
        $res = $this->handle(new FakeMailMsg('cli@x.com', 'Fuera de la oficina', ['auto-submitted' => 'auto-replied']));
        $this->assertFalse($res['mensaje']);
        $this->assertSame($antes, DB::table('tickets')->count(), 'un autorespuesta no debe crear ticket');
    }

    public function test_el_tope_anti_bucle_deja_de_crear(): void
    {
        $cid = DB::table('contacts')->insertGetId(['name' => 'C', 'email' => 'loop@x.com']);
        for ($i = 0; $i < 10; $i++) {
            DB::table('tickets')->insert([
                'code' => 'TK-' . uniqid(), 'subject' => 'x', 'status' => 'nuevo', 'priority' => 'media',
                'channel' => 'email', 'contact_id' => $cid, 'opened_at' => now(), 'last_message_at' => now(),
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        $antes = DB::table('tickets')->where('contact_id', $cid)->count();

        $res = $this->handle(new FakeMailMsg('loop@x.com', 'Otra mas'));
        $this->assertFalse($res['mensaje']);
        $this->assertSame($antes, DB::table('tickets')->where('contact_id', $cid)->count(), 'pasado el tope no debe crear el nº 11');
    }

    public function test_un_correo_normal_crea_ticket_con_mensaje(): void
    {
        $res = $this->handle(new FakeMailMsg('nuevo@x.com', 'Necesito ayuda con el dongle'));
        $this->assertTrue($res['mensaje']);

        $t = DB::table('tickets')->where('subject', 'Necesito ayuda con el dongle')->first();
        $this->assertNotNull($t);
        // La clave del incidente: el ticket NO queda huérfano, tiene su mensaje.
        $this->assertGreaterThan(0, DB::table('messages')->where('ticket_id', $t->id)->count());
    }
}
