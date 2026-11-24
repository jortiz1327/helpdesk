<?php

namespace Tests\Unit;

use App\Services\Ai\AiSuggestionService;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/** Un hilo de WhatsApp con solo fotos sin texto y una respuesta del equipo no debe mandar a la IA una petición sin mensajes (HTTP 400). */
class AiTurnosVaciosTest extends TestCase
{
    private function turnos(array $conv): array
    {
        $s = (new \ReflectionClass(AiSuggestionService::class))->newInstanceWithoutConstructor();
        $m = new ReflectionMethod($s, 'comoTurnos');
        $m->setAccessible(true);
        return $m->invoke($s, $conv);
    }

    public function test_solo_una_respuesta_del_equipo_no_deja_la_peticion_vacia(): void
    {
        $t = $this->turnos([['role' => 'assistant', 'text' => 'Buenas tardes, ya las tienes configuradas']]);
        $this->assertCount(1, $t);
        $this->assertSame('user', $t[0]['role']);
        $this->assertNotSame('', $t[0]['content']);
    }

    public function test_una_conversacion_normal_no_cambia(): void
    {
        $t = $this->turnos([['role' => 'user', 'text' => 'hola'], ['role' => 'assistant', 'text' => 'qué tal'], ['role' => 'user', 'text' => 'fotos']]);
        $this->assertSame(['user', 'assistant', 'user'], array_column($t, 'role'));
    }
}
