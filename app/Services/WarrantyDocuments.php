<?php

namespace App\Services;

use App\Models\Warranty;
use App\Models\WarrantyDocument;
use App\Models\WarrantyItem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Documentos de una garantía. Los presupuestos y albaranes se generan desde los
 * dispositivos revisados, uno por cada «quién paga» (garantía, cliente, pilas), con el
 * precio de la tarifa: una línea por producto (o por tipo de pila) con sus códigos.
 * Luego se editan a mano. También se suben documentos hechos fuera. El informe de
 * resolución solo se genera con todo revisado y se guarda tal cual quedó.
 */
class WarrantyDocuments
{
    public const MAX_BYTES = 20 * 1024 * 1024;
    public const UPLOAD_EXT = ['pdf', 'jpg', 'jpeg', 'png', 'webp', 'xlsx', 'xls', 'docx', 'doc', 'csv', 'txt', 'zip'];
    private const MIME = [
        'pdf' => 'application/pdf', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp',
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'xls' => 'application/vnd.ms-excel',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'doc' => 'application/msword',
        'csv' => 'text/csv', 'txt' => 'text/plain', 'zip' => 'application/zip',
    ];

    /** Tarifa: ['product' => [ref => precio], 'battery' => [...], 'service' => [...]] */
    public static function prices(): array
    {
        $out = ['product' => [], 'battery' => [], 'service' => []];
        foreach (DB::table('warranty_prices')->get(['kind', 'ref', 'price']) as $p) {
            $out[$p->kind][strtoupper($p->ref)] = (float) $p->price;
        }
        return $out;
    }

    /** Cuántos dispositivos hay de cada «quién paga» y qué falta para generar documentos. */
    public static function summary(Warranty $w): array
    {
        $items = WarrantyItem::where('warranty_id', $w->id)->get(['id', 'resolution', 'coverage', 'aeme_ref', 'device_model_id']);
        $precios = self::prices();
        $modelos = self::modelos($items);
        $por = [];
        foreach (array_keys(WarrantyItem::COVERAGES) as $c) {
            $suyos = $items->where('coverage', $c)->where('resolution', '!=', 'pendiente');
            $sinPrecio = $c === 'pilas'
                ? $suyos->filter(fn ($i) => ! isset($precios['battery'][strtoupper((string) ($modelos[$i->device_model_id]->battery_type ?? ''))]))->count()
                : $suyos->filter(fn ($i) => ! $i->aeme_ref || ! isset($precios['product'][strtoupper($i->aeme_ref)]))->count();
            $por[$c] = ['items' => $suyos->count(), 'no_price' => $c === 'garantia' ? 0 : $sinPrecio];
        }
        return [
            'total'      => $items->count(),
            'pending'    => $items->where('resolution', 'pendiente')->count(),
            'coverage'   => $por,
        ];
    }

    /**
     * Genera un presupuesto o albarán por cada «quién paga» pedido que tenga dispositivos
     * revisados. Devuelve los documentos creados.
     */
    public static function generate(Warranty $w, string $kind, array $coverages, int $userId): array
    {
        $items = WarrantyItem::where('warranty_id', $w->id)->where('resolution', '!=', 'pendiente')
            ->whereIn('coverage', $coverages)->orderBy('id')->get();
        $precios = self::prices();
        $modelos = self::modelos($items);
        $ajustes = WarrantyDocument::setting('documents');
        $creados = [];

        foreach (array_keys(WarrantyItem::COVERAGES) as $c) {
            $suyos = $items->where('coverage', $c);
            if (! in_array($c, $coverages, true) || $suyos->isEmpty()) continue;
            $creados[] = DB::transaction(function () use ($w, $kind, $c, $suyos, $precios, $modelos, $ajustes, $userId) {
                $doc = WarrantyDocument::create([
                    'warranty_id' => $w->id, 'kind' => $kind, 'coverage' => $c, 'source' => 'generado',
                    'number' => WarrantyDocument::nextNumber($kind), 'status' => 'borrador',
                    'vat' => (float) ($ajustes['vat'] ?? 21),
                    'note' => $kind === 'presupuesto' ? ($ajustes['conditions'] ?? null) : null,
                    'created_by' => $userId,
                ]);
                self::saveLines($doc, self::lineas($suyos, $c, $precios, $modelos));
                return $doc;
            });
        }
        foreach ($creados as $d) {
            self::evento($w->id, $userId, self::nombre($d) . ' creado');
        }
        return $creados;
    }

    /** Líneas de un grupo: por producto (garantía y cliente) o por tipo de pila. */
    private static function lineas(Collection $items, string $coverage, array $precios, array $modelos): array
    {
        $grupos = [];
        foreach ($items as $i) {
            if ($coverage === 'pilas') {
                $m = $modelos[$i->device_model_id] ?? null;
                $tipo = $m && $m->battery_type ? strtoupper($m->battery_type) : '';
                $g = &$grupos[$tipo];
                $g ??= ['ref' => $tipo ?: null, 'description' => $tipo ? "Pilas {$tipo}" : 'Pilas (tipo sin indicar en el catálogo)',
                    'codes' => [], 'qty' => 0, 'price' => $precios['battery'][$tipo] ?? 0];
                $g['qty'] += max(1, (int) ($m->battery_quantity ?? 1));
            } else {
                $ref = $i->aeme_ref ? strtoupper($i->aeme_ref) : '';
                $g = &$grupos[$ref];
                $g ??= ['ref' => $ref ?: null, 'description' => $i->product_name ?: ($ref ?: ($i->server_model ?: ($i->kind === 'ap' ? 'Antena sin modelo' : 'Etiqueta sin modelo'))),
                    'codes' => [], 'qty' => 0, 'price' => $precios['product'][$ref] ?? 0];
                $g['qty']++;
            }
            $g['codes'][] = $i->code;
            unset($g);
        }
        ksort($grupos, SORT_NATURAL);
        return array_values($grupos);
    }

    private static function modelos(Collection $items): array
    {
        $ids = $items->pluck('device_model_id')->filter()->unique()->all();
        return $ids ? DB::table('device_models')->whereIn('id', $ids)->get(['id', 'battery_type', 'battery_quantity'])->keyBy('id')->all() : [];
    }

    /** Sustituye las líneas y recalcula el total. */
    public static function saveLines(WarrantyDocument $doc, array $lines): void
    {
        DB::table('warranty_document_lines')->where('warranty_document_id', $doc->id)->delete();
        $filas = [];
        foreach (array_values($lines) as $n => $l) {
            $desc = trim(mb_substr((string) ($l['description'] ?? ''), 0, 300));
            if ($desc === '') continue;
            $codes = array_values(array_filter(array_map(fn ($c) => mb_substr(trim((string) $c), 0, 20), (array) ($l['codes'] ?? []))));
            $filas[] = [
                'warranty_document_id' => $doc->id, 'sort' => $n,
                'ref' => ($r = trim((string) ($l['ref'] ?? ''))) !== '' ? mb_substr($r, 0, 80) : null,
                'description' => $desc,
                'codes' => $codes ? json_encode($codes) : null,
                'qty' => round(max(0, min(999999, (float) ($l['qty'] ?? 1))), 2),
                'price' => round(max(-9999999, min(9999999, (float) ($l['price'] ?? 0))), 2),
            ];
        }
        if ($filas) DB::table('warranty_document_lines')->insert($filas);
        $t = self::totals($doc, $filas);
        $doc->forceFill(['total' => $t['total']])->save();
    }

    public static function lines(WarrantyDocument $doc): array
    {
        return DB::table('warranty_document_lines')->where('warranty_document_id', $doc->id)->orderBy('sort')->orderBy('id')->get()
            ->map(fn ($l) => ['ref' => $l->ref, 'description' => $l->description, 'codes' => $l->codes ? json_decode($l->codes, true) : [],
                'qty' => (float) $l->qty, 'price' => (float) $l->price])->all();
    }

    /** Base, IVA y total. Lo que cubre la garantía se lista a 0 €. */
    public static function totals(WarrantyDocument $doc, array $lines): array
    {
        $base = $doc->charges() ? round(array_sum(array_map(fn ($l) => (float) $l['qty'] * (float) $l['price'], $lines)), 2) : 0.0;
        $iva = round($base * (float) $doc->vat / 100, 2);
        return ['base' => $base, 'vat_amount' => $iva, 'total' => round($base + $iva, 2)];
    }

    /** Albarán a partir de un presupuesto (copia sus líneas). */
    public static function toAlbaran(WarrantyDocument $p, int $userId): WarrantyDocument
    {
        $a = DB::transaction(function () use ($p, $userId) {
            $a = WarrantyDocument::create([
                'warranty_id' => $p->warranty_id, 'kind' => 'albaran', 'coverage' => $p->coverage, 'source' => 'generado',
                'number' => WarrantyDocument::nextNumber('albaran'), 'status' => 'borrador', 'vat' => $p->vat,
                'from_document_id' => $p->id, 'created_by' => $userId,
            ]);
            self::saveLines($a, self::lines($p));
            return $a;
        });
        self::evento($p->warranty_id, $userId, self::nombre($a) . " creado desde {$p->number}");
        return $a;
    }

    /** Documento hecho fuera. Error legible o el documento. */
    public static function upload(Warranty $w, UploadedFile $file, array $d, int $userId): WarrantyDocument|string
    {
        if (! $file->isValid()) return 'El fichero no ha llegado bien';
        if ($file->getSize() > self::MAX_BYTES) return 'El fichero supera los 20 MB';
        $ext = strtolower($file->getClientOriginalExtension());
        if (! in_array($ext, self::UPLOAD_EXT, true)) return 'Solo PDF, imágenes, Excel, Word, CSV, TXT o ZIP';
        $kind = isset(WarrantyDocument::KINDS[$d['kind'] ?? '']) && ($d['kind'] ?? '') !== 'informe' ? $d['kind'] : 'otro';

        $path = 'warranty_docs/' . date('Y/m') . '/' . Str::uuid() . '.' . $ext;
        Storage::disk('local')->putFileAs(dirname($path), $file, basename($path));
        $doc = WarrantyDocument::create([
            'warranty_id' => $w->id, 'kind' => $kind, 'source' => 'subido',
            'path' => $path, 'file_name' => mb_substr($file->getClientOriginalName() ?: "documento.{$ext}", 0, 190), 'size' => $file->getSize(),
            'created_by' => $userId,
        ] + self::datosSubido($kind, $d));
        self::evento($w->id, $userId, self::nombre($doc) . ' subido');
        return $doc;
    }

    /** Lo que se escribe de un documento subido: número, quién paga, estado, importe y nota. */
    public static function datosSubido(string $kind, array $d): array
    {
        $estados = WarrantyDocument::KINDS[$kind]['statuses'];
        $total = trim(str_replace(',', '.', (string) ($d['total'] ?? '')));
        return [
            'number'   => ($n = trim((string) ($d['number'] ?? ''))) !== '' ? mb_substr($n, 0, 40) : null,
            'coverage' => isset(WarrantyItem::COVERAGES[$d['coverage'] ?? '']) ? $d['coverage'] : null,
            'status'   => in_array($d['status'] ?? '', $estados, true) ? $d['status'] : ($estados[0] ?? null),
            'total'    => is_numeric($total) ? round((float) $total, 2) : null,
            'note'     => ($t = trim((string) ($d['note'] ?? ''))) !== '' ? mb_substr($t, 0, 5000) : null,
        ];
    }

    /** Fichero de un documento: [contenido, mime, nombre]. Los generados se pintan al momento. */
    public static function file(WarrantyDocument $doc): ?array
    {
        if ($doc->path) {
            if (! Storage::disk('local')->exists($doc->path)) return null;
            $ext = strtolower(pathinfo($doc->path, PATHINFO_EXTENSION));
            return [Storage::disk('local')->get($doc->path), self::MIME[$ext] ?? 'application/octet-stream', $doc->file_name ?: basename($doc->path)];
        }
        if (! in_array($doc->kind, WarrantyDocument::GENERATED, true)) return null;
        $w = Warranty::find($doc->warranty_id);
        $lines = self::lines($doc);
        $html = view('warranty-document-pdf', [
            'doc' => $doc, 'w' => $w, 'lines' => $lines, 't' => self::totals($doc, $lines),
            'company' => WarrantyDocument::setting('company'),
            'kindLabel' => WarrantyDocument::KINDS[$doc->kind]['label'],
            'coverageLabel' => WarrantyItem::COVERAGES[$doc->coverage] ?? null,
            'validity' => (int) (WarrantyDocument::setting('documents')['validity_days'] ?? 0),
            'statusLabel' => WarrantyDocument::STATUSES[$doc->status] ?? null,
        ])->render();
        return [self::pdf($html), 'application/pdf', "{$doc->number}.pdf"];
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
        $docs = WarrantyDocument::where('warranty_id', $w->id)->whereIn('kind', WarrantyDocument::GENERATED)
            ->whereNotIn('status', ['borrador', 'anulado'])->orderBy('id')->get();

        $html = view('warranty-report-pdf', [
            'w' => $w, 'items' => $items, 'number' => $numero, 'photos' => $fotos,
            'company' => WarrantyDocument::setting('company'),
            'res' => WarrantyItem::RESOLUTIONS, 'cov' => WarrantyItem::COVERAGES, 'broken' => WarrantyItem::BROKEN_CASES,
            'zk' => \App\Models\ZkongBatch::RESULTS,
            'porRes' => $items->countBy('resolution')->all(), 'porCov' => $items->countBy(fn ($i) => $i->coverage ?? '')->all(),
            'docs' => $docs, 'kinds' => WarrantyDocument::KINDS,
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
        $docs = WarrantyDocument::where('warranty_id', $w->id)->whereIn('kind', WarrantyDocument::GENERATED)
            ->whereNotIn('status', ['borrador', 'anulado'])->orderBy('id')->get()
            ->map(fn ($d) => $d->number . ($d->coverage ? ' (' . WarrantyItem::COVERAGES[$d->coverage] . ')' : ''))->all();

        $vars = [
            '{codigo}' => $w->code, '{cliente}' => $w->client_name ?: 'cliente', '{proyecto}' => $w->project_name ?: $w->client_name ?: '',
            '{resumen}' => implode("\n", $resumen), '{documentos}' => $docs ? ' y los documentos ' . implode(', ', $docs) : '',
            '{agente}' => $agente, '{fecha}' => now()->format('d/m/Y'),
        ];
        return ['subject' => strtr((string) ($tpl['subject'] ?? ''), $vars), 'body' => strtr((string) ($tpl['body'] ?? ''), $vars)];
    }

    public static function delete(WarrantyDocument $doc, int $userId): void
    {
        if ($doc->path) Storage::disk('local')->delete($doc->path);
        $doc->delete();
        self::evento($doc->warranty_id, $userId, self::nombre($doc) . ' borrado');
    }

    public static function purgeFiles(int $warrantyId): void
    {
        $paths = WarrantyDocument::where('warranty_id', $warrantyId)->whereNotNull('path')->pluck('path')->all();
        if ($paths) Storage::disk('local')->delete($paths);
    }

    public static function nombre(WarrantyDocument $d): string
    {
        $tipo = WarrantyDocument::KINDS[$d->kind]['label'] ?? $d->kind;
        $cov = $d->coverage ? ' (' . (WarrantyItem::COVERAGES[$d->coverage] ?? $d->coverage) . ')' : '';
        return $tipo . ($d->number ? " {$d->number}" : ($d->file_name ? " «{$d->file_name}»" : '')) . $cov;
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
