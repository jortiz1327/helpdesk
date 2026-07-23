<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Núcleo del sistema: el TICKET.
 *
 * Cambio de fondo respecto a la app anterior: la entidad central deja de ser el
 * contacto/conversación (1 contacto = 1 hilo infinito) y pasa a ser el ticket
 * (1 contacto = N tickets, cada uno con su hilo, estado, categoría y responsable).
 */
return new class extends Migration
{
    public function up(): void
    {
        // --- Categorías (configurables desde Administración, no fijas en código) ---
        Schema::create('ticket_categories', function (Blueprint $table) {
            $table->id();
            $table->string('key', 40)->unique();     // slug estable: se usa en código
            $table->string('name', 80);              // etiqueta visible (editable)
            $table->string('color', 20)->default('#64748b');
            $table->unsignedSmallInteger('position')->default(0);
            $table->boolean('active')->default(true);
        });

        // --- Tickets ---
        Schema::create('tickets', function (Blueprint $table) {
            $table->id();

            // Referencia legible que ve el cliente: TK-AAMM-NNNN (secuencial por mes)
            $table->string('code', 20)->unique();

            $table->string('subject', 200);
            $table->unsignedBigInteger('category_id')->nullable();

            // Ciclo de vida. 'nuevo' = aún sin atender por nadie.
            $table->enum('status', ['nuevo', 'en_proceso', 'pendiente_cliente', 'resuelto', 'cerrado'])
                ->default('nuevo');
            $table->enum('priority', ['baja', 'media', 'alta', 'urgente'])->default('media');

            // Canal de origen. Los tres desembocan en el mismo hilo de chat.
            $table->enum('channel', ['whatsapp', 'email', 'web'])->default('whatsapp');

            $table->unsignedBigInteger('contact_id');            // quién lo abre
            $table->unsignedBigInteger('assigned_to')->nullable(); // usuario de soporte responsable

            // Hitos (para SLA y analíticas)
            $table->timestamp('opened_at')->useCurrent();
            $table->timestamp('first_response_at')->nullable();  // 1ª respuesta de soporte
            $table->timestamp('resolved_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamp('last_message_at')->nullable();    // para ordenar la bandeja

            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate();

            $table->foreign('category_id')->references('id')->on('ticket_categories')->nullOnDelete();
            $table->foreign('contact_id')->references('id')->on('contacts')->cascadeOnDelete();
            $table->foreign('assigned_to')->references('id')->on('users')->nullOnDelete();

            // El router de mensajes entrantes busca por aquí: "¿tiene ticket abierto en este canal?"
            $table->index(['contact_id', 'channel', 'status']);
            $table->index(['status', 'last_message_at']);
            $table->index('assigned_to');
        });

        // --- Historial / auditoría del ticket ---
        Schema::create('ticket_events', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('ticket_id');
            $table->unsignedBigInteger('user_id')->nullable();  // null = lo hizo el sistema/bot
            $table->string('type', 30);                          // status | assign | category | priority | created
            $table->string('from_value', 100)->nullable();
            $table->string('to_value', 100)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->foreign('ticket_id')->references('id')->on('tickets')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
            $table->index(['ticket_id', 'id']);
        });

        /*
         * --- messages pasa a colgar del TICKET ---
         * Se reutiliza la tabla existente en vez de crear una nueva: así todo el
         * pipeline ya probado (webhook, multimedia, estados de entrega) sigue valiendo.
         */
        Schema::table('messages', function (Blueprint $table) {
            $table->unsignedBigInteger('ticket_id')->nullable()->after('contact_id');
            $table->enum('channel', ['whatsapp', 'email', 'web'])->default('whatsapp')->after('direction');
            $table->unsignedBigInteger('author_user_id')->nullable()->after('channel'); // usuario que responde
            $table->boolean('is_internal_note')->default(false)->after('body');          // nota interna: el cliente NO la ve

            $table->foreign('ticket_id')->references('id')->on('tickets')->cascadeOnDelete();
            $table->foreign('author_user_id')->references('id')->on('users')->nullOnDelete();
            $table->index(['ticket_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->dropForeign(['ticket_id']);
            $table->dropForeign(['author_user_id']);
            $table->dropIndex(['ticket_id', 'id']);
            $table->dropColumn(['ticket_id', 'channel', 'author_user_id', 'is_internal_note']);
        });

        Schema::dropIfExists('ticket_events');
        Schema::dropIfExists('tickets');
        Schema::dropIfExists('ticket_categories');
    }
};
