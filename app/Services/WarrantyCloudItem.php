<?php

namespace App\Services;

use App\Models\Warranty;
use App\Models\ZkongServer;
use App\Services\Zkong\ZkongClient;
use App\Services\Zkong\ZkongException;
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
 *   custFeature10…29  productos: «ref_aeme - N» (20 huecos)
 *
 * Entra con la CUENTA DE PRODUCTOS del servidor (Servidores ZKONG), no con la de
 * administrador. Solo sale si está configurado (services.warranty_cloud: servidor y tienda). Sin eso
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
        return self::server() !== null;
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

            // Con la cuenta de productos del servidor (la de administrador no puede crearlos).
            $c = new ZkongClient($s, true);
            if (! $s->product_merchant_id) {
                $c->login();   // el comercio y la agencia salen del login
                $s->refresh();
            }
            $json = $c->post('/item/batchImportItem', [
                'agencyId'   => $s->product_agency_id,
                'merchantId' => $s->product_merchant_id,
                'storeId'    => self::store(),
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

    /** Si se pueden emparejar etiquetas de almacén (servidor con cuenta de productos y tienda). */
    public static function canPair(): bool
    {
        return self::enabled() && self::server() !== null;
    }

    /**
     * Empareja una etiqueta de la tienda del almacén con el producto de la garantía (como
     * dialprix: bind/batchBind, el Cloud elige la antena). Antes se manda el producto, por
     * si aún no existe. Si la garantía tenía otra etiqueta, se desempareja; si la etiqueta
     * estaba con otra garantía, esa se queda sin ella. Devuelve el código emparejado.
     * Lanza ZkongException con el mensaje del Cloud si no se puede.
     */
    public static function pair(Warranty $w, string $eslCode): string
    {
        $esl = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $eslCode));
        if (! preg_match('/^[A-Z0-9]{9}$/', $esl)) throw new ZkongException('El código de una etiqueta tiene 9 letras o números.');
        $s = self::server();
        if (! self::enabled() || ! $s) throw new ZkongException('La etiqueta de almacén no está configurada (servidor con cuenta de productos y tienda).');
        if (! self::send($w->id)) throw new ZkongException('El Cloud no ha aceptado el producto de la garantía.');

        $c = new ZkongClient($s, true);
        $antes = (string) $w->cloud_label;
        if ($antes !== '' && $antes !== $esl) {
            try {
                self::unbind($c, $antes, (string) $w->code);
            } catch (Throwable $e) {
                Log::warning("Garantía {$w->code}: no se pudo desemparejar {$antes}: " . $e->getMessage());
            }
        }
        $c->post('/bind/batchBind', [
            'storeId' => self::store(),
            // batchBind quiere «itemBarcode» con c minúscula (con C responde «param invalid»).
            'tagItemBinds' => [['eslBarcode' => $esl, 'itemBarcode' => (string) $w->code]],
        ]);

        // Una etiqueta solo enseña una garantía: la que la tuviera se queda sin ella.
        foreach (Warranty::where('cloud_label', $esl)->where('id', '!=', $w->id)->get(['id', 'code']) as $otra) {
            Warranty::whereKey($otra->id)->update(['cloud_label' => null, 'cloud_label_at' => null]);
            self::evento($otra->id, $esl, null, "Etiqueta de almacén {$esl} pasada a {$w->code}");
        }
        // Sin pasar por el modelo: no es un cambio del producto (no hay que volver a mandarlo).
        Warranty::whereKey($w->id)->update(['cloud_label' => $esl, 'cloud_label_at' => now(), 'last_activity_at' => now()]);
        self::evento($w->id, $antes ?: null, $esl, $antes && $antes !== $esl ? "Etiqueta de almacén cambiada de {$antes} a {$esl}" : "Etiqueta de almacén {$esl} emparejada");
        return $esl;
    }

    /** Quita la etiqueta de almacén de la garantía (la desempareja en el Cloud). */
    public static function unpair(Warranty $w): void
    {
        $esl = (string) $w->cloud_label;
        if ($esl === '') return;
        $s = self::server();
        if (! self::enabled() || ! $s) throw new ZkongException('La etiqueta de almacén no está configurada (servidor con cuenta de productos y tienda).');
        self::unbind(new ZkongClient($s, true), $esl, (string) $w->code);
        Warranty::whereKey($w->id)->update(['cloud_label' => null, 'cloud_label_at' => null, 'last_activity_at' => now()]);
        self::evento($w->id, $esl, null, "Etiqueta de almacén {$esl} quitada");
    }

    /**
     * La etiqueta de almacén de siempre (la última usada) para ofrecerla en otra garantía:
     * ['code', 'warranty_id', 'warranty_code'] si la enseña otra garantía ahora mismo
     * (al emparejarla aquí se le quita a aquella), o solo 'code' si quedó libre. Null si
     * nunca se ha usado ninguna.
     */
    public static function usual(Warranty $w): ?array
    {
        $ahora = Warranty::whereNotNull('cloud_label')->where('cloud_label', '!=', '')
            ->orderByDesc('cloud_label_at')->orderByDesc('id')->first(['id', 'code', 'cloud_label']);
        if ($ahora) {
            return $ahora->id === $w->id ? null
                : ['code' => $ahora->cloud_label, 'warranty_id' => $ahora->id, 'warranty_code' => $ahora->code];
        }
        // Ninguna la enseña ahora: la última que se quitó o se cambió.
        $libre = DB::table('warranty_events')->where('field', 'cloud_label')->whereNotNull('from_value')->where('from_value', '!=', '')
            ->orderByDesc('id')->value('from_value');
        return $libre ? ['code' => $libre, 'warranty_id' => null, 'warranty_code' => null] : null;
    }

    private static function unbind(ZkongClient $c, string $esl, string $code): void
    {
        $c->post('/bind/batchUnbind', [
            'storeId' => self::store(),
            // Al revés que batchBind: aquí es «itemBarCode» con C (como dialprix).
            'tagItemBinds' => [['eslBarcode' => $esl, 'itemBarCode' => $code]],
        ]);
    }

    private static function store(): string
    {
        return (string) (self::server()?->product_store_id ?: config('services.warranty_cloud.store_id'));
    }

    private static function evento(int $id, ?string $de, ?string $a, string $texto): void
    {
        DB::table('warranty_events')->insert(['warranty_id' => $id, 'user_id' => auth()->id(), 'field' => 'cloud_label',
            'from_value' => $de, 'to_value' => $texto, 'created_at' => now()]);
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
        // «Ref - cantidad», como en la plantilla: con «Cant.» no cabe en el hueco (90 px a 10).
        $prods = array_map(fn ($p) => "{$p['name']} - {$p['qty']}", $d['products']);
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

    /**
     * El servidor de services.warranty_cloud.server, activo, con cuenta de productos y con
     * tienda (la suya de Servidores ZKONG o, si no, services.warranty_cloud.store_id).
     */
    private static function server(): ?ZkongServer
    {
        $n = (string) config('services.warranty_cloud.server');
        $s = $n === '' ? null : ZkongServer::where('active', true)->whereNotNull('product_username')
            ->where(fn ($q) => $q->where('name', $n)->orWhere('id', ctype_digit($n) ? (int) $n : 0))->first();
        return $s && ($s->product_store_id ?: config('services.warranty_cloud.store_id')) ? $s : null;
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
