<?php

namespace App\Http\Controllers;

use App\Services\WhatsAppService;
use Illuminate\Http\Request;

/** Portado de api/media.php — proxy que descarga y sirve un medio de Meta. Requiere token (?token=). */
class MediaController extends Controller
{
    public function handle(Request $request, WhatsAppService $wa)
    {
        $mediaId = preg_replace('/[^0-9]/', '', (string) $request->query('id'));
        if (!$mediaId) return response('id requerido', 400);

        // Solo medios de un mensaje NUESTRO y que el usuario pueda ver: los de soporte si ve
        // el ticket, los de Campañas si tiene Campañas. Antes servía cualquier id de Meta.
        $msg = \Illuminate\Support\Facades\DB::table('messages')->where('media_url', $mediaId)
            ->orderByDesc('id')->first(['funcion', 'ticket_id', 'media_path', 'media_mime']);
        $me = $request->user();
        $puede = $msg && ($msg->funcion === 'soporte'
            ? ($me->can('helpdesk.access') && (!$msg->ticket_id || \App\Support\TicketVisibility::puedeVer($me, (int) $msg->ticket_id)))
            : $me->can('campaigns.access'));
        if (!$puede) return response('medio no encontrado', 404);

        // En línea solo lo que el navegador muestra sin riesgo; el resto, como descarga.
        $enLinea = fn (string $tipo) => preg_match('#^(image/(jpeg|png|gif|webp)|audio/|video/)#', strtolower(trim(explode(';', $tipo)[0]))) === 1;

        // Copia en el servidor (WhatsApp de soporte, 6 meses): sin ir a Meta.
        $disco = \Illuminate\Support\Facades\Storage::disk('local');
        if ($msg->media_path && $disco->exists($msg->media_path)) {
            $tipo = (string) ($msg->media_mime ?: ($disco->mimeType($msg->media_path) ?: 'application/octet-stream'));
            return response()->file($disco->path($msg->media_path), [
                'Content-Type'        => $tipo,
                'Content-Disposition' => $enLinea($tipo) ? 'inline' : 'attachment',
                'Cache-Control'       => 'private, max-age=86400',
            ]);
        }

        // Cada número baja sus archivos con SU token: los del WhatsApp de soporte (otra
        // app de Meta) no se pueden descargar con el de Campañas.
        if ($msg->funcion === 'soporte') $wa = $wa->paraFuncion('soporte');

        $m = $wa->mediaBinary($mediaId);
        if (!$m) return response('medio no encontrado', 404);

        return response($m['binary'], 200, [
            'Content-Type'        => $m['type'],
            'Content-Disposition' => $enLinea((string) $m['type']) ? 'inline' : 'attachment',
            'Cache-Control'       => 'private, max-age=86400',
        ]);
    }
}
