<?php

namespace Tests\Feature;

use App\Services\ShiftService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Reparto del agente de guardia cuando un turno lo cubren VARIOS: en vez de un contador,
 * se mira el historial de asignaciones y le toca a quien lleva MÁS tiempo sin que le caiga
 * nada. Regla sutil (fechas + desempate por carga) fácil de romper sin darse cuenta.
 */
class ShiftRepartoTest extends TestCase
{
    use RefreshDatabase;

    private int $tk;

    protected function setUp(): void
    {
        parent::setUp();
        // Un ticket cualquiera del que colgar los eventos (la tabla exige ticket_id).
        $cid = DB::table('contacts')->insertGetId(['name' => 'C']);
        $this->tk = DB::table('tickets')->insertGetId([
            'code' => 'TK-1', 'subject' => 's', 'status' => 'abierto', 'priority' => 'media',
            'channel' => 'email', 'contact_id' => $cid, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** Registra que al agente $userId se le asignó un ticket en el instante $cuando. */
    private function asignado(int $userId, string $cuando): void
    {
        DB::table('ticket_events')->insert([
            'ticket_id' => $this->tk, 'type' => 'assign', 'to_value' => (string) $userId, 'created_at' => $cuando,
        ]);
    }

    /** Convierte ids en la estructura de «gente» que espera repartir(). */
    private function gente(int ...$ids): array
    {
        return array_map(fn ($id) => [
            'user_id' => $id, 'name' => "U$id", 'substitute' => false, 'replaces' => null,
        ], $ids);
    }

    public function test_si_cubre_uno_solo_se_lo_lleva_el(): void
    {
        $elegido = app(ShiftService::class)->repartir($this->gente(10));
        $this->assertSame(10, $elegido['user_id']);
    }

    public function test_a_quien_no_le_ha_tocado_nunca_entra_el_primero(): void
    {
        $this->asignado(10, now()->subHour()->format('Y-m-d H:i:s'));   // 10 ya recibió uno
        // 20 no aparece en el historial → lleva más tiempo sin nada → le toca.
        $elegido = app(ShiftService::class)->repartir($this->gente(10, 20));
        $this->assertSame(20, $elegido['user_id']);
    }

    public function test_le_toca_a_quien_lleva_mas_tiempo_sin_recibir(): void
    {
        $this->asignado(10, now()->subHours(3)->format('Y-m-d H:i:s'));   // hace más → le toca
        $this->asignado(20, now()->subHour()->format('Y-m-d H:i:s'));     // más reciente
        $elegido = app(ShiftService::class)->repartir($this->gente(10, 20));
        $this->assertSame(10, $elegido['user_id']);
    }

    public function test_a_igualdad_de_fecha_desempata_quien_menos_lleva(): void
    {
        $mismo = now()->subHour()->format('Y-m-d H:i:s');
        $this->asignado(10, $mismo);
        $this->asignado(10, $mismo);   // 10 lleva 2 en ese mismo segundo
        $this->asignado(20, $mismo);   // 20 solo 1 → desempata a su favor
        $elegido = app(ShiftService::class)->repartir($this->gente(10, 20));
        $this->assertSame(20, $elegido['user_id']);
    }
}
