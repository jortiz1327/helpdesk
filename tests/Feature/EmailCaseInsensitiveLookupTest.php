<?php

namespace Tests\Feature;

use App\Services\PortalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Quitamos el LOWER(email) de las búsquedas (rompía el índice). La corrección se
 * apoya en que la columna es utf8mb4_unicode_ci (case-insensitive): un correo
 * guardado con mayúsculas DEBE casar con la consulta en minúsculas. Este test fija
 * esa garantía: si alguien pasara la columna a _bin, saltaría aquí.
 */
class EmailCaseInsensitiveLookupTest extends TestCase
{
    use RefreshDatabase;

    public function test_correo_guardado_con_mayusculas_casa_con_la_busqueda_en_minusculas(): void
    {
        DB::table('contacts')->insert([
            'name'  => 'Mix',
            'email' => 'Cliente.MAYUS@Example.COM',   // guardado con mayúsculas a propósito
        ]);

        $portal = app(PortalService::class);

        // La consulta ya no usa LOWER(): confía en la colación _ci para casar.
        $this->assertTrue($portal->contactoExiste('cliente.mayus@example.com'));
        $this->assertFalse($portal->contactoExiste('otro@example.com'));
    }
}
