<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Un documento de una garantía para el cliente: presupuesto o albarán (generado aquí
 * con sus líneas o subido de fuera), informe de resolución, factura u otro. Los
 * generados van separados por quién paga: garantía, cliente o pilas.
 */
class WarrantyDocument extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'vat'   => 'float',
        'total' => 'float',
    ];

    // [etiqueta, prefijo del número si se genera aquí, estados que admite]
    public const KINDS = [
        'presupuesto' => ['label' => 'Presupuesto', 'prefix' => 'P', 'statuses' => ['borrador', 'enviado', 'aceptado', 'rechazado', 'anulado']],
        'albaran'     => ['label' => 'Albarán', 'prefix' => 'A', 'statuses' => ['borrador', 'entregado', 'anulado']],
        'informe'     => ['label' => 'Informe de resolución', 'prefix' => 'I', 'statuses' => []],
        'factura'     => ['label' => 'Factura', 'prefix' => null, 'statuses' => []],
        'otro'        => ['label' => 'Otro documento', 'prefix' => null, 'statuses' => []],
    ];

    public const STATUSES = [
        'borrador'  => 'Borrador',
        'enviado'   => 'Enviado',
        'aceptado'  => 'Aceptado',
        'rechazado' => 'Rechazado',
        'entregado' => 'Entregado',
        'anulado'   => 'Anulado',
    ];

    public const GENERATED = ['presupuesto', 'albaran'];   // los que se hacen aquí con líneas

    /** Siguiente número del año: P-2026-0001. */
    public static function nextNumber(string $kind): string
    {
        $base = self::KINDS[$kind]['prefix'] . '-' . date('Y') . '-';
        $ultimo = self::where('number', 'like', $base . '%')->where('source', 'generado')->orderByDesc('number')->value('number');
        $n = $ultimo ? (int) substr($ultimo, strlen($base)) + 1 : 1;
        return $base . str_pad((string) $n, 4, '0', STR_PAD_LEFT);
    }

    /** Ajuste de los documentos (company, documents, resolution_email). */
    public static function setting(string $key): array
    {
        $v = DB::table('warranty_settings')->where('key', $key)->value('value');
        return $v ? (json_decode($v, true) ?: []) : [];
    }

    public function charges(): bool
    {
        return $this->coverage !== 'garantia';
    }
}
