<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * VISTAS GUARDADAS de la bandeja (las «Colas» de osTicket). Cada agente guarda su
 * propia combinación de filtros con un nombre, y le sale como una vista rápida más.
 * De momento son PERSONALES (por usuario); a futuro podrían ser compartidas del equipo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ticket_views', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('user_id')->index();
            $t->string('name', 80);
            $t->json('filters');                 // la foto del objeto de filtros de la bandeja
            $t->string('color', 7)->default('#2563eb');
            $t->unsignedInteger('position')->default(0);
            $t->timestamps();

            $t->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ticket_views');
    }
};
