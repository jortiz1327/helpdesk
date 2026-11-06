<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Remesa a ZKONG (R-AAAA-NNN): etiquetas de varias garantías que se mandan juntas en un
 * RMA. En preparación (se le quitan y ponen etiquetas) → Enviada (con transportista y
 * tracking, apuntado a mano) → Recibida (ZKONG confirma que le ha llegado) → Respondida
 * (todas con respuesta) → Cerrada. Una enviada se puede anular con motivo.
 */
class ZkongBatch extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'sent_at'      => 'datetime',
        'received_at'  => 'datetime',
        'closed_at'    => 'datetime',
        'cancelled_at' => 'datetime',
        'returned_at'  => 'datetime',
    ];

    public const STATUSES = [
        'borrador'   => 'En preparación',
        'enviada'    => 'Enviada',
        'recibida'   => 'Recibida por ZKONG',
        'respondida' => 'Respondida',
        'cerrada'    => 'Cerrada',
        'anulada'    => 'Anulada',
    ];

    /** Estados en los que ZKONG ya la tiene o está en camino (se pueden apuntar respuestas). */
    public const EN_ZKONG = ['enviada', 'recibida', 'respondida'];

    /** Apunta un paso en la línea de tiempo de la remesa. */
    public function evento(string $type, string $text, ?int $userId, ?string $note = null, $at = null): void
    {
        DB::table('zkong_batch_events')->insert([
            'zkong_batch_id' => $this->id, 'user_id' => $userId, 'type' => $type, 'text' => mb_substr($text, 0, 300),
            'note' => $note !== null && trim($note) !== '' ? trim($note) : null, 'at' => $at ?? now(), 'created_at' => now(),
        ]);
    }

    /** Estado que le toca tras una respuesta o al reabrir: según tenga respuestas y recepción. */
    public function estadoAbierto(): string
    {
        $faltan = DB::table('warranty_items')->where('zkong_batch_id', $this->id)->whereNull('zkong_result')->exists();
        return ! $faltan ? 'respondida' : ($this->received_at ? 'recibida' : 'enviada');
    }

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
