<?php

namespace App\Console\Commands;

use App\Services\AttachmentService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Bloque 2 del import de Faveo: cuelga los adjuntos (guardados como blob en
 * `ticket_attachment.file`) de los MENSAJES ya importados.
 *
 * El enganche es el `wamid='fav:{thread_id}'` que dejó `faveo:import` en cada
 * mensaje. Requiere haber corrido antes `faveo:import --apply`.
 *
 *   php artisan faveo:attachments            (previsualiza)
 *   php artisan faveo:attachments --apply     (cuelga los ficheros)
 */
class ImportFaveoAttachments extends Command
{
    protected $signature = 'faveo:attachments
        {--apply : Guarda los ficheros (por defecto solo cuenta)}
        {--db=faveo_old : BD con el dump de Faveo (si usas BD aparte)}
        {--prefix= : Prefijo de las tablas de Faveo si están en la MISMA BD del helpdesk (p. ej. fav_) — sin BD puente}
        {--source=import-faveo : Marca de los tickets importados}';

    protected $description = 'Cuelga los adjuntos de Faveo de los mensajes ya importados (Bloque 2)';

    public function handle(AttachmentService $att): int
    {
        $apply  = (bool) $this->option('apply');
        $source = (string) $this->option('source');

        $prefix = (string) $this->option('prefix');
        $favCfg = config('database.connections.mysql');
        if ($prefix !== '') {
            $favCfg['prefix'] = $prefix;
            if (($db = (string) $this->option('db')) !== 'faveo_old') $favCfg['database'] = $db;
        } else {
            $favCfg['database'] = (string) $this->option('db');
        }
        config(['database.connections.faveo' => $favCfg]);
        $fav = DB::connection('faveo');
        try { $fav->getPdo(); } catch (\Throwable $e) {
            $this->error('No conecto con «' . $this->option('db') . '»: ' . $e->getMessage());
            return self::FAILURE;
        }

        // Mapa: thread_id de Faveo => [message_id, ticket_id] de los mensajes importados.
        $rows = DB::table('messages as m')->join('tickets as t', 't.id', '=', 'm.ticket_id')
            ->where('t.source', $source)->where('m.wamid', 'like', 'fav:%')
            ->get(['m.id as mid', 'm.ticket_id as tid', 'm.wamid']);
        $map = [];
        foreach ($rows as $r) $map[(int) substr($r->wamid, 4)] = [$r->mid, $r->tid];
        $this->info(($apply ? '🖊  APLICANDO' : '👀 DRY-RUN') . ' · ' . count($map) . ' mensajes importados con enlace a Faveo');

        $ok = 0; $sinMensaje = 0; $sinFichero = 0; $errores = 0;
        $fav->table('ticket_attachment')->orderBy('id')->chunkById(25, function ($atts) use (&$ok, &$sinMensaje, &$sinFichero, &$errores, $map, $apply, $att) {
            foreach ($atts as $a) {
                $par = $map[(int) $a->thread_id] ?? null;
                if (!$par) { $sinMensaje++; continue; }              // adjunto de un hilo que no se importó
                $file = (string) $a->file;
                if ($file === '') { $sinFichero++; continue; }        // estaba en disco de Faveo, no en la BD
                if (!$apply) { $ok++; continue; }
                [$mid, $tid] = $par;
                try {
                    $att->storeRaw((string) ($a->name ?: 'adjunto'), $file, $a->type ?: null, (int) $tid, (int) $mid);
                    $ok++;
                } catch (\Throwable $e) {
                    $errores++;
                }
            }
        });

        $this->info("Colgados: $ok · Sin mensaje (hilo no importado): $sinMensaje · Sin fichero en BD: $sinFichero · Errores: $errores");
        if (!$apply) $this->warn('DRY-RUN: no se ha guardado nada. Añade --apply.');
        return self::SUCCESS;
    }
}
