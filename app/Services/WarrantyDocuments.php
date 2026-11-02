<?php

namespace App\Services;

use App\Models\Warranty;
use App\Models\WarrantyDocument;
use App\Models\WarrantyItem;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Lo que la garantía genera por su cuenta: el informe de resolución (solo con todo
 * revisado; se guarda tal cual quedó) y el correo de resolución. Presupuestos, pedidos
 * y albaranes viven en el portal AEME (PortalDocCheck).
 */
class WarrantyDocuments
{
    /** Cuántos dispositivos revisados hay de cada «quién paga» y cuántos quedan. */
    public static function summary(Warranty $w): array
    {
        $items = WarrantyItem::where('warranty_id', $w->id)->get(['id', 'resolution', 'coverage']);
        $por = [];
        foreach (array_keys(WarrantyItem::COVERAGES) as $c) {
            $por[$c] = ['items' => $items->where('coverage', $c)->where('resolution', '!=', 'pendiente')->count()];
        }
        return [
            'total'      => $items->count(),
            'pending'    => $items->where('resolution', 'pendiente')->count(),
            'coverage'   => $por,
        ];
    }

    /** El PDF guardado del informe: [contenido, mime, nombre]. */
    public static function file(WarrantyDocument $doc): ?array
    {
        if (! $doc->path || ! Storage::disk('local')->exists($doc->path)) return null;
        return [Storage::disk('local')->get($doc->path), 'application/pdf', $doc->file_name ?: basename($doc->path)];
    }

    /**
     * Informe de resolución: solo con todos los dispositivos revisados. Se guarda el PDF
     * tal cual (uno por garantía: generarlo otra vez lo sustituye y conserva el número).
     */
    public static function report(Warranty $w, bool $photos, int $userId): WarrantyDocument|string
    {
        $items = WarrantyItem::where('warranty_id', $w->id)->orderBy('id')->get();
        if ($items->isEmpty()) return 'La garantía no tiene dispositivos';
        if ($n = $items->where('resolution', 'pendiente')->count()) return ($n === 1 ? 'Queda 1 dispositivo' : "Quedan {$n} dispositivos") . ' sin revisar';

        $previo = WarrantyDocument::where('warranty_id', $w->id)->where('kind', 'informe')->where('source', 'generado')->latest('id')->first();
        $numero = $previo->number ?? WarrantyDocument::nextNumber('informe');
        $fotos = $photos ? self::fotosInforme($items) : [];

        $html = view('warranty-report-pdf', [
            'w' => $w, 'items' => $items, 'number' => $numero, 'photos' => $fotos,
            'company' => WarrantyDocument::setting('company'),
            'res' => WarrantyItem::RESOLUTIONS, 'cov' => WarrantyItem::COVERAGES, 'broken' => WarrantyItem::BROKEN_CASES,
            'zk' => \App\Models\ZkongBatch::RESULTS,
            'porRes' => $items->countBy('resolution')->all(), 'porCov' => $items->countBy(fn ($i) => $i->coverage ?? '')->all(),
        ])->render();

        $path = 'warranty_docs/' . date('Y/m') . '/' . Str::uuid() . '.pdf';
        $bin = self::pdf($html);
        Storage::disk('local')->put($path, $bin);
        if ($previo?->path) Storage::disk('local')->delete($previo->path);

        $datos = ['path' => $path, 'file_name' => "Informe-{$w->code}.pdf", 'size' => strlen($bin), 'created_by' => $userId,
            'note' => $photos ? 'Con fotos' : null];
        $doc = $previo ? tap($previo)->update($datos + ['updated_at' => now()])
            : WarrantyDocument::create($datos + ['warranty_id' => $w->id, 'kind' => 'informe', 'source' => 'generado', 'number' => $numero]);
        self::evento($w->id, $userId, ($previo ? 'Informe de resolución actualizado' : 'Informe de resolución generado') . " ({$numero})");
        return $doc;
    }

    /** Las fotos de cada dispositivo, reducidas para que el PDF no pese: [item_id => [[tipo, dataURI]]]. */
    private static function fotosInforme(Collection $items): array
    {
        $out = [];
        $filas = DB::table('warranty_item_photos')->whereIn('warranty_item_id', $items->pluck('id'))->orderBy('id')->get(['warranty_item_id', 'type', 'path']);
        foreach ($filas as $p) {
            if (! Storage::disk('local')->exists($p->path)) continue;
            $uri = self::miniatura(Storage::disk('local')->get($p->path));
            if ($uri) $out[$p->warranty_item_id][] = [$p->type, $uri];
        }
        return $out;
    }

    private static function miniatura(string $bin, int $lado = 520): ?string
    {
        $mime = WarrantyPhotos::mimeReal($bin);
        if (! $mime) return null;
        if (! function_exists('imagecreatefromstring') || ! ($img = @imagecreatefromstring($bin))) {
            return $mime === 'image/webp' ? null : "data:{$mime};base64," . base64_encode($bin);
        }
        [$an, $al] = [imagesx($img), imagesy($img)];
        $f = min(1, $lado / max($an, $al));
        $peq = imagescale($img, max(1, (int) ($an * $f)), max(1, (int) ($al * $f)));
        ob_start();
        imagejpeg($peq ?: $img, null, 78);
        $jpg = ob_get_clean();
        return 'data:image/jpeg;base64,' . base64_encode($jpg);
    }

    /** Texto del correo de resolución con sus variables ya puestas. */
    public static function resolutionEmail(Warranty $w, string $agente): array
    {
        $tpl = WarrantyDocument::setting('resolution_email');
        $items = WarrantyItem::where('warranty_id', $w->id)->get(['resolution', 'coverage']);
        $resumen = ['Dispositivos revisados: ' . $items->where('resolution', '!=', 'pendiente')->count() . ' de ' . $items->count()];
        foreach ($items->countBy('resolution')->sortDesc() as $k => $n) {
            if ($k !== 'pendiente') $resumen[] = '· ' . (WarrantyItem::RESOLUTIONS[$k]['label'] ?? $k) . ": {$n}";
        }
        $cov = collect(WarrantyItem::COVERAGES)->map(fn ($l, $k) => ($n = $items->where('coverage', $k)->count()) ? "{$l}: {$n}" : null)->filter()->implode(' · ');
        if ($cov) $resumen[] = $cov;

        $vars = [
            '{codigo}' => $w->code, '{cliente}' => $w->client_name ?: 'cliente', '{proyecto}' => $w->project_name ?: $w->client_name ?: '',
            '{resumen}' => implode("\n", $resumen), '{documentos}' => '',
            '{agente}' => $agente, '{fecha}' => now()->format('d/m/Y'),
        ];
        return ['subject' => strtr((string) ($tpl['subject'] ?? ''), $vars), 'body' => strtr((string) ($tpl['body'] ?? ''), $vars)];
    }

    public static function purgeFiles(int $warrantyId): void
    {
        $paths = WarrantyDocument::where('warranty_id', $warrantyId)->whereNotNull('path')->pluck('path')->all();
        if ($paths) Storage::disk('local')->delete($paths);
    }

    public static function evento(int $warrantyId, ?int $userId, string $texto): void
    {
        DB::table('warranty_events')->insert(['warranty_id' => $warrantyId, 'user_id' => $userId, 'field' => 'documents',
            'from_value' => null, 'to_value' => mb_substr($texto, 0, 500), 'created_at' => now()]);
        Warranty::whereKey($warrantyId)->update(['last_activity_at' => now()]);
    }

    private static function pdf(string $html): string
    {
        $dompdf = new \Dompdf\Dompdf(['isRemoteEnabled' => false, 'defaultFont' => 'DejaVu Sans']);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper('A4');
        $dompdf->render();
        return $dompdf->output();
    }
}
