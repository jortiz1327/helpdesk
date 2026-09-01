<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Reintentos por destinatario en campañas: un 429 (rate limit) o un 5xx de Meta es
 * TRANSITORIO. Antes marcaba al destinatario `failed` para siempre; ahora se deja
 * pendiente y se reintenta hasta N veces. Este contador lleva la cuenta.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('campaign_recipients', function (Blueprint $table) {
            $table->unsignedTinyInteger('retries')->default(0)->after('error');
        });
    }

    public function down(): void
    {
        Schema::table('campaign_recipients', function (Blueprint $table) {
            $table->dropColumn('retries');
        });
    }
};
