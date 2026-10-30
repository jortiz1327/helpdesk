<?php

namespace App\Http\Controllers;

use App\Models\DeviceSighting;
use App\Models\ZkongServer;
use App\Services\Zkong\ZkongDevices;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Throwable;

/**
 * Centro de dispositivos (Gestión de garantías). Solo consulta: listas de etiquetas y
 * antenas por servidor, búsqueda de códigos en todos los servidores y ficha con historial.
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
        } catch (Throwable $e) {
            // Un servidor caído o que rechaza el acceso no es un error de la app: se cuenta.
            report($e);
            return response()->json(['ok' => false, 'error' => $e->getMessage() ?: 'No se pudo consultar el servidor'], 502);
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
        return response()->json(['ok' => true] + $this->devices->search($codes));
    }

    private function sheet(Request $request)
    {
        $code = (string) $request->query('code', '');
        if (trim($code) === '') {
            return response()->json(['ok' => false, 'error' => 'Falta el código'], 400);
        }
        return response()->json(['ok' => true] + $this->devices->sheet($code, $request->user()?->id));
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
