<?php

namespace App\Http\Controllers;

use App\Models\Warranty;
use App\Services\WarrantySurvey;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Página pública de la encuesta de la garantía, por URL FIRMADA (sin login).
 * Solo el POST (un clic de la persona) guarda; el ?score del GET solo marca la nota.
 */
class WarrantyRatingController extends Controller
{
    public function page(Request $request, int $warranty)
    {
        $w = Warranty::find($warranty);
        if (! $w) abort(404);
        $marcada = (($h = (int) $request->query('score')) >= 1 && $h <= 5) ? $h : 0;

        $aviso = null;
        $comentado = false;
        if ($request->isMethod('post')) {
            $score = (($s = (int) $request->input('score')) >= 1 && $s <= 5) ? $s : null;
            $comment = $request->has('comment') ? (string) $request->input('comment', '') : null;
            if ($score !== null || $comment !== null) [, $aviso] = WarrantySurvey::save($w->id, $score, $comment);
            $comentado = $comment !== null;
        }

        $r = DB::table('warranty_ratings')->where('warranty_id', $w->id)->first(['score', 'comment']);
        $nota = (int) ($r->score ?? 0);
        return response(WarrantySurvey::page($w, $nota, $nota ?: $marcada, (string) ($r->comment ?? ''), $comentado, $aviso))
            ->header('Content-Type', 'text/html; charset=utf-8')->header('X-Robots-Tag', 'noindex');
    }
}
