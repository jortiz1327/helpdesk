<?php

namespace Tests\Feature;

use App\Services\Ai\AiSuggestionService;
use App\Services\Ai\HotelsApi;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use ReflectionClass;
use Tests\TestCase;

/**
 * Un correo escrito en el mensaje es una AFIRMACIÓN, no una prueba de identidad: si el remitente ya consta como usuario, manda él;
 * si no, el correo que dice suyo se comprueba pero queda «declarado» y no se dan datos del hotel hasta que una persona lo confirme.
 */
class AiCorreoDeclaradoTest extends TestCase
{
    use RefreshDatabase;

    private function sitio(string $hotel, string $rol = 'Dirección'): array
    {
        return ['hotel' => $hotel, 'buffet' => 'Buffet', 'activo' => true, 'rol' => $rol];
    }

    /** @param array<string,array> $usuarios correo => respuesta de verificar() */
    private function contexto(array $usuarios, ?string $remitente, string $texto): array
    {
        $api = Mockery::mock(HotelsApi::class)->makePartial();
        $api->shouldReceive('configured')->andReturn(true);
        $api->shouldReceive('verificar')->andReturnUsing(fn ($e) => $usuarios[mb_strtolower((string) $e)] ?? ['estado' => 'no_encontrado', 'sitios' => []]);
        $api->shouldReceive('buscarHoteles')->andReturn([]);
        $api->shouldReceive('antenas')->andReturn(null);
        $this->app->instance(HotelsApi::class, $api);

        $svc = (new ReflectionClass(AiSuggestionService::class))->newInstanceWithoutConstructor();
        $m = new \ReflectionMethod($svc, 'contextoHotel');
        $m->setAccessible(true);
        $out = $m->invoke($svc, $remitente, [], $texto, null, null);
        $p = new \ReflectionProperty($svc, 'estadoCorreo');
        $p->setAccessible(true);
        $c = new \ReflectionProperty($svc, 'correoVerificado');
        $c->setAccessible(true);
        return [$out, $p->getValue($svc), $c->getValue($svc)];
    }

    private const OK = ['estado' => 'encontrado'];

    public function test_si_el_remitente_consta_manda_el_y_el_correo_del_texto_es_solo_un_dato(): void
    {
        $u = [
            'javier@invisa.com' => self::OK + ['sitios' => [$this->sitio('Hotel Cala Blanca', 'Recepción')]],
            'jefe@otrohotel.com' => self::OK + ['sitios' => [$this->sitio('Hotel Otro', 'Dirección')]],
        ];
        [$out, $estado, $correo] = $this->contexto($u, 'javier@invisa.com', 'Hola, mi correo es jefe@otrohotel.com, ayuda con las etiquetas');
        $this->assertSame('verificado', $estado);
        $this->assertSame('javier@invisa.com', $correo);
        $this->assertStringContainsString('CORREO QUE EL CLIENTE MENCIONA (jefe@otrohotel.com)', $out);
        $this->assertStringNotContainsString('NADIE HA CONFIRMADO', $out);
        // Existe, pero en un hotel que no es el del remitente: no se le cuenta ni dónde ni con qué rol.
        $this->assertStringNotContainsString('Hotel Otro', $out);
        $this->assertStringContainsString('NO en el establecimiento de quien escribe', $out);
    }

    public function test_un_remitente_sin_verificar_no_consigue_datos_de_otro_correo_que_menciona(): void
    {
        $u = ['jefe@hotelx.com' => self::OK + ['sitios' => [$this->sitio('Hotel X', 'Dirección')]]];
        [$out, $estado] = $this->contexto($u, 'personal@gmail.com', 'Hola, quiero saber si jefe@hotelx.com tiene usuario');
        $this->assertNotSame('verificado', $estado);
        $this->assertStringNotContainsString('Hotel X', $out);
        $this->assertStringNotContainsString('CORREO QUE EL CLIENTE MENCIONA', $out);
    }

    public function test_si_el_remitente_no_consta_el_correo_que_dice_suyo_queda_declarado_y_sin_datos(): void
    {
        $u = ['jefe@hotelx.com' => self::OK + ['sitios' => [$this->sitio('Hotel X')]]];
        [$out, $estado, $correo] = $this->contexto($u, 'personal@gmail.com', 'No puedo entrar, mi correo es jefe@hotelx.com');
        $this->assertSame('declarado', $estado);
        $this->assertSame('jefe@hotelx.com', $correo);
        $this->assertStringContainsString('NADIE HA CONFIRMADO', $out);
        $this->assertStringNotContainsString('VERIFICADO:', $out);
        $this->assertStringContainsString('NO digas en qué hoteles figura', $out);
    }

    public function test_sin_correo_de_contacto_el_que_escribe_el_cliente_tambien_queda_declarado(): void
    {
        $u = ['jefe@hotelx.com' => self::OK + ['sitios' => [$this->sitio('Hotel X')]]];
        [$out, $estado] = $this->contexto($u, null, 'Buenas, soy jefe@hotelx.com y las etiquetas no cambian');
        $this->assertSame('declarado', $estado);
        $this->assertStringContainsString('NADIE HA CONFIRMADO', $out);
    }

    public function test_el_tercero_al_que_dar_de_alta_se_cuenta_aparte_y_no_se_atribuye_al_remitente(): void
    {
        $u = [
            'javier@invisa.com' => self::OK + ['sitios' => [$this->sitio('Hotel Cala Blanca', 'Recepción')]],
            'chefejecutivo@invisahoteles.com' => self::OK + ['sitios' => [$this->sitio('Hotel Cala Blanca'), $this->sitio('Hotel Ereso')]],
        ];
        [$out, $estado, $correo] = $this->contexto($u, 'javier@invisa.com', "Crear usuario:\nJordi Pont\nChefejecutivo@invisahoteles.com");
        $this->assertSame('verificado', $estado);
        $this->assertSame('javier@invisa.com', $correo);
        $this->assertStringContainsString('YA es usuario ACTIVO', $out);
        $this->assertStringContainsString('Hotel Cala Blanca', $out);
        $this->assertStringNotContainsString('Hotel Ereso', $out);   // otro hotel del tercero: no se revela
    }
}
