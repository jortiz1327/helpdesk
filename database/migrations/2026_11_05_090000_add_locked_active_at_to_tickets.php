<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bloqueo de tickets: cuándo tocó algo por última vez quien lo tiene (tecla, clic, ratón).
 * Con eso los demás ven la cuenta atrás «se libera en 3:12» (minutos sin actividad).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->timestamp('locked_active_at')->nullable()->after('locked_at');
        });
    }

    public function down(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->dropColumn('locked_active_at');
        });
    }
};
