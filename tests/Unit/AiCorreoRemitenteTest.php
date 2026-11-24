<?php

namespace Tests\Unit;

use App\Services\Ai\AiSuggestionService as S;
use PHPUnit\Framework\TestCase;

/** TK-2611-0143: «dad de alta a Jordi Pont, Chefejecutivo@…» no es el correo de quien escribe. */
class AiCorreoRemitenteTest extends TestCase
{
    public function test_un_correo_para_dar_de_alta_a_otra_persona_no_es_el_del_cliente(): void
    {
        $t = "Hola, es posible que utilizara el mismo que el chef de Cala Blanca, si no está creado agradecería que se le creara:\n\nJordi Pont\nChefejecutivo@invisahoteles.com\n\nGracias";
        $this->assertNull(S::correoPropioEnTexto($t));
        $this->assertSame(['Chefejecutivo@invisahoteles.com'], S::correosEnTexto($t));
    }

    public function test_si_el_cliente_dice_que_el_correo_es_suyo_se_detecta(): void
    {
        $this->assertSame('recepcion@hotelx.com', S::correoPropioEnTexto('Hola, mi usuario es recepcion@hotelx.com y no puedo entrar'));
        $this->assertSame('jefe@hotelx.com', S::correoPropioEnTexto('Accedo con jefe@hotelx.com.'));
        $this->assertSame('jefe@hotelx.com', S::correoPropioEnTexto('No puedo entrar con mi correo: jefe@hotelx.com'));
    }

    public function test_los_correos_repetidos_se_cuentan_una_vez(): void
    {
        $this->assertSame(['a@x.com', 'b@x.com'], S::correosEnTexto('a@x.com y A@x.com, b@x.com'));
    }
}
