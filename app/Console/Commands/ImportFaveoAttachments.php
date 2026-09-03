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
        {--dir= : Carpeta con los adjuntos de DISCO de Faveo (los que no van como blob). Ficheros por su `name` (p. ej. 4369_foto.png)}
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

        // Carpeta de adjuntos de DISCO (copiada del servidor de Faveo). El fichero es {dir}/{name}.
        $dir = rtrim((string) $this->option('dir'), '/\\');
        if ($dir !== '' && !is_dir($dir)) {
            $this->error("La carpeta --dir no existe: «$dir»");
            return self::FAILURE;
        }

        $ok = 0; $sinMensaje = 0; $sinFichero = 0; $errores = 0; $deDisco = 0;
        $fav->table('ticket_attachment')->orderBy('id')->chunkById(25, function ($atts) use (&$ok, &$sinMensaje, &$sinFichero, &$errores, &$deDisco, $map, $apply, $att, $dir) {
            foreach ($atts as $a) {
                $par = $map[(int) $a->thread_id] ?? null;
                if (!$par) { $sinMensaje++; continue; }              // adjunto de un hilo que no se importó

                $raw = (string) $a->file;                             // 1º: blob en la BD
                if ($raw === '' && $dir !== '' && $a->name) {         // 2º: fichero en disco {dir}/{name}
                    $ruta = $dir . '/' . ltrim((string) $a->name, '/\\');
                    if (is_file($ruta)) { $raw = (string) @file_get_contents($ruta); $deDisco++; }
                }
                if ($raw === '') { $sinFichero++; continue; }         // ni blob ni fichero en la carpeta
                if (!$apply) { $ok++; continue; }

                [$mid, $tid] = $par;
                try {
                    // Nombre visible sin el prefijo numérico de Faveo (4369_foto.png → foto.png).
                    $nombre = preg_replace('/^\d+_/', '', (string) ($a->name ?: 'adjunto')) ?: 'adjunto';
                    $att->storeRaw($nombre, $raw, $a->type ?: null, (int) $tid, (int) $mid);
                    $ok++;
                } catch (\Throwable $e) {
                    $errores++;
                }
            }
        });

        $this->info("Colgados: $ok (de disco: $deDisco) · Sin mensaje (hilo no importado): $sinMensaje · Sin fichero: $sinFichero · Errores: $errores");
        if (!$apply) $this->warn('DRY-RUN: no se ha guardado nada. Añade --apply.');
        return self::SUCCESS;
    }
}
