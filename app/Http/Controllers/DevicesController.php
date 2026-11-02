<?php

namespace App\Http\Controllers;

use App\Models\DeviceSighting;
use App\Models\Warranty;
use App\Models\WarrantyItem;
use App\Models\ZkongBatch;
use App\Models\ZkongServer;
use App\Services\Zkong\ZkongDevices;
use App\Services\Zkong\ZkongException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Centro de dispositivos (Gestión de garantías). Solo consulta: listas de etiquetas y
 * antenas por servidor, búsqueda de códigos en todos los servidores y ficha con historial
 * y con las garantías en las que ha estado cada código.
 * Requiere warranties.access (ruta).
 */
class DevicesController extends Controller
{
    public function __construct(private ZkongDevices $devices)
    {
    }

    public function handle(Request $request)
    {
        try {
            return match ($request->query('action', 'servers')) {
                'filters' => $this->filters($request),
                'list'    => $this->list($request),
                'items'   => response()->json(['ok' => true, 'items' => $this->devices->items($this->server($request), (array) $request->input('items', []))]),
                'stats'   => response()->json(['ok' => true, 'stats' => $this->devices->stats($this->server($request), $this->kind($request))]),
                'search'  => $this->search($request),
                'sheet'   => $this->sheet($request),
                default   => $this->servers(),
            };
        } catch (ModelNotFoundException) {
            return response()->json(['ok' => false, 'error' => 'Ese servidor no existe o está desactivado'], 404);
        } catch (ZkongException $e) {
            // Un servidor caído o que rechaza el acceso no es un error de la app: se cuenta.
            report($e);
            return response()->json(['ok' => false, 'error' => $e->getMessage() ?: 'No se pudo consultar el servidor'], 502);
        } catch (Throwable $e) {
            // Cualquier otro fallo, solo en el log: su mensaje puede llevar SQL o rutas.
            report($e);
            return response()->json(['ok' => false, 'error' => 'No se pudo consultar el servidor'], 500);
        }
    }

    private function servers()
    {
        $rows = $this->devices->servers()->map(fn ($s) => ['id' => $s->id, 'name' => $s->name]);
        return response()->json(['ok' => true, 'servers' => $rows]);
    }

    private function filters(Request $request)
    {
        $s = $this->server($request);
        return response()->json(['ok' => true] + $this->devices->filters($s, $this->kind($request)));
    }

    private function list(Request $request)
    {
        $s = $this->server($request);
        $f = (array) json_decode((string) $request->query('f', '{}'), true);
        $page = max(0, (int) $request->query('page', 0));
        $size = (int) $request->query('size', 25);
        $r = $this->kind($request) === DeviceSighting::AP
            ? $this->devices->ap($s, $f, $page, $size)
            : $this->devices->esl($s, $f, $page, $size);
        return response()->json(['ok' => true] + $r);
    }

    private function search(Request $request)
    {
        $codes = preg_split('/[\s,;]+/', (string) $request->input('codes', ''), -1, PREG_SPLIT_NO_EMPTY);
        if (! $codes) {
            return response()->json(['ok' => false, 'error' => 'Escribe al menos un código'], 400);
        }
        if (count($codes) > 50) {
            return response()->json(['ok' => false, 'error' => 'Como máximo 50 códigos por búsqueda'], 400);
        }
        $r = $this->devices->search($codes);
        $g = $this->garantias(array_column($r['results'], 'code'));
        $r['results'] = array_map(fn ($x) => $x + ['warranties' => $g[$x['code']] ?? []], $r['results']);
        return response()->json(['ok' => true] + $r);
    }

    /**
     * Las garantías en las que ha estado cada código (más recientes primero), con su estado
     * y lo que se decidió en la revisión y respondió ZKONG. Los códigos ya van normalizados.
     */
    private function garantias(array $codes): array
    {
        if (! $codes) return [];
        $estados = Warranty::statuses();
        $res = WarrantyItem::RESOLUTIONS;
        return DB::table('warranty_items as i')->join('warranties as w', 'w.id', '=', 'i.warranty_id')
            ->whereIn('i.code', $codes)->orderByDesc('w.received_at')->orderByDesc('w.id')
            ->get(['i.code', 'w.id', 'w.code as warranty_code', 'w.status', 'w.received_at', 'w.client_name', 'i.resolution', 'i.zkong_result'])
            ->groupBy('code')
            ->map(fn ($xs) => $xs->map(fn ($x) => [
                'id' => $x->id, 'code' => $x->warranty_code, 'status' => $estados[$x->status] ?? $x->status,
                'closed' => in_array($x->status, Warranty::closedStatuses(), true),
                'received_at' => $x->received_at, 'client_name' => $x->client_name,
                'resolution' => $x->resolution && $x->resolution !== 'pendiente' ? ($res[$x->resolution]['label'] ?? $x->resolution) : null,
                'zkong_result' => $x->zkong_result ? (ZkongBatch::RESULTS[$x->zkong_result] ?? $x->zkong_result) : null,
            ])->values()->all())
            ->all();
    }

    private function sheet(Request $request)
    {
        $code = (string) $request->query('code', '');
        if (trim($code) === '') {
            return response()->json(['ok' => false, 'error' => 'Falta el código'], 400);
        }
        $r = $this->devices->sheet($code, $request->user()?->id);
        return response()->json(['ok' => true] + $r + ['warranties' => $this->garantias([$r['code']])[$r['code']] ?? []]);
    }

    private function server(Request $request): ZkongServer
    {
        return ZkongServer::where('active', true)->findOrFail((int) $request->query('server'));
    }

    private function kind(Request $request): string
    {
        return $request->query('kind') === 'ap' ? DeviceSighting::AP : DeviceSighting::ESL;
    }
}
