<?php

namespace App\Services;

use App\Console\Commands\TicketsFollowup;
use App\Models\EmailTemplate;
use App\Models\Setting;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * PRÓXIMOS ENVÍOS A CLIENTES que saldrán solos (sin que nadie pulse nada):
 *
 *   · cierre    — a los N días sin respuesta del cliente (`tickets:followup`).
 *   · programada — respuestas que un agente dejó programadas (`replies:send`).
 *   · autocierre — cierre de resueltos con aviso al cliente (`tickets:autoclose`), si está activo.
 *
 * Solo consulta: no envía ni toca nada. El cierre a los N días lo calcula `cierres()`, que
 * es el MISMO cálculo que usa el comando para enviar: lo que se ve es lo que va a salir.
 * Las horas son aproximadas: el cierre corre cada 15 min y el auto-cierre a las 03:30.
 */
class UpcomingSends
{
    public const TIPOS = [
        'cierre'     => 'Cierre por falta de respuesta',
        'programada' => 'Respuesta programada',
        'autocierre' => 'Cierre de resuelto',
    ];

    /**
     * Tickets que se cerrarán con aviso por falta de respuesta del cliente:
     * [ticket_id => Carbon cuándo toca]. Si ya ha vencido, sale en la próxima pasada.
     */
    public function cierres(?int $ticketId = null): array
    {
        $desde = Setting::get('ticket_followup_since');
        if (!$desde || !EmailTemplate::where('key', 'ticket_followup')->where('active', true)->exists()) return [];
        $soporte = DB::table('ticket_categories')->where('key', TicketsFollowup::CATEGORIA)->value('id');
        if (!$soporte) return [];
        $dias = max(1, (int) Setting::get('ticket_followup_days', '8'));

        $ids = DB::table('tickets as t')->join('contacts as c', 'c.id', '=', 't.contact_id')
            ->where('t.status', 'abierto')
            ->where('t.category_id', $soporte)
            ->whereIn('t.channel', ['email', 'web'])
            ->where('t.last_direction', 'out')
            ->whereNull('t.snoozed_at')->whereNull('t.merged_into_id')
            ->where('t.created_at', '>=', $desde)
            ->whereNotNull('c.email')->where('c.email', '<>', '')
            ->when($ticketId, fn ($q) => $q->where('t.id', $ticketId))
            ->pluck('t.id');
        if ($ids->isEmpty()) return [];

        // Lo último que vio el cliente en cada ticket (las notas internas no cuentan).
        $ultimos = DB::table('messages')->whereIn('id', function ($s) use ($ids) {
            $s->from('messages')->whereIn('ticket_id', $ids)->where('is_internal_note', 0)
                ->groupBy('ticket_id')->selectRaw('MAX(id)');
        })->get(['ticket_id', 'direction', 'created_at', 'payload']);

        $out = [];
        foreach ($ultimos as $m) {
            if ($m->direction !== 'out') continue;
            // Si lo último ya es el aviso automático, el cliente ya lo recibió: no se repite.
            if ((json_decode((string) $m->payload, true)['auto'] ?? null) === TicketsFollowup::MARCA) continue;
            $out[(int) $m->ticket_id] = Carbon::parse($m->created_at)->addDays($dias);
        }
        return $out;
    }

    /**
     * Todos los envíos de los próximos $horas, del más cercano al más lejano. Cada uno:
     * tipo, etiqueta, ticket (id, código, asunto), cliente, agente, cuándo e «inmediato»
     * (ya ha vencido: sale en la próxima pasada).
     */
    public function lista(int $horas = 168): array
    {
        $hasta = now()->addHours($horas);
        $items = [];

        foreach ($this->cierres() as $id => $cuando) {
            if ($cuando->lte($hasta)) $items[] = ['tipo' => 'cierre', 'ticket_id' => $id, 'at' => $cuando];
        }

        $prog = DB::table('scheduled_replies as s')->leftJoin('users as u', 'u.id', '=', 's.user_id')
            ->where('s.status', 'pending')->where('s.send_at', '<=', $hasta)
            ->get(['s.ticket_id', 's.send_at', 'u.name as autor']);
        foreach ($prog as $p) {
            $items[] = ['tipo' => 'programada', 'ticket_id' => (int) $p->ticket_id, 'at' => Carbon::parse($p->send_at), 'por' => $p->autor];
        }

        foreach ($this->autocierres($hasta) as $id => $cuando) {
            $items[] = ['tipo' => 'autocierre', 'ticket_id' => $id, 'at' => $cuando];
        }

        return $this->completar($items);
    }

    /** Envíos AUTOMÁTICOS pendientes de un ticket (para la línea de su ficha). */
    public function deTicket(int $id): array
    {
        $items = [];
        if ($c = $this->cierres($id)[$id] ?? null) $items[] = ['tipo' => 'cierre', 'ticket_id' => $id, 'at' => $c];
        if ($a = $this->autocierres(null, $id)[$id] ?? null) $items[] = ['tipo' => 'autocierre', 'ticket_id' => $id, 'at' => $a];

        return array_map(fn ($i) => [
            'tipo' => $i['tipo'], 'label' => self::TIPOS[$i['tipo']],
            'at' => $i['at']->format('Y-m-d H:i:s'), 'inmediato' => $i['at']->isPast(),
        ], $items);
    }

    /**
     * Resueltos que el auto-cierre cerrará AVISANDO al cliente (solo si está activo, con
     * aviso y con la plantilla «Ticket cerrado» encendida). Sale a las 03:30 del día en
     * que se cumple el plazo (o del siguiente, si ya pasó esa hora).
     */
    protected function autocierres(?Carbon $hasta = null, ?int $ticketId = null): array
    {
        $dias = (int) Setting::get('ticket_autoclose_days', '0');
        if ($dias <= 0 || (string) Setting::get('ticket_autoclose_notify', '0') !== '1') return [];
        if (!EmailTemplate::where('key', 'ticket_closed')->where('active', true)->exists()) return [];

        $filas = DB::table('tickets as t')->join('contacts as c', 'c.id', '=', 't.contact_id')
            ->where('t.status', 'resuelto')->where('t.channel', '<>', 'whatsapp')
            ->whereNotNull('c.email')->where('c.email', '<>', '')
            ->when($ticketId, fn ($q) => $q->where('t.id', $ticketId))
            ->when($hasta, fn ($q) => $q->whereRaw('COALESCE(t.last_message_at, t.resolved_at, t.opened_at) <= ?', [$hasta->copy()->subDays($dias)]))
            ->selectRaw('t.id, COALESCE(t.last_message_at, t.resolved_at, t.opened_at) AS base')->get();

        $out = [];
        foreach ($filas as $f) {
            $plazo = Carbon::parse($f->base)->addDays($dias);
            $pasada = $plazo->copy()->setTime(3, 30);
            if ($pasada->lte($plazo)) $pasada->addDay();
            if ($pasada->isPast()) $pasada = now()->copy()->setTime(3, 30)->addDay();
            if (!$hasta || $pasada->lte($hasta)) $out[(int) $f->id] = $pasada;
        }
        return $out;
    }

    /** Añade a cada envío los datos del ticket (una consulta) y los ordena por fecha. */
    protected function completar(array $items): array
    {
        if (!$items) return [];
        $tk = DB::table('tickets as t')->leftJoin('contacts as c', 'c.id', '=', 't.contact_id')
            ->leftJoin('users as u', 'u.id', '=', 't.assigned_to')
            ->whereIn('t.id', array_unique(array_column($items, 'ticket_id')))
            ->get(['t.id', 't.code', 't.subject', 'c.name as contact_name', 'c.email as contact_email', 'u.name as agent_name'])
            ->keyBy('id');

        usort($items, fn ($a, $b) => $a['at'] <=> $b['at']);

        $out = [];
        foreach ($items as $i) {
            $t = $tk[$i['ticket_id']] ?? null;
            if (!$t) continue;
            $out[] = [
                'tipo'      => $i['tipo'],
                'label'     => self::TIPOS[$i['tipo']],
                'at'        => $i['at']->format('Y-m-d H:i:s'),
                'inmediato' => $i['at']->isPast(),
                'ticket_id' => (int) $t->id,
                'code'      => $t->code,
                'subject'   => $t->subject,
                'cliente'   => $t->contact_name ?: $t->contact_email,
                'email'     => $t->contact_email,
                'agente'    => $t->agent_name,
                'por'       => $i['por'] ?? null,   // quién la programó
            ];
        }
        return $out;
    }
}
