<?php

namespace App\Http\Controllers;

use App\Services\WarrantyHelp;
use Illuminate\Http\Request;

/**
 * warranty_help.php — Centro de ayuda de garantías. Leer: quien entra a garantías;
 * cambiar, añadir, devolver al texto original y quitar artículos: quien tiene la configuración.
 */
class WarrantyHelpController extends Controller
{
    public function handle(Request $request)
    {
        $action = (string) $request->query('action', 'list');
        $gestiona = (bool) $request->user()?->can('warranties.config');
        if (in_array($action, ['save', 'remove'], true) && ! $gestiona) {
            return response()->json(['ok' => false, 'error' => 'No tienes permiso para cambiar el centro de ayuda'], 403);
        }
        return match ($action) {
            'save'   => $this->save($request),
            'remove' => $this->remove($request),
            default  => response()->json(['ok' => true, 'articles' => WarrantyHelp::all($gestiona),
                'sections' => WarrantyHelp::SECTIONS, 'manage' => $gestiona]),
        };
    }

    private function save(Request $request)
    {
        [$key, $error] = WarrantyHelp::save($request->all(), $request->user()->id);
        return $error ? response()->json(['ok' => false, 'error' => $error], 400)
            : response()->json(['ok' => true, 'key' => $key]);
    }

    private function remove(Request $request)
    {
        $error = WarrantyHelp::remove((string) $request->input('key'));
        return $error ? response()->json(['ok' => false, 'error' => $error], 404) : response()->json(['ok' => true]);
    }
}
