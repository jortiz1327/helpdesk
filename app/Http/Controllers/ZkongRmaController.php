<?php

namespace App\Http\Controllers;

use App\Models\WarrantyItem;
use App\Models\ZkongBatch;
use App\Services\WarrantyPhotos;
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
        return match ($request->query('action', 'inbox')) {
            'batches' => $this->batches(),
            'batch'   => $this->batch($request),
            'create'  => $this->create($request),
            'add'     => $this->add($request),
            'remove'  => $this->remove($request),
            'send'    => $this->send($request),
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
                'w.id as warranty_id', 'w.code as warranty_code', 'w.client_name', 'w.project_name']);
    }

    private function fila(object $i, array $fotos): array
    {
        $tipos = array_keys(WarrantyItem::photoTypes($i->kind));
        $mias = array_values(array_filter($fotos[$i->id] ?? [], fn ($p) => in_array($p['type'], $tipos, true)));
        return [
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

        return response()->json(['ok' => true, 'batches' => $rows->map(fn ($b) => $this->remesa($b) + [
            'items'      => (int) ($cuentas[$b->id]->n ?? 0),
            'warranties' => (int) ($cuentas[$b->id]->w ?? 0),
            'answered'   => (int) ($cuentas[$b->id]->answered ?? 0),
            'rejected'   => (int) ($cuentas[$b->id]->rejected ?? 0),
        ])]);
    }

    private function remesa($b): array
    {
        return [
            'id' => $b->id, 'code' => $b->code, 'status' => $b->status, 'note' => $b->note,
            'sent_at' => $b->sent_at?->toDateTimeString(), 'closed_at' => $b->closed_at?->toDateTimeString(),
            'created_at' => $b->created_at?->toDateTimeString(), 'created_by_name' => $b->created_by_name ?? null,
        ];
    }

    private function batch(Request $request)
    {
        $b = ZkongBatch::query()->leftJoin('users as u', 'u.id', '=', 'zkong_batches.created_by')
            ->where('zkong_batches.id', (int) $request->query('id'))->first(['zkong_batches.*', 'u.name as created_by_name']);
        if (! $b) {
            return response()->json(['ok' => false, 'error' => 'La remesa no existe'], 404);
        }
        return response()->json(['ok' => true, 'batch' => $this->remesa($b), 'items' => $this->itemsDe($b->id)]);
    }

    private function itemsDe(int $batchId): array
    {
        $rows = $this->dispositivos()->where('i.zkong_batch_id', $batchId)->orderBy('w.code')->orderBy('i.id')->get();
        $fotos = WarrantyPhotos::forItems($rows->pluck('id')->all());
        return $rows->map(fn ($i) => $this->fila($i, $fotos))->all();
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
        $b->forceFill(['status' => 'enviada', 'sent_at' => now()])->save();
        $this->historial($items, $request->user()->id, "Enviada a ZKONG en la remesa {$b->code}");
        return response()->json(['ok' => true]);
    }

    /** Respuesta de ZKONG para una o varias etiquetas (vacía = quitarla). */
    private function result(Request $request)
    {
        $b = ZkongBatch::findOrFail((int) $request->input('id'));
        if (! in_array($b->status, ['enviada', 'respondida'], true)) {
            return response()->json(['ok' => false, 'error' => $b->status === 'borrador' ? 'Primero envía la remesa a ZKONG' : 'La remesa está cerrada: reábrela para cambiar respuestas'], 400);
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

        // Respondida cuando todas tienen respuesta; si se quita alguna, vuelve a Enviada.
        $faltan = WarrantyItem::where('zkong_batch_id', $b->id)->whereNull('zkong_result')->exists();
        $b->forceFill(['status' => $faltan ? 'enviada' : 'respondida'])->save();

        $this->historial($items, $request->user()->id, 'Respuesta de ZKONG (' . $b->code . '): ' . ($res ? ZkongBatch::RESULTS[$res] : 'sin respuesta'));
        return response()->json(['ok' => true, 'status' => $b->status, 'items' => $this->itemsDe($b->id)]);
    }

    private function close(Request $request)
    {
        $b = ZkongBatch::findOrFail((int) $request->input('id'));
        if (! in_array($b->status, ['enviada', 'respondida'], true)) {
            return response()->json(['ok' => false, 'error' => 'Solo se cierra una remesa enviada'], 400);
        }
        $b->forceFill(['status' => 'cerrada', 'closed_at' => now()])->save();
        return response()->json(['ok' => true]);
    }

    private function reopen(Request $request)
    {
        $b = ZkongBatch::findOrFail((int) $request->input('id'));
        if ($b->status !== 'cerrada') {
            return response()->json(['ok' => false, 'error' => 'La remesa no está cerrada'], 400);
        }
        $faltan = WarrantyItem::where('zkong_batch_id', $b->id)->whereNull('zkong_result')->exists();
        $b->forceFill(['status' => $faltan ? 'enviada' : 'respondida', 'closed_at' => null])->save();
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
