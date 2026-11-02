<?php

namespace App\Services;

use App\Models\Warranty;
use App\Models\WarrantyDocument;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Validador previo a crear un presupuesto o albarán en el portal AEME. Solo lee:
 * comprueba lo que el portal pide al darlo de alta (cliente, proyecto del cliente,
 * líneas con descripción, unidad y cantidad, tipo de IVA del catálogo) y devuelve
 * cómo quedaría allí. La creación en el portal todavía no está activada.
 *
 * En el portal un presupuesto es estimates + lineitems (lineitemresource_type
 * 'estimate') + tax a nivel de documento; un albarán es deliveries + lineitems, sin
 * precios ni impuestos en el PDF. Lo que cubre la garantía va con precio 0.
 */
class PortalDocCheck
{
    public const TIPOS = ['presupuesto' => 'Presupuesto', 'albaran' => 'Albarán de entrega'];

    public static function check(WarrantyDocument $d): array
    {
        $checks = [];
        $add = function (string $tone, string $text) use (&$checks) { $checks[] = ['tone' => $tone, 'text' => $text]; };
        $w = Warranty::find($d->warranty_id);
        $lines = WarrantyDocuments::lines($d);
        $cobra = $d->charges();

        if (! isset(self::TIPOS[$d->kind]) || $d->source !== 'generado') {
            $add('bad', 'Solo se pasan al portal los presupuestos y albaranes generados aquí.');
            return self::fin($checks, null);
        }
        if (in_array($d->status, ['anulado', 'rechazado'], true)) {
            $add('bad', 'El documento está ' . mb_strtolower(WarrantyDocument::STATUSES[$d->status]) . '.');
        }
        if (! AemePortal::configured()) {
            $add('bad', 'No hay conexión con el portal configurada.');
            return self::fin($checks, null);
        }

        try {
            $db = DB::connection('portal_aeme');

            // Cliente y proyecto: el portal exige un cliente existente; el proyecto es opcional.
            $cliente = $w?->portal_client_id ? $db->table('clients')->where('client_id', $w->portal_client_id)->first(['client_id', 'client_company_name']) : null;
            if (! $w?->portal_client_id) {
                $add('bad', 'La garantía no tiene enlazado el cliente del portal: edítala y elige el cliente.');
            } elseif (! $cliente) {
                $add('bad', "El cliente enlazado (#{$w->portal_client_id}) ya no existe en el portal.");
            } else {
                $add('ok', 'Cliente del portal: ' . trim($cliente->client_company_name) . '.');
            }
            $proyecto = $w?->portal_project_id ? $db->table('projects')->where('project_id', $w->portal_project_id)->first(['project_id', 'project_title', 'project_clientid']) : null;
            if (! $w?->portal_project_id) {
                $add('warn', 'Sin proyecto del portal: se creará sin proyecto.');
            } elseif (! $proyecto) {
                $add('bad', "El proyecto enlazado (#{$w->portal_project_id}) ya no existe en el portal.");
            } elseif ($cliente && (int) $proyecto->project_clientid !== (int) $cliente->client_id) {
                $add('bad', 'El proyecto «' . trim($proyecto->project_title) . '» es de otro cliente del portal.');
            } else {
                $add('ok', 'Proyecto: ' . trim($proyecto->project_title) . '.');
            }

            // Líneas: el portal descarta las que no tienen descripción o unidad.
            if (! $lines) {
                $add('bad', 'El documento no tiene líneas.');
            }
            $refs = array_values(array_unique(array_filter(array_map(fn ($l) => strtoupper(trim((string) $l['ref'])), $lines))));
            $items = $refs ? $db->table('items')->whereIn('item_ref', $refs)->get(['item_ref', 'item_name', 'item_rate', 'item_unit'])
                ->keyBy(fn ($i) => strtoupper(trim($i->item_ref))) : collect();
            $tarifa = $refs && $cliente ? $db->table('rates')->where('rate_clientid', $cliente->client_id)->whereIn('rate_ref', $refs)->get(['rate_ref', 'rate_rate'])
                ->keyBy(fn ($r) => strtoupper(trim($r->rate_ref))) : collect();

            $filas = [];
            $sinRef = $noEsta = $sinDesc = $sinCant = $distinto = [];
            foreach ($lines as $i => $l) {
                $n = ($t = trim((string) $l['description'])) !== '' ? '«' . mb_strimwidth($t, 0, 40, '…') . '»' : 'la ' . ($i + 1) . 'ª';
                $ref = strtoupper(trim((string) $l['ref']));
                $it = $ref !== '' ? $items->get($ref) : null;
                if ($ref === '') {
                    $sinRef[] = $n;
                } elseif (! $it) {
                    $noEsta[] = $ref;
                }
                if (trim((string) $l['description']) === '') {
                    $sinDesc[] = $n;
                }
                if ((float) $l['qty'] <= 0) {
                    $sinCant[] = $n;
                }
                $precio = $cobra ? (float) $l['price'] : 0.0;
                $ref_portal = $tarifa->get($ref)?->rate_rate ?? $it?->item_rate;
                if ($cobra && $ref_portal !== null && abs((float) $ref_portal - $precio) >= 0.005) {
                    $distinto[] = $ref . ' (' . self::eur($precio) . ' aquí, ' . self::eur((float) $ref_portal) . ($tarifa->has($ref) ? ' en su tarifa' : ' en el catálogo') . ')';
                }
                $filas[] = [
                    'ref' => $ref ?: null, 'name' => $it ? trim((string) $it->item_name) : null, 'description' => (string) $l['description'],
                    'qty' => (float) $l['qty'], 'unit' => $it && trim((string) $it->item_unit) !== '' ? trim($it->item_unit) : 'Unidad',
                    'rate' => $precio, 'total' => round($precio * (float) $l['qty'], 2), 'found' => (bool) $it,
                ];
            }
            if ($sinDesc) {
                $add('bad', 'Hay ' . count($sinDesc) . (count($sinDesc) === 1 ? ' línea' : ' líneas') . ' sin descripción (' . implode(', ', $sinDesc) . '): el portal las descarta.');
            }
            if ($sinCant) {
                $add('bad', 'Sin cantidad: ' . implode(', ', $sinCant) . '.');
            }
            if ($noEsta) {
                $add('warn', 'Referencias que no están en el catálogo del portal: ' . implode(', ', $noEsta) . '. Irán como línea libre, con unidad «Unidad».');
            }
            if ($sinRef) {
                $add('warn', 'Sin referencia del portal: ' . implode(', ', $sinRef) . '. ' . (count($sinRef) === 1 ? 'Irá' : 'Irán') . ' como línea libre, con unidad «Unidad».');
            }
            if ($distinto) {
                $add('warn', 'Precio distinto al del portal: ' . implode('; ', $distinto) . '.');
            }
            if ($lines && ! $sinDesc && ! $sinCant && ! $noEsta && ! $sinRef) {
                $add('ok', count($lines) . ' ' . (count($lines) === 1 ? 'línea' : 'líneas') . ' con producto del portal.');
            }
            if (! $cobra) {
                $add('ok', 'Lo cubre la garantía: las líneas van a 0 €.');
            }

            // IVA: solo el presupuesto lleva impuesto, elegido del catálogo del portal.
            $impuesto = null;
            if ($d->kind === 'presupuesto') {
                $impuesto = $db->table('taxrates')->where('taxrate_value', round((float) $d->vat, 2))->first(['taxrate_id', 'taxrate_name', 'taxrate_value']);
                $impuesto ? $add('ok', 'Impuesto: ' . $impuesto->taxrate_name . '.') : $add('bad', 'El IVA del ' . rtrim(rtrim(number_format((float) $d->vat, 2, ',', ''), '0'), ',') . ' % no está en los tipos del portal.');
            }
        } catch (Throwable $e) {
            Log::warning('Portal AEME: no se pudo validar el documento ' . $d->id . ': ' . $e->getMessage());
            $add('bad', 'El portal no responde. Prueba de nuevo en un rato.');
            return self::fin($checks, null);
        }

        $base = round(array_sum(array_column($filas, 'total')), 2);
        $iva = $impuesto ? round($base * (float) $impuesto->taxrate_value / 100, 2) : 0.0;
        return self::fin($checks, [
            'type' => self::TIPOS[$d->kind], 'client' => $cliente ? trim($cliente->client_company_name) : null,
            'project' => $proyecto ? trim($proyecto->project_title) : null, 'date' => now()->toDateString(),
            'prefix' => $d->kind === 'presupuesto' ? 'PPTO-' . now()->format('y') : 'ALB-' . now()->format('y'),
            'prices' => $d->kind === 'presupuesto', 'lines' => $filas,
            'tax' => $impuesto?->taxrate_name, 'base' => $base, 'tax_amount' => $iva, 'total' => round($base + $iva, 2),
            'terms' => $d->note,
        ]);
    }

    private static function fin(array $checks, ?array $preview): array
    {
        return ['ready' => ! collect($checks)->contains('tone', 'bad'), 'checks' => $checks, 'preview' => $preview];
    }

    private static function eur(float $n): string
    {
        return number_format($n, 2, ',', '.') . ' €';
    }
}
