<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;

abstract class TestCase extends BaseTestCase
{
    /**
     * RED DE SEGURIDAD: los tests usan `RefreshDatabase`, que BORRA y remigra la BD.
     * Aquí se aborta si la conexión no apunta a una BD de test (nombre acabado en
     * «_test»). Así, aunque phpunit.xml estuviera mal configurado, jamás se toca la
     * base real (`helpdesk`).
     */
    protected function setUp(): void
    {
        parent::setUp();

        $db = (string) DB::connection()->getDatabaseName();
        if (!str_ends_with($db, '_test') && $db !== ':memory:') {
            $this->fail("ABORTADO: los tests apuntan a la BD «{$db}», que no es de test. "
                . 'Configura DB_DATABASE=helpdesk_test en phpunit.xml.');
        }
    }
}
