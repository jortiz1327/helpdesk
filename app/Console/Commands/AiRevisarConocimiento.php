<?php

namespace App\Console\Commands;

use App\Services\Ai\CoherenciaConocimiento;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;

/**
 * Revisión periódica del conocimiento de la IA (cada semana, y bajo demanda desde Ajustes): contradicciones entre los hechos vigentes y
 * los manuales, reglas, correcciones, plantillas y tickets antiguos, y desfases. No llama a ningún proveedor: no gasta nada.
 * Con --simular solo cuenta y enseña, sin marcar tickets ni guardar el resultado.
 */
class AiRevisarConocimiento extends Command
{
    protected $signature = 'ai:revisar-conocimiento {--simular : Solo enseña lo que encuentra, no marca ni guarda nada}';

    protected $description = 'Busca contradicciones y documentos desfasados en la memoria y los manuales de la IA';

    public function handle(): int
    {
        $marcar = ! $this->option('simular');
        if ($marcar) {
            Artisan::call('ai:limpiar-conocimiento');   // contraseñas tapadas y desfases de «lo que hoy hace el propio hotel» (idempotente)
        }
        $r = CoherenciaConocimiento::ejecutar($marcar);

        foreach (['ficheros' => 'Manuales y reglas', 'base' => 'Correcciones y plantillas'] as $k => $titulo) {
            $this->line($titulo . ': ' . count($r[$k]) . ' contradicción(es)');
            foreach ($r[$k] as $h) {
                $this->line("  · [{$h['hecho']}] {$h['donde']} → {$h['texto']}");
            }
        }
        $this->line('Tickets antiguos que contradicen un hecho' . ($marcar ? ' (marcados como desfasados)' : ' (se marcarían)') . ': ' . $r['tickets_marcados']);
        $this->{$r['total'] ? 'warn' : 'info'}($r['total'] ? "Hay {$r['total']} contradicción(es) por revisar." : 'Sin contradicciones.');
        return self::SUCCESS;
    }
}
