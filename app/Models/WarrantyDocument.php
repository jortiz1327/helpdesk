<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * El informe de resolución de una garantía (PDF guardado). Presupuestos, pedidos y
 * albaranes viven en el portal AEME, no aquí.
 */
class WarrantyDocument extends Model
{
    protected $guarded = ['id'];

    public const KINDS = [
        'informe' => ['label' => 'Informe de resolución', 'prefix' => 'I'],
    ];

    /** Siguiente número del año: P-2026-0001. */
    public static function nextNumber(string $kind): string
    {
        $base = self::KINDS[$kind]['prefix'] . '-' . date('Y') . '-';
        $ultimo = self::where('number', 'like', $base . '%')->where('source', 'generado')->orderByDesc('number')->value('number');
        $n = $ultimo ? (int) substr($ultimo, strlen($base)) + 1 : 1;
        return $base . str_pad((string) $n, 4, '0', STR_PAD_LEFT);
    }

    /** Ajuste de los documentos (company, resolution_email). */
    public static function setting(string $key): array
    {
        $v = DB::table('warranty_settings')->where('key', $key)->value('value');
        return $v ? (json_decode($v, true) ?: []) : [];
    }
}
