<?php

namespace Tests\Feature;

use App\Models\EmailBan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * La lista negra de correo entrante: bloquea por dirección exacta o por dominio,
 * solo entradas activas. Tras quitar el LOWER(email) (rompía el índice único), la
 * comparación se apoya en la collation _ci: este test fija que sigue casando sin
 * distinguir mayúsculas.
 */
class EmailBanTest extends TestCase
{
    use RefreshDatabase;

    public function test_bloquea_por_direccion_dominio_activo_y_case_insensitive(): void
    {
        DB::table('email_bans')->insert([
            ['email' => 'Malo@Spam.com', 'active' => 1],   // guardado con mayúsculas a propósito
            ['email' => '@baneado.com',  'active' => 1],   // dominio entero
            ['email' => 'viejo@x.com',   'active' => 0],   // inactivo → no cuenta
        ]);

        $this->assertTrue(EmailBan::isBanned('malo@spam.com'));       // exacta, case-insensitive
        $this->assertTrue(EmailBan::isBanned('MALO@SPAM.COM'));       // la consulta también en mayúsculas
        $this->assertTrue(EmailBan::isBanned('quien.sea@baneado.com')); // por dominio
        $this->assertFalse(EmailBan::isBanned('viejo@x.com'));        // inactivo
        $this->assertFalse(EmailBan::isBanned('libre@ok.com'));       // no baneado
        $this->assertFalse(EmailBan::isBanned(''));                   // vacío
    }
}
