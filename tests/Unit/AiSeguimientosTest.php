<?php

namespace Tests\Unit;

use App\Console\Commands\AiNuevosCasos;
use PHPUnit\Framework\TestCase;

class AiSeguimientosTest extends TestCase
{
    private function m(string $role, string $text): array
    {
        return ['role' => $role, 'text' => $text];
    }

    public function test_une_los_mensajes_seguidos_del_mismo_lado_y_salta_vacios(): void
    {
        $t = AiNuevosCasos::turnos([$this->m('user', 'hola'), $this->m('user', 'una cosa más'), $this->m('assistant', ' '), $this->m('assistant', 'dime'), $this->m('user', 'vale')]);
        $this->assertSame(['user', 'assistant', 'user'], array_column($t, 'role'));
        $this->assertSame("hola\n\nuna cosa más", $t[0]['text']);
    }

    public function test_corta_en_el_segundo_mensaje_del_cliente_si_le_sigue_una_persona(): void
    {
        $t = [$this->m('user', 'primer mensaje largo'), $this->m('assistant', 'pregunta nuestra'), $this->m('user', 'ya lo hice y sigue igual'), $this->m('assistant', 'respuesta nuestra'), $this->m('user', 'tercer mensaje del cliente ok'), $this->m('assistant', 'otra respuesta')];
        $this->assertSame(2, AiNuevosCasos::puntoDeCorte($t));
        $this->assertSame(4, AiNuevosCasos::puntoDeCorte($t, true));
    }

    public function test_sin_respuesta_de_una_persona_detras_no_hay_corte(): void
    {
        $t = [$this->m('user', 'primer mensaje largo'), $this->m('assistant', 'pregunta nuestra'), $this->m('user', 'ya lo hice y sigue igual')];
        $this->assertNull(AiNuevosCasos::puntoDeCorte($t));
        $this->assertNull(AiNuevosCasos::puntoDeCorte([$this->m('user', 'solo uno')]));
    }
}
