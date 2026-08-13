<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * ETIQUETAS de ticket: «post-its» de color libres, aparte de la categoría (única) y
 * la prioridad (única). Un ticket puede llevar VARIAS → catálogo + tabla pivote.
 * El catálogo lo gestionan los encargados; los agentes solo eligen de la lista.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ticket_labels', function (Blueprint $t) {
            $t->id();
            $t->string('name', 60);
            $t->string('color', 7)->default('#64748b');
            $t->unsignedInteger('position')->default(0);
            $t->boolean('active')->default(true);
            $t->timestamps();
        });

        Schema::create('ticket_label_ticket', function (Blueprint $t) {
            $t->unsignedBigInteger('ticket_id');
            $t->unsignedBigInteger('label_id');
            $t->primary(['ticket_id', 'label_id']);
            $t->index('label_id');
            $t->foreign('ticket_id')->references('id')->on('tickets')->cascadeOnDelete();
            $t->foreign('label_id')->references('id')->on('ticket_labels')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ticket_label_ticket');
        Schema::dropIfExists('ticket_labels');
    }
};
