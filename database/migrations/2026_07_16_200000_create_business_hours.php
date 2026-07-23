<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * HORARIO DE ATENCIÓN y FESTIVOS.
 *
 * Es el compromiso de cuándo se atiende, y sobre él corre el reloj del SLA: fuera
 * de horario el reloj se PARA, así que un ticket que entra un viernes a las 19:00
 * no consume plazo hasta el lunes por la mañana.
 *
 * OJO: esto NO es el cuadrante de turnos (quién trabaja cada semana). Se mantienen
 * separados a propósito: el compromiso con el cliente no puede depender de si
 * alguien rellenó la hoja de turnos.
 *
 * Se admiten VARIOS tramos por día para las jornadas partidas (mañana y tarde).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('business_hours', function (Blueprint $table) {
            $table->id();
            $table->unsignedTinyInteger('weekday');   // 1=lunes … 7=domingo (ISO)
            $table->time('opens');
            $table->time('closes');
            $table->timestamps();

            $table->index('weekday');
        });

        Schema::create('holidays', function (Blueprint $table) {
            $table->id();
            $table->date('date')->unique();
            $table->string('name', 120)->nullable();
            $table->timestamps();
        });

        // Arranque razonable: lunes a viernes, jornada partida. Es editable.
        $now = now();
        $filas = [];
        for ($d = 1; $d <= 5; $d++) {
            $filas[] = ['weekday' => $d, 'opens' => '09:00:00', 'closes' => '14:00:00', 'created_at' => $now, 'updated_at' => $now];
            $filas[] = ['weekday' => $d, 'opens' => '15:00:00', 'closes' => '18:00:00', 'created_at' => $now, 'updated_at' => $now];
        }
        DB::table('business_hours')->insert($filas);
    }

    public function down(): void
    {
        Schema::dropIfExists('holidays');
        Schema::dropIfExists('business_hours');
    }
};
