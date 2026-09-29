<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Preferencia por usuario: recibir (o no) el aviso «Respuesta del cliente». Se suma
 * a notify_sla / notify_assigned que ya existían. Cada agente decide qué avisos
 * globales quiere para sí desde su pantalla de notificaciones. Por defecto: sí.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('notify_reply')->default(true)->after('notify_assigned');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('notify_reply');
        });
    }
};
