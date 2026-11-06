<?php

namespace App\Models;

use App\Support\TicketVisibility;
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
    // La clave del enlace de seguimiento del cliente solo sale como enlace (WarrantyTracking).
    protected $hidden = ['track_token'];

    protected $casts = [
        'first_contact_at' => 'date',
        'received_at'      => 'date',
        'installed_at'     => 'date',
        'closed_at'        => 'datetime',
        'last_activity_at' => 'datetime',
        'status_changed_at' => 'datetime',
    ];

    // Estados: se configuran (warranty_statuses). Estos dos no se pueden desactivar:
    // el alta empieza en no_iniciado y «Cerrar garantía» pasa a cerrada.
    public const STATUS_NEW = 'no_iniciado';
    public const STATUS_CLOSED = 'cerrada';
    public const STATUS_REOPEN = 'en_curso';

    private static ?array $estados = null;

    // Avisos al cliente por fase (alta y cambios de estado), vengan de donde vengan.
    protected static function booted(): void
    {
        static::created(fn (self $w) => \App\Services\WarrantyClientNotices::queue($w, true));
        static::updated(fn (self $w) => $w->wasChanged('status') ? \App\Services\WarrantyClientNotices::queue($w, false) : null);
        // Y su producto en el cloud (la etiqueta del almacén), con cualquier cambio.
        static::saved(fn (self $w) => \App\Services\WarrantyCloudItem::queue($w->id));
    }

    /** Todos los estados, en orden: [key => {label, color, closed, active, system}]. */
    public static function statusRows(): array
    {
        return self::$estados ??= DB::table('warranty_statuses')->orderBy('sort')->orderBy('label')->get()
            ->mapWithKeys(fn ($s) => [$s->key => ['label' => $s->label, 'color' => $s->color, 'closed' => (bool) $s->closed,
                'active' => (bool) $s->active, 'system' => (bool) $s->system]])->all();
    }

    /** [key => nombre] de todos los estados (también los inactivos, para leer los antiguos). */
    public static function statuses(): array
    {
        return array_map(fn ($s) => $s['label'], self::statusRows());
    }

    /** Estados que cuentan como cerrada. */
    public static function closedStatuses(): array
    {
        return array_keys(array_filter(self::statusRows(), fn ($s) => $s['closed']));
    }

    public static function forgetStatuses(): void
    {
        self::$estados = null;
    }

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
        'first_contact_at' => 'Primera fecha de contacto',
        'received_at'    => 'Fecha de recepción',
        'installed_at'   => 'Fecha de instalación',
        'closed_at'      => 'Fecha de cierre',
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
        return ! in_array($this->status, self::closedStatuses(), true);
    }

    /**
     * ¿Puede este usuario ver un ticket del Helpdesk? Lo que la garantía saca del ticket
     * (asunto, correo del cliente, imágenes) sigue las mismas reglas que el Helpdesk: hace
     * falta helpdesk.access y que el ticket entre en lo que ve (sus categorías, etc.).
     */
    public static function canSeeTicket(?User $user, ?int $ticketId): bool
    {
        return $user && $ticketId && $user->can('helpdesk.access') && TicketVisibility::puedeVer($user, $ticketId);
    }

    public function ticketVisibleTo(?User $user): bool
    {
        return self::canSeeTicket($user, $this->ticket_id);
    }
}
