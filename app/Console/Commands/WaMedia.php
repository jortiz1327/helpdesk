<?php

namespace App\Console\Commands;

use App\Services\WhatsAppService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Mime\MimeTypes;

/**
 * Archivos del WhatsApp de SOPORTE en el servidor durante 6 meses.
 *
 *   guardar → baja de Meta los archivos que aún no tienen copia (cada minuto). Solo
 *             fotos, audios, documentos y stickers: los vídeos pesan mucho y siguen
 *             viéndose desde Meta mientras los guarde. Nunca toca Campañas.
 *   purgar  → borra las copias con más de 6 meses (cada noche), para no llenar el disco.
 *             El mensaje se queda con el aviso «no disponible · solo está en el móvil».
 */
class WaMedia extends Command
{
    public const MESES = 6;
    public const TIPOS = ['image', 'audio', 'document', 'sticker'];
    private const MAX_INTENTOS = 5;

    protected $signature = 'wa:media {accion : guardar|purgar}
        {--dias=2 : guardar: solo mensajes de los últimos N días (Meta los borra a las semanas)}
        {--limite=30 : guardar: archivos por ejecución}';

    protected $description = 'WhatsApp de soporte: guarda los archivos en el servidor 6 meses y luego los borra';

    public function handle(WhatsAppService $wa): int
    {
        return match ($this->argument('accion')) {
            'guardar' => $this->guardar($wa->paraFuncion('soporte')),
            'purgar'  => $this->purgar(),
            default   => tap(self::FAILURE, fn () => $this->error('Acción: guardar | purgar')),
        };
    }

    protected function guardar(WhatsAppService $wa): int
    {
        $pend = DB::table('messages')
            ->where('funcion', 'soporte')->whereIn('type', self::TIPOS)
            ->whereNotNull('media_url')->whereNull('media_path')
            ->where('media_tries', '<', self::MAX_INTENTOS)
            ->where('created_at', '>=', now()->subDays(max(1, (int) $this->option('dias'))))
            ->orderBy('id')->limit(max(1, (int) $this->option('limite')))
            ->get(['id', 'media_url', 'media_mime', 'created_at']);

        $ok = 0;
        foreach ($pend as $m) {
            $bin = null;
            try { $bin = $wa->mediaBinary((string) $m->media_url); } catch (\Throwable) { /* cuenta como intento */ }
            if (!$bin) {
                DB::table('messages')->where('id', $m->id)->increment('media_tries');
                continue;
            }
            $mime = strtolower(trim(explode(';', (string) ($m->media_mime ?: $bin['type']))[0]));
            $ext  = MimeTypes::getDefault()->getExtensions($mime)[0] ?? 'bin';
            $ruta = 'wa-media/' . date('Y/m', strtotime((string) $m->created_at)) . "/{$m->id}.{$ext}";
            Storage::disk('local')->put($ruta, $bin['binary']);
            DB::table('messages')->where('id', $m->id)->update(['media_path' => $ruta]);
            $ok++;
        }
        if ($pend->count()) $this->info("Archivos guardados: {$ok} de {$pend->count()}");
        return self::SUCCESS;
    }

    protected function purgar(): int
    {
        $n = 0;
        DB::table('messages')->whereNotNull('media_path')
            ->where('created_at', '<', now()->subMonths(self::MESES))
            ->orderBy('id')->select(['id', 'media_path'])
            ->chunkById(500, function ($filas) use (&$n) {
                foreach ($filas as $m) {
                    Storage::disk('local')->delete($m->media_path);
                    // Sin copia y Meta ya lo borró: la ficha muestra «no disponible».
                    DB::table('messages')->where('id', $m->id)->update(['media_path' => null, 'media_url' => null]);
                    $n++;
                }
            });
        $this->info("Archivos de más de " . self::MESES . " meses borrados: {$n}");
        return self::SUCCESS;
    }
}
