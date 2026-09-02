<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Genera el PDF del hilo de un ticket (dompdf + plantilla `ticket-pdf`). Compartido por
 * el PDF del AGENTE (TicketsController) y el del CLIENTE (PortalController). El de cliente
 * fuerza `anon=true` (los agentes salen como «Soporte») y no incluye notas internas.
 */
class TicketPdfService
{
    /**
     * @param object   $t        fila del ticket (code, subject, contact_*, status, priority, category_name, agent_name, created_at, resolved_at)
     * @param iterable $messages filas con id, direction, body, is_html, is_internal_note, created_at, author_name
     */
    public function generar($t, $messages, bool $withImages, bool $anon): string
    {
        $bodies = [];
        foreach ($messages as $m) {
            $bodies[$m->id] = (int) $m->is_html === 1
                ? $this->incrustarImagenes((string) $m->body, $withImages)
                : nl2br(e((string) $m->body));
        }

        $html = view('ticket-pdf', [
            't' => $t, 'messages' => $messages, 'bodies' => $bodies, 'anon' => $anon,
            'statuses' => TicketService::STATUSES, 'priorities' => TicketService::priorities(),
        ])->render();

        $dompdf = new \Dompdf\Dompdf(['isRemoteEnabled' => false, 'defaultFont' => 'DejaVu Sans']);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper('A4');
        $dompdf->render();

        return $dompdf->output();
    }

    /**
     * Incrusta como data: URI las imágenes EN LÍNEA (editor `/api/inline/N` de
     * `inline_uploads` y `cid:` del correo `/api/attachment_inline/N` de `attachments`), o
     * las quita si no se piden. dompdf no descarga nada (isRemoteEnabled=false), así que al
     * final se elimina cualquier <img> que NO haya quedado como data: (evita imágenes rotas).
     */
    public function incrustarImagenes(string $html, bool $withImages): string
    {
        if (!$withImages) return preg_replace('#<img[^>]*>#i', '', $html) ?? $html;

        // Editor: /api/inline/{id} (tabla inline_uploads)
        $html = preg_replace_callback('#<img([^>]*?)src="[^"]*?/api/inline/(\d+)\?[^"]*"([^>]*)>#i', function ($m) {
            $row = DB::table('inline_uploads')->find((int) $m[2]);
            if (!$row || !Storage::disk('local')->exists($row->path)) return '';
            return '<img' . $m[1] . 'src="data:' . $row->mime . ';base64,' . base64_encode(Storage::disk('local')->get($row->path)) . '"' . $m[3] . '>';
        }, $html) ?? $html;

        // Correo (cid): /api/attachment_inline/{id} (tabla attachments)
        $html = preg_replace_callback('#<img([^>]*?)src="[^"]*?/api/attachment_inline/(\d+)\?[^"]*"([^>]*)>#i', function ($m) {
            $row = DB::table('attachments')->find((int) $m[2]);
            if (!$row || !$row->mime || !Storage::disk('local')->exists($row->path)) return '';
            return '<img' . $m[1] . 'src="data:' . $row->mime . ';base64,' . base64_encode(Storage::disk('local')->get($row->path)) . '"' . $m[3] . '>';
        }, $html) ?? $html;

        // Cualquier <img> que NO haya quedado incrustada (src no es data:) se elimina.
        return preg_replace('#<img(?![^>]*src="data:)[^>]*>#i', '', $html) ?? $html;
    }
}
