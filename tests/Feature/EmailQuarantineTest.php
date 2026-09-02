<?php

namespace Tests\Feature;

use App\Models\EmailAccount;
use App\Services\MailService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Mensaje IMAP falso, con lo mínimo que lee cuarentena(). */
class FakeImapMsg
{
    public function getFrom(): array { return [(object) ['mail' => 'malo@x.com', 'personal' => 'Cliente Malo']]; }
    public function getSubject(): string { return 'Pedido roto'; }
    public function getMessageId(): string { return '<abc123@x.com>'; }
    public function getTextBody(): string { return 'Cuerpo del correo que no se pudo procesar.'; }
    public function getHTMLBody(): string { return ''; }
    public function getDate(): string { return '2026-09-01 10:00:00'; }
}

/**
 * Cuarentena de correos (dead-letter). Un correo venenoso ya no se pierde: se guarda con
 * su remitente/asunto/error para que el admin lo vea, y reintentar tiene guardas claras.
 */
class EmailQuarantineTest extends TestCase
{
    use RefreshDatabase;

    private function cuenta(): EmailAccount
    {
        return EmailAccount::create([
            'email' => 'soporte@x.com', 'imap_host' => 'imap.x.com', 'imap_user' => 'soporte@x.com',
        ]);
    }

    private function invocar(string $metodo, array $args)
    {
        $svc = app(MailService::class);
        $m = new \ReflectionMethod($svc, $metodo);
        $m->setAccessible(true);
        return $m->invoke($svc, ...$args);
    }

    public function test_un_correo_saltado_se_guarda_en_cuarentena(): void
    {
        $acc = $this->cuenta();
        $this->invocar('cuarentena', [$acc, new FakeImapMsg(), 777, new \RuntimeException('cuerpo ilegible')]);

        $row = DB::table('email_quarantine')->where('email_account_id', $acc->id)->where('uid', 777)->first();
        $this->assertNotNull($row);
        $this->assertSame('malo@x.com', $row->from_email);
        $this->assertSame('Pedido roto', $row->subject);
        $this->assertStringContainsString('cuerpo ilegible', $row->error);
        $this->assertStringContainsString('no se pudo procesar', $row->body_preview);
        $this->assertNull($row->resolved_at);
    }

    public function test_no_duplica_por_cuenta_y_uid(): void
    {
        $acc = $this->cuenta();
        $this->invocar('cuarentena', [$acc, new FakeImapMsg(), 777, new \RuntimeException('a')]);
        $this->invocar('cuarentena', [$acc, new FakeImapMsg(), 777, new \RuntimeException('b')]);
        $this->assertSame(1, DB::table('email_quarantine')->where('uid', 777)->count());
    }

    public function test_reintentar_da_error_claro_si_la_cuenta_ya_no_existe(): void
    {
        // Fila de cuarentena huérfana (cuenta inexistente) → mensaje claro, sin reventar.
        $id = DB::table('email_quarantine')->insertGetId([
            'email_account_id' => 9999, 'uid' => 1, 'created_at' => now(),
        ]);
        [$ok, $err] = app(MailService::class)->reintentar($id, 1);
        $this->assertFalse($ok);
        $this->assertStringContainsString('cuenta de correo ya no existe', $err);
    }

    public function test_la_config_de_correo_lista_la_cuarentena_y_descartar_la_resuelve(): void
    {
        \Spatie\Permission\Models\Role::findOrCreate('superadmin', 'web');
        $u = \App\Models\User::create(['name' => 'Jefe', 'email' => 'j@x.com', 'password' => bcrypt('x')]);
        $u->assignRole('superadmin');
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
        $token = \App\Services\TokenService::make($u);

        $acc = $this->cuenta();
        $id = DB::table('email_quarantine')->insertGetId([
            'email_account_id' => $acc->id, 'uid' => 5, 'from_email' => 'a@b.com',
            'subject' => 'Roto', 'error' => 'x', 'created_at' => now(),
        ]);

        // La config de correo trae la cuarentena.
        $this->withHeader('X-App-Token', $token)->getJson('/api/email.php')
            ->assertOk()->assertJsonPath('quarantine.0.id', $id);

        // Descartar la resuelve → desaparece del listado.
        $this->withHeader('X-App-Token', $token)
            ->postJson('/api/email.php?action=quarantine_discard', ['id' => $id])->assertOk();
        $this->assertNotNull(DB::table('email_quarantine')->where('id', $id)->value('resolved_at'));
        $this->withHeader('X-App-Token', $token)->getJson('/api/email.php?action=quarantine')
            ->assertOk()->assertJsonCount(0, 'items');
    }
}
