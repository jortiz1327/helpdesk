<?php

namespace Tests\Feature;

use App\Services\Ai\HistorialCliente;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Historial del cliente: solo tickets suyos, de su mismo correo o de su hotel; resumidos, sin datos personales ni notas internas. */
class HistorialClienteTest extends TestCase
{
    use RefreshDatabase;

    private int $uid;

    protected function setUp(): void
    {
        parent::setUp();
        $this->uid = DB::table('users')->insertGetId(['name' => 'Ana', 'email' => 'ana@x.com', 'password' => 'x']);
    }

    private function contacto(string $nombre, string $email, ?int $sede = null): int
    {
        return DB::table('contacts')->insertGetId(['name' => $nombre, 'email' => $email, 'sede_id' => $sede, 'sede_verificada_at' => $sede ? now() : null]);
    }

    private function ticket(int $contacto, string $asunto, string $pregunta, ?string $respuesta, string $estado = 'resuelto', ?string $fecha = null, bool $nota = false): int
    {
        $f = $fecha ?? now()->toDateTimeString();
        $id = DB::table('tickets')->insertGetId(['code' => 'TK-' . uniqid(), 'subject' => $asunto, 'status' => $estado, 'priority' => 'media', 'channel' => 'email',
            'contact_id' => $contacto, 'opened_at' => $f, 'last_message_at' => $f, 'created_at' => $f, 'updated_at' => $f]);
        $m = fn ($dir, $body, $nota = 0, $autor = null) => DB::table('messages')->insert(['contact_id' => $contacto, 'wa_id' => '', 'ticket_id' => $id,
            'direction' => $dir, 'body' => $body, 'is_internal_note' => $nota, 'author_user_id' => $autor, 'created_at' => $f]);
        $m('in', $pregunta);
        if ($respuesta) {
            $m('out', $respuesta, 0, $this->uid);
        }
        if ($nota) {
            $m('out', 'NOTA SECRETA moroso', 1, $this->uid);
        }
        return $id;
    }

    public function test_sin_tickets_anteriores_no_hay_historial(): void
    {
        $c = $this->contacto('Marta Gómez', 'marta@hotel.com');
        $actual = $this->ticket($c, 'Hoy', 'Algo', null, 'abierto');
        $this->assertSame('', HistorialCliente::para($c, $actual));
    }

    public function test_resume_lo_pedido_y_lo_contestado_sin_datos_personales_ni_notas(): void
    {
        $c = $this->contacto('Marta Gómez', 'marta@hotel.com');
        $viejo = $this->ticket($c, 'Etiqueta sin conexión', 'Soy Marta Gómez (marta@hotel.com, 600 123 456). La etiqueta no conecta.', 'Hola Marta, reinicie la etiqueta quitando las pilas 15 minutos.', 'resuelto', now()->subMonths(2)->toDateTimeString(), true);
        $actual = $this->ticket($c, 'Hoy', 'De nuevo', null, 'abierto');
        $h = HistorialCliente::para($c, $actual);
        $this->assertStringContainsString('HISTORIAL DEL CLIENTE', $h);
        $this->assertStringContainsString('Etiqueta sin conexión', $h);
        $this->assertStringContainsString('reinicie la etiqueta', $h);
        $this->assertStringContainsString('del propio cliente', $h);
        foreach (['marta@hotel.com', '600 123 456', 'Marta', 'Gómez', 'NOTA SECRETA', 'moroso'] as $prohibido) {
            $this->assertStringNotContainsString($prohibido, $h);
        }
        $this->assertStringNotContainsString('Hoy', $h, 'el ticket actual no se incluye');
        $this->assertNotNull($viejo);
    }

    public function test_incluye_lo_suyo_y_lo_del_mismo_hotel_pero_no_otros_ni_antiguos(): void
    {
        $g = DB::table('grupos')->insertGetId(['name' => 'G']);
        $m = DB::table('marcas')->insertGetId(['grupo_id' => $g, 'name' => 'M']);
        $s = DB::table('sedes')->insertGetId(['marca_id' => $m, 'name' => 'Hotel Sol', 'active' => 1]);
        $otraSede = DB::table('sedes')->insertGetId(['marca_id' => $m, 'name' => 'Hotel Luna', 'active' => 1]);
        $yo = $this->contacto('Ana Ruiz', 'ana@sol.com', $s);
        $companero = $this->contacto('Luis Pérez', 'luis@sol.com', $s);
        $ajeno = $this->contacto('Pepe Otro', 'pepe@luna.com', $otraSede);
        $sinRelacion = $this->contacto('Zoe', 'zoe@otro.com');
        $this->ticket($yo, 'Por whatsapp', 'Pregunta wa', 'Respuesta wa');
        $this->ticket($companero, 'De un compañero', 'Pregunta comp', 'Respuesta comp');
        $this->ticket($ajeno, 'Hotel ajeno', 'Pregunta ajena', 'Respuesta ajena');
        $this->ticket($sinRelacion, 'Sin relación', 'Pregunta zoe', 'Respuesta zoe');
        $this->ticket($yo, 'Muy antiguo', 'Pregunta vieja', 'Respuesta vieja', 'resuelto', now()->subMonths(14)->toDateTimeString());
        $actual = $this->ticket($yo, 'Hoy', 'Ahora', null, 'abierto');

        $h = HistorialCliente::para($yo, $actual);
        $this->assertStringContainsString('Por whatsapp', $h);
        $this->assertStringContainsString('De un compañero', $h);
        $this->assertStringContainsString('otro usuario de su hotel', $h);
        foreach (['Hotel ajeno', 'Sin relación', 'Muy antiguo', 'Luis', 'Pérez'] as $no) {
            $this->assertStringNotContainsString($no, $h);
        }
    }

    public function test_como_mucho_tres_tickets(): void
    {
        $c = $this->contacto('Marta', 'm@h.com');
        foreach (range(1, 5) as $i) {
            $this->ticket($c, "Asunto $i", "Pregunta $i", "Respuesta $i");
        }
        $this->assertSame(3, substr_count(HistorialCliente::para($c, null), "\n- 2"));
    }
}
