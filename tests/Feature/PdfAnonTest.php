<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Exportar el hilo a PDF con «agentes anónimos»: los nombres de agente salen como
 * «Soporte» (para compartir el PDF fuera sin exponer quién atendió), pero el CLIENTE
 * se sigue mostrando. Se prueba el render de la plantilla, sin generar el binario.
 */
class PdfAnonTest extends TestCase
{
    private function render(bool $anon): string
    {
        $t = (object) [
            'code' => 'TK-1', 'subject' => 'Prueba', 'contact_name' => 'Cliente Ana',
            'contact_wa' => null, 'contact_email' => null, 'status' => 'abierto',
            'priority' => 'media', 'category_name' => 'General', 'agent_name' => 'Pedro Agente',
            'created_at' => '2026-09-01 10:00', 'resolved_at' => null,
        ];
        $messages = collect([
            (object) ['id' => 1, 'is_internal_note' => 0, 'direction' => 'out', 'author_name' => 'Pedro Agente', 'created_at' => 'x'],
            (object) ['id' => 2, 'is_internal_note' => 0, 'direction' => 'in',  'author_name' => null,          'created_at' => 'x'],
            (object) ['id' => 3, 'is_internal_note' => 1, 'direction' => 'out', 'author_name' => 'Pedro Agente', 'created_at' => 'x'],
        ]);
        $bodies = [1 => 'respuesta', 2 => 'pregunta', 3 => 'nota interna'];

        return view('ticket-pdf', [
            't' => $t, 'messages' => $messages, 'bodies' => $bodies, 'anon' => $anon,
            'statuses' => ['abierto' => 'Abierto'], 'priorities' => ['media' => 'Media'],
        ])->render();
    }

    public function test_sin_anonimizar_sale_el_nombre_del_agente(): void
    {
        $html = $this->render(false);
        $this->assertStringContainsString('Pedro Agente', $html);
        $this->assertStringContainsString('Cliente Ana', $html);
    }

    public function test_anonimizado_oculta_al_agente_pero_no_al_cliente(): void
    {
        $html = $this->render(true);
        $this->assertStringNotContainsString('Pedro Agente', $html);   // agente oculto (cabecera + mensajes + nota)
        $this->assertStringContainsString('Soporte', $html);           // sale como Soporte
        $this->assertStringContainsString('Cliente Ana', $html);       // el cliente SÍ se muestra
    }
}
