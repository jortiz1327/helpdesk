<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Campañas en TRAMOS de días: `daily_limit` = cuántos destinatarios salen como máximo al
 * día (null = todos de una vez). La regla global «solo días laborables» es el ajuste
 * `send_business_days_only` (sin fila = encendida, ver CampaignService::esperaHoy).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('campaigns', 'daily_limit')) {
            Schema::table('campaigns', function (Blueprint $t) {
                $t->unsignedInteger('daily_limit')->nullable()->after('total');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('campaigns', 'daily_limit')) {
            Schema::table('campaigns', fn (Blueprint $t) => $t->dropColumn('daily_limit'));
        }
    }
};
