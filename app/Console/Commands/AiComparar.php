<?php

namespace App\Console\Commands;

use App\Services\Ai\AiProviders;
use App\Services\Ai\AiSuggestionService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

/**
 * Compara proveedores de IA con los MISMOS tickets ya resueltos, a ciegas: genera el borrador
 * con cada uno y los guarda barajados (A, B, C…) en un archivo para puntuar, y la clave de
 * qué letra era qué proveedor en otro. No envía nada a ningún cliente ni toca los tickets.
 *
 * Los datos personales (correos, teléfonos, nombre del cliente) se tapan antes de salir,
 * salvo que se pase --real. Pide confirmación antes de enviar nada fuera.
 */
class AiComparar extends Command
{
    protected $signature = 'ai:comparar
        {--tickets= : IDs de tickets separados por coma (si no, se eligen al azar)}
        {--ultimos=20 : Cuántos tickets resueltos o cerrados elegir al azar}
        {--proveedores= : anthropic,gemini,mistral (por defecto, los que tengan clave)}
        {--real : NO tapar datos personales}
        {--yes : No pedir confirmación}';

    protected $description = 'Compara a ciegas el borrador de varios proveedores de IA con tickets ya resueltos';

    public function handle(): int
    {
        $nombres = $this->option('proveedores')
            ? array_values(array_filter(array_map('trim', explode(',', (string) $this->option('proveedores')))))
            : array_keys(AiProviders::NOMBRES);

        $proveedores = [];
        foreach ($nombres as $n) {
            if (! isset(AiProviders::NOMBRES[$n])) {
                $this->error("Proveedor desconocido: $n");
                return self::FAILURE;
            }
            $p = AiProviders::make($n);
            if ($p->configured()) {
                $proveedores[$n] = $p;
            } else {
                $this->warn("Sin clave para $n (AI_KEY_" . strtoupper($n) . '): se omite.');
            }
        }
        if (count($proveedores) < 2) {
            $this->error('Hacen falta al menos dos proveedores con clave para comparar.');
            return self::FAILURE;
        }

        $ids = $this->option('tickets')
            ? array_values(array_filter(array_map('intval', explode(',', (string) $this->option('tickets')))))
            : DB::table('tickets as t')->whereIn('t.status', ['resuelto', 'cerrado'])
                ->whereExists(fn ($q) => $q->select(DB::raw(1))->from('messages as m')->whereColumn('m.ticket_id', 't.id')->where('m.direction', 'in')->where('m.is_internal_note', 0))
                ->inRandomOrder()->limit(max(1, (int) $this->option('ultimos')))->pluck('t.id')->all();
        if (! $ids) {
            $this->error('No hay tickets que comparar.');
            return self::FAILURE;
        }

        $real = (bool) $this->option('real');
        $this->line(count($ids) . ' tickets × ' . count($proveedores) . ' proveedores (' . implode(', ', array_keys($proveedores)) . ').');
        $this->line($real ? 'ATENCIÓN: se enviarán con datos personales reales.' : 'Se taparán correos, teléfonos y el nombre del cliente.');
        if (! $this->option('yes') && ! $this->confirm('El texto de esos tickets saldrá hacia esos proveedores. ¿Continuar?')) {
            return self::FAILURE;
        }

        $dir = storage_path('app/ai-comparar/' . now()->format('Ymd-His'));
        File::ensureDirectoryExists($dir);

        $md = "# Comparación a ciegas\n\nPara cada ticket, puntúa de 1 (inútil) a 5 (lo enviaría tal cual) cada borrador. La clave de quién es quién está en `clave.json`; no la abras hasta terminar.\n\n";
        $clave = [];
        $totales = [];

        foreach ($ids as $n => $id) {
            // El servicio se crea por proveedor: lleva estado temporal de anonimización.
            $ticket = DB::table('tickets')->where('id', $id)->first(['code', 'subject']);
            if (! $ticket) {
                continue;
            }
            $resultados = [];
            foreach ($proveedores as $nombre => $p) {
                $resultados[$nombre] = (new AiSuggestionService($p))->redactar((int) $id, $p, ! $real);
                $t = &$totales[$nombre];
                $t['ok'] = ($t['ok'] ?? 0) + (($resultados[$nombre]['status'] === 'ready') ? 1 : 0);
                $t['no_sabe'] = ($t['no_sabe'] ?? 0) + (($resultados[$nombre]['status'] === 'abstained') ? 1 : 0);
                $t['fallo'] = ($t['fallo'] ?? 0) + (($resultados[$nombre]['status'] === 'failed') ? 1 : 0);
                $t['in'] = ($t['in'] ?? 0) + (int) ($resultados[$nombre]['input_tokens'] ?? 0);
                $t['out'] = ($t['out'] ?? 0) + (int) ($resultados[$nombre]['output_tokens'] ?? 0);
                unset($t);
            }

            // Pregunta del cliente tal como salió (tapada si procede) y borradores barajados.
            $pregunta = $this->preguntaMostrada((int) $id, ! $real);
            $orden = array_keys($resultados);
            shuffle($orden);
            $md .= '## Ticket ' . ($n + 1) . ' · ' . $ticket->code . "\n\n**Cliente:**\n\n> " . str_replace("\n", "\n> ", $pregunta) . "\n\n";
            foreach ($orden as $i => $nombre) {
                $letra = chr(65 + $i);
                $r = $resultados[$nombre];
                $texto = $r['status'] === 'ready' ? $r['body']
                    : ($r['status'] === 'abstained' ? '_(No supo contestar)_' : '_(Falló: ' . ($r['error'] ?? 'error') . ')_');
                $md .= "**Borrador $letra** — puntuación: __ /5\n\n" . $texto . "\n\n";
                $clave[$ticket->code][$letra] = ['proveedor' => $nombre, 'modelo' => $r['model'] ?? null, 'estado' => $r['status'], 'tokens_in' => $r['input_tokens'] ?? null, 'tokens_out' => $r['output_tokens'] ?? null];
            }
            $md .= "---\n\n";
            $this->output->write('.');
        }
        $this->newLine();

        File::put($dir . '/ciega.md', $md);
        File::put($dir . '/clave.json', json_encode($clave, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        $this->table(['Proveedor', 'Borradores', 'No supo', 'Fallos', 'Tokens entrada', 'Tokens salida'],
            array_map(fn ($n, $t) => [$n, $t['ok'], $t['no_sabe'], $t['fallo'], $t['in'], $t['out']], array_keys($totales), $totales));
        $this->info("Listo. Puntúa: $dir/ciega.md  ·  Clave: $dir/clave.json");
        return self::SUCCESS;
    }

    /** El último mensaje del cliente, como saldría hacia fuera (tapado si procede). */
    private function preguntaMostrada(int $ticketId, bool $tapar): string
    {
        $m = DB::table('messages')->where('ticket_id', $ticketId)->where('direction', 'in')->where('is_internal_note', 0)->orderByDesc('id')->value('body');
        $t = trim(html_entity_decode(strip_tags((string) preg_replace('#<(br|/p|/div|/li)\s*/?>#i', "\n", (string) $m)), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        if ($tapar) {
            $t = (string) preg_replace('/[\w.+-]+@[\w-]+(\.[\w-]+)+/u', '[email]', $t);
            $t = (string) preg_replace('/\+?\d[\d .\/-]{6,}\d/', '[tel]', $t);
            $nombre = trim((string) DB::table('tickets as t')->join('contacts as c', 'c.id', '=', 't.contact_id')->where('t.id', $ticketId)->value('c.name'));
            if (mb_strlen($nombre) >= 3) {
                $t = str_ireplace($nombre, '[cliente]', $t);
            }
        }
        return mb_substr($t, 0, 1200);
    }
}
