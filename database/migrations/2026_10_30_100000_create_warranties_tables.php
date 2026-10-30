<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Gestión de garantías: los expedientes (G-AAAA-NNNN), su seguimiento (notas que escribe
 * el equipo) y su historial (cada cambio de campo, automático). En el futuro se abrirán
 * desde un ticket de soporte: de ahí el ticket_id.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('warranties', function (Blueprint $t) {
            $t->id();
            $t->string('code', 30)->unique();
            $t->date('received_at');
            $t->string('client_name', 160)->nullable();
            $t->string('project_name', 160)->nullable();
            $t->string('category', 60)->nullable();
            $t->string('urgency', 20)->nullable();              // baja | media | alta | muy_urgente (null = no disponible)
            $t->string('status', 20)->default('no_iniciado');
            $t->unsignedBigInteger('responsible_id')->nullable();
            $t->unsignedBigInteger('ticket_id')->nullable();
            $t->text('description')->nullable();
            $t->unsignedBigInteger('created_by')->nullable();
            $t->timestamp('closed_at')->nullable();
            $t->timestamp('last_activity_at')->nullable();
            $t->timestamps();

            $t->foreign('responsible_id')->references('id')->on('users')->nullOnDelete();
            $t->foreign('ticket_id')->references('id')->on('tickets')->nullOnDelete();
            $t->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            $t->index(['status', 'received_at']);
            $t->index('responsible_id');
        });

        Schema::create('warranty_notes', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('warranty_id');
            $t->unsignedBigInteger('user_id')->nullable();
            $t->string('type', 30)->default('nota');
            $t->text('body');
            $t->timestamp('created_at')->useCurrent();

            $t->foreign('warranty_id')->references('id')->on('warranties')->cascadeOnDelete();
            $t->foreign('user_id')->references('id')->on('users')->nullOnDelete();
            $t->index(['warranty_id', 'created_at']);
        });

        Schema::create('warranty_events', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('warranty_id');
            $t->unsignedBigInteger('user_id')->nullable();
            $t->string('field', 40);
            $t->text('from_value')->nullable();
            $t->text('to_value')->nullable();
            $t->timestamp('created_at')->useCurrent();

            $t->foreign('warranty_id')->references('id')->on('warranties')->cascadeOnDelete();
            $t->foreign('user_id')->references('id')->on('users')->nullOnDelete();
            $t->index(['warranty_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('warranty_events');
        Schema::dropIfExists('warranty_notes');
        Schema::dropIfExists('warranties');
    }
};
