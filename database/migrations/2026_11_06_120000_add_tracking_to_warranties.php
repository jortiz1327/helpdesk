<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Seguimiento para el cliente: la clave de su enlace (/seguimiento/<clave>, se puede
 * renovar y así se anula la anterior) y cuántas veces lo ha abierto.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('warranties', 'track_token')) return;
        Schema::table('warranties', function (Blueprint $t) {
            $t->string('track_token', 40)->nullable()->unique();
            $t->unsignedInteger('track_views')->default(0);
            $t->dateTime('track_last_view_at')->nullable();
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('warranties', 'track_token')) return;
        Schema::table('warranties', function (Blueprint $t) {
            $t->dropUnique(['track_token']);
            $t->dropColumn(['track_token', 'track_views', 'track_last_view_at']);
        });
    }
};
