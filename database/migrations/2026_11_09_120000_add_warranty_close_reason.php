<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Motivo de cierre (de la lista de Configuración) y cómo se resolvió. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('warranties', function (Blueprint $t) {
            $t->string('close_reason', 40)->nullable()->after('closed_at');
            $t->text('close_resolution')->nullable()->after('close_reason');
        });
    }

    public function down(): void
    {
        Schema::table('warranties', function (Blueprint $t) {
            $t->dropColumn(['close_reason', 'close_resolution']);
        });
    }
};
