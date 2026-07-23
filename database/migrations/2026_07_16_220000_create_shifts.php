<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CUADRANTE DE TURNOS de soporte (sustituye al Excel).
 *
 *   shifts           → quién cubre cada SEMANA en cada turno (mañana / tarde).
 *   shift_overrides  → sustituciones durante un PERIODO («esta semana la tarde la lleva Juan»),
 *                      que en el Excel vivían como texto en la columna CAMBIOS.
 *
 * La semana se guarda como la FECHA DEL LUNES, no como texto: en el Excel eso ya
 * había provocado errores («31 al 04 de agosto» era en realidad 31 ago – 4 sep).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shifts', function (Blueprint $table) {
            $table->id();
            $table->date('week_start');                   // lunes de la semana
            $table->enum('shift', ['morning', 'afternoon']);
            $table->unsignedBigInteger('user_id');
            $table->string('notes', 190)->nullable();
            $table->timestamps();

            $table->unique(['week_start', 'shift']);      // un agente por turno y semana
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });

        Schema::create('shift_overrides', function (Blueprint $table) {
            $table->id();
            $table->date('date');                         // el día concreto que se sustituye
            $table->enum('shift', ['morning', 'afternoon']);
            $table->unsignedBigInteger('user_id');        // quien lo cubre ese día
            $table->string('notes', 190)->nullable();
            $table->timestamps();

            $table->unique(['date', 'shift']);
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });

        /*
         * Solo algunas categorías se reparten por turno. El soporte de programación
         * rota; facturas y garantías tienen responsable fijo y no entran aquí.
         */
        Schema::table('ticket_categories', function (Blueprint $table) {
            $table->boolean('use_shift')->default(false)->after('sla_resolve_hours');
        });
    }

    public function down(): void
    {
        Schema::table('ticket_categories', function (Blueprint $table) {
            $table->dropColumn('use_shift');
        });
        Schema::dropIfExists('shift_overrides');
        Schema::dropIfExists('shifts');
    }
};
