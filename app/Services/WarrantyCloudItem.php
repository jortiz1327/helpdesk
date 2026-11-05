<?php

namespace App\Services;

use App\Models\Warranty;
use App\Models\ZkongServer;
use App\Services\Zkong\ZkongClient;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Cada garantía es también un PRODUCTO en el cloud de ZKONG (plantilla «default / almacen»
 * de la tienda del almacén): así, al vincularle una etiqueta, la etiqueta enseña la
 * pegatina del paquete. Se manda igual que en dialprix (item/batchImportItem) siempre que
 * la garantía o sus dispositivos cambian; crearla de más no hace daño.
 *
 * Campos (los de la plantilla):
 *   barCode       código de la garantía          itemTitle   cliente (o proyecto)
 *   shortTitle    empresa (si hay proyecto)
 *   custFeature1  enviado por el cliente el      custFeature2  recibido en almacén
 *   custFeature3  revisado el                    custFeature4  pte. creación presupuesto
 *   custFeature5  resolución enviada el
 *   custFeature10…29  productos: «ref_aeme - Cant. N» (20 huecos)
 *
 * Solo sale si está configurado (services.warranty_cloud: servidor y tienda). Sin eso
 * (tests, local) no se llama a nada.
 */
class WarrantyCloudItem
{
    public const ATTR_CATEGORY = 'default';
    public const ATTR_NAME = 'almacen';
    public const PRIMER_PRODUCTO = 10;
    public const HUECOS = 20;

    /** Garantías pendientes de mandar en esta petición (una vez cada una). */
    private static array $pendientes = [];

    public static function enabled(): bool
    {
        return (bool) config('services.warranty_cloud.store_id') && (bool) config('services.warranty_cloud.server');
    }

    /** Lo llaman los modelos al cambiar algo: sale después de responder, una vez por garantía. */
    public static function queue(?int $warrantyId): void
    {
        if (! $warrantyId || ! self::enabled() || isset(self::$pendientes[$warrantyId])) return;
        self::$pendientes[$warrantyId] = true;
        dispatch(function () use ($warrantyId) {
            unset(self::$pendientes[$warrantyId]);
            self::send($warrantyId);
        })->afterResponse();
    }

    /** Manda (crea o actualiza) el producto de la garantía. true si ZKONG lo aceptó. */
    public static function send(int $warrantyId): bool
    {
        try {
            $w = Warranty::find($warrantyId);
            $s = self::server();
            if (! $w || ! $s) return false;

            $c = new ZkongClient($s);
            if (! $s->merchant_id) {
                $c->login();   // el comercio y la agencia salen del login
                $s->refresh();
            }
            $json = $c->post('/item/batchImportItem', [
                'agencyId'   => $s->agency_id,
                'merchantId' => $s->merchant_id,
                'storeId'    => (string) config('services.warranty_cloud.store_id'),
                'unitName'   => 0,
                'itemList'   => [self::item($w)],
            ]);
            // Como en dialprix: «data» con algo dentro son los productos que no entraron.
            if (! empty($json['data'])) {
                Log::warning("Garantía {$w->code}: el cloud no aceptó el producto", ['data' => $json['data']]);
                return false;
            }
            return true;
        } catch (Throwable $e) {
            Log::warning("Garantía {$warrantyId}: no se pudo mandar el producto al cloud: " . $e->getMessage());
            return false;
        }
    }

    /** El producto tal como va al cloud. */
    public static function item(Warranty $w): array
    {
        $d = WarrantyDocuments::stickerData($w);
        $item = [
            'attrCategory' => self::ATTR_CATEGORY,
            'attrName'     => self::ATTR_NAME,
            'barCode'      => (string) $w->code,
            'productCode'  => (string) $w->code,
            'itemTitle'    => (string) ($d['client'] ?: '—'),
            'shortTitle'   => (string) $d['company'],
            // El envío se avisa desde el ticket (a veces antes de que exista la garantía).
            'custFeature1' => self::fecha(DB::table('warranty_shipments')->where(fn ($q) => $q->where('warranty_id', $w->id)
                ->when($w->ticket_id, fn ($q) => $q->orWhere('ticket_id', $w->ticket_id)))->max('shipped_on')),
            'custFeature2' => self::fecha($w->received_at),
            'custFeature3' => self::fecha(DB::table('warranty_items')->where('warranty_id', $w->id)->max('reviewed_at')),
            'custFeature4' => self::fecha(self::entradaEn($w, 'pte_presupuesto')),
            'custFeature5' => self::fecha(self::entradaEn($w, 'enviada')),
        ];

        // Productos: uno por hueco; si hay más de 20, el último dice cuántos faltan.
        $prods = array_map(fn ($p) => "{$p['name']} - Cant. {$p['qty']}", $d['products']);
        if (count($prods) > self::HUECOS) {
            $resto = array_slice($prods, self::HUECOS - 1);
            $prods = array_slice($prods, 0, self::HUECOS - 1);
            $prods[] = '+' . count($resto) . ' más';
        }
        for ($i = 0; $i < self::HUECOS; $i++) {
            $item['custFeature' . (self::PRIMER_PRODUCTO + $i)] = $prods[$i] ?? '';
        }
        return $item;
    }

    private static function server(): ?ZkongServer
    {
        $n = (string) config('services.warranty_cloud.server');
        return ZkongServer::where('active', true)->where(fn ($q) => $q->where('name', $n)->orWhere('id', ctype_digit($n) ? (int) $n : 0))->first();
    }

    /**
     * La primera vez que la garantía pasó a ese estado, según el historial (guarda el nombre
     * del estado). Si no está ahí pero es el estado actual, cuándo cambió a él.
     */
    private static function entradaEn(Warranty $w, string $estado): ?string
    {
        $nombre = Warranty::statuses()[$estado] ?? $estado;
        $primera = DB::table('warranty_events')->where('warranty_id', $w->id)->where('field', 'status')
            ->whereIn('to_value', array_unique([$nombre, $estado]))->min('created_at');
        if ($primera) return (string) $primera;
        return (string) $w->status === $estado && $w->status_changed_at ? (string) $w->status_changed_at : null;
    }

    private static function fecha($v): string
    {
        if (! $v) return '';
        try {
            return \Carbon\Carbon::parse($v)->format('d/m/Y');
        } catch (Throwable) {
            return '';
        }
    }
}
