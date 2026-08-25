<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * DIAGNÓSTICO de índices: pasa un EXPLAIN por las consultas más pesadas (bandeja,
 * contadores, informes) y lo traduce a lenguaje llano — ¿usa índice o escanea la
 * tabla entera?, ¿cuántas filas mira?, ¿ordena en memoria (filesort)?
 *
 * Sirve para decidir CON DATOS si merece la pena añadir un índice, en vez de meterlo
 * a ciegas. Con la tabla pequeña casi todo escanea y no pasa nada; el veredicto tiene
 * sentido con volumen (los 50k de prueba, o producción).
 */
class DbExplain extends Command
{
    protected $signature = 'db:explain {--user= : id del agente a simular (por defecto, uno con categorías)}';

    protected $description = 'EXPLAIN de las consultas clave de tickets: ¿usan índice o escanean la tabla?';

    public function handle(): int
    {
        $total = DB::table('tickets')->count();
        $this->info("Tabla `tickets`: " . number_format($total) . " filas.");
        if ($total < 5000) {
            $this->warn('Con pocas filas MySQL suele preferir escanear (es más rápido que el índice). '
                . 'Para un veredicto fiable, ejecútalo con volumen (los 50k de prueba o producción).');
        }

        // Un agente realista: preferimos uno que tenga categorías asignadas (define su «área»).
        $uid = (int) $this->option('user');
        if (!$uid) {
            $uid = (int) (DB::table('user_ticket_categories')->value('user_id')
                ?? DB::table('users')->orderBy('id')->value('id'));
        }
        $user = User::find($uid);
        if (!$user) { $this->error('No hay usuarios.'); return self::FAILURE; }

        $cats = $user->categoryIds();
        $catList = $cats ? implode(',', $cats) : '0';
        $me = (int) $user->id;
        $desde = now()->subDays(30)->toDateTimeString();
        $abiertos = "'nuevo','abierto','en_progreso','esperando_respuesta'";
        $despierto = '(t.snoozed_at IS NULL OR ((t.snoozed_until IS NULL OR t.snoozed_until <= NOW()) AND t.snooze_wake_on_reply = 0))';

        $this->line("Agente simulado: <info>{$user->name}</info> (id {$me}, "
            . ($cats ? count($cats) . ' categoría(s): ' . $catList : 'sin categorías') . ")\n");

        $consultas = [
            'Bandeja (alcance del agente, abiertos, despiertos, ordenada)' => [
                "SELECT t.id FROM tickets t
                 WHERE t.channel <> 'cron'
                   AND (t.category_id IN ($catList) OR t.assigned_to = $me OR t.status = 'cerrado')
                   AND t.status IN ($abiertos) AND $despierto
                 ORDER BY t.last_message_at DESC, t.id DESC LIMIT 25", []],

            'Cola «Sin responder» (habló el cliente, ordenada)' => [
                "SELECT t.id FROM tickets t
                 WHERE t.channel <> 'cron' AND t.last_direction = 'in'
                   AND t.status IN ($abiertos) AND $despierto
                 ORDER BY t.last_message_at DESC, t.id DESC LIMIT 25", []],

            'Vista «Pospuestos» (solo los dormidos)' => [
                "SELECT t.id FROM tickets t
                 WHERE t.snoozed_at IS NOT NULL AND NOT $despierto
                 ORDER BY t.last_message_at DESC LIMIT 25", []],

            'Filtro «SLA vencido» (no en pausa, plazo pasado)' => [
                "SELECT t.id FROM tickets t
                 WHERE t.status IN ($abiertos) AND t.sla_paused_since IS NULL
                   AND (t.sla_resolve_due_at < NOW()
                        OR (t.sla_response_due_at < NOW() AND t.first_response_at IS NULL))
                 LIMIT 25", []],

            'Cron sla:check (barrido de por-vencer/vencido)' => [
                "SELECT t.id FROM tickets t
                 WHERE t.status IN ('nuevo','abierto','en_progreso') AND t.channel <> 'cron'
                   AND (t.sla_response_due_at IS NOT NULL OR t.sla_resolve_due_at IS NOT NULL)
                 LIMIT 500", []],

            'Contador «míos» (asignados a mí y abiertos)' => [
                "SELECT COUNT(*) FROM tickets t
                 WHERE t.channel <> 'cron' AND t.assigned_to = $me AND t.status IN ($abiertos) AND $despierto", []],

            'Informes · desglose por AGENTE (últimos 30 días)' => [
                "SELECT t.assigned_to, COUNT(*) n FROM tickets t
                 WHERE t.created_at >= ? GROUP BY t.assigned_to", [$desde]],

            'Informes · desglose por CATEGORÍA (últimos 30 días)' => [
                "SELECT t.category_id, COUNT(*) n FROM tickets t
                 WHERE t.created_at >= ? GROUP BY t.category_id", [$desde]],

            'Informes · serie diaria de CREADOS' => [
                "SELECT DATE(t.created_at) d, COUNT(*) n FROM tickets t
                 WHERE t.created_at >= ? GROUP BY d", [$desde]],

            'Informes · serie diaria de RESUELTOS' => [
                "SELECT DATE(t.resolved_at) d, COUNT(*) n FROM tickets t
                 WHERE t.resolved_at IS NOT NULL AND t.resolved_at >= ? GROUP BY d", [$desde]],
        ];

        $filas = [];
        foreach ($consultas as $nombre => [$sql, $bind]) {
            $ex = DB::select('EXPLAIN ' . $sql, $bind);
            // Con joins/subconsultas EXPLAIN trae varias líneas; nos quedamos con la de `tickets`.
            $r = collect($ex)->firstWhere('table', 't') ?? $ex[0];

            $key   = $r->key ?? null;
            $rows  = (int) ($r->rows ?? 0);
            $type  = $r->type ?? '';
            $extra = $r->Extra ?? '';

            $veredicto = $this->veredicto($key, $type, $rows, $extra, $total);
            $filas[] = [
                mb_strimwidth($nombre, 0, 44, '…'),
                $key ?: '— (ninguno)',
                number_format($rows),
                $this->banderas($extra),
                $veredicto,
            ];
        }

        $this->table(['Consulta', 'Índice usado', 'Filas', 'Notas', 'Veredicto'], $filas);
        $this->line("\nLeyenda: «Filas» = cuántas estima MySQL recorrer. Si se acerca al total de la tabla "
            . "y no usa índice, es un escaneo completo. «filesort» = ordena en memoria (sin índice de orden).");
        return self::SUCCESS;
    }

    private function banderas(string $extra): string
    {
        $b = [];
        if (str_contains($extra, 'filesort'))   $b[] = 'filesort';
        if (str_contains($extra, 'temporary'))  $b[] = 'tmp';
        if (str_contains($extra, 'index merge') || str_contains($extra, 'Using union')) $b[] = 'merge';
        return $b ? implode(' · ', $b) : '—';
    }

    private function veredicto(?string $key, string $type, int $rows, string $extra, int $total): string
    {
        $escaneaMucho = $type === 'ALL' || (!$key && $rows > 2000) || ($total > 0 && $rows > $total * 0.5);
        if ($escaneaMucho && !$key) return '⚠️ escaneo completo';
        if (!$key)                   return '⚠️ sin índice';
        if (str_contains($extra, 'filesort') && $rows > 2000) return '🟡 índice, pero ordena en memoria';
        return '✅ usa índice';
    }
}
