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
                ? $this->incrustarImagenes((string) $m->body, $withImages, (int) $t->id)
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
     *
     * SEGURIDAD: el `src` lo pudo escribir un remitente externo. Solo se incrusta si la
     * ruta lleva NUESTRA firma válida y, en los adjuntos, si son DE ESTE ticket y son
     * imágenes de verdad (nunca SVG: dompdf lo interpretaría). Si no, la imagen se quita.
     */
    public function incrustarImagenes(string $html, bool $withImages, ?int $ticketId = null): string
    {
        if (!$withImages) return preg_replace('#<img[^>]*>#i', '', $html) ?? $html;

        $raster = ['image/jpeg', 'image/jpg', 'image/pjpeg', 'image/png', 'image/gif', 'image/webp', 'image/bmp'];
        $incrustar = fn ($m, $row) => '<img' . $m[1] . 'src="data:' . $row->mime . ';base64,'
            . base64_encode(Storage::disk('local')->get($row->path)) . '"' . $m[3] . '>';

        // Editor: /api/inline/{id} (tabla inline_uploads)
        $html = preg_replace_callback('#<img([^>]*?)src="([^"]*)"([^>]*)>#i', function ($m) use ($incrustar, $raster, $ticketId) {
            if (($id = HtmlSanitizer::rutaFirmada($m[2], 'inline')) !== null) {
                $row = DB::table('inline_uploads')->find($id);
                if (!$row || !in_array(strtolower((string) $row->mime), $raster, true) || !Storage::disk('local')->exists($row->path)) return '';
                return $incrustar($m, $row);
            }
            // Correo (cid): /api/attachment_inline/{id} (tabla attachments), solo de ESTE ticket
            if (($id = HtmlSanitizer::rutaFirmada($m[2], 'attachment_inline')) !== null) {
                $row = DB::table('attachments')->find($id);
                if (!$row || ($ticketId && (int) $row->ticket_id !== $ticketId)) return '';
                if (!in_array(strtolower((string) $row->mime), $raster, true) || !Storage::disk('local')->exists($row->path)) return '';
                return $incrustar($m, $row);
            }
            return $m[0];
        }, $html) ?? $html;

        // Cualquier <img> que NO haya quedado incrustada (src no es data:) se elimina.
        return preg_replace('#<img(?![^>]*src="data:)[^>]*>#i', '', $html) ?? $html;
    }
}
