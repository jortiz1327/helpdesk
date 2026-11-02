<?php

namespace App\Services;

use App\Models\Warranty;
use App\Models\WarrantyDocument;
use Illuminate\Support\Carbon;

/**
 * Plazos de garantía (Configuración → Plazos). Por defecto, los de las Condiciones de
 * garantía: hardware 2 años y pilas 1 año (o 4.000 refrescos, que solo se cita: los
 * servidores no dan el contador) desde la instalación. Cada categoría puede tener los suyos.
 *
 * La instalación de cada dispositivo es su alta en ZKONG (la primera que se conoce) o, si
 * se indica, la fecha de instalación de la garantía, que manda sobre todas. Se compara con
 * la fecha de recepción de la garantía (o la de primer contacto, según la configuración).
 */
class WarrantyTerms
{
    public const DEFAULTS = [
        'hardware_months'       => 24,
        'battery_months'        => 12,
        'battery_cycles'        => 4000,
        'reference'             => 'received',   // received | first_contact
        'out_of_term_to_client' => true,         // fuera de plazo: se propone «Paga el cliente»
        'categories'            => [],           // [{category, hardware_months, battery_months}]
    ];

    public const REFERENCES = ['received' => 'Fecha de recepción', 'first_contact' => 'Primera fecha de contacto'];

    private static ?array $cache = null;

    public static function settings(): array
    {
        return self::$cache ??= array_replace(self::DEFAULTS, WarrantyDocument::setting('terms'));
    }

    public static function forget(): void
    {
        self::$cache = null;
    }

    /** Los plazos de una garantía (los de su categoría si tiene) y la fecha con la que se comparan. */
    public static function forWarranty(Warranty $w): array
    {
        $s = self::settings();
        $cat = collect($s['categories'])->first(fn ($c) => $w->category && mb_strtolower($c['category']) === mb_strtolower($w->category));
        $ref = $s['reference'] === 'first_contact' ? ($w->first_contact_at ?? $w->received_at) : $w->received_at;
        return [
            'hardware_months' => (int) ($cat['hardware_months'] ?? $s['hardware_months']),
            'battery_months'  => (int) ($cat['battery_months'] ?? $s['battery_months']),
            'battery_cycles'  => (int) $s['battery_cycles'],
            'category'        => $cat ? $cat['category'] : null,
            'reference'       => ($ref ?? now())->copy()->startOfDay(),
        ];
    }

    /**
     * El plazo de un dispositivo, o null si no se sabe cuándo se instaló.
     * {installed_at, source (zkong|manual), hardware_until, hardware_ok, battery_until, battery_ok}
     */
    public static function item(Warranty $w, $item, ?array $t = null): ?array
    {
        $t ??= self::forWarranty($w);
        [$fecha, $origen] = $w->installed_at ? [$w->installed_at, 'manual'] : [$item->installed_at ?? null, 'zkong'];
        if (! $fecha) {
            return null;
        }
        $fecha = Carbon::parse($fecha)->startOfDay();
        $hw = $fecha->copy()->addMonthsNoOverflow($t['hardware_months']);
        $bat = $fecha->copy()->addMonthsNoOverflow($t['battery_months']);
        return [
            'installed_at' => $fecha->toDateString(), 'source' => $origen,
            'hardware_until' => $hw->toDateString(), 'hardware_ok' => $t['reference']->lte($hw),
            'battery_until' => $bat->toDateString(), 'battery_ok' => $t['reference']->lte($bat),
        ];
    }

    /** «2 años», «18 meses». */
    public static function months(int $n): string
    {
        return $n % 12 === 0 ? (($a = intdiv($n, 12)) === 1 ? '1 año' : "{$a} años") : ($n === 1 ? '1 mes' : "{$n} meses");
    }
}
