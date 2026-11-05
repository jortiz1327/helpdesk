<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Garantías: batería (%) y señal con la antena (%) de cada etiqueta al comprobarla en
 * ZKONG. Con la conexión y la última comunicación, la ficha propone un diagnóstico.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('warranty_items', function (Blueprint $t) {
            $t->unsignedTinyInteger('battery')->nullable()->after('last_commu_at');
            $t->unsignedTinyInteger('ap_signal')->nullable()->after('battery');
        });
    }

    public function down(): void
    {
        Schema::table('warranty_items', function (Blueprint $t) {
            $t->dropColumn(['battery', 'ap_signal']);
        });
    }
};
