<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Borra los resúmenes de tickets (ai_summaries) con más de N días: contienen texto de clientes y no
 * deben guardarse para siempre. Si hace falta, se vuelve a generar al pedirlo. Conserva el mes en curso
 * (el gasto del tope mensual se calcula con estas filas), así que el mínimo es 35 días.
 */
class AiPurgarResumenes extends Command
{
    protected $signature = 'ai:purgar-resumenes {--dias=90 : Antigüedad a partir de la cual se borran (mínimo 35)} {--simular : Solo cuenta}';

    protected $description = 'Borra los resúmenes de tickets de la IA más antiguos que N días';

    public function handle(): int
    {
        $dias = max(35, (int) $this->option('dias'));
        $q = DB::table('ai_summaries')->where('created_at', '<', now()->subDays($dias));
        $n = $this->option('simular') ? $q->count() : $q->delete();
        $this->info(($this->option('simular') ? 'Se borrarían ' : 'Borrados ') . "$n resúmenes con más de $dias días.");
        return self::SUCCESS;
    }
}
