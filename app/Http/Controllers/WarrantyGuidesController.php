<?php

namespace App\Http\Controllers;

use App\Models\Warranty;
use App\Services\WarrantyGuides;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * warranty_guides.php — Protocolos y guías de garantías. Consultar y descargar: quien entra
 * a garantías; subir, cambiar y quitar documentos: quien tiene la configuración.
 */
class WarrantyGuidesController extends Controller
{
    public function handle(Request $request)
    {
        $action = (string) $request->query('action', 'list');
        $gestiona = (bool) $request->user()?->can('warranties.config');
        if (in_array($action, ['save', 'delete', 'order'], true) && ! $gestiona) {
            return response()->json(['ok' => false, 'error' => 'No tienes permiso para gestionar la documentación'], 403);
        }
        return match ($action) {
            'for_warranty' => $this->paraGarantia($request),
            'save'         => $this->save($request),
            'delete'       => $this->delete($request),
            'order'        => $this->order($request),
            default        => response()->json(['ok' => true, 'guides' => WarrantyGuides::all($gestiona), 'manage' => $gestiona,
                'kinds' => WarrantyGuides::KINDS, 'languages' => WarrantyGuides::LANGUAGES,
                'categories' => DB::table('warranty_categories')->orderBy('sort')->orderBy('name')->pluck('name'),
                'extensions' => WarrantyGuides::EXT, 'max_mb' => WarrantyGuides::MAX_KB / 1024]),
        };
    }

    /** warranty_guide.php?id=&token= — el archivo, en línea (visor) o con ?dl=1 para descargar. */
    public function file(Request $request)
    {
        $g = DB::table('warranty_guides')->find((int) $request->query('id'));
        if (! $g || (! $g->active && ! $request->user()?->can('warranties.config')) || ! Storage::disk('local')->exists($g->path)) abort(404);
        // En línea solo lo que el navegador enseña sin riesgo (PDF e imágenes); lo demás se descarga.
        $enLinea = ! $request->boolean('dl') && in_array($g->mime, ['application/pdf', 'image/png', 'image/jpeg'], true);
        $nombre = str_replace('"', '', $g->file_name);
        return response()->file(Storage::disk('local')->path($g->path), ['Content-Type' => $g->mime,
            'Content-Disposition' => ($enLinea ? 'inline' : 'attachment') . '; filename="' . $nombre . '"; filename*=UTF-8\'\'' . rawurlencode($g->file_name),
            'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, max-age=300']);
    }

    private function paraGarantia(Request $request)
    {
        $w = Warranty::findOrFail((int) $request->query('id'));
        return response()->json(['ok' => true] + WarrantyGuides::forWarranty($w) + ['kinds' => WarrantyGuides::KINDS]);
    }

    private function save(Request $request)
    {
        $id = (int) $request->input('id');
        $antes = $id ? DB::table('warranty_guides')->find($id) : null;
        if ($id && ! $antes) return $this->error('Ese documento ya no existe');
        [$datos, $error] = WarrantyGuides::clean($request->all(), DB::table('warranty_categories')->pluck('name')->all());
        if ($error) return $this->error($error);

        $f = $request->file('file');
        if (! $antes && ! $f) return $this->error('Elige el archivo');
        if ($f) {
            $guardado = WarrantyGuides::store($f);
            if (is_string($guardado)) return $this->error($guardado);
            [$datos['path'], $datos['file_name'], $datos['mime'], $datos['size']] = $guardado;
        }
        $datos += ['updated_by' => $request->user()->id, 'updated_at' => now()];
        if ($antes) {
            DB::table('warranty_guides')->where('id', $id)->update($datos);
            // Al sustituir el archivo, el anterior sobra.
            if ($f && $antes->path !== $datos['path']) Storage::disk('local')->delete($antes->path);
        } else {
            $id = DB::table('warranty_guides')->insertGetId($datos + ['created_at' => now(), 'sort' => (int) DB::table('warranty_guides')->max('sort') + 1]);
        }
        return response()->json(['ok' => true, 'id' => $id]);
    }

    private function delete(Request $request)
    {
        $g = DB::table('warranty_guides')->find((int) $request->input('id'));
        if (! $g) return $this->error('Ese documento ya no existe');
        DB::table('warranty_guides')->where('id', $g->id)->delete();
        Storage::disk('local')->delete($g->path);
        return response()->json(['ok' => true]);
    }

    private function order(Request $request)
    {
        foreach (array_values((array) $request->input('ids', [])) as $i => $id) {
            DB::table('warranty_guides')->where('id', (int) $id)->update(['sort' => $i]);
        }
        return response()->json(['ok' => true]);
    }

    private function error(string $msg)
    {
        return response()->json(['ok' => false, 'error' => $msg], 400);
    }
}
