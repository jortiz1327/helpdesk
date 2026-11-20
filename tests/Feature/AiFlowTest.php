<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class AiFlowTest extends TestCase
{
    public function test_la_pagina_del_flujo_solo_se_abre_con_el_enlace_firmado(): void
    {
        $this->get('/flujo-ia')->assertForbidden();
        $this->get('/flujo-ia?expires=1&signature=falsa')->assertForbidden();
        $url = URL::temporarySignedRoute('ia.flujo.agentes', now()->addHour(), [], false);
        $this->get($url)->assertOk()->assertSee('Volver a la app')->assertSee('flujo-info', false)->assertDontSee('Detalle de cada capítulo');
        $this->get($url)->assertHeader('X-Robots-Tag', 'noindex');
    }

    public function test_el_enlace_del_flujo_exige_sesion(): void
    {
        $this->getJson('/api/ai_flow.php')->assertUnauthorized();
    }
}
