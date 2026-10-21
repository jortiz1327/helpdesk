<?php

namespace App\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/*
 * DETALLE de las acciones sobre tickets para el registro de acciones.
 *
 * El middleware LogActivity hace una FOTO de los tickets afectados antes de la acción
 * (snapshot) y, al terminar, esta clase compara con cómo han quedado para escribir
 * QUÉ cambió: «Asignó TK-2609-0003 a Robert», «Prioridad: Media → Alta», las etiquetas
 * que se pusieron y quitaron, el texto de la respuesta… En vez del genérico «Editó un
 * ticket #6950», que no decía nada.
 */
class TicketActivity
{
    /** Acciones automáticas o de solo lectura: no son actos del agente, no se registran. */
    public const RUIDO = ['unlock', 'heartbeat', 'messages', 'contact_open', 'mergeable', 'export'];

    /** Ids de ticket a los que afecta la petición (para la foto previa). */
    public static function idsDe(Request $request): array
    {
        $ids = [];
        foreach (['id', 'into', 'from'] as $k) {
            $v = $request->input($k);
            if (is_scalar($v) && (int) $v > 0) $ids[] = (int) $v;
        }
        foreach ((array) $request->input('ids', []) as $v) {
            if (is_scalar($v) && (int) $v > 0) $ids[] = (int) $v;
        }
        return array_values(array_unique(array_slice($ids, 0, 200)));
    }

    /** Foto de los tickets: lo justo para describir el cambio. */
    public static function snapshot(array $ids): array
    {
        if (!$ids) return [];
        $rows = DB::table('tickets as t')
            ->leftJoin('contacts as c', 'c.id', '=', 't.contact_id')
            ->whereIn('t.id', $ids)
            ->get(['t.id', 't.code', 't.subject', 't.status', 't.priority', 't.category_id',
                   't.assigned_to', 't.snoozed_until', 't.contact_id', 'c.name as contact_name', 'c.email as contact_email']);
        $labels = DB::table('ticket_label_ticket')->whereIn('ticket_id', $ids)->get(['ticket_id', 'label_id'])
            ->groupBy('ticket_id')->map(fn ($g) => $g->pluck('label_id')->map(fn ($x) => (int) $x)->sort()->values()->all());

        $out = [];
        foreach ($rows as $r) {
            $a = (array) $r;
            $a['labels'] = $labels[$r->id] ?? [];
            $out[(int) $r->id] = $a;
        }
        return $out;
    }

    /**
     * Describe la acción. Devuelve [frase, referencia, detalles[]] o null si no hay nada
     * que contar (ruido, o una edición que no cambió nada).
     */
    public static function describir(string $action, Request $request, array $antes, array $resp): ?array
    {
        if (in_array($action, self::RUIDO, true)) return null;

        $ids     = array_keys($antes);
        $despues = self::snapshot($ids);
        $uno     = $antes[$ids[0] ?? 0] ?? null;          // la acción típica es sobre UN ticket
        $ahora   = $despues[$ids[0] ?? 0] ?? null;
        $ref     = $uno ? self::ref($uno) : null;
        $tk      = $uno ? $uno['code'] : 'un ticket';

        switch ($action) {
            case 'create':
                $code = (string) ($resp['code'] ?? '');
                $t = isset($resp['id']) ? (self::snapshot([(int) $resp['id']])[(int) $resp['id']] ?? null) : null;
                $det = array_filter([
                    $t && $t['subject'] ? 'Asunto: «' . self::corta($t['subject'], 90) . '»' : null,
                    $t ? 'Cliente: ' . self::cliente($t) : null,
                    $t && $t['assigned_to'] ? 'Asignado a ' . self::agente($t['assigned_to']) : null,
                ]);
                return [$code ? "Creó el ticket $code" : 'Creó un ticket', $t ? self::ref($t) : ($code ?: null), array_values($det)];

            case 'assign':
                if (!$uno || !$ahora) return ['Asignó un ticket', $ref, []];
                $de = $uno['assigned_to']; $a = $ahora['assigned_to'];
                if ((int) $de === (int) $a) return null;
                $yo = (int) $request->user()?->id;
                $frase = !$a ? "Quitó la asignación de $tk"
                    : ((int) $a === $yo ? "Se asignó $tk" : "Asignó $tk a " . self::agente($a));
                return [$frase, $ref, ['Agente: ' . self::agente($de) . ' → ' . self::agente($a)]];

            case 'status':
                if (!$uno || !$ahora) return ['Cambió el estado de un ticket', $ref, []];
                if ($uno['status'] === $ahora['status']) return null;
                return [self::verboEstado($ahora['status']) . " $tk", $ref,
                    ['Estado: ' . self::estado($uno['status']) . ' → ' . self::estado($ahora['status'])]];

            case 'priority':
                if (!$uno || !$ahora || $uno['priority'] === $ahora['priority']) return $uno ? null : ['Cambió la prioridad de un ticket', $ref, []];
                return ["Cambió la prioridad de $tk", $ref,
                    ['Prioridad: ' . self::prioridad($uno['priority']) . ' → ' . self::prioridad($ahora['priority'])]];

            case 'category':
                if (!$uno || !$ahora || (int) $uno['category_id'] === (int) $ahora['category_id']) return $uno ? null : ['Cambió la categoría de un ticket', $ref, []];
                return ["Cambió la categoría de $tk", $ref,
                    ['Categoría: ' . self::categoria($uno['category_id']) . ' → ' . self::categoria($ahora['category_id'])]];

            case 'labels':
                if (!$uno || !$ahora) return ['Cambió las etiquetas de un ticket', $ref, []];
                $puso   = array_diff($ahora['labels'], $uno['labels']);
                $quito  = array_diff($uno['labels'], $ahora['labels']);
                if (!$puso && !$quito) return null;
                return ["Cambió las etiquetas de $tk", $ref, array_values(array_filter([
                    $puso  ? 'Añadió: ' . self::etiquetas($puso) : null,
                    $quito ? 'Quitó: ' . self::etiquetas($quito) : null,
                ]))];

            case 'snooze':
                $hasta = $ahora['snoozed_until'] ?? null;
                return ["Pospuso $tk", $ref, array_values(array_filter([
                    $hasta ? 'Hasta el ' . self::fecha($hasta) : null,
                    trim((string) $request->input('reason')) !== '' ? 'Motivo: «' . self::corta($request->input('reason'), 140) . '»' : null,
                ]))];

            case 'unsnooze':
                return ["Reactivó $tk (estaba pospuesto)", $ref, []];

            case 'set_requester':
                if (!$uno || !$ahora) return ['Cambió el solicitante de un ticket', $ref, []];
                return ["Cambió el solicitante de $tk", $ref, ['Solicitante: ' . self::cliente($uno) . ' → ' . self::cliente($ahora)]];

            case 'reply':
                $prog = !empty($resp['scheduled']);
                $det  = array_filter([
                    ($txt = self::texto($request->input('body'))) !== '' ? '«' . $txt . '»' : null,
                    $prog && !empty($resp['send_at']) ? 'Programada para el ' . self::fecha($resp['send_at']) : null,
                    $uno && $ahora && $uno['status'] !== $ahora['status'] ? 'Estado: ' . self::estado($uno['status']) . ' → ' . self::estado($ahora['status']) : null,
                ]);
                return [($prog ? 'Programó una respuesta en ' : 'Respondió a ') . $tk, $ref, array_values($det)];

            case 'note':
                $txt = self::texto($request->input('body'));
                return ["Añadió una nota interna en $tk", $ref, $txt !== '' ? ['«' . $txt . '»'] : []];

            case 'sched_cancel':
                return ['Canceló una respuesta programada', null, []];

            case 'delete':
                return ["Eliminó el ticket $tk", $ref, $uno ? array_values(array_filter([
                    $uno['subject'] ? 'Asunto: «' . self::corta($uno['subject'], 90) . '»' : null,
                    'Cliente: ' . self::cliente($uno),
                ])) : []];

            case 'merge':
                $into = $antes[(int) $request->input('into')] ?? null;
                $from = $antes[(int) $request->input('from')] ?? null;
                return ['Fusionó ' . ($from['code'] ?? 'un ticket') . ' en ' . ($into['code'] ?? 'otro'),
                    $into ? self::ref($into) : null,
                    trim((string) $request->input('reason')) !== '' ? ['Motivo: «' . self::corta($request->input('reason'), 140) . '»'] : []];

            case 'bulk':
                return self::bulk($request, $antes, $resp);
        }

        return null;   // acción nueva sin descripción: mejor el genérico del middleware
    }

    /** Acciones masivas: qué se hizo y a qué tickets. */
    private static function bulk(Request $request, array $antes, array $resp): array
    {
        $op = (string) $request->input('op');
        $n  = (int) ($resp['affected'] ?? count($antes));
        $codigos = array_column($antes, 'code');
        $lista = 'Tickets: ' . implode(', ', array_slice($codigos, 0, 12)) . (count($codigos) > 12 ? ' y ' . (count($codigos) - 12) . ' más' : '');
        $plural = $n === 1 ? '1 ticket' : "$n tickets";

        [$frase, $extra] = match ($op) {
            'status'   => [self::verboEstado((string) $request->input('status')) . " $plural", null],
            'assign'   => [$request->input('user_id') ? "Asignó $plural a " . self::agente($request->input('user_id')) : "Quitó la asignación de $plural", null],
            'priority' => ["Cambió la prioridad de $plural", 'Prioridad: ' . self::prioridad((string) $request->input('priority'))],
            'category' => ["Cambió la categoría de $plural", 'Categoría: ' . self::categoria($request->input('category_id'))],
            'label'    => [($request->input('mode') === 'remove' ? 'Quitó' : 'Puso') . ' la etiqueta «' . self::etiquetas([(int) $request->input('label_id')]) . "» a $plural", null],
            'restore'  => ["Deshizo el cambio de estado de $plural", null],
            // El principal es el más antiguo (id menor), como en TicketsController::bulk.
            'merge'    => ["Fusionó $plural en " . ($antes ? ($antes[min(array_keys($antes))]['code'] ?? 'uno') : 'uno'),
                           trim((string) $request->input('reason')) !== '' ? 'Motivo: «' . self::corta($request->input('reason'), 140) . '»' : null],
            default    => ["Acción masiva sobre $plural", null],
        };
        return [$frase, null, array_values(array_filter([$extra, $codigos ? $lista : null]))];
    }

    /* ----------------------------- traductores ----------------------------- */

    /** Referencia del chip: código + asunto corto. */
    private static function ref(array $t): string
    {
        return $t['code'] . ($t['subject'] ? ' · ' . self::corta($t['subject'], 60) : '');
    }

    private static function verboEstado(?string $st): string
    {
        return [
            'cerrado'     => 'Cerró',
            'resuelto'    => 'Resolvió',
            'abierto'     => 'Reabrió',
            'planificado' => 'Planificó',
            'nuevo'       => 'Marcó como nuevo',
        ][$st] ?? 'Cambió el estado de';
    }

    private static function estado(?string $st): string
    {
        return TicketService::STATUSES[$st] ?? ($st ?: '—');
    }

    private static function prioridad(?string $p): string
    {
        if (!$p || $p === TicketService::SIN_PRIORIDAD) return 'Sin asignar';
        return TicketService::priorities()[$p] ?? $p;
    }

    private static function categoria($id): string
    {
        static $cache = [];
        if (!$id) return 'Sin categoría';
        return $cache[$id] ??= (string) (DB::table('ticket_categories')->where('id', (int) $id)->value('name') ?? "#$id");
    }

    private static function agente($id): string
    {
        static $cache = [];
        if (!$id) return 'Nadie';
        return $cache[$id] ??= (string) (DB::table('users')->where('id', (int) $id)->value('name') ?? "#$id");
    }

    private static function etiquetas(array $ids): string
    {
        return DB::table('ticket_labels')->whereIn('id', $ids)->orderBy('name')->pluck('name')->implode(', ') ?: '—';
    }

    private static function cliente(array $t): string
    {
        $n = trim((string) ($t['contact_name'] ?? ''));
        $e = trim((string) ($t['contact_email'] ?? ''));
        return $n && $e ? "$n <$e>" : ($n ?: ($e ?: '—'));
    }

    private static function fecha($s): string
    {
        try {
            return \Carbon\Carbon::parse($s)->timezone(config('app.timezone'))->format('d/m H:i');
        } catch (\Throwable) {
            return (string) $s;
        }
    }

    /** HTML de una respuesta → texto plano corto. */
    private static function texto($html): string
    {
        $t = html_entity_decode(strip_tags(str_replace(['<br>', '<br/>', '<br />', '</p>'], ' ', (string) $html)), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return self::corta(trim(preg_replace('/\s+/u', ' ', $t)), 160);
    }

    private static function corta($s, int $max): string
    {
        $s = trim((string) $s);
        return mb_strlen($s) > $max ? rtrim(mb_substr($s, 0, $max - 1)) . '…' : $s;
    }
}
