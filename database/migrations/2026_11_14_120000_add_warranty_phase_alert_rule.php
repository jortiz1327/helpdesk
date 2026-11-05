<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Regla de alerta «Fase atascada»: demasiados días en un mismo estado, para los estados
 * que no tienen regla propia (en curso, pte. presupuesto…). Se ajusta en Configuración.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! DB::table('warranty_alert_rules')->where('key', 'fase')->exists()) {
            DB::table('warranty_alert_rules')->insert(['key' => 'fase', 'active' => true, 'warn_days' => 10, 'crit_days' => 20]);
        }
    }

    public function down(): void
    {
        DB::table('warranty_alert_rules')->where('key', 'fase')->delete();
    }
};
