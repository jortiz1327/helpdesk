<?php

namespace Tests\Feature;

use App\Services\MailService;
use Tests\TestCase;

/**
 * Cortafuegos anti «correo venenoso»: un correo que falla siempre al procesarse NO
 * debe bloquear la entrada del buzón para siempre. Tras N intentos seguidos sobre el
 * mismo UID se salta (se avanza) en vez de reintentar sin fin. Aquí se prueba la
 * decisión pura, sin depender de un IMAP real.
 */
class MailPoisonTest extends TestCase
{
    public function test_un_uid_nuevo_empieza_en_el_intento_1(): void
    {
        // uid distinto del que veníamos arrastrando (0) → primer intento, se recuerda.
        [$saltar, $failUid, $failCount] = MailService::decidirFallo(101, 0, 0);
        $this->assertFalse($saltar);
        $this->assertSame(101, $failUid);
        $this->assertSame(1, $failCount);
    }

    public function test_los_fallos_seguidos_sobre_el_mismo_uid_se_acumulan(): void
    {
        [$saltar, , $failCount] = MailService::decidirFallo(101, 101, 1);
        $this->assertFalse($saltar);
        $this->assertSame(2, $failCount);
    }

    public function test_tras_el_maximo_de_intentos_se_salta_el_correo(): void
    {
        // El intento nº MAX debe saltar (se avanza el buzón y se limpia el contador).
        [$saltar, $failUid, $failCount] = MailService::decidirFallo(101, 101, MailService::MAX_MAIL_ATTEMPTS - 1);
        $this->assertTrue($saltar);
        $this->assertSame(0, $failUid);
        $this->assertSame(0, $failCount);
    }

    public function test_un_uid_distinto_reinicia_el_contador(): void
    {
        // Otro correo falla: no hereda los intentos del anterior.
        [$saltar, $failUid, $failCount] = MailService::decidirFallo(200, 101, 4);
        $this->assertFalse($saltar);
        $this->assertSame(200, $failUid);
        $this->assertSame(1, $failCount);
    }
}
