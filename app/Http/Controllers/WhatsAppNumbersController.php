<?php

namespace App\Http\Controllers;

use App\Models\WhatsAppNumber;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

/**
 * Números de WhatsApp del helpdesk (opción B). CRUD del catálogo que usa el webhook
 * para enrutar por `phone_number_id`. Solo superadmin (ruta con can:settings.manage).
 */
class WhatsAppNumbersController extends Controller
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

    protected function list()
    {
        // Es una pantalla de superadmin; se devuelven las credenciales para poder
        // editarlas (mismo criterio que la Configuración de WhatsApp existente).
        return response()->json([
            'ok'      => true,
            'numbers' => WhatsAppNumber::orderBy('id')->get(),
            'funciones' => [
                ['value' => 'campanas', 'label' => 'Campañas'],
            ],
        ]);
    }

    protected function save(Request $request)
    {
        $label   = trim((string) $request->input('label'));
        $phoneId = trim((string) $request->input('phone_number_id'));
        $funcion = (string) $request->input('funcion', 'campanas');

        if ($label === '')   return response()->json(['ok' => false, 'error' => 'Ponle una etiqueta (p. ej. Campañas)'], 400);
        if ($phoneId === '') return response()->json(['ok' => false, 'error' => 'Falta el ID del número (phone_number_id)'], 400);
        if (!in_array($funcion, WhatsAppNumber::FUNCIONES, true)) {
            return response()->json(['ok' => false, 'error' => 'Función no válida'], 400);
        }

        $id  = (int) $request->input('id');
        $cur = $id ? WhatsAppNumber::find($id) : null;
        if ($id && !$cur) return response()->json(['ok' => false, 'error' => 'Número no encontrado'], 404);

        // El phone_number_id es la clave de enrutado: no puede repetirse.
        $dup = WhatsAppNumber::where('phone_number_id', $phoneId)
            ->when($cur, fn ($q) => $q->where('id', '!=', $cur->id))->exists();
        if ($dup) return response()->json(['ok' => false, 'error' => 'Ya hay un número con ese phone_number_id'], 409);

        $entorno = $request->input('entorno') === 'real' ? 'real' : 'prueba';
        $data = [
            'label'           => mb_substr($label, 0, 60),
            'phone_number_id' => $phoneId,
            'funcion'         => $funcion,
            'entorno'         => $entorno,
            'waba_id'         => trim((string) $request->input('waba_id')) ?: null,
            'app_id'          => trim((string) $request->input('app_id')) ?: null,
            'display_number'  => trim((string) $request->input('display_number')) ?: null,
            'active'          => filter_var($request->input('active', true), FILTER_VALIDATE_BOOLEAN),
        ];
        // Token y App Secret: solo se pisan si vienen en la petición (para no borrarlos
        // al guardar sin reescribirlos). Cadena vacía explícita sí los limpia.
        if ($request->has('token'))      $data['token']      = trim((string) $request->input('token')) ?: null;
        if ($request->has('app_secret')) $data['app_secret'] = trim((string) $request->input('app_secret')) ?: null;

        $cur ? $cur->update($data) : WhatsAppNumber::create($data);
        return response()->json(['ok' => true]);
    }

    protected function delete(Request $request)
    {
        $n = WhatsAppNumber::find((int) $request->input('id'));
        if (!$n) return response()->json(['ok' => false, 'error' => 'Número no encontrado'], 404);
        $n->delete();
        return response()->json(['ok' => true]);
    }

    /**
     * «Probar conexión» de UN número: pregunta a Meta por el número usando SU token.
     * Distinto del global: cada número puede estar en una app/token distinta.
     */
    protected function test(Request $request)
    {
        $n = WhatsAppNumber::find((int) $request->input('id'));
        if (!$n) return response()->json(['ok' => false, 'error' => 'Número no encontrado'], 404);
        if (!$n->token || !$n->phone_number_id) {
            return response()->json(['ok' => false, 'error' => 'Faltan token o phone_number_id']);
        }

        $version = config('whatsapp.graph_version');
        try {
            $resp = Http::withToken($n->token)->timeout(20)
                ->get("https://graph.facebook.com/{$version}/{$n->phone_number_id}", [
                    'fields' => 'verified_name,display_phone_number,quality_rating',
                ]);
            $res = (array) ($resp->json() ?? []);
            if ($resp->successful() && !empty($res['display_phone_number'])) {
                // Se guarda el número legible para mostrarlo en la lista.
                $n->update(['display_number' => $res['display_phone_number']]);
                return response()->json(['ok' => true, 'info' => $res]);
            }
            return response()->json(['ok' => false, 'error' => $res['error']['message'] ?? 'No se pudo conectar']);
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'error' => 'Error de conexión: ' . $e->getMessage()]);
        }
    }
}
