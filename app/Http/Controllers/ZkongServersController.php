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
            'product_username' => $s->product_username,
            'product_store_id' => $s->product_store_id,
            'product_token_updated_at' => $s->product_token_updated_at?->toDateTimeString(),
            'updated_at' => $s->updated_at?->toDateTimeString(),
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
        // los dos. La contraseña viaja cifrada con la clave del servidor, que queda fijada.
        if (! preg_match('#^https?://[a-z0-9.-]+(:\d+)?(/[\w./-]*)?$#i', $url)) {
            return response()->json(['ok' => false, 'error' => 'Escribe la dirección completa, p. ej. http://app02.etiquetaselectronicas.com:9999'], 400);
        }
        if (! ZkongClient::hostPermitido($url)) {
            return response()->json(['ok' => false, 'error' => 'Esa dirección es de una red interna: escribe la del servidor ZKONG en internet'], 400);
        }
        if (! $id && $pass === '') {
            return response()->json(['ok' => false, 'error' => 'Falta la contraseña'], 400);
        }

        $s = $id ? ZkongServer::findOrFail($id) : new ZkongServer(['position' => (int) ZkongServer::max('position') + 1]);
        // La contraseña guardada solo vale para la dirección y el usuario con que se guardó:
        // si cambian, hay que volver a escribirla (si no, se le mandaría a otra máquina).
        if ($s->exists && $pass === '' && ($s->base_url !== $url || $s->username !== $user)) {
            return response()->json(['ok' => false, 'error' => 'Al cambiar la dirección o el usuario, vuelve a escribir la contraseña'], 400);
        }
        // Cuenta de productos (opcional): mismas reglas. Vacía = el servidor no tiene.
        $pUser = trim((string) $request->input('product_username', ''));
        $pPass = (string) $request->input('product_password', '');
        $pAntes = (string) $s->product_username;
        if ($pUser !== '' && $pPass === '' && ($pAntes !== $pUser || ($s->exists && $s->base_url !== $url) || ! $s->product_password)) {
            return response()->json(['ok' => false, 'error' => 'Escribe la contraseña de la cuenta de productos'], 400);
        }
        $tienda = preg_replace('/\D/', '', (string) $request->input('product_store_id', ''));
        if ($pUser !== '' && $tienda === '') {
            return response()->json(['ok' => false, 'error' => 'Indica la tienda del Cloud donde la cuenta de productos crea los productos'], 400);
        }
        $s->product_store_id = $pUser !== '' ? mb_substr($tienda, 0, 40) : null;
        $cambiaProductos = $pAntes !== $pUser || $pPass !== '' || ($s->exists && $s->base_url !== $url);

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
        // Otra dirección, usuario o contraseña: el token guardado ya no vale, y la clave
        // pública del servidor se vuelve a apuntar en el siguiente acceso.
        if ($cambiaAcceso) {
            $s->token = null;
            $s->token_updated_at = null;
            $s->public_key_sha256 = null;
        }
        if ($cambiaProductos) {
            $s->product_username = $pUser !== '' ? mb_substr($pUser, 0, 120) : null;
            $s->product_password = $pUser !== '' ? ($pPass !== '' ? $pPass : $s->product_password) : null;
            $s->product_token = null;
            $s->product_merchant_id = null;
            $s->product_agency_id = null;
            $s->product_token_updated_at = null;
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
        $t = microtime(true);
        $ms = fn () => (int) round((microtime(true) - $t) * 1000);
        try {
            // ?cuenta=productos prueba la cuenta que crea productos.
            $info = (new ZkongClient($s, $request->query('cuenta') === 'productos'))->login();
            return response()->json(['ok' => true, 'merchant_id' => $info['merchant_id'], 'ms' => $ms()]);
        } catch (Throwable $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage(), 'ms' => $ms()]);
        }
    }
}
