<?php

namespace App\Services;

/**
 * Cerrar y reabrir una garantía. Pasar a un estado que cuenta como cerrada pide el
 * motivo de cierre (lista de Configuración) y, si se quiere, cómo se resolvió; volver a
 * un estado abierto pide por qué se reabre. Vale igual desde la ficha, el formulario o
 * el cambio en lote, y todo queda en el historial.
 */
class WarrantyClosing
{
    // De serie, de los procedimientos de garantías: se cambian en Configuración.
    public const DEFAULTS = [
        ['key' => 'aceptada', 'label' => 'Garantía aceptada', 'active' => true],
        ['key' => 'rechazada', 'label' => 'Garantía rechazada', 'active' => true],
        ['key' => 'sustitucion', 'label' => 'Sustitución realizada', 'active' => true],
        ['key' => 'reparacion', 'label' => 'Reparación realizada', 'active' => true],
        ['key' => 'solucion_tecnica', 'label' => 'Solución técnica', 'active' => true],
        ['key' => 'acuerdo', 'label' => 'Acuerdo con el cliente', 'active' => true],
        ['key' => 'zkong', 'label' => 'Resuelto por ZKONG', 'active' => true],
        ['key' => 'configuracion', 'label' => 'Error de configuración', 'active' => true],
        ['key' => 'no_procede', 'label' => 'No procede garantía', 'active' => true],
        ['key' => 'cliente_no_sigue', 'label' => 'El cliente no continúa', 'active' => true],
        ['key' => 'duplicada', 'label' => 'Duplicada', 'active' => true],
        ['key' => 'otro', 'label' => 'Otro', 'active' => true],
    ];
    public const MAX = 40;

    /** [{key, label, active}] */
    public static function reasons(): array
    {
        $v = \App\Models\WarrantyDocument::setting('close_reasons');
        return array_key_exists('list', $v) ? (array) $v['list'] : self::DEFAULTS;
    }

    public static function label(?string $key): ?string
    {
        if (! $key) return null;
        return collect(self::reasons())->firstWhere('key', $key)['label'] ?? $key;
    }

    /**
     * Lo que hay que guardar al pasar de abierta a cerrada o al revés, a partir de lo que
     * llega (close_reason, close_resolution, reopen_reason). [campos, evento, error]:
     * evento es [campo, texto] para el historial, o null si no se cierra ni se reabre.
     */
    public static function apply(array $in, bool $eraCerrada, bool $seraCerrada, array $actual = []): array
    {
        // Sigue cerrada y llega otro motivo o resolución: se corrige.
        if ($eraCerrada && $seraCerrada && array_key_exists('close_reason', $in)) {
            $motivo = (string) $in['close_reason'];
            $res = mb_substr(trim((string) ($in['close_resolution'] ?? '')), 0, 2000);
            $res = $res !== '' ? $res : null;
            if ($motivo === (string) ($actual['close_reason'] ?? '') && $res === ($actual['close_resolution'] ?? null)) return [[], null, null];
            $m = collect(self::reasons())->firstWhere('key', $motivo);
            if (! $m || (empty($m['active']) && $motivo !== ($actual['close_reason'] ?? null))) return [[], null, 'Elige el motivo de cierre'];
            return [['close_reason' => $motivo, 'close_resolution' => $res], ['close', $m['label'] . ($res ? " · {$res}" : '')], null];
        }
        if (! $eraCerrada && $seraCerrada) {
            $motivo = (string) ($in['close_reason'] ?? '');
            $m = collect(self::reasons())->firstWhere('key', $motivo);
            if (! $m || (empty($m['active']))) return [[], null, 'Elige el motivo de cierre'];
            $res = mb_substr(trim((string) ($in['close_resolution'] ?? '')), 0, 2000);
            return [['close_reason' => $motivo, 'close_resolution' => $res !== '' ? $res : null],
                ['close', $m['label'] . ($res !== '' ? " · {$res}" : '')], null];
        }
        if ($eraCerrada && ! $seraCerrada) {
            $por = mb_substr(trim((string) ($in['reopen_reason'] ?? '')), 0, 500);
            if ($por === '') return [[], null, 'Indica por qué se reabre'];
            return [['close_reason' => null, 'close_resolution' => null], ['reopen', $por], null];
        }
        return [[], null, null];
    }

    /** Lo de Configuración, validado: las claves no cambian (las usan las garantías). [lista, error]. */
    public static function clean(array $in): array
    {
        if (count($in) > self::MAX) return [null, 'Como mucho ' . self::MAX . ' motivos'];
        $out = [];
        $vistos = [];
        foreach ($in as $r) {
            $label = mb_substr(trim((string) ($r['label'] ?? '')), 0, 60);
            if ($label === '') return [null, 'Cada motivo necesita un nombre'];
            if (isset($vistos[mb_strtolower($label)])) return [null, "«{$label}» está repetido"];
            $vistos[mb_strtolower($label)] = true;
            $key = (string) ($r['key'] ?? '');
            if (! preg_match('/^[a-z0-9_]{1,30}$/', $key) || collect($out)->contains('key', $key)) {
                $key = substr('m_' . bin2hex(random_bytes(5)), 0, 12);
            }
            $out[] = ['key' => $key, 'label' => $label, 'active' => ! empty($r['active'])];
        }
        if (! collect($out)->contains('active', true)) return [null, 'Deja al menos un motivo activo'];
        return [$out, null];
    }
}
