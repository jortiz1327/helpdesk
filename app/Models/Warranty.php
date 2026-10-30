<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Expediente de garantía (G-AAAA-NNNN). El seguimiento son notas del equipo
 * (warranty_notes) y el historial, cada cambio de campo (warranty_events).
 */
class Warranty extends Model
{
    protected $table = 'warranties';

    protected $guarded = ['id'];

    protected $casts = [
        'received_at'      => 'date',
        'closed_at'        => 'datetime',
        'last_activity_at' => 'datetime',
    ];

    // Estados en el orden en que avanza un expediente.
    public const STATUSES = [
        'no_iniciado' => 'No iniciado',
        'en_curso'    => 'En curso',
        'pte_cliente' => 'Pte. cliente',
        'pte_zkong'   => 'Pte. ZKONG',
        'pte_envio'   => 'Pte. envío',
        'enviada'     => 'Enviada',
        'cerrada'     => 'Cerrada',
    ];

    public const URGENCIES = [
        'baja'        => 'Baja',
        'media'       => 'Media',
        'alta'        => 'Alta',
        'muy_urgente' => 'Muy urgente',
    ];

    public const NOTE_TYPES = [
        'nota'      => 'Nota',
        'llamada'   => 'Llamada',
        'correo'    => 'Correo',
        'cliente'   => 'Cliente',
        'revision'  => 'Revisión',
        'zkong'     => 'ZKONG',
        'envio'     => 'Envío',
        'documento' => 'Documento',
    ];

    // Los campos que guardan historial al cambiar, con su nombre para la pantalla.
    public const TRACKED = [
        'received_at'    => 'Fecha de recepción',
        'client_name'    => 'Cliente',
        'project_name'   => 'Proyecto',
        'category'       => 'Categoría',
        'urgency'        => 'Urgencia',
        'status'         => 'Estado',
        'responsible_id' => 'Responsable',
        'ticket_id'      => 'Ticket',
        'description'    => 'Descripción',
    ];

    /** Siguiente número del año: G-2026-0001, G-2026-0002… */
    public static function nextCode(?int $year = null): string
    {
        $year ??= (int) now()->year;
        $prefix = "G-{$year}-";
        $last = DB::table('warranties')->where('code', 'like', $prefix . '%')->lockForUpdate()
            ->pluck('code')
            ->map(fn ($c) => (int) substr($c, strlen($prefix)))
            ->max() ?? 0;

        return $prefix . str_pad((string) ($last + 1), 4, '0', STR_PAD_LEFT);
    }

    public function isOpen(): bool
    {
        return $this->status !== 'cerrada';
    }
}
