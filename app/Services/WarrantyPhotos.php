<?php

namespace App\Services;

use App\Models\User;
use App\Models\Warranty;
use App\Models\WarrantyItem;
use App\Support\TicketVisibility;
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
 * Las imágenes del cliente se identifican con «a:ID» (adjunto) o «m:ID» (mensaje de
 * WhatsApp con imagen) y solo se aceptan si son de un ticket de clientTickets(): el de la
 * garantía y los demás del mismo contacto que el usuario ve en el Helpdesk.
 */
class WarrantyPhotos
{
    public const MAX_BYTES = 15 * 1024 * 1024;
    public const MAX_PIXELS = 40_000_000;   // más que eso agota la memoria al montar el PDF
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

    /**
     * Tickets de los que esta garantía puede enseñar imágenes a este usuario: el suyo y los
     * demás del mismo contacto (los 30 últimos), siempre que los vea en el Helpdesk.
     * [ticket_id => código]; vacío si no ve el ticket de la garantía.
     */
    public static function clientTickets(Warranty $w, ?User $u): array
    {
        if (! $w->ticketVisibleTo($u)) return [];
        $t = DB::table('tickets')->where('id', $w->ticket_id)->first(['code', 'contact_id']);
        if (! $t) return [];
        $otros = $t->contact_id
            ? TicketVisibility::scope(DB::table('tickets as t')->where('t.contact_id', $t->contact_id), $u)
                ->orderByDesc('t.id')->limit(30)->pluck('t.code', 't.id')->all()
            : [];
        return [$w->ticket_id => $t->code] + $otros;
    }

    /**
     * Imágenes de esos tickets, la más reciente primero: {src, name, at, from (cliente |
     * agente), channel (correo | whatsapp | adjunto), ticket_id, ticket}.
     */
    public static function ticketImages(array $tickets): array
    {
        if (! $tickets) return [];
        $out = [];

        $adjuntos = DB::table('attachments as a')->leftJoin('messages as m', 'm.id', '=', 'a.message_id')
            ->whereIn('a.ticket_id', array_keys($tickets))->orderByDesc('a.id')
            ->get(['a.id', 'a.ticket_id', 'a.name', 'a.path', 'a.size', 'a.inline', 'a.created_at', 'm.direction', 'm.channel']);
        foreach ($adjuntos as $a) {
            $mime = AttachmentService::mimeDe((string) $a->path);
            if (! isset(self::EXT[$mime]) || ($a->inline && $a->size < self::LOGO_BYTES)) continue;
            $out[] = ['src' => 'a:' . $a->id, 'name' => $a->name, 'at' => (string) $a->created_at,
                'from' => $a->direction === 'out' ? 'agente' : 'cliente',
                'channel' => $a->channel === 'email' ? 'correo' : ($a->channel === 'whatsapp' ? 'whatsapp' : 'adjunto'),
                'ticket_id' => (int) $a->ticket_id, 'ticket' => $tickets[$a->ticket_id]];
        }

        // De WhatsApp: las que tienen copia en el servidor (wa:media las guarda 6 meses) o
        // aún se pueden bajar de Meta (sus medios caducan a los 30 días).
        $wa = DB::table('messages')->whereIn('ticket_id', array_keys($tickets))->where('funcion', 'soporte')
            ->whereNotNull('media_url')->where('media_mime', 'like', 'image/%')->orderByDesc('id')
            ->get(['id', 'ticket_id', 'direction', 'created_at', 'media_path']);
        foreach ($wa as $m) {
            $copia = $m->media_path && Storage::disk('local')->exists($m->media_path);
            if (! $copia && strtotime((string) $m->created_at) < now()->subDays(self::META_DAYS)->getTimestamp()) continue;
            $out[] = ['src' => 'm:' . $m->id, 'name' => 'Foto de WhatsApp', 'at' => (string) $m->created_at,
                'from' => $m->direction === 'out' ? 'agente' : 'cliente', 'channel' => 'whatsapp',
                'ticket_id' => (int) $m->ticket_id, 'ticket' => $tickets[$m->ticket_id]];
        }

        usort($out, fn ($x, $y) => strcmp($y['at'], $x['at']));
        return $out;
    }

    /**
     * Bytes y tipo de una imagen de uno de esos tickets, o null si no es de ellos, no es
     * una imagen o ya no está. [binario, mime, nombre].
     */
    public static function ticketImage(array $tickets, string $src): ?array
    {
        if (! $tickets || ! preg_match('/^([am]):(\d+)$/', $src, $mm)) return null;
        $id = (int) $mm[2];

        if ($mm[1] === 'a') {
            $a = DB::table('attachments')->where('id', $id)->whereIn('ticket_id', array_keys($tickets))->first(['id', 'name', 'path']);
            $mime = $a ? AttachmentService::mimeDe((string) $a->path) : null;
            if (! $a || ! isset(self::EXT[$mime]) || ! Storage::disk('local')->exists($a->path)) return null;
            return [Storage::disk('local')->get($a->path), $mime, $a->name];
        }

        $m = DB::table('messages')->where('id', $id)->whereIn('ticket_id', array_keys($tickets))->where('funcion', 'soporte')
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
        if ($error = self::problema($bin)) return $error;
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

    public static function fromTicket(array $tickets, WarrantyItem $item, string $type, string $src, int $userId): ?string
    {
        $img = self::ticketImage($tickets, $src);
        if (! $img) return 'Esa imagen no es de los tickets de este cliente o ya no está';
        [$bin, , $name] = $img;
        if (strlen($bin) > self::MAX_BYTES) return 'La foto supera los 15 MB';
        if ($error = self::problema($bin)) return $error;
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

    /** Por qué no se puede guardar esta imagen (tipo o tamaño en píxeles), o null si vale. */
    private static function problema(string $bin): ?string
    {
        $info = @getimagesizefromstring($bin);
        if (! isset(self::EXT[$info['mime'] ?? null])) return 'Solo fotos JPG, PNG, WEBP o GIF (las HEIC del iPhone, mejor en JPG)';
        if ($info[0] * $info[1] > self::MAX_PIXELS) return 'La foto es demasiado grande (más de 40 megapíxeles): redúcela antes de subirla';
        return null;
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
