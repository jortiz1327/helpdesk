<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * support_settings.php — Configuración de Soporte.
 * Dos apartados: CATEGORÍAS y RESPUESTAS PREDEFINIDAS.
 * (Departamentos y Automatización: fuera de alcance por ahora.)
 *
 * Gestionar exige support.config (superadmin / encargado). Ver routes/api.php.
 */
class SupportSettingsController extends Controller
{
    public function handle(Request $request)
    {
        return match ($request->query('section', 'categories')) {
            'categories' => $this->categories($request),
            'canned'     => $this->canned($request),
            default      => response()->json(['ok' => false, 'error' => 'Sección no válida'], 400),
        };
    }

    // ---------------- Categorías ----------------

    protected function categories(Request $request)
    {
        if ($request->isMethod('get')) {
            return response()->json([
                'ok'         => true,
                'categories' => DB::table('ticket_categories')->orderBy('position')->orderBy('id')->get(),
            ]);
        }

        if ($request->isMethod('delete')) {
            $id = (int) $request->query('id');
            $used = DB::table('tickets')->where('category_id', $id)->count();
            if ($used > 0) {
                return response()->json(['ok' => false, 'error' => "No se puede eliminar: hay $used ticket(s) en esta categoría"], 400);
            }
            DB::table('ticket_categories')->where('id', $id)->delete();
            return response()->json(['ok' => true]);
        }

        // POST (crear / editar)
        $id   = (int) $request->input('id');
        $name = trim((string) $request->input('name'));
        if ($name === '') return response()->json(['ok' => false, 'error' => 'El nombre es obligatorio'], 400);

        // Los dos plazos del SLA, en horas LABORABLES. Vacío = esa categoría no tiene
        // ese compromiso (no se inventa uno).
        $horas = fn ($v) => $v === '' || $v === null ? null : max(1, min(9999, (int) $v));

        $data = [
            'name'        => mb_substr($name, 0, 80),
            'description' => mb_substr(trim((string) $request->input('description')), 0, 200) ?: null,
            'color'       => preg_match('/^#[0-9a-f]{6}$/i', (string) $request->input('color')) ? $request->input('color') : '#64748b',
            'sla_response_hours' => $horas($request->input('sla_response_hours')),
            'sla_resolve_hours'  => $horas($request->input('sla_resolve_hours')),
            // ¿Sus tickets nuevos van al agente de guardia? Solo el soporte que rota.
            'use_shift'   => $request->boolean('use_shift'),
            'active'      => $request->boolean('active', true),
        ];

        if ($id) {
            DB::table('ticket_categories')->where('id', $id)->update($data);
            return response()->json(['ok' => true, 'id' => $id]);
        }

        // Clave (slug) estable: se genera al crear y no cambia al renombrar
        $key = Str::slug($name, '_') ?: 'cat';
        $base = $key; $i = 2;
        while (DB::table('ticket_categories')->where('key', $key)->exists()) $key = $base . '_' . $i++;

        $data['key'] = $key;
        $data['position'] = (int) DB::table('ticket_categories')->max('position') + 1;
        $newId = DB::table('ticket_categories')->insertGetId($data);

        return response()->json(['ok' => true, 'id' => $newId]);
    }

    // ---------------- Respuestas predefinidas ----------------

    protected function canned(Request $request)
    {
        if ($request->isMethod('get')) {
            return response()->json([
                'ok'      => true,
                'canned'  => DB::table('canned_responses')->orderBy('position')->orderBy('id')->get(),
            ]);
        }

        if ($request->isMethod('delete')) {
            DB::table('canned_responses')->where('id', (int) $request->query('id'))->delete();
            return response()->json(['ok' => true]);
        }

        // POST (crear / editar)
        $id    = (int) $request->input('id');
        $title = trim((string) $request->input('title'));
        $body  = trim((string) $request->input('body'));
        // El atajo se normaliza: minúsculas, sin espacios ni «/» inicial.
        $short = Str::slug(preg_replace('#^/#', '', (string) $request->input('shortcut')), '');

        if ($title === '') return response()->json(['ok' => false, 'error' => 'El título es obligatorio'], 400);
        if ($body === '')  return response()->json(['ok' => false, 'error' => 'El texto es obligatorio'], 400);
        if ($short === '') return response()->json(['ok' => false, 'error' => 'El atajo es obligatorio'], 400);
        if (DB::table('canned_responses')->where('shortcut', $short)->where('id', '<>', $id)->exists()) {
            // Llaves obligatorias: sin ellas, «/$short» se lee como la variable $short»
            // (PHP admite bytes altos en nombres de variable) y revienta.
            return response()->json(['ok' => false, 'error' => "El atajo «/{$short}» ya existe"], 400);
        }

        $data = [
            'shortcut' => mb_substr($short, 0, 40),
            'title'    => mb_substr($title, 0, 120),
            'body'     => $body,
            'active'   => $request->boolean('active', true),
        ];

        if ($id) {
            DB::table('canned_responses')->where('id', $id)->update($data);
            return response()->json(['ok' => true, 'id' => $id]);
        }

        $data['created_by'] = (int) $request->user()->id;
        $data['position']   = (int) DB::table('canned_responses')->max('position') + 1;
        $newId = DB::table('canned_responses')->insertGetId($data);

        return response()->json(['ok' => true, 'id' => $newId]);
    }
}
