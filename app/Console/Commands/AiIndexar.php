<?php

namespace App\Console\Commands;

use App\Services\Ai\AiKnowledge;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Alimenta la base de conocimiento de la IA con tickets resueltos y manuales. No envía nada
 * a ningún proveedor: solo copia texto limpio (sin datos personales ni notas internas) a
 * la tabla ai_documents. Es incremental: lo ya indexado se salta, salvo con --rehacer.
 */
class AiIndexar extends Command
{
    protected $signature = 'ai:indexar
        {--solo= : tickets o manuales (por defecto, los dos)}
        {--rehacer : Vuelve a generar lo ya indexado}
        {--limite= : Máximo de tickets nuevos por ejecución}';

    protected $description = 'Indexa tickets resueltos y manuales como conocimiento de la IA';

    public function handle(AiKnowledge $k): int
    {
        $solo = (string) $this->option('solo');
        if ($solo !== '' && ! in_array($solo, ['tickets', 'manuales'], true)) {
            $this->error('--solo debe ser tickets o manuales');
            return self::FAILURE;
        }

        if ($solo !== 'manuales') {
            [$nuevos, $saltados] = $k->indexarTickets((bool) $this->option('rehacer'), $this->option('limite') !== null ? (int) $this->option('limite') : null);
            $this->line("Tickets: $nuevos indexados, $saltados saltados (ya estaban o no valían).");
        }
        if ($solo !== 'tickets') {
            $this->line('Manuales: ' . $k->indexarManuales() . ' trozos.');
        }
        $this->info('En la base de conocimiento: ' . DB::table('ai_documents')->count() . ' documentos.');
        return self::SUCCESS;
    }
}
