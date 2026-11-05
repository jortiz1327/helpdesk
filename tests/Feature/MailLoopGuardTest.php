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

    /**
     * El bucle de «Se te ha asignado el ticket TK-…»: el ticket se creaba y asignaba (y
     * avisaba al agente), el mensaje fallaba al guardarse, se revertía, y en cada reintento
     * se volvía a crear con el mismo código y a avisar. El aviso solo sale si el alta queda.
     */
    public function test_un_correo_que_falla_al_guardarse_no_avisa_de_la_asignacion(): void
    {
        $this->seed(\Database\Seeders\RolesPermissionsSeeder::class);
        $u = \App\Models\User::create(['name' => 'Ana', 'email' => 'ana@x.com', 'password' => bcrypt('x')]);
        $u->assignRole('agente');
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
        \App\Models\TicketRule::create(['name' => 'A Ana', 'active' => true, 'position' => 1, 'channel' => 'any', 'match' => 'any',
            'conditions' => [['field' => 'subject', 'op' => 'contains', 'value' => 'mistral']], 'actions' => ['assign_to' => $u->id], 'stop' => false]);
        $avisos = [];
        $this->mock(\App\Services\NotifyService::class, function ($m) use (&$avisos) {
            $m->shouldReceive('ticket')->andReturnUsing(function ($k) use (&$avisos) { $avisos[] = $k; return true; });
        });

        $roto = new class('cli@x.com', 'RE: Etiquetas Mistral') extends FakeMailMsg {
            public function getAttachments(): array { throw new \RuntimeException('adjunto ilegible'); }
        };
        for ($i = 0; $i < 3; $i++) {   // tres pasadas del sondeo, como en producción
            try { $this->handle($roto); $this->fail('debía fallar'); } catch (\RuntimeException $e) {}
        }
        $this->assertSame([], $avisos, 'ningún aviso de un alta revertida');
        $this->assertSame(0, DB::table('tickets')->count());
        $this->assertSame(0, DB::table('notifications')->count());

        // Cuando por fin se guarda, sí: un aviso de asignación y el acuse.
        $this->handle(new FakeMailMsg('cli@x.com', 'RE: Etiquetas Mistral'));
        $this->assertSame(['ticket_assigned', 'ticket_created'], $avisos);
        $this->assertSame(1, DB::table('notifications')->where('user_id', $u->id)->where('type', 'assigned')->count());
    }

    /** TK-2611-0035: un Outlook con todo el hilo citado pasaba de los 64 KB de TEXT y no entraba. */
    public function test_un_correo_muy_largo_entra_entero(): void
    {
        $largo = new class('hilo@x.com', 'RE: Etiquetas Mistral') extends FakeMailMsg {
            public function getHTMLBody(): string
            {
                return implode('', array_map(fn ($i) => "<p><span style=\"font-size:11.0pt\">Buenas tardes Diego, seguimos con las etiquetas de la cocina (mensaje {$i}).</span></p>", range(1, 2500)));
            }
        };
        $this->assertTrue($this->handle($largo)['mensaje']);
        $body = DB::table('messages')->where('channel', 'email')->orderByDesc('id')->value('body');
        $this->assertGreaterThan(65535, strlen($body));
        $this->assertSame(2500, substr_count($body, 'Buenas tardes Diego'));
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
