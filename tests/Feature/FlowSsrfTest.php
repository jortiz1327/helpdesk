<?php

namespace Tests\Feature;

use App\Services\FlowEngine;
use Tests\TestCase;

/**
 * El nodo «Petición API» del flujo no debe poder llamar a servicios INTERNOS (SSRF):
 * la URL puede llevar {{{vars}}} del cliente. Se bloquean loopback, localhost, la IP
 * de metadata de la nube y las redes privadas/reservadas.
 */
class FlowSsrfTest extends TestCase
{
    private function segura(string $url): bool
    {
        $engine = app(FlowEngine::class);
        $m = new \ReflectionMethod($engine, 'urlSegura');
        $m->setAccessible(true);
        return (bool) $m->invoke($engine, $url);
    }

    public function test_bloquea_destinos_internos(): void
    {
        // IPs literales (sin DNS) para que el test sea determinista y sin red.
        $this->assertFalse($this->segura('http://169.254.169.254/latest/meta-data/'));   // metadata cloud
        $this->assertFalse($this->segura('http://127.0.0.1:8080/admin'));                 // loopback
        $this->assertFalse($this->segura('http://10.1.2.3/'));                            // privada
        $this->assertFalse($this->segura('http://192.168.1.1/'));                         // privada
        $this->assertFalse($this->segura('http://172.16.5.5/'));                          // privada
        $this->assertFalse($this->segura('http://localhost/'));                           // por nombre
        $this->assertFalse($this->segura('http://[::1]/'));                               // loopback IPv6
    }

    public function test_permite_ip_publica(): void
    {
        $this->assertTrue($this->segura('http://8.8.8.8/'));            // pública
        $this->assertTrue($this->segura('https://93.184.216.34/'));    // pública (IP literal)
    }
}
