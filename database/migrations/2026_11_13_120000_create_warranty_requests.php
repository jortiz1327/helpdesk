<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Solicitudes de garantía del portal público: el formulario guiado crea un ticket y deja
 * aquí lo que pidió el cliente (dónde, qué etiquetas y qué les pasa, sus fotos), para que
 * «Crear garantía desde el ticket» lo rellene solo. Y en cada dispositivo, lo que dijo el
 * cliente que le pasaba.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('warranty_requests', function (Blueprint $t) {
            $t->id();
            $t->foreignId('ticket_id')->unique()->constrained('tickets')->cascadeOnDelete();
            $t->string('email', 190);
            $t->unsignedInteger('portal_client_id')->nullable();
            $t->string('client_name', 190)->nullable();
            $t->unsignedInteger('portal_project_id')->nullable();
            $t->string('project_name', 190)->nullable();
            $t->json('items');                              // [{code, kind, issue, detail, photos: {tipo: attachment_id}}]
            $t->string('contact_name', 120)->nullable();
            $t->string('phone', 40)->nullable();
            $t->text('address')->nullable();
            $t->timestamps();
            $t->index('email');
            $t->index('portal_client_id');
        });

        Schema::table('warranty_items', function (Blueprint $t) {
            $t->string('reported_issue', 300)->nullable()->after('position_title');
        });
    }

    public function down(): void
    {
        Schema::table('warranty_items', fn (Blueprint $t) => $t->dropColumn('reported_issue'));
        Schema::dropIfExists('warranty_requests');
    }
};
