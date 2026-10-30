<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Remesa a ZKONG (R-AAAA-NNN): etiquetas de varias garantías que se mandan juntas en un
 * RMA. Borrador (se le quitan y ponen etiquetas) → Enviada → Respondida (cuando todas
 * tienen respuesta de ZKONG) → Cerrada.
 */
class ZkongBatch extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'sent_at'   => 'datetime',
        'closed_at' => 'datetime',
    ];

    public const STATUSES = [
        'borrador'   => 'Borrador',
        'enviada'    => 'Enviada',
        'respondida' => 'Respondida',
        'cerrada'    => 'Cerrada',
    ];

    // Lo que contesta ZKONG por cada etiqueta.
    public const RESULTS = [
        'reposicion' => 'Reposición',
        'abono'      => 'Abono',
        'rechazada'  => 'Rechazada',
    ];

    /** Siguiente número del año: R-2026-001, R-2026-002… */
    public static function nextCode(?int $year = null): string
    {
        $year ??= (int) now()->year;
        $prefix = "R-{$year}-";
        $last = DB::table('zkong_batches')->where('code', 'like', $prefix . '%')->lockForUpdate()
            ->pluck('code')
            ->map(fn ($c) => (int) substr($c, strlen($prefix)))
            ->max() ?? 0;
        return $prefix . str_pad((string) ($last + 1), 3, '0', STR_PAD_LEFT);
    }
}
