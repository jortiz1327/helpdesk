<?php

namespace App\Http\Controllers;

use App\Models\Warranty;
use App\Models\WarrantyDocument;
use App\Models\WarrantyItem;
use App\Services\WarrantyDocuments;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * warranty_docs.php — documentos de una garantía: presupuestos y albaranes (generados
 * por quién paga o subidos), informe de resolución y correo de resolución.
 */
class WarrantyDocumentsController extends Controller
{
    public function handle(Request $request)
    {
        return match ($request->query('action', 'list')) {
            'get'        => $this->get($request),
            'generate'   => $this->generate($request),
            'save'       => $this->save($request),
            'status'     => $this->status($request),
            'to_albaran' => $this->toAlbaran($request),
            'upload'     => $this->upload($request),
            'delete'     => $this->delete($request),
            'download'   => $this->download($request),
            'report'     => $this->report($request),
            'email'      => $this->email($request),
            default      => $this->list($request),
        };
    }

    private function list(Request $request)
    {
        $w = Warranty::findOrFail((int) $request->query('id'));
        $docs = WarrantyDocument::query()->leftJoin('users as u', 'u.id', '=', 'warranty_documents.created_by')
            ->leftJoin('warranty_documents as o', 'o.id', '=', 'warranty_documents.from_document_id')
            ->where('warranty_documents.warranty_id', $w->id)->orderByDesc('warranty_documents.id')
            ->get(['warranty_documents.*', 'u.name as created_by_name', 'o.number as from_number']);
        $n = DB::table('warranty_document_lines')->whereIn('warranty_document_id', $docs->pluck('id'))
            ->selectRaw('warranty_document_id, count(*) as n')->groupBy('warranty_document_id')->pluck('n', 'warranty_document_id');

        return response()->json(['ok' => true, 'documents' => $docs->map(fn ($d) => $this->fila($d) + ['lines_count' => (int) ($n[$d->id] ?? 0)]),
            'summary' => WarrantyDocuments::summary($w),
            // Conceptos sueltos de la tarifa, para añadirlos a un documento con un clic.
            'services' => DB::table('warranty_prices')->where('kind', 'service')->orderBy('label')->get(['ref', 'label', 'price'])
                ->map(fn ($s) => ['ref' => $s->ref, 'label' => $s->label ?: $s->ref, 'price' => (float) $s->price])]);
    }

    private function fila(WarrantyDocument $d): array
    {
        return [
            'id' => $d->id, 'kind' => $d->kind, 'coverage' => $d->coverage, 'source' => $d->source, 'number' => $d->number,
            'status' => $d->status, 'vat' => $d->vat, 'total' => $d->total, 'note' => $d->note,
            'file_name' => $d->file_name, 'size' => $d->size, 'from_number' => $d->from_number ?? null,
            'created_by_name' => $d->created_by_name ?? null,
            'created_at' => $d->created_at?->toDateTimeString(), 'updated_at' => $d->updated_at?->toDateTimeString(),
        ];
    }

    private function doc(Request $request): WarrantyDocument
    {
        return WarrantyDocument::findOrFail((int) $request->input('id'));
    }

    private function get(Request $request)
    {
        $d = $this->doc($request);
        $lines = WarrantyDocuments::lines($d);
        return response()->json(['ok' => true, 'document' => $this->fila($d) + ['lines' => $lines, 'totals' => WarrantyDocuments::totals($d, $lines)]]);
    }

    private function generate(Request $request)
    {
        $w = Warranty::findOrFail((int) $request->input('id'));
        $kind = (string) $request->input('kind');
        if (! in_array($kind, WarrantyDocument::GENERATED, true)) {
            return response()->json(['ok' => false, 'error' => 'Tipo de documento no válido'], 400);
        }
        $cov = array_values(array_intersect(array_keys(WarrantyItem::COVERAGES), (array) $request->input('coverages', [])));
        if (! $cov) {
            return response()->json(['ok' => false, 'error' => 'Elige al menos uno: garantía, cliente o pilas'], 400);
        }
        $docs = WarrantyDocuments::generate($w, $kind, $cov, $request->user()->id);
        if (! $docs) {
            return response()->json(['ok' => false, 'error' => 'No hay dispositivos revisados de ese tipo'], 400);
        }
        return response()->json(['ok' => true, 'ids' => array_map(fn ($d) => $d->id, $docs)]);
    }

    // Generado: líneas, IVA y nota. Subido: número, quién paga, estado, importe y nota.
    private function save(Request $request)
    {
        $d = $this->doc($request);
        if ($d->kind === 'informe') {
            return response()->json(['ok' => false, 'error' => 'El informe no se edita: se genera de nuevo'], 400);
        }
        if ($d->source === 'subido') {
            $kind = isset(WarrantyDocument::KINDS[$k = (string) $request->input('kind')]) && $k !== 'informe' ? $k : $d->kind;
            $d->fill(['kind' => $kind] + WarrantyDocuments::datosSubido($kind, $request->all()))->save();
        } else {
            $estados = WarrantyDocument::KINDS[$d->kind]['statuses'];
            $d->fill([
                'vat'    => round(max(0, min(100, (float) $request->input('vat', $d->vat))), 2),
                'note'   => ($t = trim((string) $request->input('note', ''))) !== '' ? mb_substr($t, 0, 5000) : null,
                'status' => in_array($request->input('status'), $estados, true) ? $request->input('status') : $d->status,
            ])->save();
            WarrantyDocuments::saveLines($d, (array) $request->input('lines', []));
        }
        WarrantyDocuments::evento($d->warranty_id, $request->user()->id, WarrantyDocuments::nombre($d) . ' modificado');
        return $this->get($request);
    }

    private function status(Request $request)
    {
        $d = $this->doc($request);
        $s = (string) $request->input('status');
        if (! in_array($s, WarrantyDocument::KINDS[$d->kind]['statuses'], true)) {
            return response()->json(['ok' => false, 'error' => 'Estado no válido para este documento'], 400);
        }
        if ($s !== $d->status) {
            $antes = WarrantyDocument::STATUSES[$d->status] ?? '—';
            $d->forceFill(['status' => $s])->save();
            WarrantyDocuments::evento($d->warranty_id, $request->user()->id, WarrantyDocuments::nombre($d) . ": {$antes} → " . WarrantyDocument::STATUSES[$s]);
        }
        return response()->json(['ok' => true]);
    }

    private function toAlbaran(Request $request)
    {
        $d = $this->doc($request);
        if ($d->kind !== 'presupuesto' || $d->source !== 'generado') {
            return response()->json(['ok' => false, 'error' => 'Solo se hace albarán de un presupuesto generado aquí'], 400);
        }
        if (in_array($d->status, ['rechazado', 'anulado'], true)) {
            return response()->json(['ok' => false, 'error' => 'Ese presupuesto está ' . mb_strtolower(WarrantyDocument::STATUSES[$d->status])], 400);
        }
        $a = WarrantyDocuments::toAlbaran($d, $request->user()->id);
        return response()->json(['ok' => true, 'id' => $a->id, 'number' => $a->number]);
    }

    private function upload(Request $request)
    {
        $w = Warranty::findOrFail((int) $request->input('id'));
        $file = $request->file('file');
        $r = $file ? WarrantyDocuments::upload($w, $file, $request->all(), $request->user()->id) : 'Falta el fichero';
        return is_string($r)
            ? response()->json(['ok' => false, 'error' => $r], 400)
            : response()->json(['ok' => true, 'id' => $r->id]);
    }

    private function delete(Request $request)
    {
        $d = $this->doc($request);
        if ($d->source === 'generado' && in_array($d->kind, WarrantyDocument::GENERATED, true) && ! in_array($d->status, ['borrador', 'anulado'], true)) {
            return response()->json(['ok' => false, 'error' => 'Solo se borran los borradores y los anulados: anúlalo primero'], 400);
        }
        WarrantyDocuments::delete($d, $request->user()->id);
        return response()->json(['ok' => true]);
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
        $contacto = $w->ticket_id ? DB::table('tickets as t')->leftJoin('contacts as c', 'c.id', '=', 't.contact_id')
            ->where('t.id', $w->ticket_id)->first(['c.email as contact_email']) : null;
        return response()->json(['ok' => true, 'to' => $contacto->contact_email ?? null]
            + WarrantyDocuments::resolutionEmail($w, $request->user()->name));
    }
}
