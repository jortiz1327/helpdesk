<?php

namespace App\Console\Commands;

use App\Services\Ai\AiKnowledge;
use App\Services\Ai\Scrub;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Pone al día lo ya indexado en ai_documents sin volver a generarlo:
 *  - tapa las contraseñas que el equipo escribía en sus respuestas (y esos tickets quedan EXCLUIDOS de los ejemplos);
 *  - marca como DESFASADOS los tickets donde el equipo hacía por el cliente lo que hoy hace el propio hotel.
 * Es idempotente: se puede repetir. Con --simular solo cuenta, sin tocar nada.
 */
class AiLimpiarConocimiento extends Command
{
    protected $signature = 'ai:limpiar-conocimiento {--simular : Solo cuenta, no escribe}';

    protected $description = 'Tapa contraseñas y marca como desfasados los tickets antiguos de la base de conocimiento de la IA';

    public function handle(): int
    {
        $simular = (bool) $this->option('simular');
        $claves = $excluidos = $desfasados = 0;

        DB::table('ai_documents')->whereIn('source_type', ['ticket', 'correccion'])->orderBy('id')
            ->chunkById(200, function ($docs) use ($simular, &$claves, &$excluidos, &$desfasados) {
                foreach ($docs as $d) {
                    $nuevo = Scrub::contrasenas((string) $d->content);
                    $cambios = [];
                    if ($nuevo !== $d->content) {
                        $claves++;
                        $cambios['content'] = $nuevo;
                        if ($d->source_type === 'ticket') {
                            $cambios['desfasado'] = 2;
                            $excluidos++;
                        }
                    }
                    if ($d->source_type === 'ticket' && (int) $d->desfasado === 0 && ! isset($cambios['desfasado'])) {
                        $p = strpos($nuevo, 'Respuesta del equipo:');
                        if ($p !== false && AiKnowledge::desfase(substr($nuevo, $p)) === 1) {
                            $cambios['desfasado'] = 1;
                            $desfasados++;
                        }
                    }
                    if ($cambios && ! $simular) {
                        DB::table('ai_documents')->where('id', $d->id)->update($cambios);
                    }
                }
            });

        $this->line(($simular ? '[simulación] ' : '') . "Documentos con contraseñas tapadas: $claves · tickets excluidos: $excluidos · tickets marcados como desfasados: $desfasados.");
        return self::SUCCESS;
    }
}
