<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Warranty;
use App\Models\WarrantyDocument;
use App\Services\TicketReplyService;
use App\Services\TokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Correo de resolución enviado desde el ticket de la garantía (el envío SMTP se simula).
 * La resolución va siempre por correo: con un ticket de WhatsApp hay que escribir el del
 * cliente, que se guarda en la garantía y no en la ficha del contacto.
 */
class WarrantyResolutionEmailTest extends TestCase
{
    use RefreshDatabase;

    private array $enviados = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\RolesPermissionsSeeder::class);
        Storage::fake('local');
        $this->mock(TicketReplyService::class, function ($m) {
            $m->shouldReceive('porCorreo')->andReturnUsing(function ($t, $html, $files, $cc, $bcc, $me, $adjuntos) {
                $this->enviados[] = ['to' => $t->contact_email, 'subject' => $t->subject, 'html' => $html, 'adjuntos' => $adjuntos];
                return ['ok' => true, 'id' => 1];
            });
        });
    }

    private function agente(array $permisos, string $name = 'Ana'): array
    {
        $u = User::create(['name' => $name, 'email' => uniqid() . '@x.com', 'password' => bcrypt('x'), 'active' => 1]);
        $u->givePermissionTo($permisos);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        return [$u, ['X-App-Token' => TokenService::make($u)]];
    }

    private function garantia(array $ticket = []): Warranty
    {
        $cid = DB::table('contacts')->insertGetId(['name' => 'Hotel Sol', 'wa_id' => '34600111222']);
        $tid = DB::table('tickets')->insertGetId($ticket + ['code' => 'TK-2611-0001', 'subject' => 'Etiquetas rotas', 'status' => 'abierto',
            'priority' => 'media', 'channel' => 'whatsapp', 'contact_id' => $cid, 'opened_at' => now(), 'last_message_at' => now(),
            'created_at' => now(), 'updated_at' => now()]);
        return Warranty::create(['code' => 'G-2026-0001', 'status' => 'en_curso', 'received_at' => '2026-10-20', 'client_name' => 'Hotel Sol',
            'ticket_id' => $tid, 'last_activity_at' => now(), 'status_changed_at' => now()]);
    }

    private function enviar(Warranty $w, array $h, array $datos = [])
    {
        return $this->postJson('/api/warranty_docs.php?action=send_email', $datos + ['id' => $w->id, 'to' => 'compras@hotelsol.com', 'confirm_other' => true,
            'subject' => 'Resolución de su garantía G-2026-0001', 'body' => "Hola:\n\nYa está revisado.\nUn saludo"], $h);
    }

    public function test_ticket_de_whatsapp_se_envia_al_correo_escrito_y_queda_en_la_garantia(): void
    {
        [, $h] = $this->agente([...self::GARANTIAS, 'helpdesk.access', 'tickets.reply']);
        $w = $this->garantia();

        $m = $this->getJson("/api/warranty_docs.php?action=email&id={$w->id}", $h)->assertOk()->json();
        $this->assertSame([null, 'whatsapp', true], [$m['to'], $m['ticket']['channel'], $m['can_send']]);

        $this->enviar($w, $h, ['to' => 'no-es-correo'])->assertStatus(400);
        // Un correo que no es de este cliente (aquí el ticket es de WhatsApp: no hay ninguno) pide confirmarlo.
        $this->enviar($w, $h, ['to' => 'compras@hotelsol.com', 'confirm_other' => false])->assertStatus(409)->assertJsonPath('needs_confirm', true);
        $this->assertSame([], $this->enviados);
        $this->enviar($w, $h, ['to' => ' Compras@HotelSol.com '])->assertOk()->assertJson(['ticket' => 'TK-2611-0001']);

        $this->assertCount(1, $this->enviados);
        $this->assertSame(['compras@hotelsol.com', 'Resolución de su garantía G-2026-0001', null], [$this->enviados[0]['to'], $this->enviados[0]['subject'], $this->enviados[0]['adjuntos']]);
        $this->assertSame('<p>Hola:</p><p>Ya está revisado.<br>' . "\n" . 'Un saludo</p>', $this->enviados[0]['html']);
        $this->assertSame('compras@hotelsol.com', $w->fresh()->customer_email);
        $this->assertNull(DB::table('contacts')->value('email'));   // la ficha del contacto no se toca
        $this->assertSame(1, DB::table('warranty_events')->where('warranty_id', $w->id)->where('field', 'documents')
            ->where('to_value', 'Correo de resolución enviado a compras@hotelsol.com desde el ticket TK-2611-0001')->count());

        // La próxima vez sale propuesto, y ya no pide confirmación.
        $m = $this->getJson("/api/warranty_docs.php?action=email&id={$w->id}", $h)->json();
        $this->assertSame(['compras@hotelsol.com', 'Usado antes en esta garantía'], [$m['to'], $m['suggestions'][0]['label']]);
        $this->enviar($w, $h, ['confirm_other' => false])->assertOk();
    }

    public function test_encuesta_al_pie_y_solo_guarda_el_clic(): void
    {
        [, $h] = $this->agente([...self::GARANTIAS, 'helpdesk.access', 'tickets.reply']);
        $w = $this->garantia();
        $this->assertTrue($this->getJson("/api/warranty_docs.php?action=email&id={$w->id}", $h)->json('survey'));

        $this->enviar($w, $h, ['survey' => true])->assertOk();
        $html = $this->enviados[0]['html'];
        preg_match_all('#href="([^"]*garantia_valorar/' . $w->id . '[^"]*)"#', $html, $m);
        $this->assertCount(5, $m[1]);
        $this->assertStringContainsString('¿Qué tal ha ido tu garantía con nosotros?', $html);
        $this->assertSame(1, DB::table('warranty_events')->where('warranty_id', $w->id)->where('to_value', 'like', '%(con la encuesta)')->count());

        // El enlace del 4: abrirlo (como hacen los antivirus del correo) NO vota.
        $url = html_entity_decode(collect($m[1])->first(fn ($u) => str_contains($u, 'score=4')));
        $ruta = parse_url($url, PHP_URL_PATH) . '?' . parse_url($url, PHP_URL_QUERY);
        $pagina = $this->get($ruta)->assertOk()->getContent();
        $this->assertSame(0, DB::table('warranty_ratings')->count());
        $this->assertStringContainsString('Pulsa otra vez el 4', $pagina);

        // El clic en la página sí; y luego el comentario.
        preg_match('#action="([^"]+)"#', $pagina, $a);
        $accion = html_entity_decode($a[1]);
        $this->post($accion, ['score' => 4])->assertOk()->assertSee('¡Muchas gracias!', false);
        $this->post($accion, ['comment' => 'Muy rápidos, gracias'])->assertOk()->assertSee('Comentario recibido', false);
        $this->assertSame([4, 'Muy rápidos, gracias'], [(int) DB::table('warranty_ratings')->value('score'), DB::table('warranty_ratings')->value('comment')]);
        $this->assertSame(1, DB::table('warranty_events')->where('warranty_id', $w->id)->where('to_value', 'like', 'El cliente valoró la garantía%4 de 5%')->count());

        // La ficha lo enseña, y el siguiente correo ya no la propone de serie.
        $this->assertSame(4, $this->getJson("/api/warranties.php?action=get&id={$w->id}", $h)->json('warranty.rating.score'));
        $this->assertSame(4, $this->getJson("/api/warranty_docs.php?action=email&id={$w->id}", $h)->json('rated.score'));
        $this->assertSame(1, $this->getJson('/api/warranties.php?action=survey_stats', $h)->json('kpis.answers'));

        // Sin firma, o con la firma de otra garantía, nada.
        $this->post("/api/garantia_valorar/{$w->id}", ['score' => 1])->assertStatus(403);
        $this->post(str_replace("garantia_valorar/{$w->id}", 'garantia_valorar/999', $accion), ['score' => 1])->assertStatus(403);
        $this->assertSame(4, (int) DB::table('warranty_ratings')->value('score'));
    }

    public function test_con_el_informe_adjunto(): void
    {
        [$u, $h] = $this->agente([...self::GARANTIAS, 'helpdesk.access', 'tickets.reply']);
        $w = $this->garantia();
        $this->enviar($w, $h, ['attach_report' => true])->assertStatus(400);   // sin informe todavía

        Storage::disk('local')->put('warranty_docs/x.pdf', '%PDF-1.4 informe');
        WarrantyDocument::create(['warranty_id' => $w->id, 'kind' => 'informe', 'source' => 'generado', 'number' => 'IR-2026-0001',
            'path' => 'warranty_docs/x.pdf', 'file_name' => 'Informe-G-2026-0001.pdf', 'size' => 16, 'created_by' => $u->id]);
        $this->enviar($w, $h, ['attach_report' => true])->assertOk();

        $aid = $this->enviados[0]['adjuntos'][0];
        $this->assertSame(['Informe-G-2026-0001.pdf', $w->ticket_id], [DB::table('attachments')->where('id', $aid)->value('name'), (int) DB::table('attachments')->where('id', $aid)->value('ticket_id')]);
        $this->assertStringEndsWith('(con el informe)', DB::table('warranty_events')->where('field', 'documents')->latest('id')->value('to_value'));
    }

    public function test_solo_quien_puede_responder_al_ticket(): void
    {
        $w = $this->garantia();

        // Solo garantías, o del Helpdesk sin permiso de responder: puede copiarlo, no enviarlo.
        foreach ([[...self::GARANTIAS], [...self::GARANTIAS, 'helpdesk.access']] as $permisos) {
            [, $h] = $this->agente($permisos, 'P' . count($permisos));
            $this->assertFalse($this->getJson("/api/warranty_docs.php?action=email&id={$w->id}", $h)->json('can_send'));
            $this->enviar($w, $h)->assertForbidden();
        }

        // El ticket lo tiene otro agente: igual que en el Helpdesk, no se responde por él.
        [$luis] = $this->agente(['helpdesk.access'], 'Luis');
        DB::table('tickets')->where('id', $w->ticket_id)->update(['assigned_to' => $luis->id]);
        [, $h] = $this->agente([...self::GARANTIAS, 'helpdesk.access', 'tickets.reply']);
        $this->assertStringContainsString('lo tiene Luis', $this->enviar($w, $h)->assertForbidden()->json('error'));
        $this->assertSame([], $this->enviados);
    }
}
