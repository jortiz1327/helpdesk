<?php

namespace App\Http\Controllers;

use App\Models\WarrantyItem;
use App\Models\ZkongBatch;
use App\Services\WarrantyPermissions;
use App\Services\WarrantyPhotos;
use App\Services\ZkongCosts;
use App\Services\ZkongRmaXlsx;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * ZKONG: la bandeja (dispositivos revisados con «Enviar a ZKONG» que aún no van en
 * ninguna remesa) y las remesas (R-AAAA-NNN) con su Excel de RMA, el ZIP de fotos y la
 * respuesta de ZKONG por etiqueta. Cada paso deja línea en el historial de las
 * garantías afectadas.
 */
class ZkongRmaController extends Controller
{
    public function handle(Request $request)
    {
        if ($no = WarrantyPermissions::deny($request->user(), 'zkong', (string) $request->query('action', 'inbox'))) return $no;
        return match ($request->query('action', 'inbox')) {
            'batches' => $this->batches(),
            'batch'   => $this->batch($request),
            'create'  => $this->create($request),
            'add'     => $this->add($request),
            'remove'  => $this->remove($request),
            'send'    => $this->send($request),
            'shipping' => $this->shipping($request),
            'receive' => $this->receive($request),
            'rma'     => $this->rma($request),
            'return'  => $this->vuelta($request),
            'cancel'  => $this->cancel($request),
            'result'  => $this->result($request),
            'close'   => $this->close($request),
            'reopen'  => $this->reopen($request),
            'note'    => $this->note($request),
            'delete'  => $this->delete($request),
            'xlsx'    => $this->xlsx($request),
            'zip'     => $this->zip($request),
            default   => $this->inbox(),
        };
    }

    /** Resoluciones que pueden ir a ZKONG (no_reproduce, defectuosa). */
    private static function resZkong(): array
    {
        return array_keys(array_filter(WarrantyItem::RESOLUTIONS, fn ($r) => $r['zkong']));
    }

    /** Dispositivos con lo que hace falta para la bandeja y las remesas. */
    private function dispositivos()
    {
        return DB::table('warranty_items as i')->join('warranties as w', 'w.id', '=', 'i.warranty_id')
            ->select(['i.id', 'i.code', 'i.kind', 'i.server_model', 'i.aeme_ref', 'i.product_name', 'i.resolution',
                'i.zkong_issue', 'i.zkong_queued_at', 'i.zkong_batch_id', 'i.zkong_result', 'i.zkong_result_note',
                'i.zkong_unit_cost', 'i.zkong_cost_source', 'w.id as warranty_id', 'w.code as warranty_code', 'w.client_name', 'w.project_name']);
    }

    private function fila(object $i, array $fotos, array $costes = []): array
    {
        $tipos = array_keys(WarrantyItem::photoTypes($i->kind));
        $mias = array_values(array_filter($fotos[$i->id] ?? [], fn ($p) => in_array($p['type'], $tipos, true)));
        return [
            'cost' => $costes[$i->id]['cost'] ?? null, 'cost_source' => $costes[$i->id]['source'] ?? null,
            'id' => $i->id, 'code' => $i->code, 'kind' => $i->kind,
            'model' => $i->server_model ?: $i->aeme_ref, 'aeme_ref' => $i->aeme_ref, 'product_name' => $i->product_name,
            'resolution' => $i->resolution, 'issue' => $i->zkong_issue, 'queued_at' => $i->zkong_queued_at,
            'result' => $i->zkong_result, 'result_note' => $i->zkong_result_note,
            'photos' => $mias, 'photos_total' => count($tipos),
            'warranty_id' => $i->warranty_id, 'warranty_code' => $i->warranty_code,
            'client_name' => $i->client_name, 'project_name' => $i->project_name,
        ];
    }

    private function inbox()
    {
        $rows = $this->dispositivos()->whereIn('i.resolution', self::resZkong())->where('i.zkong_decision', 'enviar')
            ->whereNull('i.zkong_batch_id')->orderBy('i.zkong_queued_at')->orderBy('i.id')->get();
        $fotos = WarrantyPhotos::forItems($rows->pluck('id')->all());

        $grupos = [];
        foreach ($rows as $i) {
            $g = &$grupos[$i->warranty_id];
            $g ??= ['warranty_id' => $i->warranty_id, 'code' => $i->warranty_code, 'client_name' => $i->client_name,
                'project_name' => $i->project_name, 'queued_at' => $i->zkong_queued_at, 'items' => []];
            if ($i->zkong_queued_at && (! $g['queued_at'] || $i->zkong_queued_at < $g['queued_at'])) $g['queued_at'] = $i->zkong_queued_at;
            $g['items'][] = $this->fila($i, $fotos);
            unset($g);
        }

        // Las que aún esperan a que alguien decida si van a ZKONG.
        $decidir = DB::table('warranty_items as i')->join('warranties as w', 'w.id', '=', 'i.warranty_id')
            ->whereIn('i.resolution', self::resZkong())->where('i.zkong_decision', 'decidir')
            ->groupBy('w.id', 'w.code', 'w.client_name')->orderBy('w.code')
            ->get(['w.id as warranty_id', 'w.code', 'w.client_name', DB::raw('count(*) as n')]);

        $borradores = ZkongBatch::where('status', 'borrador')->orderByDesc('id')->get(['id', 'code']);

        return response()->json(['ok' => true, 'groups' => array_values($grupos), 'undecided' => $decidir, 'drafts' => $borradores]);
    }

    private function batches()
    {
        $rows = ZkongBatch::query()->leftJoin('users as u', 'u.id', '=', 'zkong_batches.created_by')
            ->orderByDesc('zkong_batches.id')
            ->get(['zkong_batches.*', 'u.name as created_by_name']);
        $cuentas = DB::table('warranty_items')->whereIn('zkong_batch_id', $rows->pluck('id'))
            ->groupBy('zkong_batch_id')
            ->get(['zkong_batch_id', DB::raw('count(*) as n'), DB::raw('count(distinct warranty_id) as w'),
                DB::raw('sum(zkong_result is not null) as answered'),
                DB::raw("sum(zkong_result = 'rechazada') as rejected")])
            ->keyBy('zkong_batch_id');
        // Las garantías de cada remesa (código y cliente), para verlas en la lista.
        $garantias = DB::table('warranty_items as i')->join('warranties as w', 'w.id', '=', 'i.warranty_id')
            ->whereIn('i.zkong_batch_id', $rows->pluck('id'))->distinct()->orderBy('w.code')
            ->get(['i.zkong_batch_id', 'w.id', 'w.code', 'w.client_name'])->groupBy('zkong_batch_id');
        // Cuentas solo de las enviadas (en preparación aún no hay nada en juego; anuladas, nada).
        $costes = app(ZkongCosts::class)->summary($rows->whereNotIn('status', ['borrador', 'anulada'])->pluck('id')->all());

        return response()->json(['ok' => true, 'batches' => $rows->map(fn ($b) => $this->remesa($b) + [
            'costs'      => $costes[$b->id] ?? null,
            'items'      => (int) ($cuentas[$b->id]->n ?? 0),
            'warranties' => (int) ($cuentas[$b->id]->w ?? 0),
            'answered'   => (int) ($cuentas[$b->id]->answered ?? 0),
            'rejected'   => (int) ($cuentas[$b->id]->rejected ?? 0),
            'warranty_list' => ($garantias[$b->id] ?? collect())->map(fn ($w) => ['id' => $w->id, 'code' => $w->code, 'client_name' => $w->client_name])->values(),
        ])]);
    }

    private function remesa($b): array
    {
        return [
            'id' => $b->id, 'code' => $b->code, 'status' => $b->status, 'note' => $b->note,
            'sent_at' => $b->sent_at?->toDateTimeString(), 'closed_at' => $b->closed_at?->toDateTimeString(),
            'created_at' => $b->created_at?->toDateTimeString(), 'created_by_name' => $b->created_by_name ?? null,
            'units_sent' => $b->units_sent, 'carrier' => $b->carrier, 'tracking' => $b->tracking, 'shipping_ref' => $b->shipping_ref,
            'received_at' => $b->received_at?->toDateTimeString(), 'units_received' => $b->units_received,
            'rma_number' => $b->rma_number,
            'returned_at' => $b->returned_at?->toDateTimeString(), 'units_returned' => $b->units_returned,
            'return_carrier' => $b->return_carrier, 'return_tracking' => $b->return_tracking,
            'cancelled_at' => $b->cancelled_at?->toDateTimeString(), 'cancel_reason' => $b->cancel_reason,
            // Días esperando a que ZKONG confirme que le ha llegado.
            'waiting_days' => $b->status === 'enviada' && $b->sent_at ? (int) $b->sent_at->copy()->startOfDay()->diffInDays(now()->startOfDay()) : null,
        ];
    }

    /** Línea de tiempo de la remesa, en el orden en que se apuntó cada paso (cada uno con su fecha). */
    private function eventos(int $id): array
    {
        return DB::table('zkong_batch_events as e')->leftJoin('users as u', 'u.id', '=', 'e.user_id')
            ->where('e.zkong_batch_id', $id)->orderBy('e.id')
            ->get(['e.type', 'e.text', 'e.note', 'e.at', 'u.name as user_name'])
            ->map(fn ($e) => (array) $e)->all();
    }

    /** Fecha «AAAA-MM-DD» del formulario con la hora de ahora (hoy) o a las 12:00 (otro día). */
    private function dia($v, string $campo): \Illuminate\Support\Carbon
    {
        $v = trim((string) $v);
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) abort(response()->json(['ok' => false, 'error' => "Indica la {$campo}"], 400));
        $d = \Illuminate\Support\Carbon::parse($v);
        if ($d->isAfter(today())) abort(response()->json(['ok' => false, 'error' => "La {$campo} no puede ser futura"], 400));
        return $d->isToday() ? now() : $d->setTime(12, 0);
    }

    /** Datos del transporte del formulario (todos opcionales). */
    private function transporte(Request $request): array
    {
        return [
            'carrier'      => $this->texto($request->input('carrier'), 80),
            'tracking'     => $this->texto($request->input('tracking'), 120),
            'shipping_ref' => $this->texto($request->input('shipping_ref'), 120),
        ];
    }

    private function resumenEnvio(ZkongBatch $b): string
    {
        return implode(' · ', array_filter([
            $b->units_sent ? "{$b->units_sent} unidades" : null,
            $b->carrier, $b->tracking ? "tracking {$b->tracking}" : null, $b->shipping_ref ? "ref. {$b->shipping_ref}" : null,
        ]));
    }

    private function batch(Request $request)
    {
        $b = ZkongBatch::query()->leftJoin('users as u', 'u.id', '=', 'zkong_batches.created_by')
            ->where('zkong_batches.id', (int) $request->query('id'))->first(['zkong_batches.*', 'u.name as created_by_name']);
        if (! $b) {
            return response()->json(['ok' => false, 'error' => 'La remesa no existe'], 404);
        }
        return response()->json(['ok' => true, 'batch' => $this->remesa($b), 'items' => $this->itemsDe($b->id), 'events' => $this->eventos($b->id),
            'costs' => app(ZkongCosts::class)->summary([$b->id])[$b->id]]);
    }

    private function itemsDe(int $batchId): array
    {
        $rows = $this->dispositivos()->where('i.zkong_batch_id', $batchId)->orderBy('w.code')->orderBy('i.id')->get();
        $fotos = WarrantyPhotos::forItems($rows->pluck('id')->all());
        $costes = app(ZkongCosts::class)->perItem($rows->all());
        return $rows->map(fn ($i) => $this->fila($i, $fotos, $costes))->all();
    }

    /** Ids pedidos que están de verdad en la bandeja (enviar, sin remesa). */
    private function deLaBandeja(Request $request)
    {
        $ids = array_map('intval', (array) $request->input('item_ids', []));
        return WarrantyItem::whereIn('id', $ids)->whereIn('resolution', self::resZkong())
            ->where('zkong_decision', 'enviar')->whereNull('zkong_batch_id')->get(['id', 'code', 'warranty_id']);
    }

    private function create(Request $request)
    {
        $items = $this->deLaBandeja($request);
        if ($items->isEmpty()) {
            return response()->json(['ok' => false, 'error' => 'Elige al menos una etiqueta de la bandeja'], 400);
        }
        $b = DB::transaction(function () use ($items, $request) {
            $b = ZkongBatch::create(['code' => ZkongBatch::nextCode(), 'status' => 'borrador', 'created_by' => $request->user()->id,
                'note' => $this->texto($request->input('note'), 2000)]);
            WarrantyItem::whereIn('id', $items->pluck('id'))->update(['zkong_batch_id' => $b->id]);
            $b->evento('creada', 'Remesa creada con ' . $items->count() . ' ' . ($items->count() === 1 ? 'etiqueta' : 'etiquetas'), $request->user()->id);
            return $b;
        });
        $this->historial($items, $request->user()->id, "En la remesa {$b->code} (borrador)");
        return response()->json(['ok' => true, 'id' => $b->id, 'code' => $b->code]);
    }

    private function borrador(Request $request): ZkongBatch
    {
        $b = ZkongBatch::findOrFail((int) $request->input('id'));
        if ($b->status !== 'borrador') {
            abort(response()->json(['ok' => false, 'error' => 'La remesa ya está enviada: no se le pueden quitar ni poner etiquetas'], 400));
        }
        return $b;
    }

    private function add(Request $request)
    {
        $b = $this->borrador($request);
        $items = $this->deLaBandeja($request);
        if ($items->isEmpty()) {
            return response()->json(['ok' => false, 'error' => 'Elige al menos una etiqueta de la bandeja'], 400);
        }
        WarrantyItem::whereIn('id', $items->pluck('id'))->update(['zkong_batch_id' => $b->id]);
        $this->historial($items, $request->user()->id, "En la remesa {$b->code} (borrador)");
        return response()->json(['ok' => true, 'id' => $b->id, 'code' => $b->code]);
    }

    private function remove(Request $request)
    {
        $b = $this->borrador($request);
        $ids = array_map('intval', (array) $request->input('item_ids', []));
        $items = WarrantyItem::where('zkong_batch_id', $b->id)->whereIn('id', $ids)->get(['id', 'code', 'warranty_id']);
        WarrantyItem::whereIn('id', $items->pluck('id'))->update(['zkong_batch_id' => null]);
        $this->historial($items, $request->user()->id, "Sale de la remesa {$b->code}: vuelve a la bandeja");
        return response()->json(['ok' => true, 'items' => $this->itemsDe($b->id)]);
    }

    private function send(Request $request)
    {
        $b = $this->borrador($request);
        $items = WarrantyItem::where('zkong_batch_id', $b->id)->get(['id', 'code', 'warranty_id']);
        if ($items->isEmpty()) {
            return response()->json(['ok' => false, 'error' => 'La remesa no tiene etiquetas'], 400);
        }
        // A mano: fecha, unidades, transportista, tracking y albarán (no hay API del transportista).
        $cuando = $this->dia($request->input('sent_on', today()->toDateString()), 'fecha de envío');
        $uds = (int) $request->input('units_sent', $items->count());
        if ($uds < 1 || $uds > 100000) {
            return response()->json(['ok' => false, 'error' => 'Indica cuántas unidades se envían'], 400);
        }
        $rma = $this->texto($request->input('rma_number'), 60);
        $b->forceFill(['status' => 'enviada', 'sent_at' => $cuando, 'units_sent' => $uds] + ($rma ? ['rma_number' => $rma] : []) + $this->transporte($request))->save();
        // El coste de cada etiqueta, del portal, tal como está hoy.
        app(ZkongCosts::class)->snapshot($b);
        $b->evento('enviada', 'Enviada a ZKONG' . ($rma ? " (RMA {$rma})" : '') . (($r = $this->resumenEnvio($b)) ? ": {$r}" : ''), $request->user()->id, $request->input('note'), $cuando);
        $this->historial($items, $request->user()->id, "Enviada a ZKONG en la remesa {$b->code}");
        return response()->json(['ok' => true]);
    }

    /** Corregir o completar el transporte (el tracking suele llegar después). */
    private function shipping(Request $request)
    {
        $b = ZkongBatch::findOrFail((int) $request->input('id'));
        if (! in_array($b->status, ZkongBatch::EN_ZKONG, true)) {
            return response()->json(['ok' => false, 'error' => 'Solo se cambia el envío de una remesa enviada'], 400);
        }
        $datos = $this->transporte($request);
        if ($request->has('sent_on')) $datos['sent_at'] = $this->dia($request->input('sent_on'), 'fecha de envío');
        if ($request->filled('units_sent')) $datos['units_sent'] = max(1, (int) $request->input('units_sent'));
        $b->forceFill($datos)->save();
        $b->evento('envio', 'Datos del envío actualizados' . (($r = $this->resumenEnvio($b)) ? ": {$r}" : ''), $request->user()->id);
        $items = WarrantyItem::where('zkong_batch_id', $b->id)->get(['id', 'code', 'warranty_id']);
        $this->historial($items, $request->user()->id, "Envío de la remesa {$b->code} actualizado" . ($r ? " ({$r})" : ''));
        return response()->json(['ok' => true]);
    }

    /** ZKONG confirma que le ha llegado la remesa. */
    private function receive(Request $request)
    {
        $b = ZkongBatch::findOrFail((int) $request->input('id'));
        if ($b->status !== 'enviada') {
            return response()->json(['ok' => false, 'error' => $b->received_at ? 'La recepción ya está apuntada' : 'Primero registra el envío'], 400);
        }
        $cuando = $this->dia($request->input('received_on'), 'fecha de recepción');
        if ($b->sent_at && $cuando->copy()->startOfDay()->lt($b->sent_at->copy()->startOfDay())) {
            return response()->json(['ok' => false, 'error' => 'La recepción no puede ser anterior al envío'], 400);
        }
        $uds = $request->filled('units_received') ? max(0, (int) $request->input('units_received')) : null;
        $b->forceFill(['received_at' => $cuando, 'units_received' => $uds])->save();
        $b->forceFill(['status' => $b->estadoAbierto()])->save();
        $b->evento('recibida', 'Recibida por ZKONG' . ($uds !== null ? ": {$uds} unidades" : ''), $request->user()->id, $request->input('note'), $cuando);
        $items = WarrantyItem::where('zkong_batch_id', $b->id)->get(['id', 'code', 'warranty_id']);
        $this->historial($items, $request->user()->id, "ZKONG confirma que ha recibido la remesa {$b->code}");
        return response()->json(['ok' => true]);
    }

    /** El número de RMA que da ZKONG (suele llegar después de pedirlo). Vacío = quitarlo. */
    private function rma(Request $request)
    {
        $b = ZkongBatch::findOrFail((int) $request->input('id'));
        if ($b->status === 'anulada') {
            return response()->json(['ok' => false, 'error' => 'La remesa está anulada'], 400);
        }
        $rma = $this->texto($request->input('rma_number'), 60);
        if ($rma === $b->rma_number) return response()->json(['ok' => true]);
        $antes = $b->rma_number;
        $b->forceFill(['rma_number' => $rma])->save();
        $b->evento('rma', $rma ? ($antes ? "Nº de RMA cambiado: {$antes} → {$rma}" : "Nº de RMA de ZKONG: {$rma}") : 'Nº de RMA quitado', $request->user()->id);
        $items = WarrantyItem::where('zkong_batch_id', $b->id)->get(['id', 'code', 'warranty_id']);
        $this->historial($items, $request->user()->id, $rma ? "Remesa {$b->code}: RMA {$rma} de ZKONG" : "Remesa {$b->code}: sin nº de RMA");
        return response()->json(['ok' => true]);
    }

    /**
     * La vuelta: llegan las reposiciones de ZKONG (fecha, unidades, transportista y
     * tracking). Se puede corregir; con la fecha vacía se quita.
     */
    private function vuelta(Request $request)
    {
        $b = ZkongBatch::findOrFail((int) $request->input('id'));
        if (! in_array($b->status, ['recibida', 'respondida', 'cerrada'], true)) {
            return response()->json(['ok' => false, 'error' => $b->status === 'enviada' ? 'Primero apunta que ZKONG ha recibido la remesa' : 'Solo se apunta la vuelta de una remesa que ZKONG ha recibido'], 400);
        }
        $items = WarrantyItem::where('zkong_batch_id', $b->id)->get(['id', 'code', 'warranty_id']);
        if (trim((string) $request->input('returned_on', '')) === '') {
            $b->forceFill(['returned_at' => null, 'units_returned' => null, 'return_carrier' => null, 'return_tracking' => null])->save();
            $b->evento('vuelta', 'Vuelta quitada', $request->user()->id);
            return response()->json(['ok' => true]);
        }
        $cuando = $this->dia($request->input('returned_on'), 'fecha de vuelta');
        if ($b->received_at && $cuando->copy()->startOfDay()->lt($b->received_at->copy()->startOfDay())) {
            return response()->json(['ok' => false, 'error' => 'La vuelta no puede ser anterior a la recepción en ZKONG'], 400);
        }
        $uds = $request->filled('units_returned') ? max(0, min(100000, (int) $request->input('units_returned'))) : null;
        $nueva = ! $b->returned_at;
        $b->forceFill(['returned_at' => $cuando, 'units_returned' => $uds,
            'return_carrier' => $this->texto($request->input('return_carrier'), 80), 'return_tracking' => $this->texto($request->input('return_tracking'), 120)])->save();
        $r = implode(' · ', array_filter([$uds !== null ? "{$uds} unidades" : null, $b->return_carrier, $b->return_tracking ? "tracking {$b->return_tracking}" : null]));
        $b->evento('vuelta', ($nueva ? 'Llegan las reposiciones de ZKONG' : 'Vuelta actualizada') . ($r ? ": {$r}" : ''), $request->user()->id, $request->input('note'), $cuando);
        $this->historial($items, $request->user()->id, ($nueva ? 'Llegan las reposiciones de ZKONG' : 'Vuelta actualizada') . " (remesa {$b->code})");
        return response()->json(['ok' => true]);
    }

    /** Anular una remesa enviada (con motivo): sus etiquetas vuelven a la bandeja. */
    private function cancel(Request $request)
    {
        $b = ZkongBatch::findOrFail((int) $request->input('id'));
        if (! in_array($b->status, ['enviada', 'recibida'], true)) {
            return response()->json(['ok' => false, 'error' => $b->status === 'borrador' ? 'Una remesa en preparación se descarta, no se anula' : 'Solo se anula una remesa enviada sin respuestas'], 400);
        }
        $motivo = $this->texto($request->input('reason'), 500);
        if (! $motivo) {
            return response()->json(['ok' => false, 'error' => 'Indica el motivo de la anulación'], 400);
        }
        $items = WarrantyItem::where('zkong_batch_id', $b->id)->get(['id', 'code', 'warranty_id']);
        DB::transaction(function () use ($b, $motivo, $request) {
            WarrantyItem::where('zkong_batch_id', $b->id)->update(['zkong_batch_id' => null, 'zkong_result' => null, 'zkong_result_note' => null,
                'zkong_unit_cost' => null, 'zkong_cost_source' => null]);   // en la próxima remesa, el coste de entonces
            $b->forceFill(['status' => 'anulada', 'cancelled_at' => now(), 'cancel_reason' => $motivo])->save();
            $b->evento('anulada', 'Remesa anulada', $request->user()->id, $motivo);
        });
        $this->historial($items, $request->user()->id, "Remesa {$b->code} anulada: vuelve a la bandeja");
        return response()->json(['ok' => true]);
    }

    /** Respuesta de ZKONG para una o varias etiquetas (vacía = quitarla). */
    private function result(Request $request)
    {
        $b = ZkongBatch::findOrFail((int) $request->input('id'));
        if (! in_array($b->status, ZkongBatch::EN_ZKONG, true)) {
            return response()->json(['ok' => false, 'error' => match ($b->status) {
                'borrador' => 'Primero envía la remesa a ZKONG', 'anulada' => 'La remesa está anulada',
                default => 'La remesa está cerrada: reábrela para cambiar respuestas',
            }], 400);
        }
        $res = (string) $request->input('result', '');
        if ($res !== '' && ! isset(ZkongBatch::RESULTS[$res])) {
            return response()->json(['ok' => false, 'error' => 'Respuesta no válida'], 400);
        }
        $ids = array_map('intval', (array) $request->input('item_ids', []));
        $items = WarrantyItem::where('zkong_batch_id', $b->id)->whereIn('id', $ids)->get(['id', 'code', 'warranty_id']);
        if ($items->isEmpty()) {
            return response()->json(['ok' => false, 'error' => 'Elige al menos una etiqueta de la remesa'], 400);
        }
        WarrantyItem::whereIn('id', $items->pluck('id'))->update([
            'zkong_result'      => $res ?: null,
            'zkong_result_note' => $res ? $this->texto($request->input('note'), 500) : null,
        ]);

        // Respondida cuando todas tienen respuesta; si se quita alguna, vuelve a Recibida/Enviada.
        $b->forceFill(['status' => $b->estadoAbierto()])->save();
        // Si al enviarla el portal no respondía, el coste se toma ahora.
        app(ZkongCosts::class)->snapshot($b);

        $texto = 'Respuesta de ZKONG: ' . ($res ? ZkongBatch::RESULTS[$res] : 'sin respuesta') . ' (' . $items->count() . ' ' . ($items->count() === 1 ? 'etiqueta' : 'etiquetas') . ')';
        $b->evento('respuesta', $texto, $request->user()->id, $res ? $request->input('note') : null);
        $this->historial($items, $request->user()->id, 'Respuesta de ZKONG (' . $b->code . '): ' . ($res ? ZkongBatch::RESULTS[$res] : 'sin respuesta'));
        return response()->json(['ok' => true, 'status' => $b->status, 'items' => $this->itemsDe($b->id)]);
    }

    private function close(Request $request)
    {
        $b = ZkongBatch::findOrFail((int) $request->input('id'));
        if (! in_array($b->status, ZkongBatch::EN_ZKONG, true)) {
            return response()->json(['ok' => false, 'error' => 'Solo se cierra una remesa enviada'], 400);
        }
        $b->forceFill(['status' => 'cerrada', 'closed_at' => now()])->save();
        $b->evento('cerrada', 'Remesa cerrada', $request->user()->id);
        $items = WarrantyItem::where('zkong_batch_id', $b->id)->get(['id', 'code', 'warranty_id']);
        $this->historial($items, $request->user()->id, "Remesa {$b->code} cerrada");
        return response()->json(['ok' => true]);
    }

    private function reopen(Request $request)
    {
        $b = ZkongBatch::findOrFail((int) $request->input('id'));
        if ($b->status !== 'cerrada') {
            return response()->json(['ok' => false, 'error' => 'La remesa no está cerrada'], 400);
        }
        $b->forceFill(['closed_at' => null])->save();
        $b->forceFill(['status' => $b->estadoAbierto()])->save();
        $b->evento('reabierta', 'Remesa reabierta', $request->user()->id);
        return response()->json(['ok' => true]);
    }

    private function note(Request $request)
    {
        $b = ZkongBatch::findOrFail((int) $request->input('id'));
        $b->forceFill(['note' => $this->texto($request->input('note'), 2000)])->save();
        return response()->json(['ok' => true]);
    }

    private function delete(Request $request)
    {
        $b = $this->borrador($request);
        $items = WarrantyItem::where('zkong_batch_id', $b->id)->get(['id', 'code', 'warranty_id']);
        DB::transaction(function () use ($b) {
            WarrantyItem::where('zkong_batch_id', $b->id)->update(['zkong_batch_id' => null]);
            $b->delete();
        });
        $this->historial($items, $request->user()->id, "Remesa {$b->code} descartada: vuelve a la bandeja");
        return response()->json(['ok' => true]);
    }

    /** Excel del RMA con las columnas de ZKONG. */
    private function xlsx(Request $request)
    {
        $b = ZkongBatch::findOrFail((int) $request->query('id'));
        $bin = app(ZkongRmaXlsx::class)->build($b, $this->itemsDe($b->id));
        return response($bin, 200, [
            'Content-Type'        => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="RMA-' . $b->code . '.xlsx"',
            'Cache-Control'       => 'no-store',
        ]);
    }

    /** Fotos de la remesa: una carpeta por expediente y cada foto con el código del dispositivo. */
    private function zip(Request $request)
    {
        $b = ZkongBatch::findOrFail((int) $request->query('id'));
        $fotos = DB::table('warranty_item_photos as p')->join('warranty_items as i', 'i.id', '=', 'p.warranty_item_id')
            ->join('warranties as w', 'w.id', '=', 'i.warranty_id')
            ->where('i.zkong_batch_id', $b->id)->orderBy('w.code')->orderBy('i.id')
            ->get(['p.path', 'p.type', 'i.code', 'i.kind', 'w.code as warranty_code']);
        if ($fotos->isEmpty()) {
            return response()->json(['ok' => false, 'error' => 'La remesa no tiene fotos'], 404);
        }

        $tmp = tempnam(sys_get_temp_dir(), 'rma');
        $zip = new \ZipArchive();
        $zip->open($tmp, \ZipArchive::OVERWRITE);
        $disco = Storage::disk('local');
        foreach ($fotos as $f) {
            if (! $disco->exists($f->path)) continue;
            $ext = pathinfo($f->path, PATHINFO_EXTENSION);
            $zip->addFile($disco->path($f->path), "{$f->warranty_code}/{$f->code}_{$f->type}.{$ext}");
        }
        $zip->close();

        return response()->download($tmp, "RMA-{$b->code}-fotos.zip", ['Content-Type' => 'application/zip', 'Cache-Control' => 'no-store'])
            ->deleteFileAfterSend();
    }

    /** Una línea en el historial de cada garantía afectada: «Texto (n): códigos». */
    private function historial($items, int $userId, string $texto): void
    {
        foreach ($items->groupBy('warranty_id') as $wid => $grupo) {
            $codes = $grupo->pluck('code')->all();
            $lista = implode(', ', array_slice($codes, 0, 10)) . (count($codes) > 10 ? ' y ' . (count($codes) - 10) . ' más' : '');
            DB::table('warranty_events')->insert([
                'warranty_id' => $wid, 'user_id' => $userId, 'field' => 'zkong',
                'from_value' => null, 'to_value' => "{$texto} (" . count($codes) . "): {$lista}", 'created_at' => now(),
            ]);
            DB::table('warranties')->where('id', $wid)->update(['last_activity_at' => now()]);
        }
    }

    private function texto($v, int $max): ?string
    {
        $v = trim((string) $v);
        return $v === '' ? null : mb_substr($v, 0, $max);
    }
}
