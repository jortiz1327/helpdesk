<?php

namespace App\Services;

use App\Models\Warranty;
use App\Models\WarrantyItem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Fotos de los dispositivos revisados. Cada una es una copia propia en el disco local
 * (fuera de public/), venga de una subida o de una imagen que el cliente mandó en el
 * ticket: así no depende de que el adjunto se borre o de que caduque el medio de
 * WhatsApp. source queda solo como procedencia.
 *
 * Las imágenes del ticket se identifican con «a:ID» (adjunto) o «m:ID» (mensaje de
 * WhatsApp con imagen) y solo se aceptan si son del ticket de la garantía.
 */
class WarrantyPhotos
{
    public const MAX_BYTES = 15 * 1024 * 1024;
    private const EXT = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'];
    private const META_DAYS = 30;           // lo que guarda Meta un medio de WhatsApp
    private const LOGO_BYTES = 30 * 1024;   // imágenes en línea más pequeñas: firmas y logos del correo

    /** Fotos de varios dispositivos: [item_id => [{id, type, name, from_ticket}]]. */
    public static function forItems(array $itemIds): array
    {
        $out = [];
        if (! $itemIds) return $out;
        foreach (DB::table('warranty_item_photos')->whereIn('warranty_item_id', $itemIds)->orderBy('id')
            ->get(['id', 'warranty_item_id', 'type', 'name', 'source']) as $p) {
            $out[$p->warranty_item_id][] = ['id' => $p->id, 'type' => $p->type, 'name' => $p->name, 'source' => $p->source];
        }
        return $out;
    }

    /** Imágenes del ticket de la garantía, la más reciente primero. */
    public static function ticketImages(Warranty $w): array
    {
        if (! $w->ticket_id) return [];
        $out = [];

        $adjuntos = DB::table('attachments as a')->leftJoin('messages as m', 'm.id', '=', 'a.message_id')
            ->where('a.ticket_id', $w->ticket_id)->orderByDesc('a.id')
            ->get(['a.id', 'a.name', 'a.path', 'a.size', 'a.inline', 'a.created_at', 'm.direction']);
        foreach ($adjuntos as $a) {
            $mime = AttachmentService::mimeDe((string) $a->path);
            if (! isset(self::EXT[$mime]) || ($a->inline && $a->size < self::LOGO_BYTES)) continue;
            $out[] = ['src' => 'a:' . $a->id, 'name' => $a->name, 'at' => (string) $a->created_at, 'from' => $a->direction === 'out' ? 'agente' : 'cliente'];
        }

        // De WhatsApp: las que tienen copia en el servidor o aún se pueden bajar de Meta
        // (sus medios caducan a los 30 días).
        $wa = DB::table('messages')->where('ticket_id', $w->ticket_id)->where('funcion', 'soporte')
            ->whereNotNull('media_url')->where('media_mime', 'like', 'image/%')->orderByDesc('id')
            ->get(['id', 'direction', 'created_at', 'media_path']);
        foreach ($wa as $m) {
            $copia = $m->media_path && Storage::disk('local')->exists($m->media_path);
            if (! $copia && strtotime((string) $m->created_at) < now()->subDays(self::META_DAYS)->getTimestamp()) continue;
            $out[] = ['src' => 'm:' . $m->id, 'name' => 'Foto de WhatsApp', 'at' => (string) $m->created_at, 'from' => $m->direction === 'out' ? 'agente' : 'cliente'];
        }

        usort($out, fn ($x, $y) => strcmp($y['at'], $x['at']));
        return $out;
    }

    /**
     * Bytes y tipo de una imagen del ticket de la garantía, o null si no es de ese ticket,
     * no es una imagen o ya no está. [binario, mime, nombre].
     */
    public static function ticketImage(Warranty $w, string $src): ?array
    {
        if (! $w->ticket_id || ! preg_match('/^([am]):(\d+)$/', $src, $mm)) return null;
        $id = (int) $mm[2];

        if ($mm[1] === 'a') {
            $a = DB::table('attachments')->where('id', $id)->where('ticket_id', $w->ticket_id)->first(['id', 'name', 'path']);
            $mime = $a ? AttachmentService::mimeDe((string) $a->path) : null;
            if (! $a || ! isset(self::EXT[$mime]) || ! Storage::disk('local')->exists($a->path)) return null;
            return [Storage::disk('local')->get($a->path), $mime, $a->name];
        }

        $m = DB::table('messages')->where('id', $id)->where('ticket_id', $w->ticket_id)->where('funcion', 'soporte')
            ->first(['media_url', 'media_path', 'media_mime']);
        if (! $m || ! $m->media_url) return null;
        $disco = Storage::disk('local');
        if ($m->media_path && $disco->exists($m->media_path)) {
            $bin = $disco->get($m->media_path);
        } else {
            try { $bin = app(WhatsAppService::class)->paraFuncion('soporte')->mediaBinary((string) $m->media_url)['binary'] ?? null; }
            catch (\Throwable $e) { Log::warning('Garantías: no se pudo bajar la foto de WhatsApp', ['message' => $id, 'error' => $e->getMessage()]); $bin = null; }
        }
        $mime = $bin ? self::mimeReal($bin) : null;
        return $mime ? [$bin, $mime, 'Foto de WhatsApp'] : null;
    }

    /** Guarda una subida como foto del dispositivo (sustituye la de ese tipo). Error legible o null. */
    public static function upload(WarrantyItem $item, string $type, UploadedFile $file, int $userId): ?string
    {
        if (! $file->isValid()) return 'La foto no ha llegado bien';
        if ($file->getSize() > self::MAX_BYTES) return 'La foto supera los 15 MB';
        $bin = (string) file_get_contents($file->getRealPath());
        if (! self::mimeReal($bin)) return 'Solo fotos JPG, PNG, WEBP o GIF (las HEIC del iPhone, mejor en JPG)';
        self::guardar($item, $type, $bin, $file->getClientOriginalName(), null, $userId);
        return null;
    }

    /** Ruta absoluta y tipo de una foto guardada, o null. */
    public static function file(int $photoId): ?array
    {
        $p = DB::table('warranty_item_photos')->where('id', $photoId)->first(['path']);
        if (! $p || ! Storage::disk('local')->exists($p->path)) return null;
        return [Storage::disk('local')->path($p->path), AttachmentService::mimeDe($p->path)];
    }

    public static function fromTicket(Warranty $w, WarrantyItem $item, string $type, string $src, int $userId): ?string
    {
        $img = self::ticketImage($w, $src);
        if (! $img) return 'Esa imagen no es del ticket de esta garantía o ya no está';
        [$bin, , $name] = $img;
        if (strlen($bin) > self::MAX_BYTES) return 'La foto supera los 15 MB';
        self::guardar($item, $type, $bin, $name, $src, $userId);
        return null;
    }

    public static function delete(int $photoId): ?object
    {
        $p = DB::table('warranty_item_photos')->where('id', $photoId)->first();
        if (! $p) return null;
        DB::table('warranty_item_photos')->where('id', $p->id)->delete();
        Storage::disk('local')->delete($p->path);
        return $p;
    }

    /** Al borrar un dispositivo (la fila se va en cascada) hay que llevarse sus ficheros. */
    public static function purgeFiles(array $itemIds): void
    {
        $paths = DB::table('warranty_item_photos')->whereIn('warranty_item_id', $itemIds)->pluck('path')->all();
        if ($paths) Storage::disk('local')->delete($paths);
    }

    /** Tipo de imagen por su contenido (no por la extensión ni por lo que diga el navegador). */
    public static function mimeReal(string $bin): ?string
    {
        $info = @getimagesizefromstring($bin);
        $mime = $info['mime'] ?? null;
        return isset(self::EXT[$mime]) ? $mime : null;
    }

    private static function guardar(WarrantyItem $item, string $type, string $bin, ?string $name, ?string $source, int $userId): void
    {
        $mime = self::mimeReal($bin);
        $path = 'warranty_photos/' . date('Y/m') . '/' . Str::uuid() . '.' . self::EXT[$mime];
        Storage::disk('local')->put($path, $bin);

        $antes = DB::table('warranty_item_photos')->where('warranty_item_id', $item->id)->where('type', $type)->first();
        if ($antes) self::delete($antes->id);

        DB::table('warranty_item_photos')->insert([
            'warranty_item_id' => $item->id,
            'type'             => $type,
            'path'             => $path,
            'source'           => $source,
            'name'             => mb_substr($name ?: 'foto', 0, 190),
            'uploaded_by'      => $userId,
            'created_at'       => now(),
        ]);
    }
}
