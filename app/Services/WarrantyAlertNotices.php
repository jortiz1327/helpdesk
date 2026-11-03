<?php

namespace App\Services;

use App\Models\Setting;
use App\Models\User;
use App\Models\Warranty;
use App\Models\WarrantyDocument;
use Illuminate\Support\Facades\DB;

/**
 * Avisos de las alertas de garantías (los manda warranties:alerts cada 15 minutos):
 *   · Campana: cuando una garantía pasa a crítica por una regla, un aviso a su responsable
 *     (sin responsable: a quienes se elijan en Configuración, o a quien la gestiona). Una
 *     vez por garantía y regla mientras siga crítica; si deja de serlo y vuelve, se repite.
 *   · Correo: un resumen al día (laborables, a la hora elegida) a cada uno con sus
 *     garantías críticas (y, si se quiere, también las de aviso). Apagado de serie.
 * La primera pasada solo anota las que ya estaban críticas, para no llenar la campana.
 */
class WarrantyAlertNotices
{
    public const DEFAULTS = ['bell' => true, 'email' => false, 'hour' => '08:00', 'include_warn' => false, 'unassigned_to' => []];
    private const INICIADO = 'warranty_alert_notices_init';
    private const ULTIMO_CORREO = 'warranty_alert_digest_last';

    public static function settings(): array
    {
        return array_replace(self::DEFAULTS, array_intersect_key(WarrantyDocument::setting('notices'), self::DEFAULTS));
    }

    /** Lo de Configuración, validado. [ajustes, error]. */
    public static function clean(array $in): array
    {
        $hora = (string) ($in['hour'] ?? '08:00');
        if (! preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $hora)) return [null, 'Hora no válida'];
        $ids = array_values(array_unique(array_map('intval', (array) ($in['unassigned_to'] ?? []))));
        $validos = User::whereIn('id', $ids)->where('active', true)->pluck('id')->all();
        return [['bell' => (bool) ($in['bell'] ?? true), 'email' => (bool) ($in['email'] ?? false), 'hour' => $hora,
            'include_warn' => (bool) ($in['include_warn'] ?? false), 'unassigned_to' => array_values(array_intersect($ids, $validos))], null];
    }

    /** Las garantías abiertas, con lo que necesitan las alertas y los avisos. */
    private static function abiertas()
    {
        return DB::table('warranties')->whereNotIn('status', Warranty::closedStatuses())
            ->get(['id', 'code', 'client_name', 'project_name', 'status', 'urgency', 'responsible_id', 'last_activity_at', 'status_changed_at', 'created_at']);
    }

    /** Quién recibe los avisos de las garantías sin responsable: [user_id]. */
    public static function sinResponsable(): array
    {
        $ids = self::settings()['unassigned_to'];
        $q = User::where('active', true);
        $ids = $ids ? $q->whereIn('id', $ids)->pluck('id')->all() : $q->permission('warranties.config')->pluck('id')->all();
        // Nadie con la configuración a mano: el superadmin.
        if (! $ids) $ids = User::where('active', true)->role(config('rbac.super_role', 'superadmin'))->pluck('id')->all();
        return array_map('intval', $ids);
    }

    /** A quién va lo de una garantía: su responsable (si sigue activo) o los de «sin responsable». */
    private static function destinatarios(object $w, array $sinResp, array $activos): array
    {
        return $w->responsible_id && isset($activos[(int) $w->responsible_id]) ? [(int) $w->responsible_id] : $sinResp;
    }

    /** Campana: las nuevas críticas. Devuelve [avisos, garantías nuevas en crítico]. $dry: solo contarlas. */
    public static function check(bool $dry = false): array
    {
        $rows = self::abiertas();
        $alertas = app(WarrantyAlerts::class)->alerts($rows);
        $criticas = [];   // [warranty_id => [key => texto]]
        foreach ($alertas as $id => $l) foreach ($l as $a) if ($a['level'] === 'crit') $criticas[$id][$a['key']] = $a['text'];

        $hechas = DB::table('warranty_alert_notices')->get(['warranty_id', 'alert_key'])
            ->groupBy('warranty_id')->map(fn ($g) => $g->pluck('alert_key')->all())->all();
        $silencio = ! Setting::get(self::INICIADO);
        $cfg = self::settings();
        $sinResp = self::sinResponsable();
        $activos = array_flip(User::where('active', true)->pluck('id')->all());
        $porId = $rows->keyBy('id');
        $avisos = 0;
        $nuevas = [];

        foreach ($criticas as $id => $claves) {
            $faltan = array_diff_key($claves, array_flip($hechas[$id] ?? []));
            if (! $faltan) continue;
            $w = $porId[$id];
            $nuevas[] = $w->code;
            if ($dry) continue;
            DB::table('warranty_alert_notices')->insert(array_map(fn ($k) => ['warranty_id' => $id, 'alert_key' => $k, 'notified_at' => now()], array_keys($faltan)));
            if ($silencio || ! $cfg['bell']) continue;
            $quien = trim($w->client_name . ($w->project_name ? " · {$w->project_name}" : ''));
            $texto = "{$w->code}" . ($quien ? " ({$quien})" : '') . ' está en crítico: ' . implode(', ', $faltan);
            foreach (self::destinatarios($w, $sinResp, $activos) as $uid) {
                app(NotificationService::class)->push($uid, 'warranty_alert', $texto, null, null, (int) $id);
                $avisos++;
            }
        }

        if (! $dry) {
            // Lo que ya no está en crítico (o se ha cerrado) se olvida: si vuelve, se avisa otra vez.
            foreach ($hechas as $id => $claves) {
                $fuera = array_diff($claves, array_keys($criticas[$id] ?? []));
                if ($fuera) DB::table('warranty_alert_notices')->where('warranty_id', $id)->whereIn('alert_key', $fuera)->delete();
            }
            if ($silencio) Setting::put(self::INICIADO, now()->toDateTimeString());
        }
        return [$avisos, $nuevas];
    }

    /** El resumen por correo: [user_id => [{code, client, project, level, alerts}]] (solo quien tiene alguna). */
    public static function digestPlan(): array
    {
        $rows = self::abiertas();
        $alertas = app(WarrantyAlerts::class)->alerts($rows);
        $conAviso = self::settings()['include_warn'];
        $sinResp = self::sinResponsable();
        $activos = array_flip(User::where('active', true)->pluck('id')->all());
        $out = [];
        foreach ($rows as $w) {
            $l = array_values(array_filter($alertas[$w->id] ?? [], fn ($a) => $conAviso || $a['level'] === 'crit'));
            if (! $l) continue;
            $fila = ['id' => (int) $w->id, 'code' => $w->code, 'client' => (string) $w->client_name, 'project' => (string) $w->project_name,
                'level' => $l[0]['level'], 'alerts' => array_column($l, 'text'), 'unassigned' => ! $w->responsible_id];
            foreach (self::destinatarios($w, $sinResp, $activos) as $uid) $out[$uid][] = $fila;
        }
        foreach ($out as &$l) usort($l, fn ($a, $b) => [$a['level'] === 'crit' ? 0 : 1, $a['code']] <=> [$b['level'] === 'crit' ? 0 : 1, $b['code']]);
        return $out;
    }

    /** ¿Toca mandar el correo ahora? (encendido, laborable, pasada la hora y no mandado hoy). */
    public static function digestDue(bool $force = false): bool
    {
        $cfg = self::settings();
        if (! $cfg['email']) return false;
        $hoy = now()->format('Y-m-d');
        if (now()->isWeekend() || isset(app(BusinessHoursService::class)->festivos()[$hoy])) return false;
        return $force || (now()->format('H:i') >= $cfg['hour'] && Setting::get(self::ULTIMO_CORREO) !== $hoy);
    }

    public static function digestSent(): void
    {
        Setting::put(self::ULTIMO_CORREO, now()->format('Y-m-d'));
    }
}
