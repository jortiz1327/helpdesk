<?php

namespace App\Http\Controllers;

use App\Models\Warranty;
use App\Models\WarrantyDocument;
use App\Services\PortalDocCheck;
use App\Services\WarrantyDocuments;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * warranty_docs.php — documentos de una garantía. Presupuestos, pedidos y albaranes
 * viven en el portal AEME: aquí se consultan y se preparan los presupuestos para
 * crearlos allí (crear aún no está activado). Lo propio: informe y correo de resolución.
 */
class WarrantyDocumentsController extends Controller
{
    public function handle(Request $request)
    {
        return match ($request->query('action', 'list')) {
            'portal'        => $this->portal($request),
            'portal_drafts' => $this->portalDrafts($request),
            'download'      => $this->download($request),
            'report'        => $this->report($request),
            'email'         => $this->email($request),
            default         => $this->list($request),
        };
    }

    private function list(Request $request)
    {
        $w = Warranty::findOrFail((int) $request->query('id'));
        $informe = WarrantyDocument::where('warranty_id', $w->id)->where('kind', 'informe')->latest('id')->first();
        return response()->json(['ok' => true, 'summary' => WarrantyDocuments::summary($w),
            'report' => $informe ? ['id' => $informe->id, 'number' => $informe->number, 'note' => $informe->note,
                'updated_at' => $informe->updated_at?->toDateTimeString()] : null]);
    }

    // Documentos de garantía del cliente en el portal (solo lectura).
    private function portal(Request $request)
    {
        $w = Warranty::findOrFail((int) $request->query('id'));
        if (! $w->portal_client_id) {
            return response()->json(['ok' => true, 'linked' => false, 'projects' => []]);
        }
        $r = PortalDocCheck::overview((int) $w->portal_client_id, $request->boolean('fresh'));
        return $r === null
            ? response()->json(['ok' => false, 'error' => 'El portal no responde. Prueba de nuevo en un rato.'], 502)
            : response()->json(['ok' => true, 'linked' => true] + $r);
    }

    // Presupuestos que se crearían en el portal y qué falta (solo lee; crear aún no está activado).
    private function portalDrafts(Request $request)
    {
        $w = Warranty::findOrFail((int) $request->input('id'));
        return response()->json(['ok' => true, 'enabled' => false] + PortalDocCheck::drafts($w));
    }

    private function download(Request $request)
    {
        $d = WarrantyDocument::findOrFail((int) $request->query('id'));
        $f = WarrantyDocuments::file($d);
        if (! $f) {
            return response()->json(['ok' => false, 'error' => 'El fichero ya no está'], 404);
        }
        [$bin, $mime, $name] = $f;
        $ascii = preg_replace('/[^A-Za-z0-9._-]+/', '_', $name);
        return response($bin, 200, [
            'Content-Type' => $mime,
            'Content-Disposition' => 'attachment; filename="' . $ascii . '"; filename*=UTF-8\'\'' . rawurlencode($name),
            'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'no-store',
        ]);
    }

    private function report(Request $request)
    {
        $w = Warranty::findOrFail((int) $request->input('id'));
        $r = WarrantyDocuments::report($w, $request->boolean('photos'), $request->user()->id);
        return is_string($r)
            ? response()->json(['ok' => false, 'error' => $r], 400)
            : response()->json(['ok' => true, 'id' => $r->id]);
    }

    // El correo de resolución ya rellenado, para copiarlo (no se envía desde aquí).
    private function email(Request $request)
    {
        $w = Warranty::findOrFail((int) $request->query('id'));
        // El correo del cliente sale del ticket: solo si este usuario puede verlo en el Helpdesk.
        $contacto = $w->ticketVisibleTo($request->user()) ? DB::table('tickets as t')->leftJoin('contacts as c', 'c.id', '=', 't.contact_id')
            ->where('t.id', $w->ticket_id)->first(['c.email as contact_email']) : null;
        return response()->json(['ok' => true, 'to' => $contacto->contact_email ?? null]
            + WarrantyDocuments::resolutionEmail($w, $request->user()->name));
    }
}
