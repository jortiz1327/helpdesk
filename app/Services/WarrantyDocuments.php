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

    /*
     * Grupos de dispositivos revisados, para el correo y para Finanzas (Configuración):
     *  devueltas   — se devuelven las mismas (garantía o pilas): reacondicionadas
     *  sustituidas — en garantía, pero se cambian por nuevas (cuentan stock)
     *  danadas     — dañadas por el cliente (paga el cliente)
     *  cliente     — paga el cliente por otra cosa (fuera de plazo…)
     * En Finanzas, dañadas y cliente van en el mismo presupuesto, y las pilas cambiadas
     * (pilas o «se le cambiaron las pilas») tienen el suyo.
     */
    public const BLOCK_GROUPS = [
        'devueltas'   => 'Reacondicionadas (se devuelven las mismas)',
        'sustituidas' => 'Sustituidas en garantía',
        'danadas'     => 'Dañadas por el cliente',
        'cliente'     => 'Fuera de garantía (paga el cliente)',
    ];

    public const FINANCE_GROUPS = [
        'devueltas'   => 'Se devuelven las mismas',
        'pilas'       => 'Pilas cambiadas',
        'sustituidas' => 'Sustituidas en garantía',
        'cliente'     => 'Paga el cliente',
    ];

    public const BODY_DEFAULT = "Hola {cliente}:\n\nEn relación con los dispositivos de la garantía {codigo} ({proyecto}) remitidos para su evaluación técnica, te informamos del resultado del análisis realizado por nuestro equipo:\n\n{detalle}\n\nTe adjuntamos el informe técnico con el detalle de cada dispositivo{documentos}. Una vez efectuado el pago, envíanos el justificante para preparar el envío: quedará pendiente hasta recibirlo.\n\nQuedamos a tu disposición para cualquier aclaración.\n\nUn saludo,\n{agente}";

    public const BLOCK_DEFAULTS = [
        'devueltas' => ['active' => true, 'text' => "• Reacondicionadas ({n}): únicamente presentaban incidencias de sincronización. Se han sustituido las baterías para asegurar su correcto funcionamiento y que las pruebas se hicieran correctamente. Figuran en el presupuesto con coste cero: no se factura la etiqueta, solo la sustitución de las baterías. Permanecerán en nuestras oficinas hasta recibir el justificante de pago."],
        'sustituidas' => ['active' => true, 'text' => "• Sustituidas en garantía ({n}): presentan un defecto de fabricación cubierto por la garantía y se cambian por unidades nuevas sin coste."],
        'danadas' => ['active' => true, 'text' => "• Con daños físicos ({n}): {motivos}. No se consideran defectos de fabricación y, por tanto, no están cubiertas por la garantía.\n\nDe acuerdo con nuestro protocolo, quedan excluidos de revisión los dispositivos con golpes, caídas o impactos en la carcasa o la pantalla, entrada de líquidos, humedad o corrosión, exposición a calor extremo, sobretensiones o daños por fuerza mayor. Estos daños, incluso si se producen al cambiar las pilas o al retirar el dispositivo del expositor, comprometen su funcionamiento interno e impiden una revisión fiable."],
        'cliente' => ['active' => true, 'text' => "• Fuera de garantía ({n}): la cobertura es de {plazo_hardware} desde la instalación, por lo que únicamente sería posible su reposición por unidades nuevas.\n\nSi no aceptas la reposición de las unidades fuera de garantía, indícanoslo respondiendo a este correo: enviaremos solo las cubiertas o reacondicionadas, una vez recibido el pago. Si más adelante pidieras el envío de las excluidas, el transporte correría de tu cuenta."],
    ];

    public const FINANCE_DEFAULTS = [
        'header'      => "{cliente} - {codigo} - Garantía material\n{enlace}",
        'replaced'    => ['defectuosa', 'danada', 'desechada'],
        'battery_ref' => 'AM-BZ{numero}',
        'groups'      => [
            'devueltas'   => ['active' => true, 'stock' => 'no cuenta stock', 'cost' => 'a coste 0', 'note' => 'etiquetas enviadas por el cliente que están en buen estado'],
            'pilas'       => ['active' => true, 'stock' => 'cuenta stock', 'cost' => 'a coste cliente', 'note' => ''],
            'sustituidas' => ['active' => true, 'stock' => 'cuenta stock', 'cost' => 'a coste 0', 'note' => 'fallo de fábrica, sustitución en garantía'],
            'cliente'     => ['active' => true, 'stock' => 'cuenta stock', 'cost' => 'a coste cliente', 'note' => 'dispositivos dañados por el cliente o fuera de garantía'],
        ],
    ];

    /** Un ajuste con sus valores de serie debajo (lo que falte sale de serie). */
    public static function ajuste(string $key, array $defaults): array
    {
        $v = WarrantyDocument::setting($key);
        foreach ($defaults as $k => $d) {
            if (is_array($d) && ! array_is_list($d)) {
                $v[$k] = array_replace_recursive($d, is_array($v[$k] ?? null) ? $v[$k] : []);
            }
        }
        return array_replace($defaults, $v);
    }

    /** En qué grupo del correo va un dispositivo revisado (null: sin revisar). */
    public static function grupoDe($i, array $sustituidas): ?string
    {
        if ($i->resolution === 'pendiente' || ! $i->coverage) return null;
        if ($i->coverage === 'cliente') return $i->resolution === 'danada' ? 'danadas' : 'cliente';
        return in_array($i->resolution, $sustituidas, true) ? 'sustituidas' : 'devueltas';
    }

    /** Texto del correo de resolución con sus variables ya puestas. */
    public static function resolutionEmail(Warranty $w, string $agente): array
    {
        $tpl = WarrantyDocument::setting('resolution_email');
        $items = WarrantyItem::where('warranty_id', $w->id)->orderBy('id')->get(['code', 'resolution', 'coverage', 'broken_cases']);
        $resumen = ['Dispositivos revisados: ' . $items->where('resolution', '!=', 'pendiente')->count() . ' de ' . $items->count()];
        foreach ($items->countBy('resolution')->sortDesc() as $k => $n) {
            if ($k !== 'pendiente') $resumen[] = '· ' . (WarrantyItem::RESOLUTIONS[$k]['label'] ?? $k) . ": {$n}";
        }
        $cov = collect(WarrantyItem::COVERAGES)->map(fn ($l, $k) => ($n = $items->where('coverage', $k)->count()) ? "{$l}: {$n}" : null)->filter()->implode(' · ');
        if ($cov) $resumen[] = $cov;

        $t = WarrantyTerms::forWarranty($w);
        $plazos = ['{plazo_hardware}' => WarrantyTerms::months($t['hardware_months']), '{plazo_pilas}' => WarrantyTerms::months($t['battery_months']),
            '{ciclos}' => number_format($t['battery_cycles'], 0, ',', '.')];

        // El detalle: un bloque por grupo con dispositivos, en el orden de Configuración.
        $sustituidas = self::ajuste('finance', self::FINANCE_DEFAULTS)['replaced'];
        $grupos = $items->groupBy(fn ($i) => self::grupoDe($i, $sustituidas) ?? '');
        $bloques = [];
        foreach (self::ajuste('resolution_blocks', self::BLOCK_DEFAULTS) as $g => $b) {
            $suyos = $grupos->get($g);
            if (! $suyos || empty($b['active']) || trim((string) ($b['text'] ?? '')) === '') continue;
            $motivos = $suyos->flatMap(fn ($i) => $i->broken_cases ?? [])->unique()->map(fn ($k) => WarrantyItem::BROKEN_CASES[$k] ?? $k)->values();
            $bloques[] = strtr(trim($b['text']), $plazos + ['{n}' => $suyos->count(), '{codigos}' => $suyos->pluck('code')->implode(', '),
                '{motivos}' => mb_strtolower($motivos->implode(', ')) ?: 'daños visibles']);
        }

        $vars = $plazos + [
            '{codigo}' => $w->code, '{cliente}' => $w->client_name ?: 'cliente', '{proyecto}' => $w->project_name ?: $w->client_name ?: '',
            '{resumen}' => implode("\n", $resumen), '{detalle}' => $bloques ? implode("\n\n", $bloques) : implode("\n", $resumen), '{documentos}' => '',
            '{agente}' => $agente, '{fecha}' => now()->format('d/m/Y'),
        ];
        return ['subject' => strtr((string) ($tpl['subject'] ?? ''), $vars), 'body' => strtr((string) ($tpl['body'] ?? ''), $vars)];
    }

    /**
     * Aviso a Finanzas de los presupuestos que hay que crear, como lo piden los
     * procedimientos: cabecera (cliente, garantía y enlace al proyecto del portal) y un
     * PPTO por grupo con las unidades de cada modelo, si cuenta stock y a qué coste.
     */
    public static function financeText(Warranty $w): string
    {
        $cfg = self::ajuste('finance', self::FINANCE_DEFAULTS);
        $items = WarrantyItem::where('warranty_id', $w->id)->orderBy('id')->get();
        $modelos = DB::table('device_models')->whereIn('id', $items->pluck('device_model_id')->filter()->unique())->get(['id', 'battery_type', 'battery_quantity'])->keyBy('id');

        $grupos = ['devueltas' => [], 'pilas' => [], 'sustituidas' => [], 'cliente' => []];
        $sumar = function (string $g, string $nombre, int $n) use (&$grupos) { $grupos[$g][$nombre] = ($grupos[$g][$nombre] ?? 0) + $n; };
        foreach ($items as $i) {
            $g = self::grupoDe($i, $cfg['replaced']);
            if (! $g) continue;
            $sumar($g === 'danadas' ? 'cliente' : $g, ($i->kind === 'ap' ? 'antena|antenas' : 'etiqueta|etiquetas') . '|' . ($i->aeme_ref ?: $i->server_model ?: 'sin modelo'), 1);
            if ($i->coverage === 'pilas' || $i->battery_changed) {
                $m = $modelos->get($i->device_model_id);
                $tipo = strtoupper(trim((string) ($m->battery_type ?? '')));
                $ref = $tipo === '' ? '(tipo sin indicar)' : strtr($cfg['battery_ref'] ?: '{tipo}', ['{numero}' => preg_replace('/\D/', '', $tipo), '{tipo}' => $tipo]);
                $sumar('pilas', "pila|pilas|{$ref}", max(1, (int) ($m->battery_quantity ?? 1)));
            }
        }

        $cabecera = strtr((string) $cfg['header'], [
            '{cliente}' => $w->project_name ?: $w->client_name ?: 'Cliente', '{empresa}' => $w->client_name ?: '', '{codigo}' => self::codigoCorto($w->code),
            '{enlace}' => self::enlacePortal($w), '{fecha}' => now()->format('d/m/Y'),
        ]);
        $partes = [trim(preg_replace('/^\h*\R/m', '', $cabecera . "\n"))];   // sin las líneas que quedan vacías
        $n = 0;
        foreach ($grupos as $g => $lineas) {
            $c = $cfg['groups'][$g] ?? [];
            if (! $lineas || empty($c['active'])) continue;
            ksort($lineas, SORT_NATURAL);
            $txt = 'PPTO ' . (++$n) . (trim((string) ($c['stock'] ?? '')) !== '' ? ' (' . trim($c['stock']) . ')' : '') . ':';
            foreach ($lineas as $k => $cant) {
                [$uno, $varios, $ref] = explode('|', $k, 3);
                $txt .= "\n{$cant} " . ($cant === 1 ? $uno : $varios) . " {$ref}" . (trim((string) ($c['cost'] ?? '')) !== '' ? ' ' . trim($c['cost']) : '');
            }
            if (trim((string) ($c['note'] ?? '')) !== '') $txt .= "\n(" . trim($c['note']) . ')';
            $partes[] = $txt;
        }
        if ($n === 0) $partes[] = 'No hay dispositivos revisados todavía.';
        return implode("\n\n", array_filter($partes, fn ($p) => $p !== ''));
    }

    /** G-2026-0032 → G-26-0032, como se nombran en el portal. */
    public static function codigoCorto(string $code): string
    {
        return preg_replace('/^G-\d{2}(\d{2})-(\d+)$/', 'G-$1-$2', $code);
    }

    /** El proyecto de esta garantía en el portal (por su código), o vacío. */
    private static function enlacePortal(Warranty $w): string
    {
        if (! $w->portal_client_id || ! preg_match('/^G-\d{2}(\d{2})-(\d+)$/', $w->code, $m)) return '';
        foreach (PortalDocCheck::overview((int) $w->portal_client_id)['projects'] ?? [] as $p) {
            if ($p['code'] && preg_match('/^G-(\d{2})-(\d+)$/', $p['code'], $x) && $x[1] === $m[1] && (int) $x[2] === (int) $m[2]) return $p['url'];
        }
        return '';
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
