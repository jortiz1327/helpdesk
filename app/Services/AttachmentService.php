<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/** Guarda y sirve los adjuntos de los tickets. */
class AttachmentService
{
    public const MAX_BYTES = 10 * 1024 * 1024;   // 10 MB por fichero
    public const MAX_FILES = 10;

    /**
     * Extensiones PERMITIDAS (lista blanca). Un adjunto de soporte es una captura,
     * un PDF o un log: nunca un ejecutable ni un script. Filtrar por lista negra
     * («todo menos .exe») siempre se queda corto.
     */
    public const ALLOWED_EXT = [
        'jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'heic',
        'pdf', 'txt', 'log', 'csv', 'doc', 'docx', 'xls', 'xlsx', 'odt', 'ods',
        'zip', 'eml', 'msg',
    ];

    /**
     * Guarda los ficheros de una petición y los enlaza al ticket/mensaje.
     * Devuelve [guardados, errores[]].
     */
    public function store(array $files, int $ticketId, ?int $messageId, ?int $userId): array
    {
        $saved = [];
        $errors = [];

        foreach (array_slice($files, 0, self::MAX_FILES) as $file) {
            if (!$file instanceof UploadedFile || !$file->isValid()) {
                $errors[] = 'Archivo no válido';
                continue;
            }

            $name = $file->getClientOriginalName();
            $ext  = strtolower($file->getClientOriginalExtension());

            // OJO con las llaves: PHP admite bytes altos en los nombres de variable,
            // así que "«$name»" se interpretaría como la variable $name» (con la comilla
            // pegada) y reventaría. Con {$name} queda claro dónde acaba.
            if (!in_array($ext, self::ALLOWED_EXT, true)) {
                $errors[] = "«{$name}»: tipo de archivo no permitido";
                continue;
            }
            if ($file->getSize() > self::MAX_BYTES) {
                $errors[] = "«{$name}»: supera los 10 MB";
                continue;
            }

            // Nombre en disco aleatorio: el nombre original NO se usa como ruta
            // (evita colisiones y travesías de directorio tipo «../../algo»).
            $path = $file->storeAs(
                'attachments/' . date('Y/m'),
                Str::uuid() . '.' . $ext,
                'local'   // fuera de public/: solo se sirve por endpoint autenticado
            );

            $saved[] = DB::table('attachments')->insertGetId([
                'ticket_id'   => $ticketId,
                'message_id'  => $messageId,
                'name'        => mb_substr($name, 0, 190),
                'path'        => $path,
                'mime'        => $file->getClientMimeType(),
                'size'        => $file->getSize(),
                'uploaded_by' => $userId,
            ]);
        }

        return [$saved, $errors];
    }

    /**
     * Guarda un adjunto a partir de BYTES en crudo (no de una subida HTTP).
     * Lo usa el canal de correo: los adjuntos IMAP no son UploadedFile.
     * Aplica la misma lista blanca y límite de tamaño. Devuelve el id o null si se descarta.
     */
    public function storeRaw(string $name, string $content, ?string $mime, int $ticketId, ?int $messageId, ?int $userId = null, ?string $contentId = null, bool $inline = false): ?int
    {
        $ext  = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        $size = strlen($content);

        if ($ext === '' || !in_array($ext, self::ALLOWED_EXT, true)) return null;
        if ($size <= 0 || $size > self::MAX_BYTES) return null;

        // Nombre en disco aleatorio (mismo criterio que store()): el nombre original
        // nunca se usa como ruta.
        $path = 'attachments/' . date('Y/m') . '/' . Str::uuid() . '.' . $ext;
        Storage::disk('local')->put($path, $content);

        return DB::table('attachments')->insertGetId([
            'ticket_id'   => $ticketId,
            'message_id'  => $messageId,
            'name'        => mb_substr($name, 0, 190),
            'path'        => $path,
            'mime'        => $mime ?: 'application/octet-stream',
            'content_id'  => $contentId ? mb_substr($contentId, 0, 255) : null,
            'inline'      => $inline,
            'size'        => $size,
            'uploaded_by' => $userId,
        ]);
    }

    /** Adjuntos de un ticket, agrupados por mensaje. */
    public function forTicket(int $ticketId): array
    {
        $rows = DB::table('attachments')->where('ticket_id', $ticketId)->orderBy('id')
            ->get(['id', 'message_id', 'name', 'mime', 'size', 'inline']);

        $byMessage = [];
        foreach ($rows as $r) {
            $r->is_image = str_starts_with((string) $r->mime, 'image/');
            $r->inline   = (bool) $r->inline;   // imagen incrustada en el cuerpo (no en la tira)
            $byMessage[$r->message_id ?? 0][] = $r;
        }
        return $byMessage;
    }

    /** Devuelve [ruta absoluta, fila] de un adjunto, o null si no existe. */
    public function find(int $id): ?array
    {
        $a = DB::table('attachments')->where('id', $id)->first();
        if (!$a || !Storage::disk('local')->exists($a->path)) return null;

        return [Storage::disk('local')->path($a->path), $a];
    }
}
