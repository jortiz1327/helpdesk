<?php

namespace App\Http\Controllers;

use App\Models\Faq;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Preguntas frecuentes del portal, configurables. Vive en «Configuración de
 * soporte» → pestaña «Preguntas frecuentes». Requiere support.config.
 */
class FaqsController extends Controller
{
    public function handle(Request $request)
    {
        return match ($request->query('action', 'list')) {
            'save'    => $this->save($request),
            'delete'  => $this->delete($request),
            'reorder' => $this->reorder($request),
            default   => $this->list(),
        };
    }

    /** Secciones válidas de la base de conocimiento. */
    protected const SECCIONES = ['faq', 'info'];

    protected function list()
    {
        // Todas (también borradores) para el agente, agrupadas por sección y en su
        // orden, con el nombre de la categoría vinculada para pintarlo sin otro viaje.
        $rows = Faq::orderBy('section')->orderBy('position')->orderBy('id')->get();
        $cats = DB::table('ticket_categories')->pluck('name', 'id');
        foreach ($rows as $r) $r->category_name = $r->category_id ? ($cats[$r->category_id] ?? null) : null;

        return response()->json(['faqs' => $rows]);
    }

    protected function save(Request $request)
    {
        $question = trim((string) $request->input('question'));
        $answer   = trim((string) $request->input('answer'));
        if ($question === '') return response()->json(['ok' => false, 'error' => 'La pregunta es obligatoria'], 400);
        if ($answer === '')   return response()->json(['ok' => false, 'error' => 'La respuesta es obligatoria'], 400);

        $id  = (int) $request->input('id');
        $cur = $id ? Faq::find($id) : null;
        if ($id && !$cur) return response()->json(['ok' => false, 'error' => 'Artículo no encontrado'], 404);

        // Sección: la del artículo existente (no se cambia al editar) o la del alta.
        $section = $cur ? $cur->section : (string) $request->input('section', 'faq');
        if (!in_array($section, self::SECCIONES, true)) $section = 'faq';
        $esInfo = $section === 'info';

        // La categoría vinculada tiene que existir (o ninguna). Solo aplica a FAQ.
        $categoryId = $request->input('category_id');
        $categoryId = ($categoryId === null || $categoryId === '' || (int) $categoryId === 0) ? null : (int) $categoryId;
        if ($categoryId && !DB::table('ticket_categories')->where('id', $categoryId)->exists()) {
            return response()->json(['ok' => false, 'error' => 'La categoría vinculada no existe'], 400);
        }

        $data = [
            'section'     => $section,
            'question'    => mb_substr($question, 0, 200),
            'answer'      => $answer,
            // Las fichas de info no llevan pista, palabras clave ni categoría.
            'hint'        => $esInfo ? null : (mb_substr(trim((string) $request->input('hint', '')), 0, 255) ?: null),
            'keywords'    => $esInfo ? null : $this->normalizarClaves($request->input('keywords', '')),
            'category_id' => $esInfo ? null : $categoryId,
            'active'      => filter_var($request->input('active', true), FILTER_VALIDATE_BOOLEAN),
        ];

        if ($cur) {
            $cur->update($data);
        } else {
            // Nueva: al final de SU sección (cada sección tiene su propio orden).
            $data['position'] = (int) (Faq::where('section', $section)->max('position') ?? 0) + 1;
            Faq::create($data);
        }

        return response()->json(['ok' => true]);
    }

    protected function delete(Request $request)
    {
        $f = Faq::find((int) $request->input('id'));
        if (!$f) return response()->json(['ok' => false, 'error' => 'FAQ no encontrada'], 404);
        $f->delete();
        return response()->json(['ok' => true]);
    }

    /** Reordenar: recibe la lista de ids en el orden deseado. */
    protected function reorder(Request $request)
    {
        $ids = $request->input('ids', []);
        if (!is_array($ids)) return response()->json(['ok' => false, 'error' => 'Orden no válido'], 400);

        DB::transaction(function () use ($ids) {
            foreach (array_values($ids) as $i => $id) {
                Faq::where('id', (int) $id)->update(['position' => $i + 1]);
            }
        });

        return response()->json(['ok' => true]);
    }

    /**
     * Deja las palabras clave limpias: separadas por coma, sin espacios sobrantes,
     * sin duplicados, en minúsculas (la búsqueda del portal es case-insensitive).
     */
    protected function normalizarClaves($raw): ?string
    {
        $partes = array_filter(array_map('trim', explode(',', (string) $raw)), fn ($p) => $p !== '');
        $partes = array_map(fn ($p) => mb_strtolower($p), $partes);
        $partes = array_values(array_unique($partes));
        return $partes ? mb_substr(implode(', ', $partes), 0, 500) : null;
    }
}
