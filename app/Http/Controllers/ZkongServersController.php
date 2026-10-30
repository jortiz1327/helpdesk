<?php

namespace App\Http\Controllers;

use App\Models\ZkongServer;
use App\Services\Zkong\ZkongClient;
use Illuminate\Http\Request;
use Throwable;

/**
 * Servidores ZKONG del Centro de dispositivos. Solo el superadmin (warranties.servers no
 * se asigna a ningún rol). La contraseña nunca vuelve al navegador, y «Probar» dice si
 * entra pero no enseña el token.
 */
class ZkongServersController extends Controller
{
    public function handle(Request $request)
    {
        return match ($request->query('action', 'list')) {
            'save'   => $this->save($request),
            'delete' => $this->delete($request),
            'test'   => $this->test($request),
            default  => $this->list(),
        };
    }

    private function list()
    {
        $rows = ZkongServer::orderBy('position')->orderBy('id')->get()->map(fn ($s) => [
            'id' => $s->id, 'name' => $s->name, 'base_url' => $s->base_url, 'username' => $s->username,
            'active' => $s->active, 'merchant_id' => $s->merchant_id,
            'token_updated_at' => $s->token_updated_at?->toDateTimeString(),
        ]);
        return response()->json(['ok' => true, 'servers' => $rows]);
    }

    private function save(Request $request)
    {
        $id = (int) $request->input('id');
        $name = trim((string) $request->input('name'));
        $url = rtrim(trim((string) $request->input('base_url')), '/');
        $user = trim((string) $request->input('username'));
        $pass = (string) $request->input('password', '');

        if ($name === '' || $user === '') {
            return response()->json(['ok' => false, 'error' => 'El nombre y el usuario son obligatorios'], 400);
        }
        // Los servidores ZKONG suelen ir por http y puerto propio (p. ej. :9999): se admiten
        // los dos. La contraseña viaja cifrada con la clave del servidor igualmente.
        if (! preg_match('#^https?://[a-z0-9.-]+(:\d+)?(/[\w./-]*)?$#i', $url)) {
            return response()->json(['ok' => false, 'error' => 'Escribe la dirección completa, p. ej. http://app02.etiquetaselectronicas.com:9999'], 400);
        }
        if (! $id && $pass === '') {
            return response()->json(['ok' => false, 'error' => 'Falta la contraseña'], 400);
        }

        $s = $id ? ZkongServer::findOrFail($id) : new ZkongServer(['position' => (int) ZkongServer::max('position') + 1]);
        $cambiaAcceso = ! $s->exists || $s->base_url !== $url || $s->username !== $user || $pass !== '';
        $s->fill([
            'name'     => mb_substr($name, 0, 60),
            'base_url' => mb_substr($url, 0, 190),
            'username' => mb_substr($user, 0, 120),
            'active'   => filter_var($request->input('active', true), FILTER_VALIDATE_BOOLEAN),
        ]);
        if ($pass !== '') {
            $s->password = $pass;
        }
        // Otra dirección, usuario o contraseña: el token guardado ya no vale.
        if ($cambiaAcceso) {
            $s->token = null;
            $s->token_updated_at = null;
        }
        $s->save();

        return response()->json(['ok' => true, 'id' => $s->id]);
    }

    private function delete(Request $request)
    {
        ZkongServer::where('id', (int) $request->input('id'))->delete();
        return response()->json(['ok' => true]);
    }

    private function test(Request $request)
    {
        $s = ZkongServer::findOrFail((int) $request->input('id'));
        try {
            $info = (new ZkongClient($s))->login();
            return response()->json(['ok' => true, 'merchant_id' => $info['merchant_id']]);
        } catch (Throwable $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()]);
        }
    }
}
